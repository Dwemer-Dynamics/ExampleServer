<?php
// Event log: finished turns, cancels, checkpoint restores and log-only game events from
// ex_events (migrations 003 and 004). A turn's detail shows its retrieval snapshot (with the
// profile, memory and vector matches it used) and the connector calls made for it (migration 007).
// Read-only. Exact filters, 25 rows per page by id (keyset), no polling.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/events.php';

const LOG_PAGE_ROWS = 25;
const LOG_TIME_FORMAT = "to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || ' UTC'";

// Each filter is optional; a value that is present must be valid.
$filters = [];
$problems = [];
foreach (['session' => 'session_id', 'npc' => 'npc_id', 'request' => 'request_id'] as $param => $column) {
    $value = $_GET[$param] ?? '';
    if ($value === '') {
        continue;
    }
    if (!is_string($value) || !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $value)) {
        $problems[] = 'IDs use 1-64 letters, numbers, underscores, dots, colons or hyphens.';
        continue;
    }
    $filters[$param] = [$column, '=', $value];
}
foreach (['kind' => EVENT_KINDS, 'status' => EVENT_STATUSES] as $param => $allowed) {
    $value = $_GET[$param] ?? '';
    if ($value === '') {
        continue;
    }
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        $problems[] = "Choose a $param from the list.";
        continue;
    }
    $filters[$param] = [$param, '=', $value];
}
foreach (['from' => '>=', 'to' => '<'] as $param => $operator) {
    $value = $_GET[$param] ?? '';
    if ($value === '') {
        continue;
    }
    $time = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC')) : false;
    if ($time === false || $time->format('Y-m-d\TH:i') !== $value) {
        $problems[] = 'Times use the date and time picker (UTC).';
        continue;
    }
    $filters[$param] = ['created_at', $operator, $time->format('Y-m-d H:i:s+00')];
}
$cursor = null;
foreach (['before' => '<', 'after' => '>'] as $param => $operator) {
    $value = $_GET[$param] ?? '';
    if ($value === '') {
        continue;
    }
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,17}$/D', $value) || $cursor !== null) {
        $problems[] = 'The page link is not valid. Start again from the newest events.';
        continue;
    }
    $cursor = [$param, $operator, $value];
}
$eventId = $_GET['event'] ?? '';
if ($eventId !== '' && (!is_string($eventId) || !preg_match('/^[1-9][0-9]{0,17}$/D', $eventId))) {
    $problems[] = 'The event link is not valid.';
    $eventId = '';
}
$value = fn(string $param) => is_string($_GET[$param] ?? null) ? $_GET[$param] : '';
$filterQuery = array_filter(['session' => $value('session'), 'npc' => $value('npc'), 'request' => $value('request'),
    'kind' => $value('kind'), 'status' => $value('status'), 'from' => $value('from'), 'to' => $value('to')], 'strlen');

$rows = [];
$detail = null;
$linkedCalls = null;
$message = '';
$hasNewer = false;
$hasOlder = false;
$config = ui_config();
if ($problems) {
    $message = implode(' ', array_unique($problems));
} elseif ($config === null) {
    $message = 'Server configuration is missing or invalid.';
} else {
    $db = ui_database($config);
    $migrated = $db === null ? false : @pg_query($db, "SELECT 1 FROM ex_schema_migrations WHERE version = '003_event_log'");
    if ($db === null) {
        $message = 'Database unavailable. Check PostgreSQL and your private settings.';
    } elseif ($migrated === false || pg_num_rows($migrated) === 0) {
        $message = 'The event log needs migration 003_event_log. Run the installer.';
    } elseif ($eventId !== '') {
        $result = @pg_query_params($db, 'SELECT *, ' . LOG_TIME_FORMAT . ' AS time,
            to_char(client_reported_at AT TIME ZONE \'UTC\', \'YYYY-MM-DD HH24:MI:SS\') || \' UTC\' AS reported_time
            FROM ex_events WHERE id = $1', [$eventId]);
        if ($result === false) {
            $message = 'The event log could not be read.';
        } else {
            $detail = pg_fetch_assoc($result) ?: null;
            $message = $detail === null ? 'This event is no longer retained.' : '';
            // Voice, embedding and memory calls made for this request, when migration 007 exists.
            $calls = $detail === null || $detail['request_id'] === null ? false : @pg_query_params($db,
                "SELECT kind, provider, status, error_code, duration_ms, request_id, detail FROM ex_connector_calls
                 WHERE request_id = $1 OR parent_request_id = $1 ORDER BY id LIMIT 20", [$detail['request_id']]);
            $linkedCalls = $calls === false ? null : pg_fetch_all($calls);
        }
    } else {
        $where = [];
        $params = [];
        foreach ($filters as [$column, $operator, $param]) {
            $params[] = $param;
            $where[] = "$column $operator $" . count($params);
        }
        $ascending = $cursor !== null && $cursor[0] === 'after';
        if ($cursor !== null) {
            $params[] = $cursor[2];
            $where[] = "id {$cursor[1]} $" . count($params);
        }
        $result = @pg_query_params($db, 'SELECT id, kind, status, request_id, session_id, npc_id, generation, error_code,
                duration_ms, actions, rejected_actions, client_report IS NOT NULL AS reported, ' . LOG_TIME_FORMAT . ' AS time
            FROM ex_events' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY id ' . ($ascending ? 'ASC' : 'DESC') . ' LIMIT ' . (LOG_PAGE_ROWS + 1), $params);
        if ($result === false) {
            $message = 'The event log could not be read.';
        } else {
            $rows = pg_fetch_all($result);
            $more = count($rows) > LOG_PAGE_ROWS;
            $rows = array_slice($rows, 0, LOG_PAGE_ROWS);
            if ($ascending) {
                $rows = array_reverse($rows);
                [$hasNewer, $hasOlder] = [$more, true];
            } else {
                [$hasNewer, $hasOlder] = [$cursor !== null, $more];
            }
        }
    }
}

function log_names(?string $json): string
{
    $names = is_string($json) ? json_decode($json, true) : null;
    return is_array($names) && $names ? implode(', ', array_map('strval', $names)) : 'none';
}

function log_badge(string $status): string
{
    $class = match ($status) {
        'complete', 'ok' => 'chim-badge-success',
        'failed' => 'chim-badge-danger',
        default => 'chim-badge-accent',
    };
    return '<span class="chim-tag ' . $class . '">' . e($status) . '</span>';
}

$title = 'Logs';
$active = 'logs';
require __DIR__ . '/tmpl/header.php';
?>
<section class="chim-panel">
    <p class="chim-panel-intro">Event log: finished turns, cancels, checkpoint restores and log-only game events, newest first. Voice, NPC selection, embedding and memory calls are on <a href="connector_calls.php">Connector calls</a>. <strong>complete</strong> means the server finished; it does not mean the game received the reply or ran an action. An action is a tag the model asked for, never an executed command.</p>
    <form method="get" class="filter-toolbar" aria-label="Filter events">
        <div><label for="session">Session ID</label><input type="text" id="session" name="session" maxlength="64" value="<?= e($value('session')) ?>"></div>
        <div><label for="npc">NPC ID</label><input type="text" id="npc" name="npc" maxlength="64" value="<?= e($value('npc')) ?>"></div>
        <div><label for="request">Request ID</label><input type="text" id="request" name="request" maxlength="64" value="<?= e($value('request')) ?>"></div>
        <div><label for="kind">Kind</label><select id="kind" name="kind"><option value="">All kinds</option><?php foreach (EVENT_KINDS as $kind): ?><option<?= $value('kind') === $kind ? ' selected' : '' ?>><?= e($kind) ?></option><?php endforeach; ?></select></div>
        <div><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option><?php foreach (EVENT_STATUSES as $status): ?><option<?= $value('status') === $status ? ' selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></div>
        <div><label for="from">From (UTC)</label><input type="datetime-local" id="from" name="from" value="<?= e($value('from')) ?>"></div>
        <div><label for="to">Before (UTC)</label><input type="datetime-local" id="to" name="to" value="<?= e($value('to')) ?>"></div>
        <button>Show events</button>
        <a href="logs.php">Clear</a>
    </form>
</section>
<section class="chim-panel">
<?php if ($message !== ''): ?>
    <p class="notice" role="status"><?= e($message) ?></p>
    <?php if ($eventId !== ''): ?><p><a href="logs.php?<?= e(http_build_query($filterQuery)) ?>">Back to the event list</a></p><?php endif; ?>
<?php elseif ($detail !== null): ?>
    <h2>Event <?= e($detail['id']) ?></h2>
    <div class="table-wrap"><table class="dense-table"><tbody>
        <tr><th scope="row">Time</th><td><?= e($detail['time']) ?></td></tr>
        <tr><th scope="row">Kind</th><td><?= e($detail['kind']) ?><?= ($detail['event_type'] ?? null) !== null ? ' (game event ' . e($detail['event_type']) . ($detail['kind'] === 'game_event' ? ', logged only: no model call, history or actions' : ', NPC asked to respond') . ')' : '' ?></td></tr>
        <tr><th scope="row">Server status</th><td><?= log_badge($detail['status']) ?><?= $detail['error_code'] !== null ? ' ' . e($detail['error_code']) : '' ?></td></tr>
        <tr><th scope="row">Session / NPC</th><td><?= e($detail['session_id']) ?> / <?= e($detail['npc_id'] ?? 'all NPCs') ?></td></tr>
        <tr><th scope="row">Request ID</th><td><?= e($detail['request_id'] ?? 'none') ?></td></tr>
        <tr><th scope="row">Generation</th><td><?= e($detail['generation'] ?? 'none') ?></td></tr>
        <tr><th scope="row">Reply mode</th><td><?= e($detail['provider'] ?? 'none') ?></td></tr>
        <tr><th scope="row">Server time</th><td><?= $detail['duration_ms'] !== null ? e($detail['duration_ms']) . ' ms' : 'not recorded' ?></td></tr>
        <tr><th scope="row"><?= $detail['kind'] === 'game_event' ? 'Event text (sent by the game, untrusted)' : 'Player text' ?></th><td class="reply"><?= e($detail['player_text'] ?? 'none') ?></td></tr>
        <tr><th scope="row">NPC reply</th><td class="reply"><?= e($detail['reply'] ?? 'none') ?></td></tr>
        <tr><th scope="row"><?= $detail['status'] === 'complete' ? 'Actions returned' : 'Allowed actions (not returned: the turn did not complete)' ?></th><td><?= e(log_names($detail['actions'])) ?></td></tr>
        <tr><th scope="row">Actions rejected by the server</th><td><?= e(log_names($detail['rejected_actions'])) ?></td></tr>
        <?php if ($detail['context'] !== null): ?><tr><th scope="row">Context (sent by the game)</th><td class="reply"><?php foreach (json_decode($detail['context'], true) ?: [] as $key => $value): ?><?= e($key) ?>: <?= e($value) ?><br><?php endforeach; ?></td></tr><?php endif; ?>
        <?php if ($detail['detail'] !== null): ?><tr><th scope="row"><?= $detail['kind'] === 'game_event' ? 'Event text (sent by the game, untrusted; earlier rows kept 200 characters)' : 'Detail' ?></th><td class="reply"><?= e($detail['detail']) ?></td></tr><?php endif; ?>
    </tbody></table></div>
    <?php $retrieval = isset($detail['retrieval']) ? json_decode($detail['retrieval'], true) : null; ?>
    <?php if ($detail['kind'] === 'turn'): ?>
    <h2>Retrieved for this request</h2>
    <?php if (!is_array($retrieval)): ?>
    <p>No retrieval snapshot. The turn failed before retrieval, or it predates migration 004.</p>
    <?php else: ?>
    <p class="help">A copy of what the server gave the model, saved when the turn ran. Later fact edits and checkpoint restores do not change it. Bios and facts are shared memory for every session; history lines belong to this session only.</p>
    <div class="table-wrap"><table class="dense-table"><tbody>
        <tr><th scope="row">Search words</th><td><?= e(($retrieval['terms'] ?? '') !== '' ? $retrieval['terms'] : 'none') ?></td></tr>
        <tr><th scope="row">Biography (shared)</th><td class="reply"><?php $bio = $retrieval['bio'] ?? null; ?><?= is_array($bio) ? e(($bio['name'] ?? '') . (($bio['role'] ?? '') !== '' ? ', ' . $bio['role'] : '') . ': ' . ($bio['bio'] ?? '')) : 'none saved for this NPC' ?></td></tr>
        <tr><th scope="row">Session history lines sent</th><td><?= e($retrieval['history_lines'] ?? 0) ?></td></tr>
        <?php $profile = $retrieval['profile'] ?? null; ?>
        <tr><th scope="row">Profile</th><td><?= is_array($profile) ? e($profile['name'] ?? '') . ' (id ' . e($profile['id'] ?? '') . ', ' . (!empty($profile['assigned']) ? 'assigned' : 'default') . '; mode ' . e($profile['llm_mode'] ?? 'inherited') . ', model ' . e($profile['llm_model'] ?? 'inherited') . ', prompt ' . e($profile['prompt_chars'] ?? 0) . ' characters)' : 'none (config only)' ?></td></tr>
        <?php $memory = $retrieval['memory'] ?? null; ?>
        <tr><th scope="row">Memory (this session)</th><td><?= is_array($memory) ? (!empty($memory['summary']) ? 'summary' : 'no summary') . ', ' . e($memory['diary_entries'] ?? 0) . ' diary entries' : 'off or not recorded' ?></td></tr>
        <tr><th scope="row">Vector search</th><td><?= e(['ok' => 'used', 'failed' => 'failed; keyword search only', 'off' => 'off'][$retrieval['embedding'] ?? ''] ?? 'not recorded') ?></td></tr>
    </tbody></table></div>
    <?php $retrievedFacts = is_array($retrieval['facts'] ?? null) ? $retrieval['facts'] : []; ?>
    <?php if (!$retrievedFacts): ?><p>No facts matched.</p><?php else: ?>
    <div class="table-wrap"><table class="dense-table"><caption>Facts given to the model (shared)</caption><thead><tr><th scope="col">Fact ID</th><th scope="col">Match</th><th scope="col">Scope</th><th scope="col">Topic</th><th scope="col">Fact as retrieved</th></tr></thead><tbody>
    <?php foreach ($retrievedFacts as $fact): ?><tr><td><?= e($fact['id'] ?? '') ?></td><td><?= e(($fact['match'] ?? 'keyword') . (isset($fact['score']) ? ' ' . $fact['score'] : '')) ?></td><td><?= e($fact['scope'] ?? '') ?></td><td><?= e($fact['topic'] ?? '') ?></td><td class="reply"><?= e($fact['fact'] ?? '') ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($linkedCalls !== null): ?>
    <h2>Connector calls for this request</h2>
    <?php if (!$linkedCalls): ?><p>None recorded (no voice, embedding or memory call named this request).</p>
    <?php else: ?>
    <div class="table-wrap"><table class="dense-table"><thead><tr><th scope="col">Kind</th><th scope="col">Provider</th><th scope="col">Status</th><th scope="col">Time taken</th><th scope="col">Call ID</th><th scope="col">Detail</th></tr></thead><tbody>
    <?php foreach ($linkedCalls as $call): ?><tr><td><?= e($call['kind']) ?></td><td><?= e($call['provider'] ?? 'none') ?></td><td><?= log_badge($call['status']) ?><?= $call['error_code'] !== null ? ' ' . e($call['error_code']) : '' ?></td><td><?= $call['duration_ms'] !== null ? e($call['duration_ms']) . ' ms' : '' ?></td><td><?= e($call['request_id'] ?? '') ?></td><td class="reply"><?= e($call['detail'] ?? '') ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <p><a href="connector_calls.php?<?= e(http_build_query(['request' => $detail['request_id']])) ?>">Open in Connector calls</a></p>
    <?php endif; ?>
    <h2>Client reported (untrusted)</h2>
    <?php $report = $detail['client_report'] === null ? null : json_decode($detail['client_report'], true); ?>
    <?php if (!is_array($report) || !is_array($report['results'] ?? null)): ?>
    <p>No client report. Without one, nothing says whether the game received the reply or ran its actions.</p>
    <?php else: ?>
    <p class="help">Sent by the game client at <?= e($detail['reported_time']) ?>. The server only checked that these action names were returned for this request; it cannot verify the game.</p>
    <div class="table-wrap"><table class="dense-table"><thead><tr><th scope="col">Action</th><th scope="col">Client says</th><th scope="col">Reason</th></tr></thead><tbody>
    <?php foreach ($report['results'] as $item): ?><tr><td><?= e($item['name'] ?? '') ?></td><td><?= e($item['status'] ?? '') ?></td><td class="reply"><?= e($item['reason'] ?? '') ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <p><a href="logs.php?<?= e(http_build_query($filterQuery)) ?>">Back to the event list</a></p>
<?php elseif (!$rows): ?>
    <p role="status"><?= $filterQuery || $cursor !== null ? 'No events match these filters.' : 'No events recorded yet. Send a test turn from Conversation.' ?></p>
<?php else: ?>
    <div class="table-wrap"><table class="dense-table"><thead><tr><th scope="col">Time</th><th scope="col">Kind</th><th scope="col">Status</th><th scope="col">Session / NPC</th><th scope="col">Request ID</th><th scope="col">Gen.</th><th scope="col">Server time</th><th scope="col">Actions</th><th scope="col">Client report</th><th scope="col">Event</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
        <tr><td><?= e($row['time']) ?></td><td><?= e($row['kind']) ?></td><td><?= log_badge($row['status']) ?><?= $row['error_code'] !== null ? ' ' . e($row['error_code']) : '' ?></td>
        <td><?= e($row['session_id']) ?> / <?= e($row['npc_id'] ?? 'all') ?></td><td><?= e($row['request_id'] ?? '') ?></td><td><?= e($row['generation'] ?? '') ?></td>
        <td><?= $row['duration_ms'] !== null ? e($row['duration_ms']) . ' ms' : '' ?></td>
        <td><?= $row['status'] === 'complete' ? 'returned' : 'not returned' ?>: <?= e(log_names($row['actions'])) ?>; rejected: <?= e(log_names($row['rejected_actions'])) ?></td>
        <td><?= $row['reported'] === 't' ? 'Client reported' : 'None' ?></td>
        <td><a href="logs.php?<?= e(http_build_query(['event' => $row['id']] + $filterQuery)) ?>" aria-label="Details for event <?= e($row['id']) ?>">Details</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="links">
        <?php if ($hasNewer): ?><a href="logs.php?<?= e(http_build_query(['after' => $rows[0]['id']] + $filterQuery)) ?>">Newer events</a><a href="logs.php?<?= e(http_build_query($filterQuery)) ?>">Newest</a><?php endif; ?>
        <?php if ($hasOlder): ?><a href="logs.php?<?= e(http_build_query(['before' => $rows[count($rows) - 1]['id']] + $filterQuery)) ?>">Older events</a><?php endif; ?>
    </p>
<?php endif; ?>
</section>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
