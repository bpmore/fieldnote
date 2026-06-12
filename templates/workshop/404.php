<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page sheet">
    <h1 class="display">404</h1>
    <p>Drawing not found &mdash; this sheet was never filed.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to the drafting table</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
