<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Video/bootstrap.php';
require_once __DIR__ . '/TestHarness.php';

TestHarness::group('Deterministic Video Studio export');
if (!VideoFfmpeg::available()) {
    TestHarness::assertTrue(false, 'FFmpeg is available');
    exit(TestHarness::summary());
}

$pdo = Database::connection();
$userId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$studioRepository = new VideoStudioRepository($pdo);
$studio = new VideoStudioService($studioRepository);
$jobs = new VideoJobRepository($pdo);
$projectId = 0;
$objectKeys = [];
$uploadedReferenceAssetIds = [];
$working = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'video-export-test-' . bin2hex(random_bytes(5));
VideoFfmpeg::ensureDirectory($working);

try {
    $payload = $studio->createProject($userId, ['title' => 'Video Export Integration ' . bin2hex(random_bytes(3)), 'aspectRatio' => '16:9']);
    $projectId = (int)$payload['project']['id'];
    $version = (int)$payload['project']['version'];
    foreach ($payload['scenes'] as $defaultScene) {
        $payload = $studio->deleteScene($userId, (int)$defaultScene['id'], $version);
        $version = (int)$payload['project']['version'];
    }
    $colors = ['#2e3b34','#7a554b'];

    foreach ($colors as $index => $color) {
        $scenePayload = $studio->createScene($userId, $projectId, $version, [
            'title' => 'Synthetic scene ' . ($index + 1), 'durationSeconds' => 1,
            'transitionType' => $index === 0 ? 'cross_dissolve' : 'cut', 'transitionDurationSeconds' => $index === 0 ? 0.2 : 0,
        ]);
        $version = (int)$scenePayload['project']['version'];
        $sceneId = (int)$scenePayload['selectedSceneId'];
        $source = $working . DIRECTORY_SEPARATOR . 'source_' . $index . '.mp4';
        VideoFfmpeg::run([VideoFfmpeg::binary(),'-y','-f','lavfi','-i','color=c=' . $color . ':s=640x360:r=30','-t','1','-c:v','libx264','-pix_fmt','yuv420p',$source]);
        $key = sprintf('video/test/%d/%d/source_%d.mp4', $userId,$projectId,$index);
        StorageService::uploadFile($key,$source);
        $objectKeys[] = $key;
        if ($index === 0) {
            $continuityFrame = (new VideoMediaStorage())->prepareContinuityFrame($key);
            TestHarness::assertTrue(is_file($continuityFrame['path']) && filesize($continuityFrame['path']) > 0, 'the previous clip final frame can be prepared for continuity');
            foreach ($continuityFrame['temporaryPaths'] as $temporaryPath) @unlink($temporaryPath);

            $referenceFrame = (new VideoMediaStorage())->prepareReferenceVideoFrame($key);
            TestHarness::assertTrue(is_file($referenceFrame['path']) && filesize($referenceFrame['path']) > 0, 'an uploaded video final frame can become a provider image input');
            foreach ($referenceFrame['temporaryPaths'] as $temporaryPath) @unlink($temporaryPath);

            $scenePayload = (new VideoReferenceUploadService($studioRepository))->upload($userId, $sceneId, $version, [[
                'name' => 'referencia-local.mp4',
                'type' => 'video/mp4',
                'tmp_name' => $source,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($source),
            ]], 'source_video');
            $version = (int)$scenePayload['project']['version'];
            $uploadedVideo = $scenePayload['assets']['uploadedReferences'][0] ?? [];
            $uploadedReferenceAssetIds[] = (int)($uploadedVideo['id'] ?? 0);
            $uploadedVideoRow = $studioRepository->findReferenceAsset($userId, (int)($uploadedVideo['id'] ?? 0));
            if (is_array($uploadedVideoRow)) $objectKeys[] = (string)$uploadedVideoRow['file_path'];
            TestHarness::assertSame('video', (string)($uploadedVideo['mediaType'] ?? ''), 'a local MP4 can be uploaded as the editable source video');
        }
        $jobId = $jobs->createGeneration([
            'user_id'=>$userId,'project_id'=>$projectId,'scene_id'=>$sceneId,'provider'=>'test_provider','model'=>'synthetic',
            'mode'=>'image_to_video','idempotency_key'=>bin2hex(random_bytes(32)),'scene_version'=>$version,
            'input_hash'=>hash('sha256',$key),'duration_seconds'=>1,'aspect_ratio'=>'16:9','prompt'=>'Synthetic test only','request_json'=>'{}',
        ]);
        $jobs->markGenerationSucceeded($jobId,$key,'',1,[]);
    }

    $projectClips = array_values(array_filter(
        $studioRepository->library($userId)['generatedClips'],
        static fn(array $clip): bool => (int)$clip['projectId'] === $projectId
    ));
    TestHarness::assertSame(2, count($projectClips), 'the video library keeps every generated project clip');
    TestHarness::assertTrue(
        count(array_filter($projectClips, static fn(array $clip): bool => (int)$clip['generationVersion'] === 1)) === 2,
        'the video library numbers each scene generation version'
    );

    $timeline = $jobs->exportTimeline($userId,$projectId);
    TestHarness::assertSame(2,count($timeline),'the export snapshot contains each generated scene');
    $exportId = $jobs->createExport([
        'user_id'=>$userId,'project_id'=>$projectId,'aspect_ratio'=>'16:9',
        'snapshot'=>['kind'=>'preview','projectVersion'=>$version,'createdAt'=>date('c'),'scenes'=>$timeline],
    ]);
    $exportService = new VideoExportService($studioRepository,$jobs,new VideoTaskDispatcher(),new VideoExportBuilder(new VideoMediaStorage()));
    $result = $exportService->process($exportId);
    TestHarness::assertSame('succeeded',$result['status'],'FFmpeg produces the MP4 montage');
    $export = $jobs->findExport($exportId);
    TestHarness::assertTrue((int)$export['bytes'] > 1024,'the export records its real byte size');
    TestHarness::assertTrue((float)$export['duration_seconds'] > 1.5,'the montage contains both scene clips');
    $objectKeys[] = (string)$export['output_path'];

    $deleted = $studio->deleteProject($userId,$projectId,$version);
    TestHarness::assertTrue(
        count(array_filter($deleted['projects'], static fn(array $project): bool => (int)$project['id'] === $projectId)) === 0,
        'deleting a project removes it from the active workspace'
    );
    TestHarness::assertSame(null,$studioRepository->findProject($userId,$projectId),'a deleted project cannot be reopened');
    $preservedClips = array_values(array_filter(
        $studioRepository->library($userId)['generatedClips'],
        static fn(array $clip): bool => (int)$clip['projectId'] === $projectId
    ));
    TestHarness::assertSame(2,count($preservedClips),'deleting a project preserves its generated videos in the library');
} finally {
    if ($projectId > 0) $pdo->prepare('DELETE FROM video_projects WHERE id=? AND user_id=?')->execute([$projectId,$userId]);
    foreach (array_filter($uploadedReferenceAssetIds) as $assetId) {
        $pdo->prepare('DELETE FROM video_reference_assets WHERE id=? AND user_id=?')->execute([$assetId,$userId]);
    }
    foreach ($objectKeys as $key) StorageService::delete($key);
    foreach (glob($working . DIRECTORY_SEPARATOR . '*') ?: [] as $file) if (is_file($file)) @unlink($file);
    @rmdir($working);
}

TestHarness::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM video_projects WHERE title LIKE 'Video Export Integration %'")->fetchColumn(),'export test data is removed');
exit(TestHarness::summary());
