<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p>This frame is empty &mdash; the page you wanted is not here.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to the wall</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
