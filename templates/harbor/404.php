<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p>Nothing moored at this berth.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Return to harbor</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
