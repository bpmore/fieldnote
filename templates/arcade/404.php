<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page cabinet">
    <h1 class="display">GAME OVER</h1>
    <p>That page does not exist. Continue?</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">PRESS START</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
