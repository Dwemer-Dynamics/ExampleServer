<?php
// Advanced memory for one session and NPC (lib/memory.php, sql/006_memory.sql): the saved
// summary and diary, candidate facts waiting for review, and clearing. Settings and vector
// reindexing are on memory_settings.php. Every write is a CSRF-checked POST with a fixed action,
// then a redirect back here. Regenerate calls the NPC's model (outside any transaction) and
// saves only if no newer turn, cancel or restore happened meanwhile.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/memory.php';
require __DIR__ . '/../lib/profiles.php';

const MEMORY_ACTIONS = ['save_summary', 'add_diary', 'delete_diary', 'regenerate', 'approve', 'reject', 'clear'];
const MEMORY_DONE = [
    'summary' => 'Summary saved.',
    'summary_removed' => 'Summary removed.',
    'diary' => 'Diary entry added.',
    'diary_deleted' => 'Diary entry deleted.',
    'regenerated' => 'Summary regenerated and a diary entry added.',
    'approved' => 'Fact approved. It is now shared knowledge for this NPC in every session (see NPC bios).',
    'rejected' => 'Candidate rejected and deleted.',
    'cleared' => 'Memory cleared for this session and NPC. Any reply still being generated for them was discarded.',
];
const FACTS_PER_SCOPE = 50;  // the same limit as the NPC bios page
const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/D';

// Back to this page after a successful change.
function memory_done(string $here, string $what): never
{
    header("Location: $here&done=$what", true, 303);
    exit;
}

function memory_id_param(mixed $value): string
{
    return is_string($value) && ctype_digit($value) && strlen($value) <= 18 ? $value : '';
}

// Runs one parameterized query; on failure logs the detail and returns false.
function memory_query(\PgSql\Connection $db, string $sql, array $params = []): \PgSql\Result|false
{
    $result = @pg_query_params($db, $sql, $params);
    if ($result === false) {
        error_log('example-ai dashboard: memory query failed: ' . pg_last_error($db));
    }
    return $result;
}

$session = is_string($_GET['session'] ?? null) && preg_match(ID_PATTERN, $_GET['session']) ? $_GET['session'] : 'dashboard-demo';
$npc = is_string($_GET['npc'] ?? null) && preg_match(ID_PATTERN, $_GET['npc']) ? $_GET['npc'] : 'npc_guide';
$here = 'memory.php?' . http_build_query(['session' => $session, 'npc' => $npc]);
$notice = '';
$message = '';
$typed = ['summary' => null, 'diary' => ''];

$db = null;
$config = ui_config();
if ($config === null) {
    $notice = 'Server configuration is missing or invalid.';
} else {
    $db = ui_database($config);
    if ($db === null) {
        $notice = 'Database unavailable. Check PostgreSQL and your private settings.';
    } elseif (($problem = schema_problem($db)) !== null) {
        $notice = $problem[1];
        $db = null;
    }
}
$settings = memory_settings($config ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db !== null) {
    $action = $_POST['action'] ?? '';
    $candidateId = memory_id_param($_POST['candidate_id'] ?? '');
    if (!check_csrf()) {
        http_response_code(403);
        $message = 'This form expired. Reload and try again.';
    } elseif (!in_array($action, MEMORY_ACTIONS, true)) {
        http_response_code(400);
        $message = 'Unknown form action.';
    } elseif ($action === 'save_summary') {
        $raw = is_string($_POST['summary'] ?? null) ? $_POST['summary'] : '';
        $typed['summary'] = $raw;
        $text = memory_clean($raw, MEMORY_MAX_SUMMARY + 1);
        if ($text !== null && mb_strlen($text) > MEMORY_MAX_SUMMARY) {
            $message = 'The summary must be at most ' . MEMORY_MAX_SUMMARY . ' characters.';
        } elseif ($text === null) {
            if (memory_query($db, "DELETE FROM ex_memory_notes WHERE session_id = $1 AND npc_id = $2 AND kind = 'summary'", [$session, $npc]) !== false) {
                memory_done($here, 'summary_removed');
            }
            $message = 'Database error. Nothing was saved.';
        } else {
            if (memory_save_summary($db, $session, $npc, $text, 'manual', null)) {
                memory_done($here, 'summary');
            }
            $message = 'Database error. Nothing was saved.';
        }
    } elseif ($action === 'add_diary') {
        $typed['diary'] = is_string($_POST['diary'] ?? null) ? $_POST['diary'] : '';
        $text = memory_clean($typed['diary'], MEMORY_MAX_DIARY + 1);
        if ($text === null || mb_strlen($text) > MEMORY_MAX_DIARY) {
            $message = 'A diary entry must be 1-' . MEMORY_MAX_DIARY . ' characters.';
        } else {
            if (memory_add_diary($db, $session, $npc, $text, 'manual', null)) {
                memory_done($here, 'diary');
            }
            $message = 'Database error. Nothing was saved.';
        }
    } elseif ($action === 'delete_diary') {
        $noteId = memory_id_param($_POST['note_id'] ?? '');
        $result = $noteId === '' ? false : memory_query($db,
            "DELETE FROM ex_memory_notes WHERE id = $1 AND session_id = $2 AND npc_id = $3 AND kind = 'diary'", [$noteId, $session, $npc]);
        if ($result !== false && pg_affected_rows($result) === 1) {
            memory_done($here, 'diary_deleted');
        }
        $message = 'That diary entry no longer exists.';
    } elseif ($action === 'regenerate') {
        $generation = memory_generation($db, $session, $npc);
        $profile = profile_for_npc($db, $npc);
        if ($generation === null) {
            $message = 'This NPC has no turns in this session yet, so there is nothing to summarise.';
        } elseif ($profile === false) {
            $message = 'Database error. Nothing was generated.';
        } else {
            // The model may be slow: release the PHP session first. The NPC's profile decides
            // which model writes the notes, the same as for its replies.
            session_write_close();
            $nameRow = memory_query($db, 'SELECT name FROM ex_npc_bios WHERE npc_id = $1', [$npc]);
            $name = $nameRow !== false && pg_num_rows($nameRow) === 1 ? pg_fetch_result($nameRow, 0, 0) : $npc;
            $llm = profile_llm(is_array($config['llm'] ?? null) ? $config['llm'] : [], $profile);
            $started = microtime(true);
            [$status, $code, $source] = memory_refresh_notes($db, $llm, $session, $npc, $name, $generation,
                max(2, min(50, (int)($config['history_limit'] ?? 10))), null);
            audit_insert($db, $config, ['kind' => 'memory', 'provider' => $llm['mode'] ?? 'mock', 'status' => $status,
                'error_code' => $code, 'duration_ms' => event_duration_ms($started), 'request_id' => audit_child_id('ui-mem'),
                'session_id' => $session, 'npc_id' => $npc, 'detail' => 'dashboard regenerate' . ($source !== null ? " ($source)" : '')]);
            if ($status === 'ok') {
                memory_done($here, 'regenerated');
            }
            $message = match ($code) {
                'stale' => 'A newer turn, cancel or checkpoint restore happened while the model was writing. Nothing was saved; try again.',
                'llm_unavailable' => 'The model did not answer. Nothing was saved. Check the LLM page; details are only in the server log.',
                'bad_response' => 'The model reply was not the expected JSON. Nothing was saved.',
                'no_history' => 'There are no retained lines for this session and NPC, so there is nothing to summarise.',
                default => 'Database error. Nothing was saved.',
            };
        }
    } elseif ($action === 'approve') {
        $topic = memory_clean($_POST['topic'] ?? null, 65);
        $fact = memory_clean($_POST['fact'] ?? null, 301);
        if ($candidateId === '' || $topic === null || $fact === null || mb_strlen($topic) > 64 || mb_strlen($fact) > 300) {
            $message = 'Topic must be 1-64 characters and the fact 1-300 characters.';
        } else {
            // One transaction: the candidate becomes a shared fact with its provenance, then goes.
            $ok = memory_query($db, 'BEGIN') !== false;
            $row = $ok ? memory_query($db, 'SELECT session_id, source_request_id FROM ex_memory_candidates WHERE id = $1 AND npc_id = $2 FOR UPDATE',
                [$candidateId, $npc]) : false;
            $added = $row !== false && pg_num_rows($row) === 1 ? memory_query($db,
                "INSERT INTO ex_knowledge (npc_id, topic, fact, source, source_session_id, source_request_id)
                 SELECT $1::text, $2::text, $3::text, 'extracted', $4::text, $5::text
                 WHERE (SELECT count(*) FROM ex_knowledge WHERE npc_id = $1::text) < $6
                 ON CONFLICT DO NOTHING",
                [$npc, $topic, $fact, pg_fetch_result($row, 0, 0), pg_fetch_result($row, 0, 1), FACTS_PER_SCOPE]) : false;
            if ($added !== false && pg_affected_rows($added) === 1
                && memory_query($db, 'DELETE FROM ex_memory_candidates WHERE id = $1', [$candidateId]) !== false
                && memory_query($db, 'COMMIT') !== false) {
                memory_done($here, 'approved');
            }
            @pg_query($db, 'ROLLBACK');
            $message = $added === false ? 'That candidate no longer exists, or a database error occurred. Nothing was saved.'
                : 'Not approved: this NPC already has a fact with this topic, or ' . FACTS_PER_SCOPE . ' facts. Change the topic or edit NPC bios.';
        }
    } elseif ($action === 'reject') {
        $result = $candidateId === '' ? false : memory_query($db, 'DELETE FROM ex_memory_candidates WHERE id = $1 AND npc_id = $2', [$candidateId, $npc]);
        if ($result !== false && pg_affected_rows($result) === 1) {
            memory_done($here, 'rejected');
        }
        $message = 'That candidate no longer exists.';
    } elseif (($_POST['confirm_clear'] ?? '') !== '1') {
        $message = 'Tick the confirmation box to clear this memory.';
    } else {
        // Like a cancel, clearing moves the NPC's generation on, so a turn or memory update still
        // running for this session and NPC cannot write its notes back afterwards.
        next_generation($db, $session, $npc, function () use ($db, $session, $npc): void {
            db_query($db, 'DELETE FROM ex_memory_notes WHERE session_id = $1 AND npc_id = $2', [$session, $npc]);
            db_query($db, 'DELETE FROM ex_memory_candidates WHERE session_id = $1 AND npc_id = $2', [$session, $npc]);
        });
        memory_done($here, 'cleared');
    }
    if ($message !== '' && http_response_code() === 200) {
        http_response_code(409);
    }
}

// Read this session's notes and this NPC's candidates. Bounded.
$summary = null;
$diary = [];
$candidates = [];
if ($db !== null) {
    $notes = memory_query($db,
        "SELECT id, kind, text, source, source_request_id,
                to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') || ' UTC' AS time
         FROM ex_memory_notes WHERE session_id = $1 AND npc_id = $2 ORDER BY kind DESC, id DESC LIMIT $3",
        [$session, $npc, MEMORY_DIARY_KEEP + 1]);
    $pending = memory_query($db,
        "SELECT id, session_id, topic, fact, source, source_request_id, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') || ' UTC' AS time
         FROM ex_memory_candidates WHERE npc_id = $1 ORDER BY id DESC LIMIT $2", [$npc, MEMORY_MAX_PENDING]);
    if ($notes === false || $pending === false) {
        $notice = 'Database error. Memory could not be loaded.';
    } else {
        foreach (pg_fetch_all($notes) as $note) {
            if ($note['kind'] === 'summary') {
                $summary = $note;
            } else {
                $diary[] = $note;
            }
        }
        $candidates = pg_fetch_all($pending);
    }
}
$doneText = MEMORY_DONE[$_GET['done'] ?? ''] ?? '';
$state = fn(bool $on) => $on ? 'on' : 'off';
$title = 'Memory';
$active = 'memory';
require __DIR__ . '/tmpl/header.php';
?>
<section class="chim-panel">
    <p>Saved memory for one session and NPC: a summary and diary that turns use when advanced memory is on, and facts the model proposed, which wait here until you approve them. Generated text is model output: it is stored and sent to the model as quoted data, never as instructions.</p>
    <p class="help">Memory is <strong><?= $state($settings['enabled']) ?></strong>; automatic notes <?= $state($settings['auto_notes']) ?>; automatic fact proposals <?= $state($settings['auto_extract']) ?>. Change this on <a href="memory_settings.php">Memory settings</a>. Checkpoints copy and restore the summary and diary with the session's lines.</p>
    <form method="get" class="filter-toolbar">
        <div><label for="session">Session ID</label><input type="text" id="session" name="session" maxlength="64" required value="<?= e($session) ?>"></div>
        <div><label for="npc">NPC ID</label><input type="text" id="npc" name="npc" maxlength="64" required value="<?= e($npc) ?>"></div>
        <button>Show memory</button>
    </form>
</section>
<?php if ($notice !== ''): ?>
<section class="chim-panel"><p class="notice" role="alert"><?= e($notice) ?></p></section>
<?php else: ?>
<?php if ($doneText !== ''): ?><p class="notice" role="status"><?= e($doneText) ?></p><?php endif; ?>
<?php if ($message !== ''): ?><p class="notice" role="alert" id="memory-error"><?= e($message) ?></p><?php endif; ?>
<section class="chim-panel">
    <h2>Summary</h2>
    <p class="help"><?= $summary === null ? 'No summary saved for this session and NPC.' : 'Saved ' . e($summary['time']) . ', ' . e(MEMORY_SOURCES[$summary['source']] ?? $summary['source']) . ($summary['source_request_id'] !== null ? ', from request ' . e($summary['source_request_id']) : '') . '.' ?></p>
    <form method="post" action="<?= e($here) ?>">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save_summary">
        <label for="summary">Summary (up to <?= MEMORY_MAX_SUMMARY ?> characters; save it empty to remove it)</label>
        <textarea id="summary" name="summary" rows="5" maxlength="<?= MEMORY_MAX_SUMMARY ?>"><?= e($typed['summary'] ?? ($summary['text'] ?? '')) ?></textarea>
        <div class="preview-actions"><button type="submit">Save summary</button></div>
    </form>
    <form method="post" action="<?= e($here) ?>" class="preview-actions">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="regenerate">
        <button type="submit" class="secondary">Regenerate summary and add a diary entry</button>
        <span>Calls this NPC's model (mode <?= e($config['llm']['mode'] ?? 'mock') ?> unless its profile overrides it); may cost money.</span>
    </form>
</section>
<section class="chim-panel">
    <h2>Diary</h2>
    <?php if (!$diary): ?><p>No diary entries.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="dense-table"><caption>Newest first; the newest <?= MEMORY_DIARY_IN_PROMPT ?> are given to the model, <?= MEMORY_DIARY_KEEP ?> are kept</caption>
        <thead><tr><th scope="col">Time</th><th scope="col">Source</th><th scope="col">Entry</th><th scope="col">Actions</th></tr></thead><tbody>
        <?php foreach ($diary as $note): ?><tr><td><?= e($note['time']) ?></td><td><?= e($note['source']) ?><?= $note['source_request_id'] !== null ? ' (' . e($note['source_request_id']) . ')' : '' ?></td><td class="reply"><?= e($note['text']) ?></td>
            <td><form method="post" action="<?= e($here) ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="delete_diary">
                <input type="hidden" name="note_id" value="<?= e($note['id']) ?>">
                <button type="submit" class="btn-danger" aria-label="Delete diary entry from <?= e($note['time']) ?>">Delete</button>
            </form></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <form method="post" action="<?= e($here) ?>" class="filter-toolbar">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="add_diary">
        <div><label for="diary">New diary entry</label><input type="text" id="diary" name="diary" maxlength="<?= MEMORY_MAX_DIARY ?>" required value="<?= e($typed['diary']) ?>"></div>
        <button type="submit">Add entry</button>
    </form>
</section>
<section class="chim-panel">
    <h2>Candidate facts for <?= e($npc) ?></h2>
    <p class="help">Proposed by the model from successful exchanges in any session. They are not used until approved; approving makes the fact shared knowledge for this NPC in every session, and checkpoint restores never remove approved facts. Edit the topic or text before approving if needed.</p>
    <?php if (!$candidates): ?><p>No candidates waiting.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="dense-table"><thead><tr><th scope="col">Proposed</th><th scope="col">Provenance</th><th scope="col">Topic and fact</th><th scope="col">Review</th></tr></thead><tbody>
    <?php foreach ($candidates as $candidate): $formId = 'candidate-' . $candidate['id']; ?>
        <tr><td><?= e($candidate['time']) ?></td>
        <td>session <?= e($candidate['session_id']) ?>, request <?= e($candidate['source_request_id'] ?? 'none') ?>, <?= e(MEMORY_SOURCES[$candidate['source']] ?? $candidate['source']) ?></td>
        <td><label for="<?= e($formId) ?>-topic">Topic</label><input type="text" id="<?= e($formId) ?>-topic" name="topic" form="<?= e($formId) ?>" maxlength="64" value="<?= e($candidate['topic']) ?>">
            <label for="<?= e($formId) ?>-fact">Fact</label><textarea id="<?= e($formId) ?>-fact" name="fact" form="<?= e($formId) ?>" rows="2" maxlength="300"><?= e($candidate['fact']) ?></textarea></td>
        <td><form method="post" id="<?= e($formId) ?>" action="<?= e($here) ?>" class="links">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="candidate_id" value="<?= e($candidate['id']) ?>">
            <button type="submit" name="action" value="approve">Approve</button>
            <button type="submit" name="action" value="reject" class="btn-danger">Reject</button>
        </form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<section class="chim-panel confirm-panel">
    <h2>Clear memory</h2>
    <form method="post" action="<?= e($here) ?>" class="links">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="clear">
        <label class="inline-check"><input type="checkbox" name="confirm_clear" value="1"> Delete the summary, diary and candidate facts of session <?= e($session) ?> for <?= e($npc) ?></label>
        <button type="submit" class="btn-danger">Clear memory</button>
    </form>
    <p class="help">Conversation history, approved facts, bios and other sessions are not changed. Any reply still being generated for this NPC in this session is discarded, like a cancel.</p>
</section>
<?php endif; ?>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
