<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = Auth::requireUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$jobId = basename((string)($_POST['job'] ?? ''));
$filename = basename((string)($_POST['filename'] ?? ''));

if ($jobId === '' || $filename === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing job ID or filename.']);
    exit;
}

$jobDir = __DIR__ . '/jobs/' . $jobId;
$statusFile = $jobDir . '/status.json';

if (!is_file($statusFile)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Job status file not found.']);
    exit;
}

$status = json_decode((string)file_get_contents($statusFile), true);

if (!is_array($status)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not parse job status.']);
    exit;
}

// Check authorization
if ((int)($status['user_id'] ?? 0) !== (int)$currentUser['id']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied.']);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Verify the selected filename is a valid candidate for this job
$candidates = $status['candidates'] ?? [];
if (!in_array($filename, $candidates, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid candidate filename.']);
    exit;
}

try {
    $db = Database::connection();
    $artworkId = find_or_recover_artwork_record($db, $jobId, $status);

    if ($artworkId === null) {
        throw new RuntimeException('Artwork record not found in database.');
    }

    $selectedPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($selectedPath)) {
        throw new RuntimeException('Selected candidate file does not exist on disk.');
    }

    $db->prepare('UPDATE artworks SET status = :status, root_file = :root_file, updated_at = :now WHERE id = :id')
        ->execute([
            'status' => 'done',
            'root_file' => $filename,
            'now' => date('c'),
            'id' => $artworkId
        ]);

    $measurements = $status['measurements'] ?? [];

    // Save metadata json file
    $metaName = pathinfo($filename, PATHINFO_FILENAME) . '.meta.json';
    $metaPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $metaName;
    file_put_contents(
        $metaPath,
        json_encode([
            'source_job_id' => $jobId,
            'user_id' => (int)$status['user_id'],
            'root_file' => $filename,
            'measurements' => $measurements,
            'artist_notes' => $status['artist_notes'] ?? '',
            'provider_settings' => ProviderSettings::all(),
            'scale_text' => build_scale_text_for_meta_select($measurements),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    // Update status.json
    $status['result_file'] = $filename;
    file_put_contents(
        $statusFile,
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    // Update SQLite database to done and set root_file
    $stmt = $db->prepare('UPDATE artworks SET status = :status, root_file = :root_file, updated_at = :now WHERE id = :id');
    $stmt->execute([
        'status' => 'done',
        'root_file' => $filename,
        'now' => date('c'),
        'id' => $artworkId
    ]);

    persist_root_artwork_candidates($db, $artworkId, (array)$candidates, $filename);

    $redirect = 'mockup_combinations_review.php?id=' . $artworkId . '&world_mother_category=selected';

    echo json_encode([
        'ok' => true,
        'redirect' => $redirect
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

function build_scale_text_for_meta_select(array $measurements): string
{
    $width = trim((string)($measurements['width'] ?? ''));
    $height = trim((string)($measurements['height'] ?? ''));
    $depth = trim((string)($measurements['depth'] ?? ''));
    $unit = trim((string)($measurements['unit'] ?? 'cm'));

    if ($width === '' || $height === '') {
        return 'No physical artwork size was provided. Keep scale plausible for the visible artwork proportions.';
    }

    $text = "The real physical artwork measures {$width} {$unit} wide x {$height} {$unit} high.";
    $text .= " These measurements refer only to the artwork, not to the photo, wall, furniture, background or surrounding objects.";
    $text .= " In mockups, scale the artwork realistically relative to architecture, furniture and human figures.";

    if ($depth !== '') {
        $text .= " Physical stretcher/support depth: {$depth} {$unit}.";
    }

    return $text;
}

function find_or_recover_artwork_record(PDO $db, string $jobId, array $status): ?int
{
    $stmtArtwork = $db->prepare("SELECT id FROM artworks WHERE job_id = :job_id LIMIT 1");
    $stmtArtwork->execute(['job_id' => $jobId]);
    $artworkId = $stmtArtwork->fetchColumn();

    if ($artworkId) {
        return (int)$artworkId;
    }

    $userId = (int)($status['user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    $measurements = is_array($status['measurements'] ?? null) ? $status['measurements'] : [];
    $now = date('c');

    try {
        $stmt = $db->prepare("
            INSERT INTO artworks (user_id, job_id, main_file, status, width, height, depth, unit, created_at, updated_at)
            VALUES (:user_id, :job_id, :main_file, :status, :width, :height, :depth, :unit, :created_at, :updated_at)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'job_id' => $jobId,
            'main_file' => basename((string)($status['main_file'] ?? '')),
            'status' => 'awaiting_selection',
            'width' => (string)($measurements['width'] ?? ''),
            'height' => (string)($measurements['height'] ?? ''),
            'depth' => (string)($measurements['depth'] ?? ''),
            'unit' => (string)($measurements['unit'] ?? 'cm'),
            'created_at' => (string)($status['created_at'] ?? $now),
            'updated_at' => $now,
        ]);

        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        $stmtArtwork->execute(['job_id' => $jobId]);
        $artworkId = $stmtArtwork->fetchColumn();
        return $artworkId ? (int)$artworkId : null;
    }
}

function persist_root_artwork_candidates(PDO $db, int $artworkId, array $candidates, string $selectedFile): void
{
    $viewTypes = [
        0 => 'frontal',
        1 => 'three-quarter-left',
        2 => 'three-quarter-right',
    ];

    Database::withBusyRetry(function () use ($db, $artworkId, $candidates, $selectedFile, $viewTypes): void {
        $artworkStmt = $db->prepare('SELECT user_id, job_id FROM artworks WHERE id = :id LIMIT 1');
        $artworkStmt->execute(['id' => $artworkId]);
        $artwork = $artworkStmt->fetch() ?: [];

        $columnRows = Database::isMysql()
            ? $db->query('SHOW COLUMNS FROM root_artwork_candidates')->fetchAll()
            : $db->query('PRAGMA table_info(root_artwork_candidates)')->fetchAll();
        $columnsAvailable = array_map(
            static fn(array $row): string => (string)($row['Field'] ?? $row['name'] ?? ''),
            $columnRows
        );

        $db->prepare('DELETE FROM root_artwork_candidates WHERE artwork_id = :artwork_id')
            ->execute(['artwork_id' => $artworkId]);

        $insertColumns = ['artwork_id', 'file_name', 'view_type', 'is_selected', 'created_at'];
        if (in_array('user_id', $columnsAvailable, true)) {
            array_unshift($insertColumns, 'user_id');
        }
        if (in_array('job_id', $columnsAvailable, true)) {
            $insertColumns[] = 'job_id';
        }
        if (in_array('updated_at', $columnsAvailable, true)) {
            $insertColumns[] = 'updated_at';
        }
        $stmt = $db->prepare(sprintf(
            'INSERT INTO root_artwork_candidates (%s) VALUES (%s)',
            implode(', ', $insertColumns),
            implode(', ', array_map(static fn(string $column): string => ':' . $column, $insertColumns))
        ));
        $now = date('c');
        foreach (array_values($candidates) as $index => $candidate) {
            $file = basename((string)$candidate);
            if ($file === '') {
                continue;
            }
            $payload = [
                'artwork_id' => $artworkId,
                'file_name' => $file,
                'view_type' => $viewTypes[$index] ?? 'frontal',
                'is_selected' => $file === $selectedFile ? 1 : 0,
                'created_at' => $now,
            ];
            if (in_array('user_id', $insertColumns, true)) {
                $payload['user_id'] = (int)($artwork['user_id'] ?? 0);
            }
            if (in_array('job_id', $insertColumns, true)) {
                $payload['job_id'] = (string)($artwork['job_id'] ?? '');
            }
            if (in_array('updated_at', $insertColumns, true)) {
                $payload['updated_at'] = $now;
            }
            $stmt->execute($payload);
        }
    }, 12);
}

function start_mockup_queue_workers(int $artworkId, int $limit, int $workerCount): void
{
    // Legacy fallback: used when job IDs are not pre-assigned.
    $workerCount = max(1, min(8, $workerCount));

    for ($i = 0; $i < $workerCount; $i++) {
        start_mockup_queue_worker($artworkId, $limit);
    }
}

function start_mockup_queue_worker(int $artworkId, int $limit): void
{
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'process_mockup_queue.php';
    $php = resolve_php_binary_for_worker();

    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . (int)$artworkId . ' ' . (int)$limit . ' > NUL 2>&1';
        @pclose(@popen($cmd, 'r'));
        return;
    }

    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . (int)$artworkId . ' ' . (int)$limit . ' > /dev/null 2>&1 &';
    @exec($cmd);
}

/**
 * Launch one worker bound to a specific pre-assigned job ID.
 * The worker will process only that job — no claimNext() race condition.
 */
function start_mockup_queue_worker_for_job(int $jobId): void
{
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'process_mockup_queue.php';
    $php = resolve_php_binary_for_worker();

    if (PHP_OS_FAMILY === 'Windows') {
        // Pass job_id as 4th argument: php process_mockup_queue.php 0 1 {jobId}
        // artworkId=0 limit=1 means fallback, but jobId overrides both.
        $cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' 0 1 ' . (int)$jobId . ' > NUL 2>&1';
        @pclose(@popen($cmd, 'r'));
        return;
    }

    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script)
        . ' 0 1 ' . (int)$jobId . ' > /dev/null 2>&1 &';
    @exec($cmd);
}

function resolve_php_binary_for_worker(): string
{
    if (is_file(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') !== false) {
        return PHP_BINARY;
    }

    $laragonMatches = glob('C:\\laragon\\bin\\php\\*\\php.exe') ?: [];
    if (!empty($laragonMatches) && is_file((string)$laragonMatches[0])) {
        return (string)$laragonMatches[0];
    }

    return 'php';
}
