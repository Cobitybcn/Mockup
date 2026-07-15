<?php
// check_jobs.php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();
    
    // Imprimir variables de configuración de la base de datos
    echo "=== CONFIGURACION DE LA BASE DE DATOS EN LA NUBE ===\n";
    try {
        $stmtSettings = $pdo->query('SELECT `key`, value FROM app_settings');
        foreach ($stmtSettings->fetchAll() as $row) {
            echo "{$row['key']}: {$row['value']}\n";
        }
    } catch (Throwable $dbErr) {
        echo "Error leyendo app_settings: " . $dbErr->getMessage() . "\n";
    }
    echo "====================================================\n\n";

    echo "=== STORAGE / GCS ===\n";
    $gcsActive = false;
    try {
        $gcsActive = StorageService::isGcsActive();
        echo "GCS activo: " . ($gcsActive ? "SI" : "NO") . "\n";
        echo "GCS_BUCKET_NAME: " . app_env('GCS_BUCKET_NAME', '[vacío]') . "\n";
    } catch (Throwable $storageErr) {
        echo "Error inicializando GCS: " . $storageErr->getMessage() . "\n";
    }
    echo "====================================================\n\n";

    // Imprimir créditos de los usuarios
    echo "=== CREDITOS DE LOS USUARIOS ===\n";
    try {
        $stmtUsers = $pdo->query('SELECT id, email, credits FROM users');
        foreach ($stmtUsers->fetchAll() as $row) {
            echo "ID: {$row['id']} | Email: {$row['email']} | Créditos: {$row['credits']}\n";
        }
    } catch (Throwable $dbErr) {
        echo "Error leyendo usuarios: " . $dbErr->getMessage() . "\n";
    }
    echo "================================================\n\n";

    // Imprimir últimos trabajos
    echo "=== ULTIMOS 10 TRABAJOS DE GENERACION ===\n";
    $stmt = $pdo->query('SELECT id, status, error, updated_at, mockup_file, prompt_file FROM mockup_generation_jobs ORDER BY id DESC LIMIT 10');
    $jobs = $stmt->fetchAll();
    
    if (empty($jobs)) {
        echo "No se encontraron trabajos de generación en la base de datos MySQL.\n";
    } else {
        foreach ($jobs as $job) {
            echo "ID de Trabajo: {$job['id']}\n";
            echo "Estado: {$job['status']}\n";
            echo "Actualizado: {$job['updated_at']}\n";
            echo "Mockup file: " . ($job['mockup_file'] ?: '[vacío]') . "\n";
            echo "Prompt file: " . ($job['prompt_file'] ?: '[vacío]') . "\n";
            if (!empty($job['mockup_file'])) {
                $mockupFile = basename((string)$job['mockup_file']);
                $localPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $mockupFile;
                echo "Existe en disco web: " . (is_file($localPath) ? "SI (" . filesize($localPath) . " bytes)" : "NO") . "\n";
                if ($gcsActive) {
                    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'check_jobs_' . $mockupFile;
                    $downloaded = StorageService::downloadFile('results/' . $mockupFile, $tmpPath);
                    echo "Existe en GCS results/: " . ($downloaded && is_file($tmpPath) ? "SI (" . filesize($tmpPath) . " bytes)" : "NO") . "\n";
                    if (is_file($tmpPath)) {
                        @unlink($tmpPath);
                    }
                }
                echo "Media URL: media.php?file=" . rawurlencode($mockupFile) . "\n";
            }
            if (!empty($job['error'])) {
                echo "Error: {$job['error']}\n";
            } else {
                echo "Error: [Ninguno]\n";
            }
            echo "--------------------------------------------------\n";
        }
    }
} catch (Throwable $e) {
    echo "Error consultando la base de datos: " . $e->getMessage() . "\n";
}
