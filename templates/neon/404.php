<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page panel">
    <div class="panel-body">
        <h1 class="display">404</h1>
        <p class="lost-note">// signal lost. this sector does not exist.</p>
        <a class="button" href="<?= e($router->generate('home')) ?>">Reconnect</a>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
