<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="not-found">
    <h1 class="nf-title">four &middot; oh &middot; four</h1>
    <p class="nf-text">this page has drifted away, like a line<br>remembered wrong</p>
    <a class="back-link" href="<?= e($router->generate('home')) ?>">return</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
