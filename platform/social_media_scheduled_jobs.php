<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

try {
    $user = Auth::requireUser();
    FeatureAccess::requireJson($user, FeatureAccess::SOCIAL_MANAGE, 'Social Media');
    $userId = (int)$user['id'];
    Auth::start();
    $jobs = new SocialPublishJobService(Database::connection());
    $service = new SocialScheduledPublicationService(Database::connection(), $jobs);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        echo json_encode(['ok' => true, 'jobs' => $service->recent($userId)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        throw new RuntimeException('Use GET or POST for scheduled publications.');
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid scheduled publication request.');
    $csrf = (string)($_SESSION['social_media_board_csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string)($input['csrf'] ?? ''))) {
        throw new RuntimeException('The publication session expired. Reload the board and try again.');
    }

    $jobId = max(0, (int)($input['job_id'] ?? 0));
    if ($jobId <= 0) throw new InvalidArgumentException('Choose a scheduled publication.');
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $confirmation = (string)($input['confirmation'] ?? '');
    if ($action === 'reschedule' && $confirmation === 'REPROGRAMAR') {
        $job = $service->reschedule(
            $jobId,
            $userId,
            (string)($input['date'] ?? ''),
            (string)($input['time'] ?? ''),
            (string)($input['timezone'] ?? 'UTC')
        );
        $message = 'La publicación fue reprogramada.';
    } elseif ($action === 'publish_now' && $confirmation === 'PUBLICAR_AHORA') {
        $job = $service->publishNow($jobId, $userId);
        $message = 'La publicación entró en la cola para publicarse ahora.';
    } elseif ($action === 'retry' && $confirmation === 'REINTENTAR') {
        $job = $service->retry($jobId, $userId);
        $message = 'La publicación fallida volvió a la cola sin duplicar las que ya se publicaron.';
    } elseif ($action === 'cancel' && $confirmation === 'CANCELAR') {
        $job = $service->cancel($jobId, $userId);
        $message = 'La publicación programada fue cancelada.';
    } else {
        throw new InvalidArgumentException('Confirm the scheduled publication action.');
    }

    echo json_encode([
        'ok' => true,
        'job' => $job,
        'jobs' => $service->recent($userId),
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $e) {
    if (http_response_code() < 400) http_response_code(409);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Scheduled publication management failed.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
