<?php use function Fieldnote\e; ?>
    </main>
    <footer class="site-footer">
        <div class="dim-rule" aria-hidden="true"><span class="dim-label">END OF SHEET</span></div>
        <?php if (!empty($siteConfig['footer'])): ?>
            <p><?= e($siteConfig['footer']) ?></p>
        <?php endif; ?>
        <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS feed</a></p>
        <?php Fieldnote\fn_social_links($siteConfig); ?>
        <?php Fieldnote\fn_footer_copyright($siteConfig); ?>
        <?php Fieldnote\fn_a11y_badge($router, $siteConfig); ?>
    </footer>
</body>
</html>
