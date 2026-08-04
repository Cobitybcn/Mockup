<?php
declare(strict_types=1);

/**
 * Las afinidades declaradas por el artista: nombre y fundamento, una linea por
 * artista. El mismo ejemplo se verifica en la suite de la plataforma
 * (platform/tests/regression/saatchi_listing_generation_test.php): si un lado
 * cambia de formato, el otro test lo delata.
 */

require dirname(__DIR__) . '/inc/AppArtistReferences.php';

// El texto real que escribio el artista, con sus comas y sus dos puntos dentro
// del fundamento: es lo que rompia el separado por comas que habia antes.
$raw = "Mark Rothko: los grandes campos cromáticos crean una atmósfera envolvente, mientras que las formas reducidas intensifican el peso emocional del color y el espacio.\n"
    . "Barnett Newman: intervenciones lineales mínimas organizan amplios campos de color, convirtiendo divisiones simples en acontecimientos espaciales y perceptivos.\n"
    . "\n"
    . "Richard Diebenkorn: los planos abstractos conservan una sensación de paisaje, con líneas y zonas cromáticas que sugieren horizontes, distancias y estructuras territoriales.\n"
    . "Paul Klee: pequeñas presencias geométricas funcionan como signos o arquitecturas primitivas, introduciendo escala, ritmo y ambigüedad simbólica.\n"
    . "Nicolas de Staël: bloques compactos de color conservan rastros de paisaje y arquitectura, equilibrando abstracción y tensión espacial reconocible.";

$parsed = AppArtistReferences::parse($raw);
$names = array_map(static fn (array $r): string => $r['name'], $parsed);

$index = (string)file_get_contents(dirname(__DIR__) . '/index.php');

// El campo admite un bloque por idioma. El artista escribe los fundamentos en
// los dos y el sitio muestra el que corresponde a la pagina.
$bilingue = "[ES]\n"
    . "Mark Rothko: los grandes campos cromáticos crean una atmósfera envolvente.\n"
    . "Barnett Newman: intervenciones lineales mínimas organizan amplios campos.\n"
    . "\n"
    . "[EN]\n"
    . "Mark Rothko: large colour fields create an enveloping atmosphere.\n"
    . "Barnett Newman: minimal linear interventions organize wide fields.";

$es = AppArtistReferences::parse($bilingue, 'es');
$en = AppArtistReferences::parse($bilingue, 'en');
$sinIdioma = AppArtistReferences::parse($bilingue);
$idiomaAusente = AppArtistReferences::parse($bilingue, 'fr');

$checks = [
    [count($es) === 2 && count($en) === 2, 'each language block is read on its own'],
    [str_starts_with($es[0]['rationale'], 'los grandes campos'), 'the Spanish page gets the Spanish rationale'],
    [str_starts_with($en[0]['rationale'], 'large colour fields'), 'the English page gets the English one'],
    [$es[0]['name'] === 'Mark Rothko' && $en[0]['name'] === 'Mark Rothko', 'the name is the same in both: it is not translated'],
    [count($sinIdioma) === 2 && $sinIdioma === $es, 'with no language asked, the first block answers'],
    [$idiomaAusente === $es, 'a language with no block of its own falls back to the first, instead of showing nothing'],
    [!in_array('[ES]', array_column($es, 'name'), true) && !in_array('[EN]', array_column($en, 'name'), true),
        'the block headers are never read as artist names'],

    [count($parsed) === 5, 'the five declared affinities are read, and the blank line is not one of them'],
    [$names === ['Mark Rothko', 'Barnett Newman', 'Richard Diebenkorn', 'Paul Klee', 'Nicolas de Staël'],
        'every name is read whole, accents included'],
    [str_starts_with($parsed[1]['rationale'], 'intervenciones lineales mínimas'),
        'the rationale keeps its own commas instead of being split on them'],
    [str_ends_with($parsed[0]['rationale'], 'del color y el espacio.'),
        'the rationale is not truncated'],
    [AppArtistReferences::parse('')  === [], 'an empty field declares no affinity at all'],
    [AppArtistReferences::parse('Mark Rothko') === [['name' => 'Mark Rothko', 'rationale' => '']],
        'a bare name without rationale is still a valid affinity'],
    [str_contains($index, "AppArtistReferences::parse((string)(\$profile['reference_artists'] ?? ''), artist_site_language())"),
        'the artist page reads the affinities in the page language'],
    [str_contains($index, 'id="influences"'), 'the artist page publishes them as their own section'],
    [substr_count($index, 'AppArtistReferences::parse') === 1,
        'affinities are published on the artist page only: repeating them on every artwork would be duplicate content'],
];

foreach ($checks as [$passed, $message]) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$message}.\n");
        exit(1);
    }
}
