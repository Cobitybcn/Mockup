<?php
declare(strict_types=1);

/**
 * "Influencias del artista" en la pagina de cada obra.
 *
 * La seccion muestra el analisis UNICO de esa obra —que toma de cada afinidad
 * declarada que sostiene— en el idioma de la pagina. Nunca repite el parrafo
 * del perfil (contenido duplicado) y una obra sin afinidades sostenidas no
 * lleva seccion: la seleccion es la regla. El texto llega desde la copia
 * publicada: publicar es aprobar, tambien aca.
 */

$root = dirname(__DIR__);
$index = (string)file_get_contents($root . '/index.php');
$catalog = (string)file_get_contents($root . '/inc/AppPublishedCatalog.php');

$checks = [
    // El catalogo publicado expone el campo por idioma, vacio cuando no existe.
    ['influences_analysis', $catalog, 'AppPublishedCatalog expone influences_analysis por idioma'],
    // La pagina de obra pinta la seccion solo cuando hay texto.
    ["artwork['influences_analysis']", $index, 'la pagina de obra lee el analisis de ESTA obra'],
    ['artwork-artist-influences', $index, 'la seccion tiene su bloque propio'],
    ["site_t('Artist influences', 'Influencias del artista')", $index, 'el titulo existe en los dos idiomas'],
    // El nombre viaja hacia las Afinidades del artista: el racimo se navega.
    ["#influences", $index, 'la seccion enlaza a las Afinidades de la pagina del artista'],
];
foreach ($checks as [$needle, $haystack, $label]) {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$label} (no se encontro: {$needle})\n");
        exit(1);
    }
}

// La seccion es condicional: sin texto no hay seccion. La guardia debe existir
// textual, no inferirse.
if (!preg_match('/\$artistInfluences\s*!==\s*\'\'/', $index)) {
    fwrite(STDERR, "FAIL: la seccion debe pintarse solo cuando el analisis tiene texto: vacio es valido y no lleva seccion.\n");
    exit(1);
}

// Y tiene que vivir en la plantilla que REALMENTE sirve la pagina de obra:
// render_published_artwork. La primera version la puso en otra plantilla del
// mismo archivo y la seccion quedo invisible con los datos perfectos.
$inicio = (int)strpos($index, 'function render_published_artwork');
$fin = (int)strpos($index, 'function ', $inicio + 10);
$plantillaViva = substr($index, $inicio, max(0, $fin - $inicio));
if (!str_contains($plantillaViva, 'artwork-artist-influences')) {
    fwrite(STDERR, "FAIL: la seccion debe estar dentro de render_published_artwork, la plantilla que sirve la pagina publicada.\n");
    exit(1);
}

// En el estado vacio del castellano el campo tambien se limpia: una pagina en
// espanol sin traduccion no hereda el ingles.
if (substr_count($catalog, "\$row['influences_analysis'] = '';") < 1) {
    fwrite(STDERR, "FAIL: el estado sin traduccion debe dejar el campo vacio, nunca heredar otro idioma.\n");
    exit(1);
}

echo "PASS: la pagina de obra publica su analisis de influencias por idioma, solo cuando existe.\n";
