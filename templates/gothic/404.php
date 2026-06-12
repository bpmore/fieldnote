<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p class="ornament" aria-hidden="true">&#9670; &#10016; &#9670;</p>
    <p>This page lies beyond the candlelight. Nothing answers from the dark.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Return to the nave</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
