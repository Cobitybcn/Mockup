<?php
declare(strict_types=1);

class BilingualEditorialFakeClient extends GeminiImageClient
{
    public string $lastPrompt = '';

    public function generateText(array $parts, string $model = 'gemini-2.5-flash'): string
    {
        $this->lastPrompt = (string)($parts[0]['text'] ?? '');
        $isMockup = str_contains($this->lastPrompt, 'independent contextual image')
            || str_contains($this->lastPrompt, 'contextual mockup image')
            || str_contains($this->lastPrompt, 'MOCKUP COMPLETENESS');
        $isEnglish = str_contains($this->lastPrompt, 'international English');
        if ($isMockup) {
            return json_encode([
                'description' => $isEnglish
                    ? 'A precise contextual reading of the artwork inside a contemporary architectural space.'
                    : 'Una lectura contextual precisa de la obra dentro de un espacio arquitectónico contemporáneo.',
                'tags' => $isEnglish
                    ? 'painting, abstract, contemporary, geometric, minimalist, acrylic, oil, canvas, textured, warm colors'
                    : 'pintura, abstracta, contemporánea, geométrica, minimalista, acrílico, óleo, lienzo, texturada, colores cálidos',
                'search_terms' => $isEnglish
                    ? 'original abstract painting, contemporary abstract art, geometric abstract painting for sale, acrylic and oil painting on canvas, textured minimalist abstract artwork, warm color abstract painting for collectors, original painting for contemporary interiors, abstract artwork for architectural spaces, buy original contemporary abstract painting, large geometric painting for modern interiors, Maurizio Valch original abstract artwork, contemporary canvas painting with texture'
                    : 'pintura abstracta original, arte abstracto contemporáneo, pintura abstracta geométrica en venta, pintura acrílica y óleo sobre lienzo, obra abstracta minimalista texturada, pintura abstracta de colores cálidos para coleccionistas, pintura original para interiores contemporáneos, obra abstracta para espacios arquitectónicos, comprar pintura abstracta contemporánea original, pintura geométrica grande para interiores modernos, obra abstracta original de Maurizio Valch, pintura contemporánea sobre lienzo con textura',
                'seo_title' => $isEnglish
                    ? 'SOL DIVISUS in a contemporary space | Maurizio Valch'
                    : 'SOL DIVISUS en un espacio contemporáneo | Maurizio Valch',
                'seo_description' => $isEnglish
                    ? 'SOL DIVISUS presented as an original acrylic and oil abstract painting in a contemporary architectural setting.'
                    : 'SOL DIVISUS presentada como pintura abstracta original en acrílico y óleo dentro de un espacio arquitectónico contemporáneo.',
                'alt_text' => $isEnglish
                    ? 'Abstract painting displayed on a light wall in a contemporary interior.'
                    : 'Pintura abstracta expuesta sobre una pared clara en un interior contemporáneo.',
                'caption' => $isEnglish
                    ? 'SOL DIVISUS by Maurizio Valch in a contemporary interior.'
                    : 'SOL DIVISUS de Maurizio Valch en un interior contemporáneo.',
                'social' => [
                    'website' => [
                        'description' => $isEnglish ? 'Contextual website description.' : 'Descripción contextual para el website.',
                        'caption' => $isEnglish ? 'Website caption.' : 'Pie para el website.',
                        'alt_text' => $isEnglish ? 'Accessible website image description.' : 'Descripción accesible para la imagen del website.',
                    ],
                    'pinterest' => [
                        'title' => $isEnglish ? 'Original abstract painting in a contemporary interior' : 'Pintura abstracta original en un interior contemporáneo',
                        'description' => $isEnglish ? 'Pinterest description for this exact mockup.' : 'Descripción de Pinterest para este mockup.',
                        'board_suggestions' => $isEnglish ? 'Contemporary abstract painting' : 'Pintura abstracta contemporánea',
                        'topic_suggestions' => $isEnglish ? 'Original art, contemporary interiors' : 'Arte original, interiores contemporáneos',
                        'keywords' => $isEnglish ? 'abstract painting, original art, contemporary interior' : 'pintura abstracta, arte original, interior contemporáneo',
                    ],
                    'instagram' => [
                        'caption' => $isEnglish ? 'Instagram caption for SOL DIVISUS.' : 'Caption de Instagram para SOL DIVISUS.',
                        'hook' => $isEnglish ? 'Color divides the space.' : 'El color divide el espacio.',
                        'hashtags' => $isEnglish ? '#abstractpainting #originalart' : '#pinturaabstracta #arteoriginal',
                        'cta' => $isEnglish ? 'View the complete artwork.' : 'Ver la obra completa.',
                    ],
                    'facebook' => [
                        'headline' => $isEnglish ? 'SOL DIVISUS in context' : 'SOL DIVISUS en contexto',
                        'post_text' => $isEnglish ? 'Facebook text for this contextual image.' : 'Texto de Facebook para esta imagen contextual.',
                        'link_description' => $isEnglish ? 'Open the artwork page.' : 'Abrir la página de la obra.',
                        'cta' => $isEnglish ? 'View artwork' : 'Ver obra',
                    ],
                    'tiktok' => [
                        'visual_hook' => $isEnglish ? 'Begin with the full room.' : 'Comenzar con la sala completa.',
                        'suggested_motion' => $isEnglish ? 'Move slowly toward the painting.' : 'Acercarse lentamente a la pintura.',
                        'sequence_role' => $isEnglish ? 'Context opening.' : 'Apertura contextual.',
                        'caption_seed' => $isEnglish ? 'SOL DIVISUS in space.' : 'SOL DIVISUS en el espacio.',
                        'video_notes' => $isEnglish ? 'Future video preparation only.' : 'Solo preparación para un video futuro.',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE);
        }
        if (str_contains($this->lastPrompt, 'international English for the United States and Europe')) {
            return json_encode([
                'subtitle' => 'Existing subtitle',
                'short_description' => 'An original abstract painting shaped by acrylic and oil on canvas.',
                'description' => 'This contemporary abstract art series presents acrylic and oil painting on canvas through a textured structural abstract painting language.',
                'tags' => 'painting, abstract, contemporary, structural, minimalist, acrylic, oil, canvas, textured, deep colors',
                'search_terms' => 'original abstract painting, contemporary abstract art, abstract painting for sale, acrylic and oil painting on canvas, textured structural abstract painting, minimalist abstract painting for collectors, deep color abstract art for interiors, original contemporary painting by Maurizio Valch, abstract art for private collections, structural painting for architectural interiors, buy original abstract art online, contemporary mixed media abstract painting',
                'seo_title' => 'STRATA | Contemporary abstract painting series | Maurizio Valch',
                'seo_description' => 'International English SEO description',
            ], JSON_UNESCAPED_UNICODE);
        }
        return json_encode([
            'subtitle' => 'Subtítulo existente',
            'short_description' => 'Una pintura abstracta original construida con acrílico y óleo sobre lienzo.',
            'description' => 'Esta serie de arte abstracto contemporáneo trabaja la pintura acrílica y óleo sobre lienzo mediante una pintura abstracta estructural texturada.',
            'tags' => 'pintura, abstracta, contemporánea, estructural, minimalista, acrílico, óleo, lienzo, texturada, colores densos',
            'search_terms' => 'pintura abstracta original, arte abstracto contemporáneo, pintura abstracta en venta, pintura acrílica y óleo sobre lienzo, pintura abstracta estructural texturada, pintura abstracta minimalista para coleccionistas, arte abstracto de colores densos para interiores, pintura contemporánea original de Maurizio Valch, arte abstracto para colecciones privadas, pintura estructural para interiores arquitectónicos, comprar arte abstracto original online, pintura abstracta contemporánea de técnica mixta',
            'seo_title' => 'STRATA | Serie de pintura abstracta contemporánea | Maurizio Valch',
            'seo_description' => 'Descripción SEO en español',
        ], JSON_UNESCAPED_UNICODE);
    }
}

final class BilingualEditorialRepairingMockupClient extends BilingualEditorialFakeClient
{
    public int $calls = 0;

    public function generateText(array $parts, string $model = 'gemini-2.5-flash'): string
    {
        $this->calls++;
        if ($this->calls === 1) {
            $this->lastPrompt = (string)($parts[0]['text'] ?? '');
            return json_encode([
                'description' => 'Contenido parcial',
                'tags' => '',
                'search_terms' => '',
            ], JSON_UNESCAPED_UNICODE);
        }
        return parent::generateText($parts, $model);
    }
}

final class BilingualEditorialStaleIdentityClient extends BilingualEditorialFakeClient
{
    public function generateText(array $parts, string $model = 'gemini-2.5-flash'): string
    {
        $result = parent::generateText($parts, $model);
        if (!str_contains($this->lastPrompt, 'independent contextual image')) return $result;
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) return $result;
        $decoded['description'] = str_replace(
            'SOL DIVISUS',
            'Warm Structures, Distant Sun',
            (string)($decoded['description'] ?? '')
        ) . ' Pertenece a la Serie Core.';
        $decoded['caption'] = str_replace(
            'SOL DIVISUS',
            'Warm Structures, Distant Sun',
            (string)($decoded['caption'] ?? '')
        );
        $decoded['seo_title'] = str_replace(
            'SOL DIVISUS',
            'Warm Structures, Distant Sun',
            (string)($decoded['seo_title'] ?? '')
        );
        $decoded['social']['website']['description'] = 'Warm Structures, Distant Sun pertenece a la Core Series.';
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

function run_bilingual_editorial_service_tests(): void
{
    TestHarness::group('Contenido editorial bilingue');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE artist_profiles (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE artwork_series (
        id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, title TEXT NOT NULL, subtitle TEXT NOT NULL DEFAULT '',
        description TEXT NOT NULL DEFAULT '', long_description TEXT NOT NULL DEFAULT '', tags TEXT NOT NULL DEFAULT '',
        keywords TEXT NOT NULL DEFAULT '', seo_description TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE artworks (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, artwork_group_id INTEGER, series_id INTEGER, series TEXT NOT NULL DEFAULT '', final_title TEXT NOT NULL DEFAULT '', subtitle TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE artwork_groups (
        id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, canonical_artwork_id INTEGER NOT NULL,
        title TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'active', updated_at TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE artwork_sheets (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, canonical_artwork_id INTEGER NOT NULL, title TEXT NOT NULL DEFAULT '', subtitle TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '', short_description TEXT NOT NULL DEFAULT '', keywords TEXT NOT NULL DEFAULT '', tags TEXT NOT NULL DEFAULT '', alt_text TEXT NOT NULL DEFAULT '', caption TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT '', generated_json TEXT NOT NULL DEFAULT '{}', updated_at TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE mockups (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, source_artwork_id INTEGER, mockup_file TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE mockup_sheets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, artwork_sheet_id INTEGER, artwork_id INTEGER NOT NULL, mockup_id INTEGER, mockup_file TEXT NOT NULL, user_notes TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '', keywords TEXT NOT NULL DEFAULT '', tags TEXT NOT NULL DEFAULT '', alt_text TEXT NOT NULL DEFAULT '', caption TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT '', generated_json TEXT NOT NULL DEFAULT '{}', created_at TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT '')");
    $pdo->exec("INSERT INTO users (id,email) VALUES (7,'artist@example.com')");
    $pdo->exec("INSERT INTO artwork_series (id,user_id,title,subtitle,description,long_description,tags,keywords,seo_description) VALUES (3,7,'STRATA','Existing subtitle','Existing summary','Existing curatorial text','abstract','layered painting','Existing SEO')");
    $pdo->exec("INSERT INTO artworks (id,user_id,artwork_group_id,series_id,series,final_title) VALUES (11,7,31,3,'STRATA','Old artwork title')");
    $pdo->exec("INSERT INTO artwork_groups (id,user_id,canonical_artwork_id,title,status) VALUES (31,7,11,'Stale group title','active')");
    $pdo->exec("INSERT INTO artwork_sheets (id,user_id,canonical_artwork_id,title,status) VALUES (41,7,11,'Old sheet title','draft')");
    $pdo->exec("INSERT INTO mockups (id,user_id,source_artwork_id,mockup_file) VALUES (21,7,11,'mockup-test.jpg')");
    $migration = require dirname(__DIR__, 2) . '/migrations/schema/20260722_000002_bilingual_editorial_content.php';
    ($migration['up'])($pdo);
    $publicationMigration = require dirname(__DIR__, 2) . '/migrations/schema/20260722_000003_bilingual_spanish_publication.php';
    ($publicationMigration['up'])($pdo);
    $jobMigration = require dirname(__DIR__, 2) . '/migrations/schema/20260724_000001_bilingual_editorial_jobs.php';
    ($jobMigration['up'])($pdo);
    $service = new BilingualEditorialService($pdo);
    $service->setEnabled(7, true);
    TestHarness::assertTrue($service->isEnabled(7), 'el piloto se habilita solo para el artista elegido');
    TestHarness::assertSame('es', $service->sourceLocale(7), 'el idioma mental del análisis piloto es español');
    $jobs = new BilingualEditorialJobService($pdo);
    $queuedJob = $jobs->createOrReuse(7, 'mockup', 21, 'adapt');
    $sameQueuedJob = $jobs->createOrReuse(7, 'mockup', 21, 'prepare');
    TestHarness::assertSame((int)$queuedJob['id'], (int)$sameQueuedJob['id'], 'una navegación repetida reutiliza la generación editorial activa');
    $claimedJob = $jobs->claim((int)$queuedJob['id']);
    TestHarness::assertSame('processing', (string)($claimedJob['status'] ?? ''), 'el worker reclama la generación persistente una sola vez');
    $jobs->complete((int)$queuedJob['id'], ['english_content' => ['description' => 'Background English']]);
    $completedJob = $jobs->publicState($jobs->job((int)$queuedJob['id'], 7));
    TestHarness::assertSame('completed', $completedJob['status'], 'la generación terminada queda disponible después de abandonar la ficha');
    TestHarness::assertSame('Background English', $completedJob['result']['english_content']['description'] ?? '', 'el resultado persistente puede recuperarse al volver');
    $stalledJob = $jobs->createOrReuse(7, 'artwork', 11, 'adapt');
    $pdo->prepare("UPDATE bilingual_editorial_jobs SET task_name='old-cloud-task',updated_at=? WHERE id=?")
        ->execute([date(DATE_ATOM, time() - 600), (int)$stalledJob['id']]);
    $stalledJob = $jobs->recoverStalledJob($jobs->job((int)$stalledJob['id'], 7));
    TestHarness::assertSame('', (string)$stalledJob['task_name'], 'una tarea editorial en cola y detenida queda lista para reenviarse');
    TestHarness::assertSame(1, (int)$stalledJob['attempts'], 'la recuperación contabiliza el reenvío para evitar un bloqueo infinito');
    TestHarness::assertSame(true, $jobs->needsDispatch($stalledJob), 'el endpoint reconoce que la tarea recuperada necesita una nueva entrega');
    $analysisClient = new BilingualEditorialFakeClient();
    $analysisImage = tempnam(sys_get_temp_dir(), 'analysis_locale_');
    file_put_contents($analysisImage, 'test-image');
    try {
        (new ArtworkAnalysisV2Service($analysisClient))->generateDraft(['id' => 11], [], $analysisImage, '', 'es');
    } catch (RuntimeException) {
        // The fake response is intentionally not a full artwork analysis.
    } finally {
        @unlink($analysisImage);
    }
    TestHarness::assertContains('directly in natural Spanish', $analysisClient->lastPrompt, 'el análisis futuro piensa y redacta directamente en español');

    $seriesProposalClient = new BilingualEditorialFakeClient();
    $seriesProposal = (new BilingualEditorialAdapterService($pdo, $seriesProposalClient))->generateSpanishDraft(
        7,
        'series',
        3,
        [],
        'Contexto privado de la serie'
    );
    TestHarness::assertSame('Esta serie de arte abstracto contemporáneo trabaja la pintura acrílica y óleo sobre lienzo mediante una pintura abstracta estructural texturada.', $seriesProposal['content']['description'] ?? '', 'Series puede generar una propuesta editorial directamente en español');
    TestHarness::assertContains('Think and write in Spanish', $seriesProposalClient->lastPrompt, 'la propuesta de Series no se redacta primero en ingles');
    TestHarness::assertContains('one coherent master description', $seriesProposalClient->lastPrompt, 'Series genera un texto maestro coherente en lugar de fragmentarlo');
    TestHarness::assertContains('Do not split them into keyword, collector, context, acquisition or long-tail interface blocks', $seriesProposalClient->lastPrompt, 'Series elimina los bloques SEO duplicados');
    TestHarness::assertContains('twelve to sixteen distinct, natural phrases a real buyer could type', $seriesProposalClient->lastPrompt, 'Series genera búsquedas amplias y long tails suficientes');
    TestHarness::assertContains('premium art-marketplace discovery model', $seriesProposalClient->lastPrompt, 'Series usa categorías comerciales reconocibles como un catálogo de arte');
    TestHarness::assertContains('Select facets dynamically from supported evidence', $seriesProposalClient->lastPrompt, 'Series selecciona facetas comerciales sin fijar de antemano el estilo');
    TestHarness::assertContains('surrealism, figurative art, expressionism', $seriesProposalClient->lastPrompt, 'la arquitectura funciona con estilos distintos de la abstracción');
    TestHarness::assertContains('XL, XXL or monumental only when confirmed physical dimensions', $seriesProposalClient->lastPrompt, 'Series reserva el lenguaje de gran formato para dimensiones justificadas');
    TestHarness::assertContains('Do not manufacture a long-tail phrase', $seriesProposalClient->lastPrompt, 'Series no disfraza conceptos curatoriales de búsquedas');
    TestHarness::assertContains('Never invent or imply search volume', $seriesProposalClient->lastPrompt, 'la IA propone lenguaje de búsqueda sin inventar métricas externas');
    TestHarness::assertContains('artist-authored title, series explanation', $seriesProposalClient->lastPrompt, 'Series usa la explicación del artista como autoridad');
    TestHarness::assertContains('CONFIRMED MATERIALS AND PROCESS', $seriesProposalClient->lastPrompt, 'Series recibe técnicas y materiales del perfil como fuente explícita');
    TestHarness::assertContains('acrylic with oil finishes must retain both acrylic and oil', $seriesProposalClient->lastPrompt, 'Series no reduce una técnica mixta al primer material');
    TestHarness::assertContains('six must be genuine long tails', $seriesProposalClient->lastPrompt, 'Series exige long tails reales dentro de una sola selección útil');
    TestHarness::assertContains('Do not preserve the previous sentence structure', $seriesProposalClient->lastPrompt, 'Series reconstruye el texto visible en vez de conservar el borrador viejo');
    TestHarness::assertContains('at least three distinct phrases across short_description plus description', $seriesProposalClient->lastPrompt, 'Series integra de forma verificable una selección SEO en el texto público');
    TestHarness::assertContains('do not force robotic exact-match syntax', $seriesProposalClient->lastPrompt, 'Series permite una redacción natural sin perder el vocabulario de búsqueda');
    TestHarness::assertContains('Do not use Markdown emphasis', $seriesProposalClient->lastPrompt, 'Series devuelve texto editorial limpio sin asteriscos de formato');
    TestHarness::assertContains('never emit compressed noun stacks', $seriesProposalClient->lastPrompt, 'Series exige búsquedas españolas con gramática natural');
    TestHarness::assertContains('Keep transactional, collector and professional-context phrases exclusively in SEO metadata', $seriesProposalClient->lastPrompt, 'Series no convierte el texto curatorial en una frase de venta');
    TestHarness::assertContains('twelve to sixteen distinct, natural phrases', $seriesProposalClient->lastPrompt, 'Series mantiene un conjunto SEO amplio pero acotado');
    TestHarness::assertContains('UNIVERSAL SERIES TITLE | one established descriptive category phrase | ARTIST NAME', $seriesProposalClient->lastPrompt, 'Series aplica una plantilla limpia y consistente al título SEO');
    TestHarness::assertTrue(strpos($seriesProposalClient->lastPrompt, 'series_visual_language') === false, 'Series no depende de análisis visuales');
    TestHarness::assertSame('unprepared', $service->get(7, 'series', 3, 'es')['status'], 'generar la propuesta de Series no modifica el borrador español');
    $service->save(7, 'series', 3, 'es', ['description' => 'Texto con **negrita de IA** y `código`']);
    TestHarness::assertSame(
        'Texto con negrita de IA y código',
        $service->get(7, 'series', 3, 'es')['content']['description'] ?? '',
        'el guardado elimina Markdown aunque la IA vuelva a entregarlo'
    );
    $preparedSeries = (new BilingualEditorialAdapterService($pdo, new BilingualEditorialFakeClient()))
        ->prepareBilingualSeries(7, 3, [], 'Contexto privado de la serie');
    TestHarness::assertSame('current', $preparedSeries['status'] ?? '', 'Series termina ambos idiomas dentro de una sola preparación');
    TestHarness::assertSame(
        'Esta serie de arte abstracto contemporáneo trabaja la pintura acrílica y óleo sobre lienzo mediante una pintura abstracta estructural texturada.',
        $service->get(7, 'series', 3, 'es')['content']['description'] ?? '',
        'la preparación completa guarda el español validado'
    );
    TestHarness::assertSame(
        'This contemporary abstract art series presents acrylic and oil painting on canvas through a textured structural abstract painting language.',
        $service->get(7, 'series', 3, 'en')['content']['description'] ?? '',
        'la preparación completa guarda el inglés validado junto al español'
    );
    TestHarness::assertSame(true, $service->get(7, 'series', 3, 'es')['is_published'], 'la preparación completa publica el master español en el website');
    TestHarness::assertSame(
        'Esta serie de arte abstracto contemporáneo trabaja la pintura acrílica y óleo sobre lienzo mediante una pintura abstracta estructural texturada.',
        $service->get(7, 'series', 3, 'es')['published_content']['description'] ?? '',
        'la preparación completa conserva una instantánea pública española'
    );
    $mockupProposalClient = new BilingualEditorialFakeClient();
    $mockupProposal = (new BilingualEditorialAdapterService($pdo, $mockupProposalClient))->generateSpanishDraft(7, 'mockup', 21);
    TestHarness::assertSame('Una lectura contextual precisa de la obra dentro de un espacio arquitectónico contemporáneo.', $mockupProposal['content']['description'] ?? '', 'Mockups puede generar una propuesta editorial directamente en español');
    TestHarness::assertContains('independent contextual image', $mockupProposalClient->lastPrompt, 'la propuesta del mockup conserva su lectura contextual propia');
    TestHarness::assertContains('Never infer artwork pigments from mockup lighting', $mockupProposalClient->lastPrompt, 'el mockup no reinterpreta pigmentos alterados por la escena');
    $staleArtworkAnalysis = [
        'confirmed_facts' => ['series' => 'Core'],
        'canonical_editorial' => [
            'title' => 'Warm Structures, Distant Sun',
            'caption' => 'Warm Structures, Distant Sun, Core Series.',
        ],
    ];
    $pdo->prepare('UPDATE artwork_sheets SET title=?,generated_json=? WHERE id=41')
        ->execute(['VIA SOLIS', json_encode($staleArtworkAnalysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $pdo->exec("UPDATE artworks SET final_title='VIA SOLIS' WHERE id=11");
    $pdo->exec("UPDATE artwork_series SET title='PRIMORDIUM' WHERE id=3");
    $identityProposal = (new BilingualEditorialAdapterService($pdo, new BilingualEditorialStaleIdentityClient()))
        ->generateSpanishDraft(7, 'mockup', 21);
    $identityJson = json_encode($identityProposal['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    TestHarness::assertContains('VIA SOLIS', $identityJson, 'el título maestro actual reemplaza cualquier nombre heredado en el contenido del mockup');
    TestHarness::assertTrue(!str_contains($identityJson, 'Warm Structures, Distant Sun'), 'el mockup no vuelve a publicar el título histórico de la obra');
    TestHarness::assertContains('PRIMORDIUM', $identityJson, 'la serie actual reemplaza la serie heredada en el contenido del mockup');
    TestHarness::assertTrue(!str_contains($identityJson, 'Core Series') && !str_contains($identityJson, 'Serie Core'), 'el mockup no vuelve a publicar la serie histórica');
    $repairingMockupClient = new BilingualEditorialRepairingMockupClient();
    $repairedMockupProposal = (new BilingualEditorialAdapterService($pdo, $repairingMockupClient))->generateSpanishDraft(7, 'mockup', 21);
    TestHarness::assertSame(2, $repairingMockupClient->calls, 'Mockups corrige automáticamente una primera respuesta incompleta');
    TestHarness::assertContains('pintura, abstracta, contemporánea', $repairedMockupProposal['content']['tags'] ?? '', 'la corrección recupera los tags obligatorios');
    TestHarness::assertContains('pintura abstracta geométrica en venta', $repairedMockupProposal['content']['search_terms'] ?? '', 'la corrección recupera búsquedas y long tails');
    TestHarness::assertContains('Instagram', $repairedMockupProposal['content']['social']['instagram']['caption'] ?? '', 'la corrección completa también las adaptaciones de redes');
    TestHarness::assertSame('unprepared', $service->get(7, 'mockup', 21, 'es')['status'], 'generar la propuesta del mockup no modifica su borrador español');
    $spanishMockupAnalysis = [
        'mockup_analysis_v2' => [
            'schema_version' => 'mockup-analysis.v2',
            'analysis_language' => 'es',
            'neutral' => ['contextual_description' => 'Lectura espacial en español'],
            'channels' => ['instagram' => ['caption' => 'Caption español']],
        ],
    ];
    $pdo->prepare("INSERT INTO mockup_sheets
        (user_id,artwork_sheet_id,artwork_id,mockup_id,mockup_file,description,status,generated_json)
        VALUES (7,NULL,11,21,'mockup-test.jpg','Existing English mockup description','draft',?)")
        ->execute([json_encode($spanishMockupAnalysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    TestHarness::assertSame([], $service->seedEnglishFromLegacy(7, 'mockup', 21), 'el contenido inglés heredado ya no se importa');
    TestHarness::assertSame('unprepared', $service->get(7, 'mockup', 21, 'en')['status'], 'el inglés nuevo comienza vacío sin recuperar contenido histórico');
    $service->fillSourceFromAnalysis(7, 'mockup', 21, ['description' => 'Lectura espacial en español', 'social' => ['instagram' => ['caption' => 'Caption español']]]);
    TestHarness::assertSame('Lectura espacial en español', $service->get(7, 'mockup', 21, 'es')['content']['description'] ?? '', 'el análisis automático del mockup alimenta la fuente española');
    $incompleteEnglishSave = $service->save(7, 'mockup', 21, 'en', ['description' => 'Fresh international English content']);
    TestHarness::assertSame('Fresh international English content', $service->get(7, 'mockup', 21, 'en')['content']['description'] ?? '', 'el inglés internacional nuevo se guarda como derivado revisable');
    TestHarness::assertSame('stale', $incompleteEnglishSave['english_status'] ?? '', 'un inglés con campos fuente todavía vacíos nunca figura como current');
    $pdo->exec("UPDATE bilingual_editorial_content SET status='current' WHERE user_id=7 AND entity_type='mockup' AND entity_id=21 AND locale='en'");
    TestHarness::assertSame('stale', $service->get(7, 'mockup', 21, 'en')['status'], 'las fichas históricas marcadas current se autocorrigen al detectar campos ingleses vacíos');
    TestHarness::assertSame('Fresh international English content', (string)$pdo->query("SELECT description FROM mockup_sheets WHERE mockup_id=21 ORDER BY id DESC LIMIT 1")->fetchColumn(), 'el inglés aprobado alimenta las columnas de publicación');
    $storedMockupJson = json_decode((string)$pdo->query("SELECT generated_json FROM mockup_sheets WHERE mockup_id=21 ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
    TestHarness::assertSame('es', $storedMockupJson['mockup_analysis_v2']['analysis_language'] ?? '', 'el análisis base del mockup permanece en español');
    TestHarness::assertSame(false, array_key_exists('mockup_analysis_v2_en', $storedMockupJson), 'el mockup no crea un bloque paralelo en inglés');
    $service->save(7, 'mockup', 21, 'es', $repairedMockupProposal['content']);
    $repairingEnglishClient = new BilingualEditorialRepairingMockupClient();
    $repairedEnglishMockup = (new BilingualEditorialAdapterService($pdo, $repairingEnglishClient))
        ->adaptMissing(7, 'mockup', 21, 'es', 'en');
    TestHarness::assertSame(2, $repairingEnglishClient->calls, 'el inglés del mockup también corrige automáticamente una respuesta incompleta');
    TestHarness::assertSame('current', $repairedEnglishMockup['english_status'] ?? '', 'el inglés solo queda current después de completar todos los campos');
    TestHarness::assertContains('painting, abstract, contemporary', $repairedEnglishMockup['content']['tags'] ?? '', 'la reparación inglesa conserva los tags del master');
    TestHarness::assertContains('geometric abstract painting for sale', $repairedEnglishMockup['content']['search_terms'] ?? '', 'la reparación inglesa conserva búsquedas y long tails');
    $backgroundPrepare = $jobs->createOrReuse(7, 'mockup', 21, 'prepare', [
        'current_spanish' => $service->get(7, 'mockup', 21, 'es')['content'],
        'private_memo' => 'Preparación persistente',
    ]);
    $backgroundResult = (new BilingualEditorialGenerationWorker(
        $pdo,
        new BilingualEditorialAdapterService($pdo, new BilingualEditorialFakeClient())
    ))->process((int)$backgroundPrepare['id']);
    TestHarness::assertSame(true, $backgroundResult['ok'] ?? false, 'el worker completa español e inglés fuera de la ficha');
    TestHarness::assertSame('completed', $backgroundResult['job']['status'] ?? '', 'el resultado del worker queda persistido para recuperarlo al volver');
    TestHarness::assertSame(true, $service->get(7, 'mockup', 21, 'es')['is_published'], 'el worker publica el texto español del mockup antes de finalizar');
    TestHarness::assertSame(
        'Una lectura contextual precisa de la obra dentro de un espacio arquitectónico contemporáneo.',
        $service->get(7, 'mockup', 21, 'es')['published_content']['description'] ?? '',
        'el website recibe la nueva descripción española generada'
    );

    $service->setSpanishPublished(7, 'series', 3, false);
    $service->save(7, 'series', 3, 'es', ['description' => 'Nuevo texto curatorial en español']);
    TestHarness::assertSame(false, $service->get(7, 'series', 3, 'es')['is_published'], 'el español nuevo permanece como borrador privado');
    TestHarness::assertSame('es-en', $service->adaptationDirection(['description' => 'Texto'], [])['direction'] ?? '', 'el master español abre la adaptación al inglés internacional');
    $service->save(7, 'series', 3, 'en', ['description' => 'Old complete English content']);
    $service->save(7, 'series', 3, 'es', ['description' => 'Nuevo texto maestro en español']);
    $rebuiltEnglish = (new BilingualEditorialAdapterService($pdo, new BilingualEditorialFakeClient()))
        ->adaptMissing(7, 'series', 3, 'es', 'en');
    TestHarness::assertSame(
        'This contemporary abstract art series presents acrylic and oil painting on canvas through a textured structural abstract painting language.',
        $rebuiltEnglish['content']['description'] ?? '',
        'Preparar website reemplaza incluso un inglés completo pero desactualizado'
    );
    TestHarness::assertSame(
        'This contemporary abstract art series presents acrylic and oil painting on canvas through a textured structural abstract painting language.',
        (string)$pdo->query('SELECT long_description FROM artwork_series WHERE id=3')->fetchColumn(),
        'Preparar website sincroniza el nuevo inglés con la publicación local'
    );
    $service->save(7, 'series', 3, 'es', ['description' => 'Nuevo texto curatorial en español']);

    $service->setSpanishPublished(7, 'series', 3, true);
    $publishedSpanish = $service->get(7, 'series', 3, 'es');
    TestHarness::assertSame(true, $publishedSpanish['is_published'], 'el artista puede publicar la version española');
    TestHarness::assertSame('Nuevo texto curatorial en español', $publishedSpanish['published_content']['description'] ?? '', 'la publicación conserva una instantánea aprobada');
    $service->save(7, 'series', 3, 'es', ['description' => 'Borrador español posterior']);
    TestHarness::assertSame('Nuevo texto curatorial en español', $service->get(7, 'series', 3, 'es')['published_content']['description'] ?? '', 'editar después no altera la instantánea española publicada');
    TestHarness::assertSame(true, $service->get(7, 'series', 3, 'es')['has_unpublished_changes'], 'la interfaz detecta cuando el borrador español publicado cambió');
    $service->setSpanishPublished(7, 'series', 3, true);
    TestHarness::assertSame('Borrador español posterior', $service->get(7, 'series', 3, 'es')['published_content']['description'] ?? '', 'actualizar español publicado reemplaza la instantánea con el borrador actual');
    TestHarness::assertSame(false, $service->get(7, 'series', 3, 'es')['has_unpublished_changes'], 'la actualización deja sincronizado el contenido público');
    $service->setSpanishPublished(7, 'series', 3, false);
    TestHarness::assertSame(false, $service->get(7, 'series', 3, 'es')['is_published'], 'retirar español no borra el borrador ni su instantánea');

    $service->fillSourceFromAnalysis(7, 'artwork', 11, ['description' => 'Análisis original pensado en español']);
    TestHarness::assertSame('Análisis original pensado en español', $service->get(7, 'artwork', 11, 'es')['content']['description'] ?? '', 'el nuevo análisis alimenta primero la edición española');

    $service->saveUniversalTitle(7, 'series', 3, 'STRATA NOVA');
    TestHarness::assertSame('STRATA NOVA', (string)$pdo->query('SELECT title FROM artwork_series WHERE id=3')->fetchColumn(), 'el titulo universal se guarda una sola vez');
    TestHarness::assertSame('STRATA NOVA', (string)$pdo->query('SELECT series FROM artworks WHERE id=11')->fetchColumn(), 'las obras conservan el nombre universal actualizado de su serie');
    $publicationId = (new PublicationService($pdo))->createForSheet(41, 7);
    $service->saveUniversalTitle(7, 'artwork', 11, 'STRATA IX — VESTIGIA');
    TestHarness::assertSame('STRATA IX — VESTIGIA', (string)$pdo->query('SELECT final_title FROM artworks WHERE id=11')->fetchColumn(), 'la obra conserva un único título maestro');
    TestHarness::assertSame('STRATA IX — VESTIGIA', (string)$pdo->query('SELECT title FROM artwork_sheets WHERE id=41')->fetchColumn(), 'la ficha recibe inmediatamente el título maestro');
    TestHarness::assertSame('STRATA IX — VESTIGIA', (string)$pdo->query('SELECT title FROM artwork_groups WHERE id=31')->fetchColumn(), 'el grupo técnico no conserva un nombre anterior');
    TestHarness::assertSame('STRATA IX — VESTIGIA', (string)$pdo->query('SELECT title FROM publications WHERE id=' . $publicationId)->fetchColumn(), 'la publicación heredada recibe el título maestro');

    $platformRoot = dirname(__DIR__, 2);
    foreach (['series.php', 'artwork.php', 'mockup_bilingual_experiment.php'] as $screen) {
        $spanishScreen = (string)file_get_contents($platformRoot . '/' . $screen);
        TestHarness::assertContains('data-editorial-locale="en"', $spanishScreen, "la mesa expone el tablero inglés internacional en {$screen}");
        TestHarness::assertContains('data-editorial-locale="es"', $spanishScreen, "la fuente española permanece editable en {$screen}");
    }
    foreach (['series.php', 'mockup_bilingual_experiment.php'] as $screen) {
        $assistantScreen = (string)file_get_contents($platformRoot . '/' . $screen);
        TestHarness::assertContains('data-editorial-generate', $assistantScreen, "la generación española está presente en {$screen}");
        TestHarness::assertContains('bilingual-preparation-bar', $assistantScreen, "la preparación completa queda visible como una sola decisión en {$screen}");
        TestHarness::assertTrue(
            strpos($assistantScreen, 'data-editorial-use-proposal') === false,
            "la preparación no exige aplicar otra propuesta intermedia en {$screen}"
        );
        TestHarness::assertTrue(
            strpos($assistantScreen, 'data-spanish-publication') === false,
            "la mesa no exige una publicación española separada en {$screen}"
        );
    }
    $seriesScreen = (string)file_get_contents($platformRoot . '/series.php');
    TestHarness::assertTrue(
        strpos($seriesScreen, 'data-editorial-refresh') === false,
        'actualizar Series regenera el español antes de reconstruir el inglés'
    );
    TestHarness::assertContains('data-series-direction-copy="conceptual_core"', $seriesScreen, 'la explicación de Series usa superficies editoriales');
    TestHarness::assertContains('series-bilingual-field series-bilingual-field--large', $seriesScreen, 'la dirección reutiliza exactamente el componente del texto curatorial');
    TestHarness::assertContains('grid-template-rows:subgrid', $seriesScreen, 'los tableros editoriales comparten filas reales para comparar ES y EN');
    TestHarness::assertContains('grid-row:1 / span 9', $seriesScreen, 'todos los campos SEO permanecen en la misma línea visual');
    TestHarness::assertContains('data-current-series-delete', $seriesScreen, 'la ficha bilingüe conserva una acción visible para eliminar la serie actual');
    TestHarness::assertContains('Sus obras y mockups pasarán a NO SERIE', $seriesScreen, 'eliminar una serie explica el destino de sus obras y mockups');
    TestHarness::assertTrue(
        !str_contains(substr(
            $seriesScreen,
            strpos($seriesScreen, 'data-series-direction-form'),
            strpos($seriesScreen, '</form>', strpos($seriesScreen, 'data-series-direction-form')) - strpos($seriesScreen, 'data-series-direction-form')
        ), '<textarea'),
        'Series no muestra campos textarea para escribir'
    );
    TestHarness::assertTrue(
        strpos($seriesScreen, 'bilingual-preparation-bar') > strpos($seriesScreen, 'series-bilingual-spread'),
        'la preparación aparece debajo de los tableros bilingües'
    );
    $editorScript = (string)file_get_contents($platformRoot . '/bilingual-editorial.js');
    $editorStyles = (string)file_get_contents($platformRoot . '/bilingual-editorial.css');
    TestHarness::assertContains('field.textContent.trim()', $editorScript, 'la detección de idioma funciona aunque el espacio editorial esté plegado');
    TestHarness::assertContains('syncComparisonRows', $editorScript, 'las filas españolas e inglesas se alinean para comparación visual');
    TestHarness::assertContains("window.matchMedia('(max-width: 800px)')", $editorScript, 'la alineación comparativa se retira cuando los tableros se apilan');
    TestHarness::assertContains("'enqueue_prepare'", $editorScript, 'Series, Artworks y Mockups registran la preparación antes de llamar a la IA');
    TestHarness::assertContains("'generation_status'", $editorScript, 'la ficha recupera una generación aunque el artista haya navegado a otra pantalla');
    TestHarness::assertContains('Generación guardada en cola · podés salir de esta ficha', $editorScript, 'la interfaz explica que el trabajo continúa fuera de la ficha');
    TestHarness::assertContains('snapshot.spanish_content', $editorScript, 'la acción persistente recupera el master español validado');
    TestHarness::assertContains('snapshot.english_content', $editorScript, 'la acción persistente recupera el inglés internacional validado');
    TestHarness::assertContains("assistantRequest('publish_spanish')", $editorScript, 'la preparación completa de obras y mockups publica el master español sin otro paso');
    $artworkScreen = (string)file_get_contents($platformRoot . '/artwork.php');
    TestHarness::assertContains('data-editorial-generate', $artworkScreen, 'Artwork puede generar el contenido completo ES y EN desde una sola acción');
    TestHarness::assertContains('Generar contenido ES + EN', $artworkScreen, 'Artwork presenta la acción completa cuando el español está vacío');
    $adapterSource = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialAdapterService.php');
    TestHarness::assertContains("['series', 'artwork', 'mockup']", $adapterSource, 'el generador español admite obras además de Series y Mockups');
    TestHarness::assertContains("'short_description' => ''", $adapterSource, 'la propuesta de Artwork incluye la descripción breve');
    TestHarness::assertContains("editor.addEventListener('focusout'", $editorScript, 'el nombre universal se guarda al terminar la edición sin interrumpir el cursor');
    TestHarness::assertTrue(strpos($editorScript, "schedule('title'") === false, 'el autoguardado no reescribe el título mientras el artista está escribiendo');
    TestHarness::assertContains('grid-template-columns:minmax(280px, 1fr) auto', $editorStyles, 'la preparación reserva espacio estable para texto y decisión');
    TestHarness::assertContains('width:auto !important', $editorStyles, 'el botón de preparación no hereda el ancho global completo');
    TestHarness::assertContains('.is-editorial-generating .editorial-page--english', $editorStyles, 'el tablero inglés queda atenuado mientras la generación continúa');
    $editorEndpoint = (string)file_get_contents($platformRoot . '/bilingual_editorial.php');
    TestHarness::assertContains("in_array(\$locale, ['es', 'en']", $editorEndpoint, 'el endpoint acepta únicamente los dos tableros editoriales previstos');
    TestHarness::assertContains("adaptMissing", $editorEndpoint, 'el endpoint permite generar inglés internacional nuevo desde el español');
    TestHarness::assertContains("prepareBilingualSeries", $editorEndpoint, 'el endpoint guarda Series solo después de validar ambos idiomas');
    TestHarness::assertContains("CloudTasksService::enqueueEditorialGeneration", $editorEndpoint, 'la traducción se entrega a la cola persistente de producción');
    TestHarness::assertContains('recoverStalledJob', $editorEndpoint, 'una traducción detenida se recupera sin bloquear para siempre la ficha');
    $cloudTasksSource = (string)file_get_contents($platformRoot . '/app/Services/CloudTasksService.php');
    TestHarness::assertContains('$oidcToken->setAudience($oidcAudience)', $cloudTasksSource, 'Cloud Tasks autentica contra el origen del worker y no contra la ruta PHP');
    $audienceMethod = new ReflectionMethod(CloudTasksService::class, 'oidcAudience');
    $audienceMethod->setAccessible(true);
    TestHarness::assertSame(
        'https://mockups-worker-example-uc.a.run.app',
        $audienceMethod->invoke(null, 'https://mockups-worker-example-uc.a.run.app/editorial_worker.php'),
        'la audiencia OIDC elimina la ruta que Cloud Run rechaza con HTTP 403'
    );
    $editorWorker = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialGenerationWorker.php');
    TestHarness::assertTrue(
        strpos($editorWorker, 'setSpanishPublished') < strpos($editorWorker, 'adaptMissing'),
        'el master español llega al website antes de comenzar la adaptación inglesa'
    );
    $sheetServiceSource = (string)file_get_contents($platformRoot . '/app/Services/ArtworkSheetService.php');
    TestHarness::assertContains('Think, analyze and write directly in natural Spanish', $sheetServiceSource, 'mockup_analysis_v2 puede pensar directamente en español');
    TestHarness::assertContains("\$decoded['analysis_language'] = \$analysisLocale", $sheetServiceSource, 'el análisis automático registra explícitamente su idioma');
    TestHarness::assertContains("fillSourceFromAnalysis(\$userId, 'mockup'", $sheetServiceSource, 'el análisis automático español alimenta la edición fuente del mockup');
    TestHarness::assertContains('updateMockupAnalysisDraft', $sheetServiceSource, 'el análisis español se persiste sin copiarse a columnas inglesas');
    TestHarness::assertContains("unset(\$generated['mockup_analysis_v2_en'])", $sheetServiceSource, 'un nuevo análisis elimina cualquier bloque inglés anterior');
    $artworkScreen = (string)file_get_contents($platformRoot . '/artwork.php');
    TestHarness::assertTrue(strpos($artworkScreen, 'data-spanish-publication') === false, 'Artwork no exige publicar el español por separado');
    TestHarness::assertContains('website-decision website-save', $artworkScreen, 'Website usa un Decision Block verde pastel para guardar');
    TestHarness::assertContains('website-decision website-unpublish', $artworkScreen, 'Website usa un Decision Block rosa pastel para retirar la publicación');
    TestHarness::assertContains('website-decision website-publish', $artworkScreen, 'Website conserva un Decision Block amarillo pastel para publicar');
    TestHarness::assertContains('generate_spanish_reanalysis_comparison', $artworkScreen, 'la obra puede generar una lectura española independiente desde la imagen');
    TestHarness::assertContains('use_spanish_reanalysis_comparison', $artworkScreen, 'la propuesta española comparativa solo se aplica mediante una decisión explícita');
    TestHarness::assertContains("['comparison_only'] = true", $artworkScreen, 'el reanálisis comparativo no se aplica automáticamente a los campos editoriales');
    TestHarness::assertContains('data-editorial-adapt', $artworkScreen, 'Artwork muestra la flecha para adaptar el español al inglés internacional');
    TestHarness::assertContains('grid-template-rows:auto repeat(9,auto)', $artworkScreen, 'Artwork reserva una fila independiente para cada campo editorial');
    TestHarness::assertContains('grid-row:1 / span 10', $artworkScreen, 'los nueve campos y la cabecera no pueden superponerse');
    $mockupScreen = (string)file_get_contents($platformRoot . '/mockup_bilingual_experiment.php');
    TestHarness::assertContains('data-editorial-adapt', $mockupScreen, 'Mockup muestra la flecha para adaptar el español al inglés internacional');
    TestHarness::assertContains('data-english-status=', $mockupScreen, 'Mockup expone el estado del inglés para mostrar la adaptación cuando corresponde');
    TestHarness::assertContains('grid-template-rows:auto repeat(7,auto)', $mockupScreen, 'Mockup reserva una fila independiente para cada campo editorial');
    TestHarness::assertContains('grid-row:1/span 8', $mockupScreen, 'los siete campos del mockup y la cabecera no pueden superponerse');
    TestHarness::assertContains('This is not a literal translation', (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialAdapterService.php'), 'la flecha reconstruye el inglés naturalmente y no hace una traducción literal');
    $albumScreen = (string)file_get_contents($platformRoot . '/root_album.php');
    $masterTitleRead = strpos($albumScreen, "\$title = trim((string)(\$albumArtwork['final_title'] ?? ''));");
    $groupTitleFallback = strpos($albumScreen, "\$title = trim((string)(\$albumArtwork['group_title'] ?? ''));");
    TestHarness::assertTrue(
        $masterTitleRead !== false && $groupTitleFallback !== false && $masterTitleRead < $groupTitleFallback,
        'la tarjeta de ArtWorks prioriza el título maestro antes que la copia técnica del grupo'
    );
    $mappedAnalysis = ArtworkAnalysisV2::editorialContent([
        'canonical_editorial' => [
            'subtitle' => 'Subtítulo desde imagen',
            'master_description' => 'Descripción desde imagen',
            'short_description' => 'Resumen',
            'artist_vocabulary' => ['territorio', 'estratos'],
            'alt_text' => 'Descripción visible',
            'caption' => 'Caption',
        ],
        'search_metadata' => [
            'catalogue_tags' => ['pintura', 'abstracto'],
            'search_terms' => ['pintura abstracta original'],
            'seo_title' => 'Obra abstracta original | Maurizio Valch',
            'seo_description' => 'Pintura abstracta original de Maurizio Valch.',
        ],
    ]);
    TestHarness::assertSame('Descripción desde imagen', $mappedAnalysis['description'], 'el reanálisis se proyecta al mismo esquema editorial español');
    TestHarness::assertSame('pintura abstracta original', $mappedAnalysis['search_terms'], 'el reanálisis conserva una sola selección de búsquedas reales');
    TestHarness::assertSame('Obra abstracta original | Maurizio Valch', $mappedAnalysis['seo_title'], 'el reanálisis entrega un título SEO específico');
}
