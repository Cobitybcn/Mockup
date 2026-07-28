<?php
declare(strict_types=1);

/**
 * Import (production -> local) el catalogo de Video Lab de un artista.
 * Complemento de import_artist_from_production.php, que no cubria las
 * tablas de video. Misma politica de seguridad: SOLO LEE de produccion,
 * SOLO ESCRIBE en local, dry-run por defecto, --apply para escribir.
 *
 * Uso:
 *   php scripts/import_video_from_production.php --user-email=mauriziovalch@gmail.com \
 *       --source-host=127.0.0.1 --source-port=3307 [--apply]
 *
 * La contrasena de origen se lee de PRODUCTION_IMPORT_SOURCE_PASSWORD.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['user-email:', 'source-host:', 'source-port::', 'source-database::', 'source-username::', 'apply']);
$email = strtolower(trim((string)($options['user-email'] ?? '')));
$sourceHost = trim((string)($options['source-host'] ?? ''));
$sourcePort = max(1, (int)($options['source-port'] ?? 3307));
$sourceDatabase = trim((string)($options['source-database'] ?? 'mockups'));
$sourceUsername = trim((string)($options['source-username'] ?? 'mockups_app'));
$sourcePassword = (string)(getenv('PRODUCTION_IMPORT_SOURCE_PASSWORD') ?: '');
$apply = array_key_exists('apply', $options);

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "A valid --user-email is required.\n");
    exit(1);
}
if ($sourceHost === '' || $sourcePassword === '') {
    fwrite(STDERR, "Source host and PRODUCTION_IMPORT_SOURCE_PASSWORD are required.\n");
    exit(1);
}
if (app_env('APP_ENV', '') !== 'local') {
    fwrite(STDERR, "Safety stop: this script only runs with APP_ENV=local.\n");
    exit(1);
}

$target = Database::connection();
$targetDatabase = (string)$target->query('SELECT DATABASE()')->fetchColumn();
if (stripos($targetDatabase, 'local') === false) {
    fwrite(STDERR, "Safety stop: target database '{$targetDatabase}' does not look like a local database.\n");
    exit(1);
}

$source = new PDO(
    "mysql:host={$sourceHost};port={$sourcePort};dbname={$sourceDatabase};charset=utf8mb4",
    $sourceUsername,
    $sourcePassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function readOnlyQuery(PDO $pdo, string $sql, array $params = []): array
{
    if (!preg_match('/^\s*SELECT/i', $sql)) {
        throw new RuntimeException('Only SELECT is allowed against the source connection.');
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$sourceUserStmt = $source->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$sourceUserStmt->execute([$email]);
$sourceUserId = (int)($sourceUserStmt->fetchColumn() ?: 0);
$targetUserStmt = $target->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$targetUserStmt->execute([$email]);
$targetUserId = (int)($targetUserStmt->fetchColumn() ?: 0);
if ($sourceUserId <= 0) {
    fwrite(STDERR, "No production user found for {$email}\n");
    exit(1);
}
if ($targetUserId <= 0) {
    fwrite(STDERR, "No local user found for {$email}. Run import_artist_from_production.php first.\n");
    exit(1);
}

echo "Mode: " . ($apply ? 'APPLY (writing to local)' : 'DRY RUN (no writes)') . "\n";
echo "Source: {$sourceDatabase}@{$sourceHost}:{$sourcePort}  Target: {$targetDatabase}\n";
echo "Artist: {$email}  (production id {$sourceUserId} -> local id {$targetUserId})\n\n";

$stats = [];
$record = static function (array &$stats, string $bucket, string $table): void {
    $stats[$bucket][$table] = ($stats[$bucket][$table] ?? 0) + 1;
};

$target->beginTransaction();
try {
    // --- Rebuild natural-key maps this script needs (not persisted across runs) ---
    $artworkMap = [];
    $sourceArtworks = readOnlyQuery($source, 'SELECT id, job_id FROM artworks WHERE user_id=?', [$sourceUserId]);
    $targetArtworks = $target->prepare('SELECT id, job_id FROM artworks WHERE user_id=?');
    $targetArtworks->execute([$targetUserId]);
    $targetArtworksByJob = [];
    foreach ($targetArtworks->fetchAll() as $row) { $targetArtworksByJob[(string)$row['job_id']] = (int)$row['id']; }
    foreach ($sourceArtworks as $row) {
        if (isset($targetArtworksByJob[(string)$row['job_id']])) {
            $artworkMap[(int)$row['id']] = $targetArtworksByJob[(string)$row['job_id']];
        }
    }

    $seriesMap = [];
    $sourceSeries = readOnlyQuery($source, 'SELECT id, slug FROM artwork_series WHERE user_id=?', [$sourceUserId]);
    $targetSeries = $target->prepare('SELECT id, slug FROM artwork_series WHERE user_id=?');
    $targetSeries->execute([$targetUserId]);
    $targetSeriesBySlug = [];
    foreach ($targetSeries->fetchAll() as $row) { $targetSeriesBySlug[(string)$row['slug']] = (int)$row['id']; }
    foreach ($sourceSeries as $row) {
        if (isset($targetSeriesBySlug[(string)$row['slug']])) {
            $seriesMap[(int)$row['id']] = $targetSeriesBySlug[(string)$row['slug']];
        }
    }

    $mockupMap = [];
    $sourceMockups = readOnlyQuery($source, 'SELECT id, mockup_file FROM mockups WHERE user_id=?', [$sourceUserId]);
    $targetMockups = $target->prepare('SELECT id, mockup_file FROM mockups WHERE user_id=?');
    $targetMockups->execute([$targetUserId]);
    $targetMockupsByFile = [];
    foreach ($targetMockups->fetchAll() as $row) { $targetMockupsByFile[(string)$row['mockup_file']] = (int)$row['id']; }
    foreach ($sourceMockups as $row) {
        if (isset($targetMockupsByFile[(string)$row['mockup_file']])) {
            $mockupMap[(int)$row['id']] = $targetMockupsByFile[(string)$row['mockup_file']];
        }
    }

    $remapNullable = static function (?int $id, array $map): ?int {
        if ($id === null || $id <= 0) { return null; }
        return $map[$id] ?? null;
    };

    // --- video_projects (match by legacy hash of title+created_at) ---
    $projectFields = ['title', 'description', 'global_prompt', 'aspect_ratio', 'target_duration_seconds', 'project_type', 'status', 'master_volume', 'version', 'created_at', 'updated_at'];
    $sourceProjects = readOnlyQuery($source, 'SELECT id,artwork_id,series_id,' . implode(',', $projectFields) . ' FROM video_projects WHERE user_id=?', [$sourceUserId]);
    $targetProjectsStmt = $target->prepare('SELECT id,' . implode(',', $projectFields) . ' FROM video_projects WHERE user_id=?');
    $targetProjectsStmt->execute([$targetUserId]);
    $targetProjectsByHash = [];
    foreach ($targetProjectsStmt->fetchAll() as $row) {
        $hash = hash('sha256', $row['title'] . '|' . $row['created_at']);
        $targetProjectsByHash[$hash] = (int)$row['id'];
    }
    $insertProject = $target->prepare('INSERT INTO video_projects (user_id,artwork_id,series_id,' . implode(',', $projectFields) . ') VALUES (?,?,?,' . implode(',', array_fill(0, count($projectFields), '?')) . ')');
    $updateProject = $target->prepare('UPDATE video_projects SET artwork_id=?,series_id=?,' . implode(',', array_map(static fn(string $f) => "{$f}=?", $projectFields)) . ' WHERE id=?');
    $projectMap = [];
    foreach ($sourceProjects as $row) {
        $hash = hash('sha256', $row['title'] . '|' . $row['created_at']);
        $localArtworkId = $remapNullable(isset($row['artwork_id']) ? (int)$row['artwork_id'] : null, $artworkMap);
        $localSeriesId = $remapNullable(isset($row['series_id']) ? (int)$row['series_id'] : null, $seriesMap);
        if (isset($targetProjectsByHash[$hash])) {
            $localId = $targetProjectsByHash[$hash];
            $projectMap[(int)$row['id']] = $localId;
            if ($apply) {
                $values = [$localArtworkId, $localSeriesId];
                foreach ($projectFields as $f) { $values[] = $row[$f]; }
                $values[] = $localId;
                $updateProject->execute($values);
            }
            $record($stats, 'updated', 'video_projects');
            continue;
        }
        $projectMap[(int)$row['id']] = 0;
        if ($apply) {
            $values = [$targetUserId, $localArtworkId, $localSeriesId];
            foreach ($projectFields as $f) { $values[] = $row[$f]; }
            $insertProject->execute($values);
            $projectMap[(int)$row['id']] = (int)$target->lastInsertId();
        }
        $record($stats, 'inserted', 'video_projects');
    }

    // --- video_scenes (match by remapped video_project_id + position) ---
    $sceneFields = ['position', 'title', 'purpose', 'prompt', 'duration_seconds', 'generation_mode', 'artwork_motion', 'camera_movement', 'custom_camera_movement', 'motion_intensity', 'transition_out_type', 'transition_out_duration_seconds', 'audio_mode', 'status', 'is_locked', 'created_at', 'updated_at'];
    $sourceProjectIds = array_keys($projectMap);
    $sceneMap = [];
    if ($sourceProjectIds) {
        $marks = implode(',', array_fill(0, count($sourceProjectIds), '?'));
        $sourceScenes = readOnlyQuery($source, "SELECT id,video_project_id," . implode(',', $sceneFields) . " FROM video_scenes WHERE video_project_id IN ({$marks})", $sourceProjectIds);
        $localProjectIds = array_values(array_unique(array_filter($projectMap)));
        $targetScenesByProjectPosition = [];
        if ($localProjectIds) {
            $lm = implode(',', array_fill(0, count($localProjectIds), '?'));
            $targetScenesStmt = $target->prepare("SELECT id,video_project_id," . implode(',', $sceneFields) . " FROM video_scenes WHERE video_project_id IN ({$lm})");
            $targetScenesStmt->execute($localProjectIds);
            foreach ($targetScenesStmt->fetchAll() as $row) {
                $targetScenesByProjectPosition[(int)$row['video_project_id'] . '|' . (int)$row['position']] = $row;
            }
        }
        $insertScene = $target->prepare('INSERT INTO video_scenes (video_project_id,' . implode(',', $sceneFields) . ') VALUES (?,' . implode(',', array_fill(0, count($sceneFields), '?')) . ')');
        $updateScene = $target->prepare('UPDATE video_scenes SET ' . implode(',', array_map(static fn(string $f) => "{$f}=?", $sceneFields)) . ' WHERE id=?');
        foreach ($sourceScenes as $row) {
            $localProjectId = $projectMap[(int)$row['video_project_id']] ?? 0;
            if ($localProjectId <= 0) { continue; }
            $key = $localProjectId . '|' . (int)$row['position'];
            if (isset($targetScenesByProjectPosition[$key])) {
                $existing = $targetScenesByProjectPosition[$key];
                $sceneMap[(int)$row['id']] = (int)$existing['id'];
                if ($apply) {
                    $values = [];
                    foreach ($sceneFields as $f) { $values[] = $row[$f]; }
                    $values[] = (int)$existing['id'];
                    $updateScene->execute($values);
                }
                $record($stats, 'updated', 'video_scenes');
                continue;
            }
            $sceneMap[(int)$row['id']] = 0;
            if ($apply) {
                $values = [$localProjectId];
                foreach ($sceneFields as $f) { $values[] = $row[$f]; }
                $insertScene->execute($values);
                $localId = (int)$target->lastInsertId();
                $sceneMap[(int)$row['id']] = $localId;
                $targetScenesByProjectPosition[$key] = ['id' => $localId];
            }
            $record($stats, 'inserted', 'video_scenes');
        }
    }

    // --- video_generation_jobs (match by user_id + idempotency_key) ---
    $jobFields = ['provider', 'model', 'generation_mode', 'status', 'external_job_id', 'task_name', 'idempotency_key', 'scene_version', 'input_hash', 'active_slot', 'requested_duration_seconds', 'generated_duration_seconds', 'aspect_ratio', 'prompt_text', 'request_json', 'response_json', 'output_path', 'thumbnail_path', 'error', 'cost_estimate', 'cost_currency', 'attempts', 'next_poll_at', 'started_at', 'completed_at', 'created_at', 'updated_at'];
    $sourceJobs = readOnlyQuery($source, 'SELECT id,video_project_id,video_scene_id,artwork_id,series_id,' . implode(',', $jobFields) . ' FROM video_generation_jobs WHERE user_id=?', [$sourceUserId]);
    $targetJobsStmt = $target->prepare('SELECT id,idempotency_key FROM video_generation_jobs WHERE user_id=?');
    $targetJobsStmt->execute([$targetUserId]);
    $targetJobsByKey = [];
    foreach ($targetJobsStmt->fetchAll() as $row) { $targetJobsByKey[(string)$row['idempotency_key']] = (int)$row['id']; }
    $insertJob = $target->prepare('INSERT INTO video_generation_jobs (user_id,video_project_id,video_scene_id,artwork_id,series_id,' . implode(',', $jobFields) . ') VALUES (?,?,?,?,?,' . implode(',', array_fill(0, count($jobFields), '?')) . ')');
    $updateJob = $target->prepare('UPDATE video_generation_jobs SET video_project_id=?,video_scene_id=?,artwork_id=?,series_id=?,' . implode(',', array_map(static fn(string $f) => "{$f}=?", $jobFields)) . ' WHERE id=?');
    $jobMap = [];
    foreach ($sourceJobs as $row) {
        $localProjectId = $projectMap[(int)$row['video_project_id']] ?? 0;
        if ($localProjectId <= 0) { continue; }
        $localSceneId = $remapNullable(isset($row['video_scene_id']) ? (int)$row['video_scene_id'] : null, $sceneMap);
        $localArtworkId = $remapNullable(isset($row['artwork_id']) ? (int)$row['artwork_id'] : null, $artworkMap);
        $localSeriesId = $remapNullable(isset($row['series_id']) ? (int)$row['series_id'] : null, $seriesMap);
        $key = (string)$row['idempotency_key'];
        if (isset($targetJobsByKey[$key])) {
            $localId = $targetJobsByKey[$key];
            $jobMap[(int)$row['id']] = $localId;
            if ($apply) {
                $values = [$localProjectId, $localSceneId, $localArtworkId, $localSeriesId];
                foreach ($jobFields as $f) { $values[] = $row[$f]; }
                $values[] = $localId;
                $updateJob->execute($values);
            }
            $record($stats, 'updated', 'video_generation_jobs');
            continue;
        }
        $jobMap[(int)$row['id']] = 0;
        if ($apply) {
            $values = [$targetUserId, $localProjectId, $localSceneId, $localArtworkId, $localSeriesId];
            foreach ($jobFields as $f) { $values[] = $row[$f]; }
            $insertJob->execute($values);
            $localId = (int)$target->lastInsertId();
            $jobMap[(int)$row['id']] = $localId;
            $targetJobsByKey[$key] = $localId;
        }
        $record($stats, 'inserted', 'video_generation_jobs');
    }

    // --- video_exports (match by output_path, fallback remapped project_id + created_at) ---
    $exportFields = ['status', 'format', 'video_codec', 'audio_codec', 'aspect_ratio', 'timeline_snapshot_json', 'task_name', 'output_path', 'duration_seconds', 'bytes', 'error', 'started_at', 'completed_at', 'created_at', 'updated_at'];
    $sourceExports = readOnlyQuery($source, 'SELECT id,video_project_id,' . implode(',', $exportFields) . ' FROM video_exports WHERE user_id=?', [$sourceUserId]);
    $targetExportsStmt = $target->prepare('SELECT id,video_project_id,output_path,created_at FROM video_exports WHERE user_id=?');
    $targetExportsStmt->execute([$targetUserId]);
    $targetExportsByPath = [];
    $targetExportsByProjectCreated = [];
    foreach ($targetExportsStmt->fetchAll() as $row) {
        if ((string)$row['output_path'] !== '') { $targetExportsByPath[(string)$row['output_path']] = (int)$row['id']; }
        $targetExportsByProjectCreated[(int)$row['video_project_id'] . '|' . $row['created_at']] = (int)$row['id'];
    }
    $insertExport = $target->prepare('INSERT INTO video_exports (user_id,video_project_id,' . implode(',', $exportFields) . ') VALUES (?,?,' . implode(',', array_fill(0, count($exportFields), '?')) . ')');
    $updateExport = $target->prepare('UPDATE video_exports SET video_project_id=?,' . implode(',', array_map(static fn(string $f) => "{$f}=?", $exportFields)) . ' WHERE id=?');
    $exportMap = [];
    foreach ($sourceExports as $row) {
        $localProjectId = $projectMap[(int)$row['video_project_id']] ?? 0;
        if ($localProjectId <= 0) { continue; }
        $path = (string)$row['output_path'];
        $existingId = $path !== '' ? ($targetExportsByPath[$path] ?? null) : ($targetExportsByProjectCreated[$localProjectId . '|' . $row['created_at']] ?? null);
        if ($existingId !== null) {
            $exportMap[(int)$row['id']] = $existingId;
            if ($apply) {
                $values = [$localProjectId];
                foreach ($exportFields as $f) { $values[] = $row[$f]; }
                $values[] = $existingId;
                $updateExport->execute($values);
            }
            $record($stats, 'updated', 'video_exports');
            continue;
        }
        $exportMap[(int)$row['id']] = 0;
        if ($apply) {
            $values = [$targetUserId, $localProjectId];
            foreach ($exportFields as $f) { $values[] = $row[$f]; }
            $insertExport->execute($values);
            $localId = (int)$target->lastInsertId();
            $exportMap[(int)$row['id']] = $localId;
            if ($path !== '') { $targetExportsByPath[$path] = $localId; }
            $targetExportsByProjectCreated[$localProjectId . '|' . $row['created_at']] = $localId;
        }
        $record($stats, 'inserted', 'video_exports');
    }

    // --- video_reference_assets (match by file_path) ---
    $assetFields = ['original_name', 'mime_type', 'media_type', 'byte_size', 'width', 'height', 'created_at'];
    $sourceAssets = readOnlyQuery($source, 'SELECT id,file_path,' . implode(',', $assetFields) . ' FROM video_reference_assets WHERE user_id=?', [$sourceUserId]);
    $targetAssetsStmt = $target->prepare('SELECT id,file_path FROM video_reference_assets WHERE user_id=?');
    $targetAssetsStmt->execute([$targetUserId]);
    $targetAssetsByPath = [];
    foreach ($targetAssetsStmt->fetchAll() as $row) { $targetAssetsByPath[(string)$row['file_path']] = (int)$row['id']; }
    $insertAsset = $target->prepare('INSERT INTO video_reference_assets (user_id,file_path,' . implode(',', $assetFields) . ') VALUES (?,?,' . implode(',', array_fill(0, count($assetFields), '?')) . ')');
    $assetMap = [];
    foreach ($sourceAssets as $row) {
        $path = (string)$row['file_path'];
        if (isset($targetAssetsByPath[$path])) {
            $assetMap[(int)$row['id']] = $targetAssetsByPath[$path];
            $record($stats, 'unchanged', 'video_reference_assets');
            continue;
        }
        $assetMap[(int)$row['id']] = 0;
        if ($apply) {
            $values = [$targetUserId, $path];
            foreach ($assetFields as $f) { $values[] = $row[$f]; }
            $insertAsset->execute($values);
            $localId = (int)$target->lastInsertId();
            $assetMap[(int)$row['id']] = $localId;
            $targetAssetsByPath[$path] = $localId;
        }
        $record($stats, 'inserted', 'video_reference_assets');
    }

    // --- video_scene_references (match by remapped scene_id + role + position; remap source_id per source_type) ---
    $refFields = ['role', 'source_type', 'position', 'file_path', 'metadata_json', 'created_at', 'updated_at'];
    $sourceSceneIds = array_keys($sceneMap);
    if ($sourceSceneIds) {
        $marks = implode(',', array_fill(0, count($sourceSceneIds), '?'));
        $sourceRefs = readOnlyQuery($source, "SELECT id,video_scene_id,source_id," . implode(',', $refFields) . " FROM video_scene_references WHERE video_scene_id IN ({$marks})", $sourceSceneIds);
        $localSceneIds = array_values(array_unique(array_filter($sceneMap)));
        $targetRefsByKey = [];
        if ($localSceneIds) {
            $lm = implode(',', array_fill(0, count($localSceneIds), '?'));
            $targetRefsStmt = $target->prepare("SELECT id,video_scene_id,role,position FROM video_scene_references WHERE video_scene_id IN ({$lm})");
            $targetRefsStmt->execute($localSceneIds);
            foreach ($targetRefsStmt->fetchAll() as $row) {
                $targetRefsByKey[(int)$row['video_scene_id'] . '|' . $row['role'] . '|' . (int)$row['position']] = (int)$row['id'];
            }
        }
        $insertRef = $target->prepare('INSERT INTO video_scene_references (video_scene_id,source_id,' . implode(',', $refFields) . ') VALUES (?,?,' . implode(',', array_fill(0, count($refFields), '?')) . ')');
        foreach ($sourceRefs as $row) {
            $localSceneId = $sceneMap[(int)$row['video_scene_id']] ?? 0;
            if ($localSceneId <= 0) { continue; }
            $sourceType = (string)$row['source_type'];
            $localSourceId = match ($sourceType) {
                'generation_job' => $jobMap[(int)$row['source_id']] ?? 0,
                'mockup' => $mockupMap[(int)$row['source_id']] ?? 0,
                'reference_asset' => $assetMap[(int)$row['source_id']] ?? 0,
                default => 0,
            };
            if ($localSourceId <= 0) {
                $record($stats, 'skipped_unmapped_source', 'video_scene_references');
                continue;
            }
            $key = $localSceneId . '|' . $row['role'] . '|' . (int)$row['position'];
            if (isset($targetRefsByKey[$key])) {
                $record($stats, 'unchanged', 'video_scene_references');
                continue;
            }
            $targetRefsByKey[$key] = true;
            if ($apply) {
                $values = [$localSceneId, $localSourceId];
                foreach ($refFields as $f) { $values[] = $f === 'source_type' ? $sourceType : $row[$f]; }
                $insertRef->execute($values);
            }
            $record($stats, 'inserted', 'video_scene_references');
        }
    }

    // --- artwork_video_publications (match by user_id + remapped artwork_id, unique locally) ---
    $sourcePublications = readOnlyQuery($source, 'SELECT artwork_id,video_export_id,published_at,updated_at FROM artwork_video_publications WHERE user_id=?', [$sourceUserId]);
    $targetPublicationsStmt = $target->prepare('SELECT id,artwork_id FROM artwork_video_publications WHERE user_id=?');
    $targetPublicationsStmt->execute([$targetUserId]);
    $targetPublicationsByArtwork = [];
    foreach ($targetPublicationsStmt->fetchAll() as $row) { $targetPublicationsByArtwork[(int)$row['artwork_id']] = (int)$row['id']; }
    $insertPublication = $target->prepare('INSERT INTO artwork_video_publications (user_id,artwork_id,video_export_id,published_at,updated_at) VALUES (?,?,?,?,?)');
    $updatePublication = $target->prepare('UPDATE artwork_video_publications SET video_export_id=?,published_at=?,updated_at=? WHERE id=?');
    foreach ($sourcePublications as $row) {
        $localArtworkId = $artworkMap[(int)$row['artwork_id']] ?? 0;
        $localExportId = $exportMap[(int)$row['video_export_id']] ?? 0;
        if ($localArtworkId <= 0 || $localExportId <= 0) {
            $record($stats, 'skipped_unmapped', 'artwork_video_publications');
            continue;
        }
        if (isset($targetPublicationsByArtwork[$localArtworkId])) {
            if ($apply) {
                $updatePublication->execute([$localExportId, $row['published_at'], $row['updated_at'], $targetPublicationsByArtwork[$localArtworkId]]);
            }
            $record($stats, 'updated', 'artwork_video_publications');
            continue;
        }
        if ($apply) {
            $insertPublication->execute([$targetUserId, $localArtworkId, $localExportId, $row['published_at'], $row['updated_at']]);
        }
        $record($stats, 'inserted', 'artwork_video_publications');
    }

    if ($apply) {
        $target->commit();
    } elseif ($target->inTransaction()) {
        $target->rollBack();
    }

    echo json_encode(['mode' => $apply ? 'applied' : 'dry-run', 'artist_email' => $email, 'summary' => $stats], JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    if ($target->inTransaction()) {
        $target->rollBack();
    }
    fwrite(STDERR, "Import failed: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
