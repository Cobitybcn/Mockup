<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['user-id::', 'email::', 'disable', 'no-backfill']);
$pdo = Database::connection();
$userId = max(0, (int)($options['user-id'] ?? 0));
if ($userId <= 0 && trim((string)($options['email'] ?? '')) !== '') {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1');
    $stmt->execute([trim((string)$options['email'])]);
    $userId = (int)$stmt->fetchColumn();
}
if ($userId <= 0) {
    fwrite(STDERR, "Use --user-id=ID or --email=artist@example.com\n");
    exit(1);
}

$service = new BilingualEditorialService($pdo);
$enabled = !isset($options['disable']);
$service->setEnabled($userId, $enabled);
$counts = ['series' => 0, 'artwork' => 0, 'mockup' => 0];
if ($enabled && !isset($options['no-backfill'])) $counts = $service->backfillEnglish($userId);

echo json_encode(['user_id' => $userId, 'enabled' => $enabled, 'english_backfill' => $counts], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
