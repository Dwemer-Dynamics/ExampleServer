<?php
// Writes a private client config.json for ExampleMod: this install's base URL and token.
// Usage (inside DwemerDistro WSL, as a user that can read config/config.php):
//   sudo -u dwemer php scripts/pair_client.php --out /home/dwemer/example-client.json
//       [--base-url http://127.0.0.1:19000/ExampleServer] [--session-id demo-save-1]
//
// - The file is new: an existing file or symbolic link at --out is never replaced.
// - It is created with mode 0600, outside the web root and this app folder, in a folder
//   owned by the user running this script that no one else can write to (and whose parent
//   folders only that user or root can change), so the path cannot be swapped meanwhile.
// - The token is written only into that file, never printed.
// - Without --base-url the URL is inferred for installs under /var/www/html (Apache port
//   19000 by default: CUSTOM_MODS_PORT in DwemerDistro), e.g. /var/www/html/custom-mods/my-project -> .../custom-mods/my-project.
//   Anywhere else, pass the address the game should use with --base-url.
// Copy the file to the PC that runs the client and save it next to example_mod.exe as
// config.json (git-ignored), then delete the copy in WSL if you no longer need it.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/app.php';

const PAIR_WEB_ROOT = '/var/www/html';
const PAIR_DEFAULT_ORIGIN = 'http://127.0.0.1:19000';

function pair_fail(string $message): never
{
    fwrite(STDERR, "pair_client: $message\n");
    exit(1);
}

// http(s) only, a host, an optional port and path; no user info, query, fragment,
// spaces or control characters. Returns it without a trailing slash.
function pair_base_url(string $url): string
{
    $parts = parse_url($url);
    if (strlen($url) > 300 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) || $parts === false
        || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === ''
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !preg_match('/^[A-Za-z0-9._~\/-]*$/D', $parts['path'] ?? '')) {
        pair_fail('--base-url must be a plain http:// or https:// address, e.g. http://127.0.0.1:19000/ExampleServer');
    }
    return rtrim($url, '/');
}

function pair_inferred_url(string $appDir): string
{
    $relative = str_starts_with($appDir, PAIR_WEB_ROOT . '/') ? substr($appDir, strlen(PAIR_WEB_ROOT) + 1) : '';
    foreach ($relative === '' ? [''] : explode('/', $relative) as $segment) {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $segment) || $segment === '.' || $segment === '..') {
            pair_fail('this app is not under ' . PAIR_WEB_ROOT . ', so its address is unknown. Pass --base-url.');
        }
    }
    return PAIR_DEFAULT_ORIGIN . '/' . $relative;
}

$options = ['--out' => null, '--base-url' => null, '--session-id' => 'demo-save-1'];
for ($i = 1; $i < $argc; $i++) {
    if (!array_key_exists($argv[$i], $options) || $i + 1 >= $argc) {
        pair_fail('usage: php scripts/pair_client.php --out <new file> [--base-url <url>] [--session-id <id>]');
    }
    $options[$argv[$i]] = $argv[++$i];
}
$out = $options['--out'];
if ($out === null || !str_starts_with($out, '/') || preg_match('/[\x00-\x1F\x7F]/', $out)
    || preg_match('#(^|/)\.\.?(/|$)#', $out)) {
    pair_fail('--out must be an absolute path to a new file, without . or .. parts.');
}
$sessionId = $options['--session-id'];
if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $sessionId)) {
    pair_fail('--session-id must be 1-64 characters of A-Z a-z 0-9 _ . : -');
}

$appDir = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$parent = realpath(dirname($out));
if ($parent === false || !is_dir($parent)) {
    pair_fail('the folder for --out does not exist.');
}
$target = $parent . '/' . basename($out);
// Both the literal and the resolved paths, so a symbolic link to the web root or app counts.
$roots = array_unique(array_filter([dirname(__DIR__), $appDir, PAIR_WEB_ROOT, realpath(PAIR_WEB_ROOT)]));
foreach ($roots as $inside) {
    if ($target === $inside || str_starts_with($target, $inside . '/')) {
        pair_fail("--out must be outside $inside, so the token is never served over HTTP.");
    }
}

// The folder must be ours and writable by no one else. Every folder above it must belong to
// us or root and be unwritable by others (or sticky, like /tmp), so nobody can swap it.
if (!function_exists('posix_geteuid')) {
    pair_fail('the PHP posix extension is needed to check folder ownership.');
}
$user = posix_geteuid();
clearstatcache();
for ($dir = $parent; ; $dir = dirname($dir)) {
    $info = @lstat($dir);
    $mode = $info === false ? 0 : $info['mode'];
    $owned = $info !== false && ($dir === $parent ? $info['uid'] === $user : in_array($info['uid'], [0, $user], true));
    $shared = ($mode & 0022) !== 0 && ($dir === $parent || ($mode & 01000) === 0);
    if (!$owned || $shared || ($mode & 0170000) !== 0040000) {
        pair_fail("$dir must be a folder owned by you" . ($dir === $parent ? '' : ' or root')
            . ' that other users cannot write to. Choose a private folder such as your home folder.');
    }
    if ($dir === '/') {
        break;
    }
}

$config = load_config();
$baseUrl = $options['--base-url'] !== null ? pair_base_url($options['--base-url']) : pair_inferred_url($appDir);
$client = [
    'server_url' => $baseUrl,
    'token' => $config['token'],
    'session_id' => $sessionId,
    'timeout_seconds' => 40,
    'voice_enabled' => false,
    'allowed_actions' => ['follow_player'],
];

// Refuse anything already there, including a dangling symbolic link (PHP's "x" mode alone
// can follow one). "x" then also fails if a file appears in between.
clearstatcache();
$old = umask(0077);
$file = is_link($target) || file_exists($target) ? false : @fopen($target, 'x');
umask($old);
if ($file === false) {
    pair_fail("$target already exists or cannot be created. Nothing was changed; choose a new file name.");
}
clearstatcache();
$created = @lstat($target);
$opened = fstat($file);
if (is_link($target) || $created === false || $opened['ino'] !== $created['ino'] || $opened['dev'] !== $created['dev']
    || ($opened['mode'] & 0777) !== 0600) {
    fclose($file);
    pair_fail("$target changed or is not mode 0600. Check that folder; nothing was written.");
}
$json = json_encode($client, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$written = fwrite($file, $json);
if ($written !== strlen($json) || !fflush($file)) {
    ftruncate($file, 0);
    fclose($file);
    pair_fail("$target could not be fully written. It was emptied; delete it and try again.");
}
fclose($file);

echo "Wrote $target (mode 0600) for $baseUrl.\n";
echo "It contains this server's token. Copy it privately to the client PC, save it next to\n";
echo "example_mod.exe as config.json, then run: example_mod.exe --health\n";
