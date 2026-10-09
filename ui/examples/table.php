<?php
require __DIR__ . '/../common.php';
$rows = [['Guide', 'Market Square'], ['Traveler', 'Town Gate']]; // Sample data, not game state.
$title = 'Table example';
$active = 'examples/table';
require __DIR__ . '/../tmpl/header.php';
?>
<section class="chim-panel">
    <p>Sample data only. Replace these rows with your own bounded, parameterized query.</p>
    <div class="table-wrap"><table><thead><tr><th scope="col">Name</th><th scope="col">Location</th></tr></thead><tbody>
    <?php foreach ($rows as [$name, $location]): ?><tr><td><?= e($name) ?></td><td><?= e($location) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<a href="index.php">Back to examples</a>
<?php require __DIR__ . '/../tmpl/footer.php'; ?>
