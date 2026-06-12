<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <p class="frame-no">frame 00A</p>
    <h1 class="display">404</h1>
    <p>That frame never made it onto the roll.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to the contact sheet</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
