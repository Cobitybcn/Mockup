<?php
declare(strict_types=1);

$script = str_replace('/', '\\', __DIR__ . '/../test_bg2.php');
$logOut = str_replace('/', '\\', __DIR__ . '/../test_out2.log');
$logErr = str_replace('/', '\\', __DIR__ . '/../test_err2.log');
$doneFile = str_replace('/', '\\', __DIR__ . '/../test_bg_done2.txt');

@unlink($doneFile);
@unlink($logOut);
@unlink($logErr);

$phpPath = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe';

function wmic_escape(string $val): string {
    return '\"' . str_replace('"', '\"', $val) . '\"';
}

// 1. Test WMIC command
$wmicCmd = sprintf(
    'wmic process call create "cmd.exe /c %s %s %s > %s 2> %s" 2>&1',
    wmic_escape($phpPath),
    wmic_escape($script),
    wmic_escape('test_arg'),
    wmic_escape($logOut),
    wmic_escape($logErr)
);

echo "Testing WMIC:\nCommand: $wmicCmd\n";
$outputWmic = shell_exec($wmicCmd);
echo "Output:\n$outputWmic\n\n";

// 2. Test start /B command
$startCmd = sprintf(
    'cmd.exe /c start /B "" "%s" "%s" test_arg > "%s" 2> "%s" 2>&1',
    $phpPath,
    $script,
    $logOut,
    $logErr
);

echo "Testing start /B:\nCommand: $startCmd\n";
$outputStart = shell_exec($startCmd);
echo "Output:\n$outputStart\n\n";
