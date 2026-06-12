<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="not-found index-card">
    <p class="card-stamp-row"><span class="stamp">Not on file</span></p>
    <h1 class="nf-title">404 &mdash; card missing</h1>
    <p class="nf-text">No record matches this call number. It may have been misfiled or withdrawn.</p>
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Return to the catalog</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
