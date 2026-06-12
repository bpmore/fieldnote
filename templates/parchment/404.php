<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="not-found">
    <h1 class="nf-title">Folio 404</h1>
    <p class="nf-text">This leaf is missing from the codex &mdash; lost, lent, or eaten by mice.</p>
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Back to the codex</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
