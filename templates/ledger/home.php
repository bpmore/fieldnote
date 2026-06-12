<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">No entries on record yet.</p>
<?php else: ?>
    <p class="register-caption" aria-hidden="true">Entries &mdash; page <?= (int) $page ?></p>
    <ol class="register">
        <?php foreach (array_values($allPosts) as $i => $p): ?>
            <li class="entry">
                <article class="entry-inner">
                    <span class="entry-no" aria-hidden="true"><?= e(sprintf('%02d', $i + 1)) ?></span>
                    <div class="entry-main">
                        <h2 class="entry-title">
                            <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                        </h2>
                        <?php if (!empty($p['password'])): ?>
                            <p class="entry-by"><span aria-hidden="true">&#128274;</span> Private entry</p>
                        <?php else: ?>
                            <p class="entry-by"><?= e($p['author']) ?></p>
                        <?php endif; ?>
                    </div>
                    <span class="entry-date"><?= e(date($dateFormat, (int) $p['date'])) ?></span>
                </article>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
