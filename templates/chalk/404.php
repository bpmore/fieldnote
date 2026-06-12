<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p>This page was erased before the bell. See me after class.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to class</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
