<?php
declare(strict_types=1);

ini_set('memory_limit', '768M');
ini_set('max_execution_time', '900');

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();

function complete_root_views_store_candidates(PDO $pdo, int $artworkId, int $userId, string $jobId, array $files, string $selectedRootFile = ''): void
{
    $viewMap = [
        1 => 'frontal',
        2 => 'three-quarter-left',
        3 => 'three-quarter-right',
    ];

    Database::withBusyRetry(function () use ($pdo, $artworkId, $userId, $jobId, $files, $selectedRootFile, $viewMap): void {
        if (Database::isMysql()) {
            $columnRows = $pdo->query('SHOW COLUMNS FROM root_artwork_candidates')->fetchAll(PDO::FETCH_ASSOC);
            $columns = array_map(static fn(array $row): string => (string)$row['Field'], $columnRows);
        } else {
            $columnRows = $pdo->query('PRAGMA table_info(root_artwork_candidates)')->fetchAll(PDO::FETCH_ASSOC);
            $columns = array_map(static fn(array $row): string => (string)$row['name'], $columnRows);
        }
        $hasColumn = static fn(string $name): bool => in_array($name, $columns, true);
        $existsStmt = $pdo->prepare('
            SELECT COUNT(*)
            FROM root_artwork_candidates
            WHERE artwork_id = :artwork_id
            AND file_name = :file_name
        ');

        foreach (array_values($files) as $index => $fileValue) {
            $file = basename((string)$fileValue);
            $version = $index + 1;
            if (preg_match('/_v(\d+)\.(?:png|jpe?g|webp)$/i', $file, $matches) === 1) {
                $version = (int)$matches[1];
            }
            if ($file === '' || !isset($viewMap[$version]) || !is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
                continue;
            }

            $existsStmt->execute([
                'artwork_id' => $artworkId,
                'file_name' => $file,
            ]);
            if ((int)$existsStmt->fetchColumn() > 0) {
                continue;
            }

            $insertColumns = ['artwork_id', 'file_name', 'view_type', 'is_selected', 'created_at'];
            $params = [
                'artwork_id' => $artworkId,
                'file_name' => $file,
                'view_type' => $viewMap[$version],
                'is_selected' => $file === $selectedRootFile ? 1 : 0,
                'created_at' => date('c'),
            ];
            if ($hasColumn('user_id')) {
                $insertColumns[] = 'user_id';
                $params['user_id'] = $userId;
            }
            if ($hasColumn('job_id')) {
                $insertColumns[] = 'job_id';
                $params['job_id'] = $jobId;
            }
            if ($hasColumn('updated_at')) {
                $insertColumns[] = 'updated_at';
                $params['updated_at'] = date('c');
            }
            $placeholders = array_map(static fn(string $column): string => ':' . $column, $insertColumns);
            $sql = 'INSERT INTO root_artwork_candidates (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $pdo->prepare($sql)->execute($params);
        }
    }, 12);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

$artworkId = max(0, (int)($_POST['artwork_id'] ?? 0));
if ($artworkId <= 0) {
    http_response_code(400);
    echo 'Missing artwork.';
    exit;
}

$pdo = Database::connection();
$stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
$stmt->execute([
    'id' => $artworkId,
    'user_id' => (int)$user['id'],
]);
$artwork = $stmt->fetch();

if (!is_array($artwork)) {
    http_response_code(404);
    echo 'Artwork not found.';
    exit;
}

$sourceFile = basename((string)($artwork['root_file'] ?: $artwork['main_file'] ?? ''));
$sourcePath = '';
foreach ([
    RESULTS_DIR . DIRECTORY_SEPARATOR . $sourceFile,
    __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $sourceFile,
] as $candidatePath) {
    if ($sourceFile !== '' && is_file($candidatePath)) {
        $sourcePath = $candidatePath;
        break;
    }
}

if ($sourcePath === '') {
    http_response_code(400);
    echo 'No source image is available for this artwork.';
    exit;
}

$jobsDir = __DIR__ . DIRECTORY_SEPARATOR . 'jobs';
if (!is_dir($jobsDir) && !mkdir($jobsDir, 0775, true) && !is_dir($jobsDir)) {
    http_response_code(500);
    echo 'Could not create jobs directory.';
    exit;
}

$jobId = 'complete_root_views_' . $artworkId . '_' . time() . '_' . random_int(1000, 9999);
$jobDir = $jobsDir . DIRECTORY_SEPARATOR . $jobId;
if (!mkdir($jobDir, 0775, true)) {
    http_response_code(500);
    echo 'Could not create root completion job.';
    exit;
}

$ext = strtolower(pathinfo($sourceFile, PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
    $ext = 'png';
}
$mainFile = 'main_artwork.' . $ext;
if (!copy($sourcePath, $jobDir . DIRECTORY_SEPARATOR . $mainFile)) {
    http_response_code(500);
    echo 'Could not stage source image.';
    exit;
}

$measurements = [
    'width' => (string)($artwork['width'] ?? ''),
    'height' => (string)($artwork['height'] ?? ''),
    'depth' => (string)($artwork['depth'] ?? ''),
    'unit' => (string)($artwork['unit'] ?? 'cm'),
];
$now = date('c');
$status = [
    'ok' => true,
    'job_id' => $jobId,
    'artwork_id' => $artworkId,
    'status' => 'processing',
    'created_at' => $now,
    'updated_at' => $now,
    'message' => 'Completing missing root artwork views from an existing upload.',
    'main_file' => $mainFile,
    'extra_files' => [],
    'candidates' => [],
    'result_file' => null,
    'error' => null,
    'measurements' => $measurements,
    'artist_notes' => '',
    'provider_settings' => ProviderSettings::all(),
    'user_id' => (int)$user['id'],
    'root_source' => 'completed_from_existing_root',
];
file_put_contents(
    $jobDir . DIRECTORY_SEPARATOR . 'status.json',
    json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

try {
    ProviderSettings::set($status['provider_settings']);
    $result = ServiceFactory::artworkProcessor()->createRootImage($jobDir, $status);
    $files = array_values(array_map('basename', (array)($result['files'] ?? [])));
    if (!$files) {
        throw new RuntimeException('No root view candidates were generated.');
    }

    $status['status'] = 'done';
    $status['updated_at'] = date('c');
    $status['message'] = 'Root view candidates created. Choose the best official root.';
    $status['candidates'] = $files;
    file_put_contents(
        $jobDir . DIRECTORY_SEPARATOR . 'status.json',
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    complete_root_views_store_candidates($pdo, $artworkId, (int)$user['id'], $jobId, $files, basename((string)($artwork['root_file'] ?? '')));

    $pdo->prepare('UPDATE artworks SET job_id = :job_id, status = :status, updated_at = :updated_at WHERE id = :id AND user_id = :user_id')
        ->execute([
            'job_id' => $jobId,
            'status' => 'done',
            'updated_at' => date('c'),
            'id' => $artworkId,
            'user_id' => (int)$user['id'],
        ]);

    header('Location: root_select.php?job=' . rawurlencode($jobId));
    exit;
} catch (Throwable $e) {
    $status['status'] = 'error';
    $status['updated_at'] = date('c');
    $status['error'] = $e->getMessage();
    file_put_contents(
        $jobDir . DIRECTORY_SEPARATOR . 'status.json',
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    header('Location: artwork.php?id=' . rawurlencode((string)$artworkId) . '&root_views_error=' . rawurlencode($e->getMessage()));
    exit;
}
