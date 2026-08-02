<?php
declare(strict_types=1);

/**
 * Custodia del Panel Distribución (Etapa 3 de PUBLICACION_DISENO.md): los
 * adaptadores publican leyendo SOLO el producto congelado, con compuertas de
 * dominio antes de cualquier transporte, un idioma por envío, y errores que
 * jamás sobreviven a un reintento exitoso (IV.16). Cero red: transports falsos.
 */
function run_publication_distribution_service_tests(): void
{
    TestHarness::group('Publicación: distribución por destino (adaptadores)');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec("CREATE TABLE artworks (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, final_title TEXT NOT NULL DEFAULT '', series_id INTEGER, series TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE artwork_sheets (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, canonical_artwork_id INTEGER NOT NULL,
        source_image_file TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '', subtitle TEXT NOT NULL DEFAULT '',
        description TEXT NOT NULL DEFAULT '', short_description TEXT NOT NULL DEFAULT '', keywords TEXT NOT NULL DEFAULT '',
        tags TEXT NOT NULL DEFAULT '', alt_text TEXT NOT NULL DEFAULT '', caption TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'draft')");
    $pdo->exec("CREATE TABLE mockups (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, mockup_file TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE mockup_sheets (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, artwork_sheet_id INTEGER NOT NULL,
        mockup_id INTEGER, mockup_file TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '',
        keywords TEXT NOT NULL DEFAULT '', tags TEXT NOT NULL DEFAULT '', alt_text TEXT NOT NULL DEFAULT '', caption TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE bilingual_editorial_content (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, entity_type TEXT NOT NULL,
        entity_id INTEGER NOT NULL, locale TEXT NOT NULL, content_json TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'source',
        is_published INTEGER NOT NULL DEFAULT 0, published_content_json TEXT NOT NULL DEFAULT '', UNIQUE(user_id,entity_type,entity_id,locale))");
    $pdo->exec("CREATE TABLE user_language_policy (user_id INTEGER PRIMARY KEY, working_locale TEXT NOT NULL,
        publication_locales_json TEXT NOT NULL DEFAULT '', interface_locale TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE artwork_video_publications (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, artwork_id INTEGER NOT NULL,
        video_export_id INTEGER NOT NULL, published_at TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT '')");
    foreach (['pinterest_connections', 'meta_connections', 'instagram_connections', 'tiktok_connections'] as $table) {
        $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, purpose TEXT NOT NULL DEFAULT 'artist',
            pinterest_account_id TEXT DEFAULT '', meta_user_id TEXT DEFAULT '', meta_user_name TEXT DEFAULT '', token_expires_at TEXT DEFAULT '',
            page_id TEXT DEFAULT '', page_name TEXT DEFAULT '', instagram_account_id TEXT DEFAULT '', instagram_username TEXT DEFAULT '',
            instagram_user_id TEXT DEFAULT '', username TEXT DEFAULT '', account_type TEXT DEFAULT '',
            tiktok_open_id TEXT DEFAULT '', tiktok_username TEXT DEFAULT '', tiktok_display_name TEXT DEFAULT '',
            scopes TEXT DEFAULT '', status TEXT NOT NULL DEFAULT 'disconnected', connected_at TEXT DEFAULT '', access_token_expires_at TEXT DEFAULT '')");
    }
    $pdo->exec('CREATE TABLE app_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
    foreach (['20260731_000001_publication_products.php', '20260731_000002_publication_distributions.php', '20260731_000003_publication_distribution_series.php'] as $migrationFile) {
        $migration = require dirname(__DIR__, 2) . '/migrations/schema/' . $migrationFile;
        $migration['up']($pdo);
    }

    $userId = 9;
    $json = static fn(array $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->exec("INSERT INTO user_language_policy VALUES (9,'es','[\"es\",\"en\"]','es')");
    $pdo->exec("INSERT INTO artworks (id,user_id,final_title) VALUES (101,9,'STRATA DIST — UNUM')");
    $pdo->exec("INSERT INTO artwork_sheets (id,user_id,canonical_artwork_id,title,description,short_description) VALUES (1,9,101,'STRATA DIST — UNUM','Desc','Short')");
    $pdo->exec("INSERT INTO mockups (id,user_id,mockup_file) VALUES (71,9,'dist-cover.jpg'),(72,9,'dist-second.jpg'),(73,9,'dist-third.jpg'),(74,9,'dist-fourth.jpg')");
    $pdo->exec("INSERT INTO mockup_sheets (id,user_id,artwork_sheet_id,mockup_id,mockup_file,title,alt_text,caption) VALUES
        (12,9,1,71,'dist-cover.jpg','Cover','Item alt','Item cap'),
        (13,9,1,72,'dist-second.jpg','Second','Item alt 2','Item cap 2'),
        (14,9,1,73,'dist-third.jpg','Third','Item alt 3','Item cap 3'),
        (15,9,1,74,'dist-fourth.jpg','Fourth','Item alt 4','Item cap 4')");

    $publicationService = new PublicationService($pdo);
    $pdo->exec("ALTER TABLE publications ADD COLUMN saatchi_url TEXT NOT NULL DEFAULT ''");
    $now = date('c');
    $pdo->prepare("INSERT INTO publications
        (user_id, artwork_sheet_id, slug, title, description, short_description, language, objective, cta_label, cta_url,
         visibility, status, content_source, profile_snapshot_json, metadata_snapshot_json, published_at, created_at, updated_at, header_file)
        VALUES (9,1,'strata-dist','STRATA DIST — UNUM','Desc','Short','en','portfolio','','','public','published','inherit','{}','{}',?,?,?,'dist-cover.jpg')")
        ->execute([$now, $now, $now]);
    $publicationId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO publication_items (publication_id,mockup_sheet_id,position,role,title,alt_text,caption) VALUES
        (?,12,0,'cover','Cover','Item alt','Item cap'),
        (?,13,1,'context','Second','Item alt 2','Item cap 2'),
        (?,14,2,'context','Third','Item alt 3','Item cap 3'),
        (?,15,3,'context','Fourth','Item alt 4','Item cap 4')")
        ->execute([$publicationId, $publicationId, $publicationId, $publicationId]);

    $coverContent = [
        'caption' => 'Cap ES', 'alt_text' => 'Alt ES', 'search_terms' => 'termino uno, termino dos',
        'social' => [
            'pinterest' => ['title' => 'Pin ES', 'description' => 'Pin desc ES', 'board_suggestions' => ['Tablero Uno'], 'keywords' => []],
            'instagram' => ['caption' => 'IG caption ES', 'hook' => 'Hook', 'hashtags' => ['#arte'], 'cta' => 'CTA ES'],
            'facebook' => ['headline' => 'FB H ES', 'post_text' => 'FB post ES', 'link_description' => 'FB link ES', 'cta' => 'FB CTA ES'],
            'tiktok' => ['visual_hook' => 'H', 'suggested_motion' => 'M', 'sequence_role' => 'R', 'caption_seed' => 'Semilla TT ES', 'video_notes' => 'N'],
        ],
    ];
    $coverEn = $coverContent;
    $coverEn['alt_text'] = 'Alt EN';
    $coverEn['social']['pinterest']['title'] = 'Pin EN';
    $coverEn['social']['instagram']['caption'] = 'IG caption EN';
    $coverEn['social']['instagram']['hashtags'] = ['#art', '#abstract'];
    $coverEn['social']['facebook']['headline'] = 'FB H EN';
    $coverEn['social']['tiktok']['caption_seed'] = 'Seed TT EN';
    $artworkEs = ['subtitle' => 'S', 'description' => 'D ES', 'short_description' => 'SD', 'tags' => 'pintura abstracta, rojo',
        'search_terms' => 'st', 'seo_title' => 't', 'seo_description' => 'd', 'alt_text' => 'a', 'caption' => 'c'];
    $artworkEn = $artworkEs;
    $artworkEn['description'] = 'D EN';
    $artworkEn['tags'] = 'abstract painting, red field';

    $insertContent = $pdo->prepare('INSERT INTO bilingual_editorial_content
        (user_id,entity_type,entity_id,locale,content_json,status,is_published,published_content_json) VALUES (?,?,?,?,?,?,?,?)');
    $insertContent->execute([9, 'artwork', 101, 'es', $json(['description' => 'draft']), 'source', 1, $json($artworkEs)]);
    $insertContent->execute([9, 'artwork', 101, 'en', $json($artworkEn), 'current', 0, '']);
    $insertContent->execute([9, 'mockup', 71, 'es', $json(['caption' => 'draft']), 'source', 1, $json($coverContent)]);
    $insertContent->execute([9, 'mockup', 71, 'en', $json($coverEn), 'current', 0, '']);
    $secondEn = $coverEn;
    $secondEn['alt_text'] = 'Alt EN 2';
    $secondEn['social']['pinterest']['title'] = 'Pin EN segundo';
    $insertContent->execute([9, 'mockup', 72, 'es', $json(['caption' => 'draft 2']), 'source', 1, $json($coverContent)]);
    $insertContent->execute([9, 'mockup', 72, 'en', $json($secondEn), 'current', 0, '']);
    $insertContent->execute([9, 'mockup', 73, 'es', $json(['caption' => 'draft 3']), 'source', 1, $json($coverContent)]);
    $insertContent->execute([9, 'mockup', 74, 'es', $json(['caption' => 'draft 4']), 'source', 1, $json($coverContent)]);

    $productService = new PublicationProductService($pdo, $publicationService);

    $captured = [];
    $failNext = ['value' => false];
    $pinFailKeys = ['keys' => []];
    $capture = static function (string $name) use (&$captured, &$failNext): callable {
        return static function (array $request) use ($name, &$captured, &$failNext): array {
            if ($failNext['value']) {
                $failNext['value'] = false;
                throw new RuntimeException('transport exploded');
            }
            $captured[$name][] = $request;
            return match ($name) {
                'tiktok' => ['external_id' => 'publish-123', 'status' => 'processing'],
                'tiktok_status' => ['status' => 'published'],
                'tiktok_carousel' => ['external_id' => 'carousel-777', 'external_url' => '', 'status' => 'inbox'],
                'tiktok_carousel_status' => ['status' => 'published'],
                default => ['external_id' => 'ext-' . $name, 'external_url' => 'https://example.com/' . $name],
            };
        };
    };
    $pinterestFake = static function (array $request) use (&$captured, &$pinFailKeys): array {
        $captured['pinterest'][] = $request;
        $results = [];
        foreach ((array)$request['items'] as $item) {
            $key = (string)$item['key'];
            $results[] = in_array($key, $pinFailKeys['keys'], true)
                ? ['key' => $key, 'external_id' => '', 'external_url' => '', 'error' => 'pin rejected']
                : ['key' => $key, 'external_id' => 'pin-' . $key, 'external_url' => 'https://www.pinterest.com/pin/pin-' . $key . '/', 'error' => ''];
        }
        return ['items' => $results, 'external_id' => 'board-resolved'];
    };
    $service = new PublicationDistributionService($pdo, $productService, $publicationService, [
        'pinterest' => $pinterestFake,
        'facebook' => $capture('facebook'),
        'instagram' => $capture('instagram'),
        'tiktok' => $capture('tiktok'),
        'tiktok_status' => $capture('tiktok_status'),
        'tiktok_carousel' => $capture('tiktok_carousel'),
        'tiktok_carousel_status' => $capture('tiktok_carousel_status'),
        'instagram_video' => $capture('instagram_video'),
        'facebook_video' => $capture('facebook_video'),
        'schedule' => $capture('schedule'),
    ]);

    // ————— Motor: el producto se genera solo, sin acto del artista —————
    TestHarness::assertTrue($productService->find($publicationId, $userId) === null, 'antes del primer envío no existe producto congelado');
    $noConnectionEarly = false;
    try {
        $service->publish($publicationId, $userId, 'pinterest', 'en', []);
    } catch (RuntimeException $e) {
        $noConnectionEarly = true;
    }
    TestHarness::assertTrue($noConnectionEarly, 'el primer envío sigue gateado por conexión');
    TestHarness::assertTrue($productService->find($publicationId, $userId) !== null, 'el motor generó el producto solo, sin «Generar Producto»');
    TestHarness::assertTrue(($captured['pinterest'] ?? []) === [], 'ningún transporte se invoca cuando la compuerta bloquea');

    // ————— Sin conexión: bloquea —————
    $noConnection = false;
    try {
        $service->publish($publicationId, $userId, 'pinterest', 'en', []);
    } catch (RuntimeException $e) {
        $noConnection = str_contains($e->getMessage(), 'Conexiones') || str_contains($e->getMessage(), 'Connections');
    }
    TestHarness::assertTrue($noConnection, 'un destino sin conexión bloquea apuntando a Conexiones');
    $pdo->exec("INSERT INTO pinterest_connections (user_id,purpose,status) VALUES (9,'artist','connected')");
    $pdo->exec("INSERT INTO meta_connections (user_id,purpose,status) VALUES (9,'artist','connected')");
    $pdo->exec("INSERT INTO instagram_connections (user_id,purpose,status) VALUES (9,'artist','connected')");
    $pdo->exec("INSERT INTO tiktok_connections (user_id,purpose,status) VALUES (9,'artist','connected')");

    // ————— defaultLocale + locale inválido —————
    $product = $productService->find($publicationId, $userId);
    TestHarness::assertSame('en', PublicationDistributionService::defaultLocale($product['payload']), 'el idioma por defecto del envío es la adaptación internacional');
    $badLocale = false;
    try {
        $service->publish($publicationId, $userId, 'pinterest', 'fr', []);
    } catch (InvalidArgumentException $e) {
        $badLocale = true;
    }
    TestHarness::assertTrue($badLocale, 'un idioma fuera del producto se rechaza — un envío, un idioma');

    // ————— Pinterest: serie completa de pins, tablero auto-resuelto, reintento parcial —————
    $pinFailKeys['keys'] = ['13'];
    $pinState = $service->publish($publicationId, $userId, 'pinterest', 'en', []);
    $pinRequest = $captured['pinterest'][0];
    TestHarness::assertSame(4, count($pinRequest['items']), 'un solo acto publica la serie completa: un pin por imagen de la composición');
    TestHarness::assertSame('Pin EN', (string)$pinRequest['items'][0]['title'], 'cada pin lleva el copy editorial de SU imagen');
    TestHarness::assertSame('Pin EN segundo', (string)$pinRequest['items'][1]['title'], 'el segundo pin lleva su propio título, no el de la portada');
    TestHarness::assertSame('Alt EN', (string)$pinRequest['items'][0]['alt_text'], 'cada pin lleva el alt de su imagen en el idioma elegido');
    TestHarness::assertSame('Tablero Uno', (string)$pinRequest['board_name'], 'el tablero lo resuelve el sistema desde la sugerencia editorial — nunca el artista');
    TestHarness::assertTrue(str_contains((string)$pinRequest['link'], 'strata-dist'), 'los pins enlazan a la página de la obra en el sitio del artista');
    TestHarness::assertSame('partial', (string)$pinState['status'], 'un pin rechazado deja la serie en PARCIAL');
    TestHarness::assertTrue($pinState['published_count'] === 3 && $pinState['total_count'] === 4, 'el estado muestra 3/4 pins publicados');
    $pinFailKeys['keys'] = [];
    $retryPinState = $service->publish($publicationId, $userId, 'pinterest', 'en', []);
    $retryRequest = $captured['pinterest'][1];
    TestHarness::assertSame(1, count($retryRequest['items']), 'el reintento SOLO repite los pins fallidos — jamás duplica los publicados');
    TestHarness::assertSame('13', (string)$retryRequest['items'][0]['key'], 'el reintento apunta exactamente al pin que falló');
    TestHarness::assertTrue($retryPinState['status'] === 'published' && $retryPinState['error'] === '', 'la serie completa queda PINS PUBLICADOS y el error desaparece (IV.16)');
    TestHarness::assertTrue($retryPinState['published_count'] === 4 && $retryPinState['total_count'] === 4, 'el estado final muestra 4/4 pins');

    // ————— Series sociales: parte 1 ahora, el resto programado con el lapso —————
    TestHarness::assertSame(12, $service->seriesGapHours($userId), 'el lapso default de la cadencia es 12 horas');
    $igState = $service->publish($publicationId, $userId, 'instagram', 'en', []);
    $igRequest = $captured['instagram'][0];
    TestHarness::assertSame(['dist-cover.jpg', 'dist-second.jpg', 'dist-third.jpg'], (array)$igRequest['image_files'], 'la parte 1 es un carrusel con las 3 primeras imágenes');
    TestHarness::assertSame(1, (int)$igRequest['part'], 'la parte 1 sale al momento');
    TestHarness::assertSame(['#art', '#abstract'], json_decode((string)$igRequest['draft']['hashtags'], true), 'el post lleva los hashtags de su imagen líder como JSON');
    TestHarness::assertTrue(str_contains((string)$igRequest['draft']['description'], 'IG caption EN'), 'el post usa el caption compuesto de su líder en el idioma elegido');
    $scheduleCall = $captured['schedule'][0] ?? null;
    TestHarness::assertTrue(is_array($scheduleCall), 'la parte 2 se programa vía el motor de tareas');
    $expectedGapSeconds = 12 * 3600;
    $actualGapSeconds = strtotime((string)$scheduleCall['when']) - time();
    TestHarness::assertTrue(abs($actualGapSeconds - $expectedGapSeconds) < 120, 'la parte 2 sale a las 12 horas (lapso default)');
    TestHarness::assertSame('scheduled', (string)$igState['status'], 'la serie queda SERIE PROGRAMADA mientras hay partes pendientes');
    TestHarness::assertSame(1, (int)$igState['published_count'], 'la parte 1 ya está publicada');
    TestHarness::assertSame(2, (int)$igState['total_count'], '4 imágenes → 2 posts (3+1)');
    $scheduledPart = null;
    foreach ($igState['parts'] as $partState) {
        if ($partState['status'] === 'scheduled') $scheduledPart = $partState;
    }
    TestHarness::assertTrue($scheduledPart !== null && $scheduledPart['scheduled_at'] !== '', 'el estado muestra PROGRAMADO con su fecha visible');

    // El worker dispara la parte programada y es idempotente.
    $scheduledRowId = (int)$pdo->query("SELECT id FROM publication_distributions WHERE destination='instagram' AND status='scheduled'")->fetchColumn();
    $service->runScheduledSend($scheduledRowId);
    $afterWorker = $service->states($publicationId, $userId)['instagram'];
    TestHarness::assertSame('published', (string)$afterWorker['status'], 'el worker publica la parte programada y la serie queda SERIE PUBLICADA');
    $instagramCallsBefore = count($captured['instagram']);
    $service->runScheduledSend($scheduledRowId);
    TestHarness::assertSame($instagramCallsBefore, count($captured['instagram']), 'una fila ya publicada es no-op para el worker (idempotencia)');
    $notPending = false;
    try {
        $service->publishSeriesPartNow($publicationId, $userId, 'instagram', 2);
    } catch (RuntimeException $e) {
        $notPending = true;
    }
    TestHarness::assertTrue($notPending, 'una parte publicada no puede reenviarse');
    $service->setSeriesGapHours($userId, 5);
    TestHarness::assertSame(5, $service->seriesGapHours($userId), 'el lapso es configurable una vez por usuario');
    $service->setSeriesGapHours($userId, 12);

    // ————— Facebook: serie con draft-shaped por parte —————
    $service->publish($publicationId, $userId, 'facebook', 'en', []);
    $fbDraft = $captured['facebook'][0]['draft'];
    TestHarness::assertSame('facebook', (string)$fbDraft['channel'], 'facebook usa el publisher real con array draft-shaped');
    TestHarness::assertSame('FB H EN', (string)$fbDraft['title'], 'facebook toma el headline del líder del post');
    TestHarness::assertSame('[]', (string)$fbDraft['hashtags'], 'facebook manda hashtags como string JSON vacío');
    TestHarness::assertSame('image', (string)$fbDraft['source_type'], 'facebook publica imágenes de la composición');
    TestHarness::assertSame(3, count((array)$captured['facebook'][0]['image_files']), 'facebook parte 1 lleva su grupo de imágenes');

    // ————— TikTok: sin video bloquea; con video envía y refresca —————
    $noVideo = false;
    try {
        $service->publish($publicationId, $userId, 'tiktok', 'en', []);
    } catch (RuntimeException $e) {
        $noVideo = str_contains($e->getMessage(), 'video');
    }
    TestHarness::assertTrue($noVideo, 'tiktok sin video en la página bloquea con mensaje claro');
    $pdo->exec('INSERT INTO artwork_video_publications (user_id,artwork_id,video_export_id) VALUES (9,101,55)');
    $productService->generate($publicationId, $userId); // el video cambió las fuentes → regenerar
    $tiktokState = $service->publish($publicationId, $userId, 'tiktok', 'en', []);
    TestHarness::assertSame('processing', (string)$tiktokState['status'], 'tiktok queda ENVIADO · PROCESANDO');
    TestHarness::assertSame(55, (int)$captured['tiktok'][0]['video_export_id'], 'tiktok envía el video congelado en el producto');
    TestHarness::assertTrue(str_starts_with((string)$captured['tiktok'][0]['caption'], 'Seed TT EN'), 'tiktok usa el caption del producto en el idioma elegido');
    $refreshed = $service->refreshTikTokStatus($publicationId, $userId);
    TestHarness::assertSame('published', (string)$refreshed['status'], 'consultar estado mapea PUBLISH_COMPLETE a PUBLICADO');

    // ————— Meta: el reel es un cuarto acto, no una parte de la serie —————
    // La serie de Instagram ya se publicó más arriba; enviar el reel no puede
    // tocarla, ni contarse entre sus partes, ni compartir su fila.
    $igSeriesBefore = $service->states($publicationId, $userId)['instagram'];
    $reelState = $service->publish($publicationId, $userId, 'instagram_video', 'en', []);
    $igSeriesAfter = $service->states($publicationId, $userId)['instagram'];

    TestHarness::assertSame('published', (string)$reelState['status'], 'el reel de Instagram queda publicado en su propia fila');
    TestHarness::assertSame(1, count($captured['instagram_video'] ?? []), 'el reel usa el transporte de video, no el de la serie');
    TestHarness::assertSame('video', (string)$captured['instagram_video'][0]['draft']['source_type'], 'el draft del reel se marca como video: el publisher lo manda como REELS');
    TestHarness::assertTrue(
        str_contains((string)$captured['instagram_video'][0]['video_url'], 'publication_video_media.php'),
        'el reel apunta al video público de la página, que es lo que Meta va a buscar'
    );
    TestHarness::assertSame(
        (int)($igSeriesBefore['published_count'] ?? 0),
        (int)($igSeriesAfter['published_count'] ?? 0),
        'la serie de Instagram no suma partes por haber mandado el reel'
    );
    TestHarness::assertSame(
        (string)($igSeriesBefore['status'] ?? ''),
        (string)($igSeriesAfter['status'] ?? ''),
        'el estado de la serie de Instagram queda intacto'
    );

    $fbPhotosBefore = count($captured['facebook'] ?? []);
    $fbReel = $service->publish($publicationId, $userId, 'facebook_video', 'en', []);
    TestHarness::assertSame('published', (string)$fbReel['status'], 'el video de Facebook queda publicado en su propia fila');
    TestHarness::assertSame('video', (string)$captured['facebook_video'][0]['draft']['source_type'], 'el draft de Facebook se marca como video: el publisher usa /videos');
    TestHarness::assertSame($fbPhotosBefore, count($captured['facebook'] ?? []), 'mandar el video de Facebook no invoca el transporte de fotos');

    // ————— TikTok: carrusel y video conviven como dos publicaciones —————
    $videoStateBefore = $service->states($publicationId, $userId)['tiktok'];
    $carouselState = $service->publish($publicationId, $userId, 'tiktok', 'en', ['medium' => 'carousel']);
    $carouselRequest = $captured['tiktok_carousel'][0];
    TestHarness::assertSame(['dist-cover.jpg', 'dist-second.jpg', 'dist-third.jpg', 'dist-fourth.jpg'], (array)$carouselRequest['image_files'], 'el carrusel viaja con toda la composición');
    TestHarness::assertSame(0, (int)$carouselRequest['cover_index'], 'la portada del carrusel es la de la composición');
    TestHarness::assertTrue(trim((string)$carouselRequest['title']) !== '', 'el carrusel lleva el título corto que exige Creator\'s Draft');
    TestHarness::assertSame('inbox', (string)$carouselState['status'], 'el carrusel queda TE ESPERA EN TIKTOK — nunca "publicado" sin confirmación');
    $videoStateAfter = $service->states($publicationId, $userId)['tiktok'];
    TestHarness::assertSame((string)$videoStateBefore['status'], (string)$videoStateAfter['status'], 'enviar el carrusel no toca el estado del video');
    TestHarness::assertSame('carousel-777', (string)$carouselState['external_id'], 'el carrusel guarda su propio publish_id');
    $refreshedCarousel = $service->refreshTikTokStatus($publicationId, $userId, 'carousel');
    TestHarness::assertSame('published', (string)$refreshedCarousel['status'], 'cuando el artista lo publica desde su bandeja, el estado se cierra en PUBLICADO');
    TestHarness::assertSame('publish-123', (string)$service->states($publicationId, $userId)['tiktok']['external_id'], 'el video conserva su propio id, independiente del carrusel');
    TestHarness::assertSame('inbox', PublicationDistributionService::mapTikTokStatus('SEND_TO_USER_INBOX'), 'SEND_TO_USER_INBOX se traduce a "te espera en TikTok"');
    TestHarness::assertSame('published', PublicationDistributionService::mapTikTokStatus('PUBLISH_COMPLETE'), 'PUBLISH_COMPLETE es el único que se traduce a publicado');

    // ————— Motor: una fuente cambiada regenera el producto sola en el próximo envío —————
    $fingerprintBefore = (string)$productService->find($publicationId, $userId)['source_fingerprint'];
    $pdo->prepare("UPDATE bilingual_editorial_content SET published_content_json=? WHERE user_id=9 AND entity_type='mockup' AND entity_id=71 AND locale='es'")
        ->execute([$json(['caption' => 'cambiada', 'alt_text' => 'Alt ES v2', 'search_terms' => 'mockup interior es', 'social' => $coverContent['social']])]);
    $service->publish($publicationId, $userId, 'pinterest', 'en', []);
    $fingerprintAfter = (string)$productService->find($publicationId, $userId)['source_fingerprint'];
    TestHarness::assertTrue($fingerprintBefore !== $fingerprintAfter, 'el motor regeneró el producto solo al detectar la fuente cambiada — sin acto del artista');

    // ————— IV.16: un error jamás sobrevive a un reintento exitoso —————
    $failNext['value'] = true;
    $failed = false;
    try {
        $service->publish($publicationId, $userId, 'tiktok', 'en', []);
    } catch (RuntimeException $e) {
        $failed = true;
    }
    $failedState = $service->states($publicationId, $userId)['tiktok'];
    TestHarness::assertTrue($failed && $failedState['status'] === 'failed' && $failedState['error'] !== '', 'un transporte fallido deja FALLÓ con su error visible');
    $retryState = $service->publish($publicationId, $userId, 'tiktok', 'en', []);
    TestHarness::assertTrue($retryState['status'] === 'processing' && $retryState['error'] === '', 'el reintento exitoso reemplaza el error por completo (IV.16)');

    // ————— Un solo acto: todo lo conectado, cada uno con su mecánica —————
    $pdo->exec('DELETE FROM publication_distributions');
    $pdo->exec("UPDATE instagram_connections SET status='disconnected'");
    $summary = $service->publishAllConnected($publicationId, $userId, 'en');
    TestHarness::assertSame('sent', (string)$summary['results']['pinterest']['status'], 'el acto único dispara Pinterest');
    TestHarness::assertSame('sent', (string)$summary['results']['facebook']['status'], 'el acto único dispara la serie de Facebook');
    TestHarness::assertSame('skipped', (string)$summary['results']['instagram']['status'], 'un destino sin conexión se saltea, no rompe');
    TestHarness::assertSame('sent', (string)$summary['results']['tiktok']['status'], 'el acto único dispara TikTok');
    TestHarness::assertSame('sent', (string)$summary['results']['facebook_video']['status'], 'el acto único también manda el reel de Facebook cuando la página tiene video');
    TestHarness::assertSame('skipped', (string)$summary['results']['instagram_video']['status'], 'el reel de una red sin conexión se saltea igual que su serie');
    TestHarness::assertSame('skipped', (string)$summary['results']['x']['status'], 'X sin conexión se saltea como cualquier otra red');
    TestHarness::assertTrue($summary['sent'] === 4 && $summary['skipped'] === 3 && $summary['failed'] === 0, 'el resumen cuenta enviados, salteados y fallidos, reel y X incluidos');

    $repeat = $service->publishAllConnected($publicationId, $userId, 'en');
    TestHarness::assertSame('skipped', (string)$repeat['results']['pinterest']['status'], 'repetir el acto no vuelve a publicar lo ya publicado');
    TestHarness::assertSame('skipped', (string)$repeat['results']['facebook']['status'], 'una serie ya iniciada no se reinicia');
    TestHarness::assertSame('skipped', (string)$repeat['results']['tiktok']['status'], 'un envío de TikTok en curso no se duplica');

    $pdo->exec("UPDATE instagram_connections SET status='connected'");
    $pdo->exec('DELETE FROM publication_distributions');
    $failNext['value'] = true;
    $withFailure = $service->publishAllConnected($publicationId, $userId, 'en');
    TestHarness::assertTrue($withFailure['failed'] === 1, 'un destino que falla queda registrado como fallido');
    TestHarness::assertTrue($withFailure['sent'] >= 2, 'el fallo de un destino no detiene a los demás');
    $pdo->exec('DELETE FROM publication_distributions');

    // ————— Saatchi manual + X reservado —————
    $service->markSaatchiUploaded($publicationId, $userId);
    TestHarness::assertSame('uploaded', (string)$service->states($publicationId, $userId)['saatchi']['status'], 'Saatchi marcado a mano queda CARGADO A MANO');
    $pdo->prepare('UPDATE publications SET saatchi_url=? WHERE id=?')->execute(['https://www.saatchiart.com/art/x', $publicationId]);
    $saatchiState = $service->states($publicationId, $userId)['saatchi'];
    TestHarness::assertTrue($saatchiState['status'] === 'listed' && str_contains($saatchiState['external_url'], 'saatchiart.com'), 'con listing pegado en Sitio web, Saatchi queda LISTADO (derivado en vivo)');
    $xBlocked = false;
    try {
        $service->publish($publicationId, $userId, 'x', 'en', []);
    } catch (RuntimeException $e) {
        $xBlocked = true;
    }
    TestHarness::assertTrue($xBlocked, 'X es lugar reservado: publish rechaza');

    // ————— Lo que la inyección de transportes NO puede ver —————
    // Los tests inyectan transportes falsos, así que el cuerpo real de los
    // transportes nunca se ejecuta acá. Una llamada estática a un método de
    // instancia pasó la suite entera y murió en producción con los 7 pines:
    // «Non-static method PinterestPublisher::imagePinPayload() cannot be called
    // statically». Este invariante vigila la FORMA de la llamada en el código
    // fuente, que es exactamente el punto ciego de la inyección.
    $platformRoot = dirname(__DIR__, 2);
    $sources = [];
    foreach (['/app', ''] as $subdirectory) {
        $directory = $platformRoot . $subdirectory;
        $iterator = $subdirectory === ''
            ? new ArrayIterator(array_map(static fn(string $f): SplFileInfo => new SplFileInfo($f), (array)glob($directory . '/*.php')))
            : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && strtolower($entry->getExtension()) === 'php') {
                $sources[$entry->getPathname()] = true;
            }
        }
    }
    $publisherStatics = [];
    foreach ((new ReflectionClass('PinterestPublisher'))->getMethods() as $method) {
        if ($method->isStatic()) $publisherStatics[] = $method->getName();
    }
    $badCalls = [];
    foreach (array_keys($sources) as $file) {
        $code = (string)file_get_contents($file);
        if (preg_match_all('/PinterestPublisher::([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $code, $matches) === 0) continue;
        foreach ($matches[1] as $called) {
            if (!in_array($called, $publisherStatics, true)) {
                $badCalls[] = basename($file) . ' → PinterestPublisher::' . $called . '()';
            }
        }
    }
    TestHarness::assertSame(
        [],
        array_values(array_unique($badCalls)),
        'ninguna llamada estática apunta a un método de instancia de PinterestPublisher'
    );

    // Un destino que no publicó NADA no está enviado: publish debe fallar para
    // que el resumen del lote no lo cuente entre los éxitos.
    $allFailed = new PublicationDistributionService($pdo, $productService, $publicationService, [
        'pinterest' => static fn(array $request): array => ['external_id' => 'board-1', 'items' => [
            ['key' => '1', 'external_id' => '', 'external_url' => '', 'error' => 'Pinterest rechazó la imagen'],
            ['key' => '2', 'external_id' => '', 'external_url' => '', 'error' => 'Pinterest rechazó la imagen'],
        ]],
    ]);
    $pinterestFailed = false;
    try {
        $allFailed->publish($publicationId, $userId, 'pinterest', 'en', []);
    } catch (RuntimeException $e) {
        $pinterestFailed = str_contains($e->getMessage(), 'rechazó');
    }
    TestHarness::assertTrue($pinterestFailed, 'con cero pines publicados el destino falla en vez de reportarse enviado');
    TestHarness::assertSame('failed', (string)$allFailed->states($publicationId, $userId)['pinterest']['status'], 'y la fila queda registrada como fallada, con su detalle');
}
