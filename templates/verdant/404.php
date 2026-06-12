<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p class="latin" aria-hidden="true">Pagina inventa non est</p>
    <p>This page never took root.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to the garden</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
