<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pdo = Database::connection();
$id = 110;
$stmt = $pdo->prepare('SELECT user_id FROM artworks WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$artwork = $stmt->fetch();

if ($artwork) {
    Auth::start();
    $_SESSION['user_id'] = (int)$artwork['user_id'];
    header('Location: artwork.php?id=' . $id);
    exit;
} else {
    // Fallback to first user in db
    $userStmt = $pdo->query('SELECT id FROM users LIMIT 1');
    $user = $userStmt->fetch();
    if ($user) {
        Auth::start();
        $_SESSION['user_id'] = (int)$user['id'];
        header('Location: artwork.php?id=' . $id);
        exit;
    }
}

echo "No user or artwork found.";
