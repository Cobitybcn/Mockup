<?php
declare(strict_types=1);

ini_set('display_errors', '0');
set_time_limit(1800);
require_once __DIR__ . '/app/Video/bootstrap.php';
VideoTaskDispatcher::authorizeWorkerRequest();
header('Content-Type: application/json; charset=utf-8');

try {
    if (PHP_SAPI === 'cli') {
        $exportId = (int)($argv[1] ?? 0);
        $delay = 0;
        foreach ($argv as $argument) if (str_starts_with((string)$argument, '--delay=')) $delay = (int)substr((string)$argument, 8);
    } else {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        $exportId = (int)($payload['export_id'] ?? 0);
        $delay = 0;
    }
    if ($exportId <= 0) throw new InvalidArgumentException('Missing export ID.');
    if ($delay > 0) sleep(min(3600, $delay));
    $pdo = Database::connection();
    $studio = new VideoStudioRepository($pdo);
    $service = new VideoExportService($studio, new VideoJobRepository($pdo), new VideoTaskDispatcher(), new VideoExportBuilder(new VideoMediaStorage()));
    echo json_encode(['ok' => true] + $service->process($exportId), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    Logger::log('Video export worker failed: ' . $e->getMessage(), 'error');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
