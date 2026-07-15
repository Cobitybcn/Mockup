<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (!StorageService::isGcsActive()) {
    fwrite(STDERR, "GCS is not active. Set GCS_BUCKET_NAME before syncing.\n");
    exit(1);
}

$baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'world_mothers';
if (!is_dir($baseDir)) {
    fwrite(STDERR, "Local storage/world_mothers directory not found.\n");
    exit(1);
}

$allowed = array_fill_keys(WorldMotherLibrary::allowedExtensions(), true);
$dryRun = in_array('--dry-run', $argv, true);
$uploaded = 0;
$failed = 0;
$seen = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }

    $extension = strtolower($file->getExtension());
    if (!isset($allowed[$extension]) && $file->getFilename() !== 'index.json') {
        continue;
    }

    $localPath = $file->getPathname();
    $relative = str_replace('\\', '/', substr($localPath, strlen(dirname(__DIR__)) + 1));
    $seen++;

    if ($dryRun) {
        echo "[dry-run] {$relative}\n";
        continue;
    }

    if (StorageService::uploadFile($relative, $localPath)) {
        $uploaded++;
        echo "[uploaded] {$relative}\n";
    } else {
        $failed++;
        echo "[failed] {$relative}\n";
    }
}

echo "\nWorld mothers sync complete. Seen: {$seen}. Uploaded: {$uploaded}. Failed: {$failed}.\n";
exit($failed > 0 ? 1 : 0);
