<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

VideoHttp::requirePost();
$user = Auth::requireUser();
FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Videos');

VideoHttp::handle(static function () use ($user): array {
    VideoHttp::verifyCsrf(['csrf' => (string)($_POST['csrf'] ?? '')]);
    $userId = (int)$user['id'];
    $exportId = (int)($_POST['exportId'] ?? 0);
    $date = (string)($_POST['date'] ?? '');
    $time = (string)($_POST['time'] ?? '');
    $timezone = (string)($_POST['timezone'] ?? 'UTC');
    $caption = (string)($_POST['caption'] ?? '');
    $coverTimestampMs = max(0, (int)($_POST['coverSeconds'] ?? 0)) * 1000;
    $destinationUrl = (string)($_POST['destinationUrl'] ?? '');

    $pdo = Database::connection();
    $board = (new TikTokBoardService($pdo))->assignVideo($userId, $exportId, $date);

    $scheduler = new TikTokPublishScheduler($pdo, new SocialPublishJobService($pdo), new VideoTikTokPublicationService($pdo));
    $when = $scheduler->scheduledAt($date, $time, $timezone);
    $job = $scheduler->schedule($user, $exportId, $caption, $when, (int)$board['id'], $coverTimestampMs, $destinationUrl);

    return ['board' => $board, 'job' => $job];
});
