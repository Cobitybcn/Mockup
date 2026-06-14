<?php
declare(strict_types=1);

/**
 * cleanup_jobs.php
 * ----------------
 * Script de mantenimiento para limpiar directorios de jobs antiguos.
 * Seguro para ejecutar desde CLI o desde el panel de admin (POST autenticado).
 *
 * Reglas:
 * - Solo borra jobs con status "done" o "error".
 * - Nunca borra jobs con status "queued" o "processing".
 * - Borra únicamente jobs con más de $maxAgedays días de antigüedad.
 */

require_once __DIR__ . '/app/bootstrap.php';

$isCli  = PHP_SAPI === 'cli';
$isAjax = !$isCli && (
    strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest' ||
    (string)($_POST['ajax'] ?? '') === '1'
);

// Si es HTTP, requiere admin autenticado y POST
if (!$isCli) {
    $user = Auth::requireUser();

    if (!Auth::isAdmin($user)) {
        http_response_code(403);
        if ($isAjax) {
            echo json_encode(['ok' => false, 'error' => 'Acceso denegado.']);
        } else {
            die('Acceso denegado.');
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        die('Método no permitido.');
    }
}

$maxAgeDays = max(1, (int)($_POST['max_age_days'] ?? 30));
$jobsDir    = __DIR__ . '/jobs';
$cutoff     = time() - ($maxAgeDays * 86400);

$stats = [
    'deleted'   => 0,
    'skipped'   => 0,
    'protected' => 0,
    'errors'    => 0,
    'total'     => 0,
];

$details = [];

if (!is_dir($jobsDir)) {
    $result = ['ok' => true, 'message' => 'No hay directorio de jobs.', 'stats' => $stats];
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
    } elseif ($isCli) {
        echo "No hay directorio de jobs.\n";
    } else {
        echo json_encode($result);
    }
    exit;
}

$entries = scandir($jobsDir);

foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }

    $jobPath = $jobsDir . DIRECTORY_SEPARATOR . $entry;

    if (!is_dir($jobPath)) {
        continue;
    }

    $stats['total']++;

    $statusFile = $jobPath . DIRECTORY_SEPARATOR . 'status.json';

    if (!is_file($statusFile)) {
        // Sin status.json → probablemente corrupto, pero no borramos sin verificar edad
        $mtime = @filemtime($jobPath) ?: 0;
        if ($mtime < $cutoff) {
            if (deleteDir($jobPath)) {
                $stats['deleted']++;
                $details[] = "Deleted (no status): $entry";
            } else {
                $stats['errors']++;
            }
        } else {
            $stats['skipped']++;
        }
        continue;
    }

    $statusData = json_decode((string)file_get_contents($statusFile), true);
    $jobStatus  = (string)($statusData['status'] ?? 'unknown');
    $createdAt  = (string)($statusData['created_at'] ?? '');
    $createdTs  = $createdAt !== '' ? (int)strtotime($createdAt) : (@filemtime($statusFile) ?: 0);

    // Proteger jobs activos
    if (in_array($jobStatus, ['queued', 'processing'], true)) {
        $stats['protected']++;
        $details[] = "Protected (active): $entry [$jobStatus]";
        continue;
    }

    // Solo borrar terminados con suficiente antigüedad
    if ($createdTs >= $cutoff) {
        $stats['skipped']++;
        $details[] = "Skipped (too recent): $entry";
        continue;
    }

    if (deleteDir($jobPath)) {
        $stats['deleted']++;
        $details[] = "Deleted: $entry [$jobStatus, " . date('Y-m-d', $createdTs) . "]";
    } else {
        $stats['errors']++;
        $details[] = "Error deleting: $entry";
    }
}

function deleteDir(string $path): bool
{
    if (!is_dir($path)) {
        return false;
    }

    $items = scandir($path);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $full = $path . DIRECTORY_SEPARATOR . $item;

        if (is_dir($full)) {
            deleteDir($full);
        } else {
            @unlink($full);
        }
    }

    return @rmdir($path);
}

$summary = sprintf(
    'Jobs: %d total, %d deleted, %d protected (active), %d skipped (recent), %d errors.',
    $stats['total'],
    $stats['deleted'],
    $stats['protected'],
    $stats['skipped'],
    $stats['errors']
);

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'      => true,
        'summary' => $summary,
        'stats'   => $stats,
        'details' => $details,
    ]);
} elseif ($isCli) {
    echo $summary . "\n";
    foreach ($details as $line) {
        echo "  · $line\n";
    }
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'      => true,
        'summary' => $summary,
        'stats'   => $stats,
    ]);
}
