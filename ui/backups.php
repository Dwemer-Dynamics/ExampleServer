<?php
// Database-only backups of this app's database (see lib/backup.php for the limits).
// Create writes a pg_dump file into a private folder; Verify restores one chosen dump into
// a NEW randomly named database and compares its tables. Nothing is ever restored over an
// existing database, nothing is dropped and no file can be downloaded from here.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/config_writer.php';
require __DIR__ . '/../lib/knowledge.php';
require __DIR__ . '/../lib/backup.php';

$config = ui_config();
$notice = '';
$message = '';
$verified = null;
$files = [];
if ($config === null) {
    $notice = 'Server configuration is missing or invalid.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!check_csrf()) {
        http_response_code(403);
        $message = 'This form expired. Reload and try again.';
    } elseif (!in_array($action, ['create', 'verify'], true)) {
        http_response_code(400);
        $message = 'Unknown form action.';
    } else {
        // Release the PHP session lock before the dump or restore runs.
        session_write_close();
        set_time_limit(2 * BACKUP_MAX_SECONDS + 30);
        try {
            if ($action === 'create') {
                $name = backup_create($config);
                header('Location: backups.php?' . http_build_query(['created' => $name]), true, 303);
                exit;
            }
            $verified = backup_verify($config, $_POST['file'] ?? null);
        } catch (BackupError $error) {
            http_response_code(409);
            $message = $error->getMessage();
        } catch (Throwable $error) {
            error_log('example-ai dashboard: backup task failed: ' . get_class($error));
            http_response_code(500);
            $message = 'The backup task failed. Nothing was changed in the live database.';
        }
    }
}
if ($config !== null) {
    try {
        $files = backup_list($config);
        $folderNote = ($config['backup_dir'] ?? '') === ''
            ? 'Backups go to a private app folder in the system temp directory, which may be emptied on restart. Set backup_dir in the private config to keep them.'
            : 'Backups go to the private folder set as backup_dir in the server config.';
    } catch (BackupError $error) {
        $notice = $error->getMessage();
    }
}
$created = is_string($_GET['created'] ?? null) && in_array($_GET['created'], array_column($files, 'name'), true) ? $_GET['created'] : '';
$title = 'Backups';
$active = 'backups';
require __DIR__ . '/tmpl/header.php';
?>
<section class="chim-panel">
    <p>Database-only backups of this example's own database, made with <code>pg_dump</code>. They do not include <code>config/config.php</code>; the command-line <code>sudo bash scripts/backup.sh</code> copies both. At most <?= BACKUP_MAX_FILES ?> backups of <?= BACKUP_MAX_BYTES / 1000000 ?> MB each, <?= BACKUP_MAX_SECONDS ?> seconds per run.</p>
    <?php if ($notice !== ''): ?><p class="notice" role="alert"><?= e($notice) ?></p><?php else: ?>
    <p class="help"><?= e($folderNote) ?> Files are only listed here, never downloaded.</p>
    <?php if ($created !== ''): ?><p class="notice" role="status">Backup <?= e($created) ?> created.</p><?php endif; ?>
    <?php if ($message !== ''): ?><p class="notice" role="alert"><?= e($message) ?></p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="create">
        <button type="submit">Create backup now</button>
    </form>
    <?php endif; ?>
</section>
<?php if ($verified !== null): ?>
<section class="chim-panel" role="status" aria-labelledby="verify-title">
    <h2 id="verify-title">Verify result: <?= $verified['ok'] ? 'OK' : 'Problem' ?></h2>
    <p><?= e($verified['message']) ?></p>
    <p>Restored <code><?= e($verified['backup']) ?></code> into the new database <code><?= e($verified['database']) ?></code>. It was kept and is not used by the server; remove it yourself when finished (for example <code>dropdb <?= e($verified['database']) ?></code>).</p>
    <?php if (isset($verified['restored'])): ?>
    <div class="table-wrap"><table class="dense-table"><thead><tr><th scope="col">Table</th><th scope="col">Rows in backup</th><th scope="col">Rows now in live database</th></tr></thead><tbody>
    <?php foreach ($verified['restored']['counts'] as $table => $count): ?>
        <tr><td><?= e($table) ?></td><td><?= $count === null ? 'Missing' : e($count) ?></td><td><?= e($verified['live']['counts'][$table] ?? 'Missing') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="help">Migrations in backup: <?= e(implode(', ', $verified['restored']['migrations']) ?: 'none') ?>. Knowledge search on a restored fact: <?= e($verified['restored']['fact_search']) ?>. Facts with a stored vector: <?= e($verified['restored']['fact_vectors'] ?? 'not checked') ?> (live <?= e($verified['live']['fact_vectors'] ?? 'not checked') ?>). Live counts can differ if the game was used after the backup.</p>
    <?php endif; ?>
    <p class="help">This check never replaces the live database. To really restore, stop the game and follow SETUP.md (Backups) on the command line.</p>
</section>
<?php endif; ?>
<?php if ($notice === '' && $config !== null): ?>
<section class="chim-panel">
    <h2>Saved backups</h2>
    <?php if (!$files): ?><p>No backups yet.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="dense-table"><thead><tr><th scope="col">File</th><th scope="col">Size</th><th scope="col">Created (UTC)</th><th scope="col">Last verify</th><th scope="col">Actions</th></tr></thead><tbody>
    <?php foreach ($files as $file): ?>
        <tr><td><?= e($file['name']) ?></td><td><?= e(number_format($file['bytes'] / 1024, 1)) ?> KB</td><td><?= e(gmdate('Y-m-d H:i:s', $file['time'])) ?></td>
        <td><?= $file['verify'] === null ? 'Never' : e(($file['verify']['ok'] ?? false ? 'OK' : 'Problem') . ' into ' . ($file['verify']['database'] ?? '?') . ', ' . ($file['verify']['checked_at'] ?? '')) ?></td>
        <td><form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="verify">
            <input type="hidden" name="file" value="<?= e($file['name']) ?>">
            <button type="submit" class="secondary" aria-label="Verify restore of <?= e($file['name']) ?> into a new database">Verify restore</button>
        </form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="help">Verify restore creates a new database named after this one plus <code>_verify_</code> and a random suffix, using the configured database role (it needs CREATEDB). It is never dropped automatically.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
