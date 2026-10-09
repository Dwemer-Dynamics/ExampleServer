<?php
// Named copies of one session's conversation lines. Uses the same functions as
// scripts/checkpoint.php (lib/checkpoint.php): same limits, session lock and stale fence.
// Restore and delete need a visible confirmation step. Game saves are never touched.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/checkpoint.php';

const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/D';
const CHECKPOINT_ACTIONS = ['save', 'restore', 'delete'];

function valid_id(mixed $value): ?string
{
    return is_string($value) && preg_match(ID_PATTERN, $value) ? $value : null;
}

$session = valid_id($_GET['session'] ?? 'dashboard-demo') ?? '';
$confirm = in_array($_GET['confirm'] ?? '', ['restore', 'delete'], true) ? $_GET['confirm'] : '';
$confirmName = valid_id($_GET['name'] ?? null) ?? '';
$notice = '';
$message = '';
$done = '';
$typedName = '';
$db = null;
$config = ui_config();
if ($config === null) {
    $notice = 'Server configuration is missing or invalid.';
} else {
    $db = ui_database($config);
    if ($db === null) {
        $notice = 'Database unavailable. Check PostgreSQL and your private settings.';
    } elseif (($problem = schema_problem($db)) !== null) {
        // Same check as every write endpoint: no checkpoint change unless the schema fits this code.
        $notice = $problem[1];
        $db = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db !== null) {
    $action = $_POST['action'] ?? '';
    $postSession = valid_id($_POST['session'] ?? null);
    $typedName = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
    $name = valid_id($typedName);
    if (!check_csrf()) {
        http_response_code(403);
        $message = 'This form expired. Reload and try again.';
    } elseif (!in_array($action, CHECKPOINT_ACTIONS, true)) {
        http_response_code(400);
        $message = 'Unknown form action.';
    } elseif ($postSession === null || $name === null) {
        http_response_code(400);
        $message = 'Session ID and checkpoint name must be 1-64 characters of A-Z a-z 0-9 _ . : -';
    } elseif ($action !== 'save' && ($_POST['confirmed'] ?? '') !== '1') {
        http_response_code(400);
        $message = 'Confirm the ' . $action . ' first.';
    } else {
        session_write_close();
        $session = $postSession;
        $result = match ($action) {
            'save' => checkpoint_save($db, $session, $name),
            'restore' => checkpoint_restore($db, $session, $name, $config),
            'delete' => checkpoint_delete($db, $session, $name),
        };
        if ($result['ok']) {
            $query = ['session' => $session, 'done' => $action, 'name' => $name];
            if (($result['logged'] ?? true) === false) {
                $query['logged'] = '0';
            }
            header('Location: checkpoints.php?' . http_build_query($query), true, 303);
            exit;
        }
        http_response_code(409);
        $message = $result['message'];
    }
}

$sessions = [];
$rows = [];
$lines = 0;
if ($db !== null) {
    $sessions = array_column(pg_fetch_all(db_query($db,
        'SELECT session_id FROM (SELECT session_id FROM ex_turns UNION SELECT session_id FROM ex_checkpoints) s
         ORDER BY session_id LIMIT 50')), 'session_id');
    if ($session !== '') {
        $rows = checkpoint_list($db, $session)['rows'];
        $lines = (int)pg_fetch_result(db_query($db, 'SELECT count(*) FROM ex_turns WHERE session_id = $1', [$session]), 0, 0);
    }
}
// Built from a fixed action and validated ids, so a link cannot put other text here.
$doneName = valid_id($_GET['name'] ?? null);
$done = match ($doneName === null ? '' : ($_GET['done'] ?? '')) {
    'save' => "Saved checkpoint $doneName for session $session.",
    'restore' => "Restored checkpoint $doneName for session $session. Any reply still being generated for it is discarded.",
    'delete' => "Deleted checkpoint $doneName for session $session.",
    default => '',
};
if ($done !== '' && ($_GET['logged'] ?? '') === '0') {
    $done .= ' It could not be written to the event log, so Logs will not show it.';
}
$confirmRow = null;
foreach ($rows as $row) {
    if ($confirm !== '' && $row['name'] === $confirmName) {
        $confirmRow = $row;
    }
}
$title = 'Checkpoints';
$active = 'checkpoints';
require __DIR__ . '/tmpl/header.php';
?>
<section class="chim-panel">
    <p>A checkpoint is a named copy of one session's conversation lines in this server's database. Restoring replaces only that session's lines and discards any reply still being generated for it. Bios, knowledge, config, other sessions and game saves are unchanged. Each session keeps at most <?= MAX_CHECKPOINTS_PER_SESSION ?> checkpoints of up to <?= MAX_CHECKPOINT_LINES ?> lines.</p>
    <form method="get" class="filter-toolbar">
        <div><label for="session">Session ID</label><input type="text" id="session" name="session" maxlength="64" required list="known-sessions" value="<?= e($session) ?>"></div>
        <datalist id="known-sessions"><?php foreach ($sessions as $known): ?><option value="<?= e($known) ?>"><?php endforeach; ?></datalist>
        <button>Show checkpoints</button>
    </form>
    <p class="help">The dashboard conversation uses <code>dashboard-demo</code>; the game sends its own session ID.<?= $sessions ? ' Known sessions: ' . e(implode(', ', array_slice($sessions, 0, 10))) . (count($sessions) > 10 ? ', …' : '') . '.' : '' ?></p>
</section>
<?php if ($notice !== ''): ?>
<section class="chim-panel"><p class="notice" role="alert"><?= e($notice) ?></p></section>
<?php elseif ($session === ''): ?>
<section class="chim-panel"><p class="notice" role="alert">Use 1-64 letters, numbers, underscores, dots, colons or hyphens for the session ID.</p></section>
<?php else: ?>
<?php if ($done !== ''): ?><p class="notice" role="status"><?= e($done) ?></p><?php endif; ?>
<?php if ($message !== ''): ?><p class="notice" role="alert" id="checkpoint-error"><?= e($message) ?></p><?php endif; ?>
<?php if ($confirmRow !== null): ?>
<section class="chim-panel confirm-panel" aria-labelledby="confirm-title">
    <h2 id="confirm-title"><?= $confirm === 'restore' ? 'Restore' : 'Delete' ?> checkpoint <?= e($confirmName) ?>?</h2>
    <?php if ($confirm === 'restore'): ?>
    <p>Session <code><?= e($session) ?></code> currently has <?= e($lines) ?> lines. They will be replaced by the <?= e($confirmRow['turn_count']) ?> lines saved on <?= e($confirmRow['created_at']) ?>. Any reply still being generated for this session is discarded. Save a checkpoint first if you want to keep the current lines.</p>
    <?php else: ?>
    <p>The saved copy (<?= e($confirmRow['turn_count']) ?> lines) is removed. The session's current lines are not changed.</p>
    <?php endif; ?>
    <form method="post" class="links">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="<?= e($confirm) ?>">
        <input type="hidden" name="session" value="<?= e($session) ?>">
        <input type="hidden" name="name" value="<?= e($confirmName) ?>">
        <input type="hidden" name="confirmed" value="1">
        <button type="submit" class="btn-danger" autofocus>Yes, <?= e($confirm) ?> <?= e($confirmName) ?></button>
        <a href="checkpoints.php?<?= e(http_build_query(['session' => $session])) ?>">Cancel</a>
    </form>
</section>
<?php endif; ?>
<section class="chim-panel">
    <h2>Save the current lines</h2>
    <form method="post" class="filter-toolbar">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="session" value="<?= e($session) ?>">
        <div><label for="name">Checkpoint name</label><input type="text" id="name" name="name" maxlength="64" required pattern="[A-Za-z0-9_.:\-]{1,64}" value="<?= e($typedName) ?>" placeholder="before-boss"<?= $message !== '' && ($_POST['action'] ?? '') === 'save' ? ' aria-invalid="true" aria-describedby="checkpoint-error"' : '' ?>></div>
        <button type="submit">Save checkpoint</button>
    </form>
    <p class="help">Session <code><?= e($session) ?></code> has <?= e($lines) ?> retained lines.</p>
</section>
<section class="chim-panel">
    <h2>Saved checkpoints</h2>
    <?php if (!$rows): ?><p>No checkpoints for session <?= e($session) ?>.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="dense-table"><thead><tr><th scope="col">Name</th><th scope="col">Lines</th><th scope="col">Saved</th><th scope="col">Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $row): $query = ['session' => $session, 'name' => $row['name']]; ?>
        <tr><td><?= e($row['name']) ?></td><td><?= e($row['turn_count']) ?></td><td><?= e($row['created_at']) ?></td>
        <td class="links"><a href="checkpoints.php?<?= e(http_build_query($query + ['confirm' => 'restore'])) ?>#confirm-title" aria-label="Restore <?= e($row['name']) ?>">Restore…</a>
            <a href="checkpoints.php?<?= e(http_build_query($query + ['confirm' => 'delete'])) ?>#confirm-title" aria-label="Delete <?= e($row['name']) ?>">Delete…</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
