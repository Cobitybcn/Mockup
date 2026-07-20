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
        context_id TEXT NOT NULL, source_artwork_id INTEGER, series_id INTEGER, created_at TEXT NOT NULL
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

    $resolver = new ReflectionMethod(WebsiteBoardService::class, 'resolveSource');
    $legacy = $resolver->invoke($service, 70, 'artwork:102');
    TestHarness::assertSame('artwork:101', (string)$legacy['key'], 'una nota antigua se reconecta con la obra canonica');

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
