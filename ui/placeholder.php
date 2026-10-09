<?php
// Compatibility for old placeholder links. These tabs are now working pages.
$pages = ['llm' => 'llm.php', 'voice' => 'voice.php', 'diagnostics' => 'diagnostics.php'];
$tab = $_GET['tab'] ?? 'llm';
header('Location: ' . (is_string($tab) && isset($pages[$tab]) ? $pages[$tab] : './'), true, 302);
exit;
