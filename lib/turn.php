<?php
// The turn pipeline, shared by turn.php and the dialogue form of event.php ("respond": true).
// This file only defines functions, so requesting it over HTTP prints nothing.
// Callers validate the request first; run_turn() then does the duplicate check, generation,
// profile, history, knowledge and memory, model call, stale check, save and event log, sends the
// reply, then runs any opt-in memory updates.

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/knowledge.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/profiles.php';
require_once __DIR__ . '/embeddings.php';
require_once __DIR__ . '/memory.php';
require_once __DIR__ . '/audit.php';

// The optional "context" object: at most 10 entries, keys a-z and _ (max 32), text values (max 200).
function require_context(mixed $context): array
{
    if (!is_array($context) || count($context) > 10) {
        fail(400, 'bad_request', 'context must be an object with at most 10 entries.');
    }
    $clean = [];
    foreach ($context as $key => $value) {
        if (!is_string($key) || !preg_match('/^[a-z_]{1,32}$/', $key)) {
            fail(400, 'bad_request', 'context keys must be 1-32 characters of a-z and _.');
        }
        $clean[$key] = require_text($value, "context.$key", 200);
    }
    return $clean;
}

// $turn holds npc_name, player_name, text and context. $eventType is set when an explicit
// event.php dialogue request started this turn; it is logged, the pipeline is the same.
function run_turn(array $config, float $started, string $requestId, string $sessionId, string $npcId,
                  array $turn, ?string $eventType = null): never
{
    trace_id($requestId);
    $historyLimit = max(0, min(50, (int)($config['history_limit'] ?? 10)));
    $allowedActions = $config['allowed_actions'] ?? ['follow_player'];
    $memorySettings = memory_settings($config);
    $db = db_connect($config);
    require_schema($db);

    // Every outcome after validation is logged (lib/events.php), outside any transaction.
    // "complete" means the server finished this turn, not that the game received or ran it.
    $event = [
        'kind' => 'turn',
        'request_id' => $requestId,
        'session_id' => $sessionId,
        'npc_id' => $npcId,
        'provider' => event_provider($config),
        'player_text' => $turn['text'],
        'context' => $turn['context'],
        'event_type' => $eventType,
    ];
    $logged = function (string $status, array $extra = []) use ($db, $config, &$event, $started): bool {
        $row = ['status' => $status, 'duration_ms' => event_duration_ms($started)] + $extra + $event;
        return event_log($db, $config, $row) !== null;
    };

    // 1. Each request id may be used once (turns and game events share this). Old ids are
    //    forgotten after a day.
    if (!claim_request_id($db, $requestId, $sessionId, $npcId)) {
        // Logged as its own failed event; the original request's event is unchanged.
        $logged('failed', ['error_code' => 'duplicate_request']);
        fail(409, 'duplicate_request', 'This request_id was already used.');
    }
    forget_old_request_ids($db);

    // 2. Start a new generation. A newer turn or a cancel makes this one stale.
    $generation = next_generation($db, $sessionId, $npcId);
    $event['generation'] = $generation;

    // 3. This NPC's profile (assigned, else the default, else none) decides the system prompt
    //    and any model or mode override; empty overrides keep the config (lib/profiles.php).
    $profile = profile_for_npc($db, $npcId);
    if ($profile === false) {
        $logged('failed', ['error_code' => 'internal_error']);
        fail(500, 'internal_error', 'Internal server error.');
    }
    $llm = profile_llm(is_array($config['llm'] ?? null) ? $config['llm'] : [], $profile);
    $event['provider'] = event_provider(['llm' => $llm]);

    // 4. Load the most recent history for this NPC, oldest first.
    $history = pg_fetch_all(db_query($db,
        'SELECT role, text FROM (
            SELECT id, role, text FROM ex_turns WHERE session_id = $1 AND npc_id = $2 ORDER BY id DESC LIMIT $3
         ) recent ORDER BY id',
        [$sessionId, $npcId, $historyLimit]));

    // 5. Optional vector search: one embedding call for the player's line, outside any
    //    transaction and never retried. Any failure falls back to the keyword search alone.
    $vectorHits = [];
    $embedding = 'off';
    $embeddingSettings = embedding_settings($config);
    if ($embeddingSettings !== null) {
        $callStarted = microtime(true);
        $code = null;
        try {
            [$vector] = embed_texts($embeddingSettings, [$turn['text']]);
            $vectorHits = embedding_search($db, $npcId, $vector, embedding_model_id($embeddingSettings), MAX_FACTS);
            $embedding = 'ok';
        } catch (EmbeddingError $error) {
            [$embedding, $code] = ['failed', $error->getMessage()];
        }
        audit_insert($db, $config, ['kind' => 'embedding', 'provider' => $embeddingSettings['provider'] ?? 'mock',
            'status' => $embedding === 'ok' ? 'ok' : 'failed', 'error_code' => $code, 'duration_ms' => event_duration_ms($callStarted),
            'request_id' => audit_child_id('emb'), 'parent_request_id' => $requestId, 'session_id' => $sessionId, 'npc_id' => $npcId,
            'detail' => $embedding === 'ok' ? count($vectorHits) . ' vector matches' : 'keyword search used instead']);
    }

    // 6. Load this NPC's stored bio and up to three facts (NPC or global): vector matches first,
    //    then keyword matches. With advanced memory on, add this session's summary and newest
    //    diary entries. Keep a copy of exactly what was retrieved for the event log.
    $trace = null;
    $knowledge = load_knowledge($db, $npcId, $turn['text'], $trace, $vectorHits);
    if ($memorySettings['enabled']) {
        $memory = memory_load($db, $sessionId, $npcId);
        if ($memory === null) {
            $logged('failed', ['error_code' => 'internal_error']);
            fail(500, 'internal_error', 'Internal server error.');
        }
        $knowledge['memory'] = $memory;
    }
    $facts = [];
    foreach ($knowledge['facts'] as $i => $fact) {
        $facts[] = ($trace['facts'][$i] ?? []) + $fact;
    }
    $event['retrieval'] = [
        'terms' => $trace['terms'] ?? '',
        'bio' => $knowledge['bio'],
        'facts' => $facts,
        'history_lines' => count($history),
        'embedding' => $embedding,
        'profile' => profile_trace($profile),
        'memory' => isset($knowledge['memory']) ? ['summary' => $knowledge['memory']['summary'] !== null,
            'diary_entries' => count($knowledge['memory']['diary'])] : null,
    ];

    // 7. Ask the model. This can be slow, so it happens outside any transaction.
    try {
        $raw = generate_reply($llm, $turn, $history, $knowledge, $profile);
    } catch (Throwable $error) {
        app_log($error->getMessage());
        $logged('failed', ['error_code' => 'llm_unavailable']);
        fail(502, 'llm_unavailable', 'The language model did not answer.');
    }
    [$reply, $actions, $rejected] = extract_actions($raw, $allowedActions, $npcId);
    $event['reply'] = $reply;
    $event['actions'] = array_column($actions, 'name');
    $event['rejected_actions'] = $rejected;

    // 8. Save the exchange only if this turn is still the current generation.
    //    The session lock comes first, so a checkpoint restore cannot interleave.
    db_query($db, 'BEGIN');
    lock_session($db, $sessionId);
    $current = db_query($db,
        'SELECT generation FROM ex_generations WHERE session_id = $1 AND npc_id = $2 FOR UPDATE',
        [$sessionId, $npcId]);
    if ((int)pg_fetch_result($current, 0, 0) !== $generation) {
        db_query($db, 'ROLLBACK');
        $logged(event_stale_status($db, $sessionId, $npcId, $generation), ['error_code' => 'stale']);
        fail(409, 'stale', 'This turn was cancelled or replaced by a newer turn.');
    }
    db_query($db,
        "INSERT INTO ex_turns (session_id, npc_id, role, text) VALUES ($1, $2, 'player', $3), ($1, $2, 'npc', $4)",
        [$sessionId, $npcId, $turn['text'], $reply]);
    db_query($db,
        'DELETE FROM ex_turns WHERE session_id = $1 AND npc_id = $2 AND id NOT IN (
            SELECT id FROM ex_turns WHERE session_id = $1 AND npc_id = $2 ORDER BY id DESC LIMIT $3
         )',
        [$sessionId, $npcId, $historyLimit]);
    db_query($db, 'COMMIT');
    $eventLogged = $logged('complete');

    $data = [
        'ok' => true,
        'request_id' => $requestId,
        'npc_id' => $npcId,
        'generation' => $generation,
        'reply' => $reply,
        'actions' => $actions,
        'rejected_actions' => $rejected,
        // Optional (protocol 1 addition): false when the event could not be stored, so
        // result.php will not accept a report for this request.
        'logged' => $eventLogged,
    ];
    if (!$memorySettings['auto_notes'] && !$memorySettings['auto_extract']) {
        send_json(200, $data);
    }
    // 9. Opt-in memory updates run after the reply was sent, so the game never waits for them.
    //    Each saves only while this turn is still the current generation (lib/memory.php).
    send_json_then(200, $data, fn() => memory_after_turn($db, $config, $llm, [
        'request_id' => $requestId, 'session_id' => $sessionId, 'npc_id' => $npcId, 'npc_name' => $turn['npc_name'],
        'text' => $turn['text'], 'reply' => $reply, 'generation' => $generation, 'history_limit' => max(2, $historyLimit),
    ]));
}
