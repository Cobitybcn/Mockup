<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function assistant_respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

try {
    $user = Auth::requireUser();
    $config = new AssistantConfig();
    if (!$config->enabledFor($user)) {
        assistant_respond(['ok' => false, 'error' => 'assistant_disabled'], 404);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        assistant_respond(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2500000) {
        throw new AssistantException('La solicitud es demasiado grande.', 'request_too_large');
    }
    $request = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($request)) {
        throw new AssistantException('The request is not valid.', 'invalid_json');
    }
    Auth::start();
    $expectedCsrf = (string)($_SESSION['assistant_csrf'] ?? '');
    $providedCsrf = (string)($request['csrf'] ?? '');
    if ($expectedCsrf === '' || $providedCsrf === '' || !hash_equals($expectedCsrf, $providedCsrf)) {
        throw new AssistantException('The session expired or is not valid. Reload the page.', 'invalid_csrf');
    }
    session_write_close();
    $pdo = Database::connection();
    $repository = new AssistantRepository($pdo);
    $service = new AssistantService($config, $repository, new AssistantContext($pdo), new AssistantOpenAIClient($config));
    $action = (string)($request['action'] ?? 'chat');
    $result = match ($action) {
        'chat' => $service->chat($user, $request),
        'history' => $service->history($user, $request),
        'conversations' => $service->conversations($user),
        'workspace' => $service->workspace($user),
        'new_conversation' => $service->newConversation($user, $request),
        default => throw new AssistantException('The requested operation is not allowed.', 'invalid_operation'),
    };
    assistant_respond(['ok' => true, 'result' => $result]);
} catch (AssistantException $exception) {
    $code = $exception->publicCode();
    $status = match (true) {
        $code === 'assistant_disabled' => 404,
        $code === 'invalid_csrf' => 403,
        str_starts_with($code, 'rate_limit_') || $code === 'gemini_rate_limit' => 429,
        str_starts_with($code, 'openai_') || str_starts_with($code, 'gemini_') => 502,
        default => 400,
    };
    assistant_respond(['ok' => false, 'error' => $code, 'message' => $exception->getMessage()], $status);
} catch (Throwable $exception) {
    Logger::log('Assistant failure: ' . get_class($exception), 'error');
    assistant_respond(['ok' => false, 'error' => 'assistant_internal', 'message' => 'The assistant encountered an internal error.'], 500);
}
