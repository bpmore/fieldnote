<?php

use function Dropplets\e;
use function Dropplets\dpl_render_head;
use function Dropplets\dpl_skip_link;

$siteName = $siteConfig['name'] !== '' ? $siteConfig['name'] : 'Dropplets';
?>
<!doctype html>
<html lang="en">

<head>
    <?php dpl_render_head(
        $siteConfig,
        $router,
        $pageTitle ?? '',
        $post ?? null,
        $router->generate('themeAsset', ['theme' => 'bauhaus', 'file' => 'theme.css'])
    ); ?>
</head>

<body>
    <?php dpl_skip_link(); ?>
    <header class="masthead">
        <div class="masthead-shapes" aria-hidden="true">
            <span class="shape shape-circle"></span>
            <span class="shape shape-square"></span>
            <span class="shape shape-triangle"></span>
        </div>
        <div class="masthead-text">
            <a class="site-title" href="<?= e($router->generate('home')) ?>"><?= e($siteName) ?></a>
            <?php if ($siteConfig['info'] !== ''): ?>
                <p class="site-info"><?= e($siteConfig['info']) ?></p>
            <?php endif; ?>
        </div>
    </header>
    <main id="main" class="wrap">
