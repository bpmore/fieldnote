<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p class="lost-note">This work is not part of the current exhibition.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Return to the exhibition</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
