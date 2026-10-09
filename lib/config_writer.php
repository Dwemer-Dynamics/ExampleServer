<?php
// Saves dashboard changes into the private config file. Used only by ui/ pages.
// This file only defines functions and constants, so requesting it over HTTP prints nothing.
//
// One save: take the lock file next to the config, read the latest config, apply the
// caller's change to a fixed list of sections, write a temporary PHP file in the same
// folder, give it the config's owner, group and mode, then rename it over the config.
// Anything that cannot be done exactly that way fails with a clear message and writes nothing.

// Only these top-level keys may change. token, database and unknown keys never change.
const CONFIG_EDITABLE_KEYS = ['history_limit', 'allowed_actions', 'llm', 'tts', 'stt', 'decision', 'memory', 'embeddings'];
const CONFIG_MAX_BYTES = 262144;

final class ConfigWriteError extends RuntimeException
{
}

function config_file_path(): string
{
    return getenv('EXAMPLE_AI_CONFIG') ?: dirname(__DIR__) . '/config/config.php';
}

function config_user_name(int $uid): string
{
    $info = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
    return is_array($info) ? $info['name'] : "uid $uid";
}

function config_group_name(int $gid): string
{
    $info = function_exists('posix_getgrgid') ? posix_getgrgid($gid) : false;
    return is_array($info) ? $info['name'] : "gid $gid";
}

// A new file in $dir ends up with group $gid if the process is in that group (it may chgrp),
// or if the folder is setgid with that group (new files inherit it without a chgrp).
function config_group_kept(string $dir, int $gid): bool
{
    if ($gid === posix_getegid() || in_array($gid, posix_getgroups(), true)) {
        return true;
    }
    $mode = fileperms($dir);
    return $mode !== false && ($mode & 02000) !== 0 && filegroup($dir) === $gid;
}

// Returns a list of problems that would stop a save. Empty means saving should work.
// Used by the save itself and shown on the Diagnostics page.
function config_write_problems(string $path): array
{
    clearstatcache();
    $dir = dirname($path);
    if (!function_exists('posix_geteuid')) {
        return ['The PHP posix extension is needed to check config ownership.'];
    }
    if (is_link($path) || is_link($dir)) {
        return ['The config path is a symbolic link. Saving only replaces a regular file.'];
    }
    if (!is_file($path)) {
        return ['The config file does not exist. Run the installer first.'];
    }
    $problems = [];
    if (!is_readable($path)) {
        $problems[] = 'The web server cannot read the config file.';
    }
    if (!is_writable($path)) {
        $problems[] = 'The web server cannot write the config file.';
    }
    if (!is_writable($dir) || !is_executable($dir)) {
        $problems[] = 'The web server cannot create files in the config folder (needed for the lock and the atomic replace).';
    }
    $lock = "$path.lock";
    if (is_link($lock) || (file_exists($lock) && !is_file($lock))) {
        $problems[] = 'The config lock file is not a regular file.';
    } elseif (is_file($lock) && (!is_readable($lock) || !is_writable($lock))) {
        $problems[] = 'The web server cannot read and write the config lock file.';
    }
    $uid = posix_geteuid();
    $owner = fileowner($path);
    if ($owner !== $uid && $uid !== 0) {
        $problems[] = 'The config is owned by ' . config_user_name($owner) . ', but the web server runs as '
            . config_user_name($uid) . '. An atomic replace would change the owner, so saving is refused.';
    }
    $group = filegroup($path);
    if ($uid !== 0 && !config_group_kept($dir, $group)) {
        $problems[] = 'The web server is not in the config group ' . config_group_name($group)
            . ' and the config folder does not pass that group to new files (setgid), so the group cannot be kept.';
    }
    return $problems;
}

// Loads the file freshly (not from a cached compile) and checks it is a usable config.
function config_read_fresh(string $path): array
{
    clearstatcache(true, $path);
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($path, true);
    }
    $size = filesize($path);
    if ($size === false || $size > CONFIG_MAX_BYTES) {
        throw new ConfigWriteError('The config file is missing or too large.');
    }
    try {
        $config = require $path;
    } catch (Throwable $error) {
        throw new ConfigWriteError('The config file could not be loaded. Nothing was saved.');
    }
    if (!is_array($config) || !is_string($config['token'] ?? null) || strlen($config['token']) < 16
        || str_contains($config['token'], 'CHANGE_ME')) {
        throw new ConfigWriteError('The config file is not a valid server config. Nothing was saved.');
    }
    return $config;
}

// Only plain values can be written back with var_export().
function config_is_plain(mixed $value): bool
{
    if (is_array($value)) {
        foreach ($value as $item) {
            if (!config_is_plain($item)) {
                return false;
            }
        }
        return true;
    }
    return $value === null || is_scalar($value);
}

// Creates a new, empty, owner-only temp file in $dir and returns [path, open handle].
// The name ends in .php so that, if the folder is inside the web root, a request for it runs
// it as PHP (a config only returns an array and prints nothing) instead of serving the
// secrets as plain text. 'x' refuses an existing name or symlink, so concurrent saves never share one.
function config_create_temp(string $dir): array
{
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $temp = $dir . '/.config-' . bin2hex(random_bytes(8)) . '.php';
        $old = umask(077);
        $handle = @fopen($temp, 'xb');
        umask($old);
        if ($handle !== false) {
            return [$temp, $handle];
        }
    }
    throw new ConfigWriteError('A temporary file could not be created in the config folder. Nothing was saved.');
}

// $change receives the latest config and returns the new one. Returns the saved config.
// Throws ConfigWriteError with a message that is safe to show (it never contains values).
function config_update(callable $change): array
{
    $path = config_file_path();
    $problems = config_write_problems($path);
    if ($problems) {
        throw new ConfigWriteError(implode(' ', $problems) . ' Nothing was saved.');
    }
    $dir = dirname($path);
    // The lock file stays in place; deleting it would let two saves lock different files.
    $lock = @fopen("$path.lock", 'c');
    if ($lock === false) {
        throw new ConfigWriteError('The config lock file could not be opened. Nothing was saved.');
    }
    // A lock this process owns stays usable by the config's group too (the folder's setgid
    // group is inherited). It is fixed in place, never replaced, so every save shares one lock.
    // When root saves, the lock gets the config's owner and group so the web server can open it.
    $lockStat = fstat($lock);
    if ($lockStat !== false && posix_geteuid() === 0 && $lockStat['uid'] !== fileowner($path)) {
        @chown("$path.lock", fileowner($path));
        @chgrp("$path.lock", filegroup($path));
    }
    if ($lockStat !== false && ($lockStat['uid'] === posix_geteuid() || posix_geteuid() === 0)
        && ($lockStat['mode'] & 0777) !== 0660) {
        @chmod("$path.lock", 0660);
    }
    $temp = null;
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new ConfigWriteError('The config lock could not be taken. Nothing was saved.');
        }
        // Read the latest file inside the lock, so other forms' saves are kept.
        $old = config_read_fresh($path);
        $new = $change($old);
        if (!is_array($new) || !config_is_plain($new)) {
            throw new ConfigWriteError('The new settings are not valid. Nothing was saved.');
        }
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            if (!in_array($key, CONFIG_EDITABLE_KEYS, true) && ($old[$key] ?? null) !== ($new[$key] ?? null)) {
                throw new ConfigWriteError('Only dashboard settings may change. Nothing was saved.');
            }
        }
        if ($new === $old) {
            return $old;
        }

        $stat = stat($path);
        $code = "<?php\n// Private server settings. Never commit this file. Rewritten by the dashboard;\n"
            . "// see config/config.example.php for the documented settings.\n\nreturn "
            . var_export($new, true) . ";\n";
        [$temp, $handle] = config_create_temp($dir);
        $written = fwrite($handle, $code);
        $synced = fflush($handle) && fsync($handle);
        fclose($handle);
        if ($written !== strlen($code) || !$synced) {
            throw new ConfigWriteError('The new config could not be written. Nothing was saved.');
        }
        // Keep the config's mode, group and owner exactly; never widen them. A group already
        // inherited from a setgid folder is left alone (a non-member may not chgrp to it).
        clearstatcache(true, $temp);
        if (!chmod($temp, $stat['mode'] & 0777)
            || (filegroup($temp) !== $stat['gid'] && !chgrp($temp, $stat['gid']))
            || (posix_geteuid() === 0 && !chown($temp, $stat['uid']))) {
            throw new ConfigWriteError('The config permissions could not be kept. Nothing was saved.');
        }
        clearstatcache(true, $temp);
        if (fileowner($temp) !== $stat['uid'] || filegroup($temp) !== $stat['gid'] || (fileperms($temp) & 0777) !== ($stat['mode'] & 0777)) {
            throw new ConfigWriteError('The config owner, group or mode would change. Nothing was saved.');
        }
        // Check the written file loads back to exactly the intended settings.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($temp, true);
        }
        if ((include $temp) !== $new) {
            throw new ConfigWriteError('The new config did not read back correctly. Nothing was saved.');
        }
        if (!rename($temp, $path)) {
            throw new ConfigWriteError('The config file could not be replaced. Nothing was saved.');
        }
        $temp = null;
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
        return $new;
    } finally {
        if ($temp !== null && is_file($temp)) {
            unlink($temp);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// Form helpers shared by the settings pages. Each returns null when the value is invalid.
function form_int(mixed $value, int $min, int $max): ?int
{
    if (!is_string($value) || !preg_match('/^-?\d{1,6}$/D', trim($value))) {
        return null;
    }
    $number = (int)trim($value);
    return $number >= $min && $number <= $max ? $number : null;
}

// An empty value is allowed; otherwise an http(s) URL without credentials or spaces.
function form_url(mixed $value): ?string
{
    $url = is_string($value) ? trim($value) : '';
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (strlen($url) > 300 || preg_match('/[\s\x00-\x1F\x7F]/', $url) || !is_array($parts)
        || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])
        || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    return $url;
}

// Model and voice names: printable, no spaces or quotes.
function form_name(mixed $value, int $max, bool $allowEmpty = true): ?string
{
    $name = is_string($value) ? trim($value) : '';
    if ($name === '') {
        return $allowEmpty ? '' : null;
    }
    return strlen($name) <= $max && preg_match('/^[A-Za-z0-9_.:\/@+-]+$/D', $name) ? $name : null;
}

// API keys: 1-512 printable characters without spaces. The value is never echoed back.
function form_secret(mixed $value): ?string
{
    $secret = is_string($value) ? trim($value) : '';
    return preg_match('/^[\x21-\x7E]{1,512}$/D', $secret) ? $secret : null;
}

function form_checked(string $name): bool
{
    return ($_POST[$name] ?? '') === '1';
}

// Shows a config save error, or redirects back to the page on success.
function save_and_redirect(callable $change, string $page, string $saved): string
{
    try {
        config_update($change);
    } catch (ConfigWriteError $error) {
        http_response_code(409);
        return $error->getMessage();
    } catch (Throwable $error) {
        error_log('example-ai dashboard: config save failed: ' . get_class($error));
        http_response_code(500);
        return 'The config could not be saved. Nothing was changed.';
    }
    header('Location: ' . $page . (str_contains($page, '?') ? '&' : '?') . 'saved=' . rawurlencode($saved), true, 303);
    exit;
}
