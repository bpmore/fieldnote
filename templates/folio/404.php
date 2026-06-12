<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="errata">
    <p class="chapter-kicker">Errata</p>
    <h1 class="chapter-title">Page not found</h1>
    <div class="ornament" aria-hidden="true"></div>
    <p>This leaf is missing from the binding. Error 404.</p>
    <p><a class="return-link" href="<?= e($router->generate('home')) ?>">&larr; Return to the contents</a></p>
</div>
<?php require __DIR__ . '/footer.php'; ?>
