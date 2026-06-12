<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The kitchen is still prepping &mdash; today's menu will be posted soon.</p>
<?php else: ?>
    <p class="course-heading" aria-hidden="true">&mdash; Du Jour &mdash;</p>
    <ul class="menu-list">
        <?php foreach ($allPosts as $p): ?>
            <li class="menu-item">
                <div class="menu-row">
                    <h2 class="dish">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <span class="leader" aria-hidden="true"></span>
                    <span class="menu-date"><?= e(date($dateFormat, (int) $p['date'])) ?></span>
                </div>
                <?php if (!empty($p['password'])): ?>
                    <p class="menu-desc"><span class="lock" aria-hidden="true">&#128274;</span> Chef's reserve &mdash; ask for the password</p>
                <?php else: ?>
                    <p class="menu-desc">Prepared by <?= e($p['author']) ?></p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
