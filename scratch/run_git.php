<?php
$output = [];
$exitCode = -1;
exec('git log -n 20 --oneline 2>&1', $output, $exitCode);
file_put_contents(__DIR__ . '/git_log.txt', "Exit code: $exitCode\n\n" . implode("\n", $output));
echo "Done\n";
