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
        'Explore new scenes',
        $sceneResultsSource,
        'los resultados siempre ofrecen volver al selector de combinaciones'
    );
    TestHarness::assertContains(
        'next-batch-scene-name',
        $sceneResultsSource,
        'la continuacion muestra solamente el nombre de la escena activa sobre la accion'
    );
    TestHarness::assertContains(
        'Create 4 more views',
        $sceneResultsSource,
        'los resultados permiten continuar con el siguiente lote de la misma escena'
    );
    TestHarness::assertContains(
        'if ($nextSceneBoardHasScenes)',
        $sceneResultsSource,
        'continuar el lote no depende de conservar el parametro compacto en la URL'
    );
    TestHarness::assertContains(
        'strcasecmp($rowWorldMotherCategory, $selectedWorldMotherCategory)',
        $sceneResultsSource,
        'el siguiente lote se calcula dentro del contexto de la escena seleccionada'
    );
    TestHarness::assertContains(
        'result-image-toolbar',
        $sceneResultsSource,
        'favorito, regeneracion y borrado comparten controles alineados sobre la imagen'
    );
    TestHarness::assertContains(
        'class="result-scene-badge"',
        $sceneResultsSource,
        'cada resultado identifica de forma visible la escena usada, incluso al mezclar generaciones'
    );
    TestHarness::assertContains(
        "'variant_label' => 'Set ' . \$rowSceneBoardIndex . (\$boardOrder > 0 ? ' · View ' . \$boardOrder : '')",
        $sceneResultsSource,
        'los conjuntos y sus vistas usan terminos comprensibles para el usuario'
    );
    TestHarness::assertContains(
        "?> results</span>",
        $sceneResultsSource,
        'el contador conserva el mismo idioma ingles que el encabezado de resultados'
    );
    TestHarness::assertContains(
        '.batch-filter button {',
        $sceneResultsSource,
        'el filtro de sets mantiene estilos propios en lugar de heredar el boton global'
    );
    TestHarness::assertContains(
        'white-space: nowrap;',
        $sceneResultsSource,
        'las opciones del filtro no aumentan su altura al partir el texto en varias lineas'
    );
    TestHarness::assertTrue(
        !str_contains($sceneResultsSource, "'variant_label' => 'Batch '")
            && !str_contains($sceneResultsSource, "' · Posición ' . \$boardOrder"),
        'las tarjetas de resultados ya no muestran batch ni posicion'
    );
    TestHarness::assertContains(
        "\$sceneTitle = 'Scene not recorded';",
        $sceneResultsSource,
        'los resultados antiguos sin metadato explican que la escena no fue registrada'
    );
    TestHarness::assertContains(
        'position: absolute;',
        $sceneResultsSource,
        'los controles flotan sobre el thumbnail sin reservar una barra adicional'
    );
    TestHarness::assertContains(
        'backdrop-filter: blur(9px) saturate(1.18);',
        $sceneResultsSource,
        'los controles conservan el tratamiento de cristal esmerilado'
    );
    TestHarness::assertContains(
        'class="result-action-icon"',
        $sceneResultsSource,
        'las acciones usan iconos vectoriales centrados en lugar de caracteres tipograficos'
    );
    $mediaControlsSource = (string)file_get_contents(dirname(__DIR__, 2) . '/media-controls.css');
    TestHarness::assertContains(
        '.media-icon-button {',
        $mediaControlsSource,
        'las imagenes y videos reutilizan un unico componente de iconos'
    );
    TestHarness::assertContains(
        'top: 8px !important;',
        $mediaControlsSource,
        'los controles sobre thumbnails comparten una altura superior calibrada'
    );
    $mockupAlbumSource = (string)file_get_contents(dirname(__DIR__, 2) . '/mockups.php');
    TestHarness::assertTrue(
        !str_contains($mockupAlbumSource, '<label class="pinterest-thumb-select"'),
        'Mockup Album ya no superpone el selector de Pinterest sobre las imagenes'
    );
    TestHarness::assertContains(
        'media-thumb-action--right-secondary',
        $mockupAlbumSource,
        'la descarga del album comparte la fila superior con las demas acciones'
    );
    $videosSource = (string)file_get_contents(dirname(__DIR__, 2) . '/videos.php');
    TestHarness::assertContains(
        'videos-play media-play-control',
        $videosSource,
        'los botones de reproduccion usan el mismo tratamiento de vidrio'
    );
    TestHarness::assertContains(
        'class="media-thumb-action-cluster" aria-label="Acciones del video"',
        $videosSource,
        'editar y descargar video quedan alineados sobre el thumbnail'
    );
    $videosCssSource = (string)file_get_contents(dirname(__DIR__, 2) . '/videos.css');
    TestHarness::assertContains(
        'grid-template-columns: repeat(auto-fill, minmax(260px, 300px));',
        $videosCssSource,
        'videos finales usa fichas estrechas para que el thumbnail sea el elemento principal'
    );
    TestHarness::assertContains(
        'aspect-ratio: 4 / 5;',
        $videosCssSource,
        'el thumbnail de videos finales conserva una proporcion rectangular vertical'
    );
    TestHarness::assertContains(
        '.videos-final-copy > span { display: none; }',
        $videosCssSource,
        'videos finales elimina texto secundario para reducir la ficha al minimo'
    );
    $websiteBoardCssSource = (string)file_get_contents(dirname(__DIR__, 2) . '/website_board.css');
    TestHarness::assertContains(
        'grid-template-columns: repeat(3, minmax(0, 1fr));',
        $websiteBoardCssSource,
        'el catalogo del website dedica mas superficie a cada thumbnail'
    );
    TestHarness::assertContains(
        'height: 292px;',
        $websiteBoardCssSource,
        'las fichas del website muestran una portada mas grande'
    );
    $videoStudioPageSource = (string)file_get_contents(dirname(__DIR__, 2) . '/video.php');
    $videoStudioCssSource = (string)file_get_contents(dirname(__DIR__, 2) . '/video_studio.css');
    TestHarness::assertContains(
        '<span class="vds-catalog-kicker">Reference Catalog</span>',
        $videoStudioPageSource,
        'Video Studio identifica el catalogo de referencias como rotulo secundario'
    );
    TestHarness::assertContains(
        '<h1 id="vds-catalog-title">Video Lab</h1>',
        $videoStudioPageSource,
        'Video Studio usa como titulo principal el nombre real de la seccion'
    );
    TestHarness::assertContains(
        "font-family: Georgia, 'Times New Roman', serif;",
        $videoStudioCssSource,
        'el catalogo de referencias usa la tipografia editorial de la aplicacion'
    );
    $socialBoardSource = (string)file_get_contents(dirname(__DIR__, 2) . '/social_media_board.php');
    TestHarness::assertContains(
        '<span class="smb-catalog-kicker">Mockup Catalog</span>',
        $socialBoardSource,
        'Social Media identifica el catalogo de mockups como rotulo secundario'
    );
    TestHarness::assertContains(
        '<h2 id="smb-catalog-title">Social Media Board</h2>',
        $socialBoardSource,
        'Social Media usa como titulo principal el nombre real de la seccion'
    );
    $websiteBoardSource = (string)file_get_contents(dirname(__DIR__, 2) . '/website_board.php');
    TestHarness::assertContains(
        '<span class="smb-catalog-kicker">Image Catalog</span>',
        $websiteBoardSource,
        'Website identifica la sincronizacion del catalogo como rotulo secundario'
    );
    TestHarness::assertContains(
        '<h2 id="wbb-source-title">Website Catalog Sync</h2>',
        $websiteBoardSource,
        'Website usa como titulo principal el nombre real de la seccion'
    );
    TestHarness::assertContains(
        '<span class="wbb-board-mark" aria-hidden="true">C</span><h2>Catalog</h2>',
        $websiteBoardSource,
        'Website conserva el mismo idioma tambien en su tablero de catalogo'
    );
    TestHarness::assertContains(
        '.wbb-board-title h2 {',
        $websiteBoardCssSource,
        'los tableros de Website comparten la tipografia editorial de los titulos'
    );
    TestHarness::assertContains(
        'aspect-ratio: 3 / 4;',
        $websiteBoardCssSource,
        'Website usa fichas verticales compactas para dar prioridad al thumbnail'
    );
    TestHarness::assertContains(
        'object-fit: cover;',
        $websiteBoardCssSource,
        'Website muestra las obras a sangre y elimina el marco vacio del thumbnail'
    );
    TestHarness::assertContains(
        'grid-auto-columns: 300px;',
        $mockupAlbumSource,
        'los favoritos del album muestran thumbnails suficientemente grandes'
    );
    TestHarness::assertTrue(
        !str_contains($sceneResultsSource, '>★</button>')
            && !str_contains($sceneResultsSource, '>↻</button>')
            && !str_contains($sceneResultsSource, '>×</button>'),
        'los controles no dependen de glifos descentrados de la fuente'
    );
    TestHarness::assertContains(
        'background: #efe5b8;',
        $sceneResultsSource,
        'la accion secundaria conserva el formato principal con amarillo pastel'
    );
    $deleteResultSource = (string)file_get_contents(dirname(__DIR__, 2) . '/delete_mockup_result.php');
    TestHarness::assertContains(
        'SET mockup_id = NULL, mockup_file = NULL',
        $deleteResultSource,
        'eliminar un resultado desvincula el trabajo historico que podria reconstruir un thumb muerto'
    );
    TestHarness::assertContains(
        'Database::beginWriteTransaction($pdo);',
        $deleteResultSource,
        'la fila visible y su referencia de generacion se eliminan atomicamente'
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
    TestHarness::assertContains(
        'class="series-artwork-thumb" data-series-drag-thumb',
        $seriesSource,
        'Series reutiliza el thumbnail existente como superficie de arrastre'
    );
    $catalogUiSource = (string)file_get_contents(dirname(__DIR__, 2) . '/ui-catalog.css');
    TestHarness::assertContains(
        'grid-auto-columns: clamp(190px, 16vw, 250px)',
        $catalogUiSource,
        'las fichas de Series reutilizan el tamano del catalogo horizontal'
    );
    TestHarness::assertContains(
        "' series-artwork-row--' . series_h(\$cardSeriesTone)",
        $seriesSource,
        'cada artwork hereda la misma familia cromatica de su bloque de serie'
    );
    TestHarness::assertContains(
        '.series-artwork-row--artwork_launch { --series-card-tone: #b77f86; }',
        $catalogUiSource,
        'el fondo de las fichas reutiliza exactamente la paleta de bloques de Series'
    );
    TestHarness::assertContains(
        'background: color-mix(in srgb, var(--series-card-tone) 40%, #fff);',
        $catalogUiSource,
        'los colores de serie conservan contraste suficiente entre grupos de artworks'
    );
    TestHarness::assertContains(
        'background: var(--series-card-tone);',
        $catalogUiSource,
        'cada ficha muestra una cabecera con el color exacto de su serie'
    );
    TestHarness::assertContains(
        'color: #fffaf7;',
        $catalogUiSource,
        'el selector usa texto casi blanco sobre la cabecera de color'
    );
    TestHarness::assertContains(
        'border-color: transparent;',
        $catalogUiSource,
        'el selector de serie no dibuja lineas blancas sobre la cabecera'
    );
    TestHarness::assertContains(
        ".series-artwork-controls {\n  order: -1;",
        $catalogUiSource,
        'el selector de serie aparece arriba de cada ficha sin cambiar de tamano'
    );
    $seriesOrderSource = (string)file_get_contents(dirname(__DIR__, 2) . '/series_artwork_order.js');
    TestHarness::assertContains(
        "handle: '[data-series-drag-thumb]'",
        $seriesOrderSource,
        'el drag and drop solo comienza desde el thumbnail de la ficha'
    );
    TestHarness::assertContains(
        'class="series-artwork-order" data-series-order-position',
        $seriesSource,
        'cada ficha muestra solamente su numero ordinal compacto'
    );
    TestHarness::assertTrue(
        !str_contains($seriesSource, 'class="creation-number-form"')
            && !str_contains($seriesSource, '>Save</button>'),
        'Series elimina el editor manual de Creation ID y su boton Save'
    );
    TestHarness::assertTrue(
        !str_contains($seriesOrderSource, 'window.location.reload()'),
        'un error de orden restaura las fichas sin recargar la pagina'
    );
    $artworksSource = (string)file_get_contents(dirname(__DIR__, 2) . '/root_album.php');
    TestHarness::assertContains(
        'name="series" aria-label="Filter ArtWorks by series"',
        $artworksSource,
        'ArtWorks permite filtrar una serie concreta'
    );
    TestHarness::assertContains(
        'if ($selectedArtworkSeriesId > 0): ?> data-series-order-list',
        $artworksSource,
        'ArtWorks habilita el ordenamiento solamente dentro de una serie filtrada'
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
    TestHarness::assertContains(
        'compact-view-progress',
        $sceneReviewSource,
        'el flujo compacto conserva la progresion visible de cada worker'
    );
    TestHarness::assertTrue(
        !str_contains($sceneReviewSource, 'Stay and watch progress')
            && !str_contains($sceneReviewSource, '>View results</a>'),
        'el progreso elimina las acciones redundantes y conserva una sola salida'
    );
    TestHarness::assertContains(
        'artworkmockups:hide-scene-progress',
        $sceneReviewSource,
        'continue working oculta el progreso y devuelve el control a la pantalla de origen'
    );
    $sceneProgressLayerSource = (string)file_get_contents(dirname(__DIR__, 2) . '/compact_scene_progress_layer.php');
    TestHarness::assertContains(
        'data-compact-scene-progress-minimize',
        $sceneProgressLayerSource,
        'el progreso en segundo plano puede minimizarse sin bloquear la aplicacion'
    );
    TestHarness::assertContains(
        'data-compact-scene-progress-hide',
        $sceneProgressLayerSource,
        'el progreso en segundo plano puede ocultarse'
    );
    TestHarness::assertContains(
        'artworkmockups:scene-progress-complete',
        $sceneReviewSource,
        'el lote avisa visualmente cuando todas las escenas llegan a un estado final'
    );
    TestHarness::assertContains(
        'compact-scene-progress-layer.is-complete',
        $sceneProgressLayerSource,
        'el progreso terminado cambia a un estado visual de exito'
    );
    TestHarness::assertContains(
        'submitArtworkSceneProgress',
        $createScenesSource,
        'la preparacion inicial de la obra permanece dentro de la aplicacion'
    );
    TestHarness::assertTrue(
        !str_contains($sceneProgressLayerSource, 'inset: 0;')
            && !str_contains($sceneProgressLayerSource, 'body.compact-scene-progress-open'),
        'el progreso flotante no vuelve a cubrir ni bloquear toda la aplicacion'
    );
    TestHarness::assertContains(
        'data-compact-scene-launch',
        $sceneResultsSource,
        'continuar un lote conserva la pantalla de resultados como fondo'
    );
    TestHarness::assertContains(
        'pollCompactSceneBatch',
        $sceneReviewSource,
        'la pantalla compacta consulta el estado de cada trabajo en segundo plano'
    );
    TestHarness::assertContains(
        'window.artworkGenerationTracker?.trackJobs(compactBatchJobIds);',
        $sceneReviewSource,
        'los trabajos de un lote se registran juntos para evitar avisos parciales'
    );
    TestHarness::assertContains(
        'body.compact-scene-runner .global-generation-ready',
        $sceneReviewSource,
        'el flujo compacto muestra un unico controlador de progreso'
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
    TestHarness::assertContains(
        "new CustomEvent('artworkmockups:generation-completed'",
        $sidebarSource,
        'el seguimiento global comunica inmediatamente cuando termina una regeneracion'
    );
    TestHarness::assertContains(
        "new CustomEvent('artworkmockups:generation-ready'",
        $sidebarSource,
        'los avisos ya terminados permiten reparar una pantalla de resultados desactualizada'
    );
    TestHarness::assertContains(
        "'replaces_mockup_id'",
        (string)file_get_contents(dirname(__DIR__, 2) . '/mockup_generation_activity.php'),
        'la actividad identifica que miniatura debe actualizar una regeneracion'
    );
    TestHarness::assertContains(
        "window.addEventListener('artworkmockups:generation-completed'",
        $sceneResultsSource,
        'los resultados escuchan la finalizacion de regeneraciones iniciadas en la pagina'
    );
    TestHarness::assertContains(
        'window.location.replace(target);',
        $sceneResultsSource,
        'una regeneracion terminada actualiza automaticamente la pantalla de resultados'
    );
    TestHarness::assertContains(
        "window.addEventListener('artworkmockups:generation-ready'",
        $sceneResultsSource,
        'una regeneracion ya terminada tambien actualiza una pagina que continuaba abierta'
    );
    TestHarness::assertContains(
        "item.kind !== 'generation'",
        $sceneResultsSource,
        'Scene Mockups se actualiza automaticamente cuando termina una nueva tanda visible'
    );
    TestHarness::assertContains(
        "newScenes + ' new scenes ready'",
        $sidebarSource,
        'fuera de resultados el aviso distingue claramente las escenas nuevas'
    );
    TestHarness::assertContains(
        'pendingAfterEvents',
        $sidebarSource,
        'la pantalla de resultados puede consumir el aviso sin mostrar una alerta redundante'
    );
    TestHarness::assertContains(
        'playNewNoticeSound(notices',
        $sidebarSource,
        'la finalizacion reproduce un unico aviso sonoro para la tanda completa'
    );
    TestHarness::assertContains(
        "failed.length > 0 ? 'error' : 'success'",
        $sidebarSource,
        'los fallos usan un tono distinto del aviso de exito'
    );
    TestHarness::assertContains(
        'artworkMockupsGenerationSoundMuted:',
        $sidebarSource,
        'la preferencia de silencio queda guardada para el usuario'
    );
    TestHarness::assertContains(
        'data-global-generation-sound',
        $sidebarSource,
        'el indicador y el aviso final permiten silenciar futuros sonidos'
    );
    TestHarness::assertContains(
        "event.key !== soundMutedKey",
        $sidebarSource,
        'el silencio se sincroniza entre el progreso flotante y la pantalla principal'
    );
    TestHarness::assertContains(
        "'scene_category' => \$category",
        (string)file_get_contents(dirname(__DIR__, 2) . '/mockup_generation_activity.php'),
        'el aviso identifica la familia visual que acaba de terminar'
    );
    TestHarness::assertTrue(
        !str_contains($sceneResultsSource, "button.textContent = 'In background';"),
        'el estado en segundo plano conserva el icono vectorial del control'
    );
    TestHarness::assertContains(
        'trackedActive.length > 0',
        $sidebarSource,
        'el aviso global espera a que termine todo el lote antes de anunciar resultados'
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
