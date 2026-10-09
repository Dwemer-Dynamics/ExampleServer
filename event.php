<?php
// POST one game event. See PROTOCOL.md.
// "respond": false (the default) only stores the event in the event log: no model call, no
// conversation history, no actions. "respond": true explicitly asks the named NPC to react,
// through the same pipeline, duplicate check and stale fence as turn.php (lib/turn.php).
// Nothing here runs automatically or in the background; the game decides when to send.

require_once __DIR__ . '/lib/turn.php';

// Fixed event types. Add a type here and in PROTOCOL.md together.
const GAME_EVENT_TYPES = ['location_entered', 'item_given', 'combat_started', 'combat_ended'];
const MAX_EVENT_TEXT = 300;

$started = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
$config = start_request('POST');
$input = read_json_body();

$requestId = trace_id(require_id($input['request_id'] ?? null, 'request_id', 8));
$sessionId = require_id($input['session_id'] ?? null, 'session_id');
$type = $input['type'] ?? null;
if (!is_string($type) || !in_array($type, GAME_EVENT_TYPES, true)) {
    fail(400, 'bad_request', 'type must be one of ' . implode(', ', GAME_EVENT_TYPES) . '.');
}
$respond = $input['respond'] ?? false;
if (!is_bool($respond)) {
    fail(400, 'bad_request', 'respond must be true or false.');
}
$text = require_text($input['text'] ?? null, 'text', MAX_EVENT_TEXT);
$context = require_context($input['context'] ?? []);

if ($respond) {
    // Dialogue: an explicit NPC target is required, exactly as for turn.php.
    $npcId = require_id($input['npc']['id'] ?? null, 'npc.id');
    $turn = [
        'npc_name' => require_text($input['npc']['name'] ?? null, 'npc.name', 64),
        'player_name' => require_text($input['player']['name'] ?? null, 'player.name', 64),
        'text' => "Game event ($type): $text",
        'context' => $context,
    ];
    run_turn($config, $started, $requestId, $sessionId, $npcId, $turn, $type);
}

// Log only. The NPC is optional (an event may concern no NPC).
$npcId = isset($input['npc']['id']) ? require_id($input['npc']['id'], 'npc.id') : null;
$db = db_connect($config);
require_schema($db);
// The whole validated event text (up to MAX_EVENT_TEXT) goes in player_text, which holds 1000
// characters; detail holds only 200. It is untrusted game input and Logs labels it so.
$event = ['kind' => 'game_event', 'status' => 'complete', 'request_id' => $requestId, 'session_id' => $sessionId,
    'npc_id' => $npcId, 'event_type' => $type, 'player_text' => $text, 'context' => $context, 'provider' => null];

// The request id and its event are stored together, so an accepted id always has its event.
// A repeated id is refused and logged as its own failed event; the first one is unchanged.
db_query($db, 'BEGIN');
if (!claim_request_id($db, $requestId, $sessionId, $npcId ?? '')) {
    db_query($db, 'ROLLBACK');
    event_log($db, $config, ['status' => 'failed', 'error_code' => 'duplicate_request',
        'duration_ms' => event_duration_ms($started)] + $event);
    fail(409, 'duplicate_request', 'This request_id was already used.');
}
$eventId = event_insert($db, ['duration_ms' => event_duration_ms($started)] + $event);
if ($eventId === null) {
    db_query($db, 'ROLLBACK');
    fail(500, 'internal_error', 'The event could not be stored.');
}
db_query($db, 'COMMIT');
event_prune($db, $config);
forget_old_request_ids($db);

// "logged" means the server stored the event. It is not a sign that anything ran in the game.
send_json(200, ['ok' => true, 'request_id' => $requestId, 'respond' => false, 'logged' => true]);
