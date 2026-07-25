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

    require_once dirname(__DIR__, 3) . '/artist-site/inc/AppPublishedStudioNotes.php';
    $catalog = new AppPublishedStudioNotes($pdo, 'artist@example.com');
    $spanishNotes = $catalog->all('es');
    $englishNotes = $catalog->all('en');
    $spanishSlug = 'donde-el-pensamiento-emerge-de-la-tierra-12';
    $englishSlug = 'where-thought-emerges-from-the-earth-12';
    TestHarness::assertTrue(isset($spanishNotes[$spanishSlug]), 'la ruta española usa su slug editorial propio');
    TestHarness::assertTrue(isset($englishNotes[$englishSlug]), 'la ruta inglesa usa su slug editorial propio');
    TestHarness::assertSame(
        'Donde el pensamiento emerge de la tierra',
        (string)$spanishNotes[$spanishSlug]['title'],
        'el website español lee el snapshot español publicado'
    );
    TestHarness::assertSame(
        $englishSlug,
        (string)$spanishNotes[$spanishSlug]['language_slugs']['en'],
        'hreflang conserva la pareja inglesa de la misma entidad'
    );

    $spanish['body_html'] = '<p>El territorio contiene presión, memoria y tiempo.</p>';
    $service->save(7, 'studio_note', 12, 'es', $spanish);
    $staleEnglish = $service->get(7, 'studio_note', 12, 'en');
    TestHarness::assertSame('stale', (string)$staleEnglish['status'], 'editar el master español marca el inglés como desactualizado');
    TestHarness::assertSame(
        '<p>El territorio nunca es una superficie neutral.</p>',
        (string)$catalog->all('es')[$spanishSlug]['objective'],
        'los cambios privados no reemplazan el snapshot español publicado'
    );

    $insertLegacy = $pdo->prepare("INSERT INTO social_campaigns
        (id,user_id,campaign_type,title,objective,status,payload_json,created_at,updated_at)
        VALUES (13,7,'website_blog','English only','<p>English legacy content.</p>','published',?,'2026-07-01','2026-07-25')");
    $insertLegacy->execute([$payload]);
    TestHarness::assertTrue(
        !isset($catalog->all('es')['english-only-13']),
        'una nota inglesa heredada nunca se reutiliza como contenido español'
    );
    TestHarness::assertTrue(
        isset($catalog->all('en')['english-only-13']),
        'las notas inglesas heredadas siguen disponibles durante la migración'
    );
}
