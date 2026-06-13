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
        $router->generate('themeAsset', ['theme' => 'ember', 'file' => 'theme.css'])
    ); ?>
</head>

<body>
    <?php fn_skip_link(); ?>
    <header class="hearth">
        <a class="site-title" href="<?= e($router->generate('home')) ?>"><?= e($siteName) ?></a>
        <?php if ($siteConfig['info'] !== ''): ?>
            <p class="site-info"><?= e($siteConfig['info']) ?></p>
        <?php endif; ?>
<?php Fieldnote\fn_search_form($router, $siteConfig, (string) ($_GET["q"] ?? "")); ?>
    </header>
    <main id="main" class="wrap">
