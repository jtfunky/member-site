<?php
/**
 * Shared page header. Set any of these before including:
 *   $pageTitle       string  — <title> text                       (default SITE_NAME)
 *   $pageDescription string  — meta description + OG/Twitter description
 *                              (default a generic site description)
 *   $pageIndex       bool    — allow search engines to index this page
 *                              (default false — app pages are noindex by
 *                              default; only the marketing pages opt in)
 *   $pageImage       string  — absolute URL for og:image/twitter:image
 *                              (default the landing hero photo)
 *   $pageCss    array   — CSS basenames, in order, e.g.
 *                         ['main','dashboard'] → /assets/css/main.css + dashboard.css
 *   $bodyClass  string  — class attribute for <body>          (default none)
 *   $showNav    bool    — include the shared nav.php           (default false)
 *   $pageHead   string  — extra raw HTML injected into <head>  (default none)
 */
$pageTitle       = $pageTitle       ?? SITE_NAME;
$pageDescription = $pageDescription ?? 'Learn drums online or in person with ' . SITE_NAME . '. A browser-based rhythm game plus real lessons — no download needed.';
$pageIndex       = $pageIndex       ?? false;
$pageImage       = $pageImage       ?? SITE_URL . '/assets/img/hero-drummer.jpg';
$pageCanonical   = SITE_URL . strtok($_SERVER['REQUEST_URI'], '?');
$pageCss   = $pageCss   ?? ['main'];
$bodyClass = $bodyClass ?? '';
$showNav   = $showNav   ?? false;
$pageHead  = $pageHead  ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="google-site-verification" content="kXMjkajmcFPozIb-z_nt2KNB8UyHa44Mrtt3qh4qkUE" />
<link rel="icon" type="image/x-icon" href="/assets/img/favicon/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/img/favicon/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/img/favicon/apple-touch-icon.png">
<link rel="manifest" href="/assets/img/favicon/site.webmanifest">
<title><?= $pageTitle ?></title>
<meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
<meta name="robots" content="<?= $pageIndex ? 'index, follow' : 'noindex, nofollow' ?>">
<link rel="canonical" href="<?= htmlspecialchars($pageCanonical) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= htmlspecialchars(SITE_NAME) ?>">
<meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($pageDescription) ?>">
<meta property="og:url" content="<?= htmlspecialchars($pageCanonical) ?>">
<meta property="og:image" content="<?= htmlspecialchars($pageImage) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($pageTitle) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($pageDescription) ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($pageImage) ?>">
<?php
// Icon font first (self-hosted Tabler subset — see assets/css/icons.css), then
// the page's own stylesheets.
array_unshift($pageCss, 'icons');
foreach ($pageCss as $css):
    // Auto cache-bust via file mtime so CSS edits show up without manual versioning.
    $cssV = @filemtime(__DIR__ . '/../assets/css/' . $css . '.css') ?: 1; ?>
<link rel="stylesheet" href="/assets/css/<?= $css ?>.css?v=<?= $cssV ?>">
<?php endforeach; ?>
<?= $pageHead ?>
</head>
<body<?= $bodyClass ? ' class="' . htmlspecialchars($bodyClass) . '"' : '' ?>>
<?php if ($showNav) include __DIR__ . '/nav.php'; ?>
