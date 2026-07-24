<?php
declare(strict_types=1);

function run_world_mother_multi_reference_regression_tests(): void
{
    TestHarness::group('World mother multi-reference');

    $engine = (new ReflectionClass(MockupCombinationEngine::class))->newInstanceWithoutConstructor();
    $selector = new ReflectionMethod(MockupCombinationEngine::class, 'selectWorldMotherImagesFromCategory');
    $singleSelector = new ReflectionMethod(MockupCombinationEngine::class, 'selectWorldMotherImageFromCategory');
    $worldImages = [
        'atelier_family' => [
            ['relative_path' => 'storage/world_mothers/atelier_family/01.jpg', 'file_name' => '01.jpg'],
            ['relative_path' => 'storage/world_mothers/atelier_family/02.jpg', 'file_name' => '02.jpg'],
            ['relative_path' => 'storage/world_mothers/atelier_family/03.jpg', 'file_name' => '03.jpg'],
            ['relative_path' => 'storage/world_mothers/atelier_family/04.jpg', 'file_name' => '04.jpg'],
        ],
    ];

    $firstPair = $selector->invoke($engine, 'atelier_family', $worldImages, 'diagonal_estudio_moderno', 1, 77, 0);
    $repeatedPair = $selector->invoke($engine, 'atelier_family', $worldImages, 'diagonal_estudio_moderno', 1, 77, 0);
    TestHarness::assertSame(2, count($firstPair), 'una categoria con varias imagenes aporta exactamente dos referencias');
    TestHarness::assertTrue(
        (string)$firstPair[0]['relative_path'] !== (string)$firstPair[1]['relative_path'],
        'las dos referencias de un mockup son distintas'
    );
    TestHarness::assertSame($firstPair, $repeatedPair, 'el par conserva la rotacion estable para la misma obra y combinacion');

    $shiftedPair = $selector->invoke($engine, 'atelier_family', $worldImages, 'diagonal_estudio_moderno', 1, 77, 1);
    $firstPairKey = array_map(static fn (array $image): string => (string)$image['relative_path'], $firstPair);
    $shiftedPairKey = array_map(static fn (array $image): string => (string)$image['relative_path'], $shiftedPair);
    sort($firstPairKey);
    sort($shiftedPairKey);
    TestHarness::assertTrue(
        $firstPairKey !== $shiftedPairKey,
        'cambiar la variante selecciona otro par valido en vez de repetir el actual'
    );

    $diversity = new SceneReferenceDiversityService();
    $largePool = [];
    for ($number = 1; $number <= 8; $number++) {
        $largePool[] = [
            'world_mother_id' => 'large_family/' . $number,
            'relative_path' => 'storage/world_mothers/large_family/' . $number . '.jpg',
            'file_name' => $number . '.jpg',
        ];
    }
    $plan = $diversity->buildPlan($largePool, 'large_family', 77, 4);
    $basePaths = [];
    $basePairKeys = [];
    foreach (range(1, 4) as $combinationIndex) {
        $pair = (array)($plan['pair_options'][$combinationIndex][0] ?? []);
        $paths = array_map(static fn (array $image): string => (string)$image['relative_path'], $pair);
        $basePaths = array_merge($basePaths, $paths);
        sort($paths);
        $basePairKeys[] = implode('|', $paths);
    }
    TestHarness::assertSame(8, count(array_unique($basePaths)), 'cuatro tarjetas usan ocho referencias sin solaparse cuando la carpeta lo permite');
    TestHarness::assertSame(4, count(array_unique($basePairKeys)), 'el tablero no repite pares exactos');

    $groupedPool = [
        ['world_mother_id' => 'grouped/a', 'relative_path' => 'grouped/a.jpg', 'scene_similarity_group' => 'same-room'],
        ['world_mother_id' => 'grouped/b', 'relative_path' => 'grouped/b.jpg', 'scene_similarity_group' => 'same-room'],
        ['world_mother_id' => 'grouped/c', 'relative_path' => 'grouped/c.jpg', 'scene_similarity_group' => 'different-view'],
    ];
    $groupedPlan = $diversity->buildPlan($groupedPool, 'grouped', 9, 1);
    $groupedPair = (array)($groupedPlan['pair_options'][1][0] ?? []);
    $groupNames = array_map(static fn (array $image): string => (string)($image['scene_similarity_group'] ?? ''), $groupedPair);
    TestHarness::assertTrue(count(array_unique($groupNames)) === count($groupNames), 'dos referencias del mismo grupo editorial nunca forman un par');

    $legacySingle = $singleSelector->invoke($engine, 'atelier_family', $worldImages, 'diagonal_estudio_moderno', 1, 77, 0);
    TestHarness::assertSame(
        (string)$firstPair[0]['relative_path'],
        (string)$legacySingle['relative_path'],
        'la seleccion escalar compatible conserva la primera referencia como ancla'
    );

    $oneImage = ['single_family' => [$worldImages['atelier_family'][0]]];
    $singlePair = $selector->invoke($engine, 'single_family', $oneImage, '', 1, 77, 0);
    TestHarness::assertSame(1, count($singlePair), 'una carpeta con una sola imagen continua enviando una referencia');

    $platformRoot = dirname(__DIR__, 2);
    $engineSource = (string)file_get_contents($platformRoot . '/app/Services/MockupCombinationEngine.php');
    $workerSource = (string)file_get_contents($platformRoot . '/app/Services/MockupGenerationWorker.php');
    $generatorSource = (string)file_get_contents($platformRoot . '/app/Services/GeminiMockupGenerator.php');
    $reviewSource = (string)file_get_contents($platformRoot . '/mockup_combinations_review.php');
    $mediaSource = (string)file_get_contents($platformRoot . '/world_mother_media.php');
    TestHarness::assertContains(
        "'world_mother_reference_images' => array_values(\$worldMotherReferences)",
        $engineSource,
        'la combinacion persiste el par completo ademas del ancla escalar'
    );
    TestHarness::assertContains(
        "'world_mother_reference_paths' => \$worldMotherPaths",
        $workerSource,
        'el worker entrega las referencias resueltas al generador'
    );
    TestHarness::assertContains(
        'SCENE REFERENCE FAMILY CONTRACT',
        $generatorSource,
        'Gemini recibe el contrato local de familia visual'
    );
    TestHarness::assertContains(
        'absolute authority over the final viewpoint, framing, perspective, and composition',
        $generatorSource,
        'el contrato mantiene autoridad absoluta de la camara'
    );
    TestHarness::assertContains(
        'scene-family-images reference-count-',
        $reviewSource,
        'Explore More muestra conjuntamente las referencias reales de la familia'
    );
    TestHarness::assertTrue(
        !str_contains($reviewSource, '<div class="thumb-box root-reference-thumb">'),
        'Explore More ya no repite visualmente la obra raiz dentro de cada combinacion'
    );
    TestHarness::assertTrue(
        !str_contains($reviewSource, 'class="scene-thumb-picker"'),
        'el selector flotante deja de interferir con las tarjetas vecinas'
    );
    TestHarness::assertContains(
        'Try another visual combination',
        $reviewSource,
        'el usuario puede rotar el par con una accion secundaria explicita'
    );
    TestHarness::assertContains(
        'window.history.replaceState',
        $reviewSource,
        'la seleccion conserva su URL sin recargar la pantalla'
    );
    TestHarness::assertContains(
        "generationButton.setAttribute('data-world-mother-variant', String(offset))",
        $reviewSource,
        'la generacion recibe el par elegido en memoria'
    );
    TestHarness::assertContains(
        '<details class="admin-reference-picker">',
        $reviewSource,
        'la seleccion exacta queda plegada y reservada para administracion'
    );
    TestHarness::assertContains(
        'SCENE_REFERENCE_PAIR_OPTIONS',
        $reviewSource,
        'Explore More comparte con el navegador el mismo catalogo de pares diversos que usa el backend'
    );
    TestHarness::assertContains(
        'conflict += 100',
        $reviewSource,
        'el cambio sin recarga evita elegir un par que ya esta visible en otra tarjeta'
    );
    TestHarness::assertContains(
        'data-camera-board-tab=',
        $reviewSource,
        'la pantalla expone los tres tableros como sets de camara visibles'
    );
    TestHarness::assertContains(
        'function switchCameraBoard(',
        $reviewSource,
        'cambiar entre los doce angulos no requiere recargar la pagina'
    );
    TestHarness::assertContains(
        'grid-template-columns: repeat(4, minmax(0, 1fr))',
        $reviewSource,
        'las cuatro camaras del set caben en una sola fila de escritorio'
    );
    TestHarness::assertContains(
        'grid-auto-columns: clamp(198px, 11vw, 220px)',
        $reviewSource,
        'el carrusel de escenas conserva tarjetas editoriales grandes y legibles'
    );
    TestHarness::assertContains(
        'data-preview-urls=',
        $reviewSource,
        'cada direccion conserva alternativas para recuperarse si falla una miniatura'
    );
    TestHarness::assertContains(
        "primaryImage.addEventListener('error'",
        $reviewSource,
        'una miniatura rota prueba automaticamente la siguiente imagen de la misma escena'
    );
    TestHarness::assertContains(
        'world_mother_is_valid_image($thumbPath)',
        $mediaSource,
        'el servidor valida la miniatura antes de reutilizar una copia temporal'
    );
    TestHarness::assertContains(
        '@unlink($thumbPath)',
        $mediaSource,
        'una miniatura temporal invalida se elimina para poder reconstruirse'
    );
    TestHarness::assertContains(
        "'[data-combination-card][data-scene-board=\"' + ACTIVE_SCENE_BOARD + '\"] '",
        $reviewSource,
        'la generacion por lote se limita a las cuatro camaras visibles'
    );
}
