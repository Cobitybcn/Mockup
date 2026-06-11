<?php
declare(strict_types=1);

$script = __DIR__ . '/test_bg2.php';
$logOut = __DIR__ . '/test_out2.log';
$logErr = __DIR__ . '/test_err2.log';

@unlink($script);
@unlink($logOut);
@unlink($logErr);
@unlink(__DIR__ . '/test_bg_done2.txt');

file_put_contents($script, '<?php
sleep(2);
file_put_contents(__DIR__ . "/test_bg_done2.txt", "OK");
');

$phpPath = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe';

function wmic_escape(string $val): string {
    // Escapa las comillas dobles internas agregando barra invertida
    return '\"' . str_replace('"', '\"', $val) . '\"';
}

$cmd = sprintf(
    'wmic process call create "cmd.exe /c %s %s %s > %s 2> %s"',
    wmic_escape($phpPath),
    wmic_escape($script),
    wmic_escape('test_arg'),
    wmic_escape($logOut),
    wmic_escape($logErr)
);

echo "Command: $cmd\n";
pclose(popen($cmd, 'r'));
echo "Parent done\n";
