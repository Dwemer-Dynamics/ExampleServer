<?php
// Named copies of one session's conversation lines (ex_checkpoints from migration 002) and,
// since migration 006, of its memory notes (summary and diary).
// Shared by scripts/checkpoint.php and the dashboard's Checkpoints page.
// This file only defines functions and constants, so requesting it over HTTP prints nothing.
//
// Save, restore and delete each run in one transaction that takes the session lock first,
// before any row lock, in the same order as next_generation() and the final save in turn.php.
// Restore replaces only that session's lines and memory notes, deletes candidate facts the
// session proposed after the checkpoint, and makes every in-flight turn of the session stale,
// so a slow old reply (or its memory update) cannot be saved on top of the restored history.
// Approved facts are shared by every session and are never changed by a restore.
// This never reads or changes game saves.

require_once __DIR__ . '/events.php';

const MAX_CHECKPOINT_LINES = 2000;
const MAX_CHECKPOINTS_PER_SESSION = 20;

// Each function returns ['ok' => bool, 'message' => string]; list also returns 'rows'.
function checkpoint_failed(\PgSql\Connection $db, string $message): array
{
    db_query($db, 'ROLLBACK');
    return ['ok' => false, 'message' => $message];
}

function checkpoint_list(\PgSql\Connection $db, string $sessionId): array
{
    $rows = pg_fetch_all(db_query($db,
        'SELECT name, turn_count, created_at FROM ex_checkpoints WHERE session_id = $1 ORDER BY created_at, id LIMIT $2',
        [$sessionId, MAX_CHECKPOINTS_PER_SESSION]));
    return ['ok' => true, 'message' => $rows ? '' : "No checkpoints for session $sessionId.", 'rows' => $rows];
}

function checkpoint_save(\PgSql\Connection $db, string $sessionId, string $name): array
{
    // The lock gives a consistent copy: no turn of this session is saved meanwhile.
    db_query($db, 'BEGIN');
    lock_session($db, $sessionId);
    $count = (int)pg_fetch_result(db_query($db, 'SELECT count(*) FROM ex_checkpoints WHERE session_id = $1', [$sessionId]), 0, 0);
    if ($count >= MAX_CHECKPOINTS_PER_SESSION) {
        return checkpoint_failed($db, 'This session already has ' . MAX_CHECKPOINTS_PER_SESSION . ' checkpoints. Delete one first.');
    }
    $lines = (int)pg_fetch_result(db_query($db, 'SELECT count(*) FROM ex_turns WHERE session_id = $1', [$sessionId]), 0, 0);
    if ($lines > MAX_CHECKPOINT_LINES) {
        return checkpoint_failed($db, "This session has $lines lines; checkpoints hold at most " . MAX_CHECKPOINT_LINES . '.');
    }
    $result = db_query($db,
        "INSERT INTO ex_checkpoints (session_id, name, turn_count, turns, memory)
         SELECT $1, $2, count(*), coalesce(jsonb_agg(jsonb_build_object(
             'npc_id', npc_id, 'role', role, 'text', text, 'created_at', created_at) ORDER BY id), '[]'),
             (SELECT coalesce(jsonb_agg(jsonb_build_object('npc_id', npc_id, 'kind', kind, 'text', text, 'source', source,
                 'source_request_id', source_request_id, 'created_at', created_at) ORDER BY id), '[]')
              FROM ex_memory_notes WHERE session_id = $1)
         FROM ex_turns WHERE session_id = $1
         ON CONFLICT (session_id, name) DO NOTHING",
        [$sessionId, $name]);
    if (pg_affected_rows($result) === 0) {
        return checkpoint_failed($db, "Checkpoint $name already exists for session $sessionId. Choose another name.");
    }
    db_query($db, 'COMMIT');
    return ['ok' => true, 'message' => "Saved checkpoint $name for session $sessionId ($lines lines)."];
}

function checkpoint_delete(\PgSql\Connection $db, string $sessionId, string $name): array
{
    // The session lock waits for a running restore, so a restore never sees its
    // checkpoint vanish between finding it and copying its lines.
    db_query($db, 'BEGIN');
    lock_session($db, $sessionId);
    $result = db_query($db, 'DELETE FROM ex_checkpoints WHERE session_id = $1 AND name = $2', [$sessionId, $name]);
    if (pg_affected_rows($result) === 0) {
        return checkpoint_failed($db, "No checkpoint $name for session $sessionId.");
    }
    db_query($db, 'COMMIT');
    return ['ok' => true, 'message' => "Deleted checkpoint $name for session $sessionId."];
}

// One transaction, so the session's history either matches the checkpoint and every
// older turn is stale, or nothing changed. A successful restore is then logged in
// ex_events, which the restore never rolls back. $config only sets event retention.
function checkpoint_restore(\PgSql\Connection $db, string $sessionId, string $name, array $config = []): array
{
    db_query($db, 'BEGIN');
    lock_session($db, $sessionId);
    $checkpoint = db_query($db, 'SELECT id, turn_count, created_at FROM ex_checkpoints WHERE session_id = $1 AND name = $2', [$sessionId, $name]);
    if (pg_num_rows($checkpoint) === 0) {
        return checkpoint_failed($db, "No checkpoint $name for session $sessionId.");
    }
    $checkpointId = pg_fetch_result($checkpoint, 0, 0);
    $stale = db_query($db, 'UPDATE ex_generations SET generation = generation + 1 WHERE session_id = $1', [$sessionId]);
    db_query($db, 'DELETE FROM ex_turns WHERE session_id = $1', [$sessionId]);
    db_query($db,
        'INSERT INTO ex_turns (session_id, npc_id, role, text, created_at)
         SELECT $1, line.npc_id, line.role, line.text, line.created_at
         FROM ex_checkpoints,
             ROWS FROM (jsonb_to_recordset(turns) AS (npc_id text, role text, text text, created_at timestamptz))
             WITH ORDINALITY AS line(npc_id, role, text, created_at, position)
         WHERE ex_checkpoints.id = $2
         ORDER BY line.position',
        [$sessionId, $checkpointId]);
    // Memory notes come from this session's lines, so they go back to the checkpoint's copy
    // (none for checkpoints made before migration 006). Candidate facts proposed after the
    // checkpoint came from lines that no longer exist and are deleted. Approved facts are
    // shared knowledge and stay.
    db_query($db, 'DELETE FROM ex_memory_notes WHERE session_id = $1', [$sessionId]);
    db_query($db,
        'INSERT INTO ex_memory_notes (session_id, npc_id, kind, text, source, source_request_id, created_at)
         SELECT $1, note.npc_id, note.kind, note.text, note.source, note.source_request_id, note.created_at
         FROM ex_checkpoints,
             ROWS FROM (jsonb_to_recordset(coalesce(memory, \'[]\')) AS (npc_id text, kind text, text text, source text,
                 source_request_id text, created_at timestamptz))
             WITH ORDINALITY AS note(npc_id, kind, text, source, source_request_id, created_at, position)
         WHERE ex_checkpoints.id = $2
         ORDER BY note.position',
        [$sessionId, $checkpointId]);
    $candidates = db_query($db, 'DELETE FROM ex_memory_candidates WHERE session_id = $1 AND created_at > $2',
        [$sessionId, pg_fetch_result($checkpoint, 0, 2)]);
    db_query($db, 'COMMIT');
    $lines = pg_fetch_result($checkpoint, 0, 1);
    $npcs = pg_affected_rows($stale);
    $dropped = pg_affected_rows($candidates);
    $logged = event_log($db, $config, ['kind' => 'restore', 'status' => 'complete', 'session_id' => $sessionId,
        'detail' => "Restored checkpoint $name ($lines lines, memory notes); in-flight turns of $npcs NPC(s) made stale;"
            . " $dropped later candidate fact(s) removed."]) !== null;
    return ['ok' => true, 'logged' => $logged, 'message' => "Restored checkpoint $name for session $sessionId ($lines"
        . " lines and its memory notes). Any in-flight turn for its $npcs NPC(s) is now stale. $dropped candidate fact(s)"
        . ' proposed after the checkpoint were removed; approved shared facts are unchanged.'
        . ($logged ? '' : ' The restore could not be written to the event log.')];
}
