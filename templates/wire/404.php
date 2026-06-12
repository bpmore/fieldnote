<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <p class="kicker"><span class="kicker-flag">Correction</span></p>
    <h1 class="error-title">Page not found</h1>
    <p>The story you are looking for has been moved or never ran.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Front page</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
