<?php
declare(strict_types=1);

function artwork_editorial_fixture(string $entityType): array
{
    $base = [
        'subtitle' => 'Subtitle',
        'short_description' => 'Short description',
        'description' => 'Description',
        'tags' => 'art, painting',
        'search_terms' => 'original contemporary painting',
        'seo_title' => 'Editorial SEO title',
        'seo_description' => 'Editorial SEO description',
        'alt_text' => 'Accessible image description',
        'caption' => 'Editorial caption',
    ];
    if ($entityType !== 'mockup') {
        return $base;
    }
    $base['social'] = [
        'website' => ['description' => 'Website description', 'caption' => 'Website caption', 'alt_text' => 'Website alt'],
        'pinterest' => [
            'title' => 'Pinterest title', 'description' => 'Pinterest description',
            'board_suggestions' => 'Contemporary art', 'topic_suggestions' => 'Original art', 'keywords' => 'painting',
        ],
        'instagram' => ['caption' => 'Instagram caption', 'hook' => 'Hook', 'hashtags' => '#art', 'cta' => 'View'],
        'facebook' => ['headline' => 'Headline', 'post_text' => 'Post', 'link_description' => 'Link', 'cta' => 'View'],
        'tiktok' => [
            'visual_hook' => 'Visual hook', 'suggested_motion' => 'Slow move', 'sequence_role' => 'Opening',
            'caption_seed' => 'Caption seed', 'video_notes' => 'Video notes',
        ],
    ];
    return $base;
}

function run_artwork_editorial_package_tests(): void
{
    TestHarness::group('Preparacion editorial por obra');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,email TEXT NOT NULL)');
    $pdo->exec("CREATE TABLE artist_profiles (
        id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,artist_name TEXT NOT NULL DEFAULT '',
        short_bio TEXT NOT NULL DEFAULT '',statement TEXT NOT NULL DEFAULT '',visual_language TEXT NOT NULL DEFAULT '',
        materials TEXT NOT NULL DEFAULT '',recurring_themes TEXT NOT NULL DEFAULT '',palette_notes TEXT NOT NULL DEFAULT '',
        target_audience TEXT NOT NULL DEFAULT '',preferred_regions TEXT NOT NULL DEFAULT '',preferred_contexts TEXT NOT NULL DEFAULT '',
        forbidden_contexts TEXT NOT NULL DEFAULT '',commercial_positioning TEXT NOT NULL DEFAULT '',
        conceptual_keywords TEXT NOT NULL DEFAULT '',tone_of_voice TEXT NOT NULL DEFAULT '',
        marketplace_strategy TEXT NOT NULL DEFAULT '',social_strategy TEXT NOT NULL DEFAULT '',
        pinterest_strategy TEXT NOT NULL DEFAULT '',photo_file TEXT NOT NULL DEFAULT '',subdomain TEXT NOT NULL DEFAULT '',
        custom_domain TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE artwork_series (
        id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,title TEXT NOT NULL,description TEXT NOT NULL DEFAULT '',
        long_description TEXT NOT NULL DEFAULT '',conceptual_core TEXT NOT NULL DEFAULT '',
        interpretive_limits TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE artworks (
        id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,final_title TEXT NOT NULL DEFAULT '',series_id INTEGER,
        artwork_group_id INTEGER,root_file TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE mockups (
        id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,source_artwork_id INTEGER,artwork_group_id INTEGER,
        artwork_file TEXT NOT NULL DEFAULT '',mockup_file TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("INSERT INTO users VALUES (7,'artist@example.com')");
    $pdo->exec("INSERT INTO artist_profiles (id,user_id,artist_name,statement) VALUES (1,7,'Artist','Studio statement')");
    $pdo->exec("INSERT INTO artwork_series VALUES (3,7,'STRATA','','','Layered territories','Avoid decorative claims')");
    $pdo->exec("INSERT INTO artworks VALUES (11,7,'SOL DIVISUS',3,31,'sol-divisus.jpg')");
    $pdo->exec("INSERT INTO mockups VALUES
        (21,7,11,31,'sol-divisus.jpg','complete.jpg'),
        (22,7,11,31,'sol-divisus.jpg','pending.jpg'),
        (23,7,999,99,'other.jpg','other.jpg')");

    $contentMigration = require dirname(__DIR__, 2) . '/migrations/schema/20260722_000002_bilingual_editorial_content.php';
    ($contentMigration['up'])($pdo);
    $publicationMigration = require dirname(__DIR__, 2) . '/migrations/schema/20260722_000003_bilingual_spanish_publication.php';
    ($publicationMigration['up'])($pdo);
    $packageMigration = require dirname(__DIR__, 2) . '/migrations/schema/20260724_000009_artwork_editorial_packages.php';
    ($packageMigration['up'])($pdo);

    $insert = $pdo->prepare("INSERT INTO bilingual_editorial_content
        (user_id,entity_type,entity_id,locale,content_json,private_memo,status,source_hash,created_at,updated_at)
        VALUES (7,?,?,?,?,'',?,'','2026-07-24T00:00:00Z','2026-07-24T00:00:00Z')");
    foreach ([
        ['series', 3, 'es', artwork_editorial_fixture('series'), 'source'],
        ['series', 3, 'en', artwork_editorial_fixture('series'), 'current'],
        ['artwork', 11, 'es', artwork_editorial_fixture('artwork'), 'source'],
        ['artwork', 11, 'en', artwork_editorial_fixture('artwork'), 'stale'],
        ['mockup', 21, 'es', artwork_editorial_fixture('mockup'), 'source'],
        ['mockup', 21, 'en', artwork_editorial_fixture('mockup'), 'current'],
        ['mockup', 22, 'es', ['description' => 'Partial'], 'source'],
    ] as [$type, $entityId, $locale, $content, $status]) {
        $insert->execute([$type, $entityId, $locale, json_encode($content), $status]);
    }

    $audit = (new ArtworkEditorialPackageService($pdo))->audit(7, 11);
    TestHarness::assertTrue($audit['prerequisites_ready'], 'el checklist reconoce perfil, titulo, serie y mockups listos');
    TestHarness::assertSame(2, $audit['mockups_total'], 'la orden queda limitada a los mockups de la obra');
    TestHarness::assertSame(0, $audit['editorial_pending']['series'], 'la serie completa no se vuelve a generar');
    TestHarness::assertSame(1, $audit['editorial_pending']['artwork'], 'la obra con ingles obsoleto se incluye');
    TestHarness::assertSame(1, $audit['editorial_pending']['mockups'], 'solo el mockup incompleto queda pendiente');
    TestHarness::assertSame('adapt', $audit['items'][0]['action'] ?? '', 'la obra conserva español y solo adapta ingles');
    TestHarness::assertSame('prepare', $audit['items'][1]['action'] ?? '', 'el mockup incompleto prepara ambos borradores');
    TestHarness::assertTrue($audit['can_start'], 'el Decision Block aparece cuando hay alcance y requisitos completos');

    $artworkPage = (string)file_get_contents(dirname(__DIR__, 2) . '/artwork.php');
    TestHarness::assertContains('data-editorial-package', $artworkPage, 'artwork.php integra el panel editorial avanzado');
    TestHarness::assertTrue(!str_contains($artworkPage, 'Create Studio Note'), 'el Decision Block anterior de Studio Notes fue retirado');
    TestHarness::assertContains('Nothing is published automatically.', $artworkPage, 'la interfaz declara el contrato de borradores sin publicacion');
}
