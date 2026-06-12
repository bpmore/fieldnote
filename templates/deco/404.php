<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page plate">
    <div class="plate-inner">
        <h1 class="display">404</h1>
        <p>This page does not appear on tonight's bill.</p>
        <a class="button" href="<?= e($router->generate('home')) ?>">Return to the foyer</a>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
