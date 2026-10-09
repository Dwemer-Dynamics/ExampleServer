<?php
// Database-only backups for the dashboard's Backups page.
// This file only defines functions and constants, so requesting it over HTTP prints nothing.
//
// - Dumps only this app's configured database with pg_dump --format=custom.
// - Runs fixed system binaries with an argument array (no shell). The password goes in
//   PGPASSWORD, never on the command line.
// - Writes into one private folder outside the web root, owned by the web server user
//   with mode 0700; dump files are 0600. Only names this code generated are listed or used.
// - "Verify" restores a chosen dump into a NEW, randomly named database and counts its rows.
//   It never restores over an existing database and never drops anything.
// The CLI script scripts/backup.sh is separate: it also copies config.php and needs sudo.

const BACKUP_MAX_BYTES = 100000000;     // 100 MB per dump
const BACKUP_MAX_SECONDS = 60;          // per pg_dump or pg_restore run
const BACKUP_MAX_FILES = 20;
const BACKUP_MAX_OUTPUT = 4096;         // bytes of tool output kept for the error log
const BACKUP_BINARIES = ['/usr/bin/', '/usr/local/bin/'];
const BACKUP_SYSTEM_DATABASES = ['postgres', 'template0', 'template1'];
const BACKUP_TABLES = ['ex_turns', 'ex_generations', 'ex_requests', 'ex_npc_bios', 'ex_knowledge', 'ex_checkpoints', 'ex_events',
    'ex_profiles', 'ex_npc_profiles', 'ex_memory_notes', 'ex_memory_candidates', 'ex_connector_calls'];

final class BackupError extends RuntimeException
{
}

function backup_database_name(array $config): string
{
    $name = $config['database']['name'] ?? 'example_ai_mod';
    if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name)) {
        throw new BackupError('database.name must be 1-63 lowercase letters, digits or underscores.');
    }
    return $name;
}

// The folder comes from the optional private setting backup_dir, otherwise an app-scoped
// folder in the system temp directory. It is never taken from the browser.
function backup_dir(array $config): string
{
    $setting = $config['backup_dir'] ?? '';
    if (!is_string($setting)) {
        throw new BackupError('backup_dir must be a string.');
    }
    if ($setting === '') {
        $app = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
        return rtrim(sys_get_temp_dir(), '/') . '/example-ai-backups-' . substr(hash('sha256', $app), 0, 12)
            . '-' . posix_geteuid();
    }
    if (!str_starts_with($setting, '/') || str_contains($setting, '/../') || str_ends_with($setting, '/..')
        || preg_match('/[\x00-\x1F\x7F]/', $setting)) {
        throw new BackupError('backup_dir must be an absolute path without "..".');
    }
    $dir = rtrim($setting, '/');
    $app = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $parent = realpath(dirname($dir));
    $webRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: null;
    $resolved = ($parent ?: dirname($dir)) . '/' . basename($dir);
    foreach (array_filter([$app, $webRoot]) as $inside) {
        if ($resolved === $inside || str_starts_with($resolved . '/', $inside . '/')) {
            throw new BackupError('backup_dir must be outside the web root and the app folder.');
        }
    }
    return $dir;
}

// Creates the folder if missing (mode 0700) and refuses one that anyone else could use.
function backup_ready_dir(array $config): string
{
    $dir = backup_dir($config);
    clearstatcache();
    if (is_link($dir)) {
        throw new BackupError('The backup folder is a symbolic link.');
    }
    if (!file_exists($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
        throw new BackupError('The backup folder could not be created.');
    }
    clearstatcache();
    if (!is_dir($dir) || is_link($dir)) {
        throw new BackupError('The backup path is not a folder.');
    }
    if (fileowner($dir) !== posix_geteuid()) {
        throw new BackupError('The backup folder is owned by ' . config_user_name(fileowner($dir))
            . ', not by the web server user ' . config_user_name(posix_geteuid()) . '.');
    }
    if ((fileperms($dir) & 0077) !== 0) {
        throw new BackupError('The backup folder must have mode 0700 (no group or other access).');
    }
    return $dir;
}

function backup_file_pattern(string $database): string
{
    return '/^' . preg_quote($database, '/') . '-\d{8}-\d{6}-[a-f0-9]{6}\.dump$/D';
}

// Lists managed dumps, newest first: regular files with a generated name, owned by us.
function backup_list(array $config): array
{
    $dir = backup_ready_dir($config);
    $pattern = backup_file_pattern(backup_database_name($config));
    $files = [];
    foreach (scandir($dir) ?: [] as $name) {
        $path = "$dir/$name";
        if (!preg_match($pattern, $name) || is_link($path) || !is_file($path) || fileowner($path) !== posix_geteuid()) {
            continue;
        }
        $verify = backup_read_verify("$path.verify.json");
        $files[] = ['name' => $name, 'bytes' => filesize($path), 'time' => filemtime($path), 'verify' => $verify];
    }
    usort($files, fn($a, $b) => [$b['time'], $b['name']] <=> [$a['time'], $a['name']]);
    return $files;
}

// Only a name from the current list can be used; anything else is refused.
function backup_find(array $config, mixed $name): string
{
    if (!is_string($name)) {
        throw new BackupError('Choose a backup from the list.');
    }
    foreach (backup_list($config) as $file) {
        if (hash_equals($file['name'], $name)) {
            return backup_ready_dir($config) . '/' . $file['name'];
        }
    }
    throw new BackupError('Choose a backup from the list.');
}

function backup_binary(string $name): string
{
    foreach (BACKUP_BINARIES as $dir) {
        if (is_file($dir . $name) && is_executable($dir . $name)) {
            return $dir . $name;
        }
    }
    throw new BackupError("$name was not found in /usr/bin or /usr/local/bin.");
}

function backup_connection_args(array $config): array
{
    $db = $config['database'] ?? [];
    $host = (string)($db['host'] ?? 'localhost');
    $port = (string)($db['port'] ?? 5432);
    $user = (string)($db['user'] ?? 'dwemer');
    if (!preg_match('/^[A-Za-z0-9_.:\/-]{1,255}$/D', $host) || !ctype_digit($port) || !preg_match('/^[A-Za-z0-9_]{1,63}$/D', $user)) {
        throw new BackupError('The database host, port or user setting is not valid for backups.');
    }
    return ['--host=' . $host, '--port=' . $port, '--username=' . $user, '--no-password'];
}

// Runs one fixed binary with a time limit. $watchFile is killed if it grows past the size cap.
// Returns the exit code; tool output goes only to the server error log, shortened.
function backup_run(array $command, array $config, ?string $watchFile = null): int
{
    $env = [
        'PATH' => '/usr/bin:/bin',
        'PGPASSWORD' => (string)($config['database']['password'] ?? ''),
        'PGCONNECT_TIMEOUT' => '5',
        'LC_ALL' => 'C',
    ];
    $pipes = [];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', $env);
    if (!is_resource($process)) {
        throw new BackupError('The backup tool could not be started.');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $deadline = microtime(true) + BACKUP_MAX_SECONDS;
    $stopped = '';
    while (true) {
        $status = proc_get_status($process);
        foreach ([1, 2] as $i) {
            while (($chunk = fread($pipes[$i], 8192)) !== false && $chunk !== '') {
                $output = substr($output . $chunk, 0, BACKUP_MAX_OUTPUT);
            }
        }
        if (!$status['running']) {
            $code = $status['exitcode'];
            break;
        }
        clearstatcache(true, (string)$watchFile);
        if ($watchFile !== null && is_file($watchFile) && filesize($watchFile) > BACKUP_MAX_BYTES) {
            $stopped = 'size';
        } elseif (microtime(true) > $deadline) {
            $stopped = 'time';
        }
        if ($stopped !== '') {
            proc_terminate($process, 15);
            usleep(200000);
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            $code = -1;
            break;
        }
        usleep(50000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    if ($output !== '' || $code !== 0) {
        error_log('example-ai dashboard: ' . basename($command[0]) . " exit $code: " . str_replace("\n", ' ', $output));
    }
    if ($stopped === 'size') {
        throw new BackupError('The backup was stopped because it grew past ' . (BACKUP_MAX_BYTES / 1000000) . ' MB.');
    }
    if ($stopped === 'time') {
        throw new BackupError('The backup tool was stopped after ' . BACKUP_MAX_SECONDS . ' seconds.');
    }
    return $code;
}

// One backup task at a time per folder. The lock file stays in place.
function backup_with_lock(string $dir, callable $task): mixed
{
    if (is_link("$dir/.lock")) {
        throw new BackupError('The backup lock file is a symbolic link.');
    }
    $lock = @fopen("$dir/.lock", 'c');
    if ($lock === false) {
        throw new BackupError('The backup lock file could not be opened.');
    }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new BackupError('Another backup task is running. Try again shortly.');
        }
        return $task();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// Creates one dump of this app's database. Returns the new file name.
function backup_create(array $config): string
{
    $database = backup_database_name($config);
    $dir = backup_ready_dir($config);
    $pgDump = backup_binary('pg_dump');
    return backup_with_lock($dir, function () use ($config, $database, $dir, $pgDump): string {
        if (count(backup_list($config)) >= BACKUP_MAX_FILES) {
            throw new BackupError('There are already ' . BACKUP_MAX_FILES . ' backups. Remove old ones from the backup folder first.');
        }
        $name = $database . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.dump';
        $partial = "$dir/.$name.partial";
        $old = umask(0077);
        try {
            $code = backup_run(array_merge([$pgDump, '--format=custom', '--file=' . $partial],
                backup_connection_args($config), ['--dbname=' . $database]), $config, $partial);
        } catch (BackupError $error) {
            @unlink($partial);
            throw $error;
        } finally {
            umask($old);
        }
        clearstatcache();
        if ($code !== 0 || !is_file($partial) || filesize($partial) < 5 || file_get_contents($partial, false, null, 0, 5) !== 'PGDMP') {
            @unlink($partial);
            throw new BackupError('pg_dump failed. Details are in the server error log.');
        }
        if (filesize($partial) > BACKUP_MAX_BYTES) {
            @unlink($partial);
            throw new BackupError('The backup is larger than ' . (BACKUP_MAX_BYTES / 1000000) . ' MB and was discarded.');
        }
        chmod($partial, 0600);
        if (!rename($partial, "$dir/$name")) {
            @unlink($partial);
            throw new BackupError('The backup file could not be finished.');
        }
        return $name;
    });
}

function backup_read_verify(string $path): ?array
{
    if (is_link($path) || !is_file($path) || filesize($path) > 16384) {
        return null;
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

// Row counts, migrations and a knowledge search in one database.
function backup_inspect(\PgSql\Connection $db): array
{
    $counts = [];
    foreach (BACKUP_TABLES as $table) {
        $result = @pg_query($db, "SELECT count(*) FROM $table");
        $counts[$table] = $result === false ? null : (int)pg_fetch_result($result, 0, 0);
    }
    $result = @pg_query($db, 'SELECT version FROM ex_schema_migrations ORDER BY version');
    $migrations = $result === false ? [] : array_column(pg_fetch_all($result), 'version');
    // Proves the generated search column still works: find one stored fact by its own words.
    $search = 'no facts';
    $fact = @pg_query($db, 'SELECT id, topic, fact FROM ex_knowledge ORDER BY id LIMIT 1');
    if ($fact !== false && pg_num_rows($fact) === 1) {
        $row = pg_fetch_assoc($fact);
        $terms = knowledge_search_terms($row['topic'] . ' ' . $row['fact']);
        $found = $terms === '' ? false : @pg_query_params($db,
            "SELECT 1 FROM ex_knowledge WHERE id = $1 AND search @@ to_tsquery('simple', $2)", [$row['id'], $terms]);
        $search = $found !== false && pg_num_rows($found) === 1 ? 'found' : 'not found';
    }
    // Facts with a stored vector (migration 006), so a verify shows embeddings were kept.
    $vectors = @pg_query($db, "SELECT count(*) FROM ex_knowledge WHERE jsonb_typeof(embedding) = 'array'");
    return ['counts' => $counts, 'migrations' => $migrations, 'fact_search' => $search,
        'fact_vectors' => $vectors === false ? null : (int)pg_fetch_result($vectors, 0, 0)];
}

function backup_connect(array $config, string $database): ?\PgSql\Connection
{
    return ui_database(['database' => ['name' => $database] + ($config['database'] ?? [])]);
}

// Restores a managed dump into a new database and compares it with the live one.
// The result is also kept next to the dump as <name>.verify.json (mode 0600).
function backup_verify(array $config, mixed $fileName): array
{
    $database = backup_database_name($config);
    $dir = backup_ready_dir($config);
    $pgRestore = backup_binary('pg_restore');
    return backup_with_lock($dir, function () use ($config, $fileName, $database, $dir, $pgRestore): array {
        $path = backup_find($config, $fileName);
        $live = backup_connect($config, $database);
        if ($live === null) {
            throw new BackupError('Database unavailable. Check PostgreSQL and your private settings.');
        }
        $role = @pg_query($live, 'SELECT rolcreatedb OR rolsuper FROM pg_roles WHERE rolname = current_user');
        if ($role === false || pg_fetch_result($role, 0, 0) !== 't') {
            throw new BackupError('The configured database role may not create databases (CREATEDB). Nothing was restored.');
        }
        // <database>_verify_<12 hex>, shortened to the 63-byte identifier limit.
        $target = substr($database, 0, 63 - 20) . '_verify_' . bin2hex(random_bytes(6));
        if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $target) || $target === $database
            || in_array($target, BACKUP_SYSTEM_DATABASES, true)) {
            throw new BackupError('Could not choose a safe new database name.');
        }
        $exists = @pg_query_params($live, 'SELECT 1 FROM pg_database WHERE datname = $1', [$target]);
        if ($exists === false || pg_num_rows($exists) !== 0) {
            throw new BackupError('The generated database name already exists. Try again.');
        }
        if (@pg_query($live, 'CREATE DATABASE ' . pg_escape_identifier($live, $target) . " TEMPLATE template0 ENCODING 'UTF8'") === false) {
            error_log('example-ai dashboard: CREATE DATABASE failed: ' . pg_last_error($live));
            throw new BackupError('The new database could not be created. Check the role\'s CREATEDB permission.');
        }
        $result = ['backup' => basename($path), 'database' => $target, 'checked_at' => gmdate('Y-m-d H:i:s') . ' UTC'];
        $code = backup_run(array_merge([$pgRestore, '--no-owner', '--no-privileges', '--exit-on-error'],
            backup_connection_args($config), ['--dbname=' . $target, $path]), $config);
        $restored = backup_connect($config, $target);
        if ($code !== 0 || $restored === null) {
            $result['ok'] = false;
            $result['message'] = 'pg_restore failed. The new database ' . $target . ' was kept for inspection; nothing was dropped.';
        } else {
            $result += ['restored' => backup_inspect($restored), 'live' => backup_inspect($live)];
            $expected = array_map(fn($file) => basename($file, '.sql'), glob(dirname(__DIR__) . '/sql/*.sql') ?: []);
            $result['ok'] = !array_diff($expected, $result['restored']['migrations'])
                && !in_array(null, $result['restored']['counts'], true)
                && $result['restored']['fact_search'] !== 'not found';
            $result['message'] = $result['ok'] ? 'The backup restored into a new database and its tables could be read.'
                : 'The backup restored, but tables or migrations are missing.';
        }
        $old = umask(0077);
        file_put_contents("$path.verify.json", json_encode($result, JSON_UNESCAPED_SLASHES), LOCK_EX);
        umask($old);
        return $result;
    });
}
