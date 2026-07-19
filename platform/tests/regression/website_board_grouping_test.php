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
        year_start INTEGER, created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE artwork_sheets (
        id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, canonical_artwork_id INTEGER NOT NULL,
        status TEXT NOT NULL, title TEXT NOT NULL, source_image_file TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE mockups (
        id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, mockup_file TEXT NOT NULL,
        context_id TEXT NOT NULL, source_artwork_id INTEGER, series_id INTEGER, created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE mockup_sheets (
        id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, mockup_file TEXT NOT NULL,
        title TEXT NOT NULL, description TEXT NOT NULL, caption TEXT NOT NULL,
        alt_text TEXT NOT NULL, keywords TEXT NOT NULL, generated_json TEXT NOT NULL
    )");

    $pdo->exec("INSERT INTO artwork_groups VALUES
        (10,70,101,'Crimson Markers','active','2026-07-15T10:00:00Z','2026-07-19T10:00:00Z'),
        (11,70,104,'Alpha Work','active','2026-07-14T10:00:00Z','2026-07-18T10:00:00Z')");
    $pdo->exec("INSERT INTO artworks VALUES
        (101,70,10,'done','Crimson Markers','crimson-primary.jpg','',NULL,''),
        (102,70,10,'done','Crimson Duplicate','crimson-secondary.jpg','',NULL,''),
        (103,70,NULL,'done','Independent Work','independent.jpg','',NULL,''),
        (104,70,11,'done','Alpha Work','alpha.jpg','',NULL,'')");
    $pdo->exec("INSERT INTO artwork_sheets VALUES
        (201,70,101,'validated','Crimson Markers','crimson-primary.jpg'),
        (202,70,102,'merged','Crimson Duplicate','crimson-secondary.jpg'),
        (203,70,103,'validated','Independent Work','independent.jpg'),
        (204,70,104,'validated','Alpha Work','alpha.jpg')");

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
}
