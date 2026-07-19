<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();
$isAdmin = Auth::isAdmin($user);
ArtworkSeries::ensureSchema($pdo);
ArtworkSeries::syncUser($pdo, (int)$user['id']);
$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$artworksPreviewActive = $id <= 0 && UiPreview::isActive($user, 'artworks-kpi');
$artwork = null;

if ($id > 0) {
    if ($isAdmin) {
        $stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute([
            'id' => $id,
            'user_id' => (int)$user['id'],
        ]);
    }
    $artwork = $stmt->fetch();
    if (!is_array($artwork)) {
        http_response_code(404);
        die('Artwork not found.');
    }
}

if ($id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'select_root_candidate') {
    $candidateId = max(0, (int)($_POST['candidate_id'] ?? 0));
    $candidateFile = basename((string)($_POST['candidate_file'] ?? ''));
    if ($candidateFile !== '' && root_album_file_exists($candidateFile)) {
        Database::withBusyRetry(function () use ($pdo, $id, $artwork, $candidateId, $candidateFile): void {
            $pdo->prepare('UPDATE root_artwork_candidates SET is_selected = 0 WHERE artwork_id = :artwork_id')
                ->execute(['artwork_id' => $id]);
            if ($candidateId > 0) {
                $pdo->prepare('UPDATE root_artwork_candidates SET is_selected = 1 WHERE id = :id AND artwork_id = :artwork_id')
                    ->execute(['id' => $candidateId, 'artwork_id' => $id]);
            }
            $pdo->prepare('UPDATE artworks SET root_file = :root_file, status = :status, updated_at = :updated_at WHERE id = :id AND user_id = :user_id')
                ->execute([
                    'root_file' => $candidateFile,
                    'status' => 'done',
                    'updated_at' => date('c'),
                    'id' => $id,
                    'user_id' => (int)($artwork['user_id'] ?? 0),
                ]);
        }, 12);
    }

    header('Location: root_album.php?id=' . rawurlencode((string)$id) . '&selected=1');
    exit;
}

function delete_artwork_job_assets(string $jobId): void
{
    $jobId = basename($jobId);

    if ($jobId === '' || !preg_match('/^job_[0-9]+_[0-9]+$/', $jobId)) {
        return;
    }

    $jobDir = __DIR__ . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . $jobId;
    if (is_dir($jobDir)) {
        $deleteDirFunc = function (string $dir) use (&$deleteDirFunc): bool {
            if (!is_dir($dir)) return false;
            $items = @scandir($dir);
            if (!is_array($items)) return false;
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($path)) {
                    $deleteDirFunc($path);
                } else {
                    @unlink($path);
                }
            }
            return @rmdir($dir);
        };
        $deleteDirFunc($jobDir);
    }

    $matchedFiles = glob(RESULTS_DIR . DIRECTORY_SEPARATOR . '*' . $jobId . '*');
    if (is_array($matchedFiles)) {
        foreach ($matchedFiles as $resFile) {
            if (is_file($resFile)) {
                @unlink($resFile);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id <= 0) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'clear_stuck') {
        $stmtJobs = $pdo->prepare("
            SELECT id, job_id, status, created_at
            FROM artworks
            WHERE user_id = :user_id
            AND status IN ('queued', 'processing', 'error')
        ");
        $stmtJobs->execute(['user_id' => $user['id']]);
        $candidates = $stmtJobs->fetchAll();

        $idsToDelete = [];
        $now = time();
        
        foreach ($candidates as $c) {
            $status = $c['status'];
            $createdAt = strtotime((string)$c['created_at']);
            
            if ($status === 'error' || ($now - $createdAt) > 300) {
                $idsToDelete[] = (int)$c['id'];
                $jobId = basename((string)$c['job_id']);
                delete_artwork_job_assets($jobId);
            }
        }

        if (!empty($idsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $stmtDelete = $pdo->prepare("DELETE FROM artworks WHERE id IN ($placeholders) AND user_id = ?");
            $params = array_merge($idsToDelete, [(int)$user['id']]);
            $stmtDelete->execute($params);
        }
        
        header('Location: root_album.php');
        exit;
    }

    if ($action === 'discard_pending') {
        $artworkId = (int)$_POST['artwork_id'];
        if ($artworkId > 0) {
            $stmtPending = $pdo->prepare("
                SELECT id, job_id
                FROM artworks
                WHERE id = :id
                AND user_id = :user_id
                AND status != 'done'
                LIMIT 1
            ");
            $stmtPending->execute([
                'id' => $artworkId,
                'user_id' => (int)$user['id'],
            ]);
            $pending = $stmtPending->fetch();

            if ($pending) {
                delete_artwork_job_assets((string)($pending['job_id'] ?? ''));
                $stmtDelete = $pdo->prepare("DELETE FROM artworks WHERE id = :id AND user_id = :user_id AND status != 'done'");
                $stmtDelete->execute([
                    'id' => $artworkId,
                    'user_id' => (int)$user['id'],
                ]);
            }
        }

        header('Location: root_album.php');
        exit;
    }

    if ($action === 'discard_all_pending') {
        $stmtPending = $pdo->prepare("
            SELECT id, job_id
            FROM artworks
            WHERE user_id = :user_id
            AND status != 'done'
        ");
        $stmtPending->execute(['user_id' => (int)$user['id']]);
        $pendingRows = $stmtPending->fetchAll();

        foreach ($pendingRows as $pending) {
            delete_artwork_job_assets((string)($pending['job_id'] ?? ''));
        }

        $stmtDelete = $pdo->prepare("DELETE FROM artworks WHERE user_id = :user_id AND status != 'done'");
        $stmtDelete->execute(['user_id' => (int)$user['id']]);

        header('Location: root_album.php');
        exit;
    }
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function root_album_label(string $viewType): string
{
    return [
        'frontal' => 'Frontal',
        'three-quarter-left' => '3/4 Left',
        'three-quarter-right' => '3/4 Right',
    ][$viewType] ?? ucwords(str_replace(['-', '_'], ' ', $viewType));
}

function root_album_normalized_view_type(string $viewType): string
{
    $value = strtolower(trim($viewType));
    $value = str_replace(['_', ' '], '-', $value);

    if (in_array($value, ['front', 'frontal', 'straight', 'straight-on'], true)) {
        return 'frontal';
    }
    if (in_array($value, ['three-quarter-left', '3-4-left', '3/4-left', 'left'], true)) {
        return 'three-quarter-left';
    }
    if (in_array($value, ['three-quarter-right', '3-4-right', '3/4-right', 'right'], true)) {
        return 'three-quarter-right';
    }

    return $value;
}

function root_album_media_url(string $file): string
{
    return 'media.php?file=' . rawurlencode(basename($file)) . '&thumb=1&w=520';
}

function root_album_file_exists(string $file): bool
{
    $file = basename($file);
    if ($file === '') {
        return false;
    }

    if (is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
        return true;
    }

    if (!StorageService::isGcsActive()) {
        return false;
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file) ?: 'root-image';
    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'root_album_' . $safeName;
    $exists = StorageService::downloadFile('results/' . $file, $tmpPath);
    if (is_file($tmpPath)) {
        @unlink($tmpPath);
    }

    return $exists;
}

function root_album_sibling_root_candidates(string $rootFile): array
{
    $rootFile = basename($rootFile);
    if (!preg_match('/^(.*)_v(\d+)(\.[A-Za-z0-9]+)$/', $rootFile, $matches)) {
        return [];
    }

    $labels = [
        1 => 'frontal',
        2 => 'three-quarter-left',
        3 => 'three-quarter-right',
    ];
    $candidates = [];
    for ($version = 1; $version <= 3; $version++) {
        $file = $matches[1] . '_v' . $version . $matches[3];
        if (!root_album_file_exists($file)) {
            continue;
        }
        $candidates[] = [
            'id' => 0,
            'file_name' => $file,
            'view_type' => $labels[$version] ?? 'frontal',
            'is_selected' => $file === $rootFile,
        ];
    }

    return $candidates;
}

$albumArtworks = [];
$candidates = [];
$missing = [];
$albumLoadError = '';
$rootTotal = 0;
$mockupTotal = 0;
$variantRootTotal = 0;
$pendingArtworks = [];
$artworkMergeCsrf = '';
$artworkSeriesRows = [];
$selectedArtworkSeriesId = max(0, (int)($_GET['series'] ?? 0));
$selectedArtworkSeries = null;
if ($id <= 0) {
    if (empty($_SESSION['artwork_merge_csrf'])) {
        $_SESSION['artwork_merge_csrf'] = bin2hex(random_bytes(24));
    }
    if (empty($_SESSION['series_csrf'])) {
        $_SESSION['series_csrf'] = bin2hex(random_bytes(24));
    }
    $artworkMergeCsrf = (string)$_SESSION['artwork_merge_csrf'];
    $artworkSeriesRows = ArtworkSeries::seriesList($pdo, (int)$user['id']);
    foreach ($artworkSeriesRows as $seriesRow) {
        if ((int)$seriesRow['id'] === $selectedArtworkSeriesId) {
            $selectedArtworkSeries = $seriesRow;
            break;
        }
    }
    if (!$selectedArtworkSeries) {
        $selectedArtworkSeriesId = 0;
    }
}

if ($id <= 0) {
    try {
        (new ArtworkGroupService($pdo))->syncUser((int)$user['id']);

        $groupSql = "
            SELECT g.id AS group_id,
                   g.canonical_artwork_id,
                   g.official_root_artwork_ids,
                   g.title AS group_title,
                   g.updated_at,
                   g.created_at,
                   a.root_file,
                   a.final_title,
                   a.subtitle,
                   a.series,
                   a.series_id,
                   a.series_creation_number,
                   s.title AS series_title,
                   a.width,
                   a.height,
                   a.unit,
                   COUNT(DISTINCT roots.id) AS root_count,
                   SUM(CASE WHEN roots.root_view_status = 'official' THEN 1 ELSE 0 END) AS official_count,
                   SUM(CASE WHEN roots.root_view_status = 'variant' THEN 1 ELSE 0 END) AS variant_count,
                   COUNT(DISTINCT m.id) AS mockup_count
            FROM artwork_groups g
            INNER JOIN artworks a ON a.id = g.canonical_artwork_id
            LEFT JOIN artwork_series s ON s.id = a.series_id AND s.user_id = a.user_id
            LEFT JOIN artworks roots ON roots.artwork_group_id = g.id AND roots.user_id = g.user_id
            LEFT JOIN mockups m ON m.artwork_group_id = g.id AND m.user_id = g.user_id
            WHERE g.status = 'active'
        ";
        $groupSql .= " AND g.user_id = :user_id";
        $groupParams = ['user_id' => (int)$user['id']];
        if ($selectedArtworkSeriesId > 0) {
            $groupSql .= " AND a.series_id = :series_id";
            $groupParams['series_id'] = $selectedArtworkSeriesId;
        }
        $groupSql .= "
            GROUP BY g.id, g.canonical_artwork_id, g.official_root_artwork_ids, g.title, g.updated_at, g.created_at,
                     a.root_file, a.final_title, a.subtitle, a.series, a.series_id, a.series_creation_number,
                     s.title, a.width, a.height, a.unit
        ";
        if ($selectedArtworkSeriesId > 0) {
            $groupSql .= " ORDER BY
                CASE WHEN a.series_creation_number IS NULL THEN 1 ELSE 0 END ASC,
                a.series_creation_number DESC,
                g.created_at DESC,
                g.id DESC";
        } else {
            $groupSql .= " ORDER BY g.updated_at DESC, g.created_at DESC, g.id DESC";
        }

        $groupStmt = $pdo->prepare($groupSql);
        $groupStmt->execute($groupParams);
        foreach ($groupStmt->fetchAll() as $row) {
            $file = basename((string)($row['root_file'] ?? ''));
            if ($file === '' || !root_album_file_exists($file)) {
                continue;
            }
            $row['root_file'] = $file;
            $row['id'] = (int)$row['canonical_artwork_id'];
            $row['source'] = 'artwork_groups';
            $albumArtworks[] = $row;
        }

        // Get totals for stats header
        $rootCountSql = "SELECT COUNT(*) FROM artwork_groups WHERE status = 'active' AND user_id = :user_id";
        $rootParams = ['user_id' => (int)$user['id']];
        $rootCountStmt = $pdo->prepare($rootCountSql);
        $rootCountStmt->execute($rootParams);
        $rootTotal = (int)$rootCountStmt->fetchColumn();

        $variantCountSql = "SELECT COUNT(*) FROM artworks WHERE artwork_group_id IS NOT NULL AND root_view_status = 'variant' AND user_id = :user_id";
        $variantParams = ['user_id' => (int)$user['id']];
        $variantCountStmt = $pdo->prepare($variantCountSql);
        $variantCountStmt->execute($variantParams);
        $variantRootTotal = (int)$variantCountStmt->fetchColumn();

        $mockupCountSql = "SELECT COUNT(*) FROM mockups WHERE user_id = :user_id";
        $mockupParams = ['user_id' => (int)$user['id']];
        $mockupCountStmt = $pdo->prepare($mockupCountSql);
        $mockupCountStmt->execute($mockupParams);
        $mockupTotal = (int)$mockupCountStmt->fetchColumn();

        $pendingSql = "
            SELECT *
            FROM artworks
            WHERE (status != 'done' OR root_file IS NULL OR root_file = '')
        ";
        $pendingSql .= " AND user_id = :user_id";
        $pendingParams = ['user_id' => (int)$user['id']];
        $pendingSql .= " ORDER BY created_at DESC";
        $pendingStmt = $pdo->prepare($pendingSql);
        $pendingStmt->execute($pendingParams);
        $pendingArtworks = $pendingStmt->fetchAll();
    } catch (Throwable $e) {
        $albumLoadError = $e->getMessage();
    }
} else {
    $candidateStmt = $pdo->prepare('
        SELECT id, file_name, view_type, is_selected
        FROM root_artwork_candidates
        WHERE artwork_id = :artwork_id
        ORDER BY id ASC
    ');
    $candidateStmt->execute(['artwork_id' => $id]);
    foreach ($candidateStmt->fetchAll() as $candidate) {
        $file = basename((string)($candidate['file_name'] ?? ''));
        if ($file === '' || !root_album_file_exists($file)) {
            continue;
        }
        $candidates[] = [
            'id' => (int)$candidate['id'],
            'file_name' => $file,
            'view_type' => (string)($candidate['view_type'] ?? 'frontal'),
            'is_selected' => (bool)$candidate['is_selected'] || $file === basename((string)($artwork['root_file'] ?? '')),
        ];
    }

    if (!$candidates) {
        $rootFile = basename((string)($artwork['root_file'] ?? ''));
        if ($rootFile !== '' && root_album_file_exists($rootFile)) {
            $candidates[] = [
                'id' => 0,
                'file_name' => $rootFile,
                'view_type' => 'frontal',
                'is_selected' => true,
            ];
        }
    }

    $rootFile = basename((string)($artwork['root_file'] ?? ''));
    $knownCandidateFiles = [];
    foreach ($candidates as $candidate) {
        $knownCandidateFiles[(string)$candidate['file_name']] = true;
    }
    foreach (root_album_sibling_root_candidates($rootFile) as $siblingCandidate) {
        if (isset($knownCandidateFiles[(string)$siblingCandidate['file_name']])) {
            continue;
        }
        $candidates[] = $siblingCandidate;
        $knownCandidateFiles[(string)$siblingCandidate['file_name']] = true;
    }

    $available = [];
    foreach ($candidates as $candidate) {
        $available[root_album_normalized_view_type((string)$candidate['view_type'])] = true;
    }
    foreach (['frontal', 'three-quarter-left', 'three-quarter-right'] as $viewType) {
        if (empty($available[$viewType])) {
            $missing[] = root_album_label($viewType);
        }
    }
    if (count($candidates) >= 3) {
        $missing = [];
    }
}

function root_album_adopt_root_artwork(PDO $pdo, int $userId, string $rootFile): array
{
    $rootFile = basename($rootFile);
    $jobId = 'adopted_root_' . $userId . '_' . substr(sha1($rootFile), 0, 16);

    $stmt = $pdo->prepare('SELECT id, final_title, subtitle, width, height, unit FROM artworks WHERE user_id = :user_id AND job_id = :job_id LIMIT 1');
    $stmt->execute([
        'user_id' => $userId,
        'job_id' => $jobId,
    ]);
    $existing = $stmt->fetch();
    if (is_array($existing)) {
        return $existing;
    }

    $now = date('c');
    Database::withBusyRetry(function () use ($pdo, $userId, $jobId, $rootFile, $now): void {
        $stmt = $pdo->prepare('
            INSERT INTO artworks (user_id, job_id, main_file, root_file, status, width, height, depth, unit, created_at, updated_at)
            VALUES (:user_id, :job_id, :main_file, :root_file, :status, :width, :height, :depth, :unit, :created_at, :updated_at)
        ');
        $stmt->execute([
            'user_id' => $userId,
            'job_id' => $jobId,
            'main_file' => $rootFile,
            'root_file' => $rootFile,
            'status' => 'done',
            'width' => '',
            'height' => '',
            'depth' => '',
            'unit' => 'cm',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }, 12);

    $stmt->execute([
        'user_id' => $userId,
        'job_id' => $jobId,
    ]);
    $created = $stmt->fetch();
    return is_array($created) ? $created : [
        'id' => 0,
        'final_title' => '',
        'subtitle' => '',
        'width' => '',
        'height' => '',
        'unit' => 'cm',
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Root Album - Artwork Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
    <style>
        .root-album-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }
        .root-album-series-filter {
            width: min(240px, 100%);
            margin-top: 12px;
        }
        .root-album-series-filter select {
            width: 100%;
            min-height: 33px;
            margin: 0;
            padding: 0 30px 0 10px;
            border-color: rgba(207, 199, 191, .72);
            border-radius: 5px;
            color: var(--muted);
            background-color: #faf8f5;
            font-size: 10px;
        }
        .root-album-card {
            position: relative;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 12px;
            box-shadow: var(--shadow);
        }
        .root-album-card > a {
            position: relative;
            display: block;
        }
        .root-album-card.series-order-chosen {
            border-color: rgba(154, 123, 86, .48);
            box-shadow: 0 10px 26px rgba(54, 42, 31, .12);
        }
        .root-album-card.is-selected {
            border-color: var(--accent);
            background: #fbf7ef;
        }
        .root-album-card img {
            display: block;
            width: 100%;
            aspect-ratio: 3 / 4;
            height: auto;
            object-fit: cover;
            border: 1px solid var(--line);
            border-radius: 3px;
            background: var(--surface-soft);
        }
        .root-album-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 10px;
        }
        .root-album-meta strong {
            font-size: 11px;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .root-album-meta span {
            color: var(--accent);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .root-album-card button {
            width: 100%;
            margin-top: 12px;
        }
        .artwork-delete-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 6;
            width: 36px !important;
            height: 36px !important;
            min-width: 36px !important;
            min-height: 36px !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 1px solid rgba(255, 255, 255, .56);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(24, 22, 21, .28);
            color: rgba(255, 255, 255, .9);
            box-shadow: 0 8px 22px rgba(22, 19, 18, .17);
            backdrop-filter: blur(9px) saturate(115%);
            opacity: .7;
            cursor: pointer;
            transition: opacity .16s ease, background .16s ease, border-color .16s ease;
        }
        .artwork-delete-btn svg {
            width: 16px;
            height: 16px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.4;
            stroke-linecap: round;
            stroke-linejoin: round;
            pointer-events: none;
        }
        .artwork-delete-btn:hover,
        .artwork-delete-btn:focus-visible {
            background: rgba(145, 85, 93, .84);
            border-color: rgba(255, 255, 255, .78);
            opacity: 1;
            outline: none;
        }
        .artwork-delete-btn[disabled] {
            cursor: wait;
            opacity: .5;
        }
        .artwork-merge-btn {
            position: absolute;
            top: 20px;
            right: 62px;
            z-index: 6;
            width: 36px !important;
            height: 36px !important;
            min-width: 36px !important;
            min-height: 36px !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 1px solid rgba(255, 255, 255, .7);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(89, 113, 87, .76);
            color: #fff;
            box-shadow: 0 8px 22px rgba(22, 19, 18, .17);
            backdrop-filter: blur(9px) saturate(115%);
            cursor: pointer;
            transition: background .16s ease, transform .16s ease;
        }
        .artwork-merge-btn:hover,
        .artwork-merge-btn:focus-visible { background: rgba(70, 96, 68, .94); outline: none; }
        .artwork-merge-btn svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; pointer-events: none; }
        .artwork-merge-btn[disabled] { cursor: wait; opacity: .5; }
        .merge-artwork-backdrop {
            position: fixed;
            inset: 0;
            z-index: 1500;
            display: grid;
            place-items: center;
            padding: 24px;
            background: rgba(32, 28, 25, .48);
            backdrop-filter: blur(3px);
        }
        .merge-artwork-backdrop[hidden] { display: none; }
        .merge-artwork-dialog {
            width: min(980px, 100%);
            max-height: min(880px, 92vh);
            overflow: auto;
            padding: 26px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--surface);
            box-shadow: 0 28px 90px rgba(26, 22, 19, .24);
        }
        .merge-artwork-kicker { display: block; margin-bottom: 7px; color: var(--accent); font-size: 9px; font-weight: 700; letter-spacing: .11em; text-transform: uppercase; }
        .merge-artwork-dialog h2 { margin: 0; font-family: var(--font-serif); font-size: 34px; font-weight: 400; }
        .merge-artwork-intro { max-width: 720px; margin: 8px 0 20px; color: var(--muted); font-size: 14px; line-height: 1.5; }
        .merge-artwork-search { width: 100%; min-height: 48px; margin-bottom: 14px; padding: 12px 14px; border: 1px solid var(--line); border-radius: 5px; background: var(--surface-soft); color: var(--ink); font-size: 15px; }
        .merge-artwork-picker { display: grid; grid-template-columns: repeat(auto-fill, minmax(145px, 1fr)); gap: 10px; max-height: 330px; overflow: auto; padding: 3px; }
        .merge-candidate {
            position: relative;
            width: 100%;
            margin: 0;
            padding: 8px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--surface-soft);
            color: var(--ink);
            text-align: left;
            cursor: pointer;
        }
        .merge-candidate:hover,
        .merge-candidate.is-selected { border-color: #9a7b56; box-shadow: 0 0 0 2px rgba(154, 123, 86, .12); }
        .merge-candidate.is-likely { order: -1; background: #f1f6ef; border-color: #b9ccb5; }
        .merge-candidate[hidden] { display: none; }
        .merge-candidate img { width: 100%; aspect-ratio: 3 / 4; object-fit: cover; border: 1px solid var(--line); border-radius: 4px; }
        .merge-candidate strong { display: block; margin-top: 7px; font-family: var(--font-serif); font-size: 16px; font-weight: 400; line-height: 1.15; }
        .merge-candidate small { display: block; margin-top: 4px; color: var(--muted); font-size: 10px; }
        .merge-likely-badge { display: none; position: absolute; top: 14px; left: 14px; padding: 4px 6px; border-radius: 3px; background: #e4eee1; color: #486245; font-size: 8px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .merge-candidate.is-likely .merge-likely-badge { display: block; }
        .merge-primary-panel { margin-top: 22px; padding-top: 20px; border-top: 1px solid var(--line); }
        .merge-primary-panel[hidden] { display: none; }
        .merge-primary-panel > strong { display: block; margin-bottom: 10px; font-size: 11px; letter-spacing: .07em; text-transform: uppercase; }
        .merge-primary-options { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .merge-primary-option { position: relative; display: grid; grid-template-columns: 90px 1fr; gap: 13px; align-items: center; padding: 10px; border: 1px solid var(--line); border-radius: 7px; background: #fff; cursor: pointer; }
        .merge-primary-option:has(input:checked) { border-color: #9a7b56; background: #fbf7ef; box-shadow: 0 0 0 2px rgba(154, 123, 86, .11); }
        .merge-primary-option input { position: absolute; top: 12px; right: 12px; }
        .merge-primary-option img { width: 90px; aspect-ratio: 3 / 4; object-fit: cover; border-radius: 4px; }
        .merge-primary-option strong { display: block; font-family: var(--font-serif); font-size: 19px; font-weight: 400; }
        .merge-primary-option span { display: block; margin-top: 5px; color: var(--muted); font-size: 11px; }
        .merge-preserves { margin: 16px 0 0; padding: 13px 15px; border: 1px solid #c8d7c3; border-radius: 6px; background: #f1f6ef; color: #486245; font-size: 12px; line-height: 1.45; }
        .merge-dialog-actions { display: grid; grid-template-columns: minmax(160px, .55fr) minmax(260px, 1fr); gap: 12px; margin-top: 20px; }
        .merge-dialog-actions button { min-height: 58px; margin: 0; }
        .merge-confirm { border-color: #c0a7aa !important; background: #e6cfd1 !important; color: #68464b !important; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .merge-confirm[disabled] { opacity: .45; cursor: not-allowed; }
        .merge-dialog-error { min-height: 18px; margin-top: 10px; color: var(--danger); font-size: 12px; }
        .root-album-title {
            margin: 10px 0 0;
            font-family: var(--font-serif);
            font-size: 20px;
            line-height: 1.2;
            color: var(--ink);
        }
        .root-album-subtitle {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 12px;
        }
        .root-album-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 10px;
        }
        .root-album-actions form {
            margin: 0;
        }
        .root-album-actions button,
        .root-album-actions .button-link {
            width: auto;
            margin: 0;
        }
        .mobile-root-slider {
            display: none;
        }
        @media (max-width: 760px) {
            .main-area > .app-header,
            .main-area > .alert-strip {
                display: none;
            }
            .workspace {
                padding: 22px 14px 32px;
            }
            .workspace-header {
                align-items: flex-start;
                margin-bottom: 16px;
            }
            .workspace-header h1 {
                font-size: clamp(34px, 11vw, 48px);
                line-height: .92;
                margin-bottom: 0;
            }
            .workspace-header p,
            .topbar-actions,
            .stats,
            .root-pending-panel {
                display: none !important;
            }
            .panel {
                padding: 0;
                border: 0;
                box-shadow: none;
                background: transparent;
            }
            .mobile-root-slider {
                display: grid;
                grid-auto-flow: column;
                grid-auto-columns: minmax(84%, 1fr);
                gap: 14px;
                overflow-x: auto;
                overscroll-behavior-x: contain;
                scroll-snap-type: x mandatory;
                -webkit-overflow-scrolling: touch;
                padding: 2px 2px 16px;
                margin: 0 -2px 18px;
                scrollbar-width: none;
                touch-action: pan-x;
            }
            .mobile-root-slider::-webkit-scrollbar {
                display: none;
            }
            .mobile-root-slide {
                position: relative;
                scroll-snap-align: center;
                background: #fffaf7;
                border: 1.5px solid #b77f86;
                border-radius: 8px;
                padding: 10px;
                box-shadow: 0 14px 34px rgba(83, 61, 43, .11);
                text-decoration: none;
                color: inherit;
            }
            .mobile-root-slide-link {
                display: block;
                color: inherit;
                text-decoration: none;
            }
            .mobile-root-slide .artwork-delete-btn,
            .root-album-card .artwork-delete-btn {
                top: 18px;
                right: 18px;
                width: 34px !important;
                height: 34px !important;
                min-width: 34px !important;
                min-height: 34px !important;
                opacity: .9;
            }
            .mobile-root-slide .artwork-merge-btn,
            .root-album-card .artwork-merge-btn {
                top: 18px;
                right: 58px;
                width: 34px !important;
                height: 34px !important;
                min-width: 34px !important;
                min-height: 34px !important;
            }
            .mobile-root-slide img {
                width: 100%;
                aspect-ratio: 3 / 4;
                object-fit: cover;
                display: block;
                border-radius: 6px;
                background: #f4f0eb;
            }
            .mobile-root-slide h2 {
                margin: 12px 0 5px;
                font: 700 13px/1.15 var(--font-sans);
                letter-spacing: .05em;
                text-transform: uppercase;
            }
            .mobile-root-slide p {
                margin: 0;
                color: var(--muted);
                font: 600 11px/1.45 var(--font-sans);
                letter-spacing: .02em;
            }
            .root-album-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }
            .root-album-card {
                padding: 0;
                border-radius: 7px;
                box-shadow: none;
                overflow: hidden;
                background: #fff;
            }
            .root-album-card img {
                aspect-ratio: 3 / 4;
                height: auto;
                object-fit: cover;
                border-radius: 0;
                margin-bottom: 0;
            }
            .root-album-title {
                margin: 8px 8px 2px;
                font: 700 11px/1.2 var(--font-sans);
                letter-spacing: .04em;
                text-transform: uppercase;
            }
            .root-album-subtitle,
            .root-album-card .button-link {
                display: none;
            }
            .merge-artwork-backdrop { padding: 10px; }
            .merge-artwork-dialog { max-height: 94vh; padding: 19px; }
            .merge-artwork-dialog h2 { font-size: 28px; }
            .merge-artwork-picker { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .merge-primary-options { grid-template-columns: 1fr; }
            .merge-dialog-actions { grid-template-columns: 1fr; }
        }
    </style>
    <link rel="stylesheet" href="media-controls.css?v=2">
    <?php if ($artworksPreviewActive): ?>
        <link rel="stylesheet" href="visual-consistency-preview.css?v=1">
    <?php endif; ?>
</head>
<body<?= $artworksPreviewActive ? ' class="ui-visual-consistency-preview" data-ui-preview="artworks-kpi"' : '' ?>>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <?php if ($id <= 0): ?>
            <div class="alert-strip">
                Artwork Mockups analyzes each artwork before generating mockups, helping artists choose visual environments that respect the work’s style, palette, composition and emotional atmosphere.
            </div>
        <?php endif; ?>
        <div class="workspace">
            <?php if ($id <= 0): ?>
                <?php if ($artworksPreviewActive): ?>
                    <aside class="ui-preview-notice" aria-label="Visual consistency preview">
                        <span><strong>Preview</strong> ArtWorks consistency</span>
                        <a href="root_album.php<?= $selectedArtworkSeriesId > 0 ? '?series=' . (int)$selectedArtworkSeriesId : '' ?>">Exit preview</a>
                    </aside>
                <?php endif; ?>
                <div class="workspace-header">
                    <div>
                        <h1>ArtWorks</h1>
                        <p>Canonical artworks with official root views and attached mockups.</p>
                        <form class="root-album-series-filter" method="get">
                            <?php if ($artworksPreviewActive): ?>
                                <input type="hidden" name="design_preview" value="artworks-kpi">
                            <?php endif; ?>
                            <select name="series" aria-label="Filter ArtWorks by series" onchange="this.form.submit()">
                                <option value="">All series</option>
                                <?php foreach ($artworkSeriesRows as $seriesRow): ?>
                                    <option value="<?= (int)$seriesRow['id'] ?>" <?= $selectedArtworkSeriesId === (int)$seriesRow['id'] ? 'selected' : '' ?>><?= h($seriesRow['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <div class="topbar-actions">
                        <a class="button-link" href="create_scenes.php">Create Art</a>
                        <a class="button-link secondary" href="account.php">Account</a>
                    </div>
                </div>

                <section class="stats" aria-label="ArtWorks summary">
                    <div class="stat-card" data-preview-counter="artworks">
                        <span>Artworks</span>
                        <strong><?= h($rootTotal) ?></strong>
                    </div>
                    <div class="stat-card" data-preview-counter="mockups">
                        <span>Mockups</span>
                        <strong><?= h($mockupTotal) ?></strong>
                    </div>
                    <div class="stat-card" data-preview-counter="variants">
                        <span>Variants</span>
                        <strong><?= h($variantRootTotal) ?></strong>
                    </div>
                    <div class="stat-card" data-preview-counter="credits">
                        <span>Credits</span>
                        <strong><?= h($user['credits']) ?></strong>
                    </div>
                </section>
            <?php else: ?>
                <div class="workspace-header">
                    <div>
                        <h1>Root Album</h1>
                        <p>Official root views for this artwork.</p>
                    </div>
                    <div class="root-album-actions">
                        <a class="button-link secondary" href="artwork_details.php?id=<?= (int)$id ?>">Artwork Details</a>
                        <?php if (!empty($missing)): ?>
                            <form method="post" action="complete_root_views.php" onsubmit="return confirm('Generate missing root views from the current root image?');">
                                <input type="hidden" name="artwork_id" value="<?= (int)$id ?>">
                                <button type="submit">Complete Root Views</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($id <= 0 && !empty($pendingArtworks)): ?>
                <section class="panel root-pending-panel" id="pendientes" style="border-left: 3px solid var(--accent); background: rgba(154, 123, 86, 0.02); margin-bottom: 30px;">
                    <div class="section-heading" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <div>
                            <h2 style="margin:0;">Pending Artworks & Selections</h2>
                            <p style="margin:4px 0 0; font-size:12px; color:var(--muted);"><?= count($pendingArtworks) ?> pieces requiring attention</p>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <?php
                            $hasClearable = false;
                            $now = time();
                            foreach ($pendingArtworks as $pending) {
                                $status = $pending['status'];
                                $createdAt = strtotime((string)$pending['created_at']);
                                if ($status === 'error' || (($status === 'queued' || $status === 'processing') && ($now - $createdAt) > 300)) {
                                    $hasClearable = true;
                                    break;
                                }
                            }
                            if ($hasClearable):
                            ?>
                                <form method="post" onsubmit="return confirm('Are you sure you want to clean all stuck or failed uploads? Uploads started less than 5 minutes ago will be kept.');" style="margin: 0;">
                                    <input type="hidden" name="action" value="clear_stuck">
                                    <button type="submit" class="button-link secondary" style="font-size: 11px; padding: 6px 12px; border: 1px solid #e53e3e; color: #e53e3e; background: transparent; cursor: pointer; border-radius: 4px; transition: all 0.2s; width: auto; margin-top: 0;">Limpiar atascados</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" onsubmit="return confirm('Discard all pending artworks and selections? This cannot be undone.');" style="margin: 0;">
                                <input type="hidden" name="action" value="discard_all_pending">
                                <button type="submit" class="button-link secondary" style="width: auto; margin-top: 0; font-size: 11px; padding: 6px 12px; border-color: var(--danger); color: var(--danger); background: transparent;">Discard all pending</button>
                            </form>
                        </div>
                    </div>
                    <div class="grid">
                        <?php foreach ($pendingArtworks as $pending): ?>
                            <article class="item-card" style="opacity: 0.95; background: var(--surface); padding: 16px; border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow);">
                                <h3 style="margin: 0 0 8px; font-size: 14px; text-align: left; font-family: var(--font-sans); font-weight: 600;">
                                    <?= h($pending['final_title'] !== '' ? $pending['final_title'] : 'Artwork Upload (' . date('m/d H:i', strtotime($pending['created_at'])) . ')') ?>
                                </h3>
                                <div style="margin: 8px 0; text-align: left;">
                                    <?php if ($pending['status'] === 'awaiting_selection'): ?>
                                        <span class="status-pill done" style="background: var(--accent); color: white; border-color: var(--accent); margin: 0;">Awaiting Selection</span>
                                    <?php else: ?>
                                        <span class="status-pill <?= h($pending['status']) ?>" style="margin: 0;"><?= h($pending['status']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="meta-line" style="text-align: left; margin: 4px 0 0; font-size: 11px;">Uploaded: <?= h(date('Y-m-d H:i', strtotime($pending['created_at']))) ?></p>
                                <div class="card-actions" style="margin-top: 14px; display: flex; gap: 8px; justify-content: flex-start;">
                                    <?php if ($pending['status'] === 'awaiting_selection'): ?>
                                        <a class="button-link" href="root_select.php?job=<?= rawurlencode((string)$pending['job_id']) ?>" style="font-size: 11px; padding: 6px 12px; color: white !important; width: auto; margin-top: 0;">Select Version</a>
                                    <?php else: ?>
                                        <a class="button-link secondary" href="waiting.php?job=<?= rawurlencode((string)$pending['job_id']) ?>" style="font-size: 11px; padding: 6px 12px; width: auto; margin-top: 0;">View Status</a>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Discard this pending artwork? This cannot be undone.');" style="margin: 0;">
                                        <input type="hidden" name="action" value="discard_pending">
                                        <input type="hidden" name="artwork_id" value="<?= h($pending['id']) ?>">
                                        <button type="submit" class="button-link secondary" style="width: auto; margin-top: 0; font-size: 11px; padding: 6px 12px; border-color: var(--danger); color: var(--danger); background: transparent;">Discard</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (isset($_GET['selected'])): ?>
                <div class="notice">Official root view updated.</div>
            <?php endif; ?>
            <?php if (isset($_GET['merged'])): ?>
                <div class="notice">The duplicate was resolved. All root images and mockups are now grouped under one artwork.</div>
            <?php endif; ?>

            <?php if ($id > 0 && !empty($missing)): ?>
                <div class="notice">Missing root views: <?= h(implode(', ', $missing)) ?>.</div>
            <?php endif; ?>
            <?php if ($albumLoadError !== ''): ?>
                <div class="notice error"><?= h($albumLoadError) ?></div>
            <?php endif; ?>

            <section class="panel">
                <?php if ($id <= 0): ?>
                    <?php if ($albumArtworks): ?>
                        <div class="mobile-root-slider" aria-label="Root artwork carousel">
                            <?php $mobileSeriesOrder = count($albumArtworks); ?>
                            <?php foreach ($albumArtworks as $albumArtwork): ?>
                                <?php
                                $visibleMobileSeriesOrder = $selectedArtworkSeriesId > 0 ? $mobileSeriesOrder-- : 0;
                                $title = trim((string)($albumArtwork['group_title'] ?? ''));
                                if ($title === '') {
                                    $title = trim((string)($albumArtwork['final_title'] ?? ''));
                                }
                                if ($title === '') {
                                    $title = 'Untitled';
                                }
                                $seriesTitle = ArtworkSeries::display((string)($albumArtwork['series_title'] ?: $albumArtwork['series'] ?? ''));
                                $width = trim((string)($albumArtwork['width'] ?? ''));
                                $height = trim((string)($albumArtwork['height'] ?? ''));
                                $unit = trim((string)($albumArtwork['unit'] ?? 'cm'));
                                $size = ($width !== '' && $height !== '') ? trim($width . ' x ' . $height . ' ' . $unit) : '';
                                $rootCount = (int)($albumArtwork['root_count'] ?? 0);
                                $mockupCount = (int)($albumArtwork['mockup_count'] ?? 0);
                                $targetUrl = 'artwork_details.php?id=' . (int)$albumArtwork['id'];
                                ?>
                                <article class="mobile-root-slide">
                                    <a class="mobile-root-slide-link" href="<?= h($targetUrl) ?>">
                                        <img src="<?= h(root_album_media_url((string)$albumArtwork['root_file'])) ?>" alt="<?= h($title) ?>" loading="lazy">
                                        <?php if ($selectedArtworkSeriesId > 0): ?><span class="series-artwork-order"><?= str_pad((string)$visibleMobileSeriesOrder, 2, '0', STR_PAD_LEFT) ?></span><?php endif; ?>
                                        <h2><?= h($title) ?><?php if ($seriesTitle !== ''): ?> <span class="title-series-soft">(<?= h($seriesTitle) ?>)</span><?php endif; ?></h2>
                                        <p>
                                            <?= $size !== '' ? h($size) . ' · ' : '' ?><?= h((string)$rootCount) ?> roots<?= $mockupCount > 0 ? ' · ' . h((string)$mockupCount) . ' mockups' : '' ?>
                                        </p>
                                    </a>
                                    <?php if (count($albumArtworks) > 1): ?>
                                        <button class="artwork-merge-btn media-icon-button media-thumb-action media-thumb-action--right-secondary" type="button" title="Fusionar con otra obra" aria-label="Fusionar con otra obra"
                                            data-merge-source
                                            data-group-id="<?= (int)$albumArtwork['group_id'] ?>"
                                            data-artwork-id="<?= (int)$albumArtwork['id'] ?>"
                                            data-title="<?= h($title) ?>"
                                            data-image="<?= h(root_album_media_url((string)$albumArtwork['root_file'])) ?>"
                                            data-mockups="<?= $mockupCount ?>"
                                            data-roots="<?= $rootCount ?>"
                                            data-width="<?= h($width) ?>" data-height="<?= h($height) ?>" data-unit="<?= h($unit) ?>">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 7.5H7a4 4 0 0 0 0 8h2.5M14.5 7.5H17a4 4 0 0 1 0 8h-2.5M8.5 11.5h7"/></svg>
                                        </button>
                                    <?php endif; ?>
                                    <button class="artwork-delete-btn media-icon-button media-thumb-action media-thumb-action--right is-danger" type="button" title="Delete artwork" aria-label="Delete artwork" data-delete-artwork data-artwork-id="<?= (int)$albumArtwork['id'] ?>">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 8.5h7l-.55 9h-5.9l-.55-9Z"/><path d="M7.5 6.5h9M10 6.5V5h4v1.5M10.5 11v4.2M13.5 11v4.2"/></svg>
                                    </button>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <div class="root-album-grid root-album-grid--catalog"<?php if ($selectedArtworkSeriesId > 0): ?> data-series-order-list data-series-order-endpoint="reorder_series_artworks.php" data-series-order-csrf="<?= h($_SESSION['series_csrf']) ?>"<?php endif; ?>>
                            <?php $desktopSeriesOrder = count($albumArtworks); ?>
                            <?php foreach ($albumArtworks as $albumArtwork): ?>
                                <?php
                                $visibleDesktopSeriesOrder = $selectedArtworkSeriesId > 0 ? $desktopSeriesOrder-- : 0;
                                $title = trim((string)($albumArtwork['group_title'] ?? ''));
                                if ($title === '') {
                                    $title = trim((string)($albumArtwork['final_title'] ?? ''));
                                }
                                if ($title === '') {
                                    $title = 'Untitled';
                                }
                                $seriesTitle = ArtworkSeries::display((string)($albumArtwork['series_title'] ?: $albumArtwork['series'] ?? ''));
                                $width = trim((string)($albumArtwork['width'] ?? ''));
                                $height = trim((string)($albumArtwork['height'] ?? ''));
                                $unit = trim((string)($albumArtwork['unit'] ?? 'cm'));
                                $size = ($width !== '' && $height !== '') ? trim($width . ' x ' . $height . ' ' . $unit) : '';
                                $targetUrl = 'artwork_details.php?id=' . (int)$albumArtwork['id'];
                                $rootCount = (int)($albumArtwork['root_count'] ?? 0);
                                $officialCount = (int)($albumArtwork['official_count'] ?? 0);
                                $variantCount = (int)($albumArtwork['variant_count'] ?? 0);
                                $mockupCount = (int)($albumArtwork['mockup_count'] ?? 0);
                                ?>
                                <article class="root-album-card"<?php if ($selectedArtworkSeriesId > 0): ?> data-series-artwork-id="<?= (int)$albumArtwork['id'] ?>" data-series-id="<?= $selectedArtworkSeriesId ?>"<?php endif; ?>>
                                    <a href="<?= h($targetUrl) ?>"<?php if ($selectedArtworkSeriesId > 0): ?> data-series-drag-thumb<?php endif; ?>>
                                        <img src="<?= h(root_album_media_url((string)$albumArtwork['root_file'])) ?>" alt="<?= h($title) ?>" loading="lazy" draggable="false">
                                        <?php if ($selectedArtworkSeriesId > 0): ?><span class="series-artwork-order" data-series-order-position><?= str_pad((string)$visibleDesktopSeriesOrder, 2, '0', STR_PAD_LEFT) ?></span><?php endif; ?>
                                    </a>
                                    <?php if (count($albumArtworks) > 1): ?>
                                        <button class="artwork-merge-btn media-icon-button media-thumb-action media-thumb-action--right-secondary" type="button" title="Fusionar con otra obra" aria-label="Fusionar con otra obra"
                                            data-merge-source
                                            data-group-id="<?= (int)$albumArtwork['group_id'] ?>"
                                            data-artwork-id="<?= (int)$albumArtwork['id'] ?>"
                                            data-title="<?= h($title) ?>"
                                            data-image="<?= h(root_album_media_url((string)$albumArtwork['root_file'])) ?>"
                                            data-mockups="<?= $mockupCount ?>"
                                            data-roots="<?= $rootCount ?>"
                                            data-width="<?= h($width) ?>" data-height="<?= h($height) ?>" data-unit="<?= h($unit) ?>">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 7.5H7a4 4 0 0 0 0 8h2.5M14.5 7.5H17a4 4 0 0 1 0 8h-2.5M8.5 11.5h7"/></svg>
                                        </button>
                                    <?php endif; ?>
                                    <button class="artwork-delete-btn media-icon-button media-thumb-action media-thumb-action--right is-danger" type="button" title="Delete artwork" aria-label="Delete artwork" data-delete-artwork data-artwork-id="<?= (int)$albumArtwork['id'] ?>">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 8.5h7l-.55 9h-5.9l-.55-9Z"/><path d="M7.5 6.5h9M10 6.5V5h4v1.5M10.5 11v4.2M13.5 11v4.2"/></svg>
                                    </button>
                                    <h2 class="root-album-title"><?= h($title) ?><?php if ($seriesTitle !== ''): ?> <span class="title-series-soft">(<?= h($seriesTitle) ?>)</span><?php endif; ?></h2>
                                    <p class="root-album-subtitle">
                                        <?php if ($artworksPreviewActive): ?>
                                            <?= $size !== '' ? h($size) . ' · ' : '' ?><?= h((string)$rootCount) ?> roots · <?= h((string)$mockupCount) ?> mockups
                                        <?php else: ?>
                                            Group #<?= (int)($albumArtwork['group_id'] ?? 0) ?> · Artwork #<?= (int)($albumArtwork['id'] ?? 0) ?>
                                            <?= $size !== '' ? ' - ' . h($size) : '' ?>
                                            · <?= h((string)$officialCount) ?> official / <?= h((string)$rootCount) ?> roots
                                            <?= $variantCount > 0 ? ' · ' . h((string)$variantCount) . ' variants' : '' ?>
                                            <?= $mockupCount > 0 ? ' · ' . h((string)$mockupCount) . ' mockups' : '' ?>
                                        <?php endif; ?>
                                    </p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="notice">No ArtWorks are available yet.</div>
                    <?php endif; ?>
                <?php elseif ($candidates): ?>
                    <div class="root-album-grid">
                        <?php foreach ($candidates as $candidate): ?>
                            <article class="root-album-card <?= $candidate['is_selected'] ? 'is-selected' : '' ?>">
                                <a href="<?= h('viewer.php?file=' . rawurlencode($candidate['file_name']) . '&back=' . rawurlencode('root_album.php?id=' . (int)$id)) ?>">
                                    <img src="<?= h(root_album_media_url($candidate['file_name'])) ?>" alt="<?= h(root_album_label($candidate['view_type'])) ?>" loading="lazy">
                                </a>
                                <div class="root-album-meta">
                                    <strong><?= h(root_album_label($candidate['view_type'])) ?></strong>
                                    <?php if ($candidate['is_selected']): ?>
                                        <span>Selected</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$candidate['is_selected']): ?>
                                    <form method="post">
                                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                                        <input type="hidden" name="action" value="select_root_candidate">
                                        <input type="hidden" name="candidate_id" value="<?= (int)$candidate['id'] ?>">
                                        <input type="hidden" name="candidate_file" value="<?= h($candidate['file_name']) ?>">
                                        <button type="submit" class="secondary">Set As Official Root</button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="notice">No root images are available for this artwork yet.</div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
<?php if ($id <= 0 && count($albumArtworks) > 1): ?>
<div class="merge-artwork-backdrop" data-merge-dialog hidden>
    <section class="merge-artwork-dialog" role="dialog" aria-modal="true" aria-labelledby="merge-artwork-title">
        <span class="merge-artwork-kicker">Resolver duplicado</span>
        <h2 id="merge-artwork-title">Fusionar con otra obra</h2>
        <p class="merge-artwork-intro">Elige la otra referencia y después cuál debe permanecer como obra principal. No se eliminarán imágenes raíz ni mockups.</p>
        <input class="merge-artwork-search" type="search" data-merge-search placeholder="Buscar por título de obra…" autocomplete="off">
        <div class="merge-artwork-picker" data-merge-picker aria-label="Elegir obra duplicada">
            <?php foreach ($albumArtworks as $mergeCandidate): ?>
                <?php
                $mergeTitle = trim((string)($mergeCandidate['group_title'] ?? ''));
                if ($mergeTitle === '') $mergeTitle = trim((string)($mergeCandidate['final_title'] ?? ''));
                if ($mergeTitle === '') $mergeTitle = 'Untitled';
                $mergeWidth = trim((string)($mergeCandidate['width'] ?? ''));
                $mergeHeight = trim((string)($mergeCandidate['height'] ?? ''));
                $mergeUnit = trim((string)($mergeCandidate['unit'] ?? 'cm'));
                ?>
                <button type="button" class="merge-candidate"
                    data-merge-candidate
                    data-group-id="<?= (int)$mergeCandidate['group_id'] ?>"
                    data-artwork-id="<?= (int)$mergeCandidate['id'] ?>"
                    data-title="<?= h($mergeTitle) ?>"
                    data-image="<?= h(root_album_media_url((string)$mergeCandidate['root_file'])) ?>"
                    data-mockups="<?= (int)$mergeCandidate['mockup_count'] ?>"
                    data-roots="<?= (int)$mergeCandidate['root_count'] ?>"
                    data-width="<?= h($mergeWidth) ?>" data-height="<?= h($mergeHeight) ?>" data-unit="<?= h($mergeUnit) ?>">
                    <span class="merge-likely-badge">Posible duplicado</span>
                    <img src="<?= h(root_album_media_url((string)$mergeCandidate['root_file'])) ?>" alt="" loading="lazy">
                    <strong><?= h($mergeTitle) ?></strong>
                    <small><?= (int)$mergeCandidate['root_count'] ?> raíces · <?= (int)$mergeCandidate['mockup_count'] ?> mockups</small>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="merge-primary-panel" data-merge-primary-panel hidden>
            <strong>¿Cuál debe quedar como obra principal?</strong>
            <div class="merge-primary-options">
                <label class="merge-primary-option">
                    <input type="radio" name="merge_primary" value="source" checked>
                    <img src="" alt="" data-merge-primary-image="source">
                    <span><strong data-merge-primary-title="source"></strong><span data-merge-primary-meta="source"></span></span>
                </label>
                <label class="merge-primary-option">
                    <input type="radio" name="merge_primary" value="candidate">
                    <img src="" alt="" data-merge-primary-image="candidate">
                    <span><strong data-merge-primary-title="candidate"></strong><span data-merge-primary-meta="candidate"></span></span>
                </label>
            </div>
            <p class="merge-preserves" data-merge-preserves></p>
        </div>

        <div class="merge-dialog-error" data-merge-error role="alert"></div>
        <div class="merge-dialog-actions">
            <button class="button-link secondary" type="button" data-merge-cancel>Cancelar</button>
            <button class="button-link merge-confirm" type="button" data-merge-confirm disabled>Fusionar obras</button>
        </div>
    </section>
</div>
<?php endif; ?>
<script>
function parseArtworkDeleteJson(response) {
    return response.text().then(text => {
        try { return JSON.parse(text); } catch (err) { throw new Error(text.substring(0, 220)); }
    });
}

const mergeDialog = document.querySelector('[data-merge-dialog]');
const mergeState = { source: null, candidate: null };

function mergeArtworkData(element) {
    return {
        groupId: Number(element?.dataset.groupId || 0),
        artworkId: Number(element?.dataset.artworkId || 0),
        title: element?.dataset.title || 'Untitled',
        image: element?.dataset.image || '',
        mockups: Number(element?.dataset.mockups || 0),
        roots: Number(element?.dataset.roots || 0),
        width: Number(element?.dataset.width || 0),
        height: Number(element?.dataset.height || 0),
        unit: (element?.dataset.unit || '').toLowerCase(),
    };
}

function likelyDuplicate(source, candidate) {
    if (!source || !candidate || !source.width || !source.height || !candidate.width || !candidate.height || source.unit !== candidate.unit) return false;
    const direct = Math.abs(source.width - candidate.width) <= 1 && Math.abs(source.height - candidate.height) <= 1;
    const rotated = Math.abs(source.width - candidate.height) <= 1 && Math.abs(source.height - candidate.width) <= 1;
    return direct || rotated;
}

function fillPrimaryOption(role, artwork) {
    const image = mergeDialog?.querySelector('[data-merge-primary-image="' + role + '"]');
    const title = mergeDialog?.querySelector('[data-merge-primary-title="' + role + '"]');
    const meta = mergeDialog?.querySelector('[data-merge-primary-meta="' + role + '"]');
    if (image) image.src = artwork.image;
    if (title) title.textContent = artwork.title;
    if (meta) meta.textContent = artwork.roots + ' raíces · ' + artwork.mockups + ' mockups';
}

function closeMergeDialog() {
    if (!mergeDialog) return;
    mergeDialog.hidden = true;
    document.body.style.overflow = '';
    mergeState.source = null;
    mergeState.candidate = null;
}

function openMergeDialog(button) {
    if (!mergeDialog) return;
    mergeState.source = mergeArtworkData(button);
    mergeState.candidate = null;
    mergeDialog.querySelector('[data-merge-error]').textContent = '';
    mergeDialog.querySelector('[data-merge-primary-panel]').hidden = true;
    mergeDialog.querySelector('[data-merge-confirm]').disabled = true;
    mergeDialog.querySelector('input[name="merge_primary"][value="source"]').checked = true;
    const search = mergeDialog.querySelector('[data-merge-search]');
    search.value = '';
    mergeDialog.querySelectorAll('[data-merge-candidate]').forEach(candidateButton => {
        const candidate = mergeArtworkData(candidateButton);
        const isSource = candidate.groupId === mergeState.source.groupId;
        candidateButton.hidden = isSource;
        candidateButton.classList.remove('is-selected');
        candidateButton.classList.toggle('is-likely', !isSource && likelyDuplicate(mergeState.source, candidate));
    });
    mergeDialog.hidden = false;
    document.body.style.overflow = 'hidden';
    window.setTimeout(() => search.focus(), 30);
}

function selectMergeCandidate(button) {
    mergeState.candidate = mergeArtworkData(button);
    mergeDialog.querySelectorAll('[data-merge-candidate]').forEach(item => item.classList.toggle('is-selected', item === button));
    fillPrimaryOption('source', mergeState.source);
    fillPrimaryOption('candidate', mergeState.candidate);
    const totalRoots = mergeState.source.roots + mergeState.candidate.roots;
    const totalMockups = mergeState.source.mockups + mergeState.candidate.mockups;
    mergeDialog.querySelector('[data-merge-preserves]').textContent = 'Se conservarán ' + totalRoots + ' imágenes raíz y ' + totalMockups + ' mockups. La referencia secundaria permanecerá internamente para conservar su procedencia.';
    mergeDialog.querySelector('[data-merge-primary-panel]').hidden = false;
    mergeDialog.querySelector('[data-merge-confirm]').disabled = false;
}

mergeDialog?.querySelector('[data-merge-search]')?.addEventListener('input', event => {
    const term = event.target.value.trim().toLowerCase();
    mergeDialog.querySelectorAll('[data-merge-candidate]').forEach(button => {
        const isSource = Number(button.dataset.groupId || 0) === mergeState.source?.groupId;
        button.hidden = isSource || (term !== '' && !(button.dataset.title || '').toLowerCase().includes(term));
    });
});

mergeDialog?.addEventListener('click', event => {
    const candidate = event.target.closest('[data-merge-candidate]');
    if (candidate) {
        selectMergeCandidate(candidate);
        return;
    }
    if (event.target.closest('[data-merge-cancel]') || event.target === mergeDialog) {
        closeMergeDialog();
        return;
    }
    const confirmButton = event.target.closest('[data-merge-confirm]');
    if (!confirmButton || !mergeState.source || !mergeState.candidate) return;

    const selectedPrimary = mergeDialog.querySelector('input[name="merge_primary"]:checked')?.value || 'source';
    const primary = selectedPrimary === 'candidate' ? mergeState.candidate : mergeState.source;
    const error = mergeDialog.querySelector('[data-merge-error]');
    const form = new FormData();
    form.append('csrf', <?= json_encode($artworkMergeCsrf) ?>);
    form.append('first_group_id', String(mergeState.source.groupId));
    form.append('second_group_id', String(mergeState.candidate.groupId));
    form.append('primary_group_id', String(primary.groupId));
    confirmButton.disabled = true;
    confirmButton.textContent = 'Fusionando…';
    error.textContent = '';

    fetch('merge_artwork_groups.php', { method: 'POST', body: form })
        .then(parseArtworkDeleteJson)
        .then(result => {
            if (!result.ok) throw new Error(result.error || 'No se pudieron fusionar las obras.');
            window.location.href = result.redirect_url || 'root_album.php?merged=1';
        })
        .catch(err => {
            error.textContent = err.message;
            confirmButton.disabled = false;
            confirmButton.textContent = 'Fusionar obras';
        });
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && mergeDialog && !mergeDialog.hidden) closeMergeDialog();
});

document.addEventListener('click', event => {
    const mergeButton = event.target.closest('[data-merge-source]');
    if (!mergeButton) return;
    event.preventDefault();
    event.stopPropagation();
    openMergeDialog(mergeButton);
});

document.addEventListener('click', event => {
    const button = event.target.closest('[data-delete-artwork]');
    if (!button) return;
    event.preventDefault();
    event.stopPropagation();
    if (!confirm('Delete this artwork, all its root views and associated mockups? This cannot be undone.')) return;

    const artworkId = button.getAttribute('data-artwork-id') || '';
    const formData = new FormData();
    formData.append('artwork_id', artworkId);
    document.querySelectorAll('[data-delete-artwork][data-artwork-id="' + CSS.escape(artworkId) + '"]').forEach(item => item.disabled = true);

    fetch('delete_artwork_group.php', { method: 'POST', body: formData })
        .then(parseArtworkDeleteJson)
        .then(result => {
            if (!result.ok) throw new Error(result.error || 'Could not delete artwork.');
            window.location.reload();
        })
        .catch(err => {
            alert(err.message);
            document.querySelectorAll('[data-delete-artwork][data-artwork-id="' + CSS.escape(artworkId) + '"]').forEach(item => item.disabled = false);
        });
});
</script>
<?php if ($id <= 0 && $selectedArtworkSeriesId > 0): ?>
<script src="assets/vendor/sortablejs/Sortable.min.js?v=1.15.7"></script>
<script src="series_artwork_order.js?v=20260719-3"></script>
<?php endif; ?>
</body>
</html>
