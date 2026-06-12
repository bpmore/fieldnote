<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p>ZAP! That page is gone.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to the gallery</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
