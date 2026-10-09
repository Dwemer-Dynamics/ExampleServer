<?php
// Shared CLI-only config validation for installer and backup database selection.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/app.php';
$config = load_config();
$name = $config['database']['name'] ?? null;
if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name)) {
    fwrite(STDERR, "Invalid database.name: use 1-63 lowercase letters, digits or underscores, starting with a letter.\n");
    exit(1);
}
echo $name;
