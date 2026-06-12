<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="skip">
    <h1 class="skip-code">404</h1>
    <p>The needle skipped &mdash; that track does not exist.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to side A</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
