<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p>That dish is off the menu &mdash; perhaps it was never served here.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">See today's menu</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
