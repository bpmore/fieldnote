<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p>This page was never drawn into the plans.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to the foyer</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
