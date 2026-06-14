<?php
declare(strict_types=1);

$baseDir = realpath(__DIR__ . '/../');
$script = $baseDir . '\\test_bg2.php';
$logOut = $baseDir . '\\test_out2.log';
$logErr = $baseDir . '\\test_err2.log';
$doneFile = $baseDir . '\\test_bg_done2.txt';

@unlink($doneFile);
@unlink($logOut);
@unlink($logErr);

$phpPath = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe';

function wmic_escape(string $val): string {
    return '\"' . str_replace('"', '\"', $val) . '\"';
}

$wmicCmd = sprintf(
    'wmic process call create "cmd.exe /c %s %s %s > %s 2> %s" 2>&1',
    wmic_escape($phpPath),
    wmic_escape($script),
    wmic_escape('test_arg'),
    wmic_escape($logOut),
    wmic_escape($logErr)
);

echo "Testing WMIC with clean absolute paths:\nCommand: $wmicCmd\n";
$outputWmic = shell_exec($wmicCmd);
echo "Output:\n$outputWmic\n\n";
