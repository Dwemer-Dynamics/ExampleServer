<?php
// Applies sql/*.sql files in name order, each once. Safe to run again, also at the same time:
// a second runner waits for the first, then finds nothing left to apply.
// Usage: php scripts/migrate.php
// Exit codes: 0 done, 1 config missing or invalid, or the database connection failed (the
// shared load_config()/db_connect() checks), 2 a migration failed (that file was rolled back),
// 3 refused, nothing changed: sql/ does not match SCHEMA_MIGRATIONS, or the database is newer
// than this code or has a gap, 4 connected, but the lock or migration table could not be used.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/app.php';

const MIGRATE_FAILED = 2;
const MIGRATE_REFUSED = 3;
const MIGRATE_UNAVAILABLE = 4;

function migrate_stop(int $code, string $message): never
{
    fwrite(STDERR, "migrate: $message\n");
    // Closing the connection also ends any open transaction and releases the session lock.
    exit($code);
}

// Runs SQL; on failure rolls back any open transaction and stops with $code.
function migrate_sql(\PgSql\Connection $db, string $sql, array $params, int $code, string $what): \PgSql\Result
{
    $result = $params ? @pg_query_params($db, $sql, $params) : @pg_query($db, $sql);
    if ($result === false) {
        $error = trim(pg_last_error($db));
        @pg_query($db, 'ROLLBACK');
        migrate_stop($code, "$what: $error");
    }
    return $result;
}

// The ordered list in lib/app.php is the authority. Check it against sql/ before touching the
// database, so an unlisted file is never applied silently.
$problem = schema_manifest_problem();
if ($problem !== null) {
    migrate_stop(MIGRATE_REFUSED, $problem[1] . ' Nothing was changed.');
}
$known = SCHEMA_MIGRATIONS;

$db = db_connect(load_config());

// One runner at a time, taken before the migration table exists. A session lock (not a
// transaction lock) so it covers every file; it ends with the connection at the latest.
if (pg_fetch_result(migrate_sql($db, "SELECT pg_try_advisory_lock(hashtext('ex_migrate'))", [], MIGRATE_UNAVAILABLE, 'lock'), 0, 0) !== 't') {
    echo "waiting for another migration runner\n";
    migrate_sql($db, "SELECT pg_advisory_lock(hashtext('ex_migrate'))", [], MIGRATE_UNAVAILABLE, 'lock');
}
migrate_sql($db, 'CREATE TABLE IF NOT EXISTS ex_schema_migrations (
    version text PRIMARY KEY,
    applied_at timestamptz NOT NULL DEFAULT now()
)', [], MIGRATE_UNAVAILABLE, 'migration table');

$applied = array_column(pg_fetch_all(migrate_sql($db, 'SELECT version FROM ex_schema_migrations ORDER BY version', [],
    MIGRATE_UNAVAILABLE, 'read applied')), 'version');

// Refuse before changing anything when the database does not fit this code.
$unknown = array_diff($applied, $known);
if ($unknown) {
    migrate_stop(MIGRATE_REFUSED, 'the database has migrations this code does not know (' . implode(', ', $unknown)
        . '). It is newer than this checkout; update the code, nothing was changed.');
}
$seenMissing = null;
foreach ($known as $version) {
    $isApplied = in_array($version, $applied, true);
    if (!$isApplied && $seenMissing === null) {
        $seenMissing = $version;
    } elseif ($isApplied && $seenMissing !== null) {
        migrate_stop(MIGRATE_REFUSED, "$version is applied but the earlier $seenMissing is not. Restore a backup or "
            . 'apply it by hand; nothing was changed.');
    }
}

foreach ($known as $version) {
    $file = __DIR__ . "/../sql/$version.sql";
    if (in_array($version, $applied, true)) {
        echo "already applied: $version\n";
        continue;
    }
    $sql = file_get_contents($file);
    if ($sql === false) {
        migrate_stop(MIGRATE_FAILED, "cannot read $version");
    }
    // One transaction per file, database only: a failed file leaves no partial schema behind.
    migrate_sql($db, 'BEGIN', [], MIGRATE_FAILED, "failed: $version");
    migrate_sql($db, $sql, [], MIGRATE_FAILED, "failed: $version (rolled back)");
    migrate_sql($db, 'INSERT INTO ex_schema_migrations (version) VALUES ($1)', [$version], MIGRATE_FAILED, "failed: $version");
    migrate_sql($db, 'COMMIT', [], MIGRATE_FAILED, "failed: $version");
    echo "applied: $version\n";
}
migrate_sql($db, "SELECT pg_advisory_unlock(hashtext('ex_migrate'))", [], MIGRATE_UNAVAILABLE, 'unlock');
echo 'schema ready for ' . BASELINE_ID . "\n";
