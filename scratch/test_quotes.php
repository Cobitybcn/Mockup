<?php
declare(strict_types=1);

$baseDir = realpath(__DIR__ . '/../');
$script = $baseDir . '\\test_bg2.php';
$doneFile = $baseDir . '\\test_bg_done2.txt';

@unlink($doneFile);

$phpPath = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe';

// Wrap arguments in escaped quotes inside the wmic process string
$wmicCmd = sprintf(
    'wmic process call create "\"%s\" \"%s\" test_arg" 2>&1',
    $phpPath,
    $script
);

echo "Testing Direct WMIC with quotes:\nCommand: $wmicCmd\n";
$outputWmic = shell_exec($wmicCmd);
echo "Output:\n$outputWmic\n\n";
