<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$configuredWorkerUrl = app_env('GCP_WORKER_URL', '');
$configuredWorkerHost = $configuredWorkerUrl !== '' ? (string)(parse_url($configuredWorkerUrl, PHP_URL_HOST) ?: '') : '';
$requestHost = (string)($_SERVER['HTTP_HOST'] ?? '');
if ($configuredWorkerHost !== '' && strcasecmp($configuredWorkerHost, $requestHost) !== 0) {
    http_response_code(404);
    exit;
}

$jobId = 0;
$job = null;
$claimed = null;
$jobService = null;
try {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    $jobId = max(0, (int)($payload['job_id'] ?? 0));
    if ($jobId <= 0) throw new InvalidArgumentException('Missing social publication job ID.');

    $pdo = Database::connection();
    $jobService = new SocialPublishJobService($pdo);
    $job = $jobService->job($jobId);
    if ((string)$job['status'] === 'published') {
        echo json_encode(['ok' => true, 'job_id' => $jobId, 'status' => 'published', 'idempotent' => true]);
        exit;
    }
    $claimed = $jobService->claim($jobId);
    if (!$claimed) {
        echo json_encode(['ok' => true, 'job_id' => $jobId, 'status' => (string)$job['status'], 'claimed' => false]);
        exit;
    }

    $job = $claimed;
    $userId = (int)$job['user_id'];
    $attemptId = (string)$job['publish_attempt_id'];
    $channel = (string)$job['channel'];
    $jobPayload = json_decode((string)$job['payload_json'], true);
    $jobPayload = is_array($jobPayload) ? $jobPayload : [];
    $draftIds = array_values(array_unique(array_filter(array_map('intval', (array)($jobPayload['draft_ids'] ?? [])))));
    if (!$draftIds) throw new RuntimeException('The scheduled publication has no prepared media.');

    $base = rtrim(app_env('APP_PUBLIC_URL', ''), '/');
    if (!str_starts_with(strtolower($base), 'https://')) throw new RuntimeException('APP_PUBLIC_URL must use public HTTPS.');

    if ($channel === 'pinterest') {
        if (app_env('PINTEREST_LIVE_PUBLISH_ENABLED', 'false') !== 'true'
            || app_env('PINTEREST_DRAFT_PUBLIC_MEDIA_ENABLED', 'false') !== 'true') {
            throw new RuntimeException('Pinterest live publication is disabled.');
        }
        $draftService = new MockupPinterestDraftService($pdo);
        $draft = $draftService->draft($draftIds[0], $userId);
        if ((string)$draft['status'] === 'published') {
            $jobService->markPublished($jobId, $attemptId, (string)$draft['external_id'], (string)$draft['external_url']);
            echo json_encode(['ok' => true, 'job_id' => $jobId, 'status' => 'published', 'recovered' => true]);
            exit;
        }
        if (trim((string)$draft['variant_file']) === '' || trim((string)$draft['board_id']) === '') {
            throw new RuntimeException('The Pinterest Pin is not fully prepared.');
        }
        $imageUrl = $base . '/pinterest_draft_media.php?token=' . rawurlencode((string)$draft['media_token']);
        $pinPayload = (new PinterestPublisher())->imagePinPayload(
            ['title' => $draft['title'], 'description' => $draft['description']],
            $draft,
            (string)$draft['board_id'],
            (string)$draft['destination_url'],
            $imageUrl
        );
        $response = (new PinterestIntegrationService($pdo))->createPin($userId, $pinPayload, (string)$draft['purpose']);
        $externalId = trim((string)($response['id'] ?? ''));
        if ($externalId === '') throw new RuntimeException('Pinterest did not return a Pin ID.');
        $externalUrl = trim((string)($response['link'] ?? ''));
        if ($externalUrl === '') $externalUrl = 'https://www.pinterest.com/pin/' . rawurlencode($externalId) . '/';
        $draftService->markPublished((int)$draft['id'], $userId, $externalId, $externalUrl, $response);
        $jobService->markPublished($jobId, $attemptId, $externalId, $externalUrl);
        echo json_encode(['ok' => true, 'job_id' => $jobId, 'status' => 'published']);
        exit;
    }

    if (!in_array($channel, ['instagram', 'facebook'], true)) {
        throw new RuntimeException('Unsupported scheduled publication channel.');
    }
    $flagPrefix = $channel === 'instagram' ? 'INSTAGRAM' : 'META';
    if (app_env($flagPrefix . '_LIVE_PUBLISH_ENABLED', 'false') !== 'true'
        || app_env($flagPrefix . '_DRAFT_PUBLIC_MEDIA_ENABLED', 'false') !== 'true') {
        throw new RuntimeException(ucfirst($channel) . ' live publication is disabled.');
    }

    $draftService = new MetaSocialDraftService($pdo);
    $drafts = [];
    foreach ($draftIds as $draftId) {
        $draft = $draftService->draft($draftId, $userId);
        if ((string)$draft['channel'] !== $channel) throw new RuntimeException('The prepared media channel does not match its job.');
        $drafts[] = $draft;
    }
    $publishedDrafts = array_values(array_filter($drafts, static fn (array $draft): bool => (string)$draft['status'] === 'published'));
    if (count($publishedDrafts) === count($drafts)) {
        $first = $publishedDrafts[0];
        $jobService->markPublished($jobId, $attemptId, (string)$first['external_id'], (string)$first['external_url']);
        echo json_encode(['ok' => true, 'job_id' => $jobId, 'status' => 'published', 'recovered' => true]);
        exit;
    }
    if ($publishedDrafts) throw new RuntimeException('This multi-image publication is only partially recorded. Verify the real account before retrying.');

    $mediaAttempt = hash('sha256', $attemptId);
    $claimedDraftIds = [];
    $freshDrafts = [];
    $imageUrls = [];
    foreach ($drafts as $draft) {
        $draftId = (int)$draft['id'];
        if (!$draftService->claimForPublishing($draftId, $userId, $mediaAttempt)) {
            throw new RuntimeException('One of the prepared images is already being published.');
        }
        $claimedDraftIds[] = $draftId;
        $fresh = $draftService->refreshMediaAccess($draftId, $userId, 7200);
        $freshDrafts[] = $fresh;
        $imageUrls[] = $base . '/meta_draft_media.php?token=' . rawurlencode((string)$fresh['media_token']);
    }

    $result = $channel === 'instagram'
        ? (new InstagramPublisher(new InstagramIntegrationService($pdo)))->publishGroup($freshDrafts, $userId, $imageUrls)
        : (new MetaPublisher(new MetaIntegrationService($pdo)))->publishGroup($freshDrafts, $userId, $imageUrls);
    foreach ($claimedDraftIds as $draftId) {
        $draftService->markPublished(
            $draftId,
            $userId,
            $mediaAttempt,
            (string)$result['id'],
            (string)$result['url'],
            (array)$result['response']
        );
    }
    $jobService->markPublished($jobId, $attemptId, (string)$result['id'], (string)$result['url']);
    echo json_encode(['ok' => true, 'job_id' => $jobId, 'status' => 'published']);
} catch (Throwable $e) {
    $needsVerification = $e instanceof PinterestTransportException
        || $e instanceof MetaGraphTransportException
        || $e instanceof InstagramGraphTransportException
        || str_contains($e->getMessage(), 'partially recorded');
    if ($jobService instanceof SocialPublishJobService && is_array($claimed)) {
        $attemptId = (string)($claimed['publish_attempt_id'] ?? '');
        if ($needsVerification) $jobService->markNeedsVerification($jobId, $attemptId, $e->getMessage());
        else $jobService->markFailed($jobId, $attemptId, $e->getMessage());
        if (isset($draftService, $claimedDraftIds, $mediaAttempt) && $draftService instanceof MetaSocialDraftService) {
            foreach ((array)$claimedDraftIds as $draftId) {
                if ($needsVerification) $draftService->markNeedsVerification((int)$draftId, (int)$claimed['user_id'], (string)$mediaAttempt, $e->getMessage());
                else $draftService->markFailed((int)$draftId, (int)$claimed['user_id'], (string)$mediaAttempt, $e->getMessage());
            }
        }
    }
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'job_id' => $jobId,
        'status' => $needsVerification ? 'needs_verification' : 'failed',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
