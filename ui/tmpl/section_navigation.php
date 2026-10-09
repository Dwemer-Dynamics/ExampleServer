<?php // Grouped link tabs adapted from HerikaServer's hub/Roleplay navigation. ?>
<nav class="config-navigation" aria-label="<?= e($sections[$activeSection]['label']) ?> pages">
    <div class="tab-groups">
        <?php foreach ($sections[$activeSection]['groups'] as $label => $tabs): ?>
        <section class="tab-group<?= isset($tabs[$active]) ? ' active' : '' ?>">
            <div class="tab-group-label"><?= e($label) ?></div>
            <div class="tab-buttons">
                <?php foreach ($tabs as $key => [$name, $href]): ?>
                <a class="tab-button<?= $active === $key ? ' active' : '' ?>" href="<?= e($uiBase . '/' . $href) ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>><?= e($name) ?></a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
</nav>
