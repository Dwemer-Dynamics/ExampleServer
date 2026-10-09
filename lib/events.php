<?php
// Event log (ex_events from migrations 003 and 004): one row per finished turn, cancel,
// restore or log-only game event. Shared by lib/turn.php, event.php, cancel.php, result.php,
// lib/checkpoint.php and the Logs page.
// This file only defines functions and constants, so requesting it over HTTP prints nothing.
//
// - "complete" means the server finished. It is not a delivery receipt and not proof the
//   game ran anything. Client reports (result.php) are stored separately as untrusted.
// - Rows hold only validated, size-capped values: ids, player text, the cleaned reply,
//   action names, the llm mode name, a short error code and a duration. Never the token,
//   keys, provider URLs, prompts, raw provider bodies or config. context is the turn's
//   validated game context (at most 10 keys of a-z and _, each value at most 200 characters).
//   retrieval is the cleaned bio/fact copy the turn gave the model (lib/turn.php).
// - Writing is best effort: a failed write is reported to the caller (logged: false) and
//   to the error log, never pretended. Only cancel.php writes inside a transaction (its
//   own, database-only, behind a savepoint), so a failed write never undoes the cancel.

const EVENT_LOG_DEFAULT_LIMIT = 10000;
const EVENT_STATUSES = ['complete', 'failed', 'cancelled', 'superseded'];
const EVENT_KINDS = ['turn', 'cancel', 'restore', 'game_event'];
const EVENT_PROVIDERS = ['mock', 'openai', 'openrouter', 'local', 'dwemerllm'];
const EVENT_REPORT_STATUSES = ['handled', 'rejected', 'failed'];
const EVENT_MAX_REPORTS = 4;

// Retention by row count, 100-100000. Optional setting event_log_limit.
function event_log_limit(array $config): int
{
    return max(100, min(100000, (int)($config['event_log_limit'] ?? EVENT_LOG_DEFAULT_LIMIT)));
}

// The llm mode name only, from a fixed list. Never a URL or model setting.
function event_provider(array $config): string
{
    $mode = $config['llm']['mode'] ?? 'mock';
    return is_string($mode) && in_array($mode, EVENT_PROVIDERS, true) ? $mode : 'unknown';
}

// Milliseconds since PHP received the request.
function event_duration_ms(float $started): int
{
    return max(0, (int)round((microtime(true) - $started) * 1000));
}

// Why a turn at $generation became stale. Each bump of an NPC's generation comes from
// a newer turn, a cancel or a checkpoint restore. "cancelled" only when a retained cancel
// event for generation + 1 proves it; otherwise "superseded", meaning the generation was
// invalidated with the exact cause unknown (a cancel whose event failed or was pruned
// also lands here).
function event_stale_status(\PgSql\Connection $db, string $sessionId, string $npcId, int $generation): string
{
    $result = @pg_query_params($db,
        "SELECT 1 FROM ex_events WHERE kind = 'cancel' AND session_id = $1 AND npc_id = $2 AND generation = $3",
        [$sessionId, $npcId, $generation + 1]);
    return $result !== false && pg_num_rows($result) > 0 ? 'cancelled' : 'superseded';
}

// Inserts one event and returns its id, or null (error logged) if it could not be stored.
// The game's validated context is stored as a JSON object, or NULL when empty.
function event_insert(\PgSql\Connection $db, array $event): ?int
{
    $text = fn(?string $value, int $max) => $value === null ? null : mb_substr($value, 0, $max);
    $context = $event['context'] ?? [];
    $json = fn(?array $value) => $value === null ? null : json_encode((object)$value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $result = @pg_query_params($db,
        'INSERT INTO ex_events (kind, status, request_id, session_id, npc_id, generation, error_code, provider,
             duration_ms, player_text, reply, actions, rejected_actions, detail, context, event_type, retrieval)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17) RETURNING id',
        [
            $event['kind'],
            $event['status'],
            $event['request_id'] ?? null,
            $event['session_id'],
            $event['npc_id'] ?? null,
            $event['generation'] ?? null,
            $event['error_code'] ?? null,
            $event['provider'] ?? null,
            $event['duration_ms'] ?? null,
            $text($event['player_text'] ?? null, 1000),
            $text($event['reply'] ?? null, 1000),
            json_encode(array_values($event['actions'] ?? [])),
            json_encode(array_values($event['rejected_actions'] ?? [])),
            $text($event['detail'] ?? null, 200),
            $json($context === [] ? null : $context),
            $event['event_type'] ?? null,
            $json($event['retrieval'] ?? null),
        ]);
    if ($result === false) {
        app_log('event log write failed: ' . pg_last_error($db));
        return null;
    }
    return (int)pg_fetch_result($result, 0, 0);
}

// Keeps the newest event_log_limit rows by id and deletes the rest. Whatever the id gaps
// or commit order, the last prune to run sees every committed row, so a burst of
// concurrent writers ends with exactly the newest rows. Outside any transaction.
function event_prune(\PgSql\Connection $db, array $config): void
{
    $pruned = @pg_query_params($db,
        'DELETE FROM ex_events WHERE id < (SELECT id FROM ex_events ORDER BY id DESC OFFSET $1 LIMIT 1)',
        [event_log_limit($config) - 1]);
    if ($pruned === false) {
        app_log('event log prune failed: ' . pg_last_error($db));
    }
}

// Inserts one event, then prunes. Returns the new id, or null if it could not be stored.
// Call it outside any transaction.
function event_log(\PgSql\Connection $db, array $config, array $event): ?int
{
    $id = event_insert($db, $event);
    if ($id !== null) {
        event_prune($db, $config);
    }
    return $id;
}
