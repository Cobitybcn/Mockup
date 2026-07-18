<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Video/bootstrap.php';
require_once __DIR__ . '/TestHarness.php';

TestHarness::group('Video Studio persistence and provider isolation');
$pdo = Database::connection();
$userId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if ($userId <= 0) {
    TestHarness::assertTrue(false, 'a test user exists');
    exit(TestHarness::summary());
}

$studioRepository = new VideoStudioRepository($pdo);
$service = new VideoStudioService($studioRepository);
$jobRepository = new VideoJobRepository($pdo);
$projectId = 0;
$autoProjectId = 0;
$unassignedProjectId = 0;
$uploadedAssetId = 0;
$uploadedObjectKey = '';

try {
    $library = $service->library($userId);
    $asset = $library['rootArtworks'][0] ?? $library['mockups'][0] ?? null;
    TestHarness::assertTrue(is_array($asset), 'the existing artwork library is reusable');
    $artworkId = (int)($asset['artworkId'] ?? 0);
    TestHarness::assertTrue($artworkId > 0, 'the reusable asset belongs to an artwork');
    $seriesStmt = $pdo->prepare('SELECT series_id FROM artworks WHERE id=? AND user_id=? LIMIT 1');
    $seriesStmt->execute([$artworkId, $userId]);
    $rawSeriesId = $seriesStmt->fetchColumn();
    $expectedSeriesId = $rawSeriesId === false || $rawSeriesId === null || $rawSeriesId === '' ? null : (int)$rawSeriesId;

    $autoNamed = $service->createProject($userId, ['title' => '', 'artworkId' => $artworkId, 'aspectRatio' => '9:16']);
    $autoProjectId = (int)$autoNamed['project']['id'];
    TestHarness::assertContains((string)$asset['artworkTitle'], (string)$autoNamed['project']['title'], 'an unnamed project inherits the artwork title');
    TestHarness::assertContains('Video ', (string)$autoNamed['project']['title'], 'an unnamed project receives a numbered video name');
    $pdo->prepare('DELETE FROM video_projects WHERE id=? AND user_id=?')->execute([$autoProjectId,$userId]);
    $autoProjectId = 0;

    $unassigned = $service->createProject($userId, ['title' => '', 'aspectRatio' => '9:16']);
    $unassignedProjectId = (int)$unassigned['project']['id'];
    TestHarness::assertContains('Video ', (string)$unassigned['project']['title'], 'a project can be created without selecting an artwork');
    TestHarness::assertSame(null, $unassigned['project']['artworkId'], 'an unassigned project remains independent from an artwork');
    $pdo->prepare('DELETE FROM video_projects WHERE id=? AND user_id=?')->execute([$unassignedProjectId,$userId]);
    $unassignedProjectId = 0;

    $created = $service->createProject($userId, [
        'title' => 'Video Studio Integration ' . bin2hex(random_bytes(3)),
        'globalPrompt' => 'A quiet study of the artwork.',
        'artworkId' => $artworkId,
        'aspectRatio' => '9:16',
        'targetDurationSeconds' => 30,
        'projectType' => 'artist_reel',
    ]);
    $projectId = (int)$created['project']['id'];
    TestHarness::assertTrue($projectId > 0, 'a real project is persisted');
    TestHarness::assertSame(1, (int)$created['project']['version'], 'new projects start at version one');
    TestHarness::assertSame(3, count($created['scenes']), 'new video projects start with three sequence boards');
    TestHarness::assertSame(VideoProviderRegistry::defaultMode(), (string)$created['scenes'][0]['generationMode'], 'default sequence boards match the active video provider');
    TestHarness::assertSame(VideoProviderRegistry::defaultDuration(), (int)$created['scenes'][0]['durationSeconds'], 'default sequence duration matches the active video provider');
    TestHarness::assertSame(4, (int)$created['scenes'][0]['durationSeconds'], 'new sequence boards default to four seconds');
    $capabilities = $service->capabilities();
    TestHarness::assertTrue(!array_key_exists('cameraMovements', $capabilities), 'movement is no longer exposed as an interface control');
    TestHarness::assertTrue(!array_key_exists('motionIntensities', $capabilities), 'motion intensity is no longer exposed as an interface control');
    TestHarness::assertSame('previous_last_frame', (string)$capabilities['continuity']['strategy'], 'the studio exposes its automatic continuity strategy');
    TestHarness::assertSame(10, (int)$capabilities['referenceLimits']['images'], 'Omni exposes the full ten-image reference budget');
    TestHarness::assertSame(1, (int)$capabilities['referenceLimits']['videos'], 'the studio keeps source video separate and singular');
    TestHarness::assertTrue(in_array('character_identity', $capabilities['referenceRoles'], true), 'character identity is an explicit priority role');
    TestHarness::assertTrue(in_array('wardrobe_identity', $capabilities['referenceRoles'], true), 'wardrobe identity is an explicit priority role');
    TestHarness::assertSame($artworkId, (int)$created['project']['artworkId'], 'the project keeps its selected artwork');
    TestHarness::assertSame($expectedSeriesId, $created['project']['seriesId'], 'the project inherits the artwork series automatically');

    $renamedTitle = (string)$created['project']['title'] . ' Renamed';
    $created = $service->updateProject($userId, $projectId, (int)$created['project']['version'], ['title' => $renamedTitle]);
    TestHarness::assertSame($renamedTitle, (string)$created['project']['title'], 'the current video title can be edited without recreating the project');

    $scenePayload = $service->createScene($userId, $projectId, (int)$created['project']['version'], [
        'title' => 'Opening',
        'purpose' => 'opening',
        'durationSeconds' => 6,
        'sourceType' => $asset['type'],
        'sourceId' => $asset['id'],
    ]);
    $sceneId = (int)$scenePayload['selectedSceneId'];
    $createdScene = array_values(array_filter($scenePayload['scenes'], static fn(array $scene): bool => (int)$scene['id'] === $sceneId))[0] ?? [];
    TestHarness::assertSame(4, count($scenePayload['scenes']), 'scene creation adds a board after the initial three');
    TestHarness::assertSame(1, count($createdScene['references'] ?? []), 'dragged assets persist as scene references');
    TestHarness::assertSame('reference', (string)($createdScene['references'][0]['role'] ?? ''), 'new attachments use one generic reference role');

    $imageUpload = tempnam(sys_get_temp_dir(), 'video-reference-image-');
    if ($imageUpload === false) throw new RuntimeException('Could not create the reference upload fixture.');
    try {
        file_put_contents($imageUpload, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        $scenePayload = (new VideoReferenceUploadService($studioRepository))->upload(
            $userId,
            $sceneId,
            (int)$scenePayload['project']['version'],
            [[
                'name' => 'referencia-local.png',
                'type' => 'image/png',
                'tmp_name' => $imageUpload,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($imageUpload),
            ]],
            'start_frame'
        );
    } finally {
        @unlink($imageUpload);
    }
    $createdScene = array_values(array_filter($scenePayload['scenes'], static fn(array $scene): bool => (int)$scene['id'] === $sceneId))[0] ?? [];
    $uploadedReference = array_values(array_filter(
        $createdScene['references'] ?? [],
        static fn(array $reference): bool => (string)($reference['sourceType'] ?? '') === 'reference_asset'
    ))[0] ?? [];
    $uploadedAssetId = (int)($uploadedReference['sourceId'] ?? 0);
    $uploadedAsset = $studioRepository->findReferenceAsset($userId, $uploadedAssetId);
    $uploadedObjectKey = (string)($uploadedAsset['file_path'] ?? '');
    TestHarness::assertSame(1, (int)($scenePayload['uploadedCount'] ?? 0), 'a local image can be uploaded directly into a sequence');
    TestHarness::assertSame(2, count($createdScene['references'] ?? []), 'local uploads coexist with catalog references');
    TestHarness::assertSame('start_frame', (string)($uploadedReference['role'] ?? ''), 'the local upload is stored in the selected drag and drop block');
    TestHarness::assertSame('image', (string)($uploadedReference['mediaType'] ?? ''), 'the server detects the uploaded reference media type');
    TestHarness::assertTrue($uploadedAssetId > 0 && StorageService::get($uploadedObjectKey) !== null, 'the local reference is persisted in protected storage');
    TestHarness::assertTrue(
        count(array_filter($scenePayload['assets']['uploadedReferences'] ?? [], static fn(array $asset): bool => (int)$asset['id'] === $uploadedAssetId)) === 1,
        'uploaded computer files become reusable catalog references'
    );

    $scenePayload = $service->updateReferenceInstruction(
        $userId,
        (int)$uploadedReference['id'],
        (int)$scenePayload['project']['version'],
        'Conservar esta composición como inicio exacto.'
    );
    $createdScene = array_values(array_filter($scenePayload['scenes'], static fn(array $scene): bool => (int)$scene['id'] === $sceneId))[0] ?? [];
    $updatedReference = array_values(array_filter(
        $createdScene['references'] ?? [],
        static fn(array $reference): bool => (int)($reference['id'] ?? 0) === (int)$uploadedReference['id']
    ))[0] ?? [];
    TestHarness::assertContains('inicio exacto', (string)($updatedReference['metadata']['instruction'] ?? ''), 'the purpose of each image is persisted for Omni');

    $updated = $service->updateScene($userId, $sceneId, (int)$scenePayload['project']['version'], [
        'prompt' => 'Slow lateral camera movement. Evening light on the floor.',
        'cameraMovement' => 'pan_right',
        'artworkMotion' => 'locked',
    ]);
    $updatedScene = array_values(array_filter($updated['scenes'], static fn(array $scene): bool => (int)$scene['id'] === $sceneId))[0] ?? [];
    TestHarness::assertContains('Slow lateral', (string)($updatedScene['prompt'] ?? ''), 'scene prompt autosave persists user text');

    $updated = $service->updateScene($userId, $sceneId, (int)$updated['project']['version'], ['status' => 'ready']);
    $updatedScene = array_values(array_filter($updated['scenes'], static fn(array $scene): bool => (int)$scene['id'] === $sceneId))[0] ?? [];
    TestHarness::assertSame('ready', (string)($updatedScene['status'] ?? ''), 'editorial readiness is persisted independently from provider job state');

    $duplicated = $service->duplicateScene($userId, $sceneId, (int)$updated['project']['version']);
    TestHarness::assertSame(5, count($duplicated['scenes']), 'scenes can be duplicated without generation');
    $ids = array_map(static fn(array $scene): int => (int)$scene['id'], $duplicated['scenes']);
    $reordered = $service->reorderScenes($userId, $projectId, (int)$duplicated['project']['version'], array_reverse($ids));
    TestHarness::assertSame(array_reverse($ids), array_map(static fn(array $scene): int => (int)$scene['id'], $reordered['scenes']), 'drag and drop order is persisted');

    $scene = $reordered['scenes'][0];
    $prompt = VideoPromptComposer::compose($reordered['project'], $scene);
    TestHarness::assertContains('Preserve the artwork exactly.', $prompt, 'every provider request receives the permanent fidelity directive');
    TestHarness::assertContains('artwork identity and fidelity, 2) character identity, 3) wardrobe identity', $prompt, 'reference priority is always injected into the provider prompt');
    TestHarness::assertContains('Slow lateral camera movement', $prompt, 'the composer preserves the user scene prompt');
    TestHarness::assertTrue(!str_contains($prompt, 'Camera movement:'), 'legacy movement selections are not injected into the prompt');
    TestHarness::assertTrue(!str_contains($prompt, 'Motion intensity:'), 'legacy intensity selections are not injected into the prompt');
    $continuityPrompt = VideoPromptComposer::compose($reordered['project'], $scene, true);
    TestHarness::assertContains('Continue directly from the supplied final frame', $continuityPrompt, 'later scenes receive the continuity directive');

    $generationContext = $jobRepository->generationContext($userId, (int)$scene['id']);
    $jobAssociation = $jobRepository->associationForReference($userId, (array)($generationContext['reference'] ?? []));

    $jobId = $jobRepository->createGeneration([
        'user_id' => $userId, 'project_id' => $projectId, 'scene_id' => (int)$scene['id'],
        'artwork_id' => $jobAssociation['artworkId'] ?? $reordered['project']['artworkId'],
        'series_id' => $jobAssociation['seriesId'] ?? $reordered['project']['seriesId'],
        'provider' => 'test_provider', 'model' => 'no-network-model', 'mode' => 'image_to_video',
        'idempotency_key' => bin2hex(random_bytes(32)), 'scene_version' => (int)$reordered['project']['version'],
        'input_hash' => hash('sha256', 'video-studio-test-' . $projectId), 'duration_seconds' => 6,
        'aspect_ratio' => '9:16', 'prompt' => $prompt, 'request_json' => '{}',
    ]);
    TestHarness::assertTrue($jobId > 0, 'generation metadata is stored without invoking an external provider');
    $storedJob = $jobRepository->findGeneration($jobId);
    TestHarness::assertSame('queued', (string)$storedJob['status'], 'new generation jobs are asynchronous');
    TestHarness::assertSame($artworkId, (int)$storedJob['artwork_id'], 'generation history stores its artwork snapshot');
    TestHarness::assertSame($expectedSeriesId, $storedJob['series_id'] === null ? null : (int)$storedJob['series_id'], 'generation history stores its series snapshot');

    $jobRepository->markGenerationSucceeded(
        $jobId,
        'video/generations/tests/continuity-' . $jobId . '.mp4',
        '',
        6,
        ['test' => true]
    );
    $targetScene = $reordered['scenes'][1] ?? null;
    TestHarness::assertTrue(is_array($targetScene), 'a different sequence is available for explicit continuation');
    $continued = $service->addReference($userId, (int)$targetScene['id'], (int)$reordered['project']['version'], [
        'sourceType' => 'generation_job',
        'sourceId' => $jobId,
        'role' => 'start_frame',
    ]);
    $continuedScene = array_values(array_filter(
        $continued['scenes'],
        static fn(array $candidate): bool => (int)$candidate['id'] === (int)$targetScene['id']
    ))[0] ?? [];
    $continuityReference = array_values(array_filter(
        $continuedScene['references'] ?? [],
        static fn(array $reference): bool => (string)($reference['role'] ?? '') === 'start_frame'
            && (string)($reference['sourceType'] ?? '') === 'generation_job'
    ))[0] ?? [];
    TestHarness::assertSame($jobId, (int)($continuityReference['sourceId'] ?? 0), 'a generated clip can be assigned explicitly to another sequence Start Frame');
    TestHarness::assertSame('video', (string)($continuityReference['mediaType'] ?? ''), 'the generated continuation remains identified as video input');
    try {
        $service->addReference($userId, (int)$scene['id'], (int)$continued['project']['version'], [
            'sourceType' => 'generation_job',
            'sourceId' => $jobId,
            'role' => 'start_frame',
        ]);
        TestHarness::assertTrue(false, 'a sequence cannot reference its own generated result');
    } catch (InvalidArgumentException) {
        TestHarness::assertTrue(true, 'a sequence cannot reference its own generated result');
    }
    try {
        $service->addReference($userId, (int)$targetScene['id'], (int)$continued['project']['version'], [
            'sourceType' => 'generation_job',
            'sourceId' => $jobId,
            'role' => 'end_frame',
        ]);
        TestHarness::assertTrue(false, 'continuity video is rejected outside Start Frame');
    } catch (InvalidArgumentException) {
        TestHarness::assertTrue(true, 'continuity video is rejected outside Start Frame');
    }

    $studioJavascript = (string)file_get_contents(__DIR__ . '/../video_studio.js');
    $studioPage = (string)file_get_contents(__DIR__ . '/../video.php');
    $studioStyles = (string)file_get_contents(__DIR__ . '/../video_studio.css');
    $editorPage = (string)file_get_contents(__DIR__ . '/../video_editor.php');
    $editorServiceSource = (string)file_get_contents(__DIR__ . '/../app/Video/VideoEditorService.php');
    $studioSidebar = (string)file_get_contents(__DIR__ . '/../sidebar.php');
    TestHarness::assertContains('data-project-aspect-ratio="9:16"', $studioPage, 'the workspace exposes a direct vertical format choice');
    TestHarness::assertContains('data-project-aspect-ratio="16:9"', $studioPage, 'the workspace exposes a direct horizontal format choice');
    TestHarness::assertContains('function createProjectNow()', $studioJavascript, 'new projects are created directly from the workspace');
    TestHarness::assertContains('initialArtworkId', $studioPage, 'Video Studio accepts the active artwork context from Mockup Lab');
    TestHarness::assertContains('initialArtworkFilter', $studioPage, 'the active artwork is normalized to its unified group');
    TestHarness::assertContains("'video.php?artwork_id='", $studioSidebar, 'the Video Studio navigation keeps the active artwork context');
    TestHarness::assertContains("action: 'library_list'", $studioJavascript, 'the reference library can refresh after Mockup Lab creates a variation');
    TestHarness::assertContains("window.addEventListener('focus', refreshLibrary)", $studioJavascript, 'returning to Video Studio refreshes newly generated variations');
    TestHarness::assertContains('function artworkFilterKey(asset)', $studioJavascript, 'the catalog uses the unified artwork group as its filter identity');
    TestHarness::assertContains('selectedArtwork?.canonicalArtworkId', $studioJavascript, 'projects retain the canonical root behind a unified artwork selection');
    TestHarness::assertContains('!artworkMap().has(state.artworkFilter)', $studioJavascript, 'an open studio adopts a newly merged artwork group after refreshing');
    $sameArtworkMethod = new ReflectionMethod(VideoGenerationService::class, 'sameUnifiedArtwork');
    TestHarness::assertTrue($sameArtworkMethod->invoke(null, ['artworkId' => 10, 'artworkGroupId' => 7], ['artworkId' => 11, 'artworkGroupId' => 7]), 'different roots in one unified artwork can form a video sequence');
    TestHarness::assertTrue(!$sameArtworkMethod->invoke(null, ['artworkId' => 10, 'artworkGroupId' => 7], ['artworkId' => 11, 'artworkGroupId' => 8]), 'roots from different unified artworks remain isolated');
    $artworkTitleMethod = new ReflectionMethod(VideoStudioRepository::class, 'artworkTitle');
    TestHarness::assertSame(
        'Crimson Ascendant Divisions',
        $artworkTitleMethod->invoke(null, ['group_title' => 'Crimson Markers', 'sheet_title' => 'Crimson Ascendant Divisions'], 'Artwork'),
        'individual root titles remain available as metadata inside a unified artwork'
    );
    TestHarness::assertContains("changes: { aspectRatio }", $studioJavascript, 'format icons persist the selected video ratio');
    TestHarness::assertContains('.vds-project-action--save', $studioStyles, 'primary project actions use the approved soft-color treatment');
    TestHarness::assertContains('width: 92px', $studioStyles, 'project actions keep a large square footprint');
    TestHarness::assertContains('data-generated-clip', $studioJavascript, 'generated results expose a direct drag source');
    TestHarness::assertContains('data-use-clip-next', $studioJavascript, 'generated results expose a one-click next-sequence action');
    TestHarness::assertContains('data-continuation-frame-preview', $studioJavascript, 'an explicit continuation previews the frame that will actually be sent');
    TestHarness::assertContains('duration - 0.12', $studioJavascript, 'the continuation preview seeks to the same final-frame offset used by FFmpeg');
    TestHarness::assertContains('.vds-continuation-frame-badge', $studioStyles, 'the continuation preview is visibly identified as the final frame');
    TestHarness::assertTrue(!str_contains($studioJavascript, 'Video base para editar'), 'source-video editing no longer competes with sequence generation');
    TestHarness::assertContains("compactReferenceSlot(scene, 'artwork_fidelity', 3", $studioJavascript, 'image 3 is reserved for artwork fidelity');
    TestHarness::assertContains("compactReferenceSlot(scene, 'character_identity', 4", $studioJavascript, 'image 4 is reserved for character identity');
    TestHarness::assertContains("compactReferenceSlot(scene, 'wardrobe_identity', 5", $studioJavascript, 'image 5 is reserved for wardrobe identity');
    TestHarness::assertContains('Array.from({ length: 5 }', $studioJavascript, 'images 6 through 10 remain available as additional references');
    TestHarness::assertContains('video_editor.php?generation_id=', $studioJavascript, 'generated results open the standalone video editor');
    TestHarness::assertTrue(!str_contains($studioJavascript, 'data-adjust-result'), 'inline editing controls are removed from sequence boards');
    TestHarness::assertContains("? 'Regenerar'", $studioJavascript, 'generated results keep an independent regeneration action');
    TestHarness::assertContains('Cada edición crea una nueva versión', $editorPage, 'the standalone editor preserves the original video');
    TestHarness::assertContains('Opcional · hasta 10', $editorPage, 'the standalone editor exposes the full Omni image budget without visual clutter');
    TestHarness::assertContains("'role' => 'source_video'", $editorServiceSource, 'video editing always resubmits the real source clip');
    TestHarness::assertTrue(!str_contains($editorServiceSource, "snapshot['previousInteractionId']"), 'video editing never combines previous_interaction_id with the Omni edit task');
    TestHarness::assertSame(1, VideoReferencePolicy::promptNumber('start_frame'), 'the start image has a stable prompt number');
    TestHarness::assertSame(2, VideoReferencePolicy::promptNumber('end_frame'), 'the target image has a stable prompt number');
    TestHarness::assertSame(3, VideoReferencePolicy::promptNumber('artwork_fidelity'), 'artwork has a stable prompt number');
    TestHarness::assertSame(10, VideoReferencePolicy::promptNumber('reference', 5), 'the fifth additional reference maps to image ten');

    try {
        (new VertexVeoProvider())->generateFromFrames([]);
        TestHarness::assertTrue(false, 'unsupported provider modes fail closed');
    } catch (Throwable) {
        TestHarness::assertTrue(true, 'unsupported provider modes fail closed');
    }
    $omniProvider = new VertexGeminiOmniProvider();
    $responseFormatMethod = new ReflectionMethod(VertexGeminiOmniProvider::class, 'videoResponseFormat');
    $uriFormats = $responseFormatMethod->invoke($omniProvider, '9:16', 4, 'gs://video-bucket/output/job-1');
    TestHarness::assertSame(1, count($uriFormats), 'Gemini Omni sends response_format as the documented list');
    TestHarness::assertSame('uri', (string)($uriFormats[0]['delivery'] ?? ''), 'Gemini Omni requests URI delivery when storage is configured');
    TestHarness::assertSame('gs://video-bucket/output/job-1/', (string)($uriFormats[0]['gcs_uri'] ?? ''), 'Gemini Omni URI delivery includes the required GCS destination');
    $inlineFormats = $responseFormatMethod->invoke($omniProvider, '16:9', 5, '');
    TestHarness::assertTrue(!isset($inlineFormats[0]['delivery'], $inlineFormats[0]['gcs_uri']), 'Gemini Omni falls back to inline delivery without a GCS destination');
    $editFormats = $responseFormatMethod->invoke($omniProvider, '16:9', 5, '', false);
    TestHarness::assertTrue(!isset($editFormats[0]['aspect_ratio']), 'Gemini Omni edit requests omit the forbidden aspect ratio');
    TestHarness::assertTrue(!isset($editFormats[0]['duration']), 'Gemini Omni edit requests let the source video determine duration');
    $errorMessageMethod = new ReflectionMethod(VertexGeminiOmniProvider::class, 'errorMessage');
    $nestedError = $errorMessageMethod->invoke($omniProvider, ['steps' => [['error' => ['message' => 'Provider detail']]]]);
    TestHarness::assertSame('Provider detail', $nestedError, 'Gemini Omni preserves nested provider errors for the interface');
    $encodeProviderResponseMethod = new ReflectionMethod(VideoJobRepository::class, 'encodeProviderResponse');
    $storedProviderResponse = (string)$encodeProviderResponseMethod->invoke(null, [
        'id' => 'interaction-test',
        'status' => 'completed',
        'output' => ['data' => str_repeat('A', 128 * 1024)],
    ]);
    $decodedProviderResponse = json_decode($storedProviderResponse, true);
    TestHarness::assertSame('interaction-test', (string)($decodedProviderResponse['id'] ?? ''), 'provider response compaction preserves the interaction ID');
    TestHarness::assertTrue(isset($decodedProviderResponse['output']['data']['_omitted']), 'provider response compaction removes inline video bytes from the database');
    TestHarness::assertTrue(strlen($storedProviderResponse) < 4096, 'provider response compaction keeps diagnostic JSON small');
    $generationService = new VideoGenerationService($studioRepository, $jobRepository, new VideoTaskDispatcher(), new VideoMediaStorage());
    $firstInputMethod = new ReflectionMethod(VideoGenerationService::class, 'firstInputRecord');
    $videoInput = ['id' => 62, 'role' => 'start_frame', 'mediaType' => 'video', 'file' => 'reference.mp4'];
    $endImage = ['id' => 63, 'role' => 'end_frame', 'mediaType' => 'image', 'file' => 'end.jpg'];
    TestHarness::assertSame(62, (int)($firstInputMethod->invoke($generationService, [$videoInput])['id'] ?? 0), 'a video-only reference is selected as the provider input');
    TestHarness::assertSame(62, (int)($firstInputMethod->invoke($generationService, [$endImage,$videoInput])['id'] ?? 0), 'the start-frame video keeps priority over an end-frame image');
    try {
        $omniProvider->generateFromFrames([]);
        TestHarness::assertTrue(false, 'Gemini Omni interpolation fails closed');
    } catch (DomainException) {
        TestHarness::assertTrue(true, 'Gemini Omni interpolation fails closed');
    }
} finally {
    if ($unassignedProjectId > 0) {
        $pdo->prepare('DELETE FROM video_projects WHERE id=? AND user_id=?')->execute([$unassignedProjectId,$userId]);
    }
    if ($autoProjectId > 0) {
        $pdo->prepare('DELETE FROM video_projects WHERE id=? AND user_id=?')->execute([$autoProjectId,$userId]);
    }
    if ($projectId > 0) {
        $stmt = $pdo->prepare('DELETE FROM video_projects WHERE id=? AND user_id=?');
        $stmt->execute([$projectId,$userId]);
    }
    if ($uploadedAssetId > 0) {
        $pdo->prepare('DELETE FROM video_reference_assets WHERE id=? AND user_id=?')->execute([$uploadedAssetId,$userId]);
    }
    if ($uploadedObjectKey !== '') StorageService::delete($uploadedObjectKey);
}

TestHarness::assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM video_projects WHERE title LIKE 'Video Studio Integration %'")->fetchColumn(), 'integration data is removed after the test');
exit(TestHarness::summary());
