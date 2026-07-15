<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));
$fileParam = basename((string)($_GET['file'] ?? ''));

echo "=== CLOUD DIAG ===\n";
echo "time: " . date(DATE_ATOM) . "\n";
echo "php_sapi: " . PHP_SAPI . "\n";
echo "host: " . ($_SERVER['HTTP_HOST'] ?? '[cli]') . "\n";
echo "cwd: " . __DIR__ . "\n";
echo "\n";

echo "=== APP / PROVIDER ===\n";
echo "APP_MODE: " . (defined('APP_MODE') ? APP_MODE : '[undefined]') . "\n";
echo "ALLOW_REAL_API: " . ((defined('ALLOW_REAL_API') && ALLOW_REAL_API) ? 'true' : 'false') . "\n";
echo "IMAGE_PROVIDER: " . (defined('IMAGE_PROVIDER') ? IMAGE_PROVIDER : '[undefined]') . "\n";
echo "Provider app_mode: " . ProviderSettings::appMode() . "\n";
echo "Provider allow_real_api: " . (ProviderSettings::allowRealApi() ? '1' : '0') . "\n";
echo "Provider image_provider: " . ProviderSettings::imageProvider() . "\n";
echo "Provider gemini_image_model: " . ProviderSettings::geminiImageModel() . "\n";
echo "VERTEX_PROJECT_ID: " . (defined('VERTEX_PROJECT_ID') ? VERTEX_PROJECT_ID : '[undefined]') . "\n";
echo "VERTEX_LOCATION: " . (defined('VERTEX_LOCATION') ? VERTEX_LOCATION : '[undefined]') . "\n";
echo "Factory mockup generator: " . get_class(ServiceFactory::mockupGenerator()) . "\n";
echo "\n";

echo "=== STORAGE ===\n";
echo "GCS_BUCKET_NAME: " . app_env('GCS_BUCKET_NAME', '[empty]') . "\n";
echo "google/cloud-storage class: " . (class_exists('Google\\Cloud\\Storage\\StorageClient') ? 'yes' : 'no') . "\n";
$gcsActive = false;
try {
    $gcsActive = StorageService::isGcsActive();
    echo "GCS active: " . ($gcsActive ? 'yes' : 'no') . "\n";
} catch (Throwable $e) {
    echo "GCS active error: " . $e->getMessage() . "\n";
}
echo "RESULTS_DIR: " . RESULTS_DIR . "\n";
echo "PROMPTS_DIR: " . PROMPTS_DIR . "\n";
echo "\n";

if ($fileParam !== '') {
    diag_file($fileParam, $gcsActive);
    echo "\n";
}

echo "=== LATEST JOBS ===\n";
try {
    $stmt = Database::connection()->prepare("
        SELECT id, status, error, updated_at, mockup_file, prompt_file
        FROM mockup_generation_jobs
        ORDER BY id DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    foreach ($stmt->fetchAll() as $job) {
        echo "job_id: {$job['id']}\n";
        echo "status: {$job['status']}\n";
        echo "updated_at: {$job['updated_at']}\n";
        echo "mockup_file: " . ((string)($job['mockup_file'] ?? '') !== '' ? (string)$job['mockup_file'] : '[empty]') . "\n";
        echo "prompt_file: " . ((string)($job['prompt_file'] ?? '') !== '' ? (string)$job['prompt_file'] : '[empty]') . "\n";
        if ((string)($job['mockup_file'] ?? '') !== '') {
            diag_file(basename((string)$job['mockup_file']), $gcsActive, false);
        }
        echo "error: " . ((string)($job['error'] ?? '') !== '' ? (string)$job['error'] : '[none]') . "\n";
        echo "--------------------------------------------------\n";
    }
} catch (Throwable $e) {
    echo "jobs_error: " . $e->getMessage() . "\n";
}

function diag_file(string $file, bool $gcsActive, bool $withHeader = true): void
{
    if ($withHeader) {
        echo "=== FILE DIAG ===\n";
    }

    $file = basename($file);
    $localPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
    echo "file: {$file}\n";
    echo "media_url: media.php?file=" . rawurlencode($file) . "\n";
    echo "local_results_exists: " . (is_file($localPath) ? 'yes (' . filesize($localPath) . ' bytes)' : 'no') . "\n";

    if (!$gcsActive) {
        echo "gcs_results_exists: skipped (GCS inactive)\n";
        return;
    }

    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cloud_diag_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $file);
    $downloaded = StorageService::downloadFile('results/' . $file, $tmpPath);
    echo "gcs_results_exists: " . ($downloaded && is_file($tmpPath) ? 'yes (' . filesize($tmpPath) . ' bytes)' : 'no') . "\n";
    if (is_file($tmpPath)) {
        @unlink($tmpPath);
    }
}

