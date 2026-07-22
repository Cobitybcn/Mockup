<?php
declare(strict_types=1);

function run_public_artist_showcase_tests(): void
{
    $items = [
        ['id' => 10, 'url' => 'one.jpg', 'alt' => 'One', 'artwork_key' => 'artwork-a', 'display_role' => 'context'],
        ['id' => 11, 'url' => 'two.jpg', 'alt' => 'Two', 'artwork_key' => 'artwork-a', 'display_role' => 'detail'],
        ['id' => 20, 'url' => 'three.jpg', 'alt' => 'Three', 'artwork_key' => 'artwork-b', 'display_role' => 'context'],
        ['id' => 30, 'url' => 'four.jpg', 'alt' => 'Four', 'artwork_key' => 'artwork-c', 'display_role' => 'detail'],
    ];
    $alwaysDifferent = true;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $selected = PublicArtistShowcase::chooseDifferent($items, 'artwork-a');
        $alwaysDifferent = $alwaysDifferent && (string)$selected['artwork_key'] !== 'artwork-a';
    }
    TestHarness::assertTrue($alwaysDifferent, 'every mockup from the immediately previous artwork is excluded');

    $singleArtwork = PublicArtistShowcase::chooseDifferent([$items[0], $items[1]], 'artwork-a');
    TestHarness::assertTrue(in_array((int)$singleArtwork['id'], [10, 11], true), 'a collection with one artwork remains available');

    $allPrimaryContextual = true;
    $allPreviousExcluded = true;
    $allHaveSupportingDetail = true;
    $allUseDifferentArtworks = true;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $composition = PublicArtistShowcase::chooseComposition($items, 'artwork-a');
        $allPrimaryContextual = $allPrimaryContextual && (string)$composition['primary']['display_role'] === 'context';
        $allPreviousExcluded = $allPreviousExcluded && (string)$composition['primary']['artwork_key'] !== 'artwork-a';
        $allHaveSupportingDetail = $allHaveSupportingDetail
            && $composition['secondary'] !== null
            && (string)$composition['secondary']['display_role'] === 'detail';
        $allUseDifferentArtworks = $allUseDifferentArtworks
            && (string)$composition['primary']['artwork_key'] !== (string)$composition['secondary']['artwork_key'];
    }
    TestHarness::assertTrue($allPrimaryContextual, 'the dominant mockup is contextual');
    TestHarness::assertTrue($allPreviousExcluded, 'the previous artwork is excluded from the dominant mockup');
    TestHarness::assertTrue($allHaveSupportingDetail, 'the editorial composition always includes a supporting detail mockup');
    TestHarness::assertTrue($allUseDifferentArtworks, 'the two mockups use different artworks');

    $contextOnly = [
        ['id' => 40, 'url' => 'five.jpg', 'alt' => 'Five', 'artwork_key' => 'artwork-d', 'display_role' => 'context'],
        ['id' => 50, 'url' => 'six.jpg', 'alt' => 'Six', 'artwork_key' => 'artwork-e', 'display_role' => 'context'],
    ];
    $compositionWithoutDetail = PublicArtistShowcase::chooseComposition($contextOnly, '');
    TestHarness::assertTrue($compositionWithoutDetail['secondary'] === null, 'the supporting position remains empty when no detail mockup is available');

    $landing = (string)file_get_contents(dirname(__DIR__, 2) . '/index.php');
    $login = (string)file_get_contents(dirname(__DIR__, 2) . '/login.php');
    $endpoint = (string)file_get_contents(dirname(__DIR__, 2) . '/public_showcase_image.php');
    TestHarness::assertTrue(str_contains($landing, 'PublicArtistShowcase::composition'), 'landing page uses an editorial artist composition');
    TestHarness::assertTrue(str_contains($landing, 'hero-mockup--primary') && str_contains($landing, 'hero-mockup--secondary'), 'landing renders primary and supporting mockups');
    TestHarness::assertTrue(!str_contains($landing, 'setInterval(() =>'), 'landing hero does not auto-advance while being inspected');
    TestHarness::assertTrue(str_contains($login, "showcaseBackground['url']"), 'login uses the selected artist mockup');
    TestHarness::assertTrue(str_contains($endpoint, 'PublicArtistShowcase::publicFile'), 'public endpoint authorizes files from the selected artist');
}
