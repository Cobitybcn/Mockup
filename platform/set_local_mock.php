<?php
// set_local_mock.php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();
    
    // 1. Force allow_real_api to 0 (disabled)
    $stmt = $pdo->prepare("UPDATE app_settings SET value = '0' WHERE `key` = 'allow_real_api'");
    $stmt->execute();
    
    // 2. Force app_mode to mock
    $stmt2 = $pdo->prepare("UPDATE app_settings SET value = 'mock' WHERE `key` = 'app_mode'");
    $stmt2->execute();
    
    echo "SUCCESS: Local environment configured to MOCK mode (Offline testing).\n";
    echo " - allow_real_api has been set to 0\n";
    echo " - app_mode has been set to 'mock'\n\n";
    echo "You can now generate combinations locally, and they will complete instantly!";
    
} catch (Throwable $e) {
    echo "Error configuring local settings: " . $e->getMessage();
}
