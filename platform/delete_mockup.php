<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = Auth::user();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized. Please sign in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$mockupId = (int)($_POST['mockup_id'] ?? 0);
if ($mockupId <= 0) {
    $rawInput = file_get_contents('php://input');
    if ($rawInput) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $mockupId = (int)($decoded['mockup_id'] ?? 0);
        }
    }
}

if ($mockupId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ID de mockup no especificado.']);
    exit;
}

$pdo = Database::connection();
$stmt = $pdo->prepare('SELECT * FROM mockups WHERE id = :id AND user_id = :user_id LIMIT 1');
$stmt->execute([
    'id' => $mockupId,
    'user_id' => (int)$currentUser['id'],
]);
$mockup = $stmt->fetch();

if (!$mockup) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Mockup no encontrado.']);
    exit;
}

$resultsDir = RESULTS_DIR;
if (!empty($mockup['mockup_file'])) {
    if (StorageService::isGcsActive()) {
        StorageService::delete('results/' . basename((string)$mockup['mockup_file']));
    }
    $mPath = $resultsDir . DIRECTORY_SEPARATOR . basename((string)$mockup['mockup_file']);
    if (is_file($mPath)) {
        @unlink($mPath);
    }
}

if (!empty($mockup['prompt_file'])) {
    if (StorageService::isGcsActive()) {
        StorageService::delete('mockup-prompts/' . basename((string)$mockup['prompt_file']));
    }
    $pPath = $resultsDir . DIRECTORY_SEPARATOR . basename((string)$mockup['prompt_file']);
    if (is_file($pPath)) {
        @unlink($pPath);
    }
}

$deleteStmt = $pdo->prepare('DELETE FROM mockups WHERE id = :id');
$deleteStmt->execute(['id' => $mockupId]);

$deleteJobStmt = $pdo->prepare('DELETE FROM mockup_generation_jobs WHERE mockup_id = :mockup_id');
$deleteJobStmt->execute(['mockup_id' => $mockupId]);

echo json_encode(['ok' => true]);
exit;
