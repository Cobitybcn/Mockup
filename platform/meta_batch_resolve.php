<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::SOCIAL_MANAGE, 'Social Media');
Auth::start();
$userId = (int)$user['id'];
$batchId = max(0, (int)($_POST['id'] ?? 0));
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST'
        || !hash_equals((string)($_SESSION['meta_batch_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))
        || trim((string)($_POST['confirmation_text'] ?? '')) !== 'VERIFICADO') {
        throw new RuntimeException('Manual verification requires the confirmation text VERIFICADO.');
    }
    $draftId = max(0, (int)($_POST['draft_id'] ?? 0));
    $pdo = Database::connection();
    $drafts = new MetaSocialDraftService($pdo);
    $batches = new MetaBatchService($pdo, $drafts);
    $items = $batches->items($batchId, $userId);
    if (!in_array($draftId, array_map(static fn (array $item): int => (int)$item['id'], $items), true)) {
        throw new RuntimeException('Draft does not belong to this Meta batch.');
    }
    $drafts->resolveVerification(
        $draftId,
        $userId,
        (string)($_POST['decision'] ?? ''),
        trim((string)($_POST['external_id'] ?? '')),
        trim((string)($_POST['external_url'] ?? ''))
    );
    $status = $batches->updateOutcome($batchId, $userId);
    (new SocialCampaignMetaBridge($pdo))->markBatchOutcome($batchId, $userId, $status);
    $_SESSION['meta_batch_notice'] = 'Manual Meta verification recorded.';
} catch (Throwable $e) {
    $_SESSION['meta_batch_error'] = $e->getMessage();
}
header('Location: meta_batch_review.php?id=' . $batchId);
exit;
