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
    TestHarness::assertSame('first_last_frame', (string)$created['scenes'][0]['generationMode'], 'default sequence boards use Start and End frames');
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
    TestHarness::assertContains('Slow lateral camera movement', $prompt, 'the composer preserves the user scene prompt');

    $generationContext = $jobRepository->generationContext($userId, (int)$scene['id']);
    $jobAssociation = $jobRepository->associationForReference($userId, (array)($generationContext['reference'] ?? []));

    $jobId = $jobRepository->createGeneration([
        'user_id' => $userId, 'project_id' => $projectId, 'scene_id' => (int)$scene['id'],
        'artwork_id' => $jobAssociation['artworkId'], 'series_id' => $jobAssociation['seriesId'],
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

    try {
        (new VertexVeoProvider())->generateFromFrames([]);
        TestHarness::assertTrue(false, 'unsupported provider modes fail closed');
    } catch (Throwable) {
        TestHarness::assertTrue(true, 'unsupported provider modes fail closed');
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
}

TestHarness::assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM video_projects WHERE title LIKE 'Video Studio Integration %'")->fetchColumn(), 'integration data is removed after the test');
exit(TestHarness::summary());
