<?php
use function Fieldnote\e;
use Fieldnote\Security;

// All internal markup is ours and self-hosted, so these pages run under a
// strict CSP (no inline scripts or styles). Must be sent before any output.
Security::sendAdminCsp();

$base = e($siteConfig['basePath']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Fieldnote | <?= e($pageTitle ?? '') ?></title>
    <link rel="icon" href="<?= $base ?>/logo.svg?v=<?= (int) @filemtime(FN_ROOT . '/public/logo.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $base ?>/static/vendor/bootstrap.min.css">
    <?php if (!empty($needsEditor)): ?>
        <link rel="stylesheet" href="<?= $base ?>/static/vendor/easymde.min.css">
        <link rel="stylesheet" href="<?= $base ?>/static/easymde-icons.css">
    <?php endif; ?>
    <?php /* mtime-versioned so admin CSS changes land without a hard refresh */ ?>
    <link rel="stylesheet" href="<?= $base ?>/static/style.css?v=<?= (int) @filemtime(FN_ROOT . '/public/static/style.css') ?>">
</head>
<body>
    <div class="container">
        <div class="row my-1">
            <div class="col-md-3"></div>
            <div class="col-md-6 setupHeader text-center">
                <a href="<?= e($siteConfig['domain'] ?: ($siteConfig['basePath'] ?: '/')) ?>"><span class="headerLogo"></span><span class="fieldnoteName">Fieldnote</span></a>
            </div>
            <div class="col-md-3"></div>
        </div>
