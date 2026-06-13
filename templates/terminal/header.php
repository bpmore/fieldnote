<?php
use function Fieldnote\e;
use function Fieldnote\fn_render_head;
use function Fieldnote\fn_skip_link;
$siteName = $siteConfig['name'] !== '' ? $siteConfig['name'] : 'Fieldnote';
?>
<!doctype html>
<html lang="en">
<head>
<?php fn_render_head($siteConfig, $router, $pageTitle ?? '', $post ?? null, $router->generate('themeAsset', ['theme' => 'terminal', 'file' => 'theme.css'])); ?>
</head>
<body>
<?php fn_skip_link(); ?>
<header class="masthead">
    <p class="prompt-line"><span class="prompt-user"><?= e(strtolower(preg_replace('/[^a-z0-9]+/i', '', $siteConfig['author'] ?: 'guest') ?: 'guest')) ?>@blog</span>:<span class="prompt-path">~</span>$ <a class="site-title" href="<?= e($router->generate('home')) ?>"><?= e($siteName) ?></a><span class="cursor" aria-hidden="true"></span></p>
    <?php if ($siteConfig['info'] !== ''): ?><p class="site-info"># <?= e($siteConfig['info']) ?></p><?php endif; ?>
<?php Fieldnote\fn_profile_link($router, $siteConfig); ?>
        <?php Fieldnote\fn_search_form($router, $siteConfig, (string) ($_GET["q"] ?? "")); ?>
</header>
<main id="main" class="wrap">
