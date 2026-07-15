<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = Auth::requireUser();

if (!Auth::isAdmin($user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin only.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$job = basename((string)($_GET['job'] ?? ''));
$image = basename((string)($_GET['image'] ?? ''));

try {
    $pdo = Database::connection();
    $artwork = null;

    if ($job !== '') {
        $stmt = $pdo->prepare('SELECT * FROM artworks WHERE job_id = :job_id AND user_id = :user_id LIMIT 1');
        $stmt->execute([
            'job_id' => $job,
            'user_id' => (int)$user['id'],
        ]);
        $artwork = $stmt->fetch();
    } elseif ($image !== '') {
        $stmt = $pdo->prepare('SELECT * FROM artworks WHERE root_file = :root_file AND user_id = :user_id LIMIT 1');
        $stmt->execute([
            'root_file' => $image,
            'user_id' => (int)$user['id'],
        ]);
        $artwork = $stmt->fetch();
    }

    if (!$artwork) {
        echo json_encode([
            'ok' => true,
            'ready' => false,
            'total' => 0,
            'prompts' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT id, context_name, context_json, prompt
        FROM mockup_contexts
        WHERE artwork_id = :artwork_id
        ORDER BY id ASC
    ');
    $stmt->execute(['artwork_id' => (int)$artwork['id']]);

    $prompts = [];
    foreach ($stmt->fetchAll() as $index => $row) {
        $contextJson = json_decode((string)($row['context_json'] ?? ''), true);
        $contextJson = is_array($contextJson) ? $contextJson : [];
        $prompts[] = [
            'id' => (string)$row['id'],
            'number' => $index + 1,
            'name' => (string)($row['context_name'] ?? 'Context'),
            'purpose' => (string)($contextJson['context_role'] ?? ''),
            'camera' => (string)($contextJson['camera_angle'] ?? ''),
            'time' => (string)($contextJson['time_of_day'] ?? ''),
            'prompt' => (string)($row['prompt'] ?? ''),
        ];
    }

    echo json_encode([
        'ok' => true,
        'ready' => count($prompts) > 0,
        'total' => count($prompts),
        'prompts' => $prompts,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
