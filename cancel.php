<?php
// POST to cancel any in-flight turn for one NPC.
// The in-flight turn will answer 409 "stale" and its reply is not saved.

require_once __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';

$started = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
$config = start_request('POST');
$input = read_json_body();
$sessionId = require_id($input['session_id'] ?? null, 'session_id');
$npcId = require_id($input['npc_id'] ?? null, 'npc_id');

$db = db_connect($config);
require_schema($db);
// The cancel event is written in the same transaction as the new generation, so an old turn
// that finds itself stale can see it and record "cancelled". If the event write fails, or
// the row is later pruned, that turn records the generic "superseded" instead.
// A savepoint keeps a failed event write from undoing the cancel itself.
$logged = false;
$generation = next_generation($db, $sessionId, $npcId,
    function (int $generation) use ($db, $sessionId, $npcId, $started, &$logged): void {
        db_query($db, 'SAVEPOINT cancel_event');
        $logged = event_insert($db, ['kind' => 'cancel', 'status' => 'complete', 'session_id' => $sessionId,
            'npc_id' => $npcId, 'generation' => $generation, 'duration_ms' => event_duration_ms($started)]) !== null;
        db_query($db, $logged ? 'RELEASE SAVEPOINT cancel_event' : 'ROLLBACK TO SAVEPOINT cancel_event');
    });
if ($logged) {
    event_prune($db, $config);
}

// logged: optional (protocol 1 addition), false when the cancel event could not be stored.
send_json(200, ['ok' => true, 'npc_id' => $npcId, 'generation' => $generation, 'logged' => $logged]);
