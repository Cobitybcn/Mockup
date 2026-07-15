<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $user = Auth::requireUser();
    $artworkId = max(0, (int)($_POST['artwork_id'] ?? 0));
    $mockupId = max(0, (int)($_POST['mockup_id'] ?? 0));
    $score = max(1, min(5, (int)($_POST['score'] ?? 0)));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $keeper = isset($_POST['keeper']) && (string)$_POST['keeper'] === '1';

    if ($artworkId <= 0 || $mockupId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing artwork_id or mockup_id.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $artworkId]);
    $artwork = $stmt->fetch();
    if (!$artwork || ((int)$artwork['user_id'] !== (int)$user['id'] && !Auth::isAdmin($user))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM mockups WHERE id = :id AND artwork_file = :artwork_file LIMIT 1');
    $stmt->execute(['id' => $mockupId, 'artwork_file' => basename((string)$artwork['root_file'])]);
    $mockup = $stmt->fetch();
    if (!$mockup) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Mockup not found.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dir = __DIR__ . '/analysis/mockup-combination-evaluations';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create evaluations directory.');
    }
    $path = $dir . '/' . $artworkId . '.evaluations.json';
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    if (!is_array($data)) {
        $data = [];
    }
    $data['schema'] = 'mockup_combination_evaluations.v1';
    $data['artwork_id'] = $artworkId;
    $data['updated_at'] = date(DATE_ATOM);
    $data['evaluations'] = is_array($data['evaluations'] ?? null) ? $data['evaluations'] : [];
    $data['evaluations'][(string)$mockupId] = [
        'mockup_id' => $mockupId,
        'score' => $score,
        'keeper' => $keeper,
        'notes' => $notes,
        'mockup_file' => basename((string)$mockup['mockup_file']),
        'updated_by_user_id' => (int)$user['id'],
        'updated_at' => date(DATE_ATOM),
    ];

    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok' => true, 'evaluation_file' => 'analysis/mockup-combination-evaluations/' . $artworkId . '.evaluations.json'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
