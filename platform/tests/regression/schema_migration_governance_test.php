<?php
declare(strict_types=1);

function run_schema_migration_governance_tests(): void
{
    TestHarness::group('Database schema: versionado y auditoria');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL DEFAULT '',
        name TEXT NOT NULL DEFAULT '',
        credits INTEGER NOT NULL DEFAULT 10,
        is_admin INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $directory = dirname(__DIR__, 2) . '/migrations/schema';
    $first = SchemaMigrator::migrate($pdo, $directory);
    $second = SchemaMigrator::migrate($pdo, $directory);

    TestHarness::assertTrue(
        in_array('20260719_000001_access_control_governance', $first['executed'], true),
        'la migracion de acceso pendiente se ejecuta'
    );
    TestHarness::assertSame([], $second['executed'], 'repetir el arranque no repite una migracion aplicada');
    TestHarness::assertSame(
        count(glob($directory . '/*.php') ?: []),
        (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(),
        'el historial registra todas las versiones disponibles'
    );

    $columns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    TestHarness::assertTrue(in_array('plan_code', $columns, true), 'la version agrega el plan de acceso a instalaciones existentes');
    TestHarness::assertTrue(in_array('session_version', $columns, true), 'las sesiones pueden invalidarse tras un cambio de credenciales');
    $pdo->query('SELECT action, identity_hash, attempts FROM auth_rate_limits WHERE 1=0');
    $pdo->query('SELECT id, data, updated_at FROM php_sessions WHERE 1=0');
    TestHarness::assertTrue(true, 'los limites de autenticacion y sesiones persistentes pertenecen al esquema versionado');
    $pdo->query('SELECT feature_key FROM user_feature_overrides WHERE 1=0');
    $pdo->query('SELECT before_json, after_json FROM user_access_audit WHERE 1=0');
    TestHarness::assertTrue(true, 'las tablas de permisos y auditoria pertenecen a la misma version');
    $pdo->query('SELECT featured_score, featured_until, editorial_score FROM scene_ranking_profiles WHERE 1=0');
    TestHarness::assertTrue(true, 'el ranking editorial de escenas pertenece al esquema versionado');
    $pdo->query('SELECT content_hash, descriptor_json, similarity_group FROM scene_reference_profiles WHERE 1=0');
    TestHarness::assertTrue(true, 'las huellas y correcciones de diversidad pertenecen al esquema versionado');
    $pdo->query('SELECT name, description, thumbnail, identifier_color, categories_json FROM reference_sets WHERE 1=0');
    $pdo->query('SELECT reference_asset_id, reference_key, category, position FROM reference_set_items WHERE 1=0');
    $pdo->query('SELECT title, category, storage_path, mime_type FROM reference_assets WHERE 1=0');
    TestHarness::assertTrue(true, 'Reference Sets y sus referencias ordenadas pertenecen al esquema versionado');
    $pdo->query('SELECT enabled, source_locale, publication_locale FROM bilingual_editorial_settings WHERE 1=0');
    $pdo->query('SELECT entity_type, entity_id, locale, content_json, status, source_hash, is_published, published_content_json, published_at FROM bilingual_editorial_content WHERE 1=0');
    TestHarness::assertTrue(true, 'el contenido bilingue experimental queda aislado por usuario, entidad e idioma');
    $pdo->query('SELECT series_id,locale,market,keyword_text,avg_monthly_searches,competition,selected FROM series_keyword_research WHERE 1=0');
    TestHarness::assertTrue(true, 'la investigación de búsqueda de Series queda separada por idioma y mercado');

    $now = date(DATE_ATOM);
    $insert = $pdo->prepare("INSERT INTO users
        (email,password_hash,name,credits,is_admin,status,created_at,updated_at,plan_code)
        VALUES (?,?,?,?,?,?,?,?,?)");
    $insert->execute(['artist@example.com', '', 'Artist', 10, 0, 'active', $now, $now, 'artist_studio']);
    FeatureAccess::updateUserAccess(
        $pdo,
        (int)$pdo->lastInsertId(),
        FeatureAccess::PLAN_ARTIST_PRO,
        [FeatureAccess::VIDEO_MANAGE => 'deny'],
        'Regression test',
        null,
        'test'
    );
    $audit = $pdo->query('SELECT actor_context,before_json,after_json FROM user_access_audit LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    TestHarness::assertSame('test', (string)$audit['actor_context'], 'cada cambio de nivel deja su origen');
    TestHarness::assertContains('artist_studio', (string)$audit['before_json'], 'la auditoria conserva el nivel anterior');
    TestHarness::assertContains('artist_pro', (string)$audit['after_json'], 'la auditoria conserva el nivel nuevo');

    SchemaMigrator::assertCurrent($pdo, $directory);
    TestHarness::assertTrue(true, 'la comprobacion final reconoce base y codigo en la misma version');
}
