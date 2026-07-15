<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$email = strtolower(trim((string)($argv[1] ?? '')));
if ($email === '') {
    fwrite(STDERR, "Usage: php scripts/assistant_smoke.php user@example.com\n");
    exit(1);
}

$pdo = Database::connection();
$statement = $pdo->prepare('SELECT id,email,name,credits,is_admin,created_at FROM users WHERE LOWER(email)=? LIMIT 1');
$statement->execute([$email]);
$user = $statement->fetch();
if (!is_array($user)) {
    fwrite(STDERR, "The requested smoke-test user does not exist.\n");
    exit(1);
}

$config = new AssistantConfig();
if (!$config->enabledFor($user) || $config->apiKey() === '') {
    fwrite(STDERR, "The assistant or OpenAI credential is not enabled in this environment.\n");
    exit(1);
}

Database::beginWriteTransaction($pdo);
try {
    $repository = new AssistantRepository($pdo);
    $service = new AssistantService($config, $repository, new AssistantContext($pdo), new AssistantOpenAIClient($config));
    $result = $service->chat($user, [
        'message' => 'Prueba de conexión: responde únicamente "Conexión correcta" y no uses herramientas.',
        'page_context' => ['current_route' => 'dashboard.php'],
    ]);
    $usage = $pdo->query('SELECT model,input_tokens,output_tokens,status FROM assistant_usage_events ORDER BY id DESC LIMIT 1')->fetch();
    if (!is_array($usage) || (string)$usage['status'] !== 'success') {
        throw new RuntimeException('The OpenAI call did not produce a successful usage record.');
    }
    echo 'OPENAI_SMOKE_OK' . PHP_EOL;
    echo 'model=' . (string)$usage['model'] . PHP_EOL;
    echo 'input_tokens=' . (int)$usage['input_tokens'] . PHP_EOL;
    echo 'output_tokens=' . (int)$usage['output_tokens'] . PHP_EOL;
    echo 'response=' . trim((string)$result['message']) . PHP_EOL;
    $pdo->rollBack();
    echo 'database_test_writes=rolled_back' . PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'OPENAI_SMOKE_FAILED: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
