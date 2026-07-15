<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$direction = $argv[1] ?? 'up';
if ($direction !== 'up') {
    throw new InvalidArgumentException('This destructive cleanup only supports up.');
}

$pdo = Database::connection();
$transactional = !Database::isMysql();
if ($transactional) {
    Database::beginWriteTransaction($pdo);
}

try {
    $pdo->exec('DROP TABLE IF EXISTS social_video_jobs');
    $pdo->exec('DROP TABLE IF EXISTS social_video_workflows');

    $stmt = $pdo->prepare(
        'DELETE FROM app_settings
         WHERE substr(`key`, 1, 13) = ?
            OR substr(`key`, 1, 28) = ?'
    );
    $stmt->execute(['social_video_', 'prompt_default_social_video_']);

    if ($transactional && $pdo->inTransaction()) {
        $pdo->commit();
    }
    echo "Retired video module tables and settings removed.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
