<?php use function Dropplets\e; ?>
    </main>
    <footer class="site-footer">
        <p class="ornament" aria-hidden="true">&#9670; &#10016; &#9670;</p>
        <?php if (!empty($siteConfig['footer'])): ?>
            <p><?= e($siteConfig['footer']) ?></p>
        <?php endif; ?>
        <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS</a></p>
    </footer>
</body>
</html>
