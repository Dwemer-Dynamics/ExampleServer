<?php
// Optional voice settings (tts and stt sections of the private config) and explicit tests.
// Tests go through api.php to the existing speak.php / listen.php handlers, which add their
// own size and time limits; the server token is added in PHP, never in this page.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/config_writer.php';

const TTS_PROVIDERS = ['pockettts' => 'PocketTTS (DwemerDistro)', 'openai' => 'OpenAI-compatible /v1/audio/speech (WAV)'];
const STT_PROVIDERS = ['parakeet' => 'Parakeet (DwemerDistro)', 'fasterwhisper' => 'faster-whisper (DwemerDistro)'];
// What speak.php sends to PocketTTS when tts.model / tts.voice are not set.
const POCKETTTS_DEFAULTS = ['model' => 'pocket-tts', 'voice' => 'alba'];
const STT_DEFAULT_URLS = [
    'parakeet' => 'http://127.0.0.1:8022/v1/audio/transcriptions',
    'fasterwhisper' => 'http://127.0.0.1:9876/api/v0/transcribe',
];

$config = ui_config();
$tts = is_array($config['tts'] ?? null) ? $config['tts'] : [];
$stt = is_array($config['stt'] ?? null) ? $config['stt'] : [];
// No stt section yet: suggest Parakeet. A saved section without a provider is faster-whisper,
// the same as listen.php.
$sttProvider = 'parakeet';
$sttUrl = STT_DEFAULT_URLS['parakeet'];
if ($stt !== []) {
    $sttProvider = (string)($stt['provider'] ?? 'fasterwhisper');
    $sttUrl = (string)($stt['url'] ?? '');
}
$form = [
    'tts_enabled' => !empty($tts['enabled']),
    'tts_provider' => (string)($tts['provider'] ?? 'pockettts'),
    'tts_url' => (string)($tts['url'] ?? ''),
    'tts_model' => (string)($tts['model'] ?? ''),
    'tts_voice' => (string)($tts['voice'] ?? ''),
    'tts_timeout' => (string)($tts['timeout_seconds'] ?? 30),
    'stt_enabled' => !empty($stt['enabled']),
    'stt_provider' => $sttProvider,
    'stt_url' => $sttUrl,
    'stt_model' => (string)($stt['model'] ?? 'whisper-1'),
    'stt_field' => (string)($stt['form_field'] ?? 'audio_file'),
    'stt_timeout' => (string)($stt['timeout_seconds'] ?? 60),
];
// New or older configs without a PocketTTS model or voice: show what speak.php would use.
if ($form['tts_provider'] === 'pockettts') {
    $form['tts_model'] = $form['tts_model'] !== '' ? $form['tts_model'] : POCKETTTS_DEFAULTS['model'];
    $form['tts_voice'] = $form['tts_voice'] !== '' ? $form['tts_voice'] : POCKETTTS_DEFAULTS['voice'];
}
$message = '';
$errorField = '';
$action = $_POST['action'] ?? '';

function post_text(string $key): string
{
    return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $config !== null) {
    if (!check_csrf()) {
        http_response_code(403);
        $message = 'This form expired. Reload and try again.';
    } elseif ($action === 'save_tts') {
        $form = array_merge($form, ['tts_enabled' => form_checked('tts_enabled'), 'tts_provider' => post_text('tts_provider'),
            'tts_url' => post_text('tts_url'), 'tts_model' => post_text('tts_model'), 'tts_voice' => post_text('tts_voice'),
            'tts_timeout' => post_text('tts_timeout')]);
        // PocketTTS needs both; a blank field saves the documented default, never ''.
        if ($form['tts_provider'] === 'pockettts') {
            $form['tts_model'] = $form['tts_model'] !== '' ? $form['tts_model'] : POCKETTTS_DEFAULTS['model'];
            $form['tts_voice'] = $form['tts_voice'] !== '' ? $form['tts_voice'] : POCKETTTS_DEFAULTS['voice'];
        }
        $values = [
            'enabled' => $form['tts_enabled'],
            'provider' => isset(TTS_PROVIDERS[$form['tts_provider']]) ? $form['tts_provider'] : null,
            'url' => form_url($form['tts_url']),
            'model' => form_name($form['tts_model'], 200),
            'voice' => form_name($form['tts_voice'], 64),
            'timeout_seconds' => form_int($form['tts_timeout'], 1, 120),
        ];
        if ($values['provider'] === null) {
            [$errorField, $message] = ['tts_provider', 'Choose a TTS provider from the list.'];
        } elseif ($values['url'] === null || ($values['enabled'] && $values['url'] === '')) {
            [$errorField, $message] = ['tts_url', 'TTS URL must be an http(s) URL (required when enabled).'];
        } elseif ($values['model'] === null || ($values['provider'] === 'openai' && $values['model'] === '')) {
            [$errorField, $message] = ['tts_model', 'Model must be letters, digits and _ . : / @ + - (required for the openai provider).'];
        } elseif ($values['voice'] === null) {
            [$errorField, $message] = ['tts_voice', 'Voice must be at most 64 characters of letters, digits and _ . : / @ + -'];
        } elseif ($values['timeout_seconds'] === null) {
            [$errorField, $message] = ['tts_timeout', 'Timeout must be 1-120 seconds.'];
        } else {
            session_write_close();
            $message = save_and_redirect(function (array $latest) use ($values): array {
                // tts.api_key and unknown keys stay as they are.
                $latest['tts'] = $values + (is_array($latest['tts'] ?? null) ? $latest['tts'] : []);
                return $latest;
            }, 'voice.php', 'tts');
        }
    } elseif ($action === 'save_stt') {
        $form = array_merge($form, ['stt_enabled' => form_checked('stt_enabled'), 'stt_provider' => post_text('stt_provider'),
            'stt_url' => post_text('stt_url'), 'stt_model' => post_text('stt_model'), 'stt_field' => post_text('stt_field'),
            'stt_timeout' => post_text('stt_timeout')]);
        $provider = isset(STT_PROVIDERS[$form['stt_provider']]) ? $form['stt_provider'] : null;
        // Switching provider without editing the URL (no JavaScript): use the new default URL.
        if ($provider !== null && in_array($form['stt_url'], STT_DEFAULT_URLS, true)) {
            $form['stt_url'] = STT_DEFAULT_URLS[$provider];
        }
        $values = [
            'enabled' => $form['stt_enabled'],
            'provider' => $provider,
            'url' => form_url($form['stt_url']),
            'timeout_seconds' => form_int($form['stt_timeout'], 1, 120),
        ];
        // Each provider has its own extra field; the other one is removed when the provider changes.
        $staleKey = 'model';
        if ($provider === 'parakeet') {
            $values['model'] = form_name($form['stt_model'], 200, false);
            $staleKey = 'form_field';
        } else {
            $values['form_field'] = preg_match('/^[A-Za-z0-9_]{1,64}$/D', $form['stt_field']) ? $form['stt_field'] : null;
        }
        if ($values['provider'] === null) {
            [$errorField, $message] = ['stt_provider', 'Choose an STT provider from the list.'];
        } elseif ($values['url'] === null || ($values['enabled'] && $values['url'] === '')) {
            [$errorField, $message] = ['stt_url', 'STT URL must be an http(s) URL (required when enabled).'];
        } elseif (array_key_exists('model', $values) && $values['model'] === null) {
            [$errorField, $message] = ['stt_model', 'Model is required: letters, digits and _ . : / @ + - (Parakeet accepts whisper-1).'];
        } elseif (array_key_exists('form_field', $values) && $values['form_field'] === null) {
            [$errorField, $message] = ['stt_field', 'Form field must be 1-64 letters, digits or underscores.'];
        } elseif ($values['timeout_seconds'] === null) {
            [$errorField, $message] = ['stt_timeout', 'Timeout must be 1-120 seconds.'];
        } else {
            session_write_close();
            $message = save_and_redirect(function (array $latest) use ($values, $staleKey): array {
                $previous = is_array($latest['stt'] ?? null) ? $latest['stt'] : [];
                $latest['stt'] = $values + $previous;
                if (($previous['provider'] ?? 'fasterwhisper') !== $values['provider']) {
                    unset($latest['stt'][$staleKey]);
                }
                return $latest;
            }, 'voice.php', 'stt');
        }
    } else {
        $message = 'Unknown form action.';
    }
    if ($message !== '' && http_response_code() === 200) {
        http_response_code(400);
    }
}

function voice_field(string $id, string $errorField): string
{
    return $id === $errorField ? ' aria-invalid="true" aria-describedby="voice-error"' : '';
}

$saved = ['tts' => 'Text to speech settings saved.', 'stt' => 'Speech to text settings saved.'][$_GET['saved'] ?? ''] ?? '';
$title = 'Voice';
$active = 'voice';
require __DIR__ . '/tmpl/header.php';
?>
<p class="chim-panel-intro">Optional voice, off by default. The game always gets text first. Saved to the private server config; the TTS key is set on <a href="api_keys.php">API keys</a>.</p>
<?php if ($config === null): ?>
<section class="chim-panel"><p class="notice" role="alert">Server configuration is missing or invalid.</p></section>
<?php else: ?>
<?php if ($saved !== ''): ?><p class="notice" role="status"><?= e($saved) ?></p><?php endif; ?>
<?php if ($message !== ''): ?><p class="notice" role="alert" id="voice-error"><?= e($message) ?></p><?php endif; ?>
<div class="llm-layout voice-layout">
    <section class="connector-card">
        <h2>Text to speech</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save_tts">
            <div class="setting-row"><span>Status</span><label><input type="checkbox" name="tts_enabled" value="1"<?= $form['tts_enabled'] ? ' checked' : '' ?>> Enabled</label></div>
            <div class="setting-row"><label for="tts_provider">Provider</label><select id="tts_provider" name="tts_provider"<?= voice_field('tts_provider', $errorField) ?>>
                <?php foreach (TTS_PROVIDERS as $value => $label): ?><option value="<?= e($value) ?>"<?= $form['tts_provider'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
            <div class="setting-row"><label for="tts_url">URL</label><input type="url" id="tts_url" name="tts_url" maxlength="300" value="<?= e($form['tts_url']) ?>" placeholder="http://127.0.0.1:8086/v1/audio/speech"<?= voice_field('tts_url', $errorField) ?>></div>
            <div class="setting-row"><label for="tts_model">Model <small>(PocketTTS: blank saves pocket-tts; openai: required)</small></label><input type="text" id="tts_model" name="tts_model" maxlength="200" value="<?= e($form['tts_model']) ?>"<?= voice_field('tts_model', $errorField) ?>></div>
            <div class="setting-row"><label for="tts_voice">Voice <small>(PocketTTS: blank saves alba)</small></label><input type="text" id="tts_voice" name="tts_voice" maxlength="64" value="<?= e($form['tts_voice']) ?>"<?= voice_field('tts_voice', $errorField) ?>></div>
            <div class="setting-row"><label for="tts_timeout">Timeout (seconds, 1-120)</label><input type="number" id="tts_timeout" name="tts_timeout" min="1" max="120" required value="<?= e($form['tts_timeout']) ?>"<?= voice_field('tts_timeout', $errorField) ?>></div>
            <div class="preview-actions"><button type="submit">Save text to speech</button></div>
        </form>
        <form id="tts-test" class="preview-output">
            <h3>Test speech</h3>
            <label for="tts-text">Text to speak (1-1000 characters)</label>
            <textarea id="tts-text" rows="2" maxlength="1000" required>Hello traveler, this is a voice test.</textarea>
            <label for="tts-npc">NPC ID <small>(optional: uses that NPC's profile voice, if it has one)</small></label>
            <input type="text" id="tts-npc" maxlength="64" pattern="[A-Za-z0-9_.:\-]{1,64}">
            <div class="preview-actions"><button type="submit" id="tts-send">Speak</button><span id="tts-status" role="status">Uses the saved settings.</span></div>
            <audio id="tts-audio" controls hidden></audio>
        </form>
    </section>
    <section class="connector-card">
        <h2>Speech to text</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save_stt">
            <div class="setting-row"><span>Status</span><label><input type="checkbox" name="stt_enabled" value="1"<?= $form['stt_enabled'] ? ' checked' : '' ?>> Enabled</label></div>
            <p class="provider-subtext">Start Parakeet or faster-whisper from the DwemerDistro launcher first, then tick Enabled.</p>
            <div class="setting-row"><label for="stt_provider">Provider</label><select id="stt_provider" name="stt_provider"<?= voice_field('stt_provider', $errorField) ?>>
                <?php foreach (STT_PROVIDERS as $value => $label): ?><option value="<?= e($value) ?>"<?= $form['stt_provider'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
            <div class="setting-row"><label for="stt_url">URL</label><input type="url" id="stt_url" name="stt_url" maxlength="300" value="<?= e($form['stt_url']) ?>" placeholder="<?= e(STT_DEFAULT_URLS[$form['stt_provider']] ?? STT_DEFAULT_URLS['parakeet']) ?>"<?= voice_field('stt_url', $errorField) ?>></div>
            <div class="setting-row" id="stt-model-row"<?= $form['stt_provider'] === 'fasterwhisper' ? ' hidden' : '' ?>><label for="stt_model">Model</label><input type="text" id="stt_model" name="stt_model" maxlength="200" value="<?= e($form['stt_model']) ?>"<?= voice_field('stt_model', $errorField) ?>></div>
            <div class="setting-row" id="stt-field-row"<?= $form['stt_provider'] === 'fasterwhisper' ? '' : ' hidden' ?>><label for="stt_field">Upload form field</label><input type="text" id="stt_field" name="stt_field" maxlength="64" value="<?= e($form['stt_field']) ?>"<?= voice_field('stt_field', $errorField) ?>></div>
            <div class="setting-row"><label for="stt_timeout">Timeout (seconds, 1-120)</label><input type="number" id="stt_timeout" name="stt_timeout" min="1" max="120" required value="<?= e($form['stt_timeout']) ?>"<?= voice_field('stt_timeout', $errorField) ?>></div>
            <div class="preview-actions"><button type="submit">Save speech to text</button></div>
        </form>
        <form id="stt-test" class="preview-output">
            <h3>Test transcription</h3>
            <label for="stt-file">WAV file (at most 10 MB)</label>
            <input type="file" id="stt-file" accept=".wav,audio/wav,audio/x-wav" required>
            <div class="preview-actions"><button type="submit" id="stt-send">Transcribe</button><span id="stt-status" role="status">Uses the saved settings.</span></div>
            <label for="stt-text">Transcript (edit or type here if recognition fails)</label>
            <textarea id="stt-text" rows="3" maxlength="1000"></textarea>
        </form>
    </section>
</div>
<input type="hidden" id="csrf" value="<?= e($csrf) ?>">
<script>
// Show only the selected STT provider's field and move an untouched default URL along with it.
const sttDefaultUrls = <?= json_encode(STT_DEFAULT_URLS, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
document.getElementById('stt_provider').addEventListener('change', event => {
    const provider = event.target.value;
    const url = document.getElementById('stt_url');
    if (url.value === '' || Object.values(sttDefaultUrls).includes(url.value)) url.value = sttDefaultUrls[provider] || '';
    url.placeholder = sttDefaultUrls[provider] || '';
    document.getElementById('stt-model-row').hidden = provider === 'fasterwhisper';
    document.getElementById('stt-field-row').hidden = provider !== 'fasterwhisper';
});
// Both tests send ordinary input and the CSRF token; PHP adds the server token.
async function voiceRequest(operation, body, contentType) {
    const response = await fetch('api.php?operation=' + operation, {
        method: 'POST', headers: {'Content-Type': contentType, 'X-CSRF-Token': document.getElementById('csrf').value}, body
    });
    // The server names every voice request (X-Request-Id); it is shown so the call can be found
    // in Logs > Connector calls, on success and on failure.
    const id = response.headers.get('X-Request-Id') || 'not reported';
    if (response.ok && (response.headers.get('Content-Type') || '').startsWith('audio/')) {
        return {blob: await response.blob(), id, voice: response.headers.get('X-Voice-Source') || 'config'};
    }
    let result = null;
    try { result = await response.json(); } catch (error) { result = null; }
    if (!response.ok || !result || !result.ok) throw new Error((result?.error?.message || 'Request failed (HTTP ' + response.status + ').') + ' Request ' + id + '.');
    return result;
}
let audioUrl = '';
document.getElementById('tts-test').addEventListener('submit', async event => {
    event.preventDefault();
    const send = document.getElementById('tts-send');
    const status = document.getElementById('tts-status');
    const audio = document.getElementById('tts-audio');
    send.disabled = true;
    status.textContent = 'Generating speech…';
    try {
        const body = {protocol: 1, text: document.getElementById('tts-text').value};
        const npc = document.getElementById('tts-npc').value.trim();
        if (npc) body.npc_id = npc;
        const result = await voiceRequest('speak', JSON.stringify(body), 'application/json');
        const blob = result.blob;
        if (!(blob instanceof Blob)) throw new Error('No audio was returned.');
        if (audioUrl) URL.revokeObjectURL(audioUrl);
        audioUrl = URL.createObjectURL(blob);
        audio.src = audioUrl;
        audio.hidden = false;
        status.textContent = 'Audio received (' + Math.round(blob.size / 1024) + ' KB, voice from ' + result.voice + ', request ' + result.id + '). Press play.';
    } catch (error) {
        audio.hidden = true;
        status.textContent = error.message;
    } finally { send.disabled = false; }
});
document.getElementById('stt-test').addEventListener('submit', async event => {
    event.preventDefault();
    const send = document.getElementById('stt-send');
    const status = document.getElementById('stt-status');
    const file = document.getElementById('stt-file').files[0];
    if (!file) { status.textContent = 'Choose a WAV file first.'; return; }
    if (file.size > 10000000) { status.textContent = 'The file is larger than 10 MB. Type instead.'; return; }
    send.disabled = true;
    status.textContent = 'Transcribing…';
    try {
        const result = await voiceRequest('listen', file, 'audio/wav');
        document.getElementById('stt-text').value = result.text;
        status.textContent = 'Transcript received (request ' + result.request_id + '). You can edit it.';
    } catch (error) {
        status.textContent = error.message;
        document.getElementById('stt-text').focus();
    } finally { send.disabled = false; }
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
