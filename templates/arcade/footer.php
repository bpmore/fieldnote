<?php use function Fieldnote\e; ?>
    </main>
    <footer class="site-footer">
        <p class="insert-coin" aria-hidden="true">&#9679; INSERT COIN &#9679;</p>
        <?php if (!empty($siteConfig['footer'])): ?>
            <p><?= e($siteConfig['footer']) ?></p>
        <?php endif; ?>
        <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS FEED</a></p>
        <?php Fieldnote\fn_social_links($siteConfig); ?>
        <?php Fieldnote\fn_footer_copyright($siteConfig); ?>
        <?php Fieldnote\fn_a11y_badge($router, $siteConfig); ?>
    </footer>
</body>
</html>
