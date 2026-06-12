<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <p class="display" aria-hidden="true">404</p>
    <h1 class="error-title">Entry not found</h1>
    <p>Nothing is filed under this address.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to the register</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
