<?php
// Dispatch only fixed existing handlers; secrets stay on the server.
// turn/cancel/event: Conversation. speak/listen: Voice tests. decision: NPC selection test.
require __DIR__ . '/common.php';
const UI_OPERATIONS = ['turn', 'cancel', 'event', 'speak', 'listen', 'decision'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'method_not_allowed', 'Use POST.');
}
if (!check_csrf()) {
    fail(403, 'csrf', 'This form expired. Reload the page.');
}
$operation = $_GET['operation'] ?? '';
if (!in_array($operation, UI_OPERATIONS, true)) {
    fail(400, 'bad_request', 'Unknown operation.');
}
$config = ui_config();
if ($config === null) {
    fail(500, 'server_not_configured', 'The server is not configured.');
}
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $config['token'];
session_write_close(); // A slow request must not block another request's cancellation.
require __DIR__ . '/../' . $operation . '.php';
