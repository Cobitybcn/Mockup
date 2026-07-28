<?php
declare(strict_types=1);

function run_artist_onboarding_tests(): void
{
    TestHarness::group('Onboarding minimo de artista Pro');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE user_language_policy (
        user_id INTEGER NOT NULL PRIMARY KEY,working_locale TEXT NOT NULL,publication_locales_json TEXT NOT NULL,
        interface_locale TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE artist_profiles (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, subdomain TEXT NOT NULL DEFAULT '')");

    $studioUser = ['id' => 1, 'email' => 'studio@example.test', 'plan_code' => FeatureAccess::PLAN_ARTIST_STUDIO];
    TestHarness::assertSame(
        false,
        ArtistOnboarding::isRequired($studioUser),
        'Artist Studio nunca pasa por el onboarding minimo'
    );

    $proNoSetup = ['id' => 2, 'email' => 'new-pro@example.test', 'plan_code' => FeatureAccess::PLAN_ARTIST_PRO];
    TestHarness::assertTrue(
        ArtistOnboarding::isRequired($proNoSetup),
        'un artista Pro nuevo requiere completar el onboarding'
    );
    TestHarness::assertSame(
        false,
        ArtistOnboarding::isComplete($proNoSetup, $pdo),
        'sin politica de idioma explicita ni subdominio, el onboarding queda incompleto'
    );

    $pdo->exec("INSERT INTO user_language_policy (user_id,working_locale,publication_locales_json,created_at,updated_at)
        VALUES (2,'en','[\"en\"]','2026-07-28T00:00:00Z','2026-07-28T00:00:00Z')");
    TestHarness::assertSame(
        false,
        ArtistOnboarding::isComplete($proNoSetup, $pdo),
        'la politica de idioma sola no alcanza: todavia falta el subdominio'
    );

    $pdo->exec("INSERT INTO artist_profiles (user_id,subdomain) VALUES (2,'new-artist')");
    TestHarness::assertTrue(
        ArtistOnboarding::isComplete($proNoSetup, $pdo),
        'con idioma explicito y subdominio, el onboarding queda completo'
    );

    $adminPro = ['id' => 3, 'email' => 'not-in-admin-emails@example.test', 'is_admin' => 1, 'plan_code' => FeatureAccess::PLAN_ARTIST_PRO];
    TestHarness::assertSame(
        false,
        ArtistOnboarding::isRequired($adminPro),
        'los administradores quedan afuera del onboarding de artista aunque tengan plan Pro'
    );

    $sidebarSource = (string)file_get_contents(dirname(__DIR__, 2) . '/sidebar.php');
    TestHarness::assertContains(
        'ArtistOnboarding::isComplete',
        $sidebarSource,
        'la plataforma redirige al onboarding desde cualquier pantalla que use el sidebar'
    );
    TestHarness::assertContains(
        "currentPage !== 'artist_onboarding.php'",
        $sidebarSource,
        'el propio formulario de onboarding no se redirige a si mismo'
    );

    $onboardingSource = (string)file_get_contents(dirname(__DIR__, 2) . '/artist_onboarding.php');
    TestHarness::assertContains('LanguagePolicy::save', $onboardingSource, 'el onboarding guarda la politica de idioma explicita');
    TestHarness::assertContains('ArtistDomainService', $onboardingSource, 'el onboarding reutiliza la validacion existente de subdominios');
}
