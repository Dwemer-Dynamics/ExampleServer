<?php
// Advanced memory and vector search settings (memory and embeddings sections of the private
// config), and an explicit Reindex button. The embeddings key is set on API keys and is never
// shown. Saving never contacts a provider; Reindex may (and may cost money with 'openai').
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/config_writer.php';
require __DIR__ . '/../lib/embeddings.php';
require __DIR__ . '/../lib/memory.php';

$config = ui_config();
$memory = is_array($config['memory'] ?? null) ? $config['memory'] : [];
$embeddings = is_array($config['embeddings'] ?? null) ? $config['embeddings'] : [];
$form = [
    'enabled' => !empty($memory['enabled']),
    'auto_notes' => !empty($memory['auto_notes']),
    'auto_extract' => !empty($memory['auto_extract']),
    'emb_enabled' => !empty($embeddings['enabled']),
    'emb_provider' => (string)($embeddings['provider'] ?? 'mock'),
    'emb_url' => (string)($embeddings['url'] ?? EMBEDDING_DEFAULT_URL),
    'emb_model' => (string)($embeddings['model'] ?? EMBEDDING_DEFAULT_MODEL),
    'emb_dims' => (string)($embeddings['dims'] ?? 0),
    'emb_timeout' => (string)($embeddings['timeout_seconds'] ?? 5),
];
$message = '';
$errorField = '';
$reindexed = null;
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $config !== null) {
    if (!check_csrf()) {
        http_response_code(403);
        $message = 'This form expired. Reload and try again.';
    } elseif ($action === 'save_memory') {
        $values = ['enabled' => form_checked('enabled'), 'auto_notes' => form_checked('auto_notes'), 'auto_extract' => form_checked('auto_extract')];
        session_write_close();
        $message = save_and_redirect(function (array $latest) use ($values): array {
            $latest['memory'] = $values + (is_array($latest['memory'] ?? null) ? $latest['memory'] : []);
            return $latest;
        }, 'memory_settings.php', 'memory');
    } elseif ($action === 'save_embeddings') {
        $text = fn(string $key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        $form = array_merge($form, ['emb_enabled' => form_checked('emb_enabled'), 'emb_provider' => $text('emb_provider'),
            'emb_url' => $text('emb_url'), 'emb_model' => $text('emb_model'), 'emb_dims' => $text('emb_dims'), 'emb_timeout' => $text('emb_timeout')]);
        $values = [
            'enabled' => $form['emb_enabled'],
            'provider' => isset(EMBEDDING_PROVIDERS[$form['emb_provider']]) ? $form['emb_provider'] : null,
            'url' => form_url($form['emb_url']),
            'model' => form_name($form['emb_model'], 200),
            'dims' => form_int($form['emb_dims'], 0, EMBEDDING_MAX_DIMS),
            'timeout_seconds' => form_int($form['emb_timeout'], 1, 30),
        ];
        if ($values['provider'] === null) {
            [$errorField, $message] = ['emb_provider', 'Choose an embeddings provider from the list.'];
        } elseif ($values['url'] === null) {
            [$errorField, $message] = ['emb_url', 'URL must be empty (default) or an http(s) URL without spaces or a user name.'];
        } elseif ($values['model'] === null || ($values['provider'] === 'openai' && $values['model'] === '')) {
            [$errorField, $message] = ['emb_model', 'Model must be letters, digits and _ . : / @ + - (required for openai).'];
        } elseif ($values['dims'] === null) {
            [$errorField, $message] = ['emb_dims', 'Vector size must be 0 (accept the provider\'s size) to ' . EMBEDDING_MAX_DIMS . '.'];
        } elseif ($values['timeout_seconds'] === null) {
            [$errorField, $message] = ['emb_timeout', 'Timeout must be 1-30 seconds.'];
        } else {
            session_write_close();
            $message = save_and_redirect(function (array $latest) use ($values): array {
                // embeddings.api_key and unknown keys stay as they are.
                $latest['embeddings'] = $values + (is_array($latest['embeddings'] ?? null) ? $latest['embeddings'] : []);
                return $latest;
            }, 'memory_settings.php', 'embeddings');
        }
    } elseif ($action === 'reindex') {
        $settings = embedding_settings($config);
        $db = ui_database($config);
        if ($settings === null) {
            $message = 'Turn vector search on and save it before reindexing.';
        } elseif ($db === null || schema_problem($db) !== null) {
            $message = 'The database is unavailable or not migrated. Nothing was indexed.';
        } else {
            // Provider calls can take a while: release the PHP session first.
            session_write_close();
            $requestId = audit_child_id('ui-emb');
            $reindexed = embedding_reindex($db, $settings, fn(array $row) => audit_insert($db, $config,
                $row + ['kind' => 'embedding', 'provider' => $settings['provider'] ?? 'mock', 'request_id' => $requestId]));
            $reindexed['request_id'] = $requestId;
        }
    } else {
        $message = 'Unknown form action.';
    }
    if ($message !== '' && http_response_code() === 200) {
        http_response_code(400);
    }
}

function memory_field(string $id, string $errorField): string
{
    return $id === $errorField ? ' aria-invalid="true" aria-describedby="memory-settings-error"' : '';
}

$stats = null;
$modelId = '';
if ($config !== null && ($settings = embedding_settings($config)) !== null) {
    $modelId = embedding_model_id($settings);
    $db ??= ui_database($config);
    $stats = $db !== null && schema_problem($db) === null ? embedding_stats($db, $modelId) : null;
}
$keySaved = (string)($embeddings['api_key'] ?? '') !== '';
$saved = ['memory' => 'Memory settings saved.', 'embeddings' => 'Vector search settings saved. Press Reindex if the provider, endpoint, model or vector size changed.'][$_GET['saved'] ?? ''] ?? '';
$title = 'Memory settings';
$active = 'memory_settings';
require __DIR__ . '/tmpl/header.php';
?>
<p class="chim-panel-intro">Optional and off by default. Saved to the private server config; the next turn uses it. Notes and candidate facts for one session and NPC are on <a href="memory.php">Roleplay &gt; Memory</a>.</p>
<?php if ($config === null): ?>
<section class="chim-panel"><p class="notice" role="alert">Server configuration is missing or invalid.</p></section>
<?php else: ?>
<?php if ($saved !== ''): ?><p class="notice" role="status"><?= e($saved) ?></p><?php endif; ?>
<?php if ($message !== ''): ?><p class="notice" role="alert" id="memory-settings-error"><?= e($message) ?></p><?php endif; ?>
<div class="llm-layout voice-layout">
    <section class="connector-card">
        <h2>Advanced memory</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save_memory">
            <div class="setting-row"><span>Status</span><label class="inline-check"><input type="checkbox" name="enabled" value="1"<?= $form['enabled'] ? ' checked' : '' ?>> Enabled: turns get this session's summary and newest diary entries</label></div>
            <div class="setting-row"><span>Automatic notes</span><label class="inline-check"><input type="checkbox" name="auto_notes" value="1"<?= $form['auto_notes'] ? ' checked' : '' ?>> Rewrite the summary and add a diary entry after each successful turn</label></div>
            <div class="setting-row"><span>Automatic fact proposals</span><label class="inline-check"><input type="checkbox" name="auto_extract" value="1"<?= $form['auto_extract'] ? ' checked' : '' ?>> Propose up to <?= MEMORY_MAX_CANDIDATES ?> candidate facts per successful turn, for review</label></div>
            <div class="preview-actions"><button type="submit">Save memory settings</button></div>
        </form>
        <p class="help">Automatic steps need Enabled too. They call the NPC's model after the reply was sent (extra calls that may cost money), never retry, and save nothing when the turn was cancelled, replaced or restored away. In mock mode they write labelled mock notes and propose a fact only for player lines like "remember that the bridge is closed".</p>
    </section>
    <section class="connector-card">
        <h2>Vector search for facts</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save_embeddings">
            <div class="setting-row"><span>Status</span><label class="inline-check"><input type="checkbox" name="emb_enabled" value="1"<?= $form['emb_enabled'] ? ' checked' : '' ?>> Enabled</label></div>
            <div class="setting-row"><label for="emb_provider">Provider</label><select id="emb_provider" name="emb_provider"<?= memory_field('emb_provider', $errorField) ?>>
                <?php foreach (EMBEDDING_PROVIDERS as $value => $label): ?><option value="<?= e($value) ?>"<?= $form['emb_provider'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
            <div class="setting-row"><label for="emb_url">Embeddings URL <small>(openai; full endpoint)</small></label><input type="url" id="emb_url" name="emb_url" maxlength="300" value="<?= e($form['emb_url']) ?>" placeholder="<?= e(EMBEDDING_DEFAULT_URL) ?>"<?= memory_field('emb_url', $errorField) ?>></div>
            <div class="setting-row"><label for="emb_model">Model <small>(openai)</small></label><input type="text" id="emb_model" name="emb_model" maxlength="200" value="<?= e($form['emb_model']) ?>"<?= memory_field('emb_model', $errorField) ?>></div>
            <div class="setting-row"><label for="emb_dims">Vector size <small>(0 accepts the provider's)</small></label><input type="number" id="emb_dims" name="emb_dims" min="0" max="<?= EMBEDDING_MAX_DIMS ?>" required value="<?= e($form['emb_dims']) ?>"<?= memory_field('emb_dims', $errorField) ?>></div>
            <div class="setting-row"><label for="emb_timeout">Timeout (seconds, 1-30)</label><input type="number" id="emb_timeout" name="emb_timeout" min="1" max="30" required value="<?= e($form['emb_timeout']) ?>"<?= memory_field('emb_timeout', $errorField) ?>></div>
            <div class="setting-row"><span>API key</span><span><span class="chim-tag<?= $keySaved ? ' chim-badge-success' : '' ?>"><?= $keySaved ? 'Saved' : 'Not set' ?></span> <a href="api_keys.php">Change on API keys</a></span></div>
            <div class="preview-actions"><button type="submit">Save vector search</button><span>Saving never contacts the provider.</span></div>
        </form>
        <p class="help">Mock vectors are hashed words: deterministic and free, but not semantic (they only match shared words). A turn sends the player's line for one embedding, compares it with at most <?= EMBEDDING_SCAN_LIMIT ?> stored vectors of this NPC's and global facts (newest first) and keeps matches scoring at least <?= EMBEDDING_MIN_SCORE ?>. Keyword search fills the remaining places and is used alone if the call fails.</p>
        <h3>Reindex</h3>
        <?php if ($stats === null): ?>
        <p><?= $form['emb_enabled'] ? 'Vector counts are unavailable (database not ready).' : 'Vector search is off.' ?></p>
        <?php else: ?>
        <p><?= e($stats['indexed']) ?> of <?= e($stats['total']) ?> facts have a vector for <code><?= e($modelId) ?></code>; <?= e($stats['missing']) ?> need one. Editing a fact clears its vector.</p>
        <?php endif; ?>
        <?php if ($reindexed !== null): ?>
        <p class="notice" role="status">Indexed <?= e($reindexed['indexed']) ?> fact(s)<?= $reindexed['changed'] ? '; ' . e($reindexed['changed']) . ' changed meanwhile and were skipped' : '' ?><?= $reindexed['error'] !== null ? '. Stopped: ' . e($reindexed['error']) . ' (not retried; details only in the server log)' : '' ?>. Request <?= e($reindexed['request_id']) ?> in Logs &gt; Connector calls.</p>
        <?php endif; ?>
        <form method="post" class="preview-actions">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="reindex">
            <button type="submit"<?= $stats === null ? ' disabled' : '' ?>>Reindex up to <?= EMBEDDING_REINDEX_LIMIT ?> facts</button>
            <span>Embeds facts without a current vector, <?= EMBEDDING_BATCH ?> per call. Press again for more.</span>
        </form>
    </section>
</div>
<?php endif; ?>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
