<?php
// OPTIONAL: POST what the game client says it did with the actions of one finished turn.
// See PROTOCOL.md. The report is untrusted client input: it is stored next to the turn's
// event for the Logs page, labelled "Client reported", and never runs anything.
// Only a retained "complete" turn event can take a report, only for actions that turn
// returned, and only once (the same report again is accepted as a repeat).

require_once __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';

$config = start_request('POST');
$input = read_json_body();
$requestId = require_id($input['request_id'] ?? null, 'request_id', 8);
$sessionId = require_id($input['session_id'] ?? null, 'session_id');
$npcId = require_id($input['npc_id'] ?? null, 'npc_id');
$list = $input['results'] ?? null;
if (!is_array($list) || !array_is_list($list) || count($list) < 1 || count($list) > EVENT_MAX_REPORTS) {
    fail(400, 'bad_request', 'results must be a list of 1-' . EVENT_MAX_REPORTS . ' objects.');
}
$results = [];
foreach ($list as $i => $item) {
    if (!is_array($item) || array_is_list($item)) {
        fail(400, 'bad_request', "results.$i must be an object.");
    }
    $name = $item['name'] ?? null;
    if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]{1,40}$/D', $name) || isset($results[$name])) {
        fail(400, 'bad_request', "results.$i.name must be a unique action name.");
    }
    $status = $item['status'] ?? null;
    if (!is_string($status) || !in_array($status, EVENT_REPORT_STATUSES, true)) {
        fail(400, 'bad_request', "results.$i.status must be one of " . implode(', ', EVENT_REPORT_STATUSES) . '.');
    }
    $result = ['name' => $name, 'status' => $status];
    if (isset($item['reason'])) {
        $result['reason'] = require_text($item['reason'], "results.$i.reason", 120);
    }
    $results[$name] = $result;
}
ksort($results);
$report = json_encode(['results' => array_values($results)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$db = db_connect($config);
require_schema($db);
// The newest completed turn with this id for this session and NPC. Duplicate, failed and
// stale attempts are separate rows with other statuses, so they never match.
$found = db_query($db,
    "SELECT id, actions, client_report FROM ex_events
     WHERE kind = 'turn' AND status = 'complete' AND request_id = $1 AND session_id = $2 AND npc_id = $3
     ORDER BY id DESC LIMIT 1",
    [$requestId, $sessionId, $npcId]);
if (pg_num_rows($found) === 0) {
    fail(404, 'unknown_request', 'No retained completed turn has this request_id, session_id and npc_id.');
}
$event = pg_fetch_assoc($found);
$returned = json_decode($event['actions'], true) ?: [];
foreach (array_keys($results) as $name) {
    if (!in_array($name, $returned, true)) {
        fail(409, 'action_not_returned', 'Only actions this turn returned can be reported.');
    }
}

// One report per event. A second, identical report is a harmless repeat.
$saved = db_query($db,
    'UPDATE ex_events SET client_report = $2::jsonb, client_reported_at = now() WHERE id = $1 AND client_report IS NULL',
    [$event['id'], $report]);
$repeat = false;
if (pg_affected_rows($saved) === 0) {
    $same = db_query($db, 'SELECT client_report = $2::jsonb FROM ex_events WHERE id = $1', [$event['id'], $report]);
    if (pg_num_rows($same) === 0 || pg_fetch_result($same, 0, 0) !== 't') {
        fail(409, 'already_reported', 'A different result was already reported for this request.');
    }
    $repeat = true;
}

send_json(200, ['ok' => true, 'request_id' => $requestId, 'repeat' => $repeat]);
