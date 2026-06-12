<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p>Off course. That page doesn't exist.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to the start line</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
