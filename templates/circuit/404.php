<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p class="lost-note">Open circuit. This trace leads nowhere.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Return to ground</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
