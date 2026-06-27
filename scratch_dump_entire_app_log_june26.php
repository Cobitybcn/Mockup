<?php
$logFile = __DIR__ . '/logs/app.log';
if (!is_file($logFile)) {
    die("Log file not found.\n");
}

$matches = [];
$handle = fopen($logFile, 'r');
if ($handle) {
    while (($line = fgets($handle)) !== false) {
        if (strpos($line, '2026-06-26 10:') !== false) {
            if (preg_match('/2026-06-26 10:(\d+):/', $line, $m)) {
                $min = (int)$m[1];
                if ($min >= 20) {
                    $matches[] = trim($line);
                }
            }
        } elseif (strpos($line, '2026-06-26 11:') !== false || strpos($line, '2026-06-26 12:') !== false) {
            $matches[] = trim($line);
        }
    }
    fclose($handle);
}

file_put_contents(__DIR__ . '/scratch_timeline_extended.json', json_encode($matches, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "Found " . count($matches) . " extended timeline lines.\n";
