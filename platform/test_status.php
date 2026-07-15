<?php
// test_status.php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();
    
    // 1. Check artwork with ID = 1
    $stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = 1 LIMIT 1');
    $stmt->execute();
    $artwork = $stmt->fetch();
    
    if ($artwork) {
        echo "Artwork ID 1 exists:\n";
        echo " - Job ID: " . $artwork['job_id'] . "\n";
        echo " - Root File: " . $artwork['root_file'] . "\n";
        echo " - Status: " . $artwork['status'] . "\n";
        
        $rootPath = RESULTS_DIR . DIRECTORY_SEPARATOR . basename($artwork['root_file']);
        echo "Checking root file at: {$rootPath}\n";
        echo "Root file exists on disk: " . (is_file($rootPath) ? "YES" : "NO") . "\n";
        if (is_file($rootPath)) {
            echo "Root file size: " . filesize($rootPath) . " bytes\n";
        }
    } else {
        echo "Artwork ID 1 not found in database.\n";
    }
    
    // 2. List all files in results/
    echo "\nListing files in results directory (" . RESULTS_DIR . "):\n";
    if (is_dir(RESULTS_DIR)) {
        $files = scandir(RESULTS_DIR);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            echo " - " . $file . " (" . filesize(RESULTS_DIR . '/' . $file) . " bytes)\n";
        }
    } else {
        echo "Results directory does not exist.\n";
    }
    
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
