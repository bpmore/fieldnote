<?php use function Dropplets\e; ?>
    </main>
    <footer class="site-footer">
        <div class="footer-inner">
            <?php if (!empty($siteConfig['footer'])): ?>
                <p><?= e($siteConfig['footer']) ?></p>
            <?php endif; ?>
            <p><a class="footer-link" href="<?= e($router->generate('feed')) ?>">RSS</a></p>
        </div>
    </footer>
</body>
</html>
