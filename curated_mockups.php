<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$db = Database::connection();

$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$image = trim((string)($_GET['image'] ?? $_POST['image'] ?? ''));

// If id is missing but image is provided, resolve the artwork id from the database using the root file.
if ($id <= 0 && $image !== '') {
    try {
        $stmt = $db->prepare("SELECT id FROM artworks WHERE root_file = :root_file LIMIT 1");
        $stmt->execute(['root_file' => basename($image)]);
        $id = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}
}

// If image is missing but id exists, try to resolve the root image filename from the artwork record.
if ($id > 0 && $image === '') {
    try {
        $stmt = $db->prepare("SELECT root_file FROM artworks WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $image = (string)$stmt->fetchColumn();
    } catch (Throwable $e) {}
}

// If both id and image cannot be resolved, fail gracefully and redirect to dashboard.
if ($id <= 0 && $image === '') {
    header('Location: root_album.php');
    exit;
}

// Verify artwork ownership
if ($id > 0) {
    try {
        $stmt = $db->prepare('SELECT user_id, root_file FROM artworks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $artwork = $stmt->fetch();

        if (!$artwork) {
            header('Location: root_album.php');
            exit;
        }

        if ((int)$artwork['user_id'] !== (int)$user['id'] && !Auth::isAdmin($user)) {
            header('Location: root_album.php');
            exit;
        }

        if ($image === '' && !empty($artwork['root_file'])) {
            $image = basename((string)$artwork['root_file']);
        }
    } catch (Throwable $e) {}
}

if ($image === '') {
    header('Location: root_album.php');
    exit;
}

// Check whether the artwork has queued mockup batch rows using MockupBatchQueue::rowsForArtwork
$queuedMockups = 0;
if ($id > 0) {
    try {
        $queuedMockups = count(MockupBatchQueue::rowsForArtwork($id));
    } catch (Throwable $e) {}
}

// Check for legacy parameter and admin privilege to bypass redirect
$legacy = ($_GET['legacy'] ?? $_POST['legacy'] ?? '') === '1';
if ($legacy && Auth::isAdmin($user) && defined('LEGACY_MOCKUP_FLOW_ENABLED') && LEGACY_MOCKUP_FLOW_ENABLED) {
    if ($queuedMockups > 0) {
        header('Location: mockup_batch_wait.php?image=' . urlencode(basename($image)));
    } else {
        header('Location: report.php?image=' . urlencode(basename($image)));
    }
} else {
    header('Location: mockup_prompt_drafts_review.php?id=' . (int)$id);
}
exit;
