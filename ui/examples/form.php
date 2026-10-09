<?php
require __DIR__ . '/../common.php';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf()) {
        http_response_code(403);
        $message = 'This form expired. Reload and try again.';
    } elseif (!is_string($_POST['name'] ?? null) || mb_strlen(trim($_POST['name'])) > 64 || trim($_POST['name']) === '') {
        $message = 'Enter a name between 1 and 64 characters.';
    } else {
        $message = 'Hello, ' . trim($_POST['name']) . '. This demonstration saved nothing.';
    }
}
$title = 'Form example';
$active = 'examples/form';
require __DIR__ . '/../tmpl/header.php';
?>
<section class="chim-panel narrow">
    <p>Demo only. This form validates input and displays a message; it never changes config or the database.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label for="name">Name</label><input type="text" id="name" name="name" maxlength="64" required>
        <button>Try the form</button>
    </form>
    <?php if ($message !== ''): ?><p class="notice help" role="status"><?= e($message) ?></p><?php endif; ?>
</section>
<a href="index.php">Back to examples</a>
<?php require __DIR__ . '/../tmpl/footer.php'; ?>
