<?php
$meta = $meta ?? page_meta($site['name'], $site['description'], $site['url']);
$bodyClass = $bodyClass ?? '';
$activeSection = strtok($bodyClass, ' ') ?: 'home';
$isJournalSection = in_array($activeSection, ['journal', 'studio-notes'], true);
$isBlogSection = $activeSection === 'blog';
$isActive = function (string $section) use ($activeSection): string {
    return $activeSection === $section ? ' class="is-active" aria-current="page"' : '';
};
$metaImage = !empty($meta['image'])
    ? (preg_match('~^https?://~', (string)$meta['image']) ? (string)$meta['image'] : rtrim($site['url'], '/') . '/' . ltrim((string)$meta['image'], '/'))
    : '';
$artistPhotoFile = trim((string)($profile['photo_file'] ?? ''));
$faviconUrl = $artistPhotoFile !== ''
    ? app_artist_photo_url($artistPhotoFile)
    : artworkmockups_public_url() . '/favicon.svg?v=1';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>document.documentElement.classList.add('has-js');</script>
    <title><?= e($meta['title']) ?></title>
    <link rel="icon" href="<?= e($faviconUrl) ?>">
    <meta name="description" content="<?= e($meta['description']) ?>">
    <?php if (!empty($meta['robots'])): ?><meta name="robots" content="<?= e($meta['robots']) ?>"><?php endif; ?>
    <?php if (!empty($meta['keywords'])): ?><meta name="keywords" content="<?= e($meta['keywords']) ?>"><?php endif; ?>
    <link rel="canonical" href="<?= e($meta['canonical']) ?>">
    <meta property="og:title" content="<?= e($meta['title']) ?>">
    <meta property="og:description" content="<?= e($meta['description']) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= e($meta['canonical']) ?>">
    <?php if ($metaImage): ?>
        <meta property="og:image" content="<?= e($metaImage) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?= $metaImage ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= e($meta['title']) ?>">
    <meta name="twitter:description" content="<?= e($meta['description']) ?>">
    <?php if ($metaImage): ?><meta name="twitter:image" content="<?= e($metaImage) ?>"><?php endif; ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="<?= e(asset_version_url('assets/css/styles.css')) ?>">
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-01NMM6VVDC"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());

        gtag('config', 'G-01NMM6VVDC');
    </script>
</head>
<body class="<?= e($bodyClass) ?>">
<header class="site-header">
    <a class="brand" href="<?= e(url_for('/')) ?>" aria-label="<?= e($artistName ?? 'Artist') ?> home">
        <span><?= e($artistName ?? 'Artist') ?></span>
        <small><?= e($site['tagline'] ?? '') ?></small>
    </a>
    <button class="mobile-nav-toggle" type="button" aria-expanded="false" aria-controls="header-tools" data-mobile-nav-toggle>
        <span class="mobile-nav-toggle__icon" aria-hidden="true"><i></i><i></i></span>
        <span>Menu</span>
    </button>
    <div class="header-tools" id="header-tools" data-header-tools>
        <nav class="main-nav" aria-label="Main navigation">
            <a<?= $isActive('artworks') ?> href="<?= e(url_for('artworks/')) ?>">Artworks</a>
            <a<?= $isActive('series') ?> href="<?= e(url_for('series')) ?>">Series</a>
            <a<?= $isActive('sold-works') ?> href="<?= e(url_for('sold-works')) ?>">Constellations</a>
            <a<?= $isJournalSection ? ' class="is-active" aria-current="page"' : '' ?> href="<?= e(url_for('studio-notes')) ?>">Studio Notes</a>
            <a<?= $isActive('artist') ?> href="<?= e(url_for('artist')) ?>">Artist</a>
            <a class="nav-cta<?= $activeSection === 'contact' ? ' is-active' : '' ?>" <?= $activeSection === 'contact' ? 'aria-current="page"' : '' ?> href="<?= e(url_for('contact')) ?>">Inquire</a>
        </nav>
        <form class="site-search" action="<?= e(url_for('artworks/')) ?>" method="get" role="search">
            <label class="sr-only" for="site-search-input">Search paintings</label>
            <input id="site-search-input" name="q" type="search" placeholder="Search works or series" autocomplete="off">
            <button type="submit">Search</button>
        </form>
    </div>
</header>
<main>
