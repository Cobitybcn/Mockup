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

    // El contrato editorial se verifica sobre el comportamiento del codigo, no sobre
    // el texto de un documento: un .md que se declara vigente no prueba nada.

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

    // ————— Libro II Cap. 3 (enmienda 2026-07-31): alcance y no repeticion —————
    $policySource = (string)file_get_contents($platformRoot . '/app/Support/EditorialIntegrityPolicy.php');
    TestHarness::assertContains(
        'isPublicCopyPath',
        $policySource,
        'EDITORIAL_CORE Libro II Cap. 3: el alcance es codigo ejecutable, no solo texto del prompt'
    );
    TestHarness::assertTrue(
        !str_contains($policySource, "str_ends_with(\$lowerPath, 'seo_description')"),
        'EDITORIAL_CORE Libro II Cap. 3: la prohibicion ya no queda limitada a seo_description'
    );

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
    TestHarness::assertContains('queueMockupCascadeForArtwork', $endpointSource, 'EDITORIAL_CORE Libro VI Cap. 4: re-publicar una obra encola la regeneracion de sus mockups');
    TestHarness::assertContains(
        'la generacion TERMINADA viaja con su',
        $endpointSource,
        'EDITORIAL_CORE Libro VI: una ficha abierta durante la generacion recibe el contenido al completarse — nunca "guardado" con campos vacios'
    );
    $jobServiceSource = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialJobService.php');
    TestHarness::assertContains('cascade_from_artwork', $jobServiceSource, 'EDITORIAL_CORE Libro VI Cap. 4: la cascada marca su origen en cada job');
    TestHarness::assertContains('artistEditedMockupIds', $jobServiceSource, 'EDITORIAL_CORE Libro VI Cap. 4: la cascada saltea los mockups editados a mano');
    TestHarness::assertContains('guardIdentityBeforePublish', (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialService.php'), 'EDITORIAL_CORE (compuerta): setPublished revalida identidad antes de congelar el snapshot');

    // ————— Libro VI Cap. 1 (enmienda 2026-07-31): la ficha termina en «obra
    // resuelta»; publicar vive en la sección Publicación —————
    $artworkScreen = (string)file_get_contents($platformRoot . '/artwork.php');
    $publicationScreen = (string)file_get_contents($platformRoot . '/publication.php');
    TestHarness::assertContains(
        'data-spanish-publication-state',
        $artworkScreen,
        'EDITORIAL_CORE Libro VI Cap. 1: la ficha de obra MUESTRA el estado editorial — visible siempre, nunca accionable como publicación'
    );
    TestHarness::assertContains(
        'publication.php',
        $artworkScreen,
        'EDITORIAL_CORE Libro VI Cap. 1 (enmienda 2026-07-31): la ficha señala que publicar se hace en la sección Publicación'
    );
    TestHarness::assertTrue(
        !str_contains($artworkScreen, 'website_intent'),
        'EDITORIAL_CORE Libro VI Cap. 1 (enmienda 2026-07-31): la ficha no contiene ninguna decisión de publicación — una sola puerta'
    );

    // ————— Libro VI Cap. 1 (opción A, reubicada): UNA sola decisión de publicación —————
    TestHarness::assertContains(
        'setSpanishPublished($userId',
        $publicationScreen,
        'EDITORIAL_CORE Libro VI Cap. 1 (opción A): «Publicar Obra» publica también el texto aprobado — un solo acto, ahora en Publicación'
    );
    // Enmienda 2026-08-04 a VI.4: publicar MARCA los mockups desactualizados,
    // no los reescribe. Escribir contenido es de la ficha de la obra; esta
    // seccion lee y distribuye. Antes estas dos aserciones exigian lo contrario.
    TestHarness::assertContains(
        'markMockupsOutdated',
        $publicationScreen,
        'EDITORIAL_CORE Libro VI Cap. 4 (enmienda 2026-08-04): publicar marca los mockups que quedaron de una lectura anterior'
    );
    TestHarness::assertTrue(
        !str_contains($publicationScreen, 'queueMockupCascadeForArtwork') && !str_contains($publicationScreen, 'dispatchCascade'),
        'publicar no regenera el texto de ningun mockup: esa escritura no pertenece a la seccion Publicacion'
    );
    TestHarness::assertContains(
        'readingChangedSincePublish',
        $publicationScreen,
        'y solo marca cuando hay una version nueva de la lectura que propagar'
    );
    TestHarness::assertContains(
        'Generá el contenido editorial antes de publicar la obra',
        $publicationScreen,
        'EDITORIAL_CORE Libro VI Cap. 1: sin contenido generado la obra no se publica (compuerta con mensaje claro)'
    );
    TestHarness::assertContains(
        'outdatedMockupIds',
        (string)file_get_contents($platformRoot . '/app/Services/ArtworkEditorialPackageService.php'),
        'EDITORIAL_CORE Libro VI Cap. 4: la ficha cuenta los mockups marcados, para que el artista vea cuantos hay antes de regenerar'
    );
    TestHarness::assertTrue(
        !str_contains($artworkScreen, "data-spanish-publication data-action"),
        'EDITORIAL_CORE Libro VI Cap. 1 (opción A): la ficha no ofrece un segundo botón de publicar separado — la barra editorial es solo estado'
    );

    // ————— Libro I Cap. 6: herramienta de titulos — sugerir si, decidir jamas —————
    $titleService = (string)file_get_contents($platformRoot . '/app/Services/TitleSuggestionService.php');
    TestHarness::assertContains(
        'saveUniversalTitle',
        $titleService,
        'EDITORIAL_CORE Libro I Cap. 6: solo confirm() — la accion explicita del artista — escribe el titulo al catalogo'
    );
    TestHarness::assertContains(
        'está bloqueado por el artista',
        $titleService,
        'EDITORIAL_CORE Libro VI Cap. 3: un titulo locked es inmutable — confirmar sobre el falla con mensaje claro'
    );
    TestHarness::assertContains(
        'collisionsForTitle',
        $titleService,
        'EDITORIAL_CORE Libro I Cap. 6: control de repeticion exacta y semantica contra el registro antes de proponer'
    );
    TestHarness::assertTrue(
        !str_contains($titleService, 'UPDATE artworks SET final_title'),
        'EDITORIAL_CORE Libro I Cap. 6: el servicio de titulos jamas escribe final_title directo — pasa por saveUniversalTitle con la confirmacion del artista'
    );
    // Funcional: normalizacion y colision lexica INTERVALLA/INTERVALLUM.
    TestHarness::assertSame('NUHRA', TitleSuggestionService::normalize('NUHRĀ'), 'la forma normalizada pierde diacriticos para comparar');
    $titlePdo = new PDO('sqlite::memory:');
    $titlePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $registryMigration = require $platformRoot . '/migrations/schema/20260729_000002_artwork_title_registry.php';
    $titlePdo->exec("CREATE TABLE artwork_series (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, updated_at TEXT)");
    ($registryMigration['up'])($titlePdo);
    $titleTool = new TitleSuggestionService($titlePdo);
    $titleTool->registerCatalogTitle(7, 501, 3, 'INTERVALLUM', 'Latín', 'intervalo', 'intervalo');
    $titleTool->registerCatalogTitle(7, 502, 3, 'LUX REMOTA', 'Latín', 'luz remota', 'luz');
    $titleTool->registerCatalogTitle(7, 503, 3, 'SOL DIVISUS', 'Latín', 'sol dividido', 'luz');
    $hits = $titleTool->collisionsForTitle(7, 'INTERVALLA');
    TestHarness::assertTrue(
        $hits !== [] && $hits[0]['title'] === 'INTERVALLUM',
        'EDITORIAL_CORE Libro I Cap. 6: INTERVALLA colisiona con INTERVALLUM por raiz lexica — el aviso existe (y jamas bloquea)'
    );
    $solarMap = $titleTool->collisionMap(7);
    TestHarness::assertTrue(
        isset($solarMap['luz']) && count($solarMap['luz']) === 2,
        'EDITORIAL_CORE Libro I Cap. 6: el mapa de colisiones agrupa raices saturadas (cluster solar) para decision del artista'
    );
    $titleEndpoint = (string)file_get_contents($platformRoot . '/title_suggestions.php');
    TestHarness::assertContains('confirmacion explicita del artista', $titleEndpoint, 'el endpoint documenta que confirmar es la unica via al catalogo');
    TestHarness::assertContains('data-title-tool', $artworkScreen, 'la ficha de obra ofrece la herramienta de titulos junto al titulo');

    // ————— Libro I Cap. 6: la IA jamas rellena titulos vacios —————
    TestHarness::assertTrue(
        !str_contains($sheetSource, '$suggestedTitle'),
        'EDITORIAL_CORE Libro I Cap. 6: applyAnalysisV2Draft ya no usa titulos sugeridos por el modelo — ni siquiera para obras sin titulo'
    );
}
