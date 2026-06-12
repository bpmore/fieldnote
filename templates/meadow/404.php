<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p>This path wanders off into the tall grass and never comes back.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to the meadow</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
