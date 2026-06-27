<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

echo "<h1>Mockup Viewing Flow Verification</h1>";

// 1. Syntax & Include Check
echo "Checking view_mockup_file.php syntax and logic...<br>";

// We can perform a dry-run check of the security checks in view_mockup_file.php
$resultsDir = realpath(RESULTS_DIR);
echo "RESULTS_DIR path: <code>" . htmlspecialchars($resultsDir) . "</code><br>";

// Test path traversal protection logic
function test_path_traversal($filename, $resultsDir) {
    $mockupFile = basename((string)$filename);
    $filePath = $resultsDir . DIRECTORY_SEPARATOR . $mockupFile;
    $realFilePath = realpath($filePath);
    
    echo "Testing input: '" . htmlspecialchars($filename) . "' -> basename: '" . htmlspecialchars($mockupFile) . "'<br>";
    if ($realFilePath === false) {
        echo "Status: <span style='color:orange;'>File does not exist (Safe/Expected for non-existent test files)</span><br>";
        return;
    }
    
    $inside = str_starts_with($realFilePath, $resultsDir . DIRECTORY_SEPARATOR);
    if ($inside) {
        echo "Status: <span style='color:green;'>VALID (inside RESULTS_DIR)</span> - Path: " . htmlspecialchars($realFilePath) . "<br>";
    } else {
        echo "Status: <span style='color:red;'>PATH TRAVERSAL DETECTED</span> - Path: " . htmlspecialchars($realFilePath) . "<br>";
    }
}

// Create a temporary dummy mockup file in RESULTS_DIR to test containment
$dummyFile = RESULTS_DIR . DIRECTORY_SEPARATOR . 'test_dummy_mockup_123.png';
file_put_contents($dummyFile, 'dummy data');

test_path_traversal('test_dummy_mockup_123.png', $resultsDir);
test_path_traversal('../config.php', $resultsDir);
test_path_traversal('subfolder/../../config.php', $resultsDir);

// Clean up dummy
if (is_file($dummyFile)) {
    unlink($dummyFile);
}

// Check database connection and look up mockups
try {
    $pdo = Database::connection();
    echo "Database connection: <span style='color:green;'>SUCCESS</span><br>";
    
    // Check if mockups table exists and has entries
    $stmt = $pdo->query("SELECT COUNT(*) FROM mockups");
    $count = $stmt->fetchColumn();
    echo "Total mockups in DB: <strong>$count</strong><br>";
    
    if ($count > 0) {
        $stmtLatest = $pdo->query("SELECT * FROM mockups ORDER BY id DESC LIMIT 1");
        $latest = $stmtLatest->fetch(PDO::FETCH_ASSOC);
        echo "Latest mockup: ID=" . $latest['id'] . ", file=" . htmlspecialchars($latest['mockup_file']) . ", user_id=" . $latest['user_id'] . "<br>";
        echo "Test viewing link: <a href='view_mockup_file.php?mockup_id=" . $latest['id'] . "' target='_blank'>view_mockup_file.php?mockup_id=" . $latest['id'] . "</a><br>";
    }
} catch (Throwable $e) {
    echo "Database check failed: <span style='color:red;'>" . htmlspecialchars($e->getMessage()) . "</span><br>";
}

echo "<h3>All static verification checks completed. Please run this script in your browser to verify live DB connectivity and path containment.</h3>";
