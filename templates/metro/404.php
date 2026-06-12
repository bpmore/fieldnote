<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <h1 class="display">404</h1>
    <p>End of the line &mdash; this station does not exist.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Return to the terminus</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
