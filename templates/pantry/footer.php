<?php use function Fieldnote\e; ?>
    </main>
    <footer class="site-footer">
        <div class="gingham" aria-hidden="true"></div>
        <div class="footer-inner">
            <?php if (!empty($siteConfig['footer'])): ?>
                <p><?= e($siteConfig['footer']) ?></p>
            <?php endif; ?>
            <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS feed</a></p>
        </div>
        <?php Fieldnote\fn_social_links($siteConfig); ?>
        <?php Fieldnote\fn_footer_copyright($siteConfig); ?>
        <?php Fieldnote\fn_a11y_badge($router, $siteConfig); ?>
    </footer>
</body>
</html>
