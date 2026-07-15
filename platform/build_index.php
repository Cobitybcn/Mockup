<?php
// build_index.php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $library = new WorldMotherLibrary();
    $basePath = $library->basePath();
    
    echo "Scanning World Mothers directory: {$basePath}\n\n";
    
    // Always scan the real folders. The previous implementation accidentally
    // read the existing index, so manual renames/deletions could not be repaired.
    $indexData = $library->rebuildIndex();
    foreach ((array)($indexData['categories'] ?? []) as $cat) {
        $slug = (string)($cat['category_slug'] ?? '');
        $imageCount = count((array)($indexData['images'][$slug] ?? []));
        echo "Found Category: {$cat['category_name']} ({$slug}) with {$imageCount} images.\n";
    }
    $indexPath = $basePath . DIRECTORY_SEPARATOR . 'index.json';
    
    echo "\nSUCCESS: World Mothers index.json successfully created at:\n{$indexPath}\n";
    
} catch (Throwable $e) {
    echo "Error building index: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
