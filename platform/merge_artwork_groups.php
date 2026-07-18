<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$user = Auth::user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Session expired.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = (string)($_POST['csrf'] ?? '');
$sessionCsrf = (string)($_SESSION['artwork_merge_csrf'] ?? '');
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'The merge confirmation expired. Reload ArtWorks and try again.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$firstGroupId = max(0, (int)($_POST['first_group_id'] ?? 0));
$secondGroupId = max(0, (int)($_POST['second_group_id'] ?? 0));
$primaryGroupId = max(0, (int)($_POST['primary_group_id'] ?? 0));
$secondaryGroupId = $primaryGroupId === $firstGroupId ? $secondGroupId : $firstGroupId;
if ($firstGroupId <= 0 || $secondGroupId <= 0 || $firstGroupId === $secondGroupId
    || !in_array($primaryGroupId, [$firstGroupId, $secondGroupId], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Choose two different artworks and which one will remain as the principal artwork.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Database::connection();
$service = new ArtworkGroupService($pdo);
$service->syncUser((int)$user['id']);
$stmt = $pdo->prepare('
    SELECT id, canonical_artwork_id, title, status
    FROM artwork_groups
    WHERE user_id = :user_id AND id IN (:first_id, :second_id)
');
$stmt->execute([
    'user_id' => (int)$user['id'],
    'first_id' => $firstGroupId,
    'second_id' => $secondGroupId,
]);
$groups = [];
foreach ($stmt->fetchAll() as $group) {
    $groups[(int)$group['id']] = $group;
}
if (count($groups) !== 2 || (string)$groups[$firstGroupId]['status'] !== 'active' || (string)$groups[$secondGroupId]['status'] !== 'active') {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'One of these artworks was already merged or is no longer available.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service->mergeGroups((int)$user['id'], $primaryGroupId, $secondaryGroupId);
    $service->syncUser((int)$user['id']);
    ArtworkSeries::syncUser($pdo, (int)$user['id']);

    $primary = $groups[$primaryGroupId];
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM mockups WHERE user_id = :user_id AND artwork_group_id = :group_id');
    $countStmt->execute(['user_id' => (int)$user['id'], 'group_id' => $primaryGroupId]);
    echo json_encode([
        'ok' => true,
        'group_id' => $primaryGroupId,
        'canonical_artwork_id' => (int)$primary['canonical_artwork_id'],
        'mockup_count' => (int)$countStmt->fetchColumn(),
        'message' => 'The artworks were merged without deleting roots or mockups.',
        'redirect_url' => 'root_album.php?merged=1&artwork_id=' . (int)$primary['canonical_artwork_id'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    Logger::log('Artwork merge failed for user #' . (int)$user['id'] . ': ' . $e->getMessage(), 'error');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The artworks could not be merged. Nothing was changed.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
