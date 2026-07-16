<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Services/SocialPublishJobService.php';
require_once __DIR__ . '/../../app/Services/SocialScheduledPublicationService.php';
require_once __DIR__ . '/../../app/Services/SocialBoardPublishService.php';
require_once __DIR__ . '/../../app/Services/MetaPublisher.php';
require_once __DIR__ . '/../../app/Services/InstagramPublisher.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO users (id) VALUES (23)');
$jobs = new SocialPublishJobService($pdo);
$publishServiceReflection = new ReflectionClass(SocialBoardPublishService::class);
$publishServiceWithoutDependencies = $publishServiceReflection->newInstanceWithoutConstructor();
$scheduledAtMethod = $publishServiceReflection->getMethod('scheduledAt');
$immediateMoment = $scheduledAtMethod->invoke($publishServiceWithoutDependencies, ['mode' => 'now'], new DateTimeZone('America/Argentina/Buenos_Aires'));
if (!$immediateMoment instanceof DateTimeImmutable || abs($immediateMoment->getTimestamp() - time()) > 3) {
    fwrite(STDERR, "FAIL: publish-now still depends on a calendar date.\n");
    exit(1);
}
$key = hash('sha256', 'one-social-publication');
$when = new DateTimeImmutable('+1 hour', new DateTimeZone('UTC'));
$first = $jobs->create(23, 'instagram', 'artist', $when, ['draft_ids' => [11, 12], 'client_key' => 'ig-one'], $key);
$duplicate = $jobs->create(23, 'instagram', 'artist', $when, ['draft_ids' => [99], 'client_key' => 'wrong'], $key);
if ((int)$first['id'] !== (int)$duplicate['id']) {
    fwrite(STDERR, "FAIL: social publication idempotency created a duplicate job.\n");
    exit(1);
}
$claimed = $jobs->claim((int)$first['id']);
if (!$claimed || (string)$claimed['status'] !== 'publishing' || (int)$claimed['attempts'] !== 1) {
    fwrite(STDERR, "FAIL: social publication job could not be claimed.\n");
    exit(1);
}
$jobs->markPublished((int)$first['id'], (string)$claimed['publish_attempt_id'], 'ig-123', 'https://instagram.com/p/test/');
$published = $jobs->job((int)$first['id'], 23);
if ((string)$published['status'] !== 'published' || $jobs->claim((int)$first['id'])['status'] !== 'published') {
    fwrite(STDERR, "FAIL: published social job lost its terminal state.\n");
    exit(1);
}

$deletedTasks = [];
$enqueuedTasks = [];
$manager = new SocialScheduledPublicationService(
    $pdo,
    $jobs,
    static function (int $jobId, DateTimeImmutable $scheduledAt) use (&$enqueuedTasks): string {
        $name = 'projects/test/locations/test/queues/social/tasks/new-' . $jobId . '-' . count($enqueuedTasks);
        $enqueuedTasks[] = ['job_id' => $jobId, 'scheduled_at' => $scheduledAt->format(DateTimeInterface::ATOM), 'name' => $name];
        return $name;
    },
    static function (string $taskName) use (&$deletedTasks): void {
        if ($taskName !== '') $deletedTasks[] = $taskName;
    }
);

$rescheduleWhen = new DateTimeImmutable('+1 day', new DateTimeZone('UTC'));
$rescheduleJob = $jobs->create(23, 'facebook', 'artist', $rescheduleWhen, ['draft_ids' => [21], 'client_key' => 'fb-reschedule'], hash('sha256', 'fb-reschedule'));
$jobs->attachTask((int)$rescheduleJob['id'], 23, 'projects/test/locations/test/queues/social/tasks/old-reschedule');
$newWhen = new DateTimeImmutable('+3 hours', new DateTimeZone('UTC'));
$rescheduled = $manager->reschedule((int)$rescheduleJob['id'], 23, $newWhen->format('Y-m-d'), $newWhen->format('H:i'), 'UTC');
$storedRescheduled = $jobs->job((int)$rescheduleJob['id'], 23);
if ((string)$rescheduled['status'] !== 'queued'
    || !in_array('projects/test/locations/test/queues/social/tasks/old-reschedule', $deletedTasks, true)
    || (string)$storedRescheduled['task_name'] !== (string)$enqueuedTasks[0]['name']
    || substr((string)$storedRescheduled['scheduled_at'], 0, 16) !== $newWhen->format('Y-m-d\TH:i')) {
    fwrite(STDERR, "FAIL: a queued social publication could not be safely rescheduled.\n");
    exit(1);
}

$nowJob = $jobs->create(23, 'instagram', 'artist', new DateTimeImmutable('+1 day', new DateTimeZone('UTC')), ['draft_ids' => [31], 'client_key' => 'ig-now'], hash('sha256', 'ig-now'));
$jobs->attachTask((int)$nowJob['id'], 23, 'projects/test/locations/test/queues/social/tasks/old-now');
$beforeNow = time() - 2;
$publishedNow = $manager->publishNow((int)$nowJob['id'], 23);
$storedNow = $jobs->job((int)$nowJob['id'], 23);
if ((string)$publishedNow['status'] !== 'queued'
    || strtotime((string)$storedNow['scheduled_at']) < $beforeNow
    || strtotime((string)$storedNow['scheduled_at']) > time() + 5) {
    fwrite(STDERR, "FAIL: a queued social publication could not be moved to now.\n");
    exit(1);
}

$cancelJob = $jobs->create(23, 'pinterest', 'platform', new DateTimeImmutable('+1 day', new DateTimeZone('UTC')), ['draft_ids' => [41], 'client_key' => 'pin-cancel'], hash('sha256', 'pin-cancel'));
$jobs->attachTask((int)$cancelJob['id'], 23, 'projects/test/locations/test/queues/social/tasks/old-cancel');
$cancelled = $manager->cancel((int)$cancelJob['id'], 23);
$ownershipBlocked = false;
try {
    $manager->cancel((int)$rescheduleJob['id'], 99);
} catch (RuntimeException) {
    $ownershipBlocked = true;
}
if ((string)$cancelled['status'] !== 'cancelled'
    || !$ownershipBlocked
    || array_filter($manager->pending(23), static fn (array $job): bool => (int)$job['id'] === (int)$cancelJob['id'])) {
    fwrite(STDERR, "FAIL: scheduled publication cancellation or ownership protection failed.\n");
    exit(1);
}

$deleteFailureJob = $jobs->create(23, 'facebook', 'artist', new DateTimeImmutable('+1 day', new DateTimeZone('UTC')), ['draft_ids' => [51], 'client_key' => 'fb-delete-failure'], hash('sha256', 'fb-delete-failure'));
$jobs->attachTask((int)$deleteFailureJob['id'], 23, 'projects/test/locations/test/queues/social/tasks/keep-me');
$deleteFailureManager = new SocialScheduledPublicationService(
    $pdo,
    $jobs,
    static fn (int $jobId, DateTimeImmutable $scheduledAt): string => 'unused',
    static function (string $taskName): void { throw new RuntimeException('delete failed'); }
);
try {
    $deleteFailureManager->cancel((int)$deleteFailureJob['id'], 23);
} catch (RuntimeException) {
}
$restoredAfterDeleteFailure = $jobs->job((int)$deleteFailureJob['id'], 23);
if ((string)$restoredAfterDeleteFailure['status'] !== 'queued'
    || (string)$restoredAfterDeleteFailure['task_name'] !== 'projects/test/locations/test/queues/social/tasks/keep-me') {
    fwrite(STDERR, "FAIL: a Cloud Tasks deletion failure did not preserve the original publication.\n");
    exit(1);
}

$enqueueFailureJob = $jobs->create(23, 'instagram', 'artist', new DateTimeImmutable('+1 day', new DateTimeZone('UTC')), ['draft_ids' => [61], 'client_key' => 'ig-enqueue-failure'], hash('sha256', 'ig-enqueue-failure'));
$jobs->attachTask((int)$enqueueFailureJob['id'], 23, 'projects/test/locations/test/queues/social/tasks/remove-me');
$enqueueFailureManager = new SocialScheduledPublicationService(
    $pdo,
    $jobs,
    static function (int $jobId, DateTimeImmutable $scheduledAt): string { throw new RuntimeException('enqueue failed'); },
    static function (string $taskName): void {}
);
try {
    $enqueueFailureManager->publishNow((int)$enqueueFailureJob['id'], 23);
} catch (RuntimeException) {
}
$storedEnqueueFailure = $jobs->job((int)$enqueueFailureJob['id'], 23);
if ((string)$storedEnqueueFailure['status'] !== 'enqueue_failed' || (string)$storedEnqueueFailure['task_name'] !== '') {
    fwrite(STDERR, "FAIL: an enqueue failure did not leave the publication recoverable.\n");
    exit(1);
}

$retryJob = $jobs->create(23, 'pinterest', 'artist', new DateTimeImmutable('-1 minute', new DateTimeZone('UTC')), ['draft_ids' => [71], 'client_key' => 'pin-retry'], hash('sha256', 'pin-retry'));
$retryClaim = $jobs->claim((int)$retryJob['id']);
$jobs->markFailed((int)$retryJob['id'], (string)$retryClaim['publish_attempt_id'], 'Pinterest rejected the first attempt.');
$retried = $manager->retry((int)$retryJob['id'], 23);
$storedRetry = $jobs->job((int)$retryJob['id'], 23);
if ((string)$retried['status'] !== 'queued'
    || (string)$storedRetry['status'] !== 'queued'
    || trim((string)$storedRetry['task_name']) === ''
    || !array_filter($manager->recent(23), static fn (array $job): bool => (int)$job['id'] === (int)$retryJob['id'])) {
    fwrite(STDERR, "FAIL: a failed social publication could not be retried safely or shown in recent status.\n");
    exit(1);
}

$draft = [
    'title' => 'New work',
    'description' => 'New work in an architectural context.',
    'destination_url' => 'https://www.saatchiart.com/mauriziovalch',
    'hashtags' => '[]',
    'alt_text' => 'Artwork in a quiet room.',
];
$facebook = MetaPublisher::facebookMultiPhotoPayload($draft, ['photo-1', 'photo-2', 'photo-3']);
$instagram = InstagramPublisher::carouselContainerPayload($draft, ['child-1', 'child-2']);
$instagramChild = InstagramPublisher::carouselItemPayload($draft, 'https://artworkmockups.com/media.jpg');
if (count((array)($facebook['attached_media'] ?? [])) !== 3
    || !str_contains((string)($facebook['message'] ?? ''), 'saatchiart.com/mauriziovalch')
    || ($instagram['media_type'] ?? '') !== 'CAROUSEL'
    || ($instagram['children'] ?? '') !== 'child-1,child-2'
    || ($instagramChild['is_carousel_item'] ?? '') !== 'true') {
    fwrite(STDERR, "FAIL: multi-image Meta payloads do not preserve the board publication model.\n");
    exit(1);
}

$boardJs = (string)file_get_contents(__DIR__ . '/../../social_media_board.js');
$worker = (string)file_get_contents(__DIR__ . '/../../social_publish_worker.php');
$workerDockerfile = (string)file_get_contents(__DIR__ . '/../../Dockerfile.worker');
if (!str_contains($boardJs, "fetch('social_media_schedule.php'")
    || !str_contains($boardJs, "fetch('social_media_scheduled_jobs.php'")
    || !str_contains($boardJs, "data-scheduled-action=\"publish_now\"")
    || !str_contains($boardJs, "data-scheduled-action=\"retry\"")
    || !str_contains($boardJs, "mode: 'now'")
    || !str_contains($boardJs, 'data-pin-destination-url')
    || !str_contains($boardJs, 'data-group-link-url')
    || !str_contains($boardJs, 'pinterest_purpose: state.pinterestPurpose')
    || !str_contains($worker, 'publishGroup')
    || !str_contains($workerDockerfile, 'libcurl4-openssl-dev')
    || !preg_match('/docker-php-ext-install\s+curl\b/', $workerDockerfile)) {
    fwrite(STDERR, "FAIL: the board is not connected to the guarded scheduled publisher.\n");
    exit(1);
}

$boardController = (string)file_get_contents(__DIR__ . '/../../social_media_board.php');
$boardService = (string)file_get_contents(__DIR__ . '/../../app/Services/SocialBoardPublishService.php');
$boardsEndpoint = (string)file_get_contents(__DIR__ . '/../../social_media_pinterest_boards.php');
if (!str_contains($boardController, "['platform', 'artist']")
    || !str_contains($boardController, 'data-delivery-mode="now"')
    || !str_contains($boardService, 'pinterestPurpose')
    || !str_contains($boardService, '$drafts->create($mockupId, $user, $purpose')
    || !str_contains($boardsEndpoint, "\$_GET['purpose']")) {
    fwrite(STDERR, "FAIL: Pinterest platform and artist identities are not preserved from board selection to publication.\n");
    exit(1);
}

echo "PASS: social board jobs are idempotent and preserve Pin, Instagram carousel and Facebook multi-photo semantics.\n";
