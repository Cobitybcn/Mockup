<?php
$output = [];
$exitCode = -1;
exec('python ' . __DIR__ . '/test_crop_prompt.py 2>&1', $output, $exitCode);
echo "Exit code: $exitCode\n\n" . implode("\n", $output);
