<?php
// Server settings: history length and which known actions the server may return.
// Saves into the private config through lib/config_writer.php. Secrets are never shown.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/config_writer.php';

// Actions with an explicit handler in the example client. No other action can be added here.
const KNOWN_ACTIONS = ['follow_player' => 'Follow the player'];

$config = ui_config();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $config !== null) {
    $limit = form_int($_POST['history_limit'] ?? null, 0, 50);
    $chosen = is_array($_POST['actions'] ?? null) ? $_POST['actions'] : [];
    if (!check_csrf()) {
        http_response_code(403);
        $message = 'This form expired. Reload and try again.';
    } elseif ($limit === null) {
        http_response_code(400);
        $message = 'History lines must be a whole number from 0 to 50.';
    } elseif (array_filter($chosen, fn($name) => !is_string($name) || !isset(KNOWN_ACTIONS[$name]))) {
        http_response_code(400);
        $message = 'Only known actions can be allowed.';
    } else {
        session_write_close();
        $message = save_and_redirect(function (array $latest) use ($limit, $chosen): array {
            // Actions added by hand that the dashboard does not know stay as they are.
            $current = is_array($latest['allowed_actions'] ?? null) ? $latest['allowed_actions'] : ['follow_player'];
            $kept = array_values(array_filter($current, fn($name) => !isset(KNOWN_ACTIONS[$name])));
            $known = array_values(array_intersect(array_keys(KNOWN_ACTIONS), $chosen));
            $latest['history_limit'] = $limit;
            $latest['allowed_actions'] = array_merge($known, $kept);
            return $latest;
        }, 'settings.php', 'settings');
    }
}
$allowed = is_array($config['allowed_actions'] ?? null) ? $config['allowed_actions'] : ['follow_player'];
$other = array_filter($allowed, fn($name) => !isset(KNOWN_ACTIONS[$name]));
$title = 'Settings';
$active = 'settings';
require __DIR__ . '/tmpl/header.php';
?>
<section class="chim-panel">
    <p>Saved to the private server config. Changes apply to the next request. Model, voice and key settings have their own pages; secrets are never shown here.</p>
    <?php if (($_GET['saved'] ?? '') === 'settings'): ?><p class="notice" role="status">Settings saved.</p><?php endif; ?>
    <?php if ($message !== ''): ?><p class="notice" role="alert" id="settings-error"><?= e($message) ?></p><?php endif; ?>
    <?php if ($config === null): ?>
    <p class="notice">Server configuration is missing or invalid.</p>
    <?php else: ?>
    <form method="post" class="narrow">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label for="history-limit">History lines per NPC (0-50)</label>
        <input type="number" id="history-limit" name="history_limit" min="0" max="50" step="1" required value="<?= e(max(0, min(50, (int)($config['history_limit'] ?? 10)))) ?>">
        <fieldset>
            <legend>Allowed actions</legend>
            <?php foreach (KNOWN_ACTIONS as $name => $label): ?>
            <label><input type="checkbox" name="actions[]" value="<?= e($name) ?>"<?= in_array($name, $allowed, true) ? ' checked' : '' ?>> <?= e($label) ?> <code><?= e($name) ?></code></label>
            <?php endforeach; ?>
            <p class="help">Only actions the example client has a handler for are listed. Unchecked actions show up as rejected actions.<?= $other ? ' Also allowed in the config file and kept unchanged: ' . e(implode(', ', $other)) . '.' : '' ?></p>
        </fieldset>
        <button type="submit">Save settings</button>
    </form>
    <h2>Overview</h2>
    <dl>
        <dt>Reply mode</dt><dd><?= e($config['llm']['mode'] ?? 'mock') ?> (<a href="llm.php">LLM</a>)</dd>
        <dt>Model</dt><dd><?= e(($config['llm']['model'] ?? '') ?: 'Not set') ?></dd>
        <dt>Text to speech</dt><dd><?= !empty($config['tts']['enabled']) ? 'Enabled' : 'Disabled' ?> (<a href="voice.php">Voice</a>)</dd>
        <dt>Speech to text</dt><dd><?= !empty($config['stt']['enabled']) ? 'Enabled' : 'Disabled' ?></dd>
        <dt>NPC selection</dt><dd><?= !empty($config['decision']['enabled']) ? 'Enabled' : 'Disabled' ?> (<a href="decision_settings.php">NPC selection</a>)</dd>
    </dl>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
