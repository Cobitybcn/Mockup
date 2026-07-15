<?php
declare(strict_types=1);

function run_world_mother_library_admin_tests(): void
{
    TestHarness::group('Scene library: create, merge, rename, delete, and index sync');

    $basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'world-mother-admin-' . bin2hex(random_bytes(5));
    mkdir($basePath, 0775, true);

    try {
        $library = new WorldMotherLibrary($basePath, 'storage/world_mothers_test');
        $library->createCategory('Source Scene');
        $library->createCategory('Target Scene');

        file_put_contents($basePath . '/source_scene/shared.jpg', 'source-version');
        file_put_contents($basePath . '/target_scene/shared.jpg', 'target-version');
        file_put_contents($basePath . '/source_scene/duplicate.png', 'same-image');
        file_put_contents($basePath . '/target_scene/duplicate.png', 'same-image');
        $library->rebuildIndex();

        $merge = $library->mergeCategory('source_scene', 'target_scene');
        TestHarness::assertSame(1, (int)$merge['moved_count'], 'merge preserves a different same-name image with a suffix');
        TestHarness::assertSame(1, (int)$merge['duplicate_count'], 'merge removes one byte-identical duplicate');
        TestHarness::assertTrue(!is_dir($basePath . '/source_scene'), 'merge removes the source scene folder');
        TestHarness::assertTrue(is_file($basePath . '/target_scene/shared-2.jpg'), 'same-name image receives a collision-safe filename');

        $renamed = $library->renameCategory('target_scene', 'Curated Scene');
        TestHarness::assertSame('curated_scene', (string)$renamed['category_slug'], 'rename normalizes the destination scene slug');
        TestHarness::assertTrue(is_dir($basePath . '/curated_scene'), 'renamed scene folder exists');

        $index = json_decode((string)file_get_contents($basePath . '/index.json'), true);
        TestHarness::assertSame('curated_scene', (string)($index['categories'][0]['category_slug'] ?? ''), 'index reflects the renamed real folder');
        TestHarness::assertSame(3, count((array)($index['images']['curated_scene'] ?? [])), 'index contains all merged variants');

        $deleted = $library->deleteCategory('curated_scene');
        TestHarness::assertSame(3, (int)$deleted['deleted_images'], 'delete reports every removed scene image');
        TestHarness::assertTrue(!is_dir($basePath . '/curated_scene'), 'delete removes the scene folder');

        $appRoot = dirname(__DIR__, 2);
        $studioSource = (string)file_get_contents($appRoot . '/world_mother_studio.php');
        $mediaSource = (string)file_get_contents($appRoot . '/world_mother_media.php');
        $generatorSource = (string)file_get_contents($appRoot . '/app/Services/WorldMotherGenerator.php');
        TestHarness::assertContains("wms_media_url(\$refPath)", $studioSource, 'analysis previews use the authenticated media endpoint');
        TestHarness::assertContains("storage/world_mother_uploads/", $mediaSource, 'media endpoint accepts persisted uploaded references');
        TestHarness::assertContains("StorageService::uploadFile(\$storageKey, \$path)", $studioSource, 'uploaded references are persisted when cloud storage is active');
        TestHarness::assertContains('persistGeneratedImage', $generatorSource, 'generated scene variants are persisted before indexing');
        TestHarness::assertContains('font-size: 44px', $studioSource, 'Scene Studio keeps the approved editorial header hierarchy');
        TestHarness::assertContains('width: 56px', $studioSource, 'scene variant thumbnails use the approved larger size');
    } finally {
        remove_world_mother_admin_test_tree($basePath);
    }
}

function remove_world_mother_admin_test_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new DirectoryIterator($path) as $item) {
        if ($item->isDot()) {
            continue;
        }
        if ($item->isDir()) {
            remove_world_mother_admin_test_tree($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}
