<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="error-page module">
    <p class="display" aria-hidden="true">404</p>
    <div class="error-main">
        <h1 class="error-title">page not found</h1>
        <p>this address maps to nothing on the grid.</p>
        <a class="button" href="<?= e($router->generate('home')) ?>">&larr; index</a>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
