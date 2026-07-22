<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$pdo = Database::connection();
$websiteBoard = new WebsiteBoardService($pdo);
$statement = $pdo->query("SELECT id,user_id,title,objective,payload_json FROM social_campaigns ORDER BY id");
$migrated = 0;
$unchanged = 0;
$failed = 0;

foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $payload = json_decode((string)$row['payload_json'], true);
    if (!is_array($payload)
        || !in_array('website_blog', array_map('strval', (array)($payload['channels'] ?? [])), true)
        || stripos((string)$row['objective'], '<img') === false) {
        continue;
    }
    try {
        $before = (string)$row['objective'];
        $websiteBoard->saveNote((int)$row['user_id'], (int)$row['id'], (string)$row['title'], $before);
        $check = $pdo->prepare('SELECT objective FROM social_campaigns WHERE id=? AND user_id=?');
        $check->execute([(int)$row['id'], (int)$row['user_id']]);
        $after = (string)$check->fetchColumn();
        if ($after !== $before) {
            $migrated++;
            echo 'Migrated note #' . (int)$row['id'] . ': ' . (string)$row['title'] . PHP_EOL;
        } else {
            $unchanged++;
        }
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, 'Failed note #' . (int)$row['id'] . ': ' . $error->getMessage() . PHP_EOL);
    }
}

echo "Studio Note image migration complete: {$migrated} migrated, {$unchanged} unchanged, {$failed} failed." . PHP_EOL;
exit($failed > 0 ? 1 : 0);
