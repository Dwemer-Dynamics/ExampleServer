<?php
// Optional NPC selection settings (decision section of the private config) and a test that
// goes through api.php to the existing decision.php handler. Selection only: the test
// shows the chosen id and reason and starts no conversation, history or action.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/config_writer.php';
require __DIR__ . '/../lib/decision.php';

const DECISION_PROVIDERS = ['mock' => 'Mock (picks an NPC named in the line)', 'jev' => 'OpenRouter JEV decisions API (needs its key)'];

$config = ui_config();
$settings = is_array($config['decision'] ?? null) ? $config['decision'] : [];
$form = [
    'enabled' => !empty($settings['enabled']),
    'provider' => (string)($settings['provider'] ?? 'mock'),
    'url' => (string)($settings['url'] ?? JEV_DEFAULT_URL),
    'model' => (string)($settings['model'] ?? JEV_DEFAULT_MODEL),
];
$message = '';
$errorField = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $config !== null) {
    $form = ['enabled' => form_checked('enabled')] + array_map(fn($key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '',
        ['provider' => 'provider', 'url' => 'url', 'model' => 'model']);
    $values = [
        'enabled' => $form['enabled'],
        'provider' => isset(DECISION_PROVIDERS[$form['provider']]) ? $form['provider'] : null,
        'url' => form_url($form['url']),
        'model' => form_name($form['model'], 200),
    ];
    if (!check_csrf()) {
        http_response_code(403);
        $message = 'This form expired. Reload and try again.';
    } elseif (($_POST['action'] ?? '') !== 'save') {
        $message = 'Unknown form action.';
    } elseif ($values['provider'] === null) {
        [$errorField, $message] = ['provider', 'Choose a provider from the list.'];
    } elseif ($values['url'] === null) {
        [$errorField, $message] = ['url', 'URL must be empty (default) or an http(s) URL without spaces or a user name.'];
    } elseif ($values['model'] === null) {
        [$errorField, $message] = ['model', 'Model must be at most 200 characters of letters, digits and _ . : / @ + -'];
    } else {
        session_write_close();
        $message = save_and_redirect(function (array $latest) use ($values): array {
            // decision.api_key and unknown keys stay as they are.
            $latest['decision'] = $values + (is_array($latest['decision'] ?? null) ? $latest['decision'] : []);
            return $latest;
        }, 'decision_settings.php', 'decision');
    }
    if ($message !== '' && http_response_code() === 200) {
        http_response_code(400);
    }
}

function decision_field(string $id, string $errorField): string
{
    return $id === $errorField ? ' aria-invalid="true" aria-describedby="decision-error"' : '';
}

$keySaved = (string)($settings['api_key'] ?? '') !== '';
$title = 'NPC selection';
$active = 'decision';
require __DIR__ . '/tmpl/header.php';
?>
<p class="chim-panel-intro">Optional. Picks which of up to <?= DECISION_MAX_CANDIDATES ?> NPCs the game offered should answer. Off, or on any failure, it returns the game's baseline NPC. A game that already knows its target (explicit or crosshair target) skips it.</p>
<?php if ($config === null): ?>
<section class="chim-panel"><p class="notice" role="alert">Server configuration is missing or invalid.</p></section>
<?php else: ?>
<div class="llm-layout">
    <section class="llm-left">
        <h2>Test</h2>
        <p class="help">Uses the saved settings through the decision endpoint. jev mode calls OpenRouter (1.5 second limit) and may cost money.</p>
        <form id="decision-test">
            <input type="hidden" id="csrf" value="<?= e($csrf) ?>">
            <label for="transcript">Player line</label>
            <textarea id="transcript" rows="3" maxlength="2000" required>Lydia, wait for me here.</textarea>
            <label for="candidates">Offered NPCs, one per line: <code>id Name</code> (1-<?= DECISION_MAX_CANDIDATES ?>)</label>
            <textarea id="candidates" rows="4" required aria-describedby="candidates-help">npc_lydia Lydia
npc_guide Guide</textarea>
            <p class="help" id="candidates-help">The first line is the baseline NPC.</p>
            <button type="submit" id="decision-send">Choose responder</button>
        </form>
        <div class="preview-output" role="status" aria-live="polite"><p id="decision-status">No test yet.</p><dl id="decision-result" hidden><dt>Request ID</dt><dd id="decision-request"></dd><dt>Chosen ID</dt><dd id="chosen-id"></dd><dt>Source</dt><dd id="chosen-source"></dd><dt>Reason</dt><dd id="chosen-reason"></dd></dl></div>
    </section>
    <section class="llm-right connector-card">
        <h2>Settings</h2>
        <?php if (($_GET['saved'] ?? '') === 'decision'): ?><p class="notice" role="status">NPC selection settings saved.</p><?php endif; ?>
        <?php if ($message !== ''): ?><p class="notice" role="alert" id="decision-error"><?= e($message) ?></p><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save">
            <div class="setting-row"><span>Status</span><label><input type="checkbox" name="enabled" value="1"<?= $form['enabled'] ? ' checked' : '' ?>> Enabled</label></div>
            <div class="setting-row"><label for="provider">Provider</label><select id="provider" name="provider"<?= decision_field('provider', $errorField) ?>>
                <?php foreach (DECISION_PROVIDERS as $value => $label): ?><option value="<?= e($value) ?>"<?= $form['provider'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
            <div class="setting-row"><label for="url">JEV URL</label><input type="url" id="url" name="url" maxlength="300" value="<?= e($form['url']) ?>"<?= decision_field('url', $errorField) ?>></div>
            <div class="setting-row"><label for="model">JEV model</label><input type="text" id="model" name="model" maxlength="200" value="<?= e($form['model']) ?>"<?= decision_field('model', $errorField) ?>></div>
            <div class="setting-row"><span>JEV API key</span><span><span class="chim-tag<?= $keySaved ? ' chim-badge-success' : '' ?>"><?= $keySaved ? 'Saved' : 'Not set' ?></span> <a href="api_keys.php">Change on API keys</a></span></div>
            <div class="preview-actions"><button type="submit">Save NPC selection</button><span>Saving never contacts OpenRouter.</span></div>
        </form>
    </section>
</div>
<script>
document.getElementById('decision-test').addEventListener('submit', async event => {
    event.preventDefault();
    const send = document.getElementById('decision-send');
    const status = document.getElementById('decision-status');
    const result = document.getElementById('decision-result');
    const candidates = document.getElementById('candidates').value.split('\n').map(line => line.trim()).filter(Boolean).map(line => {
        const [id, ...name] = line.split(/\s+/);
        return name.length ? {id, name: name.join(' ')} : {id};
    });
    result.hidden = true;
    if (candidates.length < 1 || candidates.length > <?= DECISION_MAX_CANDIDATES ?>) {
        status.textContent = 'Offer 1-<?= DECISION_MAX_CANDIDATES ?> NPCs, one per line.';
        return;
    }
    send.disabled = true;
    status.textContent = 'Choosing…';
    try {
        const response = await fetch('api.php?operation=decision', {
            method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.getElementById('csrf').value},
            body: JSON.stringify({protocol: 1, transcript: document.getElementById('transcript').value, baseline_id: candidates[0].id, candidates})
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error((data.error?.message || 'Request failed.') + (data.request_id ? ' Request ' + data.request_id + '.' : ''));
        document.getElementById('decision-request').textContent = data.request_id || 'not reported';
        document.getElementById('chosen-id').textContent = data.chosen_id;
        document.getElementById('chosen-source').textContent = data.source;
        document.getElementById('chosen-reason').textContent = data.reason;
        result.hidden = false;
        status.textContent = 'Decision received.';
    } catch (error) {
        status.textContent = error.message;
    } finally { send.disabled = false; }
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
