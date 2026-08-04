<?php
declare(strict_types=1);

/**
 * Las medidas de una obra se editan desde el encabezado de la ficha y se
 * escriben en la tabla artworks, que es lo que leen la ficha, la seccion
 * Publicacion y el sitio publico.
 */
function run_artwork_dimensions_tests(): void
{
    TestHarness::group('Medidas de obra: edicion en el encabezado');

    $platformRoot = dirname(__DIR__, 2);
    $repositoryRoot = dirname($platformRoot);

    // ————— Formato: el riesgo real es truncar enteros —————
    TestHarness::assertSame('120', ArtworkDimensions::format('120'), "format('120') no puede devolver '12': rtrim a secas se come el cero final");
    TestHarness::assertSame('120', ArtworkDimensions::format('120.00'), 'los decimales vacios no se muestran');
    TestHarness::assertSame('120.5', ArtworkDimensions::format('120.50'), 'los decimales reales se conservan');
    TestHarness::assertSame('', ArtworkDimensions::format(''), 'vacio sigue vacio');

    // ————— Encabezado: sin unidad, el alta fija cm —————
    TestHarness::assertSame('120 × 80 × 3', ArtworkDimensions::headerText('120', '80', '3'), 'el encabezado muestra las tres medidas sin unidad');
    TestHarness::assertSame('120 × 80', ArtworkDimensions::headerText('120', '80', ''), 'la profundidad es opcional');
    TestHarness::assertSame('', ArtworkDimensions::headerText('', '80', '3'), 'sin ancho no hay texto que mostrar');

    // ————— Lectura de lo que escribe el artista —————
    $tolerados = [
        '120 × 80 × 3' => ['120', '80', '3'],
        '120x80x3' => ['120', '80', '3'],
        '120 X 80 x 3 cm' => ['120', '80', '3'],
        '120,5 x 80' => ['120.5', '80', ''],
    ];
    foreach ($tolerados as $entrada => $esperado) {
        $parsed = ArtworkDimensions::parse((string)$entrada);
        TestHarness::assertSame($esperado[0], $parsed['width'], "'{$entrada}': ancho");
        TestHarness::assertSame($esperado[1], $parsed['height'], "'{$entrada}': alto");
        TestHarness::assertSame($esperado[2], $parsed['depth'], "'{$entrada}': profundidad");
    }

    $vacio = ArtworkDimensions::parse('');
    TestHarness::assertSame('', $vacio['width'], 'vaciar el campo borra las medidas en vez de fallar');

    // ————— Lo que no se acepta —————
    $rechazados = [
        '120' => 'un solo numero no alcanza',
        'abc x 80' => 'texto que no es numero',
        '0 x 80' => 'cero no es una medida',
        '-5 x 80' => 'negativos no',
        '9999 x 80' => 'un digito de mas queda fuera del tope',
        '1 x 2 x 3 x 4' => 'cuatro medidas no existen',
    ];
    foreach ($rechazados as $entrada => $motivo) {
        $lanzo = false;
        try {
            ArtworkDimensions::parse((string)$entrada);
        } catch (InvalidArgumentException) {
            $lanzo = true;
        }
        TestHarness::assertTrue($lanzo, "'{$entrada}' se rechaza con un mensaje util ({$motivo})");
    }

    // ————— La ficha lee la tabla, no el .meta.json del disco —————
    $artworkSource = (string)file_get_contents($platformRoot . '/artwork.php');
    TestHarness::assertContains(
        '$pickMeasure',
        $artworkSource,
        'la ficha resuelve las medidas priorizando la tabla artworks'
    );
    TestHarness::assertTrue(
        !str_contains($artworkSource, "\$width = \$measurement['width'] ?? \$artwork['width']"),
        'el .meta.json ya no tiene precedencia: es una foto del momento de la generacion y no se reescribe al corregir medidas'
    );
    TestHarness::assertContains(
        'data-artwork-dimensions',
        $artworkSource,
        'el encabezado expone las medidas como campo editable, junto al titulo'
    );

    // ————— El guardado automatico, igual que el del titulo —————
    $editorScript = (string)file_get_contents($platformRoot . '/bilingual-editorial.js');
    TestHarness::assertContains('save_dimensions', $editorScript, 'las medidas se guardan al salir del campo');
    TestHarness::assertContains('savedDimensions', $editorScript, 'un error devuelve el valor anterior en vez de dejar basura');
    $endpointSource = (string)file_get_contents($platformRoot . '/bilingual_editorial.php');
    TestHarness::assertContains('ArtworkDimensions::save', $endpointSource, 'el endpoint delega en la unica puerta de escritura');

    // ————— Sitio publico: pulgadas primero —————
    $siteSource = (string)file_get_contents($repositoryRoot . '/artist-site/index.php');
    TestHarness::assertContains(
        "return \$in . ' / ' . \$cm;",
        $siteSource,
        'el sitio muestra pulgadas primero: su mercado es el estadounidense'
    );
}
