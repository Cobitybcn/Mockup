<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::MOCKUPS_LAB, 'Mockup Lab');
$file = basename(str_replace('\\', '/', trim((string)($_GET['file'] ?? ''))));

if ($file === '' || preg_match('/^[A-Za-z0-9._-]+$/', $file) !== 1) {
    http_response_code(400);
    exit('Archivo invalido.');
}

$labDir = __DIR__ . '/storage/experiments/mockup-variation-lab';
$path = $labDir . DIRECTORY_SEPARATOR . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

if (!mockup_variation_lab_user_can_access_file((int)$user['id'], Auth::isAdmin($user), $file, $labDir)) {
    http_response_code(403);
    exit('No tienes acceso a este archivo.');
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$mime = @mime_content_type($path) ?: 'application/octet-stream';
if (str_ends_with($file, '.txt')) {
    $mime = 'text/plain; charset=utf-8';
} elseif (str_ends_with($file, '.json')) {
    $mime = 'application/json; charset=utf-8';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=1200');
header('Content-Disposition: inline; filename="' . addslashes($file) . '"');
readfile($path);
exit;

function mockup_variation_lab_user_can_access_file(int $userId, bool $isAdmin, string $file, string $labDir): bool
{
    foreach (glob($labDir . DIRECTORY_SEPARATOR . '*.audit.json') ?: [] as $auditPath) {
        $audit = json_decode((string)file_get_contents($auditPath), true);
        if (!is_array($audit)) {
            continue;
        }

        if (!$isAdmin && (int)($audit['requested_by_user_id'] ?? 0) !== $userId) {
            continue;
        }

        $allowed = [
            basename((string)($audit['output_file'] ?? '')),
            basename((string)($audit['prompt_file'] ?? '')),
            basename((string)$auditPath),
        ];
        if (in_array($file, $allowed, true)) {
            return true;
        }
    }

    return false;
}
