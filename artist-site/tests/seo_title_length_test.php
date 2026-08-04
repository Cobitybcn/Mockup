<?php
declare(strict_types=1);

/**
 * El titulo que se publica tiene que entrar en lo que un buscador muestra.
 *
 * Medido en produccion el 2026-08-04: 15 de las 21 obras superaban los 60
 * caracteres, y lo que se cortaba era el final — el nombre del artista, que es
 * la busqueda de mayor intencion que existe para un artista.
 */

$index = (string)file_get_contents(dirname(__DIR__) . '/index.php');

$inicio = strpos($index, 'function published_seo_title(');
if ($inicio === false) {
    fwrite(STDERR, "FAIL: published_seo_title() is missing from the site.\n");
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

$obra = 'STRATA IV — DECLIVIS';
$artista = 'Maurizio Valch';

// Los dos titulos reales de DECLIVIS, tal como estan hoy en produccion.
$largoEn = 'STRATA IV — DECLIVIS | Contemporary Abstract Painting | Maurizio Valch';
$largoEs = 'STRATA IV — DECLIVIS | Pintura Abstracta Contemporánea | Maurizio Valch';

$en = published_seo_title($largoEn, $obra, $artista);
$es = published_seo_title($largoEs, $obra, $artista);

// Una categoria distintiva no se sacrifica mientras entre.
$territorial = published_seo_title('STRATA XIII — FISSURA | Territorial Abstract Painting | Maurizio Valch', 'STRATA XIII — FISSURA', $artista);
$minimalista = published_seo_title('COACTUS | Contemporary Minimalist Abstract Painting | Maurizio Valch', 'COACTUS', $artista);

// Un titulo de obra tan largo que no deja lugar a nada mas.
$obraEnorme = str_repeat('A', 58);
$imposible = published_seo_title($obraEnorme . ' | Abstract Painting | ' . $artista, $obraEnorme, $artista);

$corto = 'DECLIVIS | Abstract Painting | Maurizio Valch';

$checks = [
    [mb_strlen($largoEn) === 70, 'the current English title really is 70 characters, over the visible limit'],
    [mb_strlen($en) <= 60, 'the published English title fits within what a search engine shows'],
    [str_contains($en, $artista), 'the artist name survives: it was the part being cut off'],
    [str_contains($en, $obra), 'the artwork title survives whole: it is identity and is never abbreviated'],
    [str_contains($en, 'Abstract Painting'), 'the category still says what the work is'],
    [!str_contains($en, 'Contemporary'), 'the generic word is the one dropped: it competes with everything and distinguishes nothing'],

    [mb_strlen($es) <= 60, 'the Spanish title fits too'],
    [str_contains($es, $artista) && str_contains($es, 'Pintura Abstracta'), 'and keeps the artist and the category in Spanish'],
    [!str_contains($es, 'Contemporánea'), 'dropping the generic word works with accents'],

    // "Territorial" es vocabulario curatorial: describe la obra pero nadie lo
    // tipea. Entre eso y "Abstract Painting" —que si se busca— gana lo que trae
    // busquedas. La palabra distintiva sigue viva en las keywords y en el texto.
    [str_contains($territorial, 'Abstract Painting'), 'when something must go, the searchable core survives over the curatorial modifier'],
    [!str_contains($territorial, 'Territorial'), 'the curatorial word is the one dropped: nobody types it into a search box'],
    [mb_strlen($territorial) <= 60, 'and it fits'],
    [str_contains($minimalista, 'Minimalist') && mb_strlen($minimalista) <= 60, 'Minimalist survives while Contemporary goes'],

    [$imposible === $obraEnorme, 'when nothing else fits, the artwork title travels alone rather than being cut'],
    [published_seo_title($corto, 'DECLIVIS', $artista) === $corto, 'a title that already fits is left exactly as it is'],
    [published_seo_title('', 'DECLIVIS', $artista) === '', 'an empty title stays empty so the caller can fall back'],

    [str_contains($index, 'published_seo_title('), 'the artwork page uses it'],
];

foreach ($checks as [$passed, $message]) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$message}.\n");
        exit(1);
    }
}
