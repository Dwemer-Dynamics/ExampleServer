<?php
// OPTIONAL VOICE: POST raw WAV audio (Content-Type: audio/wav) and get {"text": "..."} back.
// stt.provider 'parakeet' uses DwemerDistro Parakeet (port 8022, OpenAI-style
// /v1/audio/transcriptions: form fields "file" and "model"). 'fasterwhisper' uses the
// DwemerDistro faster-whisper service (port 9876, form field "audio_file").
// When STT is off, fails or hears nothing usable, the reply is a JSON error and the game
// should let the player type.
// Optional headers (protocol 1 additions): X-Request-Id (made here when missing) and
// X-Parent-Request-Id (the turn the transcript is for). After the reply, one connector-audit
// row is written with ids, status and timing only: never the audio or the transcript.

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/audit.php';

const MAX_UPLOAD_BYTES = 10000000;
const MAX_HEARD_CHARS = 1000;  // same limit as turn.php text

$started = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
$config = start_request('POST');
$requestId = trace_start($_SERVER['HTTP_X_REQUEST_ID'] ?? null);
$parentId = ($_SERVER['HTTP_X_PARENT_REQUEST_ID'] ?? '') !== ''
    ? require_id($_SERVER['HTTP_X_PARENT_REQUEST_ID'], 'X-Parent-Request-Id', 8) : null;
$stt = $config['stt'] ?? [];
// Configs from before stt.provider existed are faster-whisper.
$provider = $stt['provider'] ?? 'fasterwhisper';
$audit = ['kind' => 'stt', 'provider' => $provider, 'request_id' => $requestId, 'parent_request_id' => $parentId];
// The reply goes first; the audit row is written after it and can never change it.
$finish = function (int $status, array $data, array $row) use ($config, $started, $audit): never {
    send_json_then($status, $data, fn() => audit_record($config, $row + ['duration_ms' => event_duration_ms($started)] + $audit));
};
$failed = fn(int $status, string $code, string $message, string $auditStatus, ?string $detail = null) => $finish($status,
    ['ok' => false, 'error' => ['code' => $code, 'message' => $message]],
    ['status' => $auditStatus, 'error_code' => $code, 'detail' => $detail]);
if (empty($stt['enabled'])) {
    $failed(503, 'stt_disabled', 'Speech to text is turned off on the server.', 'disabled');
}

$wav = read_body(MAX_UPLOAD_BYTES);
if (!is_wav($wav)) {
    fail(400, 'bad_audio', 'The body must be WAV audio.');
}
$audio = new CURLStringFile($wav, 'speech.wav', 'audio/wav');
if ($provider === 'parakeet') {
    $form = ['file' => $audio, 'model' => (string)($stt['model'] ?? 'whisper-1')];
} elseif ($provider === 'fasterwhisper') {
    $form = [(string)($stt['form_field'] ?? 'audio_file') => $audio];
} else {
    app_log('unknown stt.provider');
    $failed(502, 'stt_unavailable', 'Speech to text failed. Type instead.', 'failed', 'unknown provider');
}

try {
    [$status, $response] = http_post((string)($stt['url'] ?? ''), [], $form, (int)($stt['timeout_seconds'] ?? 60), 100000);
} catch (Throwable $error) {
    app_log($error->getMessage());
    [$status, $response] = [0, ''];
}
$text = json_decode($response, true)['text'] ?? null;
if ($status !== 200 || !is_string($text) || !mb_check_encoding($text, 'UTF-8')) {
    app_log("STT failed with HTTP $status");
    $failed(502, 'stt_unavailable', 'Speech to text failed. Type instead.', 'failed', "provider HTTP $status");
}
$text = clean_line($text);
if ($text === '' || mb_strlen($text) > MAX_HEARD_CHARS) {
    app_log('STT text was empty or longer than ' . MAX_HEARD_CHARS . ' characters');
    $failed(502, 'stt_unavailable', 'No usable speech was recognised. Type instead.', 'failed', 'empty or too long');
}

$finish(200, ['ok' => true, 'text' => $text], ['status' => 'ok', 'detail' => 'WAV ' . strlen($wav) . ' bytes, ' . mb_strlen($text) . ' characters heard']);
