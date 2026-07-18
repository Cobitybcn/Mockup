<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

try {
    $user = Auth::requireUser();
    FeatureAccess::requireJson($user, FeatureAccess::SOCIAL_MANAGE, 'Social Media');
    $userId = (int)$user['id'];
    $purpose = strtolower(trim((string)($_GET['purpose'] ?? 'artist')));
    if (!in_array($purpose, ['artist', 'platform'], true)) {
        throw new InvalidArgumentException('La identidad de Pinterest no es válida.');
    }
    if ($purpose === 'platform' && !Auth::isAdmin($user)) {
        throw new RuntimeException('La cuenta de Artworks Mockups está disponible solo para administradores.');
    }
    $service = new PinterestIntegrationService(Database::connection());
    $connection = $service->connection($userId, $purpose);

    if (!is_array($connection) || (string)($connection['status'] ?? '') !== 'connected') {
        echo json_encode([
            'ok' => false,
            'boards' => [],
            'error' => 'Conecta Pinterest para cargar tus tableros.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $boards = [];
    foreach ($service->boards($userId, $purpose) as $board) {
        $id = trim((string)($board['id'] ?? ''));
        $name = trim((string)($board['name'] ?? ''));
        if ($id === '' || $name === '') continue;
        $boards[] = ['id' => $id, 'name' => $name];
    }

    echo json_encode([
        'ok' => true,
        'purpose' => $purpose,
        'boards' => $boards,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'boards' => [],
        'error' => 'No se pudieron cargar los tableros de Pinterest.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
