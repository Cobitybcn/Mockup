<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();

$db = Database::connection();
$stmt = $db->prepare("
    SELECT id
    FROM artworks
    WHERE user_id = :user_id
    AND status = 'done'
    AND root_file IS NOT NULL
    AND root_file != ''
    ORDER BY updated_at DESC, created_at DESC
    LIMIT 1
");
$stmt->execute(['user_id' => (int)$user['id']]);
$artworkId = $stmt->fetchColumn();

if ($artworkId) {
    header('Location: artwork.php?id=' . urlencode((string)$artworkId));
    exit;
}

header('Location: root_images.php');
exit;
