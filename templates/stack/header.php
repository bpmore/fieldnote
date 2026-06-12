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
        $router->generate('themeAsset', ['theme' => 'stack', 'file' => 'theme.css'])
    ); ?>
</head>

<body>
    <?php dpl_skip_link(); ?>
    <header class="masthead">
        <div class="masthead-inner">
            <a class="site-title" href="<?= e($router->generate('home')) ?>"><span class="prompt" aria-hidden="true">~/</span><?= e($siteName) ?></a>
            <?php if ($siteConfig['info'] !== ''): ?>
                <p class="site-info"><span class="comment-mark" aria-hidden="true">//</span> <?= e($siteConfig['info']) ?></p>
            <?php endif; ?>
        </div>
    </header>
    <main id="main" class="wrap">
