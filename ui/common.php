<?php
// Shared dashboard setup. Nothing here starts a game turn or changes the database.
require_once __DIR__ . '/../lib/app.php';

$uiBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/ui/index.php'), '/');
if (basename($uiBase) === 'examples') {
    $uiBase = dirname($uiBase);
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
ini_set('display_errors', '0');
session_name('example_ai_dashboard');
session_start(['use_strict_mode' => true, 'cookie_httponly' => true,
    'cookie_samesite' => 'Strict', 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'cookie_path' => $uiBase . '/']);
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}
$_SESSION = ['csrf' => $_SESSION['csrf']];
$csrf = $_SESSION['csrf'];

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function check_csrf(): bool
{
    $given = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    return is_string($given) && hash_equals($_SESSION['csrf'], $given);
}

// Unlike API config loading, pages can show a friendly setup message instead of JSON.
function ui_config(): ?array
{
    $path = getenv('EXAMPLE_AI_CONFIG') ?: __DIR__ . '/../config/config.php';
    if (!is_readable($path)) {
        return null;
    }
    try {
        $config = require $path;
        if (is_array($config) && is_string($config['token'] ?? null)
            && strlen($config['token']) >= 16 && !str_contains($config['token'], 'CHANGE_ME')) {
            return $config;
        }
    } catch (Throwable $error) {
        error_log('example-ai dashboard: config could not be loaded');
    }
    return null;
}

// Pages need a nullable connection so a stopped database remains a readable UI state.
function ui_database(array $config): ?\PgSql\Connection
{
    $settings = $config['database'] ?? [];
    $parts = [];
    foreach (['host' => 'localhost', 'port' => 5432, 'name' => 'example_ai_mod', 'user' => 'dwemer', 'password' => ''] as $key => $default) {
        $name = $key === 'name' ? 'dbname' : $key;
        $parts[] = $name . "='" . addcslashes((string)($settings[$key] ?? $default), "'\\") . "'";
    }
    $connection = @pg_connect(implode(' ', $parts) . ' connect_timeout=5', PGSQL_CONNECT_FORCE_NEW);
    return $connection === false ? null : $connection;
}
