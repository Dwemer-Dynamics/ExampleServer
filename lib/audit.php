<?php
// Connector audit (ex_connector_calls from migration 007): one row per voice, NPC selection,
// embedding or memory call, for the Logs page's "Connector calls" view.
// This file only defines functions and constants, so requesting it over HTTP prints nothing.
//
// - Rows hold ids, a fixed kind and provider name, a status, a short error code, a duration
//   and a short detail written by server code. Never audio, transcripts, prompts, provider
//   bodies, URLs or keys.
// - Writing is best effort and never changes the reply it describes: endpoints write after
//   the reply was sent (reply_then() in app.php), turns write outside any transaction.

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/events.php';

const AUDIT_KINDS = ['tts', 'stt', 'decision', 'embedding', 'memory', 'extraction'];
const AUDIT_STATUSES = ['ok', 'failed', 'disabled', 'fallback', 'stale'];

// An id for a call made on behalf of another request (its parent), e.g. "emb-..." under a turn.
function audit_child_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(10));
}

// A provider name from config, kept only if it is a short lowercase word.
function audit_provider(mixed $name): ?string
{
    return is_string($name) && preg_match('/^[a-z0-9_]{1,32}$/D', $name) ? $name : null;
}

// Inserts one row on an open connection whose schema is ready, then keeps the newest
// event_log_limit rows. Returns false (error logged) when it could not be stored.
function audit_insert(\PgSql\Connection $db, array $config, array $row): bool
{
    $id = fn(mixed $value) => is_string($value) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $value) ? $value : null;
    $code = $row['error_code'] ?? null;
    $result = @pg_query_params($db,
        'INSERT INTO ex_connector_calls (kind, provider, status, error_code, duration_ms, request_id, parent_request_id,
             session_id, npc_id, detail) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)',
        [
            $row['kind'],
            audit_provider($row['provider'] ?? null),
            $row['status'],
            is_string($code) && preg_match('/^[a-z_]{1,40}$/D', $code) ? $code : null,
            isset($row['duration_ms']) ? max(0, (int)$row['duration_ms']) : null,
            $id($row['request_id'] ?? null),
            $id($row['parent_request_id'] ?? null),
            $id($row['session_id'] ?? null),
            $id($row['npc_id'] ?? null),
            isset($row['detail']) ? mb_substr((string)$row['detail'], 0, 200) : null,
        ]);
    if ($result === false) {
        app_log('connector audit write failed: ' . pg_last_error($db));
        return false;
    }
    @pg_query_params($db, 'DELETE FROM ex_connector_calls WHERE id < (SELECT id FROM ex_connector_calls ORDER BY id DESC OFFSET $1 LIMIT 1)',
        [event_log_limit($config) - 1]);
    return true;
}

// For endpoints without their own connection: connects quietly, checks the schema like every
// other write, then inserts. Any problem is only logged.
function audit_record(array $config, array $row, ?\PgSql\Connection $db = null): bool
{
    $db ??= db_connect_quiet($config);
    if ($db === null) {
        app_log('connector audit skipped: database unavailable');
        return false;
    }
    $problem = schema_problem($db);
    if ($problem !== null) {
        app_log('connector audit skipped: ' . $problem[0]);
        return false;
    }
    return audit_insert($db, $config, $row);
}
