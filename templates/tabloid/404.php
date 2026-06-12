<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <p class="kicker">SHOCK HORROR</p>
    <h1 class="display">404</h1>
    <p>PAGE VANISHES WITHOUT A TRACE &mdash; sources baffled.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to the front page</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
