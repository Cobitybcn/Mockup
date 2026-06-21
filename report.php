<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();
$pdo = Database::connection();

$image = trim((string)($_GET['image'] ?? $_POST['image'] ?? ''));
$json = trim((string)($_GET['json'] ?? $_POST['json'] ?? ''));
$id = 0;

if ($image !== '') {
    $rootFile = basename($image);
    $stmt = $pdo->prepare('SELECT id FROM artworks WHERE user_id = :user_id AND root_file = :root_file LIMIT 1');
    $stmt->execute([
        'user_id' => (int)$currentUser['id'],
        'root_file' => $rootFile
    ]);
    $id = (int)$stmt->fetchColumn();
}

if ($id <= 0 && $json !== '') {
    // Attempt finding root image from analysis json path
    $jsonPath = ANALYSIS_DIR . DIRECTORY_SEPARATOR . basename($json);
    if (is_file($jsonPath)) {
        $tmpData = json_decode((string)file_get_contents($jsonPath), true);
        $imageFile = basename((string)($tmpData['image']['file'] ?? ''));
        if ($imageFile !== '') {
            $stmt = $pdo->prepare('SELECT id FROM artworks WHERE user_id = :user_id AND root_file = :root_file LIMIT 1');
            $stmt->execute([
                'user_id' => (int)$currentUser['id'],
                'root_file' => $imageFile
            ]);
            $id = (int)$stmt->fetchColumn();
        }
    }
}

// Fallback: lookup the latest artwork ID for the user
if ($id <= 0) {
    $stmt = $pdo->prepare("SELECT id FROM artworks WHERE user_id = :user_id AND status = 'done' AND root_file IS NOT NULL AND root_file != '' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute(['user_id' => (int)$currentUser['id']]);
    $id = (int)$stmt->fetchColumn();
}

if ($id > 0) {
    $redirectUrl = 'artwork.php?id=' . $id;
    if ($json !== '') {
        $redirectUrl .= '&json=' . urlencode(basename($json));
    }
    // Forward auto parameter if passed
    if (isset($_GET['auto'])) {
        $redirectUrl .= '&auto=' . urlencode((string)$_GET['auto']);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// No artwork found at all, redirect to Step 1
header('Location: artwork_new.php');
exit;
