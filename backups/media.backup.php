<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$file = basename((string)($_GET['file'] ?? ''));
$download = isset($_GET['download']) && $_GET['download'] === '1';

if ($file === '') {
    http_response_code(400);
    exit('Falta archivo.');
}

$path = __DIR__ . '/results/' . $file;

if (!is_file($path)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

if (!user_can_access_result_file((int)$user['id'], $file)) {
    http_response_code(403);
    exit('No tienes acceso a este archivo.');
}

$mime = @mime_content_type($path) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');

if ($download) {
    header('Content-Disposition: attachment; filename="' . addslashes($file) . '"');
} else {
    header('Content-Disposition: inline; filename="' . addslashes($file) . '"');
}

readfile($path);
exit;

function user_can_access_result_file(int $userId, string $file): bool
{
    $pdo = Database::connection();

    $stmt = $pdo->prepare('SELECT 1 FROM artworks WHERE user_id = :user_id AND root_file = :file LIMIT 1');
    $stmt->execute([
        'user_id' => $userId,
        'file' => $file,
    ]);

    if ($stmt->fetchColumn()) {
        return true;
    }

    $stmt = $pdo->prepare('
        SELECT 1
        FROM mockups
        WHERE user_id = :user_id
        AND (mockup_file = :file OR prompt_file = :file)
        LIMIT 1
    ');
    $stmt->execute([
        'user_id' => $userId,
        'file' => $file,
    ]);

    if ($stmt->fetchColumn()) {
        return true;
    }

    if (str_ends_with($file, '.analysis.json')) {
        $rootFile = preg_replace('/\.analysis\.json$/', '.png', $file);
        $stmt = $pdo->prepare('SELECT 1 FROM artworks WHERE user_id = :user_id AND root_file = :file LIMIT 1');
        $stmt->execute([
            'user_id' => $userId,
            'file' => $rootFile,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    return false;
}
