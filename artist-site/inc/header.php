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
    ? site_absolute_asset_url((string)$meta['image'], (string)$site['url'])
    : '';
$artistPhotoFile = trim((string)($profile['photo_file'] ?? ''));
$faviconUrl = $artistPhotoFile !== ''
    ? app_artist_photo_url($artistPhotoFile)
    : artworkmockups_public_url() . '/favicon.svg?v=1';
$currentLanguage = artist_site_language();
$languageCanonicals = is_array($meta['language_urls'] ?? null) ? $meta['language_urls'] : [];
$localizedCanonical = artist_site_url_with_language(
    (string)($languageCanonicals[$currentLanguage] ?? $meta['canonical']),
    $currentLanguage
);
?>
<!doctype html>
<html lang="<?= e($currentLanguage) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>document.documentElement.classList.add('has-js');</script>
    <title><?= e($meta['title']) ?></title>
    <link rel="icon" href="<?= e($faviconUrl) ?>">
    <meta name="description" content="<?= e($meta['description']) ?>">
    <?php if (!empty($meta['robots'])): ?><meta name="robots" content="<?= e($meta['robots']) ?>"><?php endif; ?>
    <?php if (!empty($meta['keywords'])): ?><meta name="keywords" content="<?= e($meta['keywords']) ?>"><?php endif; ?>
    <link rel="canonical" href="<?= e($localizedCanonical) ?>">
    <link rel="alternate" hreflang="es" href="<?= e(artist_site_url_with_language((string)($languageCanonicals['es'] ?? $meta['canonical']), 'es')) ?>">
    <link rel="alternate" hreflang="en" href="<?= e(artist_site_url_with_language((string)($languageCanonicals['en'] ?? $meta['canonical']), 'en')) ?>">
    <link rel="alternate" hreflang="x-default" href="<?= e(artist_site_url_with_language((string)($languageCanonicals['en'] ?? $meta['canonical']), 'en')) ?>">
    <meta property="og:title" content="<?= e($meta['title']) ?>">
    <meta property="og:description" content="<?= e($meta['description']) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= e($localizedCanonical) ?>">
    <meta property="og:locale" content="<?= $currentLanguage === 'es' ? 'es_ES' : 'en_US' ?>">
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
        <span><?= e(site_t('Menu', 'Menú')) ?></span>
    </button>
    <div class="header-tools" id="header-tools" data-header-tools>
        <nav class="main-nav" aria-label="Main navigation">
            <a<?= $isActive('artworks') ?> href="<?= e(url_for('artworks/')) ?>"><?= e(site_t('Artworks', 'Obras')) ?></a>
            <a<?= $isActive('series') ?> href="<?= e(url_for('series')) ?>"><?= e(site_t('Series', 'Series')) ?></a>
            <a<?= $isActive('sold-works') ?> href="<?= e(url_for('sold-works')) ?>"><?= e(site_t('Constellations', 'Constelaciones')) ?></a>
            <a<?= $isJournalSection ? ' class="is-active" aria-current="page"' : '' ?> href="<?= e(url_for('studio-notes')) ?>"><?= e(site_t('Studio Notes', 'Notas de estudio')) ?></a>
            <a<?= $isActive('artist') ?> href="<?= e(url_for('artist')) ?>"><?= e(site_t('Artist', 'Artista')) ?></a>
            <a class="nav-cta<?= $activeSection === 'contact' ? ' is-active' : '' ?>" <?= $activeSection === 'contact' ? 'aria-current="page"' : '' ?> href="<?= e(url_for('contact')) ?>"><?= e(site_t('Inquire', 'Consultar')) ?></a>
        </nav>
        <form class="site-search" action="<?= e(url_for('artworks/')) ?>" method="get" role="search">
            <label class="sr-only" for="site-search-input"><?= e(site_t('Search paintings', 'Buscar pinturas')) ?></label>
            <input id="site-search-input" name="q" type="search" placeholder="<?= e(site_t('Search works or series', 'Buscar obras o series')) ?>" autocomplete="off">
            <button type="submit"><?= e(site_t('Search', 'Buscar')) ?></button>
        </form>
        <nav class="language-switch" aria-label="<?= e(site_t('Language', 'Idioma')) ?>">
            <a href="<?= e(artist_site_language_url('es')) ?>" lang="es" hreflang="es"<?= $currentLanguage === 'es' ? ' class="is-active" aria-current="true"' : '' ?>>ES</a>
            <span aria-hidden="true">/</span>
            <a href="<?= e(artist_site_language_url('en')) ?>" lang="en" hreflang="en"<?= $currentLanguage === 'en' ? ' class="is-active" aria-current="true"' : '' ?>>EN</a>
        </nav>
    </div>
</header>
<main>
