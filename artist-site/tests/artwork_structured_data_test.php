<?php
declare(strict_types=1);

/**
 * Los datos estructurados de la obra publicada.
 *
 * Es el unico canal de vocabulario que los buscadores leen: el
 * <meta name="keywords"> lo ignoran desde 2009 y el buscador interno del sitio
 * no lo mira. Lo que no viaje aca, no viaja.
 *
 * Todo sale de datos que ya estan en la base: este bloque no redacta nada.
 */

require dirname(__DIR__) . '/inc/AppArtistReferences.php';

$index = (string)file_get_contents(dirname(__DIR__) . '/index.php');
$footer = (string)file_get_contents(dirname(__DIR__) . '/inc/footer.php');

// —— Stubs minimos: solo se ejerce la funcion que arma el esquema ——
function artist_site_language(): string { return 'en'; }
function artist_site_url_with_language(string $url, string $language): string { return $url; }
function site_absolute_asset_url(string $path, string $base): string { return $base . $path; }
function app_publication_media_url(array $artwork, string $file, int $width = 0): string { return '/media/' . $file; }
function app_series_catalog(): ?object
{
    return new class {
        public function all(): array
        {
            return [['id' => 71, 'title' => 'STRATA', 'slug' => 'strata-series']];
        }
    };
}

// Se extraen del index las dos funciones bajo prueba, sin arrastrar el resto.
foreach (['published_artwork_schema', 'published_artwork_series'] as $funcion) {
    $inicio = strpos($index, 'function ' . $funcion . '(');
    if ($inicio === false) {
        fwrite(STDERR, "FAIL: {$funcion}() is missing from the site.\n");
        exit(1);
    }
    $llaves = 0;
    $fin = $inicio;
    $abrio = false;
    for ($i = $inicio, $n = strlen($index); $i < $n; $i++) {
        if ($index[$i] === '{') { $llaves++; $abrio = true; }
        if ($index[$i] === '}') { $llaves--; }
        if ($abrio && $llaves === 0) { $fin = $i; break; }
    }
    eval(substr($index, $inicio, $fin - $inicio + 1));
}

$GLOBALS['site'] = ['url' => 'https://example.com', 'name' => 'Maurizio Valch'];
$site = $GLOBALS['site'];

$artwork = [
    'title' => 'STRATA IV — DECLIVIS',
    'slug' => 'strata-iv-declivis',
    'medium' => 'Acrylic and oil on canvas',
    'artwork_year' => '2026',
    'width' => '80',
    'height' => '120',
    'depth' => '',
    'series' => 'STRATA',
    'series_id' => 71,
    'canonical_artwork_id' => 10100,
    // Las claves que trae realmente el catalogo publicado. Antes este fixture
    // usaba 'keywords'/'tags' —una suposicion mia— y por eso el test pasaba
    // mientras la pagina real salia sin keywords.
    'artwork_keywords' => 'Deep Red Field, Fine Incisions, Deep Red Field',
    'artwork_tags' => 'Painting, Crimson Red',
];

$schema = published_artwork_schema($site, $artwork, 'declivis.jpg', 'A deep red field crossed by white incisions.', null);

$checks = [
    [($schema['@type'] ?? '') === 'VisualArtwork', 'the artwork is typed as VisualArtwork'],
    [($schema['artform'] ?? '') === 'Painting', 'artform travels: it existed in the legacy block and was lost in the published one'],

    // —— Keywords: el canal vivo ——
    [isset($schema['keywords']), 'the discovery vocabulary travels in keywords, the field search engines actually read'],
    [str_contains((string)$schema['keywords'], 'Deep Red Field'), 'the search terms of the artwork travel'],
    [str_contains((string)$schema['keywords'], 'Crimson Red'), 'the catalogue tags travel too'],
    [substr_count((string)$schema['keywords'], 'Deep Red Field') === 1, 'a repeated term is not sent twice'],

    // —— Medidas: dato de busqueda real de una obra fisica ——
    [(($schema['width']['value'] ?? 0.0) === 80.0) && (($schema['width']['unitCode'] ?? '') === 'CMT'),
        'width travels as a quantity with its unit'],
    [($schema['height']['value'] ?? 0.0) === 120.0, 'height travels'],
    [!isset($schema['depth']), 'a dimension the artwork does not declare is not invented'],

    // —— La serie ——
    [($schema['isPartOf']['name'] ?? '') === 'STRATA', 'the artwork declares the series it belongs to'],
    [str_contains((string)($schema['isPartOf']['url'] ?? ''), '/series/strata-series/'),
        'and links to the published series page, so a search engine sees them as one body of work'],

    // —— Nada inventado ——
    [!isset($schema['offers']), 'without a store offer no availability is claimed'],
];

// —— El vocabulario del listing NO llega al sitio ——
// "Quiet Solemnity" o "campo de rojo profundo" son lenguaje de obra: nadie los
// escribe en un buscador. Sirven en Saatchi, donde ese campo alimenta el
// buscador de la plataforma y donde las palabras de categoria estan vedadas
// porque el formulario ya las tiene. Aca solo serian ruido.
$conDescubrimiento = published_artwork_schema($site, array_merge($artwork, [
    'artwork_discovery_keywords' => 'Barnett Newman, Quiet Solemnity, Sober Contemplation',
]), 'declivis.jpg', 'x', null);
$vocabulario = (string)($conDescubrimiento['keywords'] ?? '');
$checks[] = [!str_contains($vocabulario, 'Quiet Solemnity'), 'the listing vocabulary does not reach the site: it is artwork language, not search language'];
$checks[] = [str_contains($vocabulario, 'Fine Incisions'), 'and the search terms the artwork already had keep travelling'];

// La otra forma de clave que devuelve el mismo catalogo tiene que dar lo mismo.
$mapeado = published_artwork_schema($site, array_merge(
    array_diff_key($artwork, ['artwork_keywords' => 0, 'artwork_tags' => 0]),
    ['keywords' => 'Deep Red Field', 'tags' => 'Crimson Red']
), 'declivis.jpg', 'x', null);
$checks[] = [str_contains((string)($mapeado['keywords'] ?? ''), 'Deep Red Field'),
    'the mapped catalogue shape also yields keywords: the published page uses one shape and the tests used to assume the other'];

// —— El sitio NO filtra lenguaje de venta ——
// Esa regla es de Saatchi, donde hay doce slots y el formulario ya indexa
// categoria, medio y estilo. En el sitio no hay tope, y las frases de compra o
// con el nombre del artista son las busquedas de mayor intencion que existen.
// Filtrarlas costo seis terminos buenos de dieciseis y fue un error.
$todo = published_artwork_schema($site, array_merge($artwork, [
    'artwork_keywords' => 'Red abstract painting with white lines, Buy original Maurizio Valch art, Textural brutalist painting for sale, Art for modern collectors, Abstracción territorial en venta',
    'artwork_tags' => 'Painting, Crimson Red',
]), 'declivis.jpg', 'x', null);
foreach ([
    'Red abstract painting with white lines',
    'Buy original Maurizio Valch art',
    'Textural brutalist painting for sale',
    'Art for modern collectors',
    'Abstracción territorial en venta',
    'Painting',
    'Crimson Red',
] as $termino) {
    $checks[] = [str_contains((string)$todo['keywords'], $termino),
        "\"{$termino}\" reaches the site: purchase intent belongs where the work is sold"];
}

// —— Una propiedad vacia no viaja ——
$vacios = published_artwork_schema($site, array_merge($artwork, ['medium' => '', 'artwork_year' => '']), 'declivis.jpg', 'x', null);
$checks[] = [!array_key_exists('artMedium', $vacios), 'an unknown medium is omitted instead of published as an empty string'];
$checks[] = [!array_key_exists('dateCreated', $vacios), 'an unknown year is omitted too'];

// —— Oferta disponible y agotada ——
$conPrecio = published_artwork_schema($site, $artwork, 'declivis.jpg', 'x', [
    'is_purchasable' => true, 'price_minor' => 320000, 'currency' => 'eur', 'status' => 'active', 'stock_available' => 1,
]);
$checks[] = [($conPrecio['offers']['price'] ?? '') === '3200.00', 'the price travels in units, not in cents'];
$checks[] = [($conPrecio['offers']['priceCurrency'] ?? '') === 'EUR', 'the currency travels normalised'];
$checks[] = [($conPrecio['offers']['availability'] ?? '') === 'https://schema.org/InStock', 'an available work is announced as such'];

$agotado = published_artwork_schema($site, $artwork, 'declivis.jpg', 'x', [
    'is_purchasable' => false, 'price_minor' => 320000, 'currency' => 'EUR', 'status' => 'sold_out', 'stock_available' => 0,
]);
$checks[] = [($agotado['offers']['availability'] ?? '') === 'https://schema.org/SoldOut', 'a sold work is not offered as available'];
$checks[] = [!isset($agotado['offers']['price']), 'a sold work does not publish a price'];

// —— Sin serie ——
$sinSerie = published_artwork_schema($site, array_merge($artwork, ['series' => '', 'series_id' => 0]), 'x.jpg', 'x', null);
$checks[] = [!isset($sinSerie['isPartOf']), 'an artwork with no series does not link to one'];

// —— El artista como entidad ——
$checks[] = [!str_contains($footer, "'@type' => 'VisualArtist'"),
    'the artist is not typed as VisualArtist: that type does not exist in schema.org and the whole block gets discarded'];
$checks[] = [str_contains($footer, "'@type' => 'Person'"), 'the artist is typed as Person'];

// —— La imagen principal de la pagina de compra usa el alt generado ——
$checks[] = [str_contains($index, "e((\$artwork['artwork_alt'] ?? '') ?: (string)\$artwork['title'])"),
    'the acquisition page uses the generated alt text instead of only the title'];

foreach ($checks as [$passed, $message]) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$message}.\n");
        exit(1);
    }
}
