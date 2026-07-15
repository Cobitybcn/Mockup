<?php
// check_size.php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

function get_dir_size(string $dir): int
{
    $size = 0;
    if (!is_dir($dir)) {
        return 0;
    }
    
    // Simple recursive folder size check
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($files as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    
    return $size;
}

function format_size(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}

try {
    $rootDir = __DIR__;
    echo "Scanning root directory: {$rootDir}\n";
    echo "========================================\n\n";
    
    $items = scandir($rootDir);
    $totalSize = 0;
    
    $folderSizes = [];
    $fileSizes = [];
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $path = $rootDir . '/' . $item;
        if (is_dir($path)) {
            $size = get_dir_size($path);
            $folderSizes[$item] = $size;
            $totalSize += $size;
        } else {
            $size = filesize($path);
            $fileSizes[$item] = $size;
            $totalSize += $size;
        }
    }
    
    arsort($folderSizes);
    arsort($fileSizes);
    
    echo "FOLDERS BY SIZE:\n";
    foreach ($folderSizes as $folder => $size) {
        echo " - /" . str_pad($folder, 30) . ": " . format_size($size) . "\n";
        if ($folder === 'storage') {
            echo "   ----------------------------------------\n";
            echo "   Subfolders inside /storage:\n";
            $storageItems = scandir($rootDir . '/storage');
            $storageSizes = [];
            foreach ($storageItems as $sItem) {
                if ($sItem === '.' || $sItem === '..') {
                    continue;
                }
                $sPath = $rootDir . '/storage/' . $sItem;
                if (is_dir($sPath)) {
                    $storageSizes[$sItem] = get_dir_size($sPath);
                } else {
                    $storageSizes[$sItem] = filesize($sPath);
                }
            }
            arsort($storageSizes);
            foreach ($storageSizes as $sItem => $sSize) {
                echo "    * " . str_pad($sItem, 28) . ": " . format_size($sSize) . "\n";
            }
            echo "   ----------------------------------------\n";
        }
    }
    
    echo "\nFILES BY SIZE:\n";
    foreach ($fileSizes as $file => $size) {
        echo " - " . str_pad($file, 31) . ": " . format_size($size) . "\n";
    }
    
    echo "\n========================================\n";
    echo "TOTAL SIZE: " . format_size($totalSize) . "\n";
    
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}
