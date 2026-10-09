<?php
// Saved NPC biographies and small knowledge facts (tables from sql/002_npc_knowledge.sql).
// turn.php reads the same rows and gives them to the model as reference data.
// Every write is a CSRF-checked POST with a fixed action, then a redirect back here.
require __DIR__ . '/common.php';

const BIO_ACTIONS = ['save_bio', 'add_fact', 'edit_fact', 'delete_fact'];
const BIO_SAVED = ['bio' => 'Biography saved.', 'fact' => 'Fact added.', 'edited' => 'Fact updated.', 'deleted' => 'Fact deleted.'];
const FACT_SCOPES = ['all', 'npc', 'global'];
const MAX_FACTS_PER_SCOPE = 50;
const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/D';

// Same cleaning as the API (clean_line()), then the length check.
function form_text(mixed $value, int $max): ?string
{
    $text = is_string($value) ? clean_line($value) : '';
    return mb_strlen($text) <= $max ? $text : null;
}

// A fact id from a form or link: digits only, or '' when missing or invalid.
function fact_id(mixed $value): string
{
    return is_string($value) && ctype_digit($value) && strlen($value) <= 18 ? $value : '';
}

// Runs one parameterized query; on failure logs the detail and returns false.
function bio_query(\PgSql\Connection $db, string $sql, array $params): \PgSql\Result|false
{
    $result = @pg_query_params($db, $sql, $params);
    if ($result === false) {
        error_log('example-ai dashboard: npc bios query failed: ' . pg_last_error($db));
    }
    return $result;
}

function redirect_here(string $npcId, string $saved): never
{
    header('Location: npc_bios.php?' . http_build_query(['npc' => $npcId, 'saved' => $saved]), true, 303);
    exit;
}

$selected = is_string($_GET['npc'] ?? null) && preg_match(ID_PATTERN, $_GET['npc']) ? $_GET['npc'] : '';
$search = form_text($_GET['q'] ?? '', 64) ?? '';
// Fact search: text in the topic or fact, within this NPC's and global facts, by scope.
$factSearch = form_text($_GET['fq'] ?? '', 64) ?? '';
$factScope = in_array($_GET['fscope'] ?? '', FACT_SCOPES, true) ? $_GET['fscope'] : 'all';
$editId = fact_id($_GET['edit'] ?? '');
$notice = '';       // page-level problem (config, database, migration, CSRF)
$message = '';      // form problem shown next to the forms
$errorField = '';   // id of the field that failed validation
$form = ['npc_id' => $selected, 'name' => '', 'role' => '', 'bio' => '', 'topic' => '', 'fact' => '', 'scope' => 'npc'];

$db = null;
$config = ui_config();
if ($config === null) {
    $notice = 'Server configuration is missing or invalid.';
} else {
    $db = ui_database($config);
    if ($db === null) {
        $notice = 'Database unavailable. Check PostgreSQL and your private settings.';
    } elseif (($problem = schema_problem($db)) !== null) {
        // Same check as every write endpoint: no bio or fact change unless the schema fits this code.
        $notice = $problem[1];
        $db = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db !== null) {
    $action = $_POST['action'] ?? '';
    if (!check_csrf()) {
        http_response_code(403);
        $notice = 'This form expired. Reload and try again.';
    } elseif (!in_array($action, BIO_ACTIONS, true)) {
        http_response_code(400);
        $notice = 'Unknown form action.';
    } elseif ($action === 'save_bio') {
        $form['npc_id'] = is_string($_POST['npc_id'] ?? null) ? trim($_POST['npc_id']) : '';
        $form['name'] = form_text($_POST['name'] ?? '', 64);
        $form['role'] = form_text($_POST['role'] ?? '', 64);
        $form['bio'] = form_text($_POST['bio'] ?? '', 1000);
        if (!preg_match(ID_PATTERN, $form['npc_id'])) {
            [$errorField, $message] = ['bio-npc-id', 'NPC ID must be 1-64 characters of A-Z a-z 0-9 _ . : -'];
        } elseif ($form['name'] === null || $form['name'] === '') {
            [$errorField, $message] = ['bio-name', 'Name must be 1-64 characters.'];
        } elseif ($form['role'] === null) {
            [$errorField, $message] = ['bio-role', 'Occupation must be at most 64 characters.'];
        } elseif ($form['bio'] === null || $form['bio'] === '') {
            [$errorField, $message] = ['bio-text', 'Biography must be 1-1000 characters.'];
        } elseif (bio_query($db,
            'INSERT INTO ex_npc_bios (npc_id, name, role, bio) VALUES ($1, $2, $3, $4)
             ON CONFLICT (npc_id) DO UPDATE SET name = $2, role = $3, bio = $4, updated_at = now()',
            [$form['npc_id'], $form['name'], $form['role'], $form['bio']]) === false) {
            $message = 'Database error. Nothing was saved.';
        } else {
            redirect_here($form['npc_id'], 'bio');
        }
        $selected = preg_match(ID_PATTERN, $form['npc_id']) ? $form['npc_id'] : $selected;
    } elseif ($action === 'add_fact') {
        $form['scope'] = ($_POST['scope'] ?? '') === 'global' ? 'global' : 'npc';
        $form['topic'] = form_text($_POST['topic'] ?? '', 64);
        $form['fact'] = form_text($_POST['fact'] ?? '', 300);
        $scopeId = $form['scope'] === 'global' ? null : $selected;
        if ($scopeId === '') {
            [$errorField, $message] = ['fact-scope', 'Choose a saved NPC first, or add the fact as global.'];
        } elseif ($form['topic'] === null || $form['topic'] === '') {
            [$errorField, $message] = ['fact-topic', 'Topic must be 1-64 characters.'];
        } elseif ($form['fact'] === null || $form['fact'] === '') {
            [$errorField, $message] = ['fact-text', 'Fact must be 1-300 characters.'];
        } else {
            $result = bio_query($db,
                'INSERT INTO ex_knowledge (npc_id, topic, fact)
                 SELECT $1::text, $2::text, $3::text
                 WHERE (SELECT count(*) FROM ex_knowledge WHERE npc_id IS NOT DISTINCT FROM $1::text) < $4
                 ON CONFLICT DO NOTHING',
                [$scopeId, $form['topic'], $form['fact'], MAX_FACTS_PER_SCOPE]);
            if ($result === false) {
                $message = 'Database error. Nothing was saved.';
            } elseif (pg_affected_rows($result) === 0) {
                [$errorField, $message] = ['fact-topic', 'Not added: this topic already exists here, or this scope already has '
                    . MAX_FACTS_PER_SCOPE . ' facts.'];
            } else {
                redirect_here($selected, 'fact');
            }
        }
    } elseif ($action === 'edit_fact') {
        $editId = fact_id($_POST['fact_id'] ?? '');
        $form['topic'] = form_text($_POST['topic'] ?? '', 64);
        $form['fact'] = form_text($_POST['fact'] ?? '', 300);
        if ($editId === '') {
            http_response_code(400);
            $message = 'Unknown fact.';
        } elseif ($form['topic'] === null || $form['topic'] === '') {
            [$errorField, $message] = ['edit-topic', 'Topic must be 1-64 characters.'];
        } elseif ($form['fact'] === null || $form['fact'] === '') {
            [$errorField, $message] = ['edit-text', 'Fact must be 1-300 characters.'];
        } else {
            // Only this NPC's or global facts, and never a topic that already exists in that scope.
            $result = bio_query($db,
                "UPDATE ex_knowledge SET topic = $2, fact = $3 WHERE id = $1 AND (npc_id = $4 OR npc_id IS NULL)
                 AND NOT EXISTS (SELECT 1 FROM ex_knowledge other WHERE other.id <> $1
                     AND coalesce(other.npc_id, '') = coalesce(ex_knowledge.npc_id, '') AND lower(other.topic) = lower($2))",
                [$editId, $form['topic'], $form['fact'], $selected]);
            if ($result === false) {
                $message = 'Database error. Nothing was saved.';
            } elseif (pg_affected_rows($result) === 0) {
                [$errorField, $message] = ['edit-topic', 'Not saved: the fact no longer exists here, or this topic is already used in its scope.'];
            } else {
                redirect_here($selected, 'edited');
            }
        }
    } else {
        $factId = $_POST['fact_id'] ?? '';
        if (!is_string($factId) || !ctype_digit($factId) || strlen($factId) > 18) {
            http_response_code(400);
            $message = 'Unknown fact.';
        } else {
            $result = bio_query($db, 'DELETE FROM ex_knowledge WHERE id = $1', [$factId]);
            if ($result === false) {
                $message = 'Database error. Nothing was deleted.';
            } elseif (pg_affected_rows($result) === 0) {
                http_response_code(404);
                $message = 'That fact no longer exists.';
            } else {
                redirect_here($selected, 'deleted');
            }
        }
    }
    if ($message !== '' && http_response_code() === 200) {
        http_response_code($errorField === '' ? 500 : 400);
    }
}

// Read the list, the selected bio and its facts. Every query is bounded.
$npcs = [];
$facts = [];
$bioFound = false;
if ($db !== null && $notice === '') {
    $list = bio_query($db,
        "SELECT npc_id, name, role FROM ex_npc_bios
         WHERE $1 = '' OR position(lower($1) IN lower(name || ' ' || npc_id)) > 0
         ORDER BY lower(name), npc_id LIMIT 50",
        [$search]);
    $bio = bio_query($db, 'SELECT name, role, bio FROM ex_npc_bios WHERE npc_id = $1', [$selected]);
    $knowledge = bio_query($db,
        "SELECT id, npc_id, topic, fact, source, source_session_id, source_request_id, embedding IS NOT NULL AS has_vector
         FROM ex_knowledge WHERE (npc_id = $1 OR npc_id IS NULL)
           AND ($3 = '' OR position(lower($3) IN lower(topic || ' ' || fact)) > 0)
           AND ($4 = 'all' OR ($4 = 'global') = (npc_id IS NULL))
         ORDER BY npc_id IS NULL, lower(topic) LIMIT $2",
        [$selected, MAX_FACTS_PER_SCOPE * 2, $factSearch, $factScope]);
    if ($list === false || $bio === false || $knowledge === false) {
        $notice = 'Database error. NPC bios could not be loaded.';
    } else {
        $npcs = pg_fetch_all($list);
        $facts = pg_fetch_all($knowledge);
        $row = pg_fetch_assoc($bio);
        $bioFound = $row !== false;
        // Keep what the user typed after a failed biography save; otherwise show the saved bio.
        if ($bioFound && ($_POST['action'] ?? '') !== 'save_bio') {
            $form = array_merge($form, $row);
        }
    }
}
$saved = BIO_SAVED[$_GET['saved'] ?? ''] ?? '';
// The fact being edited, from the loaded list; after a failed save, keep what was typed.
$editFact = null;
foreach ($facts as $fact) {
    if ($fact['id'] === $editId) {
        $editFact = $fact;
        if (($_POST['action'] ?? '') === 'edit_fact') {
            $editFact['topic'] = $form['topic'] ?? '';
            $editFact['fact'] = $form['fact'] ?? '';
        }
    }
}

// Adds aria-invalid and aria-describedby to the field that failed validation.
function field_error(string $id, string $errorField): string
{
    return $id === $errorField ? ' aria-invalid="true" aria-describedby="bio-form-error"' : '';
}

$title = 'NPC bios';
$active = 'npc_bios';
require __DIR__ . '/tmpl/header.php';
?>
<p class="chim-panel-intro">Saved to this server's database. The conversation uses the selected NPC's biography and up to three matching facts as reference data.</p>
<?php if ($notice !== ''): ?>
<section class="chim-panel"><p class="notice" role="alert"><?= e($notice) ?></p></section>
<?php else: ?>
<div class="llm-layout">
    <aside class="llm-left" aria-label="Saved biographies">
        <h2>Characters</h2>
        <form method="get">
            <input type="hidden" name="npc" value="<?= e($selected) ?>">
            <label for="sample-search">Search saved bios</label>
            <input id="sample-search" type="search" name="q" maxlength="64" value="<?= e($search) ?>" placeholder="Name or NPC ID, then Enter">
        </form>
        <form method="get" class="sample-list">
            <input type="hidden" name="q" value="<?= e($search) ?>">
            <?php foreach ($npcs as $npc): ?>
            <button name="npc" value="<?= e($npc['npc_id']) ?>" class="sample-choice<?= $npc['npc_id'] === $selected ? ' selected' : '' ?>"<?= $npc['npc_id'] === $selected ? ' aria-current="true"' : '' ?>>
                <strong><?= e($npc['name']) ?></strong><small><?= e($npc['npc_id']) ?><?= $npc['role'] !== '' ? ' · ' . e($npc['role']) : '' ?></small>
            </button>
            <?php endforeach; ?>
            <button name="npc" value="" class="sample-choice<?= $selected === '' ? ' selected' : '' ?>"<?= $selected === '' ? ' aria-current="true"' : '' ?>>
                <strong>New biography</strong><small>Any NPC ID the game sends</small>
            </button>
        </form>
        <p class="help"><?= $npcs ? 'Shows up to 50 saved bios.' : 'No saved bios' . ($search !== '' ? ' match this search.' : ' yet. Create one, or run scripts/seed_example.php for two fictional examples.') ?></p>
    </aside>
    <section class="llm-right connector-card">
        <h2>Biography editor</h2>
        <?php if ($saved !== ''): ?><p class="notice" role="status"><?= e($saved) ?></p><?php endif; ?>
        <?php if ($message !== ''): ?><p class="notice" id="bio-form-error" role="alert"><?= e($message) ?></p><?php endif; ?>
        <?php if ($selected !== '' && !$bioFound): ?><p class="help">No saved biography for <?= e($selected) ?> yet.</p><?php endif; ?>
        <form method="post" action="npc_bios.php?<?= e(http_build_query(['npc' => $selected])) ?>">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save_bio">
            <div class="setting-row"><label for="bio-npc-id">NPC ID</label><input type="text" id="bio-npc-id" name="npc_id" maxlength="64" required value="<?= e($form['npc_id']) ?>"<?= $bioFound ? ' readonly' : '' ?><?= field_error('bio-npc-id', $errorField) ?>></div>
            <div class="setting-row"><label for="bio-name">Name</label><input type="text" id="bio-name" name="name" maxlength="64" required value="<?= e($form['name']) ?>"<?= field_error('bio-name', $errorField) ?>></div>
            <div class="setting-row"><label for="bio-role">Occupation</label><input type="text" id="bio-role" name="role" maxlength="64" value="<?= e($form['role']) ?>"<?= field_error('bio-role', $errorField) ?>></div>
            <div class="setting-row"><label for="bio-text">Biography</label><textarea id="bio-text" name="bio" rows="6" maxlength="1000" required<?= field_error('bio-text', $errorField) ?>><?= e($form['bio']) ?></textarea></div>
            <div class="preview-actions"><button type="submit">Save biography</button></div>
        </form>

        <section>
            <h3>Knowledge</h3>
            <p class="help">Short facts for <?= $selected !== '' ? e($selected) : 'all NPCs' ?>. Global facts apply to every NPC. A turn uses at most three facts that share words with the player's line. Facts are shared memory: every session sees them, and checkpoint restores do not change them. Editing a fact clears its stored vector until the next reindex (Memory settings).</p>
            <form method="get" class="filter-toolbar" aria-label="Search facts">
                <input type="hidden" name="npc" value="<?= e($selected) ?>">
                <input type="hidden" name="q" value="<?= e($search) ?>">
                <div><label for="fact-search">Search facts</label><input id="fact-search" type="search" name="fq" maxlength="64" value="<?= e($factSearch) ?>" placeholder="Topic or fact text"></div>
                <div><label for="fact-search-scope">Scope</label><select id="fact-search-scope" name="fscope">
                    <option value="all"<?= $factScope === 'all' ? ' selected' : '' ?>>This NPC and global</option>
                    <option value="npc"<?= $factScope === 'npc' ? ' selected' : '' ?>>Only this NPC</option>
                    <option value="global"<?= $factScope === 'global' ? ' selected' : '' ?>>Only global</option>
                </select></div>
                <button>Search</button>
                <?php if ($factSearch !== '' || $factScope !== 'all'): ?><a href="npc_bios.php?<?= e(http_build_query(['npc' => $selected, 'q' => $search])) ?>">Show all facts</a><?php endif; ?>
            </form>
            <?php if (!$facts): ?><p><?= $factSearch !== '' || $factScope !== 'all' ? 'No facts match this search.' : 'No knowledge saved for this NPC.' ?></p>
            <?php else: ?>
            <div class="table-wrap"><table class="dense-table"><caption>Saved facts</caption>
                <thead><tr><th scope="col">Scope</th><th scope="col">Topic</th><th scope="col">Fact</th><th scope="col">Source</th><th scope="col">Actions</th></tr></thead><tbody>
                <?php foreach ($facts as $fact): ?><tr>
                    <td><?= $fact['npc_id'] === null ? 'Global' : e($fact['npc_id']) ?></td>
                    <td><?= e($fact['topic']) ?></td>
                    <td class="reply"><?= e($fact['fact']) ?></td>
                    <td><?= $fact['source'] === 'extracted' ? 'Approved candidate from session ' . e($fact['source_session_id'] ?? '?') . ', request ' . e($fact['source_request_id'] ?? '?') : 'Manual' ?><?= $fact['has_vector'] === 't' ? '; vector stored' : '' ?></td>
                    <td><a href="npc_bios.php?<?= e(http_build_query(['npc' => $selected, 'q' => $search, 'fq' => $factSearch, 'fscope' => $factScope, 'edit' => $fact['id']])) ?>#fact-edit" aria-label="Edit <?= e($fact['topic']) ?>">Edit</a>
                    <form method="post" action="npc_bios.php?<?= e(http_build_query(['npc' => $selected])) ?>">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="delete_fact">
                        <input type="hidden" name="fact_id" value="<?= e($fact['id']) ?>">
                        <button type="submit" class="btn-danger" aria-label="Delete <?= e($fact['topic']) ?>">Delete</button>
                    </form></td>
                </tr><?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
            <?php if ($editFact !== null): ?>
            <form method="post" id="fact-edit" action="npc_bios.php?<?= e(http_build_query(['npc' => $selected, 'edit' => $editFact['id']])) ?>">
                <h4>Edit fact (<?= $editFact['npc_id'] === null ? 'Global' : e($editFact['npc_id']) ?>)</h4>
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="edit_fact">
                <input type="hidden" name="fact_id" value="<?= e($editFact['id']) ?>">
                <div class="setting-row"><label for="edit-topic">Topic</label><input type="text" id="edit-topic" name="topic" maxlength="64" required value="<?= e($editFact['topic']) ?>"<?= field_error('edit-topic', $errorField) ?>></div>
                <div class="setting-row"><label for="edit-text">Fact</label><textarea id="edit-text" name="fact" rows="3" maxlength="300" required<?= field_error('edit-text', $errorField) ?>><?= e($editFact['fact']) ?></textarea></div>
                <div class="preview-actions"><button type="submit">Save fact</button><a href="npc_bios.php?<?= e(http_build_query(['npc' => $selected])) ?>">Cancel edit</a></div>
            </form>
            <?php elseif ($editId !== ''): ?><p class="notice" role="status">That fact is not shown for this NPC. Clear the search or choose its NPC.</p>
            <?php endif; ?>
            <form method="post" action="npc_bios.php?<?= e(http_build_query(['npc' => $selected])) ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="add_fact">
                <div class="setting-row"><label for="fact-scope">Scope</label><select id="fact-scope" name="scope"<?= field_error('fact-scope', $errorField) ?>>
                    <?php if ($bioFound): ?><option value="npc"<?= $form['scope'] === 'npc' ? ' selected' : '' ?>>Only <?= e($selected) ?></option><?php endif; ?>
                    <option value="global"<?= $form['scope'] === 'global' || !$bioFound ? ' selected' : '' ?>>Global</option>
                </select></div>
                <div class="setting-row"><label for="fact-topic">Topic</label><input type="text" id="fact-topic" name="topic" maxlength="64" required value="<?= e($form['topic']) ?>"<?= field_error('fact-topic', $errorField) ?>></div>
                <div class="setting-row"><label for="fact-text">Fact</label><textarea id="fact-text" name="fact" rows="3" maxlength="300" required<?= field_error('fact-text', $errorField) ?>><?= e($form['fact']) ?></textarea></div>
                <div class="preview-actions"><button type="submit">Add fact</button></div>
            </form>
        </section>
    </section>
</div>
<?php endif; ?>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
