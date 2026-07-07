<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$userId = isset($argv[1]) ? max(0, (int)$argv[1]) : 0;
$service = new ArtworkGroupService(Database::connection());

if ($userId > 0) {
    $result = $service->syncUser($userId);
    echo "user={$userId} created={$result['created']} updated={$result['updated']}\n";
    exit;
}

$result = $service->syncAllUsers();
echo "users={$result['users']} created={$result['created']} updated={$result['updated']}\n";
