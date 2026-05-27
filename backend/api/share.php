<?php
declare(strict_types=1);

// Personalized share landing page.
// FB / LinkedIn scrapers read og:title / og:description from this page, NOT
// from URL query params (those have been broken on FB since 2017 and never
// worked on LinkedIn). We render the user's stats into the meta tags here.
//
// URL: /share?d=<top% datorii>&v=<top% venituri>&n=<top% net worth>
//
// Human visitors get the SPA shell which then redirects to / via React Router.

$d = isset($_GET['d']) && is_numeric($_GET['d']) ? max(0, min(100, (int)$_GET['d'])) : null;
$v = isset($_GET['v']) && is_numeric($_GET['v']) ? max(0, min(100, (int)$_GET['v'])) : null;
$n = isset($_GET['n']) && is_numeric($_GET['n']) ? max(0, min(100, (int)$_GET['n'])) : null;

$siteUrl  = 'https://cumstaicubanii.ro';
$imageUrl = $siteUrl . '/og-image.png';

// Net worth is always present once a submission exists; datorii / asset can be
// missing if the user didn't report that side. Build a personalized OG as long
// as net worth is present, with whichever subset of {datorii, asset} was sent.
if ($n !== null) {
    $titleParts = ["Top {$n}% net worth"];
    $descParts  = [];
    $urlParts   = [];
    if ($v !== null) { $titleParts[] = "Top {$v}% asset-uri"; $descParts[] = "top {$v}% ca asset-uri"; $urlParts[] = "v={$v}"; }
    if ($d !== null) { $titleParts[] = "Top {$d}% datorii";   $descParts[] = "top {$d}% ca datorii";   $urlParts[] = "d={$d}"; }
    $descParts[] = "top {$n}% ca net worth";
    $urlParts[]  = "n={$n}";
    $title       = implode(' · ', $titleParts) . ' — cumstaicubanii.ro';
    $description = '💰 Mă situez în ' . implode(', ', $descParts) . '. Tu cum stai cu banii?';
    $shareUrl    = "{$siteUrl}/share?" . implode('&', $urlParts);
} else {
    $title       = 'Datorii vs Asset-uri — situația ta financiară, anonim · cumstaicubanii.ro';
    $description = 'Compară-te anonim cu media: datorii, asset-uri, net worth. Vezi în ce percentilă te afli.';
    $shareUrl    = $siteUrl . '/';
}

$titleEsc = htmlspecialchars($title,       ENT_QUOTES, 'UTF-8');
$descEsc  = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
$urlEsc   = htmlspecialchars($shareUrl,    ENT_QUOTES, 'UTF-8');

// Try to load the static SPA index.html and inject our meta tags into it,
// so non-bot visitors get the React app loaded. Fall back to a minimal page
// with just OG tags + JS redirect if we can't read it.
$docRoot  = $_SERVER['DOCUMENT_ROOT'] ?? '/home/cscb/web/cumstaicubanii.ro/public_html';
$indexPath = rtrim($docRoot, '/') . '/index.html';
$html = @file_get_contents($indexPath);

header('Content-Type: text/html; charset=utf-8');
// Short cache — FB scrapers cache the OG output, but if user re-shares with
// different stats, we want a fresh fetch eventually.
header('Cache-Control: public, max-age=300');

if (!is_string($html) || $html === '') {
    echo <<<HTML
<!doctype html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$titleEsc}</title>
<meta name="description" content="{$descEsc}">
<meta property="og:type" content="website">
<meta property="og:title" content="{$titleEsc}">
<meta property="og:description" content="{$descEsc}">
<meta property="og:image" content="{$imageUrl}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:url" content="{$urlEsc}">
<meta property="og:locale" content="ro_RO">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$titleEsc}">
<meta name="twitter:description" content="{$descEsc}">
<meta name="twitter:image" content="{$imageUrl}">
<script>location.replace('/');</script>
</head>
<body></body>
</html>
HTML;
    exit;
}

// Replace OG / Twitter / title / description tags in the loaded HTML.
$patterns = [
    '/<title>[^<]*<\/title>/i'                                                   => "<title>{$titleEsc}</title>",
    '/<meta\s+name="description"\s+content="[^"]*"\s*\/?>/i'                     => "<meta name=\"description\" content=\"{$descEsc}\">",
    '/<meta\s+property="og:title"\s+content="[^"]*"\s*\/?>/i'                    => "<meta property=\"og:title\" content=\"{$titleEsc}\">",
    '/<meta\s+property="og:description"\s+content="[^"]*"\s*\/?>/i'              => "<meta property=\"og:description\" content=\"{$descEsc}\">",
    '/<meta\s+property="og:url"\s+content="[^"]*"\s*\/?>/i'                      => "<meta property=\"og:url\" content=\"{$urlEsc}\">",
    '/<meta\s+name="twitter:title"\s+content="[^"]*"\s*\/?>/i'                   => "<meta name=\"twitter:title\" content=\"{$titleEsc}\">",
    '/<meta\s+name="twitter:description"\s+content="[^"]*"\s*\/?>/i'             => "<meta name=\"twitter:description\" content=\"{$descEsc}\">",
];
foreach ($patterns as $p => $r) {
    $html = preg_replace($p, $r, $html);
}

echo $html;
