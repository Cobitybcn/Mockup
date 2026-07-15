<?php
// test_dir.php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$dir = __DIR__ . '/storage/world_mothers';
echo "Checking directory: {$dir}\n";
echo "Directory exists: " . (is_dir($dir) ? "YES" : "NO") . "\n";

if (is_dir($dir)) {
    $items = scandir($dir);
    echo "Scandir output:\n";
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $fullPath = $dir . '/' . $item;
        $isDir = is_dir($fullPath);
        echo " - " . $item . ($isDir ? " (DIR)" : " (FILE)") . "\n";
        if ($isDir) {
            $subItems = scandir($fullPath);
            foreach ($subItems as $subItem) {
                if ($subItem === '.' || $subItem === '..') {
                    continue;
                }
                echo "   -> " . $subItem . "\n";
            }
        }
    }
}
