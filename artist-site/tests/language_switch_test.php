<?php
declare(strict_types=1);

$_GET['lang'] = 'es';
$_COOKIE = [];
$_SERVER['REQUEST_URI'] = '/artist-site/es/series/genesis/?q=obra#texto';
$_SERVER['SCRIPT_NAME'] = '/artist-site/index.php';

require dirname(__DIR__) . '/inc/functions.php';

if (artist_site_language() !== 'es' || site_t('English', 'Español') !== 'Español') {
    fwrite(STDERR, "FAIL: the requested Spanish language is not selected.\n");
    exit(1);
}

$englishUrl = artist_site_language_url('en');
if ($englishUrl !== '/artist-site/en/series/genesis/?q=obra#texto') {
    fwrite(STDERR, "FAIL: the language switch does not preserve the current route, query or fragment.\n");
    exit(1);
}

$spanishUrl = artist_site_url_with_language('/artist-site/artworks/?q=strata&lang=en', 'es');
if ($spanishUrl !== '/artist-site/es/artworks/?q=strata') {
    fwrite(STDERR, "FAIL: the Spanish language URL is not canonicalized correctly.\n");
    exit(1);
}

artist_site_set_language_urls([
    'en' => '/artist-site/artworks/test-work/mockups/test-work-in-modern-living-room',
    'es' => '/artist-site/artworks/test-work/mockups/test-work-en-salon-moderno',
]);
if (artist_site_language_url('en') !== '/artist-site/en/artworks/test-work/mockups/test-work-in-modern-living-room'
    || artist_site_language_url('es') !== '/artist-site/es/artworks/test-work/mockups/test-work-en-salon-moderno') {
    fwrite(STDERR, "FAIL: mockup language switching does not use each language-specific slug.\n");
    exit(1);
}
if (url_for('series/emersio') !== '/artist-site/es/series/emersio'
    || asset_url('assets/css/styles.css') !== '/artist-site/assets/css/styles.css'
    || url_for('admin') !== '/artist-site/admin') {
    fwrite(STDERR, "FAIL: public navigation is not localized or internal resources were incorrectly prefixed.\n");
    exit(1);
}

echo "PASS: public ES/EN selection and localized URLs.\n";
