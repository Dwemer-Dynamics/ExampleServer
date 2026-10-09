<?php
// POST one player line to one NPC and get the NPC reply plus allowed actions.
// See PROTOCOL.md for the request and reply format. The pipeline is in lib/turn.php.

require_once __DIR__ . '/lib/turn.php';

$started = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
$config = start_request('POST');
$input = read_json_body();

// Validate everything the game sent. The game is not trusted.
$requestId = trace_id(require_id($input['request_id'] ?? null, 'request_id', 8));
$sessionId = require_id($input['session_id'] ?? null, 'session_id');
$npcId = require_id($input['npc']['id'] ?? null, 'npc.id');
$turn = [
    'npc_name' => require_text($input['npc']['name'] ?? null, 'npc.name', 64),
    'player_name' => require_text($input['player']['name'] ?? null, 'player.name', 64),
    'text' => require_text($input['text'] ?? null, 'text', 1000),
    'context' => require_context($input['context'] ?? []),
];

run_turn($config, $started, $requestId, $sessionId, $npcId, $turn);
