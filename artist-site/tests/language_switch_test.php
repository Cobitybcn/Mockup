<?php
declare(strict_types=1);

$_GET['lang'] = 'es';
$_COOKIE = [];
$_SERVER['REQUEST_URI'] = '/artist-site/series/genesis/?q=obra&lang=en#texto';
$_SERVER['SCRIPT_NAME'] = '/artist-site/index.php';

require dirname(__DIR__) . '/inc/functions.php';

if (artist_site_language() !== 'es' || site_t('English', 'Español') !== 'Español') {
    fwrite(STDERR, "FAIL: the requested Spanish language is not selected.\n");
    exit(1);
}

$englishUrl = artist_site_language_url('en');
if ($englishUrl !== '/artist-site/series/genesis/?q=obra&lang=en#texto') {
    fwrite(STDERR, "FAIL: the language switch does not preserve the current route, query or fragment.\n");
    exit(1);
}

$spanishUrl = artist_site_url_with_language('/artist-site/artworks/?q=strata', 'es');
if ($spanishUrl !== '/artist-site/artworks/?q=strata&lang=es') {
    fwrite(STDERR, "FAIL: the Spanish language URL is not canonicalized correctly.\n");
    exit(1);
}

echo "PASS: public ES/EN selection and localized URLs.\n";
