<?php
// Shared helpers for the example server.
// This file only defines functions, so requesting it over HTTP prints nothing.

require_once __DIR__ . '/version.php';

const PROTOCOL_VERSION = 1;
const MAX_JSON_BYTES = 16384;
// Which example baseline this code is. It names the template's feature set for health checks and
// support; it is not a product release, the protocol version or a schema version.
const BASELINE_ID = 'baseline-1';
// Every migration this code needs, in order: exactly the sql/*.sql file names. To add one, add the
// new sql/NNN_name.sql file and append its name here; the newest is always the last entry.
const SCHEMA_MIGRATIONS = ['001_init', '002_npc_knowledge', '003_event_log', '004_game_events', '005_profiles',
    '006_memory', '007_connector_calls'];
define('SCHEMA_VERSION', SCHEMA_MIGRATIONS[count(SCHEMA_MIGRATIONS) - 1]);
// What to run when migrations are missing. The installer needs root and applies them.
const MIGRATE_HINT = 'Run "sudo bash scripts/install.sh" inside WSL from the server folder.';
// Optional protocol 1 features this server has, reported by health.php so a client can check
// before relying on them. Older servers send no list.
const SERVER_CAPABILITIES = ['turn', 'cancel', 'result', 'game_event', 'action_target', 'decision', 'speak', 'listen',
    'profiles', 'memory', 'trace'];

// The request id of this HTTP request, once known: the caller's validated request_id or one
// made by trace_start(). It is added to JSON replies, the X-Request-Id header and error log lines.
function trace_id(?string $set = null): ?string
{
    static $id = null;
    if ($set !== null) {
        $id = $set;
    }
    return $id;
}

// Uses the optional request_id a caller sent (8-64 id characters) or, when an older client sent
// none, makes one starting with "srv-". Returns it.
function trace_start(mixed $given): string
{
    $id = $given === null || $given === '' ? 'srv-' . bin2hex(random_bytes(12)) : require_id($given, 'request_id', 8);
    return trace_id($id);
}

// One error log line, prefixed with the request id when there is one. Callers pass short
// server-made text only: never tokens, keys, prompts, transcripts or provider reply bodies.
function app_log(string $message): void
{
    $id = trace_id();
    error_log('example-ai' . ($id !== null ? " [$id]" : '') . ': ' . $message);
}

// The JSON reply body. A known request id is added unless the reply already names one.
function json_reply(array $data): string
{
    $id = trace_id();
    if ($id !== null && !array_key_exists('request_id', $data)) {
        $data['request_id'] = $id;
    }
    return json_encode(['protocol' => PROTOCOL_VERSION] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

// Sends a JSON reply and stops.
function send_json(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (trace_id() !== null) {
        header('X-Request-Id: ' . trace_id());
    }
    echo json_reply($data);
    exit(PHP_SAPI === 'cli' && $status >= 400 ? 1 : 0);
}

// Sends a complete reply, lets the client go, then runs $after and stops. $after is best-effort
// work the caller must not wait for and that can never change the reply (connector audit rows,
// opt-in memory updates); its failures only reach the error log. Content-Length lets the client
// finish reading even where the PHP SAPI cannot close the connection early.
function reply_then(int $status, string $contentType, string $body, callable $after, array $headers = []): never
{
    http_response_code($status);
    header("Content-Type: $contentType");
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    if (trace_id() !== null) {
        header('X-Request-Id: ' . trace_id());
    }
    foreach ($headers as $line) {
        header($line);
    }
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
    ignore_user_abort(true);
    try {
        $after();
    } catch (Throwable $error) {
        app_log('work after the reply failed (' . get_class($error) . ')');
    }
    exit(PHP_SAPI === 'cli' && $status >= 400 ? 1 : 0);
}

function send_json_then(int $status, array $data, callable $after): never
{
    reply_then($status, 'application/json; charset=utf-8', json_reply($data), $after);
}

// Sends a generic error. Put details in app_log(), never in the reply.
function fail(int $status, string $code, string $message): never
{
    send_json($status, ['ok' => false, 'error' => ['code' => $code, 'message' => $message]]);
}

function load_config(): array
{
    $path = getenv('EXAMPLE_AI_CONFIG') ?: __DIR__ . '/../config/config.php';
    if (!is_readable($path)) {
        app_log("config not readable: $path");
        fail(500, 'server_not_configured', 'The server is not configured.');
    }
    $config = require $path;
    $token = $config['token'] ?? '';
    if (!is_array($config) || !is_string($token) || strlen($token) < 16 || str_contains($token, 'CHANGE_ME')) {
        app_log('config is missing a real token');
        fail(500, 'server_not_configured', 'The server is not configured.');
    }
    return $config;
}

// Checks the HTTP method, loads config and, unless $public, checks the token.
function start_request(string $method, bool $public = false): array
{
    ini_set('display_errors', '0');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        fail(405, 'method_not_allowed', "Use $method.");
    }
    $config = load_config();
    if (!$public) {
        require_token($config['token']);
    }
    return $config;
}

function require_token(string $expected): void
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $header = $headers['authorization'] ?? '';
    }
    $given = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
    if (!hash_equals($expected, $given)) {
        fail(401, 'unauthorized', 'Missing or wrong token.');
    }
}

// Reads at most $maxBytes of request body.
function read_body(int $maxBytes): string
{
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
        fail(413, 'too_large', 'The request body is too large.');
    }
    $body = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($body === false || strlen($body) > $maxBytes) {
        fail(413, 'too_large', 'The request body is too large.');
    }
    return $body;
}

// Reads a JSON object and checks the protocol version.
function read_json_body(): array
{
    $data = json_decode(read_body(MAX_JSON_BYTES), true);
    if (!is_array($data) || array_is_list($data)) {
        fail(400, 'bad_json', 'The body must be a JSON object.');
    }
    if (($data['protocol'] ?? null) !== PROTOCOL_VERSION) {
        fail(400, 'bad_request', 'protocol must be ' . PROTOCOL_VERSION . '.');
    }
    return $data;
}

// Ids are short and limited to safe characters.
function require_id(mixed $value, string $field, int $min = 1): string
{
    if (!is_string($value) || !preg_match('/^[A-Za-z0-9_.:-]{' . $min . ',64}$/', $value)) {
        fail(400, 'bad_request', "$field must be $min-64 characters of A-Z a-z 0-9 _ . : -");
    }
    return $value;
}

// One line of free text: control characters (line breaks too) become spaces, then it is trimmed.
// Invalid UTF-8 gives ''. API input, dashboard forms and stored notes all use this cleaning.
function clean_line(string $text): string
{
    return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '');
}

// Free text is cleaned with clean_line() and its length checked.
function require_text(mixed $value, string $field, int $max): string
{
    $text = is_string($value) ? clean_line($value) : '';
    if ($text === '' || mb_strlen($text) > $max) {
        fail(400, 'bad_request', "$field must be 1-$max characters.");
    }
    return $text;
}

function db_connect(array $config): \PgSql\Connection
{
    $conn = db_connect_quiet($config);
    if ($conn === null) {
        app_log('database connection failed');
        fail(503, 'database_unavailable', 'The database is unavailable.');
    }
    return $conn;
}

// Like db_connect(), but returns null instead of answering, for optional work (profile voice
// lookup, audit rows) that must never turn a reply into an error.
function db_connect_quiet(array $config): ?\PgSql\Connection
{
    $db = $config['database'] ?? [];
    $quote = fn($value) => "'" . addcslashes((string)$value, "'\\") . "'";
    $dsn = 'host=' . $quote($db['host'] ?? 'localhost')
        . ' port=' . $quote($db['port'] ?? 5432)
        . ' dbname=' . $quote($db['name'] ?? 'example_ai_mod')
        . ' user=' . $quote($db['user'] ?? 'dwemer')
        . ' password=' . $quote($db['password'] ?? '')
        . ' connect_timeout=5';
    $conn = @pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
    return $conn === false ? null : $conn;
}

// Runs one parameterised statement. Never put user values into $sql.
function db_query(\PgSql\Connection $conn, string $sql, array $params = []): \PgSql\Result
{
    $result = @pg_query_params($conn, $sql, $params);
    if ($result === false) {
        app_log('query failed: ' . pg_last_error($conn));
        fail(500, 'internal_error', 'Internal server error.');
    }
    return $result;
}

// Like db_query(), but returns null (error logged) instead of answering. For work that runs
// after the reply was sent, where an error reply is no longer possible.
function db_try(\PgSql\Connection $conn, string $sql, array $params = []): ?\PgSql\Result
{
    $result = @pg_query_params($conn, $sql, $params);
    if ($result === false) {
        app_log('query failed: ' . pg_last_error($conn));
        return null;
    }
    return $result;
}

// Takes the per-session lock until the current transaction ends. Generation starts,
// final turn saves and checkpoint restores all take it first, before any FOR UPDATE,
// so they run one at a time per session and always lock in the same order.
function lock_session(\PgSql\Connection $conn, string $sessionId): void
{
    db_query($conn, "SELECT pg_advisory_xact_lock(hashtext('ex_session'), hashtext($1))", [$sessionId]);
}

// Records a request id. Returns false when it was already used: turns and game events share
// ex_requests, so each id is accepted once. forget_old_request_ids() drops ids after a day.
function claim_request_id(\PgSql\Connection $conn, string $requestId, string $sessionId, string $npcId): bool
{
    $insert = db_query($conn,
        'INSERT INTO ex_requests (request_id, session_id, npc_id) VALUES ($1, $2, $3) ON CONFLICT DO NOTHING',
        [$requestId, $sessionId, $npcId]);
    return pg_affected_rows($insert) > 0;
}

function forget_old_request_ids(\PgSql\Connection $conn): void
{
    db_query($conn, "DELETE FROM ex_requests WHERE created_at < now() - interval '1 day'");
}

// Starts a new generation for one NPC in one session and returns it.
// Any older in-flight turn for that NPC becomes stale. $beforeCommit, if given, runs
// database-only work in the same transaction with the new generation.
function next_generation(\PgSql\Connection $conn, string $sessionId, string $npcId, ?callable $beforeCommit = null): int
{
    db_query($conn, 'BEGIN');
    lock_session($conn, $sessionId);
    $result = db_query($conn,
        'INSERT INTO ex_generations (session_id, npc_id, generation) VALUES ($1, $2, 1)
         ON CONFLICT (session_id, npc_id) DO UPDATE SET generation = ex_generations.generation + 1
         RETURNING generation',
        [$sessionId, $npcId]);
    $generation = (int)pg_fetch_result($result, 0, 0);
    if ($beforeCommit !== null) {
        $beforeCommit($generation);
    }
    db_query($conn, 'COMMIT');
    return $generation;
}

// Checks that sql/*.sql matches SCHEMA_MIGRATIONS exactly and in order. Returns a problem
// [code, message] or null. Reads only file names.
function schema_manifest_problem(): ?array
{
    $files = array_map(fn($file) => basename($file, '.sql'), glob(__DIR__ . '/../sql/*.sql') ?: []);
    sort($files, SORT_STRING);
    if ($files !== SCHEMA_MIGRATIONS) {
        app_log('sql/ files (' . implode(', ', $files) . ') do not match SCHEMA_MIGRATIONS ('
            . implode(', ', SCHEMA_MIGRATIONS) . ')');
        return ['schema_mismatch', 'The sql/ folder does not match SCHEMA_MIGRATIONS in lib/app.php. '
            . 'Add each new sql/NNN_name.sql file name to that list, in order.'];
    }
    return null;
}

// The one schema check for health, pages and every database write: the manifest matches and
// the applied migrations are exactly SCHEMA_MIGRATIONS (none missing, no gap, none unknown).
// One query. Returns a problem [code, message] or null when ready.
function schema_problem(\PgSql\Connection $db): ?array
{
    $problem = schema_manifest_problem();
    if ($problem !== null) {
        return $problem;
    }
    $result = @pg_query($db, 'SELECT version FROM ex_schema_migrations ORDER BY version');
    $applied = $result === false ? [] : array_column(pg_fetch_all($result), 'version');
    $missing = array_values(array_diff(SCHEMA_MIGRATIONS, $applied));
    if ($missing) {
        return ['not_migrated', 'The database needs migration ' . $missing[0] . '. ' . MIGRATE_HINT];
    }
    // Migrations this code does not know come from a newer checkout; this code may misread them.
    $unknown = array_values(array_diff($applied, SCHEMA_MIGRATIONS));
    if ($unknown) {
        app_log('database has newer migrations: ' . implode(', ', $unknown));
        return ['schema_too_new', 'The database is newer than this server code. Update the server checkout.'];
    }
    return null;
}

// Answers 503 (JSON, or exit 1 on the command line) unless schema_problem() finds nothing.
// Call it before any database write.
function require_schema(\PgSql\Connection $db): void
{
    $problem = schema_problem($db);
    if ($problem !== null) {
        fail(503, $problem[0], $problem[1]);
    }
}

// A WAV file starts with "RIFF", a 4-byte size, then "WAVE".
function is_wav(string $bytes): bool
{
    return strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WAVE';
}

// Small curl POST with timeouts, a response size cap and no redirects. Throws on transport
// errors; the message names only the host, never the URL's path, query or any header.
function http_post(string $url, array $headers, string|array $body, int $timeoutSeconds, int $maxBytes): array
{
    $received = '';
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => max(1, min($timeoutSeconds, 120)),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$received, $maxBytes): int {
            $received .= $chunk;
            return strlen($received) > $maxBytes ? 0 : strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($ok === false) {
        throw new RuntimeException('POST to ' . (parse_url($url, PHP_URL_HOST) ?: 'an invalid URL') . " failed: $error");
    }
    return [$status, $received];
}
