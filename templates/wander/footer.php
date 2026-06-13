<?php use function Fieldnote\e; ?>
    </main>
    <footer class="luggage">
        <?php if (!empty($siteConfig['footer'])): ?>
            <p><?= e($siteConfig['footer']) ?></p>
        <?php endif; ?>
        <p><a class="foot-link" href="<?= e($router->generate('feed')) ?>">RSS</a></p>
        <?php Fieldnote\fn_social_links($siteConfig); ?>
        <?php Fieldnote\fn_footer_copyright($siteConfig); ?>
        <?php Fieldnote\fn_a11y_badge($router, $siteConfig); ?>
    </footer>
</body>
</html>
