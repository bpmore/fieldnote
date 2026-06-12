<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page jar-label">
    <div class="scallops" aria-hidden="true"></div>
    <div class="label-body">
        <h1 class="display">404</h1>
        <p>That jar isn't on the shelf &mdash; maybe it was never canned.</p>
        <a class="button" href="<?= e($router->generate('home')) ?>">Back to the pantry</a>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
