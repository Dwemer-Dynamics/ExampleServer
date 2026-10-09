<?php
// LLM settings (llm section of the private config) and an explicit test button.
// The API key is on the API keys page. A test runs only when its button is pressed: it may
// call your provider (and cost money); it saves no history and executes no actions.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/config_writer.php';
require __DIR__ . '/../lib/llm.php';

const LLM_MODES = [
    'mock' => 'Mock (no model, predictable replies)',
    'openai' => 'OpenAI or OpenAI-compatible API',
    'openrouter' => 'OpenRouter (needs an API key)',
    'local' => 'Local OpenAI-compatible server',
    'dwemerllm' => 'DwemerDistro LLM Studio',
];

$config = ui_config();
$llm = is_array($config['llm'] ?? null) ? $config['llm'] : [];
$form = [
    'mode' => (string)($llm['mode'] ?? 'mock'),
    'base_url' => (string)($llm['base_url'] ?? ''),
    'model' => (string)($llm['model'] ?? ''),
    'timeout_seconds' => (string)($llm['timeout_seconds'] ?? 30),
    'max_tokens' => (string)($llm['max_tokens'] ?? 200),
    'mock_delay_seconds' => (string)($llm['mock_delay_seconds'] ?? 0),
];
$message = '';
$errorField = '';
$test = null;
$testText = 'Hello, can you hear me?';
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $config !== null) {
    if (!check_csrf()) {
        http_response_code(403);
        $message = 'This form expired. Reload and try again.';
    } elseif ($action === 'save') {
        foreach (array_keys($form) as $key) {
            $form[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        }
        $values = [
            'mode' => isset(LLM_MODES[$form['mode']]) ? $form['mode'] : null,
            'base_url' => form_url($form['base_url']),
            'model' => form_name($form['model'], 200),
            'timeout_seconds' => form_int($form['timeout_seconds'], 1, 120),
            'max_tokens' => form_int($form['max_tokens'], 16, 1000),
            'mock_delay_seconds' => form_int($form['mock_delay_seconds'], 0, 10),
        ];
        $errors = [
            'mode' => 'Choose a reply mode from the list.',
            'base_url' => 'Base URL must be empty or an http(s) URL without spaces or a user name (at most 300 characters).',
            'model' => 'Model must be at most 200 characters of letters, digits and _ . : / @ + -',
            'timeout_seconds' => 'Timeout must be 1-120 seconds.',
            'max_tokens' => 'Max tokens must be 16-1000.',
            'mock_delay_seconds' => 'Mock delay must be 0-10 seconds.',
        ];
        foreach ($values as $key => $value) {
            if ($value === null) {
                [$errorField, $message] = [$key, $errors[$key]];
                break;
            }
        }
        if ($message === '' && $values['mode'] !== 'mock' && $values['model'] === '') {
            [$errorField, $message] = ['model', 'Every mode except mock needs a model.'];
        } elseif ($message === '' && $values['mode'] === 'local' && $values['base_url'] === '') {
            [$errorField, $message] = ['base_url', 'Local mode needs a base URL, for example http://127.0.0.1:1234/v1'];
        }
        if ($message !== '') {
            http_response_code(400);
        } else {
            session_write_close();
            $message = save_and_redirect(function (array $latest) use ($values): array {
                // Keep api_key and any other llm keys exactly as they are.
                $latest['llm'] = $values + (is_array($latest['llm'] ?? null) ? $latest['llm'] : []);
                return $latest;
            }, 'llm.php', 'llm');
        }
    } elseif ($action === 'test') {
        $testText = is_string($_POST['text'] ?? null) ? clean_line($_POST['text']) : '';
        if ($testText === '' || mb_strlen($testText) > 1000) {
            http_response_code(400);
            [$errorField, $message] = ['test-text', 'Test message must be 1-1000 characters.'];
        } else {
            // Release the PHP session lock before a provider call that can take a while.
            session_write_close();
            $turn = ['npc_name' => 'Guide', 'player_name' => 'Traveler', 'text' => $testText, 'context' => []];
            $started = microtime(true);
            try {
                $raw = generate_reply($llm, $turn, [], ['bio' => null, 'facts' => []]);
                [$reply, $actions, $rejected] = extract_actions($raw, is_array($config['allowed_actions'] ?? null) ? $config['allowed_actions'] : []);
                $test = ['ok' => true, 'reply' => $reply, 'actions' => array_column($actions, 'name'), 'rejected' => $rejected];
            } catch (Throwable $error) {
                // Provider replies can echo request details, so only the error type is logged.
                error_log('example-ai dashboard: LLM test failed (' . get_class($error) . ')');
                $test = ['ok' => false, 'reason' => llm_test_reason($error)];
            }
            $test['ms'] = (int)round((microtime(true) - $started) * 1000);
        }
    } else {
        http_response_code(400);
        $message = 'Unknown form action.';
    }
}

// Turns a failed test into one fixed sentence. The exception text is only matched against
// the known messages from llm_chat(), llm_chat_url() and http_post(); it is never shown,
// because it can contain a host name and curl's own wording.
function llm_test_reason(Throwable $error): string
{
    $text = $error instanceof RuntimeException ? $error->getMessage() : '';
    if (preg_match('/^LLM returned HTTP (\d{1,3}) without usable text$/D', $text, $match)) {
        $status = (int)$match[1];
        $hint = match (true) {
            $status === 200 => 'the reply had no usable text; check that the model is a chat model',
            $status === 401, $status === 403 => 'the API key was refused or is missing',
            $status === 404 => 'the URL or model was not found',
            $status === 408 => 'the provider timed out',
            $status === 429 => 'rate limit or quota reached',
            $status >= 300 && $status < 400 => 'the URL redirects, and redirects are not followed; check the base URL',
            $status >= 400 && $status < 500 => 'the provider rejected the request; check the model name',
            $status >= 500 && $status < 600 => 'the provider had an error; try again later',
            default => 'unexpected response',
        };
        return "HTTP $status: $hint.";
    }
    if (str_starts_with($text, 'POST to ') && str_contains($text, ' failed: ')) {
        $curl = strtolower(substr($text, strpos($text, ' failed: ') + 9));
        return match (true) {
            str_contains($curl, 'timed out') => 'Network error: no answer before the timeout.',
            str_contains($curl, 'resolve') => 'Network error: the host name could not be resolved.',
            str_contains($curl, 'ssl'), str_contains($curl, 'certificate') => 'Network error: TLS/certificate problem.',
            str_contains($curl, 'refused'), str_contains($curl, 'connect') => 'Network error: could not connect; is the server running at that address?',
            str_contains($curl, 'writ') =>'The reply was larger than the 1 MB limit.',
            default => 'Network error: the request could not be completed.',
        };
    }
    if (str_starts_with($text, 'llm.api_key must be set')) {
        return 'This mode needs an API key; set it on API keys.';
    }
    if (str_starts_with($text, 'llm.base_url must be set')) {
        return 'Local mode needs a base URL.';
    }
    if (str_starts_with($text, 'llm.model must be set')) {
        return 'This mode needs a model.';
    }
    if (str_starts_with($text, 'Unknown llm mode')) {
        return 'The saved reply mode is not recognised; choose one and save.';
    }
    return 'No reason is available.';
}

function llm_field(string $id, string $errorField): string
{
    return $id === $errorField ? ' aria-invalid="true" aria-describedby="llm-error"' : '';
}

$keySaved = (string)($llm['api_key'] ?? '') !== '';
$title = 'LLM';
$active = 'llm';
require __DIR__ . '/tmpl/header.php';
?>
<p class="chim-panel-intro">Saved to the private server config; the next conversation turn uses it. The API key is set on <a href="api_keys.php">API keys</a> and is never shown.</p>
<?php if ($config === null): ?>
<section class="chim-panel"><p class="notice" role="alert">Server configuration is missing or invalid.</p></section>
<?php else: ?>
<div class="llm-layout">
    <section class="llm-left">
        <h2>Test</h2>
        <p class="help">Uses the saved settings. Mock mode stays on this server. Other modes call your provider and may cost money. Nothing is saved to history and actions are only listed.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="test">
            <label for="test-text">Test message</label>
            <textarea id="test-text" name="text" rows="3" maxlength="1000" required<?= llm_field('test-text', $errorField) ?>><?= e($testText) ?></textarea>
            <button type="submit">Send test to <?= e($llm['mode'] ?? 'mock') ?></button>
        </form>
        <?php if ($test !== null): ?>
        <div class="preview-output" role="status">
            <?php if ($test['ok']): ?>
            <h3>Reply in <?= e($test['ms']) ?> ms</h3>
            <p class="reply"><?= e($test['reply']) ?></p>
            <p class="help">Allowed actions: <?= e(implode(', ', $test['actions']) ?: 'None') ?>. Rejected: <?= e(implode(', ', $test['rejected']) ?: 'None') ?>.</p>
            <?php else: ?>
            <p class="notice">The language model did not answer (after <?= e($test['ms']) ?> ms). Reason: <?= e($test['reason']) ?> Check the mode, URL, model and key; provider replies are not shown here.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
    <section class="llm-right connector-card">
        <h2>Connection</h2>
        <?php if (($_GET['saved'] ?? '') === 'llm'): ?><p class="notice" role="status">LLM settings saved.</p><?php endif; ?>
        <?php if ($message !== ''): ?><p class="notice" role="alert" id="llm-error"><?= e($message) ?></p><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save">
            <div class="setting-row"><label for="mode">Reply mode</label><select id="mode" name="mode"<?= llm_field('mode', $errorField) ?>>
                <?php foreach (LLM_MODES as $value => $label): ?><option value="<?= e($value) ?>"<?= $form['mode'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
            <div class="setting-row"><label for="base_url">Base URL <small>(openai: optional; local: required)</small></label><input type="url" id="base_url" name="base_url" maxlength="300" value="<?= e($form['base_url']) ?>" placeholder="http://127.0.0.1:1234/v1"<?= llm_field('base_url', $errorField) ?>></div>
            <div class="setting-row"><label for="model">Model <small>(required except mock)</small></label><input type="text" id="model" name="model" maxlength="200" value="<?= e($form['model']) ?>"<?= llm_field('model', $errorField) ?>></div>
            <div class="setting-row"><label for="timeout_seconds">Timeout (seconds, 1-120)</label><input type="number" id="timeout_seconds" name="timeout_seconds" min="1" max="120" required value="<?= e($form['timeout_seconds']) ?>"<?= llm_field('timeout_seconds', $errorField) ?>></div>
            <div class="setting-row"><label for="max_tokens">Max tokens (16-1000)</label><input type="number" id="max_tokens" name="max_tokens" min="16" max="1000" required value="<?= e($form['max_tokens']) ?>"<?= llm_field('max_tokens', $errorField) ?>></div>
            <div class="setting-row"><label for="mock_delay_seconds">Mock delay (seconds, 0-10)</label><input type="number" id="mock_delay_seconds" name="mock_delay_seconds" min="0" max="10" required value="<?= e($form['mock_delay_seconds']) ?>"<?= llm_field('mock_delay_seconds', $errorField) ?>></div>
            <div class="setting-row"><span>API key</span><span><span class="chim-tag<?= $keySaved ? ' chim-badge-success' : '' ?>"><?= $keySaved ? 'Saved' : 'Not set' ?></span> <a href="api_keys.php">Change on API keys</a></span></div>
            <div class="preview-actions"><button type="submit">Save LLM settings</button><span>Saving never contacts the provider.</span></div>
        </form>
        <p class="help">openrouter uses https://openrouter.ai/api/v1; dwemerllm uses http://127.0.0.1:1234/v1 and never receives the key. See CONNECTORS.md.</p>
    </section>
</div>
<?php endif; ?>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
