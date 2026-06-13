<?php use function Fieldnote\e; ?>
    </main>
    <footer class="site-footer">
        <p class="footer-orn" aria-hidden="true">&#10087; &#10087; &#10087;</p>
        <?php if (!empty($siteConfig['footer'])): ?>
            <p><?= e($siteConfig['footer']) ?></p>
        <?php endif; ?>
        <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS feed</a></p>
        <?php Fieldnote\fn_a11y_badge($router, $siteConfig); ?>
    </footer>
</body>
</html>
