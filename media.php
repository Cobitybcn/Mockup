<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$file = str_replace('\\', '/', trim((string)($_GET['file'] ?? '')));
$file = ltrim($file, '/');
$isSocialVideo = preg_match('#^social-video/[A-Za-z0-9._-]+\.mp4$#', $file) === 1;
if (!$isSocialVideo) {
    $file = basename($file);
}
$download = isset($_GET['download']) && $_GET['download'] === '1';

if ($file === '') {
    http_response_code(400);
    exit('Falta archivo.');
}

$path = '';
if (str_ends_with($file, '.analysis.json')) {
    $path = ANALYSIS_DIR . DIRECTORY_SEPARATOR . $file;
} elseif (str_ends_with($file, '.txt') && is_file(PROMPTS_DIR . DIRECTORY_SEPARATOR . $file)) {
    $path = PROMPTS_DIR . DIRECTORY_SEPARATOR . $file;
} else {
    $path = RESULTS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
}

if (!Auth::isAdmin($user) && !user_can_access_result_file((int)$user['id'], $file)) {
    http_response_code(403);
    exit('No tienes acceso a este archivo.');
}

if (!is_file($path)) {
    if (StorageService::isGcsActive()) {
        $gcsKey = '';
        if (str_ends_with($file, '.analysis.json')) {
            $gcsKey = 'analysis/' . $file;
        } elseif (str_ends_with($file, '.txt')) {
            $gcsKey = 'mockup-prompts/' . $file;
        } else {
            $gcsKey = 'results/' . $file;
        }
        
        $signedUrl = StorageService::getSignedUrl($gcsKey, 5);
        if ($signedUrl) {
            header('Location: ' . $signedUrl);
            exit;
        }
    }
    http_response_code(404);
    exit('File not found.');
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$mime = @mime_content_type($path) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');

if ($download) {
    $downloadName = $file;
    if (isset($_GET['name']) && trim((string)$_GET['name']) !== '') {
        $downloadName = trim((string)$_GET['name']);
        $originalExt = pathinfo($file, PATHINFO_EXTENSION);
        $reqExt = pathinfo($downloadName, PATHINFO_EXTENSION);
        if (strtolower($originalExt) !== strtolower($reqExt)) {
            $downloadName .= '.' . $originalExt;
        }
    } elseif (str_starts_with($file, 'mockup_')) {
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

    if (preg_match('#^social-video/[A-Za-z0-9._-]+\.mp4$#', $file) === 1) {
        $stmt = $pdo->prepare('SELECT 1 FROM social_video_workflows WHERE user_id = :user_id AND video_url = :video_url LIMIT 1');
        $stmt->execute(['user_id' => $userId, 'video_url' => $file]);
        return (bool)$stmt->fetchColumn();
    }

    $file = basename($file);

    if (user_can_access_exact_result_file($pdo, $userId, $file)) {
        return true;
    }

    $canonicalFile = canonical_generated_file($file);
    if ($canonicalFile !== $file && user_can_access_exact_result_file($pdo, $userId, $canonicalFile)) {
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

function user_can_access_exact_result_file(PDO $pdo, int $userId, string $file): bool
{
    if (preg_match('/_(job_\d+_\d+)_v\d+\./', $file, $matches)) {
        $jobId = $matches[1];
        $stmt = $pdo->prepare('SELECT 1 FROM artworks WHERE user_id = :user_id AND job_id = :job_id LIMIT 1');
        $stmt->execute([
            'user_id' => $userId,
            'job_id' => $jobId
        ]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    $stmt = $pdo->prepare('SELECT 1 FROM artworks WHERE user_id = :user_id AND root_file = :file LIMIT 1');
    $stmt->execute([
        'user_id' => $userId,
        'file' => $file,
    ]);

    if ($stmt->fetchColumn()) {
        return true;
    }

    $rootVersionPrefix = root_version_prefix($file);
    if ($rootVersionPrefix !== '') {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM artworks
            WHERE user_id = :user_id
            AND root_file LIKE :root_pattern
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'root_pattern' => $rootVersionPrefix . '_v%',
        ]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM mockups
            WHERE user_id = :user_id
            AND artwork_file LIKE :root_pattern
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'root_pattern' => $rootVersionPrefix . '_v%',
        ]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    $stmt = $pdo->prepare('
        SELECT 1
        FROM mockups
        WHERE user_id = :user_id
        AND artwork_file = :file
        LIMIT 1
    ');
    $stmt->execute([
        'user_id' => $userId,
        'file' => $file,
    ]);

    if ($stmt->fetchColumn()) {
        return true;
    }

    $stmt = $pdo->prepare('
        SELECT 1
        FROM root_artwork_candidates rac
        INNER JOIN artworks a ON a.id = rac.artwork_id
        WHERE a.user_id = :user_id
        AND rac.file_name = :file
        LIMIT 1
    ');
    $stmt->execute([
        'user_id' => $userId,
        'file' => $file,
    ]);

    if ($stmt->fetchColumn()) {
        return true;
    }

    $fileColumn = str_ends_with($file, '.txt') ? 'prompt_file' : 'mockup_file';

    $stmt = $pdo->prepare("
        SELECT 1
        FROM mockups
        WHERE user_id = :user_id
        AND {$fileColumn} = :file
        LIMIT 1
    ");
    $stmt->execute([
        'user_id' => $userId,
        'file' => $file,
    ]);

    if ($stmt->fetchColumn()) {
        return true;
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM mockup_generation_jobs
        WHERE user_id = :user_id
        AND {$fileColumn} = :file
        LIMIT 1
    ");
    $stmt->execute([
        'user_id' => $userId,
        'file' => $file,
    ]);

    if ($stmt->fetchColumn()) {
        return true;
    }

    // Mockups anexados a una ficha del usuario (pueden no existir en mockups/jobs,
    // p. ej. archivos adoptados directamente desde results/).
    if ($fileColumn === 'mockup_file') {
        $stmt = $pdo->prepare('SELECT 1 FROM mockup_sheets WHERE user_id = :user_id AND mockup_file = :file LIMIT 1');
        $stmt->execute([
            'user_id' => $userId,
            'file' => $file,
        ]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    return false;
}

function canonical_generated_file(string $file): string
{
    return (string)preg_replace('/\.original(?=\.[^.]+$)/', '', $file, 1);
}

function root_version_prefix(string $file): string
{
    if (preg_match('/^(.*)_v\d+\.[A-Za-z0-9]+$/', basename($file), $matches)) {
        return (string)$matches[1];
    }

    return '';
}
