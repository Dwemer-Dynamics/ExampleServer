<?php
// OPTIONAL: POST a player line and a few offered NPCs; get back which one should answer.
// Selection only: no chat, history or actions. See PROTOCOL.md.
// Turned off by default, and then it always answers with the game's baseline NPC.
// Its only database write is one connector-audit row (ids, provider, status, reason, timing;
// never the transcript), written after the reply was sent, so it adds no wait and a failed
// write can never change the decision.

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/decision.php';
require_once __DIR__ . '/lib/audit.php';

$config = start_request('POST');
$input = read_json_body();
// Optional (protocol 1 addition); made here when an older client sends none.
$requestId = trace_start($input['request_id'] ?? null);

// The game is not trusted: validate everything before any provider call.
$transcript = require_text($input['transcript'] ?? null, 'transcript', 2000);
$baselineId = require_id($input['baseline_id'] ?? null, 'baseline_id');
$list = $input['candidates'] ?? null;
if (!is_array($list) || !array_is_list($list) || count($list) < 1 || count($list) > DECISION_MAX_CANDIDATES) {
    fail(400, 'bad_request', 'candidates must be a list of 1-' . DECISION_MAX_CANDIDATES . ' objects.');
}
$candidates = [];
foreach ($list as $i => $item) {
    if (!is_array($item) || array_is_list($item)) {
        fail(400, 'bad_request', "candidates.$i must be an object.");
    }
    $id = require_id($item['id'] ?? null, "candidates.$i.id");
    if ($id === 'abstain' || isset($candidates[$id])) {
        fail(400, 'bad_request', "candidates.$i.id must be unique and not \"abstain\".");
    }
    $candidate = ['id' => $id];
    if (isset($item['name'])) {
        $candidate['name'] = require_text($item['name'], "candidates.$i.name", 64);
    }
    if (isset($item['cues'])) {
        $candidate['cues'] = require_text($item['cues'], "candidates.$i.cues", 200);
    }
    $candidates[$id] = $candidate;
}
if (!isset($candidates[$baselineId])) {
    fail(400, 'bad_request', 'baseline_id must be one of the candidates.');
}

$settings = is_array($config['decision'] ?? null) ? $config['decision'] : [];
$started = microtime(true);
$decision = decide_responder($settings, $transcript, $baselineId, array_values($candidates));
$audit = ['kind' => 'decision', 'request_id' => $requestId, 'duration_ms' => event_duration_ms($started),
    'provider' => empty($settings['enabled']) ? null : ($settings['provider'] ?? 'mock'),
    'detail' => "reason {$decision['reason']}, " . count($candidates) . ' offered'];
$audit += match (true) {
    $decision['reason'] === 'disabled' => ['status' => 'disabled'],
    $decision['source'] === 'provider' => ['status' => 'ok'],
    in_array($decision['reason'], ['provider_error', 'bad_response'], true) => ['status' => 'failed', 'error_code' => $decision['reason']],
    default => ['status' => 'fallback'],
};
send_json_then(200, ['ok' => true] + $decision, fn() => audit_record($config, $audit));
