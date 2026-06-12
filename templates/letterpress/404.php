<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="not-found">
    <p class="nf-code" aria-hidden="true">404</p>
    <h1 class="nf-title">Out of sorts</h1>
    <p class="nf-text">This page was never set in type, or the forme has been distributed.</p>
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Back to the case</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
