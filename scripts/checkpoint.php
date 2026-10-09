<?php
// Saves, lists, restores or deletes named copies of one session's conversation lines.
// Usage:
//   php scripts/checkpoint.php save    <session_id> <name>
//   php scripts/checkpoint.php list    <session_id>
//   php scripts/checkpoint.php restore <session_id> <name>
//   php scripts/checkpoint.php delete  <session_id> <name>
//
// Restore replaces only that session's lines (ex_turns). Bios, knowledge, config and
// other sessions are untouched. It also makes every in-flight turn of the session stale,
// so a slow old reply cannot be saved on top of the restored history.
// This never reads or changes game saves; matching a checkpoint to a save is manual.
// The dashboard's Checkpoints page uses the same functions from lib/checkpoint.php.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/app.php';
require __DIR__ . '/../lib/checkpoint.php';

function usage(): never
{
    fwrite(STDERR, "Usage: php scripts/checkpoint.php save|list|restore|delete <session_id> [name]\n");
    exit(2);
}

function cli_id(?string $value): string
{
    if ($value === null || !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $value)) {
        fwrite(STDERR, "Ids and names must be 1-64 characters of A-Z a-z 0-9 _ . : -\n");
        exit(2);
    }
    return $value;
}

$command = $argv[1] ?? '';
if (!in_array($command, ['save', 'list', 'restore', 'delete'], true)) {
    usage();
}
$sessionId = cli_id($argv[2] ?? null);
$name = $command === 'list' ? '' : cli_id($argv[3] ?? null);
$config = load_config();
$db = db_connect($config);
require_schema($db);

if ($command === 'list') {
    $result = checkpoint_list($db, $sessionId);
    if ($result['message'] !== '') {
        echo $result['message'], "\n";
    }
    foreach ($result['rows'] as $row) {
        echo "{$row['name']}\t{$row['turn_count']} lines\t{$row['created_at']}\n";
    }
    exit(0);
}

$result = match ($command) {
    'save' => checkpoint_save($db, $sessionId, $name),
    'restore' => checkpoint_restore($db, $sessionId, $name, $config),
    'delete' => checkpoint_delete($db, $sessionId, $name),
};
if (!$result['ok']) {
    fwrite(STDERR, $result['message'] . "\n");
    exit(1);
}
echo $result['message'], "\n";
