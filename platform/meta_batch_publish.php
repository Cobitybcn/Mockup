<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::SOCIAL_MANAGE, 'Social Media');
Auth::start();
$userId = (int)$user['id'];
$batchId = max(0, (int)($_POST['id'] ?? 0));
$pdo = Database::connection();
$drafts = new MetaSocialDraftService($pdo);
$batches = new MetaBatchService($pdo, $drafts);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Meta publication requires a confirmed POST request.');
    }
    $csrf = (string)($_SESSION['meta_batch_publish_csrf'] ?? '');
    unset($_SESSION['meta_batch_publish_csrf']);
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))
        || (string)($_POST['confirm'] ?? '') !== 'yes'
        || trim((string)($_POST['confirmation_text'] ?? '')) !== 'PUBLICAR') {
        throw new RuntimeException('Explicit Meta batch confirmation is required.');
    }
    $base = rtrim(app_env('APP_PUBLIC_URL', ''), '/');
    if (!str_starts_with(strtolower($base), 'https://')) {
        throw new RuntimeException('APP_PUBLIC_URL must be a public HTTPS address before Meta can fetch media.');
    }
    $batch = $batches->batch($batchId, $userId);
    $items = $batches->items($batchId, $userId);
    $channels = $batches->channels($batchId, $userId);
    $hasFacebook = in_array('facebook', $channels, true);
    $hasInstagram = in_array('instagram', $channels, true);
    if ($hasFacebook && (app_env('META_LIVE_PUBLISH_ENABLED', 'false') !== 'true'
        || app_env('META_DRAFT_PUBLIC_MEDIA_ENABLED', 'false') !== 'true')) {
        throw new RuntimeException('Live Facebook publication is disabled.');
    }
    if ($hasInstagram && (app_env('INSTAGRAM_LIVE_PUBLISH_ENABLED', 'false') !== 'true'
        || app_env('INSTAGRAM_DRAFT_PUBLIC_MEDIA_ENABLED', 'false') !== 'true')) {
        throw new RuntimeException('Live Instagram publication is disabled.');
    }
    $metaIntegration = new MetaIntegrationService($pdo);
    $instagramIntegration = new InstagramIntegrationService($pdo);
    if ($hasFacebook) {
        $metaIntegration->assertPublishingReady($userId, (string)$batch['purpose'], ['facebook']);
    }
    if ($hasInstagram) {
        $instagramIntegration->publishingContext($userId, (string)$batch['purpose']);
    }
    $facebookPublisher = new MetaPublisher($metaIntegration);
    $instagramPublisher = new InstagramPublisher($instagramIntegration);
    $published = 0;
    $failed = 0;
    $skipped = 0;

    foreach ($items as $item) {
        if ((string)$item['status'] === 'published') {
            $skipped++;
            continue;
        }
        $readiness = $drafts->readiness($item);
        if ($readiness) {
            $skipped++;
            continue;
        }
        $attemptId = bin2hex(random_bytes(24));
        if (!$drafts->claimForPublishing((int)$item['id'], $userId, $attemptId)) {
            $skipped++;
            continue;
        }
        try {
            $claimed = $drafts->refreshMediaAccess((int)$item['id'], $userId, 7200);
            $imageUrl = $base . '/meta_draft_media.php?token=' . rawurlencode((string)$claimed['media_token']);
            $result = (string)$claimed['channel'] === 'instagram'
                ? $instagramPublisher->publishDraft($claimed, $userId, $imageUrl)
                : $facebookPublisher->publishDraft($claimed, $userId, $imageUrl);
            $drafts->markPublished(
                (int)$item['id'],
                $userId,
                $attemptId,
                (string)$result['id'],
                (string)$result['url'],
                (array)$result['response']
            );
            $published++;
        } catch (Throwable $e) {
            if ($e instanceof MetaGraphTransportException || $e instanceof InstagramGraphTransportException) {
                $drafts->markNeedsVerification((int)$item['id'], $userId, $attemptId, $e->getMessage());
            } else {
                $drafts->markFailed((int)$item['id'], $userId, $attemptId, $e->getMessage());
            }
            $failed++;
        }
    }

    $batchStatus = $batches->updateOutcome($batchId, $userId);
    (new SocialCampaignMetaBridge($pdo))->markBatchOutcome($batchId, $userId, $batchStatus);
    $_SESSION['meta_batch_notice'] = $published . ' social posts published; ' . $failed . ' failed; ' . $skipped . ' already published or not ready.';
} catch (Throwable $e) {
    $_SESSION['meta_batch_error'] = $e->getMessage();
}

header('Location: meta_batch_review.php?id=' . $batchId);
exit;
