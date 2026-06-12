<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <p class="center-coord" aria-hidden="true">0&deg;00&prime; N &middot; 0&deg;00&prime; W</p>
    <h1 class="display">404</h1>
    <p>This page lies off the charted map.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Navigate home</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
