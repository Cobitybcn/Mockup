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

    $legacyArtworkNewSource = (string)file_get_contents(dirname(__DIR__, 2) . '/artwork_new.php');
    TestHarness::assertContains(
        "header('Location: create_scenes.php', true, 302);",
        $legacyArtworkNewSource,
        'la entrada antigua redirige al flujo unificado de Create Art'
    );

    $createScenesSource = (string)file_get_contents(dirname(__DIR__, 2) . '/create_scenes.php');
    TestHarness::assertContains(
        'action="start_generate.php"',
        $createScenesSource,
        'Create Art conserva el formulario de preparacion de obra raiz'
    );
    TestHarness::assertContains(
        'name="user_scene_flow" value="1"',
        $createScenesSource,
        'Create Art enlaza la obra raiz con el selector de escenas'
    );
    TestHarness::assertContains(
        'margin-inline: auto;',
        $createScenesSource,
        'el formulario de escritorio queda centrado tambien en Firefox'
    );

    $rootAlbumSource = (string)file_get_contents(dirname(__DIR__, 2) . '/root_album.php');
    TestHarness::assertContains(
        'href="create_scenes.php">Create Art</a>',
        $rootAlbumSource,
        'ArtWorks abre el flujo unificado de Create Art'
    );
    TestHarness::assertTrue(
        !str_contains($rootAlbumSource, 'href="artwork_new.php"'),
        'ArtWorks no conserva enlaces al formulario antiguo'
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
    TestHarness::assertContains(
        'Explore more combinations',
        $sceneResultsSource,
        'los resultados siempre ofrecen volver al selector de combinaciones'
    );
    TestHarness::assertContains(
        'mockup_combinations_review.php?id=<?= (int)$id ?>&board=<?= (int)$reviewSceneBoardIndex ?>',
        $sceneResultsSource,
        'el selector de nuevas combinaciones conserva la obra activa'
    );

    $groupServiceSource = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/ArtworkGroupService.php');
    TestHarness::assertContains(
        '$this->mergeArtworkSheets(',
        $groupServiceSource,
        'la fusion de grupos consolida tambien la referencia editorial'
    );
    TestHarness::assertContains(
        "COALESCE(status, '') <> 'merged'",
        $groupServiceSource,
        'las fichas editoriales absorbidas no vuelven a crear grupos duplicados'
    );

    $seriesSource = (string)file_get_contents(dirname(__DIR__, 2) . '/series.php');
    TestHarness::assertContains(
        'FROM artwork_groups g',
        $seriesSource,
        'Series presenta una sola tarjeta por obra canonica'
    );
    TestHarness::assertContains(
        'm.artwork_group_id = g.id',
        $seriesSource,
        'Series suma los mockups de todas las vistas de la obra'
    );
    TestHarness::assertContains(
        "g.status = 'active'",
        $seriesSource,
        'Series no vuelve a presentar grupos ya fusionados'
    );

    $artworkSeriesSource = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Support/ArtworkSeries.php');
    TestHarness::assertContains(
        'CASE WHEN g.id IS NOT NULL THEN canonical.series_id ELSE a.series_id END AS effective_series_id',
        $artworkSeriesSource,
        'los mockups de una vista secundaria heredan la serie canonica'
    );

    TestHarness::assertContains(
        "window.artworkGenerationTracker?.trackJobs([jobId]);",
        $sceneReviewSource,
        'una generacion individual queda registrada para avisar cuando termine'
    );
    TestHarness::assertContains(
        "successCount + ' TASKS IN BACKGROUND'",
        $sceneReviewSource,
        'un lote permite seguir navegando mientras se generan sus resultados'
    );
    TestHarness::assertTrue(
        !str_contains($sceneReviewSource, "summary += '\\n\\nOpen results now?';"),
        'la generacion ya no exige confirmar manualmente la apertura de resultados'
    );
    TestHarness::assertContains(
        'is-new-generation',
        $sceneResultsSource,
        'el resultado de una regeneracion individual queda distinguido visualmente'
    );
    TestHarness::assertContains(
        'Fusionar con otra obra',
        $rootAlbumSource,
        'ArtWorks expone la fusion segura de duplicados'
    );
    TestHarness::assertContains(
        'merge_artwork_groups.php',
        $rootAlbumSource,
        'la fusion se confirma mediante un endpoint dedicado'
    );
    $mergeEndpointSource = (string)file_get_contents(dirname(__DIR__, 2) . '/merge_artwork_groups.php');
    TestHarness::assertContains(
        '$service->mergeGroups(',
        $mergeEndpointSource,
        'el endpoint reutiliza la fusion transaccional de grupos'
    );
    TestHarness::assertContains(
        'hash_equals($sessionCsrf, $csrf)',
        $mergeEndpointSource,
        'la fusion exige una confirmacion CSRF valida'
    );
    $sidebarSource = (string)file_get_contents(dirname(__DIR__, 2) . '/sidebar.php');
    TestHarness::assertContains(
        'mockup_generation_activity.php',
        $sidebarSource,
        'el seguimiento de tareas terminadas esta disponible en toda la aplicacion'
    );
    TestHarness::assertContains(
        'window.artworkGenerationTracker',
        $sidebarSource,
        'las pantallas registran trabajos sin iniciar procesos adicionales'
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
