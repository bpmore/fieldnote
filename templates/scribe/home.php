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
    <p class="empty-state">No papers have been filed yet. The first abstract is forthcoming.</p>
<?php else: ?>
    <ol class="abstracts">
        <?php foreach ($allPosts as $p): ?>
            <li class="abstract">
                <article>
                    <h2 class="abstract-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="abstract-text is-locked">&#128274; This entry is password-protected. The abstract is withheld.</p>
                    <?php elseif (($x = dpl_excerpt($p, 180)) !== ''): ?>
                        <p class="abstract-text"><?= e($x) ?></p>
                    <?php endif; ?>
                    <p class="abstract-meta"><?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                </article>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
