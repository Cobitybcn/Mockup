<?php
declare(strict_types=1);

function run_studio_note_bilingual_tests(): void
{
    TestHarness::group('Studio Notes bilingües');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,email TEXT NOT NULL)');
    $pdo->exec("CREATE TABLE social_campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,campaign_type TEXT NOT NULL,
        title TEXT NOT NULL,objective TEXT NOT NULL,source_type TEXT NOT NULL DEFAULT '',
        source_id TEXT NOT NULL DEFAULT '',source_label TEXT NOT NULL DEFAULT '',status TEXT NOT NULL,
        payload_json TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL
    )");
    $contentMigration = require dirname(__DIR__, 2) . '/migrations/schema/20260722_000002_bilingual_editorial_content.php';
    $contentMigration['up']($pdo);
    $publicationMigration = require dirname(__DIR__, 2) . '/migrations/schema/20260722_000003_bilingual_spanish_publication.php';
    $publicationMigration['up']($pdo);

    $pdo->exec("INSERT INTO users VALUES (7,'artist@example.com')");
    $payload = json_encode([
        'channels' => ['website_blog'],
        'destinations' => ['website_blog'],
        'channel_status' => ['website_blog' => 'published'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $insert = $pdo->prepare("INSERT INTO social_campaigns
        (id,user_id,campaign_type,title,objective,status,payload_json,created_at,updated_at)
        VALUES (12,7,'website_blog','Legacy English title','<p>Legacy English body.</p>','published',?,'2026-07-01','2026-07-25')");
    $insert->execute([$payload]);

    $service = new BilingualEditorialService($pdo);
    $spanish = [
        'title' => 'Donde el pensamiento emerge de la tierra',
        'excerpt' => 'Una lectura de STRATA como territorio.',
        'body_html' => '<p>El territorio nunca es una superficie neutral.</p>',
        'slug' => 'donde-el-pensamiento-emerge-de-la-tierra',
        'seo_title' => 'STRATA y territorio | Notas de estudio | Maurizio Valch',
        'seo_description' => 'Una nota sobre STRATA, territorio y memoria.',
        'alt_text' => 'Pintura abstracta con estratos rojos y azules.',
        'search_terms' => 'pintura abstracta y territorio',
    ];
    $english = [
        'title' => 'Where Thought Emerges from the Earth',
        'excerpt' => 'A reading of STRATA as territory.',
        'body_html' => '<p>Territory is never a neutral surface.</p>',
        'slug' => 'where-thought-emerges-from-the-earth',
        'seo_title' => 'STRATA and Territory | Studio Notes | Maurizio Valch',
        'seo_description' => 'A Studio Note on STRATA, territory and memory.',
        'alt_text' => 'Abstract painting with red and blue strata.',
        'search_terms' => 'abstract painting and territory',
    ];
    $service->save(7, 'studio_note', 12, 'es', $spanish);
    $englishState = $service->save(7, 'studio_note', 12, 'en', $english);
    TestHarness::assertSame('current', (string)$englishState['status'], 'la adaptación inglesa queda vinculada al hash español');

    $service->setPublished(7, 'studio_note', 12, 'es', true);
    $service->setPublished(7, 'studio_note', 12, 'en', true);

    $publishedRows = $pdo->query("SELECT locale,published_content_json
        FROM bilingual_editorial_content
        WHERE entity_type='studio_note' AND entity_id=12 AND is_published=1
        ORDER BY locale")->fetchAll(PDO::FETCH_KEY_PAIR);
    TestHarness::assertSame(
        'Donde el pensamiento emerge de la tierra',
        (string)(json_decode((string)$publishedRows['es'], true)['title'] ?? ''),
        'la plataforma conserva el snapshot español publicado'
    );
    TestHarness::assertSame(
        'Where Thought Emerges from the Earth',
        (string)(json_decode((string)$publishedRows['en'], true)['title'] ?? ''),
        'la plataforma conserva la pareja inglesa publicada'
    );

    $spanish['body_html'] = '<p>El territorio contiene presión, memoria y tiempo.</p>';
    $service->save(7, 'studio_note', 12, 'es', $spanish);
    $staleEnglish = $service->get(7, 'studio_note', 12, 'en');
    TestHarness::assertSame('stale', (string)$staleEnglish['status'], 'editar el master español marca el inglés como desactualizado');
    $publishedSpanish = $service->get(7, 'studio_note', 12, 'es');
    TestHarness::assertSame(
        '<p>El territorio nunca es una superficie neutral.</p>',
        (string)($publishedSpanish['published_content']['body_html'] ?? ''),
        'los cambios privados no reemplazan el snapshot español publicado'
    );
}
