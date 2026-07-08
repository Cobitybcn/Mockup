<?php
// run_git.php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

echo "Executing Git Commit and Push...\n";
echo "========================================\n\n";

function run_command(string $cmd): void
{
    echo "> {$cmd}\n";
    $output = [];
    $retval = 0;
    exec($cmd . ' 2>&1', $output, $retval);
    echo implode("\n", $output) . "\n";
    echo "Return value: {$retval}\n\n";
}

try {
    // 1. Stage all changes
    run_command('git add .');

    // 2. Commit the changes
    run_command('git commit -m "Add database session handler and commit index.json"');

    // 3. Push to main
    run_command('git push origin HEAD:main');
    
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}
