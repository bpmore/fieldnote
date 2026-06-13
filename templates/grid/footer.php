<?php use function Fieldnote\e; ?>
    </main>
    <footer class="site-footer">
        <div class="footer-inner module">
            <?php if (!empty($siteConfig['footer'])): ?>
                <p><?= e($siteConfig['footer']) ?></p>
            <?php endif; ?>
            <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">rss</a></p>
        </div>
        <?php Fieldnote\fn_a11y_badge($router, $siteConfig); ?>
    </footer>
</body>
</html>
