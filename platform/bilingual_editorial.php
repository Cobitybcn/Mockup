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
    FeatureAccess::requireJson($user, FeatureAccess::EDITORIAL_MANAGE, 'Editorial content');
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
    if ($action === 'save_dimensions') {
        if ($entityType !== 'artwork') {
            throw new InvalidArgumentException('Solo una obra tiene medidas fisicas.');
        }
        $saved = ArtworkDimensions::save(Database::connection(), $entityId, $userId, (string)($_POST['dimensions'] ?? ''));
        echo json_encode(['ok' => true, 'dimensions' => $saved['text']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'publish_spanish' || $action === 'unpublish_spanish') {
        $result = $service->setSpanishPublished($userId, $entityType, $entityId, $action === 'publish_spanish');
        // EDITORIAL_CORE Libro VI Cap. 4: publicar la lectura de una obra
        // regenera automaticamente el contenido de sus mockups desde la
        // version nueva, salteando los editados a mano (edicion soberana).
        if ($action === 'publish_spanish' && $entityType === 'artwork') {
            $jobs = new BilingualEditorialJobService(Database::connection());
            $cascade = $jobs->queueMockupCascadeForArtwork($userId, $entityId);
            $jobs->dispatchCascade($userId, $cascade);
            $result += [
                'cascade_queued' => array_map(static fn(array $item): int => (int)$item['mockup_id'], $cascade['queued']),
                'cascade_skipped_manual' => $cascade['skipped_manual'],
            ];
        }
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'generation_status') {
        $jobs = new BilingualEditorialJobService(Database::connection());
        $jobId = max(0, (int)($_POST['job_id'] ?? 0));
        $job = $jobId > 0
            ? $jobs->job($jobId, $userId)
            : $jobs->activeForEntity($userId, $entityType, $entityId);
        if ($job) {
            $job = $jobs->recoverStalledJob($job);
            if ($jobs->needsDispatch($job)) {
                if (CloudTasksService::isAvailable()) {
                    try {
                        $taskName = CloudTasksService::enqueueEditorialGeneration((int)$job['id']);
                        $jobs->attachTask((int)$job['id'], $userId, $taskName);
                    } catch (Throwable $dispatchError) {
                        $jobs->markEnqueueFailed((int)$job['id'], $userId, $dispatchError->getMessage());
                    }
                } else {
                    (new BilingualEditorialGenerationWorker(Database::connection()))->process((int)$job['id']);
                }
                $job = $jobs->job((int)$job['id'], $userId);
            }
        }
        $statusResponse = [
            'ok' => true,
            'job' => $job ? $jobs->publicState($job) : null,
        ];
        // El sondeo activo viaja liviano; la generacion TERMINADA viaja con su
        // contenido, para que una ficha que quedo abierta durante la
        // generacion llene sus campos en vez de decir "guardado" mostrando
        // vacio (el estado invisible confunde — EDITORIAL_CORE Libro VI).
        if ($job && (string)($statusResponse['job']['status'] ?? '') === 'completed' && $entityId > 0) {
            $spanishState = $service->get($userId, $entityType, $entityId, 'es');
            $englishState = $service->get($userId, $entityType, $entityId, 'en');
            $statusResponse += [
                'spanish_content' => (array)($spanishState['content'] ?? []),
                'english_content' => (array)($englishState['content'] ?? []),
                'english_status' => (string)($englishState['status'] ?? 'unprepared'),
                'spanish_published' => (bool)($spanishState['is_published'] ?? false),
            ];
        }
        echo json_encode($statusResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($action === 'enqueue_prepare' || $action === 'enqueue_adaptation') {
        $payload = [];
        if ($action === 'enqueue_prepare') {
            $currentSpanishJson = (string)($_POST['current_content_json'] ?? '{}');
            if (strlen($currentSpanishJson) > 500000) throw new RuntimeException('Editorial content is too large.');
            $currentSpanish = json_decode($currentSpanishJson, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($currentSpanish)) throw new RuntimeException('Invalid current Spanish content.');
            $payload = [
                'current_spanish' => $currentSpanish,
                'private_memo' => (string)($_POST['private_memo'] ?? ''),
            ];
        }

        $jobs = new BilingualEditorialJobService(Database::connection());
        $job = $jobs->createOrReuse(
            $userId,
            $entityType,
            $entityId,
            $action === 'enqueue_prepare' ? 'prepare' : 'adapt',
            $payload
        );

        if ((string)$job['status'] === 'queued' && trim((string)$job['task_name']) === '') {
            if (CloudTasksService::isAvailable()) {
                try {
                    $taskName = CloudTasksService::enqueueEditorialGeneration((int)$job['id']);
                    $jobs->attachTask((int)$job['id'], $userId, $taskName);
                } catch (Throwable $dispatchError) {
                    $jobs->markEnqueueFailed((int)$job['id'], $userId, $dispatchError->getMessage());
                    throw new RuntimeException('No se pudo dejar la generación en segundo plano: ' . $dispatchError->getMessage(), 0, $dispatchError);
                }
            } else {
                (new BilingualEditorialGenerationWorker(Database::connection()))->process((int)$job['id']);
            }
        }

        $job = $jobs->job((int)$job['id'], $userId);
        $spanish = $service->get($userId, $entityType, $entityId, 'es');
        $english = $service->get($userId, $entityType, $entityId, 'en');
        echo json_encode([
            'ok' => true,
            'job' => $jobs->publicState($job),
            'spanish_content' => (array)($spanish['content'] ?? []),
            'english_content' => (array)($english['content'] ?? []),
            'english_status' => (string)($english['status'] ?? 'unprepared'),
            'spanish_published' => (bool)($spanish['is_published'] ?? false),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
    // EDITORIAL_CORE Libro VI Cap. 4: este es el guardado directo del artista
    // desde la ficha — su edicion queda marcada como soberana y la cascada de
    // regeneracion no la pisa.
    $result = $service->save($userId, $entityType, $entityId, $locale, $content, (string)($_POST['private_memo'] ?? ''), true);
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code($error->getMessage() === 'Method not allowed.' ? 405 : 422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
