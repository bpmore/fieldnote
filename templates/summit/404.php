<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p>This trail dead-ends above the treeline.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Descend to base</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
