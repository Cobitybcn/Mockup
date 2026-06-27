<?php
$logFile = __DIR__ . '/logs/app.log';
if (!is_file($logFile)) {
    die("Log file not found.\n");
}

$matches = [];
$handle = fopen($logFile, 'r');
if ($handle) {
    while (($line = fgets($handle)) !== false) {
        if (strpos($line, '2026-06-26') !== false) {
            if (stripos($line, 'error') !== false || stripos($line, 'fail') !== false || stripos($line, 'exception') !== false || strpos($line, '1633') !== false || strpos($line, '1634') !== false) {
                $matches[] = trim($line);
            }
        }
    }
    fclose($handle);
}

file_put_contents(__DIR__ . '/scratch_errors.json', json_encode($matches, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "Found " . count($matches) . " matching log lines.\n";
