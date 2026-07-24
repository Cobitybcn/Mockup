<?php
declare(strict_types=1);

function run_series_keyword_research_tests(): void
{
    TestHarness::group('Investigación de búsqueda de Series');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE artwork_series (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, title TEXT NOT NULL)');
    $pdo->exec("INSERT INTO users (id,email) VALUES (7,'artist@example.com'),(8,'other@example.com')");
    $pdo->exec("INSERT INTO artwork_series (id,user_id,title) VALUES (3,7,'STRATA'),(4,8,'OTHER')");
    $migration = require dirname(__DIR__, 2) . '/migrations/schema/20260723_000007_series_keyword_research.php';
    ($migration['up'])($pdo);

    $service = new SeriesKeywordResearchService($pdo);
    $spanishTsv = implode("\n", [
        "Palabra clave\tPromedio de búsquedas mensuales\tCompetencia\tCompetencia (valor indexado)",
        "pintura abstracta contemporánea\t1000\tMedia\t52",
        "pinturas abstractas originales para coleccionistas\t10 – 100\tBaja\t18",
    ]);
    $import = $service->importPlannerExport(7, 3, 'es', 'España', $spanishTsv);
    TestHarness::assertSame(2, $import['imported'], 'importa filas españolas exportadas por Keyword Planner');

    $englishCsv = implode("\n", [
        '"Keyword","Avg. monthly searches","Competition","Competition (indexed value)","Currency"',
        '"large abstract painting","2,400","High","81","USD"',
    ]);
    $englishImport = $service->importPlannerExport(7, 3, 'en', 'United States', $englishCsv);
    TestHarness::assertSame(1, $englishImport['imported'], 'importa investigación inglesa en un mercado independiente');

    $rows = $service->all(7, 3);
    TestHarness::assertSame(3, count($rows), 'conserva todos los términos por idioma y mercado');
    $large = array_values(array_filter($rows, static fn(array $row): bool => $row['keyword_text'] === 'large abstract painting'))[0] ?? [];
    TestHarness::assertSame(2400, (int)($large['avg_monthly_searches'] ?? 0), 'normaliza el volumen numérico exportado');
    $range = array_values(array_filter($rows, static fn(array $row): bool => str_contains((string)$row['keyword_text'], 'coleccionistas')))[0] ?? [];
    TestHarness::assertSame('10 – 100', (string)($range['volume_label'] ?? ''), 'conserva rangos cuando Google no entrega un volumen exacto');

    $service->replaceSelection(7, 3, [(int)$large['id'], (int)$range['id']]);
    $context = $service->promptContext(7, 3);
    TestHarness::assertSame('validated_selection_available', $context['status'], 'la generación distingue investigación importada de selección validada');
    TestHarness::assertSame(2, count($context['selected']), 'solo las frases elegidas alimentan la propuesta editorial');
    TestHarness::assertContains('advertising competition', $context['instruction'], 'la competencia publicitaria nunca se presenta como dificultad SEO orgánica');

    $ownershipProtected = false;
    try {
        $service->all(7, 4);
    } catch (RuntimeException) {
        $ownershipProtected = true;
    }
    TestHarness::assertTrue($ownershipProtected, 'la investigación queda aislada por artista y serie');
}
