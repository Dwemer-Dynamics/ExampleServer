<?php
require __DIR__ . '/common.php';
$config = ui_config();
$ready = false;
$status = 'Server configuration is missing or invalid.';
if ($config !== null) {
    $db = ui_database($config);
    $status = 'Database unavailable. Check PostgreSQL and your private settings.';
    if ($db !== null) {
        $problem = schema_problem($db);
        $ready = $problem === null;
        $status = $ready ? 'Server is ready.' : $problem[1];
    }
}
// Settings only: optional services are never contacted from Home.
$services = [];
if ($config !== null) {
    $mode = (string)($config['llm']['mode'] ?? 'mock');
    if ($mode === 'mock') {
        $services['LLM'] = ['Ready', 'mock mode, predictable replies', 'conversation.php'];
    } elseif ((string)($config['llm']['model'] ?? '') === '') {
        $services['LLM'] = ['Not configured', "$mode mode, model missing", 'llm.php'];
    } else {
        $services['LLM'] = ['Not tested', "$mode mode", 'conversation.php'];
    }
    $services['Voice'] = !empty($config['tts']['enabled']) || !empty($config['stt']['enabled'])
        ? ['Not tested', 'enabled', 'voice.php'] : ['Disabled', 'optional, text only', 'voice.php'];
    $services['NPC selection'] = !empty($config['decision']['enabled'])
        ? ['Not tested', 'enabled', 'decision_settings.php'] : ['Disabled', 'optional, baseline NPC', 'decision_settings.php'];
    $services['Memory'] = !empty($config['memory']['enabled'])
        ? ['Enabled', 'summary and diary in turns' . (!empty($config['embeddings']['enabled']) ? ', vector search on' : ''), 'memory_settings.php']
        : ['Disabled', 'optional' . (!empty($config['embeddings']['enabled']) ? ', vector search on' : ''), 'memory_settings.php'];
}
$title = 'Home';
$active = 'index';
require __DIR__ . '/tmpl/header.php';
?>
<div class="dashboard-container">
    <section class="widget">
        <div class="widget-header"><h2>Server status</h2><a href="index.php">Refresh</a></div>
        <div class="widget-content"><strong class="status-label"><?= $ready ? 'Ready' : 'Setup needed' ?></strong><p><?= e($status) ?></p>
        <p class="help">Server version <?= e(SERVER_VERSION) ?> (release, lib/version.php). Protocol <?= e(PROTOCOL_VERSION) ?>, schema <?= e(SCHEMA_VERSION) ?>.</p>
        <?php if ($config !== null): ?><dl><?php foreach ($services as $name => [$state, $detail, $page]): ?><dt><?= e($name) ?></dt><dd><?= e($state) ?> (<?= e($detail) ?>) <a href="<?= e($page) ?>" aria-label="Open <?= e($name) ?> page">Open</a></dd><?php endforeach; ?><dt>History</dt><dd>Bounded per session and NPC</dd></dl><?php endif; ?>
        <p class="help">Pair the console client in one private step: in this server's folder run <code>sudo -u dwemer php scripts/pair_client.php --out /home/dwemer/example-client.json</code>, then copy that file next to <code>example_mod.exe</code> as <code>config.json</code>. See SETUP.md. The token is never shown on this page.</p></div>
    </section>
    <section class="widget">
        <div class="widget-header"><h2>Conversation</h2></div>
        <div class="widget-content"><p>Working tools: send a test turn through the existing API, inspect retained conversation lines, or review a session's memory.</p><div class="links"><a class="button" href="conversation.php">Test conversation</a><a href="history.php">History</a><a href="checkpoints.php">Checkpoints</a><a href="memory.php">Memory</a></div></div>
    </section>
    <section class="widget">
        <div class="widget-header"><h2>Configuration</h2></div>
        <div class="widget-content"><p>Working settings pages save to the private server config: Server settings, LLM, Voice, NPC selection, Memory settings and API keys. NPC bios and Profiles save to the database.</p><div class="links"><a href="settings.php">Server settings</a><a href="llm.php">LLM</a><a href="voice.php">Voice</a><a href="memory_settings.php">Memory settings</a><a href="api_keys.php">API keys</a><a href="npc_bios.php">NPC bios</a><a href="profiles.php">Profiles</a></div></div>
    </section>
    <section class="widget">
        <div class="widget-header"><h2>Control Panel</h2></div>
        <div class="widget-content"><p>Logs, Diagnostics and Backups are working tools. Logs lists finished turns, cancels and restores, with any untrusted client report, and a separate view of voice, NPC selection, embedding and memory calls. Example pages are starters to copy and save nothing.</p><div class="links"><a href="logs.php">Logs</a><a href="connector_calls.php">Connector calls</a><a href="diagnostics.php">Diagnostics</a><a href="backups.php">Backups</a><a href="examples/">Example pages</a></div></div>
    </section>
</div>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
