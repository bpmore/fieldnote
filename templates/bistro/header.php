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
        $router->generate('themeAsset', ['theme' => 'bistro', 'file' => 'theme.css'])
    ); ?>
</head>

<body>
    <?php dpl_skip_link(); ?>
    <div class="menu-card">
        <header class="masthead">
            <p class="est" aria-hidden="true">&#10043; EST. &#10043;</p>
            <a class="site-title" href="<?= e($router->generate('home')) ?>"><?= e($siteName) ?></a>
            <?php if ($siteConfig['info'] !== ''): ?>
                <p class="site-info"><?= e($siteConfig['info']) ?></p>
            <?php endif; ?>
            <div class="ornament" aria-hidden="true">&#10043;</div>
        </header>
        <main id="main" class="wrap">
