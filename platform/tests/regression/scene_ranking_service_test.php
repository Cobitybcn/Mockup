<?php
declare(strict_types=1);

function run_scene_ranking_service_regression_tests(): void
{
    TestHarness::group('Scene ranking: editorial, popularity, versatility and usage');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE mockups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        source_artwork_id INTEGER,
        artwork_file TEXT NOT NULL,
        selector_state_json TEXT NOT NULL,
        created_at TEXT NOT NULL
    )");
    $migration = require dirname(__DIR__, 2) . '/migrations/schema/20260719_000002_scene_ranking.php';
    $migration['up']($pdo);

    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scene-ranking-' . bin2hex(random_bytes(5));
    mkdir($storage . DIRECTORY_SEPARATOR . 'world_mother_favorites', 0775, true);
    mkdir($storage . DIRECTORY_SEPARATOR . 'mockup_favorites', 0775, true);
    file_put_contents($storage . DIRECTORY_SEPARATOR . 'world_mother_favorites' . DIRECTORY_SEPARATOR . 'user_8.json', json_encode(['new_scene']));

    $insert = $pdo->prepare('INSERT INTO mockups
        (user_id,source_artwork_id,artwork_file,selector_state_json,created_at)
        VALUES (:user_id,:artwork_id,:artwork_file,:selector_state_json,:created_at)');
    foreach (['front', 'low', 'three-quarter', 'detail'] as $index => $camera) {
        $insert->execute([
            'user_id' => 7,
            'artwork_id' => 42,
            'artwork_file' => 'root.jpg',
            'selector_state_json' => json_encode([
                'world_mother_category' => 'proven_scene',
                'scene_board_index' => 1,
                'combination' => ['selected_camera_slot_id' => $camera],
            ]),
            'created_at' => date(DATE_ATOM),
        ]);
    }
    file_put_contents($storage . DIRECTORY_SEPARATOR . 'mockup_favorites' . DIRECTORY_SEPARATOR . 'user_7.json', json_encode([1]));

    $service = new SceneRankingService($pdo, $storage);
    $categories = $service->enrich([
        ['category_slug' => 'proven_scene', 'category_name' => 'Proven Scene', 'image_count' => 3],
        ['category_slug' => 'new_scene', 'category_name' => 'New Scene', 'image_count' => 3],
    ]);
    $bySlug = [];
    foreach ($categories as $category) {
        $bySlug[(string)$category['category_slug']] = $category;
    }

    TestHarness::assertSame(1, (int)$bySlug['proven_scene']['usage_count'], 'four views from one batch count as one scene usage');
    TestHarness::assertSame(4, (int)$bySlug['proven_scene']['distinct_cameras'], 'versatility recognizes distinct camera slots');
    TestHarness::assertTrue((int)$bySlug['proven_scene']['popularity_score'] > 0, 'recent use and favorite outputs contribute to popularity');
    TestHarness::assertTrue((int)$bySlug['new_scene']['popularity_score'] > 0, 'scene favorites give new scenes a popularity signal');

    $service->updateProfile('new_scene', 90, date('Y-m-d', strtotime('+7 days')), 95);
    $ranked = $service->sort($service->enrich($categories), 'featured');
    TestHarness::assertSame('new_scene', (string)$ranked[0]['category_slug'], 'Featured order respects the active manual boost');
    TestHarness::assertSame(90, (int)$ranked[0]['featured_score_effective'], 'active featured scores remain effective');

    $service->updateProfile('new_scene', 100, date('Y-m-d', strtotime('-1 day')), 95);
    $expired = $service->enrich($categories);
    $expiredNew = array_values(array_filter($expired, static fn (array $category): bool => $category['category_slug'] === 'new_scene'))[0];
    TestHarness::assertSame(0, (int)$expiredNew['featured_score_effective'], 'expired Featured boosts stop affecting discovery');

    $alpha = $service->sort($expired, 'alpha');
    TestHarness::assertSame('new_scene', (string)$alpha[0]['category_slug'], 'A-Z remains available as a secondary order');

    $service->updateProfile('proven_scene', 0, '', 20);
    $service->updateProfile('new_scene', 0, '', 90);
    $adminModes = $service->enrich($categories);
    TestHarness::assertSame('new_scene', (string)$service->sort($adminModes, 'editorial')[0]['category_slug'], 'Scene Studio can prioritize editorial score');
    TestHarness::assertSame('proven_scene', (string)$service->sort($adminModes, 'usage')[0]['category_slug'], 'Scene Studio can prioritize proven usage');

    $createScenesSource = (string)file_get_contents(dirname(__DIR__, 2) . '/create_scenes.php');
    TestHarness::assertContains(
        "sort(\$sceneRanking->enrich(\$sceneCategories), 'recommended')",
        $createScenesSource,
        'Recommended replaces alphabetical order as the default discovery mode'
    );
    foreach (['recommended', 'featured', 'popular', 'versatile', 'newest', 'alpha'] as $mode) {
        TestHarness::assertContains(
            '<option value="' . $mode . '">',
            $createScenesSource,
            'Create Scenes exposes the ' . $mode . ' order'
        );
    }

    $studioSource = (string)file_get_contents(dirname(__DIR__, 2) . '/world_mother_studio.php');
    $engineSource = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/MockupCombinationEngine.php');
    TestHarness::assertContains('name="editorial_score"', $studioSource, 'Scene Studio can edit the stable editorial score');
    TestHarness::assertContains('name="featured_until"', $studioSource, 'Featured boosts can expire automatically');
    TestHarness::assertContains("sort(\$sceneRanking->enrich(\$library->categories()), 'recommended')", $studioSource, 'Scene Studio opens with Recommended as its administrative order');
    foreach (['recommended', 'featured', 'editorial', 'popular', 'versatile', 'usage', 'newest', 'alpha'] as $mode) {
        TestHarness::assertContains(
            '<option value="' . $mode . '">',
            $studioSource,
            'Scene Studio exposes the ' . $mode . ' order'
        );
    }
    foreach (['featured', 'low-usage', 'no-data'] as $filter) {
        TestHarness::assertContains(
            '<option value="' . $filter . '">',
            $studioSource,
            'Scene Studio exposes the ' . $filter . ' administrative filter'
        );
    }
    TestHarness::assertContains(
        "sort(\$ranking->enrich(\$categories), 'recommended')",
        $engineSource,
        'Explore More orders scene alternatives with the Recommended score'
    );
    TestHarness::assertContains(
        'It remains pinned while the alternatives follow Recommended order.',
        $engineSource,
        'Explore More keeps the active scene first while ranking alternatives'
    );

    foreach (glob($storage . DIRECTORY_SEPARATOR . 'world_mother_favorites' . DIRECTORY_SEPARATOR . '*') ?: [] as $file) unlink($file);
    foreach (glob($storage . DIRECTORY_SEPARATOR . 'mockup_favorites' . DIRECTORY_SEPARATOR . '*') ?: [] as $file) unlink($file);
    rmdir($storage . DIRECTORY_SEPARATOR . 'world_mother_favorites');
    rmdir($storage . DIRECTORY_SEPARATOR . 'mockup_favorites');
    rmdir($storage);
}
