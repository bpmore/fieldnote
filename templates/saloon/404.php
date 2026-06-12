<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="center-page">
    <p class="poster-eyebrow" aria-hidden="true">&#9733; Wanted &#9733;</p>
    <h1 class="display">404</h1>
    <p>That page skipped town. Last seen heading for the badlands.</p>
    <a class="button" href="<?= e($router->generate('home')) ?>">Back to the saloon</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
