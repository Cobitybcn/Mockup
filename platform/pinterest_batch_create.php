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
        (string)($_SESSION['pinterest_batch_create_csrf'] ?? ''),
        (string)($_POST['csrf'] ?? '')
    )) {
        throw new RuntimeException('The batch selection expired. Reload the page.');
    }

    $pdo = Database::connection();
    $purpose = (string)($_POST['purpose'] ?? 'artist');
    $destinationUrl = trim((string)($_POST['destination_url'] ?? ''));
    if (!filter_var($destinationUrl, FILTER_VALIDATE_URL)
        || strtolower((string)parse_url($destinationUrl, PHP_URL_SCHEME)) !== 'https') {
        throw new InvalidArgumentException('Enter a public HTTPS destination.');
    }
    $drafts = new MockupPinterestDraftService($pdo);
    $batches = new PinterestBatchService($pdo, $drafts);
    $bridge = new SocialCampaignPinterestBridge($pdo);
    $mockupIds = (array)($_POST['mockup_ids'] ?? []);

    if ($campaignId > 0) {
        $prepared = $bridge->preparation($campaignId, $userId);
        $mockupIds = $prepared['mockup_ids'];

        if ($prepared['batch_id'] > 0) {
            try {
                $batches->batch($prepared['batch_id'], $userId);
                header('Location: pinterest_batch_review.php?id=' . $prepared['batch_id']);
                exit;
            } catch (RuntimeException) {
                // The linked batch was removed; prepare a replacement below.
            }
        }
    }

    // Fetching boards first proves that the selected Pinterest identity is connected
    // before any local drafts are created.
    $pinterest = new PinterestIntegrationService($pdo);
    $boards = $pinterest->boards($userId, $purpose);
    $batchId = $batches->create($mockupIds, $user, $purpose, $destinationUrl);

    foreach ($batches->items($batchId, $userId) as $item) {
        $publishedBoards = $batches->publishedBoardIds($userId, (int)$item['mockup_id'], $purpose);
        $availableBoards = array_values(array_filter(
            $boards,
            static fn (array $board): bool => !isset($publishedBoards[(string)($board['id'] ?? '')])
        ));
        $recommended = $drafts->recommendBoard($item, $availableBoards);
        if ($recommended) {
            $batches->selectBoards($item, $userId, [(string)$recommended['id']], $boards);
        }
    }

    if ($campaignId > 0) {
        $bridge->attachBatch($campaignId, $userId, $batchId, $purpose, $destinationUrl);
        $_SESSION['social_campaign_notice'] = 'Pinterest batch prepared. Review it before publishing.';
    }

    header('Location: pinterest_batch_review.php?id=' . $batchId);
    exit;
} catch (Throwable $e) {
    if ($campaignId > 0) {
        $_SESSION['social_campaign_error'] = $e->getMessage();
    } else {
        $_SESSION['pinterest_batch_error'] = $e->getMessage();
    }
    header('Location: ' . $errorLocation);
    exit;
}
