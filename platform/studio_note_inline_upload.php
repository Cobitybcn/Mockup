<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Method not allowed.');
    }
    $user = Auth::requireUser();
    FeatureAccess::requirePage($user, FeatureAccess::WEBSITE_MANAGE, 'Studio Notes');
    if (session_status() === PHP_SESSION_NONE) session_start();
    $expectedCsrf = (string)($_SESSION['studio_notes_csrf'] ?? '');
    if ($expectedCsrf === ''
        || !hash_equals($expectedCsrf, (string)($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('Invalid session token.');
    }

    $noteId = max(0, (int)($_POST['note_id'] ?? 0));
    $upload = (array)($_FILES['image'] ?? []);
    if ($noteId <= 0 || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Elegí una imagen válida.');
    }
    $temporaryFile = (string)($upload['tmp_name'] ?? '');
    if ($temporaryFile === '' || !is_uploaded_file($temporaryFile)) {
        throw new RuntimeException('La carga de imagen no es válida.');
    }
    $bytes = file_get_contents($temporaryFile);
    $info = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
    $mime = is_array($info) ? strtolower((string)($info['mime'] ?? '')) : '';
    if (!is_string($bytes)
        || $bytes === ''
        || strlen($bytes) > 12 * 1024 * 1024
        || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('La imagen debe ser JPEG, PNG o WebP y pesar hasta 12 MB.');
    }

    $dataUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
    $normalizedHtml = (new WebsiteBoardService(Database::connection()))->normalizeNoteBody(
        (int)$user['id'],
        $noteId,
        '<img src="' . $dataUri . '" alt="">'
    );
    if (preg_match('~\bsrc=["\']([^"\']+)~iu', $normalizedHtml, $match) !== 1) {
        throw new RuntimeException('No se pudo crear la URL persistente de la imagen.');
    }

    echo json_encode([
        'ok' => true,
        'url' => html_entity_decode((string)$match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
