<?php
declare(strict_types=1);

$index = (string)file_get_contents(dirname(__DIR__) . '/index.php');
$header = (string)file_get_contents(dirname(__DIR__) . '/inc/header.php');
$footer = (string)file_get_contents(dirname(__DIR__) . '/inc/footer.php');
$seriesRepository = (string)file_get_contents(dirname(__DIR__) . '/inc/AppPublishedSeriesCatalog.php');

$checks = [
    [str_contains($index, "site_copy('home.meta_title')"), 'home metadata is selected by locale'],
    [str_contains($index, '$publishedSeries = app_series_catalog()?->all() ?? [];'), 'home series come from the published app catalog'],
    [!str_contains($index, 'render_home($site, $series, $artworks)'), 'home no longer receives the legacy series JSON'],
    [str_contains($header, 'og:locale:alternate'), 'Open Graph exposes the alternate locale'],
    [str_contains($footer, "'inLanguage' => \$currentLanguage"), 'global structured data identifies its language'],
    // Se comprueba el hecho, no el espaciado: los bloques de obra y de mockup
    // pasaron de una linea suelta a funciones propias, y la asercion anterior
    // dependia de que no hubiera espacios alrededor de la flecha.
    [substr_count($index, "'inLanguage' => artist_site_language()") >= 1
        && str_contains($index, "'inLanguage' => \$idioma"),
        'artwork and mockup structured data identify their language'],
    [str_contains($seriesRepository, 'Missing published Spanish series translation'), 'missing Spanish series content is logged'],
    [str_contains($seriesRepository, "\$language === 'es' ? ''"), 'Spanish series do not silently reuse English fields'],
];

foreach ($checks as [$passed, $message]) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$message}.\n");
        exit(1);
    }
}

echo "PASS: localized home, explicit translation failures and international SEO contract.\n";
