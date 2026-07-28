<?php
declare(strict_types=1);

function run_artist_site_metrics_tests(): void
{
    TestHarness::group('Saatchi por obra y metricas del sitio publico');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec('CREATE TABLE publications (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)');
    $saatchiMigration = require dirname(__DIR__, 2) . '/migrations/schema/20260728_000002_publication_saatchi_url.php';
    $saatchiMigration['up']($pdo);
    $hasColumn = false;
    foreach ($pdo->query('PRAGMA table_info(publications)') as $column) {
        if ((string)$column['name'] === 'saatchi_url') { $hasColumn = true; break; }
    }
    TestHarness::assertTrue($hasColumn, 'la migracion agrega saatchi_url a publications');

    $eventsMigration = require dirname(__DIR__, 2) . '/migrations/schema/20260728_000003_artist_site_events.php';
    $eventsMigration['up']($pdo);
    $pdo->prepare('INSERT INTO artist_site_events (user_id,event_type,entity_type,slug,locale,referrer_host,detail,created_at) VALUES (2,?,?,?,?,?,?,?)')
        ->execute(['saatchi_click', 'artwork', 'via-solis', 'en', 'google.com', '', date(DATE_ATOM)]);
    TestHarness::assertSame(
        1,
        (int)$pdo->query("SELECT COUNT(*) FROM artist_site_events WHERE event_type='saatchi_click'")->fetchColumn(),
        'la tabla de eventos del sitio acepta registros'
    );

    $artworkScreen = (string)file_get_contents(dirname(__DIR__, 2) . '/artwork.php');
    TestHarness::assertContains('name="saatchi_url"', $artworkScreen, 'la pestaña Website de la obra ofrece el campo del listing de Saatchi');
    TestHarness::assertContains('saatchiart.com', $artworkScreen, 'el enlace de Saatchi se valida contra saatchiart.com');

    $publicSite = (string)file_get_contents(dirname(__DIR__, 3) . '/artist-site/index.php');
    TestHarness::assertContains('function app_saatchi_listing_url', $publicSite, 'la ficha publica resuelve el enlace de Saatchi por obra');
    TestHarness::assertContains('utm_source=', $publicSite, 'el enlace de Saatchi viaja con parametros de seguimiento');
    TestHarness::assertContains("data-metric-event=\"saatchi_click\"", $publicSite, 'el boton de Saatchi reporta sus clics');
    TestHarness::assertContains("'site-event'", $publicSite, 'el sitio publico expone el endpoint de eventos propios');
    TestHarness::assertContains('function app_record_site_event', $publicSite, 'los eventos del sitio se registran en la base');
    TestHarness::assertContains("app_record_site_event('search'", $publicSite, 'las busquedas internas de visitantes quedan registradas');

    $publicFooter = (string)file_get_contents(dirname(__DIR__, 3) . '/artist-site/inc/footer.php');
    TestHarness::assertContains('sendBeacon', $publicFooter, 'el pie de pagina emite el beacon de metricas');
    TestHarness::assertContains('data-metric-event', $publicFooter, 'los enlaces sociales del pie reportan clics salientes');

    $siteData = (string)file_get_contents(dirname(__DIR__, 3) . '/artist-site/data/site.php');
    TestHarness::assertContains('saatchiart.com/mauriziovalch', $siteData, 'el perfil de Saatchi del pie usa la URL confirmada /mauriziovalch');

    $metricsScreen = (string)file_get_contents(dirname(__DIR__, 2) . '/admin_metrics.php');
    TestHarness::assertContains('Auth::isAdmin', $metricsScreen, 'las metricas del sitio son exclusivas del administrador');
    TestHarness::assertContains('artist_site_events', $metricsScreen, 'la pantalla de metricas lee la tabla de eventos propia');

    $sidebar = (string)file_get_contents(dirname(__DIR__, 2) . '/sidebar.php');
    TestHarness::assertContains('admin_metrics.php', $sidebar, 'las metricas viven en el menu Admin, sin ocupar secciones de trabajo');
}
