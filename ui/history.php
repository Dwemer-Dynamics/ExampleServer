<?php
require __DIR__ . '/common.php';
$session = is_string($_GET['session'] ?? null) ? $_GET['session'] : 'dashboard-demo';
$npc = is_string($_GET['npc'] ?? null) ? $_GET['npc'] : 'npc_guide';
$rows = [];
$message = '';
$config = ui_config();
if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $session) || !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $npc)) {
    $message = 'Use 1–64 letters, numbers, underscores, dots, colons or hyphens for each ID.';
} elseif ($config === null) {
    $message = 'Server configuration is missing or invalid.';
} else {
    $db = ui_database($config);
    if ($db === null) {
        $message = 'Database unavailable.';
    } else {
        $result = @pg_query_params($db, 'SELECT role, text, created_at FROM ex_turns WHERE session_id = $1 AND npc_id = $2 ORDER BY id DESC LIMIT 50', [$session, $npc]);
        if ($result === false) {
            $message = 'History unavailable. Check that the database has been migrated.';
        } else {
            $rows = array_reverse(pg_fetch_all($result));
        }
    }
}
$title = 'History';
$active = 'history';
require __DIR__ . '/tmpl/header.php';
?>
<section class="chim-panel">
    <p>Shows up to 50 retained lines, oldest first. The server retains only its configured history limit per session and NPC.</p>
    <form method="get" class="filter-toolbar">
            <div><label for="session">Session ID</label><input type="text" id="session" name="session" maxlength="64" value="<?= e($session) ?>" required></div>
            <div><label for="npc">NPC ID</label><input type="text" id="npc" name="npc" maxlength="64" value="<?= e($npc) ?>" required></div>
        <button>Show history</button>
    </form>
</section>
<section class="chim-panel">
    <?php if ($message !== ''): ?><p class="notice"><?= e($message) ?></p>
    <?php elseif (!$rows): ?><p>No retained conversation lines for these IDs.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="dense-table"><thead><tr><th scope="col">Time</th><th scope="col">Speaker</th><th scope="col">Text</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr><td><?= e($row['created_at']) ?></td><td><?= e($row['role']) ?></td><td class="reply"><?= e($row['text']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
