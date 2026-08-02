<?php
// app/Services/StorageService.php
declare(strict_types=1);

use Google\Cloud\Storage\StorageClient;

class StorageService
{
    private static ?StorageClient $storageClient = null;
    private static ?string $bucketName = null;

    private static function init(): bool
    {
        if (self::$bucketName !== null) {
            return self::$bucketName !== '';
        }

        $bucket = app_env('GCS_BUCKET_NAME', '');
        if ($bucket === '') {
            self::$bucketName = '';
            return false;
        }

        self::$bucketName = $bucket;
        $projectId = app_env('GCP_PROJECT_ID', '');

        if (!class_exists(StorageClient::class)) {
            Logger::log('GCS_BUCKET_NAME is configured, but google/cloud-storage is not installed or autoloaded. Falling back to local storage.', 'warning');
            self::$bucketName = '';
            return false;
        }
        
        self::$storageClient = new StorageClient([
            'projectId' => $projectId ?: null,
        ]);

        return true;
    }

    public static function isGcsActive(): bool
    {
        return self::init();
    }

    public static function put(string $targetPath, string $content): bool
    {
        if (self::init()) {
            try {
                $bucket = self::$storageClient->bucket(self::$bucketName);
                $bucket->upload($content, [
                    'name' => self::normalizePath($targetPath)
                ]);
                return true;
            } catch (Throwable $e) {
                Logger::log('Error uploading to GCS: ' . $e->getMessage(), 'error');
                return false;
            }
        }

        // Local fallback
        $localPath = self::localPath($targetPath);
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return file_put_contents($localPath, $content) !== false;
    }

    public static function get(string $targetPath): ?string
    {
        if (self::init()) {
            try {
                $bucket = self::$storageClient->bucket(self::$bucketName);
                $object = $bucket->object(self::normalizePath($targetPath));
                return $object->downloadAsString();
            } catch (Throwable $e) {
                Logger::log('Error reading from GCS: ' . $e->getMessage(), 'error');
                return null;
            }
        }

        // Local fallback
        $localPath = self::localPath($targetPath);
        return is_file($localPath) ? file_get_contents($localPath) : null;
    }

    public static function delete(string $targetPath): bool
    {
        if (self::init()) {
            try {
                $bucket = self::$storageClient->bucket(self::$bucketName);
                $object = $bucket->object(self::normalizePath($targetPath));
                if ($object->exists()) {
                    $object->delete();
                }
                return true;
            } catch (Throwable $e) {
                Logger::log('Error deleting from GCS: ' . $e->getMessage(), 'error');
                return false;
            }
        }

        // Local fallback
        $localPath = self::localPath($targetPath);
        if (is_file($localPath)) {
            return unlink($localPath);
        }
        return true;
    }

    public static function getSignedUrl(string $targetPath, int $minutes = 10): ?string
    {
        if (self::init()) {
            try {
                $bucket = self::$storageClient->bucket(self::$bucketName);
                $object = $bucket->object(self::normalizePath($targetPath));
                if (!$object->exists()) {
                    return null;
                }
                return $object->signedUrl(
                    new DateTime('+' . $minutes . ' minutes'),
                    [
                        'version' => 'v4'
                    ]
                );
            } catch (Throwable $e) {
                Logger::log('Error signing GCS URL: ' . $e->getMessage(), 'error');
                return null;
            }
        }

        // Local fallback: return direct media.php URL
        return 'media.php?file=' . urlencode($targetPath);
    }

    public static function uploadFile(string $targetPath, string $sourceFilePath): bool
    {
        if (self::init()) {
            try {
                $bucket = self::$storageClient->bucket(self::$bucketName);
                $bucket->upload(fopen($sourceFilePath, 'r'), [
                    'name' => self::normalizePath($targetPath)
                ]);
                return true;
            } catch (Throwable $e) {
                Logger::log('Error uploading file to GCS: ' . $e->getMessage(), 'error');
                return false;
            }
        }

        // Local fallback
        $localPath = self::localPath($targetPath);
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return copy($sourceFilePath, $localPath);
    }

    /**
     * A URL the browser can PUT straight to, so a large file never travels
     * through the container. Cloud Run caps an ordinary request at 32 MiB, which
     * a finished video passes easily.
     *
     * Returns null when the bucket is not configured, and the caller is expected
     * to fall back to an ordinary upload.
     */
    public static function getSignedUploadUrl(string $targetPath, string $contentType, int $minutes = 30): ?string
    {
        if (!self::init()) {
            return null;
        }
        try {
            $bucket = self::$storageClient->bucket(self::$bucketName);
            return $bucket->object(self::normalizePath($targetPath))->signedUrl(
                new DateTime('+' . $minutes . ' minutes'),
                [
                    'version' => 'v4',
                    'method' => 'PUT',
                    'contentType' => $contentType,
                ]
            );
        } catch (Throwable $e) {
            Logger::log('Error signing GCS upload URL: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /** Size in bytes of a stored object, or null when it is not there. */
    public static function objectSize(string $targetPath): ?int
    {
        if (self::init()) {
            try {
                $object = self::$storageClient->bucket(self::$bucketName)->object(self::normalizePath($targetPath));
                if (!$object->exists()) {
                    return null;
                }
                return (int)($object->info()['size'] ?? 0);
            } catch (Throwable $e) {
                Logger::log('Error reading GCS object size: ' . $e->getMessage(), 'error');
                return null;
            }
        }

        $localPath = self::localPath($targetPath);
        if (!is_file($localPath)) {
            return null;
        }
        $bytes = filesize($localPath);
        return $bytes === false ? null : (int)$bytes;
    }

    public static function downloadFile(string $targetPath, string $destFilePath): bool
    {
        if (self::init()) {
            try {
                $bucket = self::$storageClient->bucket(self::$bucketName);
                $object = $bucket->object(self::normalizePath($targetPath));
                if ($object->exists()) {
                    $dir = dirname($destFilePath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0775, true);
                    }
                    $object->downloadToFile($destFilePath);
                    return true;
                }
                return false;
            } catch (Throwable $e) {
                Logger::log('Error downloading file from GCS: ' . $e->getMessage(), 'error');
                return false;
            }
        }

        // Local fallback
        $localPath = self::localPath($targetPath);
        if (is_file($localPath) && $localPath !== $destFilePath) {
            $dir = dirname($destFilePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            return copy($localPath, $destFilePath);
        }
        return is_file($destFilePath);
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', ltrim($path, '/'));
    }

    private static function localPath(string $targetPath): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $targetPath), DIRECTORY_SEPARATOR);
    }
}
