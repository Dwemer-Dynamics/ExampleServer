<?php
// Connector audit: voice, NPC selection, embedding and memory calls from ex_connector_calls
// (migration 007). Read-only. Exact filters, 25 rows per page by id (keyset), no polling.
// Rows never hold audio, transcripts, prompts, provider bodies, URLs or keys.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/audit.php';

const CALL_PAGE_ROWS = 25;

$problems = [];
$filters = [];
$request = is_string($_GET['request'] ?? null) ? $_GET['request'] : '';
if ($request !== '' && !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $request)) {
    $problems[] = 'IDs use 1-64 letters, numbers, underscores, dots, colons or hyphens.';
    $request = '';
}
foreach (['kind' => AUDIT_KINDS, 'status' => AUDIT_STATUSES] as $param => $allowed) {
    $value = $_GET[$param] ?? '';
    if ($value !== '' && (!is_string($value) || !in_array($value, $allowed, true))) {
        $problems[] = "Choose a $param from the list.";
    } elseif ($value !== '') {
        $filters[$param] = $value;
    }
}
$before = $_GET['before'] ?? '';
if ($before !== '' && (!is_string($before) || !preg_match('/^[1-9][0-9]{0,17}$/D', $before))) {
    $problems[] = 'The page link is not valid. Start again from the newest calls.';
    $before = '';
}
$filterQuery = array_filter(['request' => $request] + $filters, 'strlen');

$rows = [];
$hasOlder = false;
$message = '';
$config = ui_config();
if ($problems) {
    $message = implode(' ', array_unique($problems));
} elseif ($config === null) {
    $message = 'Server configuration is missing or invalid.';
} else {
    $db = ui_database($config);
    $migrated = $db === null ? false : @pg_query($db, "SELECT 1 FROM ex_schema_migrations WHERE version = '007_connector_calls'");
    if ($db === null) {
        $message = 'Database unavailable. Check PostgreSQL and your private settings.';
    } elseif ($migrated === false || pg_num_rows($migrated) === 0) {
        $message = 'Connector calls need migration 007_connector_calls. Run the installer.';
    } else {
        $where = [];
        $params = [];
        if ($request !== '') {
            $params[] = $request;
            $where[] = '(request_id = $1 OR parent_request_id = $1)';
        }
        foreach ($filters as $column => $value) {
            $params[] = $value;
            $where[] = "$column = $" . count($params);
        }
        if ($before !== '') {
            $params[] = $before;
            $where[] = 'id < $' . count($params);
        }
        $result = @pg_query_params($db, "SELECT id, kind, provider, status, error_code, duration_ms, request_id, parent_request_id,
                session_id, npc_id, detail, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || ' UTC' AS time
            FROM ex_connector_calls" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . (CALL_PAGE_ROWS + 1), $params);
        if ($result === false) {
            $message = 'Connector calls could not be read.';
        } else {
            $rows = pg_fetch_all($result);
            $hasOlder = count($rows) > CALL_PAGE_ROWS;
            $rows = array_slice($rows, 0, CALL_PAGE_ROWS);
        }
    }
}

function call_badge(string $status): string
{
    $class = ['ok' => 'chim-badge-success', 'failed' => 'chim-badge-danger'][$status] ?? 'chim-badge-accent';
    return '<span class="chim-tag ' . $class . '">' . e($status) . '</span>';
}

$title = 'Connector calls';
$active = 'connector_calls';
require __DIR__ . '/tmpl/header.php';
?>
<section class="chim-panel">
    <p class="chim-panel-intro">Voice, NPC selection, embedding and memory calls, newest first. A request ID filter also finds calls made for that request (its parent), for example the voice and memory calls of one turn. Rows hold ids, status, timing and short codes only: never audio, transcripts, prompts, provider replies or keys. Conversation outcomes are on <a href="logs.php">Logs</a>.</p>
    <form method="get" class="filter-toolbar" aria-label="Filter connector calls">
        <div><label for="request">Request or parent ID</label><input type="text" id="request" name="request" maxlength="64" value="<?= e($request) ?>"></div>
        <div><label for="kind">Kind</label><select id="kind" name="kind"><option value="">All kinds</option><?php foreach (AUDIT_KINDS as $kind): ?><option<?= ($filters['kind'] ?? '') === $kind ? ' selected' : '' ?>><?= e($kind) ?></option><?php endforeach; ?></select></div>
        <div><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option><?php foreach (AUDIT_STATUSES as $status): ?><option<?= ($filters['status'] ?? '') === $status ? ' selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></div>
        <button>Show calls</button>
        <a href="connector_calls.php">Clear</a>
    </form>
</section>
<section class="chim-panel">
<?php if ($message !== ''): ?>
    <p class="notice" role="status"><?= e($message) ?></p>
<?php elseif (!$rows): ?>
    <p role="status"><?= $filterQuery || $before !== '' ? 'No calls match these filters.' : 'No connector calls recorded yet. Try a voice test, NPC selection test or a turn with memory on.' ?></p>
<?php else: ?>
    <div class="table-wrap"><table class="dense-table"><thead><tr><th scope="col">Time</th><th scope="col">Kind</th><th scope="col">Provider</th><th scope="col">Status</th><th scope="col">Time taken</th><th scope="col">Request ID</th><th scope="col">Parent</th><th scope="col">Session / NPC</th><th scope="col">Detail</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
        <tr><td><?= e($row['time']) ?></td><td><?= e($row['kind']) ?></td><td><?= e($row['provider'] ?? 'none') ?></td>
        <td><?= call_badge($row['status']) ?><?= $row['error_code'] !== null ? ' ' . e($row['error_code']) : '' ?></td>
        <td><?= $row['duration_ms'] !== null ? e($row['duration_ms']) . ' ms' : '' ?></td>
        <td><?= e($row['request_id'] ?? '') ?></td>
        <td><?= $row['parent_request_id'] !== null ? '<a href="logs.php?' . e(http_build_query(['request' => $row['parent_request_id']])) . '">' . e($row['parent_request_id']) . '</a>' : '' ?></td>
        <td><?= e($row['session_id'] ?? '') ?><?= $row['npc_id'] !== null ? ' / ' . e($row['npc_id']) : '' ?></td>
        <td class="reply"><?= e($row['detail'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="links">
        <?php if ($before !== ''): ?><a href="connector_calls.php?<?= e(http_build_query($filterQuery)) ?>">Newest</a><?php endif; ?>
        <?php if ($hasOlder): ?><a href="connector_calls.php?<?= e(http_build_query(['before' => $rows[count($rows) - 1]['id']] + $filterQuery)) ?>">Older calls</a><?php endif; ?>
    </p>
<?php endif; ?>
</section>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
