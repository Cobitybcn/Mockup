<?php
$candidates = [
    'python',
    'C:\Users\MSI\AppData\Local\Microsoft\WindowsApps\python.exe',
    'C:\Users\MSI\AppData\Local\Programs\Python\Python311\python.exe',
    'C:\Users\MSI\AppData\Local\Programs\Python\Python312\python.exe',
    'C:\Users\MSI\AppData\Local\Programs\Python\Python313\python.exe',
    'C:\laragon\bin\python\python-3.13\python.exe',
];

foreach ($candidates as $cand) {
    echo "========================================\n";
    echo "Candidate: $cand\n";
    echo "is_file: " . (is_file($cand) ? 'YES' : 'NO') . "\n";
    
    $cmd = ($cand === 'python') ? 'python' : '"' . $cand . '"';
    
    $outputV = [];
    $exitCodeV = -1;
    @exec($cmd . ' -V 2>&1', $outputV, $exitCodeV);
    echo "Version check - Exit code: $exitCodeV, Output: " . implode(" | ", $outputV) . "\n";

    $outputG = [];
    $exitCodeG = -1;
    @exec($cmd . ' -c "import google.genai" 2>&1', $outputG, $exitCodeG);
    echo "GenAI check   - Exit code: $exitCodeG, Output: " . implode(" | ", $outputG) . "\n";
}

