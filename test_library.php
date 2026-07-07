<?php
// test_library.php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $library = new WorldMotherLibrary();
    echo "Library Base Path: " . $library->basePath() . "\n";
    echo "Library Base Directory Exists: " . (is_dir($library->basePath()) ? "YES" : "NO") . "\n";
    
    $categories = $library->categories();
    echo "Category Count: " . count($categories) . "\n";
    foreach ($categories as $cat) {
        echo "Category: {$cat['category_slug']} | Name: {$cat['category_name']} | Images: {$cat['image_count']}\n";
        
        $images = $library->imagesForCategory($cat['category_slug']);
        if ($images) {
            echo "   -> First image absolute path: " . $images[0]['absolute_path'] . "\n";
        }
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
