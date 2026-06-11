<?php

use function Dropplets\e;

$siteName  = $siteConfig['name'] !== '' ? $siteConfig['name'] : 'Dropplets';
$base      = e($siteConfig['basePath']);
$fullTitle = $siteName . ' | ' . ($pageTitle ?? '');

// Absolute canonical URL when a domain is configured (path only, no query).
$canonical = '';
if ($siteConfig['domain'] !== '') {
    $path      = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $canonical = rtrim($siteConfig['domain'], '/') . $path;
}

// On a single post prefer its featured image for social cards.
$socialImage = (isset($post['imageUrl']) && $post['imageUrl'] !== '' && empty($post['password']))
    ? $post['imageUrl']
    : $siteConfig['OGImage'];
$ogType = isset($post['title']) ? 'article' : 'website';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="index, follow">
    <title><?= e($fullTitle) ?></title>

    <link rel="stylesheet" href="<?= $base ?>/static/theme.css">
    <link rel="icon" href="<?= $base ?>/logo.svg" type="image/svg+xml">
    <link rel="alternate" type="application/rss+xml" title="<?= e($siteName) ?>" href="<?= e($router->generate('feed')) ?>">
    <?php if ($canonical !== ''): ?>
        <link rel="canonical" href="<?= e($canonical) ?>">
    <?php endif; ?>

    <?php if ($siteConfig['info'] !== ''): ?>
        <meta name="description" content="<?= e($siteConfig['info']) ?>">
        <meta property="og:description" content="<?= e($siteConfig['info']) ?>">
    <?php endif; ?>
    <meta property="og:title" content="<?= e($fullTitle) ?>">
    <meta property="og:site_name" content="<?= e($siteName) ?>">
    <meta property="og:type" content="<?= e($ogType) ?>">
    <?php if ($canonical !== ''): ?>
        <meta property="og:url" content="<?= e($canonical) ?>">
    <?php endif; ?>
    <?php if ($socialImage !== ''): ?>
        <meta property="og:image" content="<?= e($socialImage) ?>">
        <meta name="twitter:image" content="<?= e($socialImage) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?= $socialImage !== '' ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= e($fullTitle) ?>">

    <?php
    // headerInject is raw markup supplied by the authenticated admin (for
    // analytics snippets and the like). It is intentionally NOT escaped and
    // is writable only from the CSRF-protected settings form.
    if (!empty($siteConfig['headerInject'])) {
        echo $siteConfig['headerInject'];
    }
    ?>
</head>

<body>
    <header class="masthead">
        <a class="site-title" href="<?= e($router->generate('home')) ?>"><?= e($siteName) ?></a>
        <?php if ($siteConfig['info'] !== ''): ?>
            <p class="site-info"><?= e($siteConfig['info']) ?></p>
        <?php endif; ?>
    </header>
    <main class="wrap">
