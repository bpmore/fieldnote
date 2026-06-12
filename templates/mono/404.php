<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="not-found">
    <h1 class="piece-title">404</h1>
    <p class="piece-meta">There is no page at this address.</p>
    <p><a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Index</a></p>
</div>
<?php require __DIR__ . '/footer.php'; ?>
