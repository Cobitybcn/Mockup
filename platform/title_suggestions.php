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
    Auth::requireValidCsrf((string)($_POST['csrf'] ?? ''), 'bilingual_editorial');
    FeatureAccess::requireJson($user, FeatureAccess::EDITORIAL_MANAGE, 'Title suggestions');
    $userId = (int)$user['id'];
    $service = new TitleSuggestionService(Database::connection());
    $action = trim((string)($_POST['action'] ?? ''));
    $artworkId = max(0, (int)($_POST['artwork_id'] ?? 0));

    if ($action === 'check') {
        // Aviso NO bloqueante para titulos manuales (regla cero: titular es
        // decision del artista; el sistema solo muestra colisiones).
        $collisions = $service->collisionsForTitle($userId, (string)($_POST['title'] ?? ''), $artworkId);
        echo json_encode(['ok' => true, 'collisions' => $collisions], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'suggest') {
        $direction = trim((string)($_POST['direction'] ?? ''));
        $allowed = ['', 'más mínima', 'más oscura', 'más material', 'más formal', 'menos literal', 'más antigua', 'más extraña'];
        if (!in_array($direction, $allowed, true)) $direction = '';
        $suggestions = $service->suggest($userId, $artworkId, $direction);
        echo json_encode(['ok' => true, 'suggestions' => $suggestions, 'pending' => $service->suggestionsForArtwork($userId, $artworkId)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'list') {
        echo json_encode(['ok' => true, 'pending' => $service->suggestionsForArtwork($userId, $artworkId), 'locked' => $service->isLocked($userId, $artworkId)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'shortlist' || $action === 'reject' || $action === 'unshortlist') {
        $service->updateStatus($userId, max(0, (int)($_POST['suggestion_id'] ?? 0)), $action === 'reject' ? 'rejected' : ($action === 'shortlist' ? 'shortlisted' : 'suggested'));
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'confirm') {
        // La UNICA via por la que una sugerencia entra al catalogo: la
        // confirmacion explicita del artista (EDITORIAL_CORE Libro I Cap. 6).
        $title = $service->confirm($userId, $artworkId, max(0, (int)($_POST['suggestion_id'] ?? 0)), (string)($_POST['lock'] ?? '') === '1');
        echo json_encode(['ok' => true, 'title' => $title], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'set_lock') {
        $service->setLock($userId, $artworkId, (string)($_POST['locked'] ?? '') === '1');
        echo json_encode(['ok' => true, 'locked' => $service->isLocked($userId, $artworkId)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    throw new RuntimeException('Acción no válida.');
} catch (Throwable $error) {
    http_response_code($error->getMessage() === 'Method not allowed.' ? 405 : 422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
