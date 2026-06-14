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

// Use cmd.exe /c start /B
$cmd = sprintf(
    'cmd.exe /c start /B "" "%s" "%s" test_arg > "%s" 2> "%s"',
    $phpPath,
    $script,
    $logOut,
    $logErr
);

echo "Command: $cmd\n";
pclose(popen($cmd, 'r'));
echo "Parent done\n";
