<?php
declare(strict_types=1);

function run_mockup_editorial_batch_tests(): void
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY,email TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE artworks (id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,final_title TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE mockups (id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,source_artwork_id INTEGER,mockup_file TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE bilingual_editorial_content (
        id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,entity_type TEXT NOT NULL,
        entity_id INTEGER NOT NULL,locale TEXT NOT NULL,content_json TEXT NOT NULL,status TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE bilingual_editorial_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,entity_type TEXT NOT NULL,
        entity_id INTEGER NOT NULL,status TEXT NOT NULL
    )");
    $pdo->exec("INSERT INTO users VALUES (7,'mauriziovalch@gmail.com')");
    $pdo->exec("INSERT INTO artworks VALUES (11,7,'SOL DIVISUS')");
    $pdo->exec("INSERT INTO mockups VALUES
        (21,7,11,'complete.jpg'),
        (22,7,11,'missing.jpg'),
        (23,7,999,'orphaned.jpg')");

    $complete = [
        'description' => 'Description', 'tags' => 'tags', 'search_terms' => 'terms',
        'seo_title' => 'SEO title', 'seo_description' => 'SEO description',
        'alt_text' => 'Alt', 'caption' => 'Caption',
        'social' => [
            'website' => ['description' => 'Web', 'caption' => 'Caption', 'alt_text' => 'Alt'],
            'pinterest' => [
                'title' => 'Pin', 'description' => 'Pin description',
                'board_suggestions' => 'Board', 'topic_suggestions' => 'Topic', 'keywords' => 'Keywords',
            ],
            'instagram' => ['caption' => 'Caption', 'hook' => 'Hook', 'hashtags' => '#art', 'cta' => 'CTA'],
            'facebook' => [
                'headline' => 'Headline', 'post_text' => 'Post',
                'link_description' => 'Link', 'cta' => 'CTA',
            ],
            'tiktok' => [
                'visual_hook' => 'Hook', 'suggested_motion' => 'Motion',
                'sequence_role' => 'Role', 'caption_seed' => 'Seed', 'video_notes' => 'Notes',
            ],
        ],
    ];
    $insert = $pdo->prepare("INSERT INTO bilingual_editorial_content
        (user_id,entity_type,entity_id,locale,content_json,status,updated_at)
        VALUES (7,'mockup',?,?,?,?,?)");
    foreach (['es', 'en'] as $locale) {
        $insert->execute([21, $locale, json_encode($complete), $locale === 'en' ? 'current' : 'source', '2026-07-24T00:00:00Z']);
    }
    $incomplete = $complete;
    $incomplete['social']['instagram']['hashtags'] = '';
    $insert->execute([22, 'es', json_encode($incomplete), 'source', '2026-07-24T00:00:00Z']);
    $insert->execute([22, 'en', json_encode($complete), 'stale', '2026-07-24T00:00:00Z']);

    $audit = (new MockupEditorialBatchService($pdo))->audit('MAURIZIOVALCH@GMAIL.COM');
    TestHarness::assertSame(2, $audit['total_active_mockups'], 'el lote excluye mockups huérfanos o eliminados con su obra');
    TestHarness::assertSame(1, $audit['incomplete_count'], 'el lote selecciona solo paquetes editoriales incompletos u obsoletos');
    TestHarness::assertSame(22, $audit['items'][0]['mockup_id'] ?? 0, 'el lote identifica el mockup incompleto exacto');
    TestHarness::assertContains('social.instagram.hashtags', implode(',', $audit['items'][0]['missing_es'] ?? []), 'la auditoría informa el campo español faltante');
    TestHarness::assertSame('stale', $audit['items'][0]['english_status'] ?? '', 'la auditoría conserva el estado inglés obsoleto');
}
