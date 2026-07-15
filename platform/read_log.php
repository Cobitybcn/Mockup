<?php
// read_log.php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$logFile = __DIR__ . '/logs/app.log';
if (!is_file($logFile)) {
    echo "Log file not found at: {$logFile}\n";
    exit;
}

$linesToRead = 100;
$file = new SplFileObject($logFile, 'r');
$file->seek(PHP_INT_MAX);
$totalLines = $file->key();

$startLine = max(0, $totalLines - $linesToRead);
$file->seek($startLine);

echo "Last {$linesToRead} lines of app.log (Total lines: {$totalLines}):\n";
echo "=================================================================\n";
while (!$file->eof()) {
    echo $file->current();
    $file->next();
}
