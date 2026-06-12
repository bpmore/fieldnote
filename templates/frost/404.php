<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page glass">
    <h1 class="display">404</h1>
    <p>That page melted away.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Go Home</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
