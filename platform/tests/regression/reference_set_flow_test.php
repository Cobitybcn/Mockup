<?php
declare(strict_types=1);

function run_reference_set_flow_tests(): void
{
    TestHarness::group('Reference Sets: persistencia y relacion Visual DNA');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE artworks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        final_title TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE artwork_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        canonical_artwork_id INTEGER NOT NULL,
        official_root_artwork_ids TEXT NOT NULL DEFAULT '',
        title TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $now = date('c');
    $pdo->prepare('INSERT INTO users (email, created_at, updated_at) VALUES (?, ?, ?)')
        ->execute(['visual@example.com', $now, $now]);
    $userId = (int)$pdo->lastInsertId();

    $migration = require dirname(__DIR__, 2) . '/migrations/schema/20260719_000004_reference_sets.php';
    ($migration['up'])($pdo);
    $labMigration = require dirname(__DIR__, 2) . '/migrations/schema/20260719_000005_visual_dna_lab.php';
    ($labMigration['up'])($pdo);

    $service = new ReferenceSetService($pdo);
    $service->ensureStarterSets($userId);
    $starterNames = array_column($service->listForUser($userId), 'name');
    TestHarness::assertTrue(in_array('Mediterranean Silence', $starterNames, true), 'incluye Mediterranean Silence como Visual DNA inicial');
    TestHarness::assertTrue(in_array('Catalan Modernism', $starterNames, true), 'incluye Catalan Modernism como Visual DNA inicial');
    TestHarness::assertTrue(in_array('Industrial Silence', $starterNames, true), 'incluye Industrial Silence como Visual DNA inicial');

    $set = $service->create(
        $userId,
        'Quiet Material Study',
        'A reusable visual intention.',
        'sage',
        ['aged-copper', 'arched-courtyard', 'late-window']
    );
    TestHarness::assertSame('Quiet Material Study', (string)$set['name'], 'guarda nombre y descripcion del Reference Set');
    TestHarness::assertSame('sage', (string)$set['identifier_color'], 'guarda el color identificador');
    TestHarness::assertSame(
        ['aged-copper', 'arched-courtyard', 'late-window'],
        array_column((array)$set['items'], 'reference_key'),
        'conserva el orden de las referencias'
    );
    TestHarness::assertTrue(trim((string)$set['thumbnail']) !== '', 'guarda una miniatura del lenguaje visual');
    TestHarness::assertSame(['Materials', 'Architecture', 'Light'], (array)$set['categories'], 'deriva y guarda las categorias ordenadas');

    $pdo->prepare('INSERT INTO reference_assets
        (user_id, title, category, storage_path, original_name, mime_type, file_size, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $userId,
            'Real plaster wall',
            'Materials',
            'storage/visual_dna/' . $userId . '/plaster.jpg',
            'plaster.jpg',
            'image/jpeg',
            1200,
            $now,
            $now,
        ]);
    $realSet = $service->create($userId, 'Real Material DNA', '', 'clay', ['asset:' . (int)$pdo->lastInsertId()]);
    TestHarness::assertTrue(
        (int)($realSet['items'][0]['reference_asset_id'] ?? 0) > 0,
        'Visual DNA conserva la relacion con una referencia real subida por el usuario'
    );

    $pdo->prepare('INSERT INTO artworks (user_id, final_title, reference_set_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, 'Test Artwork', (int)$set['id'], $now, $now]);
    $artworkId = (int)$pdo->lastInsertId();
    $groupService = new ArtworkGroupService($pdo);
    $createGroup = new ReflectionMethod(ArtworkGroupService::class, 'createGroup');
    $createGroup->setAccessible(true);
    $groupId = (int)$createGroup->invoke($groupService, $userId, $artworkId, 'Test Artwork');
    $stmt = $pdo->prepare('SELECT reference_set_id FROM artwork_groups WHERE id = ?');
    $stmt->execute([$groupId]);
    TestHarness::assertSame((int)$set['id'], (int)$stmt->fetchColumn(), 'el Mockup Group hereda la relacion Artwork → Reference Set');

    $createArt = (string)file_get_contents(dirname(__DIR__, 2) . '/create_scenes.php');
    $startGenerate = (string)file_get_contents(dirname(__DIR__, 2) . '/start_generate.php');
    $sceneMockups = (string)file_get_contents(dirname(__DIR__, 2) . '/mockups.php');
    TestHarness::assertContains('name="reference_set_id"', $createArt, 'Create Art expone el selector Visual DNA');
    TestHarness::assertContains('reference_set_id, created_at', $startGenerate, 'Create Art guarda la seleccion en la obra');
    TestHarness::assertContains('<span>Visual DNA</span>', $sceneMockups, 'Scene Mockups muestra la relacion discretamente');
    $labPage = (string)file_get_contents(dirname(__DIR__, 2) . '/studio_references_lab.php');
    $labScript = (string)file_get_contents(dirname(__DIR__, 2) . '/studio_references_lab.js');
    $labWorker = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/MockupGenerationWorker.php');
    TestHarness::assertContains('visual_dna_reference_upload.php', $labPage, 'el LAB permite cargar referencias visuales reales');
    TestHarness::assertContains('visual_dna_reference_import.php', $labPage, 'el LAB dispone de una ruta separada para importar imagenes externas');
    TestHarness::assertContains("addEventListener('drop'", $labScript, 'el Upload Area acepta imagenes soltadas desde el escritorio o navegador');
    TestHarness::assertContains("addEventListener('paste'", $labScript, 'el Upload Area acepta imagenes pegadas desde el portapapeles');
    TestHarness::assertContains("getData('text/uri-list')", $labScript, 'el LAB reconoce URLs entregadas al arrastrar desde otra pagina');
    TestHarness::assertContains('data-choose-reference', $labPage, 'el LAB conserva una alternativa discreta para elegir archivos');
    TestHarness::assertTrue(!str_contains($labPage, 'data-reference-upload-form'), 'el LAB no interpone un formulario al drag and drop visual');
    TestHarness::assertContains('void uploadReferenceFile(file)', $labScript, 'soltar una imagen la incorpora sin confirmacion intermedia');
    TestHarness::assertContains('void importExternalTransfer(event.dataTransfer, zone)', $labScript, 'las areas visibles del tablero aceptan directamente imagenes externas');
    TestHarness::assertContains("generation_source'] ?? '') === 'visual_dna_lab'", $labWorker, 'el worker aisla la conexion de generacion Visual DNA');

    $remoteUrlGuard = new ReflectionMethod(ReferenceAssetService::class, 'assertPublicRemoteUrl');
    $privateUrlRejected = false;
    try {
        $remoteUrlGuard->invoke(new ReferenceAssetService($pdo), 'http://127.0.0.1/private-image.jpg');
    } catch (InvalidArgumentException) {
        $privateUrlRejected = true;
    }
    TestHarness::assertTrue($privateUrlRejected, 'la importacion remota rechaza direcciones locales y privadas');

    $ipOrder = new ReflectionMethod(ReferenceAssetService::class, 'orderConnectionIps');
    $orderedIps = $ipOrder->invoke(new ReferenceAssetService($pdo), ['2a04:4e42:400::84', '151.101.0.84']);
    TestHarness::assertSame('151.101.0.84', $orderedIps[0] ?? '', 'la importacion remota prioriza IPv4 antes de probar IPv6');
}
