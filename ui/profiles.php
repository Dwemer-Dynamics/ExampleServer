<?php
// Character profiles (lib/profiles.php, sql/005_profiles.sql): a name, a system prompt and
// optional reply mode, model and voice overrides, assigned to NPC ids. Saved to this server's
// database; keys and URLs stay in the private config and are never stored or shown here.
// Every write is a CSRF-checked POST with a fixed action, then a redirect back here.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/profiles.php';

const PROFILE_ACTIONS = ['save', 'delete', 'assign', 'unassign'];
const PROFILE_SAVED = [
    'saved' => 'Profile saved.',
    'deleted' => 'Profile deleted. Its NPCs now use the default profile, or the config.',
    'assigned' => 'NPC assigned to this profile.',
    'unassigned' => 'NPC unassigned. It now uses the default profile, or the config.',
];
const MAX_PROFILES = 100;
const MAX_ASSIGNMENTS_SHOWN = 200;
const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/D';

// A profile id from a form or link: digits only, or '' when missing or invalid.
function profile_param(mixed $value): string
{
    return is_string($value) && ctype_digit($value) && strlen($value) <= 18 ? $value : '';
}

// Runs one parameterized query; on failure logs the detail and returns false.
function profile_query(\PgSql\Connection $db, string $sql, array $params = []): \PgSql\Result|false
{
    $result = @pg_query_params($db, $sql, $params);
    if ($result === false) {
        error_log('example-ai dashboard: profiles query failed: ' . pg_last_error($db));
    }
    return $result;
}

function profile_redirect(string $profileId, string $saved): never
{
    header('Location: profiles.php?' . http_build_query(['profile' => $profileId, 'saved' => $saved]), true, 303);
    exit;
}

$selected = profile_param($_GET['profile'] ?? '');
$notice = '';
$message = '';
$errorField = '';
$form = ['name' => '', 'system_prompt' => '', 'llm_mode' => '', 'llm_model' => '', 'tts_voice' => '', 'is_default' => false];
$assignNpc = '';

$db = null;
$config = ui_config();
if ($config === null) {
    $notice = 'Server configuration is missing or invalid.';
} else {
    $db = ui_database($config);
    if ($db === null) {
        $notice = 'Database unavailable. Check PostgreSQL and your private settings.';
    } elseif (($problem = schema_problem($db)) !== null) {
        // Same check as every write endpoint: no profile change unless the schema fits this code.
        $notice = $problem[1];
        $db = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db !== null) {
    $action = $_POST['action'] ?? '';
    if (!check_csrf()) {
        http_response_code(403);
        $notice = 'This form expired. Reload and try again.';
    } elseif (!in_array($action, PROFILE_ACTIONS, true)) {
        http_response_code(400);
        $notice = 'Unknown form action.';
    } elseif ($action === 'save') {
        foreach (['name', 'system_prompt', 'llm_mode', 'llm_model', 'tts_voice'] as $key) {
            $form[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
        }
        $form['is_default'] = ($_POST['is_default'] ?? '') === '1';
        [$values, $errorField, $message] = profile_validate($_POST);
        if ($values !== null) {
            // One default at most: choosing this one clears any other, in the same transaction.
            $params = [$values['name'], $values['system_prompt'], $values['llm_mode'], $values['llm_model'],
                $values['tts_voice'], $values['is_default'] ? 't' : 'f'];
            $ok = profile_query($db, 'BEGIN') !== false;
            if ($ok && $values['is_default']) {
                $ok = profile_query($db, 'UPDATE ex_profiles SET is_default = false WHERE is_default AND id IS DISTINCT FROM $1::bigint',
                    [$selected === '' ? null : $selected]) !== false;
            }
            $saved = !$ok ? false : ($selected === ''
                ? profile_query($db,
                    'INSERT INTO ex_profiles (name, system_prompt, llm_mode, llm_model, tts_voice, is_default)
                     SELECT $1, $2, $3, $4, $5, $6 WHERE (SELECT count(*) FROM ex_profiles) < $7
                     ON CONFLICT DO NOTHING RETURNING id', array_merge($params, [MAX_PROFILES]))
                : profile_query($db,
                    'UPDATE ex_profiles SET name = $1, system_prompt = $2, llm_mode = $3, llm_model = $4, tts_voice = $5,
                         is_default = $6, updated_at = now()
                     WHERE id = $7 AND NOT EXISTS (SELECT 1 FROM ex_profiles other WHERE other.id <> $7 AND lower(other.name) = lower($1))
                     RETURNING id', array_merge($params, [$selected])));
            if ($saved !== false && pg_num_rows($saved) === 1 && profile_query($db, 'COMMIT') !== false) {
                profile_redirect(pg_fetch_result($saved, 0, 0), 'saved');
            }
            @pg_query($db, 'ROLLBACK');
            [$errorField, $message] = $saved === false ? ['', 'Database error. Nothing was saved.']
                : ['profile-name', 'Not saved: another profile has this name, the profile no longer exists, or there are already '
                    . MAX_PROFILES . ' profiles.'];
        }
    } elseif ($action === 'delete') {
        if ($selected === '' || ($_POST['confirm_delete'] ?? '') !== '1') {
            [$errorField, $message] = ['confirm-delete', 'Tick the confirmation box to delete this profile.'];
        } elseif (($result = profile_query($db, 'DELETE FROM ex_profiles WHERE id = $1', [$selected])) === false) {
            $message = 'Database error. Nothing was deleted.';
        } else {
            profile_redirect('', pg_affected_rows($result) === 1 ? 'deleted' : '');
        }
    } elseif ($action === 'assign') {
        $assignNpc = is_string($_POST['npc_id'] ?? null) ? trim($_POST['npc_id']) : '';
        if ($selected === '') {
            $message = 'Save the profile before assigning NPCs.';
        } elseif (!preg_match(ID_PATTERN, $assignNpc)) {
            [$errorField, $message] = ['assign-npc', 'NPC ID must be 1-64 characters of A-Z a-z 0-9 _ . : -'];
        } elseif (profile_query($db,
            'INSERT INTO ex_npc_profiles (npc_id, profile_id) SELECT $1, id FROM ex_profiles WHERE id = $2
             ON CONFLICT (npc_id) DO UPDATE SET profile_id = EXCLUDED.profile_id, assigned_at = now()',
            [$assignNpc, $selected]) === false) {
            $message = 'Database error. Nothing was saved.';
        } else {
            profile_redirect($selected, 'assigned');
        }
    } else {
        $npcId = is_string($_POST['npc_id'] ?? null) ? $_POST['npc_id'] : '';
        if (!preg_match(ID_PATTERN, $npcId) || profile_query($db,
            'DELETE FROM ex_npc_profiles WHERE npc_id = $1 AND profile_id = $2', [$npcId, $selected]) === false) {
            $message = 'That assignment could not be removed.';
        } else {
            profile_redirect($selected, 'unassigned');
        }
    }
    if ($message !== '' && http_response_code() === 200) {
        http_response_code($errorField === '' ? 500 : 400);
    }
}

// Read the list, the selected profile, its NPCs and saved bios for the NPC picker. All bounded.
$profiles = [];
$assigned = [];
$bios = [];
$found = false;
if ($db !== null && $notice === '') {
    $list = profile_query($db,
        'SELECT p.id, p.name, p.is_default, (SELECT count(*) FROM ex_npc_profiles a WHERE a.profile_id = p.id) AS npcs
         FROM ex_profiles p ORDER BY lower(p.name), p.id LIMIT $1', [MAX_PROFILES]);
    $row = profile_query($db, 'SELECT name, system_prompt, llm_mode, llm_model, tts_voice, is_default FROM ex_profiles WHERE id = $1',
        [$selected === '' ? '0' : $selected]);
    $npcs = profile_query($db,
        'SELECT a.npc_id, b.name FROM ex_npc_profiles a LEFT JOIN ex_npc_bios b ON b.npc_id = a.npc_id
         WHERE a.profile_id = $1 ORDER BY a.npc_id LIMIT $2', [$selected === '' ? '0' : $selected, MAX_ASSIGNMENTS_SHOWN]);
    $bioList = profile_query($db, 'SELECT npc_id, name FROM ex_npc_bios ORDER BY lower(name), npc_id LIMIT 50');
    if ($list === false || $row === false || $npcs === false || $bioList === false) {
        $notice = 'Database error. Profiles could not be loaded.';
    } else {
        $profiles = pg_fetch_all($list);
        $assigned = pg_fetch_all($npcs);
        $bios = pg_fetch_all($bioList);
        $savedRow = pg_fetch_assoc($row);
        $found = $savedRow !== false;
        // Keep what was typed after a failed save; otherwise show the saved profile.
        if ($found && ($_POST['action'] ?? '') !== 'save') {
            $form = ['name' => $savedRow['name'], 'system_prompt' => $savedRow['system_prompt'], 'llm_mode' => (string)$savedRow['llm_mode'],
                'llm_model' => (string)$savedRow['llm_model'], 'tts_voice' => (string)$savedRow['tts_voice'], 'is_default' => $savedRow['is_default'] === 't'];
        }
        if ($selected !== '' && !$found) {
            $notice = 'That profile no longer exists.';
        }
    }
}
$llm = is_array($config['llm'] ?? null) ? $config['llm'] : [];
$inherited = 'reply mode ' . ($llm['mode'] ?? 'mock') . ', model ' . (($llm['model'] ?? '') !== '' ? $llm['model'] : 'not set')
    . ', voice ' . (($config['tts']['voice'] ?? '') !== '' ? $config['tts']['voice'] : 'not set');

function profile_field(string $id, string $errorField): string
{
    return $id === $errorField ? ' aria-invalid="true" aria-describedby="profile-error"' : '';
}

$title = 'Profiles';
$active = 'profiles';
require __DIR__ . '/tmpl/header.php';
?>
<p class="chim-panel-intro">Saved to this server's database. A turn uses the NPC's assigned profile, else the default profile, else the config alone. Empty fields inherit the config (now: <?= e($inherited) ?>). Keys and URLs are never stored in profiles.</p>
<?php if ($notice !== '' && $selected === ''): ?>
<section class="chim-panel"><p class="notice" role="alert"><?= e($notice) ?></p></section>
<?php else: ?>
<div class="llm-layout">
    <aside class="llm-left" aria-label="Saved profiles">
        <h2>Profiles</h2>
        <form method="get" class="sample-list">
            <?php foreach ($profiles as $profile): ?>
            <button name="profile" value="<?= e($profile['id']) ?>" class="sample-choice<?= $profile['id'] === $selected ? ' selected' : '' ?>"<?= $profile['id'] === $selected ? ' aria-current="true"' : '' ?>>
                <strong><?= e($profile['name']) ?></strong><small><?= $profile['is_default'] === 't' ? 'Default · ' : '' ?><?= e($profile['npcs']) ?> NPC(s)</small>
            </button>
            <?php endforeach; ?>
            <button name="profile" value="" class="sample-choice<?= $selected === '' ? ' selected' : '' ?>"<?= $selected === '' ? ' aria-current="true"' : '' ?>>
                <strong>New profile</strong><small>Name and prompt; overrides optional</small>
            </button>
        </form>
        <p class="help"><?= $profiles ? 'Up to ' . MAX_PROFILES . ' profiles.' : 'No profiles yet. Every NPC uses the config until you add one.' ?></p>
    </aside>
    <section class="llm-right connector-card">
        <h2><?= $found ? 'Edit profile' : 'New profile' ?></h2>
        <?php if (($savedNote = PROFILE_SAVED[$_GET['saved'] ?? ''] ?? '') !== ''): ?><p class="notice" role="status"><?= e($savedNote) ?></p><?php endif; ?>
        <?php if ($notice !== ''): ?><p class="notice" role="alert"><?= e($notice) ?></p><?php endif; ?>
        <?php if ($message !== ''): ?><p class="notice" id="profile-error" role="alert"><?= e($message) ?></p><?php endif; ?>
        <?php if ($notice === ''): ?>
        <form method="post" action="profiles.php?<?= e(http_build_query(['profile' => $selected])) ?>">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save">
            <div class="setting-row"><label for="profile-name">Name</label><input type="text" id="profile-name" name="name" maxlength="64" required value="<?= e($form['name']) ?>"<?= profile_field('profile-name', $errorField) ?>></div>
            <div class="setting-row"><label for="profile-prompt">System prompt <small>(operator instructions, up to <?= PROFILE_MAX_PROMPT ?> characters)</small></label><textarea id="profile-prompt" name="system_prompt" rows="6" maxlength="<?= PROFILE_MAX_PROMPT ?>"<?= profile_field('profile-prompt', $errorField) ?>><?= e($form['system_prompt']) ?></textarea></div>
            <div class="setting-row"><label for="profile-mode">Reply mode</label><select id="profile-mode" name="llm_mode"<?= profile_field('profile-mode', $errorField) ?>>
                <option value="">Inherit from config (<?= e($llm['mode'] ?? 'mock') ?>)</option>
                <?php foreach (PROFILE_LLM_MODES as $value => $label): ?><option value="<?= e($value) ?>"<?= $form['llm_mode'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
            <div class="setting-row"><label for="profile-model">Model <small>(empty inherits; required for LLM Studio)</small></label><input type="text" id="profile-model" name="llm_model" maxlength="200" value="<?= e($form['llm_model']) ?>"<?= profile_field('profile-model', $errorField) ?>></div>
            <div class="setting-row"><label for="profile-voice">TTS voice <small>(empty inherits)</small></label><input type="text" id="profile-voice" name="tts_voice" maxlength="64" value="<?= e($form['tts_voice']) ?>"<?= profile_field('profile-voice', $errorField) ?>></div>
            <div class="setting-row"><span>Default</span><label class="inline-check"><input type="checkbox" name="is_default" value="1"<?= $form['is_default'] ? ' checked' : '' ?>> Use for NPCs without their own profile</label></div>
            <div class="preview-actions"><button type="submit">Save profile</button></div>
        </form>
        <p class="help">A model override keeps the configured provider, URL and key. Only mock and LLM Studio can be chosen as another mode, because neither needs a URL or key from the config. The voice is used when the game names the NPC in its speak request.</p>
        <?php if ($found): ?>
        <section>
            <h3>NPCs using this profile</h3>
            <?php if (!$assigned): ?><p>No NPC is assigned.<?= $form['is_default'] ? ' As the default it applies to every NPC without its own profile.' : '' ?></p>
            <?php else: ?>
            <div class="table-wrap"><table class="dense-table"><caption>Assigned NPC IDs</caption>
                <thead><tr><th scope="col">NPC ID</th><th scope="col">Saved bio</th><th scope="col">Actions</th></tr></thead><tbody>
                <?php foreach ($assigned as $npc): ?><tr><td><?= e($npc['npc_id']) ?></td><td><?= e($npc['name'] ?? 'none') ?></td>
                    <td><form method="post" action="profiles.php?<?= e(http_build_query(['profile' => $selected])) ?>">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="unassign">
                        <input type="hidden" name="npc_id" value="<?= e($npc['npc_id']) ?>">
                        <button type="submit" class="secondary" aria-label="Unassign <?= e($npc['npc_id']) ?>">Unassign</button>
                    </form></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
            <form method="post" class="filter-toolbar" action="profiles.php?<?= e(http_build_query(['profile' => $selected])) ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="assign">
                <div><label for="assign-npc">NPC ID</label><input type="text" id="assign-npc" name="npc_id" maxlength="64" required list="saved-npcs" value="<?= e($assignNpc) ?>"<?= profile_field('assign-npc', $errorField) ?>></div>
                <datalist id="saved-npcs"><?php foreach ($bios as $bio): ?><option value="<?= e($bio['npc_id']) ?>"><?= e($bio['name']) ?></option><?php endforeach; ?></datalist>
                <button type="submit">Assign NPC</button>
            </form>
            <p class="help">An NPC has one profile; assigning it here moves it from any other profile.</p>
        </section>
        <section>
            <h3>Delete profile</h3>
            <form method="post" class="links" action="profiles.php?<?= e(http_build_query(['profile' => $selected])) ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <label class="inline-check"><input type="checkbox" id="confirm-delete" name="confirm_delete" value="1"<?= profile_field('confirm-delete', $errorField) ?>> Delete <?= e($form['name']) ?> and remove its <?= count($assigned) ?> assignment(s)</label>
                <button type="submit" class="btn-danger">Delete profile</button>
            </form>
        </section>
        <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
