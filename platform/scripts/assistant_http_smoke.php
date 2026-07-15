<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$email = strtolower(trim((string)($argv[1] ?? '')));
$url = trim((string)($argv[2] ?? 'http://localhost/artworkmockups/platform/website_board.php'));
if ($email === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
    fwrite(STDERR, "Usage: php scripts/assistant_http_smoke.php user@example.com [website_board_url]\n");
    exit(1);
}

$pdo = Database::connection();
$user = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=? LIMIT 1');
$user->execute([$email]);
$userId = (int)$user->fetchColumn();
if ($userId <= 0) {
    fwrite(STDERR, "The requested smoke-test user does not exist.\n");
    exit(1);
}

$pdo->exec('CREATE TABLE IF NOT EXISTS php_sessions (id VARCHAR(255) NOT NULL PRIMARY KEY,data TEXT NOT NULL,updated_at INT NOT NULL)');
$sessionId = bin2hex(random_bytes(24));
$csrf = bin2hex(random_bytes(32));
$insertSession = $pdo->prepare('REPLACE INTO php_sessions(id,data,updated_at) VALUES(?,?,?)');
$insertSession->execute([$sessionId, 'user_id|i:' . $userId . ';assistant_csrf|s:64:"' . $csrf . '";', time()]);
$cookie = session_name() . '=' . $sessionId;

$request = static function (string $requestUrl, string $cookieValue, ?array $json = null): array {
    $handle = curl_init($requestUrl);
    if ($handle === false) {
        throw new RuntimeException('Could not initialize the HTTP smoke request.');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIE => $cookieValue,
        CURLOPT_HTTPHEADER => ['Accept: application/json,text/html;q=0.9'],
    ];
    if ($json !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_HTTPHEADER] = ['Accept: application/json', 'Content-Type: application/json'];
        $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($body === false || $error !== '') {
        throw new RuntimeException('Local HTTP request failed: ' . $error);
    }
    return ['status' => $status, 'body' => (string)$body];
};

try {
    $page = $request($url, $cookie);
    if ($page['status'] !== 200 || !str_contains($page['body'], 'data-assistant-root') || !str_contains($page['body'], 'faithful-assistant-workspace')) {
        throw new RuntimeException('The authenticated page did not render the assistant. HTTP ' . $page['status']);
    }
    if (!str_contains($page['body'], 'data-assistant-mode="connected"') || !str_contains($page['body'], 'data-assistant-focus') || !str_contains($page['body'], 'Las respuestas y la memoria se guardan en Artwork')) {
        throw new RuntimeException('The page did not render the connected assistant workspace.');
    }
    if (!str_contains($page['body'], 'data-assistant-cropper') || !str_contains($page['body'], 'data-assistant-crop-confirm') || !str_contains($page['body'], 'data-assistant-crop-full')) {
        throw new RuntimeException('The connected assistant did not render the screen crop controls.');
    }
    if (!str_contains($page['body'], 'data-assistant-attach-image') || !str_contains($page['body'], 'data-assistant-image-input') || !str_contains($page['body'], 'Pega una captura con Ctrl+V')) {
        throw new RuntimeException('The connected assistant did not render the clipboard and file attachment controls.');
    }
    if (str_contains($page['body'], 'openai-chatkit')) {
        throw new RuntimeException('The lightweight workspace unexpectedly rendered the ChatKit component.');
    }
    if (!str_contains($page['body'], 'data-endpoint="assistant_api.php"') || !preg_match('/data-csrf="([a-f0-9]{64})"/', $page['body'], $csrfMatch)) {
        throw new RuntimeException('The connected workspace did not expose a valid same-origin session connection.');
    }
    $baseUrl = preg_replace('~/[^/]+$~', '/', $url);
    $api = $request((string)$baseUrl . 'assistant_api.php', $cookie, [
        'action' => 'history',
        'csrf' => $csrfMatch[1],
        'page_context' => ['current_route' => 'website_board.php'],
    ]);
    $decoded = json_decode($api['body'], true);
    if ($api['status'] !== 200 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
        throw new RuntimeException('The assistant history endpoint failed. HTTP ' . $api['status']);
    }
    $workspaceApi = $request((string)$baseUrl . 'assistant_api.php', $cookie, [
        'action' => 'workspace',
        'csrf' => $csrfMatch[1],
    ]);
    $workspaceDecoded = json_decode($workspaceApi['body'], true);
    if ($workspaceApi['status'] !== 200 || !is_array($workspaceDecoded) || ($workspaceDecoded['ok'] ?? false) !== true || !isset($workspaceDecoded['result']['overview'])) {
        throw new RuntimeException('The assistant workspace endpoint failed. HTTP ' . $workspaceApi['status']);
    }
    $assetBodies = [];
    foreach (['assets/assistant.css', 'assets/assistant.js'] as $asset) {
        $assetResponse = $request((string)$baseUrl . $asset, $cookie);
        if ($assetResponse['status'] !== 200 || trim($assetResponse['body']) === '') {
            throw new RuntimeException($asset . ' was not served correctly.');
        }
        $assetBodies[$asset] = $assetResponse['body'];
    }
    if (!str_contains($assetBodies['assets/assistant.js'], 'fetch(') || !str_contains($assetBodies['assets/assistant.js'], 'assistant_api.php')) {
        throw new RuntimeException('The connected workspace JavaScript is not wired to the backend.');
    }
    if (!str_contains($assetBodies['assets/assistant.js'], 'confirmCropSelection') || !str_contains($assetBodies['assets/assistant.js'], 'canvas.toDataURL')) {
        throw new RuntimeException('The screen crop workflow is not wired in the connected workspace JavaScript.');
    }
    if (!str_contains($assetBodies['assets/assistant.js'], "input.addEventListener('paste', pasteImage)") || !str_contains($assetBodies['assets/assistant.js'], 'normalizeImageFile')) {
        throw new RuntimeException('Clipboard image paste and normalization are not wired in the connected workspace JavaScript.');
    }
    echo "ASSISTANT_HTTP_SMOKE_OK\n";
    echo "page=website_board.php status=200\n";
    echo "assistant_ui=connected_workspace\n";
    echo "assistant_backend=same_origin\n";
    echo "csrf=accepted\n";
    echo "history_endpoint=ok\n";
    echo "workspace_endpoint=ok\n";
    echo "chatkit_runtime=absent\n";
    echo "assets=ok\n";
    echo "screen_crop=ok\n";
    echo "clipboard_image_paste=ok\n";
} finally {
    $deleteSession = $pdo->prepare('DELETE FROM php_sessions WHERE id=?');
    $deleteSession->execute([$sessionId]);
}
