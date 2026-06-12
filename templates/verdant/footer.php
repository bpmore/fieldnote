<?php use function Dropplets\e; ?>
    </main>
    <footer class="site-footer">
        <p class="footer-orn" aria-hidden="true">&#10087; &#10087; &#10087;</p>
        <?php if (!empty($siteConfig['footer'])): ?>
            <p><?= e($siteConfig['footer']) ?></p>
        <?php endif; ?>
        <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS feed</a></p>
    </footer>
</body>
</html>
