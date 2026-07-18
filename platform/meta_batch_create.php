<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::SOCIAL_MANAGE, 'Social Media');
Auth::start();
$userId = (int)$user['id'];
$campaignId = max(0, (int)($_POST['campaign_id'] ?? 0));
$errorLocation = $campaignId > 0
    ? 'social_media_catalog.php?draft=' . $campaignId
    : 'mockups.php';

try {
    if (!hash_equals(
        (string)($_SESSION['meta_batch_create_csrf'] ?? ''),
        (string)($_POST['csrf'] ?? '')
    )) {
        throw new RuntimeException('The Meta batch selection expired. Reload the page.');
    }
    $pdo = Database::connection();
    $purpose = (string)($_POST['purpose'] ?? 'artist');
    $channels = (array)($_POST['meta_channels'] ?? []);
    $destinationUrl = trim((string)($_POST['destination_url'] ?? ''));
    $mockupIds = (array)($_POST['mockup_ids'] ?? []);
    $bridge = new SocialCampaignMetaBridge($pdo);
    $drafts = new MetaSocialDraftService($pdo);
    $batches = new MetaBatchService($pdo, $drafts);

    if ($campaignId > 0) {
        $prepared = $bridge->preparation($campaignId, $userId);
        $mockupIds = $prepared['mockup_ids'];
        $channels = array_values(array_intersect(
            ['facebook', 'instagram'],
            array_unique(array_map(static fn ($value): string => strtolower(trim((string)$value)), $channels))
        ));
        if (!$channels) {
            throw new RuntimeException('Choose Facebook or Instagram for the new publication batch.');
        }
        $unavailable = array_values(array_diff($channels, $prepared['available_destinations']));
        if ($unavailable) {
            throw new RuntimeException(ucfirst(implode(' and ', $unavailable)) . ' already has a publication batch for this campaign.');
        }
    }

    $batchId = $batches->create($mockupIds, $user, $purpose, $channels, $destinationUrl);
    $batchChannels = $batches->channels($batchId, $userId);
    if ($campaignId > 0) {
        $bridge->attachBatch($campaignId, $userId, $batchId, $purpose, $batchChannels);
        $_SESSION['social_campaign_notice'] = 'Meta batch prepared. Review Facebook and Instagram before publishing.';
    }
    header('Location: meta_batch_review.php?id=' . $batchId);
    exit;
} catch (Throwable $e) {
    if ($campaignId > 0) {
        $_SESSION['social_campaign_error'] = $e->getMessage();
    } else {
        $_SESSION['meta_batch_error'] = $e->getMessage();
    }
    header('Location: ' . $errorLocation);
    exit;
}
