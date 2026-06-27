<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// 1. Authenticate user
$user = Auth::requireUser();
$pdo = Database::connection();

$artworkId = max(0, (int)($_REQUEST['artwork_id'] ?? 0));
$draftIndex = max(0, (int)($_REQUEST['draft_index'] ?? 0));

if ($artworkId <= 0) {
    http_response_code(404);
    die('Artwork ID is missing or invalid.');
}

if ($draftIndex <= 0) {
    Auth::start();
    $_SESSION['flash_error'] = 'Invalid prompt draft index requested.';
    header("Location: mockup_prompt_drafts_review.php?id={$artworkId}");
    exit;
}

// 2. Fetch artwork and check ownership
$stmt = $pdo->prepare('SELECT id FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
$stmt->execute(['id' => $artworkId, 'user_id' => $user['id']]);
$artwork = $stmt->fetch();

if (!$artwork) {
    http_response_code(404);
    die('Artwork not found or access denied.');
}

// 3. Perform approval via MockupPromptApprovalService
Auth::start();
try {
    require_once __DIR__ . '/app/Services/MockupPromptApprovalService.php';
    $service = new MockupPromptApprovalService();
    $service->approveDrafts($artworkId, [$draftIndex]);
    
    $_SESSION['flash_success'] = "Prompt draft {$draftIndex} approved successfully!";
} catch (InvalidArgumentException $e) {
    $_SESSION['flash_error'] = "Approval Failed: " . $e->getMessage();
} catch (Throwable $e) {
    $_SESSION['flash_error'] = "An unexpected error occurred during approval: " . $e->getMessage();
    Logger::log("MOCKUP_PROMPT_APPROVAL_ERROR for artwork_id {$artworkId}, draft {$draftIndex}. Error: " . $e->getMessage(), 'error');
}

// 4. Redirect back to review screen
header("Location: mockup_prompt_drafts_review.php?id={$artworkId}");
exit;
