<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="lost">
    <h1 class="lost-code">404</h1>
    <p>This page wandered off the map.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Find your way home</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
