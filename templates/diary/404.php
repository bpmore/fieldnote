<?php
use function Dropplets\e;
require __DIR__ . '/header.php';
?>
<div class="missing-page">
    <p class="entry-date entry-date-post" aria-hidden="true">Today</p>
    <h1 class="entry-page-title">This page was torn out</h1>
    <p>Whatever was written here is gone &mdash; 404, page not found.</p>
    <p><a class="return-link" href="<?= e($router->generate('home')) ?>">&larr; Back to the journal</a></p>
</div>
<?php require __DIR__ . '/footer.php'; ?>
