<?php
// API keys for the four secret slots in the private config. Card layout follows
// HerikaServer's ui/core/api_badge.php; keys stay in config/config.php, not the database.
// A key is never sent back to the browser: fields start blank and only "Saved" or "Not set"
// is shown. A blank field keeps the current key; the clear box removes it.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/config_writer.php';

const KEY_SLOTS = [
    'llm' => ['title' => 'LLM API key', 'path' => 'llm.api_key', 'link' => 'llm.php', 'page' => 'LLM',
        'uses' => 'OpenAI-compatible chat (openai and local modes, optional) and OpenRouter chat (required). Never sent in dwemerllm mode.'],
    'tts' => ['title' => 'Text to speech API key', 'path' => 'tts.api_key', 'link' => 'voice.php', 'page' => 'Voice',
        'uses' => 'OpenAI-compatible /v1/audio/speech (openai provider only, optional). PocketTTS needs no key.'],
    'decision' => ['title' => 'NPC selection API key (OpenRouter JEV)', 'path' => 'decision.api_key', 'link' => 'decision_settings.php', 'page' => 'NPC selection',
        'uses' => 'OpenRouter decisions API used by NPC selection in jev mode. Separate from the LLM key.'],
    'embeddings' => ['title' => 'Embeddings API key', 'path' => 'embeddings.api_key', 'link' => 'memory_settings.php', 'page' => 'Memory settings',
        'uses' => 'OpenAI-compatible /v1/embeddings for vector search (openai provider only). The mock provider needs no key. Separate from the LLM key.'],
];

$config = ui_config();
$message = '';
$errorField = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $config !== null) {
    $changes = [];
    if (!check_csrf()) {
        http_response_code(403);
        $message = 'This form expired. Reload and try again.';
    } else {
        foreach (KEY_SLOTS as $slot => $info) {
            $typed = is_string($_POST["key_$slot"] ?? null) ? $_POST["key_$slot"] : '';
            $clear = form_checked("clear_$slot");
            if ($clear && $typed !== '') {
                [$errorField, $message] = ["key-$slot", $info['title'] . ': either type a new key or tick clear, not both.'];
                break;
            }
            if ($clear) {
                $changes[$slot] = '';
            } elseif ($typed !== '') {
                $secret = form_secret($typed);
                if ($secret === null) {
                    [$errorField, $message] = ["key-$slot", $info['title'] . ' must be 1-512 printable characters without spaces. Nothing was saved.'];
                    break;
                }
                $changes[$slot] = $secret;
            }
        }
        if ($message === '' && !$changes) {
            $message = 'No key was typed or cleared, so nothing changed.';
        }
    }
    if ($message !== '') {
        http_response_code(http_response_code() === 200 ? 400 : http_response_code());
    } else {
        session_write_close();
        // Only the typed or cleared slots change; the others are kept from the latest file.
        $message = save_and_redirect(function (array $latest) use ($changes): array {
            foreach ($changes as $slot => $value) {
                $section = is_array($latest[$slot] ?? null) ? $latest[$slot] : [];
                $section['api_key'] = $value;
                $latest[$slot] = $section;
            }
            return $latest;
        }, 'api_keys.php', 'keys');
    }
    unset($changes, $typed, $secret);
}
$title = 'API keys';
$active = 'api_keys';
require __DIR__ . '/tmpl/header.php';
?>
<p class="chim-panel-intro">Keys are stored only in the private server config file. They are never shown again, sent to this page or written to the database.</p>
<?php if ($config === null): ?>
<section class="chim-panel"><p class="notice" role="alert">Server configuration is missing or invalid.</p></section>
<?php else: ?>
<?php if (($_GET['saved'] ?? '') === 'keys'): ?><p class="notice" role="status">API keys saved.</p><?php endif; ?>
<?php if ($message !== ''): ?><p class="notice" role="alert" id="keys-error"><?= e($message) ?></p><?php endif; ?>
<form method="post" autocomplete="off" class="connector-card">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="section-header"><h2>Service keys</h2><button type="submit">Save keys</button></div>
    <div class="provider-grid">
        <?php foreach (KEY_SLOTS as $slot => $info): $hasKey = (string)($config[$slot]['api_key'] ?? '') !== ''; ?>
        <section class="provider-card<?= $hasKey ? ' has-key' : '' ?>" aria-labelledby="title-<?= e($slot) ?>">
            <div class="provider-head">
                <h3 class="provider-title" id="title-<?= e($slot) ?>"><?= e($info['title']) ?></h3>
                <span class="chim-tag<?= $hasKey ? ' chim-badge-success' : '' ?>" id="state-<?= e($slot) ?>"><?= $hasKey ? 'Saved' : 'Not set' ?></span>
            </div>
            <label for="key-<?= e($slot) ?>">New key for <code><?= e($info['path']) ?></code></label>
            <input type="password" id="key-<?= e($slot) ?>" name="key_<?= e($slot) ?>" maxlength="512" autocomplete="new-password" spellcheck="false" placeholder="<?= $hasKey ? 'Leave blank to keep the saved key' : 'Paste a key' ?>" aria-describedby="state-<?= e($slot) ?> uses-<?= e($slot) ?>"<?= $errorField === "key-$slot" ? ' aria-invalid="true"' : '' ?>>
            <label class="inline-check"><input type="checkbox" name="clear_<?= e($slot) ?>" value="1"<?= $hasKey ? '' : ' disabled' ?>> Clear the saved key</label>
            <p class="provider-subtext" id="uses-<?= e($slot) ?>">Used for: <?= e($info['uses']) ?> Settings: <a href="<?= e($info['link']) ?>"><?= e($info['page']) ?></a>.</p>
        </section>
        <?php endforeach; ?>
    </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
