<?php
/**
 * Shared page header. Set any of these before including:
 *   $pageTitle  string  — <title> text                       (default SITE_NAME)
 *   $pageCss    array   — CSS basenames, in order, e.g.
 *                         ['main','dashboard'] → /assets/css/main.css + dashboard.css
 *   $bodyClass  string  — class attribute for <body>          (default none)
 *   $showNav    bool    — include the shared nav.php           (default false)
 *   $pageHead   string  — extra raw HTML injected into <head>  (default none)
 */
$pageTitle = $pageTitle ?? SITE_NAME;
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
<title><?= $pageTitle ?></title>
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
