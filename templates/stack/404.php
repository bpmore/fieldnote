<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <p class="display" aria-hidden="true">404</p>
    <h1 class="error-title">path not found</h1>
    <p>The requested file does not exist in this tree.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">cd ~/</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
