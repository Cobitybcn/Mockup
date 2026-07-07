<?php
// build_index.php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $library = new WorldMotherLibrary();
    $basePath = $library->basePath();
    
    echo "Scanning World Mothers directory: {$basePath}\n\n";
    
    $categories = [];
    $images = [];
    
    // We instantiate library methods to scan the folders
    // categories() will scan folders when index.json doesn't exist yet
    $localCats = $library->categories();
    
    foreach ($localCats as $cat) {
        $slug = $cat['category_slug'];
        
        $categories[] = [
            'category_slug' => $slug,
            'category_name' => $cat['category_name'],
            'relative_path' => $cat['relative_path'],
        ];
        
        // Retrieve images for this category
        $localImages = $library->imagesForCategory($slug);
        $images[$slug] = [];
        
        foreach ($localImages as $img) {
            $images[$slug][] = [
                'world_mother_id' => $img['world_mother_id'],
                'category_slug' => $img['category_slug'],
                'category_name' => $img['category_name'],
                'file_name' => $img['file_name'],
                'title' => $img['title'],
                'relative_path' => $img['relative_path'],
                'extension' => $img['extension'],
            ];
        }
        
        echo "Found Category: {$cat['category_name']} ({$slug}) with " . count($images[$slug]) . " images.\n";
    }
    
    $indexData = [
        'generated_at' => date('c'),
        'categories' => $categories,
        'images' => $images,
    ];
    
    $indexPath = $basePath . DIRECTORY_SEPARATOR . 'index.json';
    file_put_contents($indexPath, json_encode($indexData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    
    echo "\nSUCCESS: World Mothers index.json successfully created at:\n{$indexPath}\n";
    
} catch (Throwable $e) {
    echo "Error building index: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
