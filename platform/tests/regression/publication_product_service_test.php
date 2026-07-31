<?php
declare(strict_types=1);

/**
 * Custodia del "producto terminado" (Etapa 2 de PUBLICACION_DISENO.md):
 * proyección pura de la memoria editorial hacia todos los destinos, congelada
 * por publicación, con detección de fuentes cambiadas. Projection-never-source.
 */
function run_publication_product_service_tests(): void
{
    TestHarness::group('Publicación: producto terminado (proyección congelada)');

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

    $productMigration = require dirname(__DIR__, 2) . '/migrations/schema/20260731_000001_publication_products.php';
    $productMigration['up']($pdo);
    $pdo->query('SELECT payload_json, source_fingerprint FROM publication_products WHERE 1=0');
    TestHarness::assertTrue(true, 'la migración crea publication_products (única por publicación)');

    $userId = 9;
    $pdo->exec("INSERT INTO user_language_policy VALUES (9,'es','[\"es\",\"en\"]','es')");
    $pdo->exec("INSERT INTO artworks (id,user_id,final_title) VALUES (101,9,'STRATA TEST — UNUM')");
    $pdo->exec("INSERT INTO artwork_sheets (id,user_id,canonical_artwork_id,title,description,short_description) VALUES (1,9,101,'STRATA TEST — UNUM','Sheet desc','Sheet short')");
    $pdo->exec("INSERT INTO mockups (id,user_id,mockup_file) VALUES (71,9,'cover.jpg'),(72,9,'second.jpg')");
    $pdo->exec("INSERT INTO mockup_sheets (id,user_id,artwork_sheet_id,mockup_id,mockup_file,title,alt_text,caption) VALUES
        (12,9,1,71,'cover.jpg','Cover mock','Item alt 12','Item cap 12'),
        (13,9,1,72,'second.jpg','Second mock','Item alt 13','Item cap 13')");

    $service = new PublicationService($pdo);
    $now = date('c');
    $pdo->prepare("INSERT INTO publications
        (user_id, artwork_sheet_id, slug, title, description, short_description, language, objective, cta_label, cta_url,
         visibility, status, content_source, profile_snapshot_json, metadata_snapshot_json, published_at, created_at, updated_at, header_file)
        VALUES (9,1,'strata-test','STRATA TEST — UNUM','Sheet desc','Sheet short','en','portfolio','','','public','published','inherit','{}','{}',?,?,?,'cover.jpg')")
        ->execute([$now, $now, $now]);
    $publicationId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO publication_items (publication_id,mockup_sheet_id,position,role,title,alt_text,caption) VALUES
        (?,12,0,'cover','Cover mock','Item alt 12','Item cap 12'),
        (?,13,1,'context','Second mock','Item alt 13','Item cap 13')")
        ->execute([$publicationId, $publicationId]);

    $json = static fn(array $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $artworkEsDraft = ['description' => 'Draft ES desc', 'tags' => 'borrador, no publicado', 'subtitle' => 'Sub borrador'];
    $artworkEsPublished = [
        'subtitle' => 'Sub ES', 'description' => 'Published ES desc', 'short_description' => 'Corta ES',
        'tags' => 'pintura abstracta, campo rojo, lineas incisas, oleo sobre lienzo, textura',
        'search_terms' => 'comprar pintura abstracta original', 'seo_title' => 'SEO ES', 'seo_description' => 'SEO desc ES',
        'alt_text' => 'Alt ES', 'caption' => 'Caption ES',
    ];
    $artworkEn = [
        'subtitle' => 'Sub EN', 'description' => 'EN desc', 'short_description' => 'Short EN',
        'tags' => 'abstract painting, Abstract Painting, oil on canvas, textured surface, deep red tones, a very long keyword phrase over limit, contemporary art, italian abstract art, canvas painting, dark red abstract, layered texture, modern painting, strata series, vertical format, expressive work',
        'search_terms' => 'buy original abstract painting', 'seo_title' => 'SEO EN', 'seo_description' => 'SEO desc EN',
        'alt_text' => 'Alt EN', 'caption' => 'Caption EN',
    ];
    $coverSocialEs = [
        'caption' => 'Cap mock ES', 'alt_text' => 'Alt mock ES', 'search_terms' => 'mockup interior es, sala roja',
        'social' => [
            'website' => ['description' => 'Web ES', 'caption' => 'WebCap ES', 'alt_text' => 'WebAlt ES'],
            'pinterest' => ['title' => 'Pin ES', 'description' => 'Pin desc ES', 'board_suggestions' => ['Tablero Uno', 'Tablero Dos'], 'topic_suggestions' => [], 'keywords' => []],
            'instagram' => ['caption' => 'IG ES caption', 'hook' => 'IG hook', 'hashtags' => ['#arte', '#abstracto'], 'cta' => 'IG CTA'],
            'facebook' => ['headline' => 'FB H', 'post_text' => 'FB post', 'link_description' => 'FB link', 'cta' => 'FB CTA'],
            'tiktok' => ['visual_hook' => 'Hook TT', 'suggested_motion' => 'Motion TT', 'sequence_role' => 'Role TT', 'caption_seed' => 'Semilla TikTok ES', 'video_notes' => 'Notas TT'],
        ],
    ];
    $coverSocialEn = $coverSocialEs;
    $coverSocialEn['caption'] = 'Cap mock EN';
    $coverSocialEn['search_terms'] = 'red abstract mockup interior';
    $coverSocialEn['social']['pinterest']['title'] = 'Pin EN';
    $coverSocialEn['social']['instagram']['caption'] = 'IG EN caption';
    $coverSocialEn['social']['tiktok']['caption_seed'] = 'TikTok seed EN';

    $insertContent = $pdo->prepare('INSERT INTO bilingual_editorial_content
        (user_id,entity_type,entity_id,locale,content_json,status,is_published,published_content_json) VALUES (?,?,?,?,?,?,?,?)');
    $insertContent->execute([9, 'artwork', 101, 'es', $json($artworkEsDraft), 'source', 1, $json($artworkEsPublished)]);
    $insertContent->execute([9, 'artwork', 101, 'en', $json($artworkEn), 'current', 0, '']);
    $insertContent->execute([9, 'mockup', 71, 'es', $json(['caption' => 'draft mock es']), 'source', 1, $json($coverSocialEs)]);
    $insertContent->execute([9, 'mockup', 71, 'en', $json($coverSocialEn), 'current', 0, '']);
    $insertContent->execute([9, 'mockup', 72, 'es', $json(['caption' => 'Second cap ES', 'alt_text' => 'Second alt ES']), 'source', 1, $json(['caption' => 'Second cap ES pub', 'alt_text' => 'Second alt ES pub'])]);
    // mockup 72 has NO en row → 'missing'

    $products = new PublicationProductService($pdo, $service);
    $payload = $products->assemble($publicationId, $userId);

    // ————— Regla published-snapshot (publicar = aprobar) —————
    TestHarness::assertSame('Published ES desc', (string)$payload['artwork']['es']['description'], 'el locale de trabajo proyecta el snapshot PUBLICADO, nunca el borrador');
    TestHarness::assertSame('EN desc', (string)$payload['artwork']['en']['description'], 'la adaptación proyecta el contenido revisado');
    TestHarness::assertSame('published', (string)$payload['sources']['artwork']['es']['status'], 'la procedencia registra es=published');
    TestHarness::assertSame('current', (string)$payload['sources']['artwork']['en']['status'], 'la procedencia registra en=current');
    TestHarness::assertSame('STRATA TEST — UNUM', (string)$payload['artwork']['title'], 'el título universal es idéntico en todos los idiomas');

    // ————— Mapeos por destino (precedentes Meta/Pinterest) —————
    TestHarness::assertSame("FB post\n\nFB link\n\nFB CTA", (string)$payload['destinations']['facebook']['es']['composed'], 'facebook compone post_text+link_description+cta');
    TestHarness::assertSame("IG ES caption\n\nIG CTA", (string)$payload['destinations']['instagram']['es']['composed'], 'instagram compone caption+cta');
    TestHarness::assertSame('Pin ES', (string)$payload['destinations']['pinterest']['es']['title'], 'pinterest toma el título del mockup portada');
    TestHarness::assertSame(['Tablero Uno', 'Tablero Dos'], $payload['destinations']['pinterest']['es']['board_suggestions'], 'pinterest lista los tableros sugeridos');
    TestHarness::assertSame(['mockup interior es', 'sala roja'], $payload['destinations']['pinterest']['es']['keywords'], 'pinterest sin keywords propias cae a los search_terms del mockup portada');

    // ————— TikTok: caption armado del mismo locale —————
    $tiktokEs = (string)$payload['destinations']['tiktok']['es']['caption'];
    TestHarness::assertTrue(str_starts_with($tiktokEs, 'Semilla TikTok ES'), 'tiktok arranca con el caption_seed del mockup portada');
    TestHarness::assertContains('#pinturaabstracta', $tiktokEs, 'tiktok arma hashtags desde los tags de la obra del MISMO locale');
    TestHarness::assertTrue(!str_contains($tiktokEs, '#abstractpainting'), 'tiktok jamás mezcla idiomas en el caption');
    $seedWithHashtags = PublicationProductService::tiktokCaption("Texto con etiquetas propias\n\n#arte #rojo", 'pintura abstracta, campo');
    TestHarness::assertSame("Texto con etiquetas propias\n\n#arte #rojo", $seedWithHashtags['caption'], 'una semilla que ya trae hashtags queda intacta — sin segunda línea duplicada');
    TestHarness::assertSame([], $seedWithHashtags['hashtags'], 'la semilla completa no recibe hashtags agregados');

    // ————— Saatchi: compresión de keywords —————
    $saatchi = $payload['destinations']['saatchi'];
    TestHarness::assertSame('en', (string)$saatchi['keywords_locale'], 'las keywords de Saatchi salen del inglés (mercado USA)');
    TestHarness::assertSame(12, count($saatchi['keywords']), 'Saatchi corta en 12 keywords');
    TestHarness::assertTrue(!in_array('a very long keyword phrase over limit', $saatchi['keywords'], true), 'una keyword >20 caracteres se DESCARTA entera, nunca se trunca');
    TestHarness::assertSame(1, count(array_filter($saatchi['keywords'], static fn(string $keyword): bool => strtolower($keyword) === 'abstract painting')), 'los duplicados case-insensitive se eliminan');
    TestHarness::assertContains(' ', (string)$saatchi['keywords'][0], 'las frases naturales con espacio se preservan');
    TestHarness::assertSame(2, count($saatchi['images']), 'el paquete lista todas las imágenes de la composición');
    TestHarness::assertSame('Cap mock ES', (string)$saatchi['images'][0]['caption']['es'], 'cada imagen lleva su caption editorial por idioma');

    // ————— EN faltante no bloquea —————
    TestHarness::assertSame('missing', (string)$payload['sources']['mockups']['72']['en']['status'], 'un mockup sin adaptación entra con estado missing, sin bloquear');
    TestHarness::assertSame('Second cap ES pub', (string)$payload['media']['items'][1]['es']['caption'], 'el ítem usa el caption publicado de su propio editorial');
    TestHarness::assertSame('x', array_keys($payload['destinations'])[4] ?? '', 'X mantiene su lugar reservado');
    TestHarness::assertTrue($payload['destinations']['x'] === null, 'X no lleva copy: placeholder sin conexión');

    // ————— Series sociales 3×3 —————
    $fakeItems = static fn(int $n): array => array_map(static fn(int $i): array => ['n' => $i], range(1, $n));
    TestHarness::assertSame([3, 3, 3], array_map('count', PublicationProductService::seriesGroups($fakeItems(9))), '9 imágenes → 3 posts de 3');
    TestHarness::assertSame([3, 1], array_map('count', PublicationProductService::seriesGroups($fakeItems(4))), '4 imágenes → 2 posts (3+1)');
    TestHarness::assertSame([3, 3, 6], array_map('count', PublicationProductService::seriesGroups($fakeItems(12))), 'más de 9 → los extra se suman al tercer post');
    TestHarness::assertSame([3, 3, 10], array_map('count', PublicationProductService::seriesGroups($fakeItems(20))), 'el tercer post respeta el tope de 10 del carrusel');
    TestHarness::assertSame('publication-product.v3', (string)$payload['schema'], 'el schema del producto es v3 (series sociales + carrusel de TikTok)');

    // ————— Carrusel de TikTok (Creator's Draft: solo title + description) —————
    $tiktokBlock = (array)$payload['destinations']['tiktok'];
    TestHarness::assertSame(['cover.jpg', 'second.jpg'], (array)$tiktokBlock['carousel_images'], 'el carrusel viaja con toda la composición');
    TestHarness::assertSame(0, (int)$tiktokBlock['cover_index'], 'la portada del carrusel es la portada de la composición');
    $carouselEs = (array)$tiktokBlock['es']['carousel'];
    TestHarness::assertSame('Hook TT', (string)$carouselEs['title'], 'el título del carrusel sale del gancho visual del mockup portada');
    TestHarness::assertTrue(mb_strlen((string)$carouselEs['title']) <= 90, 'el título respeta el tope de 90 de TikTok');
    TestHarness::assertSame((string)$tiktokBlock['es']['caption'], (string)$carouselEs['description'], 'la descripción del carrusel es el caption ya aprobado');
    $igSeries = (array)($payload['destinations']['instagram']['series'] ?? []);
    TestHarness::assertSame(1, count($igSeries), 'con 2 imágenes la serie es un solo post');
    TestHarness::assertSame(['cover.jpg', 'second.jpg'], (array)$igSeries[0]['images'], 'el post lleva las imágenes de su grupo en orden');
    TestHarness::assertTrue(str_contains((string)$igSeries[0]['en']['composed'], 'IG EN caption'), 'el copy del post es del idioma pedido, no mezclado');
    TestHarness::assertSame(2, count((array)$igSeries[0]['en']['alts']), 'cada imagen del post lleva su alt propio');

    // ————— Congelado: una fila por publicación, hash estable —————
    $first = $products->generate($publicationId, $userId);
    $second = $products->generate($publicationId, $userId);
    TestHarness::assertSame($first['id'], $second['id'], 'generate reutiliza la única fila por publicación');
    TestHarness::assertSame($first['source_fingerprint'], $second['source_fingerprint'], 'fuentes idénticas producen el mismo fingerprint');
    $found = $products->find($publicationId, $userId);
    TestHarness::assertTrue(is_array($found) && $found['payload']['schema'] === 'publication-product.v3', 'find devuelve el producto congelado con su schema');
    TestHarness::assertTrue(!$products->isStale($found, $userId), 'recién generado, el producto está vigente');

    // ————— Staleness: cambia la fuente → desactualizado; solo-status NO —————
    $pdo->exec("UPDATE bilingual_editorial_content SET status='stale' WHERE user_id=9 AND entity_type='artwork' AND locale='en'");
    TestHarness::assertTrue(!$products->isStale($found, $userId), 'un cambio SOLO de estado no invalida el producto');
    $coverSocialEs['social']['tiktok']['caption_seed'] = 'Semilla nueva ES';
    $pdo->prepare("UPDATE bilingual_editorial_content SET published_content_json=? WHERE user_id=9 AND entity_type='mockup' AND entity_id=71 AND locale='es'")
        ->execute([$json($coverSocialEs)]);
    TestHarness::assertTrue($products->isStale($found, $userId), 're-publicar la fuente marca el producto como desactualizado');
    $regenerated = $products->generate($publicationId, $userId);
    TestHarness::assertTrue(!$products->isStale($regenerated, $userId), 'regenerar vuelve a dejar el producto vigente');
    $pdo->exec('INSERT INTO artwork_video_publications (user_id,artwork_id,video_export_id) VALUES (9,101,55)');
    TestHarness::assertTrue($products->isStale($products->find($publicationId, $userId), $userId), 'adjuntar un video a la página invalida el producto');
    $withVideo = $products->generate($publicationId, $userId);
    TestHarness::assertSame(55, (int)$withVideo['payload']['destinations']['tiktok']['video_export_id'], 'tiktok referencia el video de la página');

    // ————— Compuertas —————
    $pdo->prepare("INSERT INTO publications
        (user_id, artwork_sheet_id, slug, title, description, short_description, language, objective, cta_label, cta_url,
         visibility, status, content_source, profile_snapshot_json, metadata_snapshot_json, created_at, updated_at)
        VALUES (9,1,'strata-draft','Draft','','','en','portfolio','','','public','draft','inherit','{}','{}',?,?)")
        ->execute([$now, $now]);
    $draftPublicationId = (int)$pdo->lastInsertId();
    $unpublishedBlocked = false;
    try {
        $products->assemble($draftPublicationId, $userId);
    } catch (RuntimeException $e) {
        $unpublishedBlocked = true;
    }
    TestHarness::assertTrue($unpublishedBlocked, 'sin página publicada no hay producto (compuerta del panel 1)');

    $pdo->prepare('DELETE FROM publication_items WHERE publication_id=?')->execute([$publicationId]);
    $emptyBlocked = false;
    try {
        $products->assemble($publicationId, $userId);
    } catch (RuntimeException $e) {
        $emptyBlocked = str_contains($e->getMessage(), 'mockup');
    }
    TestHarness::assertTrue($emptyBlocked, 'sin composición de media el producto bloquea señalando el panel Sitio web');
}
