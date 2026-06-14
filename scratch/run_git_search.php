<?php
$output = [];
$exitCode = -1;

$commands = [
    'git log -S RootArtworkCropper -p',
    'git log -S cropNeutralMargin -p',
    'git log -p -n 3 c:/laragon/www/mockups/app/Services/GeminiArtworkProcessor.php',
    'git log -p -n 3 c:/laragon/www/mockups/app/Services/OpenAIArtworkProcessor.php'
];

$res = "";
foreach ($commands as $cmd) {
    $cmdOut = [];
    $cmdCode = -1;
    exec("$cmd 2>&1", $cmdOut, $cmdCode);
    $res .= "=== COMMAND: $cmd (Exit code: $cmdCode) ===\n";
    $res .= implode("\n", $cmdOut) . "\n\n";
}

file_put_contents(__DIR__ . '/git_search.txt', $res);
echo "Done\n";
