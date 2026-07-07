<?php
// find_heavy_files.php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$rootDir = __DIR__;
$minSize = 1024 * 1024; // 1 MB

echo "Scanning for files larger than 1 MB...\n";
echo "========================================\n\n";

$ignoredDirs = [
    'backups',
    'results',
    'jobs',
    'storage',
    '.git',
];

$filesFound = [];

try {
    $directory = new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS);
    $iterator = new RecursiveIteratorIterator($directory);
    
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        
        $path = $file->getPathname();
        
        // Check if file is inside an ignored directory
        $shouldIgnore = false;
        foreach ($ignoredDirs as $ignored) {
            $dirPrefix = $rootDir . DIRECTORY_SEPARATOR . $ignored . DIRECTORY_SEPARATOR;
            if (str_starts_with($path, $dirPrefix) || basename(dirname($path)) === $ignored) {
                $shouldIgnore = true;
                break;
            }
        }
        
        if ($shouldIgnore) {
            continue;
        }
        
        $size = $file->getSize();
        if ($size >= $minSize) {
            $relativePath = str_replace($rootDir . DIRECTORY_SEPARATOR, '', $path);
            $filesFound[$relativePath] = $size;
        }
    }
    
    arsort($filesFound);
    
    foreach ($filesFound as $file => $size) {
        echo " - " . str_pad($file, 60) . ": " . number_format($size / 1024 / 1024, 2) . " MB\n";
    }
    
    echo "\nTotal files found: " . count($filesFound) . "\n";
    
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}
