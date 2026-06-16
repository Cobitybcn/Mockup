<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = Auth::user();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Session expired.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$image = basename(trim((string)($_GET['image'] ?? '')));
if ($image === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing artwork image.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$pdo = Database::connection();
$stmtArtwork = $pdo->prepare('SELECT * FROM artworks WHERE root_file = :root_file AND user_id = :user_id LIMIT 1');
$stmtArtwork->execute([
    'root_file' => $image,
    'user_id' => (int)$currentUser['id'],
]);
$artwork = $stmtArtwork->fetch();

if (!$artwork) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Artwork not found.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$jobs = MockupBatchQueue::rowsForArtwork((int)$artwork['id']);
$counts = ['queued' => 0, 'processing' => 0, 'done' => 0, 'error' => 0];
$payloadJobs = [];

foreach ($jobs as $job) {
    $status = (string)$job['status'];
    if (isset($counts[$status])) {
        $counts[$status]++;
    }

    $mockupFile = basename((string)($job['mockup_file'] ?? ''));
    $promptFile = basename((string)($job['prompt_file'] ?? ''));
    $mockupId = (int)($job['mockup_id'] ?? 0);

    $payloadJobs[] = [
        'id' => (int)$job['id'],
        'context_id' => (string)$job['context_id'],
        'status' => $status,
        'error' => (string)($job['error'] ?? ''),
        'mockup_id' => $mockupId ?: null,
        'image_url' => $mockupFile !== '' ? 'media.php?file=' . rawurlencode($mockupFile) : '',
        'viewer_url' => $mockupId > 0 ? 'viewer.php?id=' . rawurlencode((string)$mockupId) : ($mockupFile !== '' ? 'media.php?file=' . rawurlencode($mockupFile) : ''),
        'download_url' => $mockupFile !== '' ? 'media.php?file=' . rawurlencode($mockupFile) . '&download=1' : '',
        'prompt_url' => $promptFile !== '' ? 'media.php?file=' . rawurlencode($promptFile) : '',
    ];
}

echo json_encode([
    'ok' => true,
    'total' => count($jobs),
    'queued' => $counts['queued'],
    'processing' => $counts['processing'],
    'done' => $counts['done'],
    'error' => $counts['error'],
    'jobs' => $payloadJobs,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
