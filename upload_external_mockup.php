<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Services/ExternalMockupUploadService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function external_mockup_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $user = Auth::user();
    if (!$user) {
        external_mockup_json(401, ['ok' => false, 'error' => 'Tu sesión venció. Vuelve a iniciar sesión.']);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        external_mockup_json(405, ['ok' => false, 'error' => 'Método no permitido.']);
    }

    $expectedCsrf = (string)($_SESSION['external_mockup_upload_csrf'] ?? '');
    $providedCsrf = (string)($_POST['csrf'] ?? '');
    if ($expectedCsrf === '' || $providedCsrf === '' || !hash_equals($expectedCsrf, $providedCsrf)) {
        external_mockup_json(403, ['ok' => false, 'error' => 'La sesión de carga venció. Recarga la página.']);
    }

    $service = new ExternalMockupUploadService(Database::connection());
    $result = $service->upload(
        (int)$user['id'],
        max(0, (int)($_POST['artwork_id'] ?? 0)),
        (array)($_FILES['mockup'] ?? []),
        [
            'batch_id' => (string)($_POST['batch_id'] ?? ''),
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'relative_path' => (string)($_POST['relative_path'] ?? ''),
        ]
    );

    external_mockup_json(201, ['ok' => true, 'mockup' => $result]);
} catch (DomainException $e) {
    external_mockup_json(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    Logger::log('External mockup upload failed: ' . $e->getMessage(), 'error');
    external_mockup_json(500, [
        'ok' => false,
        'error' => 'No se pudo guardar el mockup. Inténtalo otra vez.',
    ]);
}
