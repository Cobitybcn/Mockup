<?php
declare(strict_types=1);

function run_mockup_favorites_order_regression_tests(): void
{
    TestHarness::group('Artwork related mockup ordering');

    $ordered = MockupFavorites::orderForArtworkDisplay([
        ['id' => 14, 'is_favorite' => false, 'is_close_view' => true, 'created_at' => '2026-07-24 12:00:00'],
        ['id' => 13, 'is_favorite' => true, 'is_close_view' => true, 'created_at' => '2026-07-22 12:00:00'],
        ['id' => 12, 'is_favorite' => false, 'is_close_view' => false, 'created_at' => '2026-07-23 12:00:00'],
        ['id' => 11, 'is_favorite' => true, 'is_close_view' => false, 'created_at' => '2026-07-21 12:00:00'],
    ]);

    TestHarness::assertSame(
        [11, 13, 12, 14],
        array_column($ordered, 'id'),
        'favorites appear before every non-favorite while preserving view grouping'
    );

    $artworkPage = (string)file_get_contents(__DIR__ . '/../../artwork.php');
    TestHarness::assertContains(
        'grid.insertBefore(card, firstNonFavorite)',
        $artworkPage,
        'a newly selected favorite moves into the leading group immediately'
    );
    TestHarness::assertContains(
        'lastFavorite.after(card)',
        $artworkPage,
        'an unselected favorite leaves the leading group immediately'
    );
}
