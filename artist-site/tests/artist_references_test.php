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

$checks = [
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
    [str_contains($index, "AppArtistReferences::parse((string)(\$profile['reference_artists'] ?? ''))"),
        'the artist page reads the affinities from the profile'],
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
