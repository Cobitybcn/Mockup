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
    Auth::requireValidCsrf((string)($_POST['csrf'] ?? ''), 'series_keyword_research');
    $userId = (int)$user['id'];
    $seriesId = max(0, (int)($_POST['series_id'] ?? 0));
    $action = trim((string)($_POST['action'] ?? ''));
    $service = new SeriesKeywordResearchService(Database::connection());

    if ($action === 'import') {
        $result = $service->importPlannerExport(
            $userId,
            $seriesId,
            (string)($_POST['locale'] ?? ''),
            (string)($_POST['market'] ?? ''),
            (string)($_POST['planner_export'] ?? '')
        );
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'save_selection') {
        $rawIds = $_POST['selected_ids'] ?? [];
        if (!is_array($rawIds)) $rawIds = [];
        $service->replaceSelection($userId, $seriesId, $rawIds);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    throw new RuntimeException('Acción de investigación no válida.');
} catch (Throwable $error) {
    http_response_code($error->getMessage() === 'Method not allowed.' ? 405 : 422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
