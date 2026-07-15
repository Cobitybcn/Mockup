<?php
declare(strict_types=1);

// Ensure PHP warnings/notices never leak into the image stream.
ini_set('display_errors', '0');

require_once __DIR__ . '/app/bootstrap.php';

// Require logged-in user
$user = Auth::requireUser();
$pdo  = Database::connection();

$mockupId = isset($_GET['mockup_id']) ? (int)$_GET['mockup_id'] : 0;
$filename = isset($_GET['file']) ? basename((string)$_GET['file']) : (isset($_GET['filename']) ? basename((string)$_GET['filename']) : '');

$mockup = null;

if ($mockupId > 0) {
    if (Auth::isAdmin($user)) {
        $stmt = $pdo->prepare('SELECT * FROM mockups WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $mockupId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM mockups WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute(['id' => $mockupId, 'user_id' => $user['id']]);
    }
    $mockup = $stmt->fetch();
} elseif ($filename !== '') {
    if (Auth::isAdmin($user)) {
        $stmt = $pdo->prepare('SELECT * FROM mockups WHERE mockup_file = :filename LIMIT 1');
        $stmt->execute(['filename' => $filename]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM mockups WHERE mockup_file = :filename AND user_id = :user_id LIMIT 1');
        $stmt->execute(['filename' => $filename, 'user_id' => $user['id']]);
    }
    $mockup = $stmt->fetch();
}

if (!$mockup) {
    http_response_code(404);
    exit('Mockup not found or access denied.');
}

$mockupFile = basename((string)$mockup['mockup_file']);
if ($mockupFile === '') {
    http_response_code(400);
    exit('Invalid mockup file.');
}

// Security: Prevent path traversal and enforce RESULTS_DIR constraint
$resultsDir = realpath(RESULTS_DIR);
if ($resultsDir === false) {
    http_response_code(500);
    exit('Results directory not configured.');
}

$filePath = $resultsDir . DIRECTORY_SEPARATOR . $mockupFile;
$realFilePath = realpath($filePath);

if ($realFilePath === false || !is_file($realFilePath)) {
    http_response_code(404);
    exit('Mockup file not found on disk.');
}

// Ensure path is strictly inside RESULTS_DIR
if (!str_starts_with($realFilePath, $resultsDir . DIRECTORY_SEPARATOR)) {
    http_response_code(403);
    exit('Path traversal detected.');
}

// Validate MIME type (strictly image/jpeg or image/png)
$mime = @mime_content_type($realFilePath);
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(403);
    exit('Forbidden file type.');
}

// Close session to release session lock during file transfer
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realFilePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="' . addslashes($mockupFile) . '"');

readfile($realFilePath);
exit;
