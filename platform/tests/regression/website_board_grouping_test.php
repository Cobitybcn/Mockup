<?php
declare(strict_types=1);

function run_website_board_grouping_regression_tests(): void
{
    TestHarness::group('Website Publisher: una obra por grupo unificado');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE artworks (
        id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, artwork_group_id INTEGER,
        status TEXT NOT NULL, final_title TEXT NOT NULL, root_file TEXT NOT NULL,
        main_file TEXT NOT NULL, series_id INTEGER, series TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE artwork_groups (
        id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, canonical_artwork_id INTEGER NOT NULL,
        title TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE artwork_series (
        id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, title TEXT NOT NULL,
        header_file TEXT NOT NULL, status TEXT NOT NULL, year_end INTEGER,
        year_start INTEGER, created_at TEXT NOT NULL, published INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE artwork_sheets (
        id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, canonical_artwork_id INTEGER NOT NULL,
        status TEXT NOT NULL, title TEXT NOT NULL, source_image_file TEXT NOT NULL,
        subtitle TEXT NOT NULL DEFAULT '', short_description TEXT NOT NULL DEFAULT '',
        description TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE mockups (
        id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, mockup_file TEXT NOT NULL,
        context_id TEXT NOT NULL, source_artwork_id INTEGER, series_id INTEGER,
        selector_state_json TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE mockup_sheets (
        id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, artwork_sheet_id INTEGER NOT NULL DEFAULT 0, mockup_file TEXT NOT NULL,
        title TEXT NOT NULL, description TEXT NOT NULL, caption TEXT NOT NULL,
        alt_text TEXT NOT NULL, keywords TEXT NOT NULL, tags TEXT NOT NULL DEFAULT '', generated_json TEXT NOT NULL
    )");

    $pdo->exec("INSERT INTO artwork_groups VALUES
        (10,70,101,'Crimson Markers','active','2026-07-15T10:00:00Z','2026-07-19T10:00:00Z'),
        (11,70,104,'Alpha Work','active','2026-07-14T10:00:00Z','2026-07-18T10:00:00Z')");
    $pdo->exec("INSERT INTO artworks VALUES
        (101,70,10,'done','Crimson Markers','crimson-primary.jpg','',1,'Published Series'),
        (102,70,10,'done','Crimson Duplicate','crimson-secondary.jpg','',NULL,''),
        (103,70,NULL,'done','Independent Work','independent.jpg','',NULL,''),
        (104,70,11,'done','Alpha Work','alpha.jpg','',NULL,'')");
    $pdo->exec("INSERT INTO artwork_series VALUES
        (1,70,'Published Series','series-cover.jpg','active',NULL,2026,'2026-07-19T10:00:00Z',1)");
    $pdo->exec("INSERT INTO artwork_sheets VALUES
        (201,70,101,'validated','Crimson Markers','crimson-primary.jpg','','Ready to publish','Full description'),
        (202,70,102,'merged','Crimson Duplicate','crimson-secondary.jpg','','Duplicate','Duplicate description'),
        (203,70,103,'validated','Independent Work','independent.jpg','','Ready to publish','Full description'),
        (204,70,104,'validated','Alpha Work','alpha.jpg','','','')");
    $closeupState = json_encode(['combination' => [
        'selected_camera_slot_id' => 'detalle_textura_lienzo',
        'camera_slot_name' => 'Canvas Close-Up',
        'context_title' => 'Quiet collector interior',
    ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $insertMockup = $pdo->prepare("INSERT INTO mockups
        (id,user_id,mockup_file,context_id,source_artwork_id,series_id,selector_state_json,created_at)
        VALUES (301,70,'close-detail.jpg','quiet_collector_interior',101,1,?,'2026-07-20T10:00:00Z')");
    $insertMockup->execute([$closeupState]);

    $service = new WebsiteBoardService($pdo);
    $artworks = array_values(array_filter(
        $service->sources(70),
        static fn (array $source): bool => (string)$source['type'] === 'artwork'
    ));
    $keys = array_column($artworks, 'key');

    TestHarness::assertSame(3, count($artworks), 'el picker no repite las dos filas de una obra fusionada');
    TestHarness::assertSame(['artwork:101', 'artwork:104', 'artwork:103'], $keys, 'Website conserva el mismo orden reciente de ArtWorks y deja al final obras legadas sin grupo');
    TestHarness::assertTrue(in_array('artwork:101', $keys, true), 'el picker conserva la obra canonica');
    TestHarness::assertTrue(!in_array('artwork:102', $keys, true), 'la referencia absorbida no reaparece como obra independiente');
    TestHarness::assertTrue(in_array('artwork:103', $keys, true), 'las obras sin grupo legado siguen disponibles');
    $mockupSources = array_values(array_filter(
        $service->sources(70),
        static fn (array $source): bool => (string)$source['type'] === 'mockup'
    ));
    TestHarness::assertSame('Canvas Close-Up', (string)($mockupSources[0]['cameraSlotName'] ?? ''), 'Studio Notes conserva el nombre real de la camara del mockup');
    TestHarness::assertTrue(str_contains((string)($mockupSources[0]['searchTerms'] ?? ''), 'close'), 'las tomas de detalle se pueden encontrar buscando close aunque el contexto no lo diga');

    $resolver = new ReflectionMethod(WebsiteBoardService::class, 'resolveSource');
    $legacy = $resolver->invoke($service, 70, 'artwork:102');
    TestHarness::assertSame('artwork:101', (string)$legacy['key'], 'una nota antigua se reconecta con la obra canonica');

    $platformRoot = dirname(__DIR__, 2);
    $studioNotesPage = (string)file_get_contents($platformRoot . '/website_studio_notes.php');
    $editorialSyncScript = (string)file_get_contents($platformRoot . '/scripts/sync_artist_editorial_to_production.php');
    $artworkPage = (string)file_get_contents($platformRoot . '/artwork.php');
    $seriesPage = (string)file_get_contents($platformRoot . '/series.php');
    $viewerPage = (string)file_get_contents($platformRoot . '/viewer.php');
    $publishedStudioNotes = (string)file_get_contents(dirname($platformRoot) . '/artist-site/inc/AppPublishedStudioNotes.php');
    $artistSiteIndex = (string)file_get_contents(dirname($platformRoot) . '/artist-site/index.php');
    $studioNoteMediaEndpoint = (string)file_get_contents($platformRoot . '/studio_note_media.php');
    $editorialWorker = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialGenerationWorker.php');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'class="studio-source-stage"'), 'el tablero inicial no carga un selector visual antes de crear una nota');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'data-source-tab'), 'el tablero inicial queda limitado a notas y a la acción de crear');
    TestHarness::assertContains('class="studio-media-carousel"', $studioNotesPage, 'el archivo visual vive dentro del borrador, encima de los editores');
    TestHarness::assertContains('data-mockup-artwork-filter', $studioNotesPage, 'el carrusel filtra mockups por obra');
    TestHarness::assertContains('data-mockup-series-filter', $studioNotesPage, 'el carrusel filtra mockups por serie');
    TestHarness::assertContains('data-mockup-search', $studioNotesPage, 'el carrusel permite buscar dentro de la mezcla editorial');
    TestHarness::assertContains('draggable="true"', $studioNotesPage, 'cada mockup es una superficie de arrastre hacia el editor');
    TestHarness::assertContains('application/x-studio-note-mockup', $studioNotesPage, 'el drag and drop conserva la identidad del mockup');
    TestHarness::assertContains('overflow-x:auto', $studioNotesPage, 'el carrusel conserva el desplazamiento horizontal aprobado');
    TestHarness::assertTrue(!str_contains($studioNotesPage, '<h2>New Studio Note</h2>'), 'Studio Notes no repite un segundo titulo de pagina dentro del creador');
    TestHarness::assertContains('social-square-button social-square-button--studio_process studio-create-decision', $studioNotesPage, 'crear una nota reutiliza el Decision Block lavanda al final de la linea visual');
    TestHarness::assertContains('studio-create-decision__plus">+</span>', $studioNotesPage, 'la accion de crear una nota usa el simbolo + grande del patron de alta');
    TestHarness::assertContains('studio-create-decision__label"><?= wsn_h(t(\'NOTE\', \'NOTA\')) ?></span>', $studioNotesPage, 'la accion principal conserva la etiqueta NOTE');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'Open Website Blog'), 'Studio Notes no muestra el acceso redundante al blog publico');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'placeholder="Title your note"'), 'el titulo se escribe dentro del borrador y no antes de crearlo');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'name="destinations[]"'), 'la creacion no adelanta decisiones de destino');
    TestHarness::assertContains('data-image-align="center"', $studioNotesPage, 'las imagenes insertadas se pueden alinear editorialmente');
    TestHarness::assertContains("[{ 'align': [] }]", $studioNotesPage, 'el WYSIWYG permite alinear párrafos a izquierda, centro y derecha');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'Material visual'), 'el editor elimina la biblioteca visual lateral redundante');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'data-media-library'), 'el borrador no conserva una segunda superficie para insertar imágenes');
    TestHarness::assertContains('data-mockup-guide-en', $studioNotesPage, 'cada mockup transporta su SEO bilingüe existente al artículo');
    TestHarness::assertContains('PUBLICAR', $studioNotesPage, 'la interfaz presenta la publicación final como compromiso principal');
    TestHarness::assertContains('Actualizar inglés', $studioNotesPage, 'la interfaz separa la actualización inglesa de la publicación');
    TestHarness::assertContains('studio-note-publish--commit', $studioNotesPage, 'PUBLICAR reutiliza la geometría cuadrada del Primary Action');
    TestHarness::assertContains('preparedForPublication(currentSpanish)', $studioNotesPage, 'una nota nueva completa deja de pedir otra adaptación inglesa');
    TestHarness::assertContains('preparedForPublication(currentEnglish)', $studioNotesPage, 'el cliente reconoce el paquete inglés completo antes de ofrecer PUBLICAR');
    TestHarness::assertContains('StudioNoteMediaService::canonicalizeBilingualContent', $studioNotesPage, 'abrir y publicar comparten una sola representación bilingüe de texto, imágenes y SEO');
    TestHarness::assertContains('registerMockupMetadata(card)', $studioNotesPage, 'el editor recupera metadatos de los mockups que ya estaban insertados al recargar');
    TestHarness::assertContains(
        '$submissionSources = $draftId === $id',
        $studioNotesPage,
        'PUBLICAR recupera el mismo SEO de mockups en el POST antes de volver a clasificar'
    );
    TestHarness::assertContains(
        "\$canonicalSaved = StudioNoteMediaService::canonicalizeBilingualContent",
        $studioNotesPage,
        'PUBLICAR vuelve a leer y repara el contenido persistido antes de decidir'
    );
    TestHarness::assertContains(
        "\$websiteBoard->noteAction(\$userId, \$id, 'publish');",
        $studioNotesPage,
        'PUBLICAR cambia también la campaña que consume el website a estado published'
    );
    TestHarness::assertContains(
        '$pdo->beginTransaction();',
        $studioNotesPage,
        'las versiones bilingües y la visibilidad del website se publican atómicamente'
    );
    TestHarness::assertContains('data-change-message', $studioNotesPage, 'el estado editorial permanece junto a su acción');
    TestHarness::assertContains('studio-note-command-bar', $studioNotesPage, 'la acción no queda perdida debajo del editor largo');
    TestHarness::assertContains('name="title_es"', $studioNotesPage, 'Studio Notes escribe primero el título español');
    TestHarness::assertContains('id="editor-container-es"', $studioNotesPage, 'Studio Notes conserva una superficie amplia para el master español');
    TestHarness::assertContains('id="editor-container-en"', $studioNotesPage, 'la adaptación inglesa tiene una superficie editorial independiente');
    TestHarness::assertContains('class="studio-bilingual-editors"', $studioNotesPage, 'los editores español e inglés comparten una única superficie de comparación');
    TestHarness::assertContains('grid-template-columns:minmax(0,1fr) minmax(0,1fr)', $studioNotesPage, 'los dos idiomas permanecen lado a lado con el mismo ancho');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'studio-translation-arrow'), 'Studio Notes elimina la acción adicional de traducción');
    TestHarness::assertContains('min-height:27px', $studioNotesPage, 'las cabeceras bilingües alinean el inicio de ambos editores');
    TestHarness::assertContains('studio-note-actions__secondary', $studioNotesPage, 'retirar y eliminar se presentan juntos como acciones secundarias');
    TestHarness::assertContains("value=\"<?= wsn_h((string)(\$changeState['primary_action']", $studioNotesPage, 'la acción principal procede del clasificador del servidor');
    TestHarness::assertContains('name="tags_<?= $locale ?>"', $studioNotesPage, 'Studio Notes conserva los tags producidos por el Meta Analyzer');
    TestHarness::assertContains('name="caption_<?= $locale ?>"', $studioNotesPage, 'Studio Notes conserva los captions editoriales por idioma');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'Mesa editorial'), 'el borrador elimina la mesa editorial lateral');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'Ideas y fragmentos'), 'el borrador elimina el board de ideas que ocupaba espacio');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'Adaptar desde el español — no traducir literalmente'), 'el editor elimina el botón largo redundante');
    TestHarness::assertContains('StudioNoteChangeClassifier::classify', $studioNotesPage, 'el servidor clasifica el cambio antes de decidir una operación');
    TestHarness::assertContains("'review_spanish_metadata' =>", $studioNotesPage, 'actualizar inglés revisa SEO solo cuando quedan campos no protegidos');
    TestHarness::assertContains('mirrorImagesWithPublishedFallback', $studioNotesPage, 'publicar refleja la estructura visual sin nueva adaptación');
    TestHarness::assertContains(
        "\$englishState['content'] = \$canonicalContent['english'];",
        $studioNotesPage,
        'el editor inglés recibe la representación canónica antes de decidir cualquier acción'
    );
    TestHarness::assertContains(
        "\$action = 'publish_changes';",
        $studioNotesPage,
        'el servidor corrige una acción inglesa obsoleta y publica ajustes visuales sin crear un trabajo de IA'
    );
    TestHarness::assertTrue(
        !str_contains($studioNotesPage, 'function bodyStructure(html)'),
        'el navegador no confunde una normalización visual de Quill con contenido semántico'
    );
    TestHarness::assertContains(
        'pendingImageUploads > 0',
        $studioNotesPage,
        'una acción normal se envía directamente cuando no existen cargas de imagen pendientes'
    );
    TestHarness::assertContains(
        'controller.abort()',
        $studioNotesPage,
        'una carga de imagen colgada tiene un límite y no paraliza indefinidamente el editor'
    );
    TestHarness::assertContains("'current_english' => (array)\$savedEnglishState['content']", $studioNotesPage, 'actualizar inglés conserva el editor vigente como contexto separado');
    TestHarness::assertContains("studio_note_inline_upload.php", $studioNotesPage, 'las imágenes del WYSIWYG se persisten al insertarlas y no esperan hasta publicar');
    TestHarness::assertContains("published_content_json", $studioNotesPage, 'la tarjeta recupera la imagen del último snapshot publicado cuando el borrador actual perdió su referencia');
    TestHarness::assertContains("first_html_image_src(\$publishedBody)", $studioNotesPage, 'el cuerpo editorial publicado actúa como respaldo real del thumbnail');
    TestHarness::assertContains(
        "html_entity_decode((string)\$matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')",
        $studioNotesPage,
        'el thumbnail decodifica el separador de query antes de escapar el atributo y no solicita &amp; como texto'
    );
    TestHarness::assertContains('data-active-job-action', $studioNotesPage, 'la interfaz distingue una adaptación activa de otras propuestas');
    TestHarness::assertContains('setEnglishAdaptationBusy', $studioNotesPage, 'el editor inglés queda inhabilitado mientras se prepara su adaptación');
    TestHarness::assertContains('(string)$spanish[\'body_html\']', $studioNotesPage, 'el HTML español gobierna los medios al publicar la adaptación');
    TestHarness::assertContains('$localizedBodies', $publishedStudioNotes, 'el catálogo recupera medios persistentes desde los cuerpos bilingües publicados');
    TestHarness::assertContains("entity_type='studio_note'", $studioNoteMediaEndpoint, 'el endpoint puede reautorizar un archivo huérfano desde el snapshot bilingüe publicado');
    TestHarness::assertContains('$isPublic', $studioNoteMediaEndpoint, 'el mismo endpoint sirve la nota pública y el borrador de su propietario');
    TestHarness::assertContains('rewriteDeliveryUrls', $studioNotesPage, 'el WYSIWYG reemplaza las URLs privadas históricas al abrir la nota');
    TestHarness::assertContains('wsn_note_media_url', $studioNotesPage, 'el thumbnail editorial usa el mismo medio específico de la nota');
    TestHarness::assertTrue(
        !str_contains($artistSiteIndex, '$thumbUrl = first_html_image_src((string)$post[\'objective\'])'),
        'el catálogo no convierte una etiqueta de imagen huérfana en una portada rota'
    );
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'Dirección privada para la propuesta'), 'el editor elimina el bloque de dirección privada');
    TestHarness::assertContains("DELETE FROM studio_note_workspace_items", $studioNotesPage, 'eliminar una nota también elimina su mesa editorial');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'value="prepare_english"'), 'publicar sustituye la preparación inglesa separada');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'Reanalizar y publicar'), 'la interfaz ya no mezcla análisis y publicación');
    TestHarness::assertContains("value=\"save_draft\"", $studioNotesPage, 'guardar borrador permanece como operación secundaria independiente');
    TestHarness::assertContains('setPublished($userId, $entityType, $entityId, $workingLocale, true)', $editorialWorker, 'la publicación congela un snapshot del idioma de trabajo');
    TestHarness::assertContains('setPublished($userId, $entityType, $entityId, $adaptationLocale, true)', $editorialWorker, 'la publicación congela un snapshot del idioma adaptado cuando existe');
    $editorialAdapter = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialAdapterService.php');
    TestHarness::assertContains('studioNoteTrustedImageMetadata', $editorialAdapter, 'el Meta Analyzer reutiliza el análisis visual existente de cada mockup');
    TestHarness::assertContains('$analysisPaths', $editorialAdapter, 'solo las imágenes externas sin SEO previo llegan al análisis visual');
    TestHarness::assertContains("'editorial_sync_key' => 'studio-note-'", $studioNotesPage, 'cada nota nueva recibe una identidad estable para sincronización');
    TestHarness::assertContains('$studioNoteTargetIds', $editorialSyncScript, 'la sincronización resuelve el identificador productivo de cada nota');
    TestHarness::assertContains("'studio_note' => \$target->prepare", $editorialSyncScript, 'la sincronización admite snapshots bilingües de Notas de estudio');
    TestHarness::assertContains('data-website-cover-picker', $artworkPage, 'Website muestra la portada activa en un selector visual');
    TestHarness::assertContains('class="artwork-cover-options"', $artworkPage, 'Website permite comparar las portadas mediante miniaturas');
    TestHarness::assertContains('type="radio"', $artworkPage, 'la portada elegida se envia como una opcion accesible del formulario');
    TestHarness::assertTrue(!str_contains($artworkPage, '<select name="header_file">'), 'Website ya no oculta la portada elegida en un select de texto');
    TestHarness::assertContains("['camera_slot_name']", $artworkPage, 'los mockups del selector usan el nombre real de su camara');
    TestHarness::assertTrue(!str_contains($artworkPage, 'website_studio_notes.php?source=artwork:'), 'Artwork reserva su cabecera para el trabajo visual principal');
    TestHarness::assertTrue(!str_contains($seriesPage, 'website_studio_notes.php?source=series:'), 'Series reserva su encabezado para Create Art como unica accion contextual');
    TestHarness::assertContains('website_studio_notes.php?source=mockup:', $viewerPage, 'el viewer puede iniciar una Studio Note desde el mockup activo');
    TestHarness::assertTrue(!str_contains($viewerPage, '>Publish mockup</a>'), 'el viewer ya no confunde Studio Notes con Publish mockup');
    TestHarness::assertContains('$viewerEditorialEnabled = false;', $viewerPage, 'el viewer permanece dedicado a mirar la imagen y no muestra contenido editorial');
    TestHarness::assertContains('if ($viewerEditorialEnabled && $artworkId > 0', $viewerPage, 'abrir el viewer no crea ni modifica fichas editoriales');
    TestHarness::assertContains('<?php if ($viewerEditorialEnabled): ?>', $viewerPage, 'los análisis, keywords y adaptaciones permanecen fuera de la interfaz del viewer');

    $insertPublication = $pdo->prepare("INSERT INTO publications
        (user_id,artwork_sheet_id,slug,title,description,short_description,language,objective,cta_label,cta_url,visibility,status,profile_snapshot_json,metadata_snapshot_json,published_at,created_at,updated_at,header_file)
        VALUES (?,?,?,?,?,?,'en','portfolio','','','private','draft','{}','{}',NULL,'2026-07-20','2026-07-20','')");
    $insertPublication->execute([70,201,'crimson-markers','Crimson Markers','Full description','Ready to publish']);
    $crimsonPublicationId = (int)$pdo->lastInsertId();
    $insertPublication->execute([70,203,'independent-work','Independent Work','Full description','Ready to publish']);
    $independentPublicationId = (int)$pdo->lastInsertId();

    $bulk = $service->publishCatalogDrafts(70, [$crimsonPublicationId, $independentPublicationId]);
    TestHarness::assertSame(2, (int)$bulk['count'], 'la accion principal publica todos los borradores validados juntos');
    $bulkStatuses = $pdo->query("SELECT status FROM publications WHERE id IN ({$crimsonPublicationId},{$independentPublicationId}) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    TestHarness::assertSame(['published', 'published'], $bulkStatuses, 'el lote deja publicadas todas las obras solicitadas');

    $insertPublication->execute([70,204,'alpha-work','Alpha Work','','']);
    $invalidPublicationId = (int)$pdo->lastInsertId();
    $insertPublication->execute([70,201,'crimson-second-draft','Crimson Second Draft','Full description','Ready to publish']);
    $validPublicationId = (int)$pdo->lastInsertId();
    $bulkBlocked = false;
    try {
        $service->publishCatalogDrafts(70, [$invalidPublicationId, $validPublicationId]);
    } catch (RuntimeException $error) {
        $bulkBlocked = str_contains($error->getMessage(), 'No se publicó ninguna obra')
            && str_contains($error->getMessage(), 'Alpha Work');
    }
    TestHarness::assertTrue($bulkBlocked, 'el lote informa qué obra impide publicar');
    $blockedStatuses = $pdo->query("SELECT status FROM publications WHERE id IN ({$invalidPublicationId},{$validPublicationId}) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    TestHarness::assertSame(['draft', 'draft'], $blockedStatuses, 'si una obra falla la validacion ninguna obra del lote se publica');
}
