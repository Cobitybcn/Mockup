<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$job = basename((string)($_GET['job'] ?? ''));

header('Content-Type: application/json; charset=utf-8');

if ($job === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta job.']);
    exit;
}

$statusFile = __DIR__ . '/jobs/' . $job . '/status.json';

if (!is_file($statusFile)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Trabajo no encontrado.']);
    exit;
}

$status = json_decode((string)file_get_contents($statusFile), true);

if (!is_array($status)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo leer el estado.']);
    exit;
}

if ((int)($status['user_id'] ?? 0) !== (int)$user['id']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No tienes acceso a este trabajo.']);
    exit;
}

echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
