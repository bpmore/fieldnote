<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="oops">
    <h1 class="oops-code">404</h1>
    <p>That recipe seems to have crumbled away.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to the kitchen</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
