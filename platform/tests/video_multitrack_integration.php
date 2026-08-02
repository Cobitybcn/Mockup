<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Video/bootstrap.php';
require_once __DIR__ . '/TestHarness.php';
require_once __DIR__ . '/VideoIntegrationFixture.php';

TestHarness::group('Three-track video editor');
if (!VideoFfmpeg::available()) {
    TestHarness::assertTrue(false, 'FFmpeg is available');
    exit(TestHarness::summary());
}

$pdo = Database::connection();
$fixture = createVideoIntegrationFixture($pdo);
$userId = (int)$fixture['user_id'];
$repository = new VideoStudioRepository($pdo);
$studio = new VideoStudioService($repository);
$jobs = new VideoJobRepository($pdo);
$working = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'video-multitrack-' . bin2hex(random_bytes(5));
VideoFfmpeg::ensureDirectory($working);
$keys = [];
$projectId = 0;

try {
    $payload = $studio->createProject($userId, ['title' => 'Multitrack test', 'aspectRatio' => '16:9', 'artworkId' => (int)$fixture['artwork_id']]);
    $projectId = (int)$payload['project']['id'];
    $source = $working . DIRECTORY_SEPARATOR . 'source.mp4';
    VideoFfmpeg::run([VideoFfmpeg::binary(), '-y', '-f', 'lavfi', '-i', 'color=c=#7a554b:s=640x360:r=30:d=2',
        '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=2', '-shortest', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', $source]);
    TestHarness::assertTrue(VideoFfmpeg::hasAudio($source), 'an imported video exposes its embedded audio');
    $key = sprintf('video/test/%d/%d/multitrack.mp4', $userId, $projectId);
    StorageService::uploadFile($key, $source); $keys[] = $key;
    $assetId = $repository->createReferenceAsset($userId, [
        'filePath'=>$key, 'originalName'=>'multitrack.mp4', 'mimeType'=>'video/mp4', 'mediaType'=>'video',
        'byteSize'=>filesize($source), 'durationSeconds'=>2.0, 'hasAudio'=>true,
    ]);
    $version = (int)$repository->findProject($userId, $projectId)['version'];
    $timeline = (new VideoTimelineService($repository, $jobs))->update($userId, $projectId, $version, [
        'videoBlocks' => [
            ['id'=>'v1','sourceType'=>'reference_asset','sourceId'=>$assetId,'track'=>1,'timelineStart'=>0,'sourceStart'=>0,'sourceEnd'=>2,'linkGroup'=>'g1'],
            ['id'=>'v2','sourceType'=>'reference_asset','sourceId'=>$assetId,'track'=>2,'timelineStart'=>0.35,'sourceStart'=>0,'sourceEnd'=>1.4],
            ['id'=>'v3','sourceType'=>'reference_asset','sourceId'=>$assetId,'track'=>3,'timelineStart'=>0.75,'sourceStart'=>0,'sourceEnd'=>1.0],
        ],
        'audioBlocks' => [
            ['id'=>'a1','sourceType'=>'reference_asset','sourceId'=>$assetId,'track'=>1,'timelineStart'=>0,'sourceStart'=>0,'sourceEnd'=>2,'volume'=>0.8,'linkGroup'=>'g1'],
        ],
    ]);
    $saved = $timeline['project']['timeline'];
    TestHarness::assertSame([1,2,3], array_map(static fn(array $b): int => (int)$b['track'], $saved['videoBlocks']), 'V1, V2 and V3 persist as equivalent tracks');
    TestHarness::assertSame(1, count($saved['audioBlocks']), 'embedded audio persists as a linked audio block');
    $render = $jobs->renderTimeline($userId, $projectId, $saved);
    TestHarness::assertSame(3, count($render['videoBlocks']), 'the compositor receives all three video tracks');
    TestHarness::assertSame(1, count($render['audioBlocks']), 'the compositor receives the detached audio source');

    $version = (int)$timeline['project']['version'];
    $exportId = $jobs->createExport([
        'user_id'=>$userId, 'project_id'=>$projectId, 'aspect_ratio'=>'16:9',
        'snapshot'=>['kind'=>'final','projectVersion'=>$version,'createdAt'=>date('c'),'scenes'=>$render['videoBlocks'],'multitrack'=>$render,'music'=>null],
    ]);
    $service = new VideoExportService($repository, $jobs, new VideoTaskDispatcher(), new VideoExportBuilder(new VideoMediaStorage()));
    TestHarness::assertSame('succeeded', $service->process($exportId)['status'], 'FFmpeg renders the three-track composition');
    $export = $jobs->findExport($exportId);
    $output = (new VideoMediaStorage())->localObjectPath((string)$export['output_path']);
    TestHarness::assertTrue(abs((float)$export['duration_seconds'] - 2.0) < 0.2, 'the multitrack render matches the timeline duration');
    TestHarness::assertTrue(VideoFfmpeg::hasAudio($output), 'the linked imported audio reaches the final render once');
    $keys[] = (string)$export['output_path'];
} finally {
    if ($projectId > 0) $pdo->prepare('DELETE FROM video_projects WHERE id=? AND user_id=?')->execute([$projectId,$userId]);
    foreach ($keys as $key) StorageService::delete($key);
    foreach (glob($working . DIRECTORY_SEPARATOR . '*') ?: [] as $file) if (is_file($file)) @unlink($file);
    @rmdir($working);
    removeVideoIntegrationFixture($pdo, $fixture);
}

exit(TestHarness::summary());
