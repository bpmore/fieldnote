<?php use function Fieldnote\e; ?>
</main>
<footer class="site-footer">
    <?php if (!empty($siteConfig['footer'])): ?><p><?= e($siteConfig['footer']) ?></p><?php endif; ?>
    <p><a href="<?= e($router->generate('feed')) ?>">RSS</a></p>
        <?php Fieldnote\fn_a11y_badge($router, $siteConfig); ?>
</footer>
</body>
</html>
