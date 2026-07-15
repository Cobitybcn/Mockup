<?php
declare(strict_types=1);

/**
 * Zona protegida: "Flujo de obra raiz provista por el usuario"
 * (ver docs/AUDITORIA_PROMPTS_MOCKUPS_20260701.md, seccion Zonas protegidas).
 *
 * upload_existing_root.php es un script procedural que ejecuta codigo real
 * (Auth::requireUser(), validacion de metodo POST, insert en BD) apenas se
 * hace `require`. No se puede requerir de forma segura desde un test sin
 * simular una subida real con sesion autenticada, asi que esto es un test
 * de CARACTERIZACION por inspeccion de codigo fuente: verifica que las
 * invariantes criticas sigan presentes textualmente en el archivo, no las
 * ejecuta. Si algun dia se refactoriza la logica a una clase testeable,
 * reemplazar esto por tests reales via Reflection como en root_artwork_test.php.
 */
function run_uploaded_root_regression_tests(): void
{
    TestHarness::group('Obra raiz subida: caracterizacion por inspeccion de codigo (upload_existing_root.php)');

    $path = dirname(__DIR__, 2) . '/upload_existing_root.php';
    TestHarness::assertTrue(is_file($path), 'upload_existing_root.php existe');
    $source = (string)file_get_contents($path);

    TestHarness::assertContains(
        "in_array(\$ext, ['jpg', 'jpeg', 'png', 'webp'], true)",
        $source,
        'la whitelist de extensiones sigue siendo exactamente jpg/jpeg/png/webp'
    );

    TestHarness::assertContains(
        "if (\$width === '' || \$height === '')",
        $source,
        'width y height siguen siendo obligatorios'
    );

    TestHarness::assertContains(
        'INSERT INTO artworks (user_id, job_id, main_file, root_file, status, width, height, depth, unit, created_at, updated_at)',
        $source,
        'el INSERT a artworks conserva las columnas esperadas'
    );

    TestHarness::assertContains(
        "'root_source' => 'uploaded_final'",
        $source,
        "status.json marca root_source='uploaded_final'"
    );
    TestHarness::assertContains(
        "'generation_skipped' => true",
        $source,
        "status.json marca generation_skipped=true (contrato: no se debe regenerar la obra raiz subida)"
    );

    TestHarness::assertContains(
        "header('Location: mockup_combinations_review.php?id=' . \$artworkId . '&scene_select=1&scene_limit=4');",
        $source,
        'el flujo redirige primero al selector de escenas'
    );
    TestHarness::assertTrue(
        !str_contains($source, 'world_mother_category=selected'),
        'la subida no vuelve a apuntar a la carpeta eliminada selected'
    );

    $selectRootSource = (string)file_get_contents(dirname(__DIR__, 2) . '/select_root.php');
    TestHarness::assertTrue(
        !str_contains($selectRootSource, 'world_mother_category=selected'),
        'la seleccion de Root abre el paso de escenas con una categoria real'
    );
    TestHarness::assertContains(
        "&scene_select=1&scene_limit=4",
        $selectRootSource,
        'la seleccion de Root entra en el modo de seleccion antes de generar'
    );

    $artworkNewSource = (string)file_get_contents(dirname(__DIR__, 2) . '/artwork_new.php');
    TestHarness::assertTrue(
        !str_contains($artworkNewSource, 'world_mother_category=selected'),
        'las obras generadas abren el selector sin el marcador selected'
    );
    TestHarness::assertContains(
        '&amp;scene_select=1&amp;scene_limit=4',
        $artworkNewSource,
        'las obras generadas conservan el paso de seleccion de escenas'
    );

    $sceneReviewSource = (string)file_get_contents(dirname(__DIR__, 2) . '/mockup_combinations_review.php');
    TestHarness::assertContains(
        "\$sceneSelectionFlow = !empty(\$_GET['scene_select']);",
        $sceneReviewSource,
        'la revision distingue el selector inicial del modo administrativo antiguo'
    );
    TestHarness::assertContains(
        'startCompactSceneFlow(this)',
        $sceneReviewSource,
        'el selector abre la pantalla compacta de generacion'
    );
    TestHarness::assertContains(
        "'&auto_generate=1&compact=1&scene_limit=' + USER_SCENE_LIMIT",
        $sceneReviewSource,
        'la generacion usa el progreso compacto y cuatro vistas'
    );

    $sceneResultsSource = (string)file_get_contents(dirname(__DIR__, 2) . '/mockup_combination_results.php');
    TestHarness::assertContains(
        "header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');",
        $sceneResultsSource,
        'los resultados privados no quedan congelados después de eliminar la obra'
    );
    TestHarness::assertContains(
        'if (event.persisted)',
        $sceneResultsSource,
        'una pestaña restaurada desde la cache de navegacion vuelve a validar la obra'
    );

    $engine = (new ReflectionClass(MockupCombinationEngine::class))->newInstanceWithoutConstructor();
    $resolver = new ReflectionMethod(MockupCombinationEngine::class, 'resolveWorldMotherCategory');
    $availableScenes = [
        'Patina Surfaces' => [['file_name' => 'patina.jpg']],
        'Quiet Luxury' => [['file_name' => 'quiet.jpg']],
    ];
    TestHarness::assertSame(
        '',
        $resolver->invoke($engine, 'selected', $availableScenes),
        'un enlace antiguo con selected cae en la seleccion de una escena indexada'
    );
    TestHarness::assertSame(
        'Quiet Luxury',
        $resolver->invoke($engine, 'quiet_luxury', $availableScenes),
        'los slugs normalizados de escenas reales siguen siendo compatibles'
    );

    $referenceMode = new ReflectionMethod(MockupCombinationEngine::class, 'cameraReferenceMode');
    foreach ([1, 2, 3] as $boardIndex) {
        foreach ($engine->activeCameraSlots($boardIndex) as $slotId => $slot) {
            TestHarness::assertSame(
                'reconstructed_view',
                $referenceMode->invoke($engine, $slotId),
                "la camara {$slotId} del lote {$boardIndex} reconstruye el contexto en vez de copiar la escena"
            );
            TestHarness::assertContains(
                'WORLD MOTHER ROLE: ENVIRONMENTAL INSPIRATION ONLY',
                WorldMotherCameraAuthorityPolicy::promptBlock($slotId),
                "la camara {$slotId} del lote {$boardIndex} recibe el contrato comun de inspiracion"
            );
        }
    }

    $worldMotherPolicy = WorldMotherCameraAuthorityPolicy::applyToPrompt('SLOT PROMPT', 'diagonal_estudio_moderno');
    TestHarness::assertContains(
        'do not copy the source photo layout',
        $worldMotherPolicy,
        'el contrato final prohibe copiar literalmente el layout de IMAGE 2'
    );
    TestHarness::assertContains(
        'SLOT PROMPT',
        $worldMotherPolicy,
        'el contrato ambiental conserva el prompt especifico de la camara'
    );

    TestHarness::assertContains(
        'Auth::requireUser()',
        $source,
        'la subida sigue requiriendo un usuario autenticado'
    );
}
