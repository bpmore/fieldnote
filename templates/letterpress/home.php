<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_excerpt;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The press is idle. No specimens have been set.</p>
<?php else: ?>
    <div class="specimens">
        <?php foreach ($allPosts as $p): ?>
            <article class="specimen">
                <span class="specimen-letter" aria-hidden="true"><?= e(mb_strtolower(mb_substr(trim($p['title']) !== '' ? trim($p['title']) : 'a', 0, 1))) ?></span>
                <div class="specimen-body">
                    <h2 class="specimen-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <p class="specimen-meta"><?= e(date($dateFormat, (int) $p['date'])) ?> &middot; <?= e($p['author']) ?></p>
                    <?php if (!empty($p['password'])): ?>
                        <p class="specimen-text is-locked">&#128274; Locked in the chase &mdash; this proof requires a password.</p>
                    <?php elseif (($x = dpl_excerpt($p, 150)) !== ''): ?>
                        <p class="specimen-text"><?= e($x) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
