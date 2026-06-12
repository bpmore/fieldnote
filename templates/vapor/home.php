<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">Nothing on the shelf yet. Rewind later.</p>
<?php else: ?>
    <ol class="shelf">
        <?php foreach ($allPosts as $p): ?>
            <li class="cassette">
                <span class="spine" aria-hidden="true"></span>
                <div class="cassette-body">
                    <h2 class="cassette-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="cassette-meta"><span class="lock" aria-hidden="true">&#128274;</span> Protected &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="cassette-meta"><?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
