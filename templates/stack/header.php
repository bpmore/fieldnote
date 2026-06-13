<?php

use function Fieldnote\e;
use function Fieldnote\fn_render_head;
use function Fieldnote\fn_skip_link;

$siteName = $siteConfig['name'] !== '' ? $siteConfig['name'] : 'Fieldnote';
?>
<!doctype html>
<html lang="en">

<head>
    <?php fn_render_head(
        $siteConfig,
        $router,
        $pageTitle ?? '',
        $post ?? null,
        $router->generate('themeAsset', ['theme' => 'stack', 'file' => 'theme.css'])
    ); ?>
</head>

<body>
    <?php fn_skip_link(); ?>
    <header class="masthead">
        <div class="masthead-inner">
            <a class="site-title" href="<?= e($router->generate('home')) ?>"><span class="prompt" aria-hidden="true">~/</span><?= e($siteName) ?></a>
            <?php if ($siteConfig['info'] !== ''): ?>
                <p class="site-info"><span class="comment-mark" aria-hidden="true">//</span> <?= e($siteConfig['info']) ?></p>
            <?php endif; ?>
        </div>
<?php Fieldnote\fn_profile_link($router, $siteConfig); ?>
        <?php Fieldnote\fn_search_form($router, $siteConfig, (string) ($_GET["q"] ?? "")); ?>
    </header>
    <main id="main" class="wrap">
