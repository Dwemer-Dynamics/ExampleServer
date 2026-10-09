<?php
$sections = require __DIR__ . '/../navigation.php';
$activeSection = 'home';
foreach ($sections as $key => $section) {
    foreach ($section['groups'] as $tabs) {
        if (isset($tabs[$active])) {
            $activeSection = $key;
        }
    }
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · ExampleServer</title>
    <link rel="stylesheet" href="<?= e($uiBase) ?>/css/style_new.css">
    <link rel="stylesheet" href="<?= e($uiBase) ?>/css/navbar.css">
    <link rel="stylesheet" href="<?= e($uiBase) ?>/css/chim-theme.css">
    <link rel="stylesheet" href="<?= e($uiBase) ?>/css/hub-navigation.css">
    <link rel="stylesheet" href="<?= e($uiBase) ?>/css/style.css">
    <script src="<?= e($uiBase) ?>/navbar.js" defer></script>
</head>
<body<?= $activeSection !== 'home' ? ' class="hub-page"' : '' ?>>
<a class="skip" href="#content">Skip to content</a>
<?php require __DIR__ . '/navbar.php'; ?>
<main class="<?= $activeSection === 'home' ? 'container' : 'hub-container' ?>" id="content">
<?php if ($activeSection !== 'home') require __DIR__ . '/section_navigation.php'; ?>
<div class="page-header chim-page-head"><h1 class="chim-page-head-title"><?= e($title) ?></h1></div>
