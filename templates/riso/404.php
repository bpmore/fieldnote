<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display offset">404</h1>
    <p>This page never made it off the press.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to the contents page</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
