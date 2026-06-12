<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="missing">
    <h1 class="missing-code">404</h1>
    <p>That page slipped out of the album.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Come back home</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
