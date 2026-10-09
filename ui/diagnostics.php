<?php
// Real readiness checks, run when the page loads. Read-only: no network calls to providers,
// no services started, no migrations applied and no secrets or private paths shown.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/config_writer.php';
require __DIR__ . '/../lib/knowledge.php';
require __DIR__ . '/../lib/backup.php';

$checks = [];
// Required checks are Ready or Failed. Optional services are Disabled, Not configured or
// Not tested: this page never contacts them, so it cannot say they work.
function check(string $name, bool $ok, string $detail): void
{
    status_check($name, $ok ? 'Ready' : 'Failed', $detail);
}
function status_check(string $name, string $status, string $detail): void
{
    global $checks;
    $checks[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
}

check('PHP version', PHP_VERSION_ID >= 80200, PHP_VERSION . ' (8.2 or newer needed)');
foreach (['pgsql', 'curl', 'mbstring', 'posix'] as $extension) {
    check("PHP extension $extension", extension_loaded($extension), extension_loaded($extension) ? 'Loaded' : 'Missing');
}
$config = ui_config();
check('Config', $config !== null, $config !== null ? 'Readable, with a real token' : 'Missing, unreadable or still has the placeholder token');
$expected = SCHEMA_MIGRATIONS;
$manifestProblem = schema_manifest_problem();
if ($manifestProblem !== null) {
    check('Migration list', false, $manifestProblem[1]);
}
$migrations = [];
$schemaReady = false;
if ($config !== null) {
    $problems = function_exists('posix_geteuid') ? config_write_problems(config_file_path()) : ['posix extension missing.'];
    check('Dashboard can save settings', !$problems, $problems ? implode(' ', $problems) : 'Config file and folder are writable; owner, group and mode can be kept.');
    $db = ui_database($config);
    check('Database connection', $db !== null, $db !== null ? 'Connected to ' . ($config['database']['name'] ?? 'example_ai_mod') : 'Could not connect. Check PostgreSQL and your private settings.');
    if ($db !== null) {
        $result = @pg_query($db, 'SELECT version FROM ex_schema_migrations ORDER BY version');
        $migrations = $result === false ? [] : array_column(pg_fetch_all($result), 'version');
        $missing = array_diff($expected, $migrations);
        $schemaReady = $result !== false && !$missing && $manifestProblem === null;
        check('Migrations', $schemaReady, $result === false ? 'No migration table. ' . MIGRATE_HINT
            : ($missing ? 'Missing: ' . implode(', ', $missing) . '. ' . MIGRATE_HINT : 'All applied: ' . implode(', ', $migrations)));
        $unknown = array_diff($migrations, $expected);
        if ($unknown) {
            check('Schema age', false, 'The database has migrations this code does not know (' . implode(', ', $unknown) . '). Update this checkout.');
        }
        if ($schemaReady) {
            $counts = [];
            foreach (BACKUP_TABLES as $table) {
                $count = @pg_query($db, "SELECT count(*) FROM $table");
                $counts[] = $table . ' ' . ($count === false ? '?' : pg_fetch_result($count, 0, 0));
            }
            check('Tables', true, implode(', ', $counts));
        }
    }
    // Settings only: whether each optional service is on. Nothing is contacted from here.
    $mode = (string)($config['llm']['mode'] ?? 'mock');
    $model = (string)($config['llm']['model'] ?? '');
    if ($mode === 'mock') {
        status_check('LLM', 'Ready', 'mock mode: predictable replies, no provider.');
    } elseif ($model === '') {
        status_check('LLM', 'Not configured', "$mode mode, model missing. Set it on the LLM page.");
    } else {
        status_check('LLM', 'Not tested', "$mode mode, model set. Send a test turn from Conversation.");
    }
    $optional = [
        'Text to speech' => [!empty($config['tts']['enabled']), 'Enabled (' . ($config['tts']['provider'] ?? 'pockettts') . '). Test it on the Voice page.'],
        'Speech to text' => [!empty($config['stt']['enabled']), 'Enabled (' . ($config['stt']['provider'] ?? 'fasterwhisper') . '). Test it on the Voice page.'],
        'NPC selection' => [!empty($config['decision']['enabled']), 'Enabled (' . ($config['decision']['provider'] ?? 'mock') . '). Test it on the NPC selection page.'],
        'Advanced memory' => [!empty($config['memory']['enabled']), 'Enabled (automatic notes ' . (!empty($config['memory']['auto_notes']) ? 'on' : 'off')
            . ', fact proposals ' . (!empty($config['memory']['auto_extract']) ? 'on' : 'off') . '). Review it on Roleplay > Memory.'],
        'Vector search' => [!empty($config['embeddings']['enabled']), 'Enabled (' . ($config['embeddings']['provider'] ?? 'mock') . '). Reindex and check counts on Memory settings.'],
    ];
    foreach ($optional as $name => [$enabled, $detail]) {
        status_check($name, $enabled ? 'Not tested' : 'Disabled', $enabled ? $detail : 'Disabled (optional; off by default).');
    }
    try {
        backup_dir($config);
        backup_binary('pg_dump');
        backup_binary('pg_restore');
        check('Backup tools', true, 'pg_dump and pg_restore found');
    } catch (BackupError $error) {
        check('Backup tools', false, $error->getMessage());
    }
}
// What health.php reports to the game: the protocol, the needed schema and optional features.
status_check('Server version', 'Ready', SERVER_VERSION . ' (this server release, set in lib/version.php; health.php reports it as server_version).');
status_check('Baseline', 'Ready', BASELINE_ID . ' (example template baseline, not a release or schema number). Needs migrations '
    . implode(', ', SCHEMA_MIGRATIONS) . ', in order.');
status_check('Protocol', 'Ready', 'Version ' . PROTOCOL_VERSION . '. health.php reports server_version ' . SERVER_VERSION . ', baseline ' . BASELINE_ID . ', expected_schema ' . SCHEMA_VERSION
    . ' and capabilities: ' . implode(', ', SERVER_CAPABILITIES) . '. Clients check it with example_mod.exe --health.');
// Ready only when every required check passed: one Failed row (such as unknown migrations
// from a newer checkout, or missing backup tools) means setup is needed.
$failed = array_column(array_filter($checks, fn($item) => $item['status'] === 'Failed'), 'name');
$ready = $config !== null && $schemaReady && !$failed;
$title = 'Diagnostics';
$active = 'diagnostics';
require __DIR__ . '/tmpl/header.php';
?>
<section class="chim-panel">
    <p>Live checks made when this page loaded. The page says Ready only when no check failed. The game's <code>health.php</code> checks less: a valid config, the database, every migration up to <code><?= e(SCHEMA_VERSION) ?></code> and no unknown newer ones, so it can answer ready while a check here (for example backup tools) has failed. Optional services are not contacted here, so they show Disabled or Not tested; use their test buttons. Finished turns appear in <a href="logs.php">Logs</a>.</p>
    <p><strong class="status-label"><?= $ready ? 'Ready' : 'Setup needed' ?></strong><?= $failed ? ' Failed: ' . e(implode(', ', $failed)) . '.' : '' ?> <a href="diagnostics.php">Check again</a></p>
    <div class="table-wrap"><table class="dense-table"><thead><tr><th scope="col">Check</th><th scope="col">Result</th><th scope="col">Detail</th></tr></thead><tbody>
    <?php foreach ($checks as $item): ?>
        <?php $class = ['Ready' => 'chim-badge-success', 'Failed' => 'chim-badge-danger', 'Not configured' => 'chim-badge-accent'][$item['status']] ?? ''; ?>
        <tr><td><?= e($item['name']) ?></td><td><span class="chim-tag <?= $class ?>"><?= e($item['status']) ?></span></td><td class="reply"><?= e($item['detail']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
