<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

try {
    $user = Auth::requireUser();
    $userId = (int)$user['id'];
    $service = new PinterestIntegrationService(Database::connection());
    $connection = $service->connection($userId, 'artist');

    if (!is_array($connection) || (string)($connection['status'] ?? '') !== 'connected') {
        echo json_encode([
            'ok' => false,
            'boards' => [],
            'error' => 'Conecta Pinterest para cargar tus tableros.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $boards = [];
    foreach ($service->boards($userId, 'artist') as $board) {
        $id = trim((string)($board['id'] ?? ''));
        $name = trim((string)($board['name'] ?? ''));
        if ($id === '' || $name === '') continue;
        $boards[] = ['id' => $id, 'name' => $name];
    }

    echo json_encode([
        'ok' => true,
        'boards' => $boards,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'boards' => [],
        'error' => 'No se pudieron cargar los tableros de Pinterest.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
