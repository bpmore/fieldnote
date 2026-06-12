<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="correction">
    <p class="story-meta">Correction</p>
    <h1 class="piece-headline">This page was never printed</h1>
    <p class="story-standfirst">The address you followed leads nowhere &mdash; a 404, in the trade.</p>
    <p><a class="return-link" href="<?= e($router->generate('home')) ?>">&larr; All stories</a></p>
</div>
<?php require __DIR__ . '/footer.php'; ?>
