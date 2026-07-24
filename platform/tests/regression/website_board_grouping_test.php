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
    $artworkPage = (string)file_get_contents($platformRoot . '/artwork.php');
    $seriesPage = (string)file_get_contents($platformRoot . '/series.php');
    $viewerPage = (string)file_get_contents($platformRoot . '/viewer.php');
    TestHarness::assertContains('class="studio-source-stage"', $studioNotesPage, 'Studio Notes mantiene una única etapa visual para crear una nota');
    TestHarness::assertContains("['artwork' => 'Artworks', 'series' => 'Series', 'mockup' => 'Mockups']", $studioNotesPage, 'el selector organiza las tres fuentes visuales sin mezclarlas');
    TestHarness::assertContains('overflow-x:auto', $studioNotesPage, 'el selector movil usa desplazamiento horizontal nativo');
    TestHarness::assertTrue(!str_contains($studioNotesPage, '<h2>New Studio Note</h2>'), 'Studio Notes no repite un segundo titulo de pagina dentro del creador');
    TestHarness::assertContains('data-source-tab="none" data-clear-source', $studioNotesPage, 'No source es una pestaña que oculta las galerias y limpia la seleccion');
    TestHarness::assertContains('social-square-button social-square-button--studio_process studio-create-decision', $studioNotesPage, 'crear una nota reutiliza el Decision Block lavanda al final de la linea visual');
    TestHarness::assertContains('studio-create-decision__plus">+</span>', $studioNotesPage, 'la accion de crear una nota usa el simbolo + grande del patron de alta');
    TestHarness::assertContains('studio-create-decision__label">NOTE</span>', $studioNotesPage, 'la accion principal conserva la etiqueta NOTE');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'Open Website Blog'), 'Studio Notes no muestra el acceso redundante al blog publico');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'placeholder="Title your note"'), 'el titulo se escribe dentro del borrador y no antes de crearlo');
    TestHarness::assertTrue(!str_contains($studioNotesPage, 'name="destinations[]"'), 'la creacion no adelanta decisiones de destino');
    TestHarness::assertContains('data-insert-image=', $studioNotesPage, 'el editor permite insertar el material visual relacionado con la fuente');
    TestHarness::assertContains("quill.insertEmbed(safeIndex, 'image'", $studioNotesPage, 'las imagenes relacionadas se insertan en la posicion del cursor');
    TestHarness::assertContains('draggable="true"', $studioNotesPage, 'el material relacionado se puede arrastrar al editor');
    TestHarness::assertContains('application/x-studio-note-media', $studioNotesPage, 'Studio Notes usa un payload de drag and drop propio');
    TestHarness::assertContains('data-image-align="center"', $studioNotesPage, 'las imagenes insertadas se pueden alinear editorialmente');
    TestHarness::assertContains('data-media-filter="related"', $studioNotesPage, 'la biblioteca distingue el material relacionado');
    TestHarness::assertContains('data-media-filter="mockup"', $studioNotesPage, 'la biblioteca permite buscar entre mockups diferentes');
    TestHarness::assertContains('data-media-search', $studioNotesPage, 'la biblioteca visual ofrece busqueda por sus metadatos');
    TestHarness::assertContains("(string)(\$media['searchTerms'] ?? '')", $studioNotesPage, 'la busqueda incluye camara, slot y sinonimos del mockup');
    TestHarness::assertContains("normalize('NFD')", $studioNotesPage, 'la busqueda visual ignora tildes');
    TestHarness::assertContains('data-website-cover-picker', $artworkPage, 'Website muestra la portada activa en un selector visual');
    TestHarness::assertContains('class="artwork-cover-options"', $artworkPage, 'Website permite comparar las portadas mediante miniaturas');
    TestHarness::assertContains('type="radio"', $artworkPage, 'la portada elegida se envia como una opcion accesible del formulario');
    TestHarness::assertTrue(!str_contains($artworkPage, '<select name="header_file">'), 'Website ya no oculta la portada elegida en un select de texto');
    TestHarness::assertContains("['camera_slot_name']", $artworkPage, 'los mockups del selector usan el nombre real de su camara');
    TestHarness::assertContains('website_studio_notes.php?source=artwork:', $artworkPage, 'cada Artwork puede iniciar una Studio Note contextual');
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
