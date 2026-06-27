<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();

$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));

if ($id <= 0) {
    header('Location: dashboard.php');
    exit;
}

// Optional: verify ownership
$db = Database::connection();
$stmt = $db->prepare('SELECT id FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
$stmt->execute(['id' => $id, 'user_id' => $user['id']]);
$artworkExists = $stmt->fetchColumn();

if (!$artworkExists && !Auth::isAdmin($user)) {
    header('Location: dashboard.php');
    exit;
}

header('Location: artwork.php?id=' . urlencode((string)$id));
exit;
