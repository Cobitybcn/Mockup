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
    $userId = (int)$user['id'];
    $service = new BilingualEditorialService(Database::connection());
    if (!$service->isEnabled($userId)) {
        throw new RuntimeException('The bilingual editorial pilot is not enabled for this account.');
    }

    $entityType = trim((string)($_POST['entity_type'] ?? ''));
    $entityId = max(0, (int)($_POST['entity_id'] ?? 0));
    $action = trim((string)($_POST['action'] ?? 'save_content'));

    if ($action === 'save_title') {
        $title = $service->saveUniversalTitle($userId, $entityType, $entityId, (string)($_POST['title'] ?? ''));
        echo json_encode(['ok' => true, 'title' => $title], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'publish_spanish' || $action === 'unpublish_spanish') {
        $result = $service->setSpanishPublished($userId, $entityType, $entityId, $action === 'publish_spanish');
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'adapt_missing' || $action === 'propose_adaptation') {
        $sourceLocale = trim((string)($_POST['source_locale'] ?? ''));
        $targetLocale = trim((string)($_POST['target_locale'] ?? ''));
        $adapter = new BilingualEditorialAdapterService(Database::connection());
        $result = $action === 'adapt_missing'
            ? $adapter->adaptMissing($userId, $entityType, $entityId, $sourceLocale, $targetLocale)
            : $adapter->proposeAdaptation($userId, $entityType, $entityId, $sourceLocale, $targetLocale);
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'generate_spanish_draft') {
        $currentSpanishJson = (string)($_POST['current_content_json'] ?? '{}');
        if (strlen($currentSpanishJson) > 500000) throw new RuntimeException('Editorial content is too large.');
        $currentSpanish = json_decode($currentSpanishJson, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($currentSpanish)) throw new RuntimeException('Invalid current Spanish content.');
        $result = (new BilingualEditorialAdapterService(Database::connection()))->generateSpanishDraft(
            $userId,
            $entityType,
            $entityId,
            $currentSpanish,
            (string)($_POST['private_memo'] ?? '')
        );
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'prepare_bilingual_series') {
        if ($entityType !== 'series') throw new RuntimeException('Esta preparación completa corresponde únicamente a Series.');
        $currentSpanishJson = (string)($_POST['current_content_json'] ?? '{}');
        if (strlen($currentSpanishJson) > 500000) throw new RuntimeException('Editorial content is too large.');
        $currentSpanish = json_decode($currentSpanishJson, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($currentSpanish)) throw new RuntimeException('Invalid current Spanish content.');
        $result = (new BilingualEditorialAdapterService(Database::connection()))->prepareBilingualSeries(
            $userId,
            $entityId,
            $currentSpanish,
            (string)($_POST['private_memo'] ?? '')
        );
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $locale = trim((string)($_POST['locale'] ?? ''));
    if (!in_array($locale, ['es', 'en'], true)) {
        throw new RuntimeException('Idioma editorial no válido.');
    }
    $encoded = (string)($_POST['content_json'] ?? '{}');
    if (strlen($encoded) > 500000) throw new RuntimeException('Editorial content is too large.');
    $content = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($content)) throw new RuntimeException('Invalid editorial content.');
    $result = $service->save($userId, $entityType, $entityId, $locale, $content, (string)($_POST['private_memo'] ?? ''));
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code($error->getMessage() === 'Method not allowed.' ? 405 : 422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
