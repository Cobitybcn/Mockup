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

        $removedImage = $library->deleteImage('curated_scene', 'shared-2.jpg');
        TestHarness::assertSame('shared-2.jpg', (string)$removedImage['file_name'], 'a scene source can be removed from its dedicated workspace');
        TestHarness::assertTrue(!is_file($basePath . '/curated_scene/shared-2.jpg'), 'removing a scene source deletes its stored image');

        $deleted = $library->deleteCategory('curated_scene');
        TestHarness::assertSame(2, (int)$deleted['deleted_images'], 'delete reports every remaining scene image');
        TestHarness::assertTrue(!is_dir($basePath . '/curated_scene'), 'delete removes the scene folder');

        $staleIndex = [
            'generated_at' => date(DATE_ATOM),
            'categories' => [[
                'category_slug' => 'missing_scene',
                'category_name' => 'Missing Scene',
                'relative_path' => 'storage/world_mothers_test/missing_scene',
            ]],
            'images' => [
                'missing_scene' => [[
                    'file_name' => 'missing.jpg',
                    'relative_path' => 'storage/world_mothers_test/missing_scene/missing.jpg',
                ]],
            ],
        ];
        file_put_contents($basePath . '/index.json', json_encode($staleIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $freshLibrary = new WorldMotherLibrary($basePath, 'storage/world_mothers_test');
        TestHarness::assertSame(0, count($freshLibrary->categories()), 'local library hides stale indexed scenes whose folders are missing');

        mkdir($basePath . '/Quiet Luxury', 0775, true);
        file_put_contents($basePath . '/Quiet Luxury/reference.jpg', 'legacy-scene-reference');
        $freshLibrary->rebuildIndex();
        $generator = new WorldMotherGenerator($freshLibrary);
        $resolveCategory = new ReflectionMethod($generator, 'resolveCategorySlug');
        $resolveCategory->setAccessible(true);
        TestHarness::assertSame(
            'Quiet Luxury',
            $resolveCategory->invoke($generator, 'quiet_luxury'),
            'legacy scene folder names resolve from normalized card URLs without creating a duplicate category'
        );

        $appRoot = dirname(__DIR__, 2);
        $studioSource = (string)file_get_contents($appRoot . '/world_mother_studio.php');
        $variationLabSource = (string)file_get_contents($appRoot . '/world_mother_variation_lab.php');
        $sidebarSource = (string)file_get_contents($appRoot . '/sidebar.php');
        $mediaSource = (string)file_get_contents($appRoot . '/world_mother_media.php');
        $generatorSource = (string)file_get_contents($appRoot . '/app/Services/WorldMotherGenerator.php');
        TestHarness::assertContains("wms_media_url(\$refPath)", $studioSource, 'analysis previews use the authenticated media endpoint');
        TestHarness::assertContains("storage/world_mother_uploads/", $mediaSource, 'media endpoint accepts persisted uploaded references');
        TestHarness::assertContains("StorageService::uploadFile(\$storageKey, \$path)", $studioSource, 'uploaded references are persisted when cloud storage is active');
        TestHarness::assertContains('persistGeneratedImage', $generatorSource, 'generated scene variants are persisted before indexing');
        TestHarness::assertContains('font-size: 44px', $studioSource, 'Scene Studio keeps the approved editorial header hierarchy');
        TestHarness::assertContains('<summary>Reference Diversity</summary>', $studioSource, 'Scene Studio keeps diversity corrections inside a folded administrative panel');
        TestHarness::assertContains('name="similarity_group[]"', $studioSource, 'Scene Studio can curate reference similarity groups');
        TestHarness::assertContains('array_slice($images, 0, 3)', $studioSource, 'scene cards use the approved compact three-thumbnail treatment');
        TestHarness::assertContains('laboratoryAction.disabled = uploadedFiles.length === 0;', $studioSource, 'selecting scene references enables the analysis action');
        TestHarness::assertTrue(strpos($studioSource, "laboratoryAction.getAttribute('form')") === false, 'scene upload does not require a redundant form attribute before it can start');
        TestHarness::assertContains('name="variant_images[]"', $studioSource, 'each scene workspace exposes one direct multi-image input');
        TestHarness::assertContains('data-scene-source-dropzone', $studioSource, 'scene sources can be dropped from the computer');
        TestHarness::assertContains('sourceUploader.requestSubmit()', $studioSource, 'selected scene images upload without a second form action');
        TestHarness::assertContains('Upload between 1 and 24 images at a time.', $studioSource, 'direct scene feeding accepts useful image batches');
        TestHarness::assertTrue(strpos($studioSource, '<h3>Transform an image</h3>') === false, 'the scene workspace removes the redundant transformation form');
        TestHarness::assertTrue(strpos($studioSource, '<h3>Extend this scene</h3>') === false, 'the scene workspace removes the redundant extension form');
        TestHarness::assertTrue(strpos($studioSource, 'name="transform_prompt"') === false, 'the scene workspace does not duplicate the prompt editor');
        TestHarness::assertTrue(strpos($studioSource, 'name="similar_prompt"') === false, 'the scene workspace does not duplicate related-style controls');
        TestHarness::assertContains('name="action" value="delete_variant"', $studioSource, 'individual scene sources can be removed from the scene workspace');
        TestHarness::assertContains('world_mother_studio.php?scene=', $studioSource, 'scene cards open a dedicated scene workspace');
        TestHarness::assertContains('WorldMotherGenerator::safeSlug($candidateSceneSlug) === WorldMotherGenerator::safeSlug($requestedSceneSlug)', $studioSource, 'scene detail accepts normalized URLs for legacy folder names');
        TestHarness::assertContains('world_mother_variation_lab.php?scene=', $studioSource, 'every contained scene image opens the Scene Source Lab');
        TestHarness::assertContains('class="mobile-scale-dial"', $variationLabSource, 'Scene Source Lab reuses the Mockup Lab scale dial');
        TestHarness::assertContains('class="mobile-human-dial"', $variationLabSource, 'Scene Source Lab reuses the Mockup Lab human presence dial');
        TestHarness::assertContains('class="mobile-lighting-dial"', $variationLabSource, 'Scene Source Lab reuses the Mockup Lab lighting dial');
        TestHarness::assertContains('name="artwork_scale"', $variationLabSource, 'the cloned scale control is connected to scene generation');
        TestHarness::assertContains('name="human_presence"', $variationLabSource, 'the cloned human control is connected to scene generation');
        TestHarness::assertContains('name="lighting_modifier"', $variationLabSource, 'the cloned lighting control is connected to scene generation');
        TestHarness::assertTrue(strpos($variationLabSource, 'scene-direction-dial') === false, 'Scene Source Lab does not retain invented floating controls');
        TestHarness::assertContains('$generator->editWorldMother(', $variationLabSource, 'Scene Source Lab uses conservative editing instead of original scene generation');
        TestHarness::assertTrue(strpos($variationLabSource, '$generator->generateOriginalWorldMother(') === false, 'Scene Source Lab does not invoke the scene invention workflow');
        TestHarness::assertContains('IMAGE 1 is the mandatory source of truth', $generatorSource, 'the scene edit prompt treats the attached image as authoritative');
        TestHarness::assertContains('Everything not explicitly requested must remain visually unchanged', $generatorSource, 'the scene edit prompt locks all unrequested content');
        TestHarness::assertContains("'GEMINI_OUTPUT_ASPECT_RATIO'] = \$this->closestSupportedAspectRatio(\$referencePath)", $generatorSource, 'scene editing preserves the closest supported source aspect ratio');
        TestHarness::assertContains("'generation_kind' => \$editMode ? 'conservative_edit'", $generatorSource, 'scene edit audits identify conservative edits');
        TestHarness::assertContains('<summary id="scene-source-title">Additional Prompt</summary>', $variationLabSource, 'Scene Source Lab follows the folded prompt treatment from Mockup Lab');
        TestHarness::assertContains('Apply Changes', $variationLabSource, 'Scene Source Lab exposes the familiar primary editing action');
        TestHarness::assertContains('Other Previous Variations', $variationLabSource, 'Scene Source Lab keeps the scene sources available as editing history');
        TestHarness::assertContains("'world_mother_variation_lab.php'", $sidebarSource, 'Scene Studio stays active while editing an individual source');
        TestHarness::assertTrue(strpos($studioSource, 'popover-preview') === false, 'Scene Studio no longer renders the displaced floating thumbnail zoom');
        TestHarness::assertTrue(strpos($studioSource, 'scene-gallery-featured') === false, 'scene cards do not expand a variant into an oversized cover');
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
