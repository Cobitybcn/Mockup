<?php
declare(strict_types=1);

/**
 * LA LEY EJECUTABLE de platform/docs/EDITORIAL_CORE.md.
 *
 * Cada asercion custodia un articulo de la constitucion editorial. Si una
 * falla, el cambio viola el CORE: leer el articulo citado antes de tocar el
 * test. El preflight corre esta suite antes de todo merge a main — un cambio
 * que la rompa no puede llegar a produccion.
 */
function run_editorial_core_contract_tests(): void
{
    TestHarness::group('EDITORIAL_CORE: contrato ejecutable');

    $platformRoot = dirname(__DIR__, 2);
    $repoRoot = dirname($platformRoot);

    // ————— Constitucion presente y obligatoria —————
    $core = (string)@file_get_contents($platformRoot . '/docs/EDITORIAL_CORE.md');
    TestHarness::assertTrue($core !== '', 'EDITORIAL_CORE.md existe — la constitucion es un archivo versionado, no una conversacion');
    TestHarness::assertContains('CONTRATO VIGENTE', $core, 'EDITORIAL_CORE.md se declara contrato vigente');
    $agents = (string)@file_get_contents($repoRoot . '/AGENTS.md');
    TestHarness::assertContains('EDITORIAL_CORE.md', $agents, 'AGENTS.md obliga a leer la constitucion antes de tocar contenido editorial');

    // ————— Libro I Cap. 6: el sistema nunca decide titulos —————
    $prompt = ArtworkAnalysisV2::prompt();
    TestHarness::assertTrue(
        !str_contains($prompt, 'Produce one title'),
        'EDITORIAL_CORE Libro I Cap. 6: el prompt no pide producir un titulo — titular es del artista'
    );
    TestHarness::assertContains(
        'immutable input, never an output',
        $prompt,
        'EDITORIAL_CORE Libro I Cap. 6: el titulo entra al prompt como hecho inmutable del artista'
    );
    TestHarness::assertTrue(
        !str_contains($prompt, '{catalogue_title_constraints}'),
        'EDITORIAL_CORE Libro I Cap. 6: la maquinaria de originalidad de titulos salio del analisis (vive en la herramienta de sugerencias)'
    );
    $schemaStart = strpos($prompt, '"canonical_editorial"');
    $schemaSlice = $schemaStart !== false ? substr($prompt, $schemaStart, 120) : '';
    TestHarness::assertTrue(
        $schemaSlice !== '' && !str_contains($schemaSlice, '"title"'),
        'EDITORIAL_CORE Libro I Cap. 6: canonical_editorial no tiene campo title en el schema de salida — lo que no existe no se puede inventar'
    );

    $serviceSource = (string)file_get_contents($platformRoot . '/app/Services/ArtworkAnalysisV2Service.php');
    TestHarness::assertContains(
        'EditorialIdentityGuard::forceIdentity',
        $serviceSource,
        'EDITORIAL_CORE (guardian): finalize() fuerza la identidad del artista — ningun caller puede recibir un borrador contaminado'
    );
    $sheetSource = (string)file_get_contents($platformRoot . '/app/Services/ArtworkSheetService.php');
    TestHarness::assertTrue(
        !str_contains($sheetSource, 'generateArtworkSheet('),
        'EDITORIAL_CORE Libro I Cap. 6: el flujo legacy que sobreescribia titulos sin proteccion fue eliminado'
    );

    // ————— Libro VI Cap. 1: gate de contexto integro —————
    TestHarness::assertContains(
        'assertIntegralContext',
        $serviceSource,
        'EDITORIAL_CORE Libro VI Cap. 1: generar lectura exige titulo + serie + direccion de serie, en el unico punto por el que pasan todos los callers'
    );
    $probeService = new class extends ArtworkAnalysisV2Service {
        public function __construct() { parent::__construct(new GeminiImageClient()); }
    };
    $probeImage = tempnam(sys_get_temp_dir(), 'core') . '.jpg';
    file_put_contents($probeImage, 'probe');
    try {
        $gateMessage = '';
        try {
            $probeService->generateDraft(['id' => 1, 'final_title' => '', 'series_id' => 3], [], $probeImage);
        } catch (DomainException $e) {
            $gateMessage = $e->getMessage();
        }
        TestHarness::assertContains('título', $gateMessage, 'EDITORIAL_CORE Libro VI Cap. 1: sin titulo del artista no se genera lectura');
        $gateMessage = '';
        try {
            $probeService->generateDraft(['id' => 1, 'final_title' => 'STRATA I — UNUS', 'series_id' => 0], [], $probeImage);
        } catch (DomainException $e) {
            $gateMessage = $e->getMessage();
        }
        TestHarness::assertContains('serie', $gateMessage, 'EDITORIAL_CORE Libro VI Cap. 1: sin serie asignada no se genera lectura');
    } finally {
        @unlink($probeImage);
    }

    // ————— Libro VI Cap. 2: refinar, no olvidar + transitoriedad del blob —————
    TestHarness::assertContains('currentReadingBlock', $serviceSource, 'EDITORIAL_CORE Libro VI Cap. 2: la regeneracion recibe la lectura vigente como base a refinar');
    TestHarness::assertContains('{current_reading_block}', $prompt, 'EDITORIAL_CORE Libro VI Cap. 2: el prompt tiene el bloque de lectura vigente');
    $workerSource = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialGenerationWorker.php');
    TestHarness::assertContains('$currentReading', $workerSource, 'EDITORIAL_CORE Libro VI Cap. 2: el worker pasa la lectura vigente al generador');
    TestHarness::assertTrue(
        !str_contains($sheetSource, 'APPROVED ARTWORK IDENTITY:'),
        'EDITORIAL_CORE Libro VI Cap. 2: el mockup ya no recibe el blob viejo del analisis como identidad'
    );
    TestHarness::assertContains('CONFIRMED ARTWORK IDENTITY', $sheetSource, 'EDITORIAL_CORE Libro I Cap. 7: la identidad del mockup se lee EN VIVO de las tablas canonicas');
    TestHarness::assertContains('APPROVED ARTWORK READING', $sheetSource, 'EDITORIAL_CORE Libro I Cap. 7: el mockup hereda la lectura publicada de su obra');
    TestHarness::assertContains('ARTIST-AUTHORED SERIES DIRECTION', $sheetSource, 'EDITORIAL_CORE Libro I Cap. 4: la direccion de la serie llega al contenido de mockups');
    TestHarness::assertContains('MOCKUP SCENE METADATA', $sheetSource, 'EDITORIAL_CORE Libro I Cap. 7: la escena pedida del mockup entra como evidencia estructural');
    $adapterSource = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialAdapterService.php');
    TestHarness::assertContains('approved_artwork_reading', $adapterSource, 'EDITORIAL_CORE Libro VI Cap. 2: el adapter tambien hereda la lectura publicada en vivo, no el blob');
    TestHarness::assertContains('EditorialIdentityGuard::rewriteAliases', $adapterSource, 'EDITORIAL_CORE (guardian): una sola implementacion de identidad, compartida');

    // ————— Libro VI Cap. 1: publicar = aprobar (gate de mockups) —————
    TestHarness::assertContains(
        'La obra necesita su contenido publicado',
        $sheetSource,
        'EDITORIAL_CORE Libro VI Cap. 1: sin lectura de obra publicada, sus mockups no generan contenido'
    );

    // ————— Libro III: SEO responsabilidad del sistema —————
    TestHarness::assertContains('{search_intent_rules}', $prompt, 'EDITORIAL_CORE Libro III: las reglas de intencion de busqueda rigen el analisis de obra');
    TestHarness::assertContains('{keyword_research_block}', $prompt, 'EDITORIAL_CORE Libro III Cap. 4: la investigacion de keywords se inyecta sola cuando existe');
    TestHarness::assertContains('keywordResearchBlock', $serviceSource, 'EDITORIAL_CORE Libro III Cap. 4: el generador de obra consulta promptContext()');
    TestHarness::assertContains('mockupKeywordResearchBlock', $sheetSource, 'EDITORIAL_CORE Libro III Cap. 4: el generador de mockups consulta promptContext()');
    TestHarness::assertContains("SearchIntentPrompt::forEntity('mockup')", $sheetSource, 'EDITORIAL_CORE Libro III: las reglas de busqueda rigen tambien el mockup');

    // ————— Guardian funcional: identidad forzada y prosa reescrita —————
    $cleaned = EditorialIdentityGuard::forceIdentity(
        ['canonical_editorial' => ['title' => 'Estratos Ocre', 'master_description' => 'La obra Estratos Ocre respira en silencio.']],
        'STRATA V — QUINQUE'
    );
    TestHarness::assertSame('STRATA V — QUINQUE', (string)$cleaned['canonical_editorial']['title'], 'EDITORIAL_CORE (guardian): el titulo real del artista reemplaza cualquier invento');
    TestHarness::assertContains('STRATA V — QUINQUE respira', (string)$cleaned['canonical_editorial']['master_description'], 'EDITORIAL_CORE (guardian): la mencion inventada se reescribe tambien en la prosa');
    $bounded = EditorialIdentityGuard::rewriteAliases(
        ['text' => 'Sol y Solitude conviven.'],
        'LUX',
        ['Sol'],
        '',
        []
    );
    TestHarness::assertSame('LUX y Solitude conviven.', (string)$bounded['text'], 'EDITORIAL_CORE (guardian): el reemplazo respeta limites de palabra — un alias corto no corrompe otras palabras');

    // ————— Compuerta al publicar + edicion soberana (funcional, fixture real) —————
    $pdo = bilingual_editorial_test_pdo();
    $artistEditsMigration = require $platformRoot . '/migrations/schema/20260729_000001_editorial_artist_edits.php';
    ($artistEditsMigration['up'])($pdo);
    $service = new BilingualEditorialService($pdo);

    $pdo->exec("UPDATE artworks SET final_title='STRATA V — QUINQUE' WHERE id=11");
    $pdo->exec("UPDATE artwork_sheets SET generated_json='" . json_encode(['canonical_editorial' => ['title' => 'Estratos Ocre']]) . "' WHERE id=41");
    $service->save(7, 'artwork', 11, 'es', [
        'description' => 'La pintura Estratos Ocre se integra al espacio con campos de color.',
        'caption' => 'Estratos Ocre en sala',
    ]);
    $service->setPublished(7, 'artwork', 11, 'es', true);
    $published = $service->get(7, 'artwork', 11, 'es');
    TestHarness::assertTrue((bool)$published['is_published'], 'la publicacion se concreta tras pasar la compuerta');
    $publishedText = json_encode($published['published_content'], JSON_UNESCAPED_UNICODE);
    TestHarness::assertTrue(
        !str_contains((string)$publishedText, 'Estratos Ocre'),
        'EDITORIAL_CORE (compuerta al publicar): un nombre divergente no puede llegar al snapshot publicado'
    );
    TestHarness::assertContains('STRATA V — QUINQUE', (string)$publishedText, 'EDITORIAL_CORE (compuerta): el nombre real del artista queda en lo publicado');

    // Edicion soberana: el guardado del artista queda marcado y la cascada lo respeta.
    $service->save(7, 'mockup', 21, 'es', ['description' => 'Texto del artista, intocable.'], '', true);
    $service->save(7, 'mockup', 22, 'es', ['description' => 'Texto generado por el sistema.'], '', false);
    $edited = $service->artistEditedMockupIds(7, 11);
    TestHarness::assertTrue(in_array(21, $edited, true), 'EDITORIAL_CORE Libro VI Cap. 4: la edicion manual del artista queda marcada como soberana');
    TestHarness::assertTrue(!in_array(22, $edited, true), 'EDITORIAL_CORE Libro VI Cap. 4: el texto del sistema sigue siendo regenerable');

    $endpointSource = (string)file_get_contents($platformRoot . '/bilingual_editorial.php');
    TestHarness::assertContains('cascade_from_artwork', $endpointSource, 'EDITORIAL_CORE Libro VI Cap. 4: re-publicar una obra encola la regeneracion de sus mockups');
    TestHarness::assertContains('artistEditedMockupIds', $endpointSource, 'EDITORIAL_CORE Libro VI Cap. 4: la cascada saltea los mockups editados a mano');
    TestHarness::assertContains('guardIdentityBeforePublish', (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialService.php'), 'EDITORIAL_CORE (compuerta): setPublished revalida identidad antes de congelar el snapshot');

    // ————— Libro I Cap. 6: la IA jamas rellena titulos vacios —————
    TestHarness::assertTrue(
        !str_contains($sheetSource, '$suggestedTitle'),
        'EDITORIAL_CORE Libro I Cap. 6: applyAnalysisV2Draft ya no usa titulos sugeridos por el modelo — ni siquiera para obras sin titulo'
    );
}
