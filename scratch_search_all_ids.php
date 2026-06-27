<?php
$logFile = __DIR__ . '/logs/app.log';
if (!is_file($logFile)) {
    die("Log file not found.\n");
}

$ids = [1295, 1296, 1297, 1298, 1299, 1300, 1301, 1302, 1303, 1304];
$matches = [];

$handle = fopen($logFile, 'r');
if ($handle) {
    while (($line = fgets($handle)) !== false) {
        foreach ($ids as $id) {
            if (strpos($line, (string)$id) !== false) {
                $matches[] = trim($line);
                break; // break inner loop, proceed to next line
            }
        }
    }
    fclose($handle);
}

file_put_contents(__DIR__ . '/scratch_log_matches.json', json_encode($matches, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "Matched " . count($matches) . " log lines.\n";
