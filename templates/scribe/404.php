<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="not-found">
    <p class="nf-code" aria-hidden="true">&sect; 404</p>
    <h1 class="nf-title">Entry not located</h1>
    <p>The page you requested does not appear in the index.</p>
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Return to the index</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
