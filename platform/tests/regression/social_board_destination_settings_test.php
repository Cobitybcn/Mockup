<?php
declare(strict_types=1);

function run_social_board_destination_settings_tests(): void
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE app_settings (`key` TEXT PRIMARY KEY,value TEXT NOT NULL,updated_at TEXT NOT NULL)");
    $settings = new SocialBoardDestinationSettings($pdo);

    $saved = $settings->save(42, 'https://artist.example/artworks/', 'https://saatchi.example/artist/');
    $loaded = $settings->forUser(42);
    TestHarness::assertTrue(
        $saved === $loaded
            && $loaded['website'] === 'https://artist.example/artworks'
            && $loaded['saatchi'] === 'https://saatchi.example/artist',
        'Social Media guarda los enlaces de destino predeterminados por usuario'
    );

    TestHarness::assertTrue(
        $settings->forUser(43) !== $loaded,
        'los enlaces predeterminados de Social Media no se comparten entre artistas'
    );

    $invalidBlocked = false;
    try {
        $settings->save(42, 'http://localhost/artworks', 'https://saatchi.example/artist');
    } catch (InvalidArgumentException) {
        $invalidBlocked = true;
    }
    TestHarness::assertTrue(
        $invalidBlocked,
        'los destinos predeterminados exigen una URL pública HTTPS'
    );

    $board = (string)file_get_contents(dirname(__DIR__, 2) . '/social_media_board.php');
    $javascript = (string)file_get_contents(dirname(__DIR__, 2) . '/social_media_board.js');
    TestHarness::assertTrue(
        str_contains($board, 'data-toggle-destinations')
            && str_contains($board, 'data-default-destination="website"')
            && str_contains($javascript, "fetch('social_media_destinations.php'"),
        'Social Media permite modificar sus destinos predeterminados dentro del tablero'
    );
    TestHarness::assertContains(
        'clearAcceptedPublications(result.jobs || [])',
        $javascript,
        'las publicaciones aceptadas dejan el tablero limpio para el siguiente trabajo'
    );
}
