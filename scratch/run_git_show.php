<?php
$output = [];
$exitCode = -1;
exec('git show a049d67 2>&1', $output, $exitCode);
file_put_contents(__DIR__ . '/git_show.txt', "Exit code: $exitCode\n\n" . implode("\n", $output));
echo "Done\n";
