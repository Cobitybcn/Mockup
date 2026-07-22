<?php
declare(strict_types=1);

function run_root_artwork_view_set_regression_tests(): void
{
    TestHarness::group('Set raiz: frente, perfil izquierdo y perfil derecho');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE artworks (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, job_id TEXT NOT NULL UNIQUE)');
    $pdo->exec('CREATE TABLE root_artwork_candidates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        artwork_id INTEGER NOT NULL,
        user_id INTEGER,
        job_id TEXT,
        file_name TEXT NOT NULL,
        view_type TEXT NOT NULL,
        is_selected INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT
    )');
    $pdo->prepare('INSERT INTO artworks (user_id, job_id) VALUES (?, ?)')->execute([17, 'root-set-job']);
    $artworkId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO root_artwork_candidates (artwork_id, file_name, view_type, is_selected, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$artworkId, 'stale.png', 'frontal', 1, date('c')]);

    $service = new RootArtworkViewSetService();
    $files = [
        'base_artwork_gemini_root-set-job_v3.png',
        'base_artwork_gemini_root-set-job_v1.png',
        'base_artwork_gemini_root-set-job_v2.png',
    ];
    $result = $service->replaceForJob($pdo, 'root-set-job', 17, $files);

    TestHarness::assertSame(3, RootArtworkViewSetService::requiredCount(), 'el contrato exige exactamente tres vistas');
    TestHarness::assertSame(
        'base_artwork_gemini_root-set-job_v1.png',
        $result['selected_file'],
        'la vista frontal v1 queda como raiz activa aunque los archivos lleguen desordenados'
    );
    TestHarness::assertSame(
        ['frontal', 'three-quarter-left', 'three-quarter-right'],
        array_keys($result['views']),
        'las tres vistas conservan su identidad semantica'
    );

    $rows = $pdo->query('SELECT file_name, view_type, is_selected, user_id, job_id FROM root_artwork_candidates ORDER BY view_type')->fetchAll(PDO::FETCH_ASSOC);
    TestHarness::assertSame(3, count($rows), 'una unica obra guarda tres candidatas sin conservar filas obsoletas');
    TestHarness::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM artworks')->fetchColumn(), 'las vistas no crean obras duplicadas en el catalogo');
    TestHarness::assertSame(1, count(array_filter($rows, static fn(array $row): bool => (int)$row['is_selected'] === 1)), 'solo una vista queda seleccionada');
    TestHarness::assertSame([17], array_values(array_unique(array_map(static fn(array $row): int => (int)$row['user_id'], $rows))), 'todas las vistas pertenecen al mismo artista');
    TestHarness::assertSame(['root-set-job'], array_values(array_unique(array_column($rows, 'job_id'))), 'todas las vistas pertenecen al mismo trabajo');

    $service->replaceForJob($pdo, 'root-set-job', 17, $files);
    TestHarness::assertSame(3, (int)$pdo->query('SELECT COUNT(*) FROM root_artwork_candidates')->fetchColumn(), 'un reintento reemplaza el set y no duplica candidatas');

    $missingViewRejected = false;
    try {
        RootArtworkViewSetService::completeViewSet(array_slice($files, 0, 2));
    } catch (RuntimeException) {
        $missingViewRejected = true;
    }
    TestHarness::assertTrue($missingViewRejected, 'el flujo no finaliza con un set incompleto');

    TestHarness::group('Set raiz: caracterizacion del flujo automatico');
    $root = dirname(__DIR__, 2);
    $geminiSource = (string)file_get_contents($root . '/app/Services/GeminiArtworkProcessor.php');
    $openAiSource = (string)file_get_contents($root . '/app/Services/OpenAIArtworkProcessor.php');
    $mockSource = (string)file_get_contents($root . '/app/Services/MockArtworkProcessor.php');
    $processSource = (string)file_get_contents($root . '/process_generate.php');

    foreach (['Gemini' => $geminiSource, 'OpenAI' => $openAiSource, 'Mock' => $mockSource] as $provider => $source) {
        TestHarness::assertContains(
            'RootArtworkViewSetService::requiredCount()',
            $source,
            $provider . ' conserva tres resultados en el flujo automatico'
        );
        TestHarness::assertTrue(
            !str_contains($source, "!empty(\$status['user_scene_flow']) ? 1"),
            $provider . ' no vuelve a reducir el set automatico a una sola imagen'
        );
    }
    TestHarness::assertContains(
        'replaceForJob(',
        $processSource,
        'el proceso automatico persiste el set dentro de la misma obra'
    );
    TestHarness::assertContains(
        "\$selectedRootFile = (string)\$rootViewSet['selected_file'];",
        $processSource,
        'la raiz activa se resuelve semanticamente y no por posicion accidental'
    );
    TestHarness::assertContains(
        "\$diskStatus['root_views'] = \$rootViewSet['views'];",
        $processSource,
        'el estado del trabajo expone las tres vistas recuperadas'
    );
    TestHarness::assertContains(
        'runCommandsParallel(',
        $geminiSource,
        'Vertex genera las tres vistas en paralelo'
    );
    TestHarness::assertContains(
        'postImageEditsParallel(',
        $openAiSource,
        'OpenAI genera las tres vistas en paralelo'
    );
}
