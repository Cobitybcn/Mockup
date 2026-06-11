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

$path = '';
if (str_ends_with($file, '.analysis.json')) {
    $path = ANALYSIS_DIR . DIRECTORY_SEPARATOR . $file;
} elseif (str_starts_with($file, 'mockup_prompt_') && str_ends_with($file, '.txt')) {
    $path = PROMPTS_DIR . DIRECTORY_SEPARATOR . $file;
} else {
    $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
}

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
    $downloadName = $file;
    if (str_starts_with($file, 'mockup_')) {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT artwork_file, context_id FROM mockups WHERE mockup_file = :file LIMIT 1');
            $stmt->execute(['file' => $file]);
            $mInfo = $stmt->fetch();
            if ($mInfo) {
                $contextTitle = Display::contextTitle($mInfo['context_id']);
                $cleanContext = strtolower(trim((string)preg_replace('/[^A-Za-z0-9-]+/', '-', $contextTitle), '-'));
                
                $cleanArtwork = strtolower(trim((string)preg_replace('/[^A-Za-z0-9-]+/', '-', pathinfo((string)$mInfo['artwork_file'], PATHINFO_FILENAME)), '-'));
                $cleanArtwork = (string)preg_replace('/^(base_artwork_mock_|base_artwork_ai_|base_artwork_gemini_|base_artwork_)/', '', $cleanArtwork);
                
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                $downloadName = "mockup_{$cleanArtwork}_{$cleanContext}.{$ext}";
            }
        } catch (Throwable $e) {
            // Fallback to default filename on error
        }
    }
    header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
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
