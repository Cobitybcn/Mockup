<?php
// list_gcs.php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    if (!StorageService::isGcsActive()) {
        echo "GCS is not active. Please check GCS_BUCKET_NAME in .env.\n";
        exit;
    }
    
    // We access GCS through reflection to read self::$storageClient and self::$bucketName
    $ref = new ReflectionClass('StorageService');
    $propClient = $ref->getProperty('storageClient');
    $propClient->setAccessible(true);
    $client = $propClient->getValue();
    
    $propBucket = $ref->getProperty('bucketName');
    $propBucket->setAccessible(true);
    $bucketName = $propBucket->getValue();
    
    echo "Connected to GCS Bucket: {$bucketName}\n";
    echo "Listing objects under prefix: storage/world_mothers/\n";
    echo "==================================================\n\n";
    
    $bucket = $client->bucket($bucketName);
    $objects = $bucket->objects([
        'prefix' => 'storage/world_mothers/'
    ]);
    
    $count = 0;
    foreach ($objects as $object) {
        $count++;
        echo " - Name: " . $object->name() . " (Size: " . $object->info()['size'] . " bytes)\n";
        if ($count >= 50) {
            echo "... (showing first 50 objects)\n";
            break;
        }
    }
    
    echo "\nTotal listed objects: {$count}\n";
    
} catch (Throwable $e) {
    echo "Error listing GCS: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
