<?php
require __DIR__ . '/../common.php';
$title = 'Example pages';
$active = 'examples/index';
require __DIR__ . '/../tmpl/header.php';
?>
<section class="chim-panel">
    <p>Starter pages, not working tools. Copy one and replace its sample content when extending your server. Each uses the same shared navigation and stylesheet.</p>
    <div class="links"><a href="blank.php">Blank page</a><a href="form.php">Form example</a><a href="table.php">Table example</a><a href="../profiles.php">Profiles (working page)</a></div>
</section>
<?php require __DIR__ . '/../tmpl/footer.php'; ?>
