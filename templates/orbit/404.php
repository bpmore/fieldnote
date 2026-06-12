<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p class="lost-note">Signal lost. That page drifted out of orbit.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to base</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
