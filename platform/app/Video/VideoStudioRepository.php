<?php
declare(strict_types=1);

final class VideoStudioRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function listProjects(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT p.*,
            (SELECT COUNT(*) FROM video_scenes s WHERE s.video_project_id=p.id) AS scene_count,
            (SELECT COUNT(*) FROM video_generation_jobs j WHERE j.video_project_id=p.id AND j.status='succeeded') AS generated_clip_count
            FROM video_projects p
            WHERE p.user_id=? AND p.status NOT IN ('archived','deleted')
            ORDER BY p.updated_at DESC,p.id DESC");
        $stmt->execute([$userId]);
        return array_map([$this, 'normalizeProject'], $stmt->fetchAll());
    }

    public function findProject(int $userId, int $projectId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM video_projects WHERE id=? AND user_id=? AND status<>'deleted' LIMIT 1");
        $stmt->execute([$projectId, $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->normalizeProject($row) : null;
    }

    public function createProject(int $userId, array $values): int
    {
        $now = date('c');
        $stmt = $this->pdo->prepare('INSERT INTO video_projects
            (user_id,title,description,global_prompt,artwork_id,series_id,aspect_ratio,target_duration_seconds,project_type,status,master_volume,version,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,\'draft\',1,1,?,?)');
        $stmt->execute([
            $userId,
            $values['title'],
            $values['description'],
            $values['global_prompt'],
            $values['artwork_id'],
            $values['series_id'],
            $values['aspect_ratio'],
            $values['target_duration_seconds'],
            $values['project_type'],
            $now,
            $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateProject(int $userId, int $projectId, int $expectedVersion, array $changes): bool
    {
        if ($changes === []) {
            return true;
        }
        $sets = [];
        $params = [];
        foreach ($changes as $column => $value) {
            $sets[] = $column . '=?';
            $params[] = $value;
        }
        $sets[] = 'version=version+1';
        $sets[] = 'updated_at=?';
        $params[] = date('c');
        $params[] = $projectId;
        $params[] = $userId;
        $params[] = $expectedVersion;
        $stmt = $this->pdo->prepare('UPDATE video_projects SET ' . implode(',', $sets) . ' WHERE id=? AND user_id=? AND version=?');
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }

    public function touchProject(int $userId, int $projectId, int $expectedVersion): void
    {
        $stmt = $this->pdo->prepare('UPDATE video_projects SET version=version+1,updated_at=? WHERE id=? AND user_id=? AND version=?');
        $stmt->execute([date('c'), $projectId, $userId, $expectedVersion]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('This project changed in another session. Reload the latest version before saving.');
        }
    }

    public function scenes(int $projectId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_scenes WHERE video_project_id=? ORDER BY position,id');
        $stmt->execute([$projectId]);
        $scenes = $stmt->fetchAll();
        if ($scenes === []) {
            return [];
        }

        $sceneIds = array_map(static fn(array $row): int => (int)$row['id'], $scenes);
        $placeholders = implode(',', array_fill(0, count($sceneIds), '?'));

        $refStmt = $this->pdo->prepare("SELECT * FROM video_scene_references WHERE video_scene_id IN ({$placeholders}) ORDER BY video_scene_id,position,id");
        $refStmt->execute($sceneIds);
        $referencesByScene = [];
        foreach ($refStmt->fetchAll() as $reference) {
            $referencesByScene[(int)$reference['video_scene_id']][] = $this->normalizeReference($reference);
        }

        $jobStmt = $this->pdo->prepare("SELECT * FROM video_generation_jobs WHERE video_scene_id IN ({$placeholders}) ORDER BY id DESC");
        $jobStmt->execute($sceneIds);
        $latestJobs = [];
        $activeJobs = [];
        foreach ($jobStmt->fetchAll() as $job) {
            $sceneId = (int)$job['video_scene_id'];
            $normalized = $this->normalizeJob($job);
            $latestJobs[$sceneId] ??= $normalized;
            if (!isset($activeJobs[$sceneId]) && (int)($job['active_slot'] ?? 0) === 1 && (string)$job['status'] === 'succeeded') {
                $activeJobs[$sceneId] = $normalized;
            }
        }

        return array_map(function (array $scene) use ($referencesByScene, $latestJobs, $activeJobs): array {
            $id = (int)$scene['id'];
            $scene = $this->normalizeScene($scene);
            $scene['references'] = $referencesByScene[$id] ?? [];
            $scene['generation'] = $latestJobs[$id] ?? null;
            $scene['active_generation'] = $activeJobs[$id] ?? null;
            return $scene;
        }, $scenes);
    }

    public function findScene(int $userId, int $sceneId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT s.*,p.user_id,p.aspect_ratio,p.version AS project_version
            FROM video_scenes s INNER JOIN video_projects p ON p.id=s.video_project_id
            WHERE s.id=? AND p.user_id=? LIMIT 1');
        $stmt->execute([$sceneId, $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->normalizeScene($row) + [
            'user_id' => (int)$row['user_id'],
            'aspect_ratio' => (string)$row['aspect_ratio'],
            'project_version' => (int)$row['project_version'],
        ] : null;
    }

    public function createScene(int $projectId, int $position, array $values): int
    {
        $now = date('c');
        $stmt = $this->pdo->prepare('INSERT INTO video_scenes
            (video_project_id,position,title,purpose,prompt,duration_seconds,generation_mode,artwork_motion,camera_movement,custom_camera_movement,motion_intensity,transition_out_type,transition_out_duration_seconds,audio_mode,status,is_locked,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'draft\',0,?,?)');
        $stmt->execute([
            $projectId,
            $position,
            $values['title'],
            $values['purpose'],
            $values['prompt'],
            $values['duration_seconds'],
            $values['generation_mode'],
            $values['artwork_motion'],
            $values['camera_movement'],
            $values['custom_camera_movement'],
            $values['motion_intensity'],
            $values['transition_out_type'],
            $values['transition_out_duration_seconds'],
            $values['audio_mode'],
            $now,
            $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateScene(int $sceneId, array $changes): void
    {
        if ($changes === []) {
            return;
        }
        $sets = [];
        $params = [];
        foreach ($changes as $column => $value) {
            $sets[] = $column . '=?';
            $params[] = $value;
        }
        $sets[] = 'updated_at=?';
        $params[] = date('c');
        $params[] = $sceneId;
        $stmt = $this->pdo->prepare('UPDATE video_scenes SET ' . implode(',', $sets) . ' WHERE id=?');
        $stmt->execute($params);
    }

    public function nextScenePosition(int $projectId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(position),0)+10 FROM video_scenes WHERE video_project_id=?');
        $stmt->execute([$projectId]);
        return max(10, (int)$stmt->fetchColumn());
    }

    public function reorderScenes(int $projectId, array $sceneIds): void
    {
        $offset = 1000000;
        $stmt = $this->pdo->prepare('UPDATE video_scenes SET position=position+? WHERE video_project_id=?');
        $stmt->execute([$offset, $projectId]);
        $update = $this->pdo->prepare('UPDATE video_scenes SET position=?,updated_at=? WHERE id=? AND video_project_id=?');
        foreach (array_values($sceneIds) as $index => $sceneId) {
            $update->execute([($index + 1) * 10, date('c'), (int)$sceneId, $projectId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Could not persist the complete scene order.');
            }
        }
    }

    public function duplicateScene(int $sceneId, int $targetPosition): int
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_scenes WHERE id=? LIMIT 1');
        $stmt->execute([$sceneId]);
        $scene = $stmt->fetch();
        if (!is_array($scene)) {
            throw new OutOfBoundsException('Scene not found.');
        }
        $newId = $this->createScene((int)$scene['video_project_id'], $targetPosition, [
            'title' => trim((string)$scene['title']) . ' Copy',
            'purpose' => (string)$scene['purpose'],
            'prompt' => (string)$scene['prompt'],
            'duration_seconds' => (float)$scene['duration_seconds'],
            'generation_mode' => (string)$scene['generation_mode'],
            'artwork_motion' => (string)$scene['artwork_motion'],
            'camera_movement' => (string)$scene['camera_movement'],
            'custom_camera_movement' => (string)$scene['custom_camera_movement'],
            'motion_intensity' => (string)$scene['motion_intensity'],
            'transition_out_type' => (string)$scene['transition_out_type'],
            'transition_out_duration_seconds' => (float)$scene['transition_out_duration_seconds'],
            'audio_mode' => (string)$scene['audio_mode'],
        ]);
        $refs = $this->pdo->prepare('SELECT role,source_type,source_id,position,file_path,metadata_json FROM video_scene_references WHERE video_scene_id=? ORDER BY position,id');
        $refs->execute([$sceneId]);
        $insert = $this->pdo->prepare('INSERT INTO video_scene_references
            (video_scene_id,role,source_type,source_id,position,file_path,metadata_json,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $now = date('c');
        foreach ($refs->fetchAll() as $reference) {
            $insert->execute([$newId,$reference['role'],$reference['source_type'],$reference['source_id'],$reference['position'],$reference['file_path'],$reference['metadata_json'],$now,$now]);
        }
        return $newId;
    }

    public function deleteScene(int $sceneId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM video_scenes WHERE id=?');
        $stmt->execute([$sceneId]);
    }

    public function replaceReference(int $sceneId, string $role, array $source): int
    {
        if (VideoReferencePolicy::isSingle($role)) {
            $this->pdo->prepare('DELETE FROM video_scene_references WHERE video_scene_id=? AND role=?')->execute([$sceneId, $role]);
        }
        $positionStmt = $this->pdo->prepare('SELECT COALESCE(MAX(position),0)+1 FROM video_scene_references WHERE video_scene_id=? AND role=?');
        $positionStmt->execute([$sceneId, $role]);
        $position = max(1, (int)$positionStmt->fetchColumn());
        $now = date('c');
        $stmt = $this->pdo->prepare('INSERT INTO video_scene_references
            (video_scene_id,role,source_type,source_id,position,file_path,metadata_json,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$sceneId,$role,$source['source_type'],$source['source_id'],$position,$source['file_path'],$source['metadata_json'],$now,$now]);
        return (int)$this->pdo->lastInsertId();
    }

    public function referencesForScene(int $sceneId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_scene_references WHERE video_scene_id=? ORDER BY position,id');
        $stmt->execute([$sceneId]);
        return array_map([$this, 'normalizeReference'], $stmt->fetchAll());
    }

    public function updateReferenceInstruction(int $userId, int $referenceId, string $instruction): int
    {
        $stmt = $this->pdo->prepare('SELECT r.metadata_json,r.video_scene_id,s.video_project_id
            FROM video_scene_references r
            INNER JOIN video_scenes s ON s.id=r.video_scene_id
            INNER JOIN video_projects p ON p.id=s.video_project_id
            WHERE r.id=? AND p.user_id=? LIMIT 1');
        $stmt->execute([$referenceId, $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new OutOfBoundsException('Reference not found.');

        $metadata = json_decode((string)$row['metadata_json'], true);
        if (!is_array($metadata)) $metadata = [];
        $metadata['instruction'] = $instruction;
        $update = $this->pdo->prepare('UPDATE video_scene_references SET metadata_json=?,updated_at=? WHERE id=?');
        $update->execute([self::encode($metadata), date('c'), $referenceId]);
        $this->updateScene((int)$row['video_scene_id'], ['status' => 'draft']);
        return (int)$row['video_project_id'];
    }

    public function createReferenceAsset(int $userId, array $asset): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO video_reference_assets
            (user_id,file_path,original_name,mime_type,media_type,byte_size,width,height,created_at)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $userId,
            (string)$asset['filePath'],
            (string)$asset['originalName'],
            (string)$asset['mimeType'],
            (string)$asset['mediaType'],
            (int)$asset['byteSize'],
            $asset['width'] ?? null,
            $asset['height'] ?? null,
            date('c'),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findReferenceAsset(int $userId, int $assetId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_reference_assets WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$assetId, $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function removeReference(int $userId, int $referenceId): int
    {
        $stmt = $this->pdo->prepare('SELECT r.video_scene_id,s.video_project_id
            FROM video_scene_references r
            INNER JOIN video_scenes s ON s.id=r.video_scene_id
            INNER JOIN video_projects p ON p.id=s.video_project_id
            WHERE r.id=? AND p.user_id=? LIMIT 1');
        $stmt->execute([$referenceId, $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new OutOfBoundsException('Reference not found.');
        }
        $this->pdo->prepare('DELETE FROM video_scene_references WHERE id=?')->execute([$referenceId]);
        $this->updateScene((int)$row['video_scene_id'], ['status' => 'draft']);
        return (int)$row['video_project_id'];
    }

    public function referenceSource(int $userId, string $sourceType, int $sourceId): ?array
    {
        if ($sourceType === 'mockup') {
            $stmt = $this->pdo->prepare('SELECT m.id,m.mockup_file,m.context_id,m.source_artwork_id,a.final_title,a.width,a.height,
                    COALESCE(m.artwork_group_id,a.artwork_group_id,0) AS artwork_group_id,g.canonical_artwork_id,g.title AS group_title
                FROM mockups m
                LEFT JOIN artworks a ON a.id=m.source_artwork_id AND a.user_id=m.user_id
                LEFT JOIN artwork_groups g ON g.id=COALESCE(m.artwork_group_id,a.artwork_group_id) AND g.user_id=m.user_id AND g.status=\'active\'
                WHERE m.id=? AND m.user_id=? LIMIT 1');
            $stmt->execute([$sourceId, $userId]);
            $row = $stmt->fetch();
            if (!is_array($row)) return null;
            return [
                'source_type' => 'mockup',
                'source_id' => (int)$row['id'],
                'file_path' => basename((string)$row['mockup_file']),
                'metadata_json' => self::encode([
                    'label' => Display::contextTitle((string)$row['context_id']),
                    'artworkId' => (int)($row['source_artwork_id'] ?? 0),
                    'artworkTitle' => trim((string)($row['group_title'] ?? '')) ?: trim((string)($row['final_title'] ?? '')),
                    'artworkGroupId' => (int)($row['artwork_group_id'] ?? 0),
                    'canonicalArtworkId' => (int)($row['canonical_artwork_id'] ?? $row['source_artwork_id'] ?? 0),
                    'width' => $row['width'] ?? null,
                    'height' => $row['height'] ?? null,
                    'mediaType' => 'image',
                ]),
            ];
        }
        if ($sourceType === 'artwork') {
            $stmt = $this->pdo->prepare('SELECT a.id,a.root_file,a.main_file,a.final_title,a.width,a.height,a.artwork_group_id,
                    g.canonical_artwork_id,g.title AS group_title
                FROM artworks a
                LEFT JOIN artwork_groups g ON g.id=a.artwork_group_id AND g.user_id=a.user_id AND g.status=\'active\'
                WHERE a.id=? AND a.user_id=? AND a.status=\'done\' LIMIT 1');
            $stmt->execute([$sourceId, $userId]);
            $row = $stmt->fetch();
            if (!is_array($row)) return null;
            $file = basename((string)($row['root_file'] ?: $row['main_file']));
            return [
                'source_type' => 'artwork',
                'source_id' => (int)$row['id'],
                'file_path' => $file,
                'metadata_json' => self::encode([
                    'label' => trim((string)$row['final_title']) ?: Display::artworkTitle($file),
                    'artworkId' => (int)$row['id'],
                    'artworkTitle' => trim((string)($row['group_title'] ?? '')) ?: trim((string)$row['final_title']),
                    'artworkGroupId' => (int)($row['artwork_group_id'] ?? 0),
                    'canonicalArtworkId' => (int)($row['canonical_artwork_id'] ?? $row['id']),
                    'width' => $row['width'] ?? null,
                    'height' => $row['height'] ?? null,
                    'mediaType' => 'image',
                ]),
            ];
        }
        if ($sourceType === 'generation_job') {
            $stmt = $this->pdo->prepare("SELECT j.id,j.output_path,j.artwork_id,j.series_id,s.title,s.id AS scene_id,p.id AS project_id
                FROM video_generation_jobs j
                INNER JOIN video_projects p ON p.id=j.video_project_id
                LEFT JOIN video_scenes s ON s.id=j.video_scene_id
                WHERE j.id=? AND j.user_id=? AND j.status='succeeded' AND j.output_path<>'' LIMIT 1");
            $stmt->execute([$sourceId, $userId]);
            $row = $stmt->fetch();
            if (!is_array($row)) return null;
            return [
                'source_type' => 'generation_job',
                'source_id' => (int)$row['id'],
                'file_path' => (string)$row['output_path'],
                'metadata_json' => self::encode([
                    'label' => trim((string)($row['title'] ?? '')) ?: 'Generated Clip #' . (int)$row['id'],
                    'projectId' => (int)$row['project_id'],
                    'sceneId' => (int)($row['scene_id'] ?? 0),
                    'artworkId' => (int)($row['artwork_id'] ?? 0),
                    'seriesId' => (int)($row['series_id'] ?? 0),
                    'mediaType' => 'video',
                ]),
            ];
        }
        if ($sourceType === 'reference_asset') {
            $row = $this->findReferenceAsset($userId, $sourceId);
            if (!is_array($row)) return null;
            return [
                'source_type' => 'reference_asset',
                'source_id' => (int)$row['id'],
                'file_path' => (string)$row['file_path'],
                'metadata_json' => self::encode([
                    'label' => (string)$row['original_name'],
                    'mediaType' => (string)$row['media_type'],
                    'mimeType' => (string)$row['mime_type'],
                    'width' => $row['width'] === null ? null : (int)$row['width'],
                    'height' => $row['height'] === null ? null : (int)$row['height'],
                    'byteSize' => (int)$row['byte_size'],
                    'uploaded' => true,
                ]),
            ];
        }
        return null;
    }

    public function library(int $userId): array
    {
        $favoriteIds = MockupFavorites::idsForUser($userId);
        $favoriteLookup = array_fill_keys($favoriteIds, true);
        $favoritePosition = array_flip($favoriteIds);

        $artworkStmt = $this->pdo->prepare("SELECT a.id,a.root_file,a.main_file,a.final_title,a.width,a.height,a.updated_at,
                COALESCE(canonical.series_id,a.series_id,0) AS series_id,a.artwork_group_id,
                ag.canonical_artwork_id,ag.title AS group_title,sh.title AS sheet_title,s.title AS series_title
            FROM artworks a
            LEFT JOIN artwork_groups ag ON ag.id=a.artwork_group_id AND ag.user_id=a.user_id AND ag.status='active'
            LEFT JOIN artworks canonical ON canonical.id=ag.canonical_artwork_id AND canonical.user_id=a.user_id
            LEFT JOIN artwork_sheets sh ON sh.id=(SELECT MAX(sh2.id) FROM artwork_sheets sh2 WHERE sh2.user_id=a.user_id AND sh2.canonical_artwork_id=a.id)
            LEFT JOIN artwork_series s ON s.id=COALESCE(canonical.series_id,a.series_id) AND s.user_id=a.user_id
            WHERE a.user_id=? AND a.status='done' ORDER BY a.updated_at DESC,a.id DESC LIMIT 300");
        $artworkStmt->execute([$userId]);
        $artworks = [];
        foreach ($artworkStmt->fetchAll() as $row) {
            $file = basename((string)($row['root_file'] ?: $row['main_file']));
            if ($file === '') continue;
            $individualTitle = self::artworkTitle($row, 'Artwork #' . (int)$row['id']);
            $groupTitle = trim((string)($row['group_title'] ?? ''));
            $artworkTitle = (int)($row['artwork_group_id'] ?? 0) > 0 && $groupTitle !== '' ? $groupTitle : $individualTitle;
            $artworks[] = $this->assetPayload('artwork', (int)$row['id'], $file, [
                'label' => $artworkTitle,
                'artworkId' => (int)$row['id'],
                'artworkTitle' => $artworkTitle,
                'individualArtworkTitle' => $individualTitle,
                'artworkGroupId' => (int)($row['artwork_group_id'] ?? 0),
                'canonicalArtworkId' => (int)($row['canonical_artwork_id'] ?? $row['id']),
                'groupTitle' => $groupTitle,
                'seriesId' => (int)($row['series_id'] ?? 0),
                'seriesTitle' => trim((string)($row['series_title'] ?? '')),
                'width' => $row['width'] ?? null,
                'height' => $row['height'] ?? null,
                'mediaType' => 'image',
            ]);
        }

        $mockupStmt = $this->pdo->prepare("SELECT m.id,m.mockup_file,m.context_id,m.source_artwork_id,
                COALESCE(m.series_id,canonical.series_id,a.series_id,0) AS series_id,m.created_at,a.final_title,a.width,a.height,
                COALESCE(m.artwork_group_id,a.artwork_group_id,0) AS artwork_group_id,
                ag.canonical_artwork_id,ag.title AS group_title,sh.title AS sheet_title,s.title AS series_title
            FROM mockups m
            LEFT JOIN artworks a ON a.id=m.source_artwork_id AND a.user_id=m.user_id
            LEFT JOIN artwork_groups ag ON ag.id=COALESCE(m.artwork_group_id,a.artwork_group_id) AND ag.user_id=m.user_id AND ag.status='active'
            LEFT JOIN artworks canonical ON canonical.id=ag.canonical_artwork_id AND canonical.user_id=m.user_id
            LEFT JOIN artwork_sheets sh ON sh.id=(SELECT MAX(sh2.id) FROM artwork_sheets sh2 WHERE sh2.user_id=m.user_id AND sh2.canonical_artwork_id=a.id)
            LEFT JOIN artwork_series s ON s.id=COALESCE(m.series_id,canonical.series_id,a.series_id) AND s.user_id=m.user_id
            WHERE m.user_id=? AND m.mockup_file<>'' ORDER BY m.created_at DESC,m.id DESC LIMIT 300");
        $mockupStmt->execute([$userId]);
        $mockups = [];
        foreach ($mockupStmt->fetchAll() as $row) {
            $file = basename((string)$row['mockup_file']);
            if ($file === '') continue;
            $individualTitle = self::artworkTitle(
                $row,
                (int)($row['source_artwork_id'] ?? 0) > 0 ? 'Artwork #' . (int)$row['source_artwork_id'] : 'Artwork'
            );
            $groupTitle = trim((string)($row['group_title'] ?? ''));
            $artworkTitle = (int)($row['artwork_group_id'] ?? 0) > 0 && $groupTitle !== '' ? $groupTitle : $individualTitle;
            $contextTitle = Display::contextTitle((string)$row['context_id']);
            $asset = $this->assetPayload('mockup', (int)$row['id'], $file, [
                'label' => trim($artworkTitle . ' — ' . $contextTitle, ' —'),
                'artworkId' => (int)($row['source_artwork_id'] ?? 0),
                'artworkTitle' => $artworkTitle,
                'individualArtworkTitle' => $individualTitle,
                'artworkGroupId' => (int)($row['artwork_group_id'] ?? 0),
                'canonicalArtworkId' => (int)($row['canonical_artwork_id'] ?? $row['source_artwork_id'] ?? 0),
                'groupTitle' => $groupTitle,
                'seriesId' => (int)($row['series_id'] ?? 0),
                'seriesTitle' => trim((string)($row['series_title'] ?? '')),
                'contextTitle' => $contextTitle,
                'width' => $row['width'] ?? null,
                'height' => $row['height'] ?? null,
                'mediaType' => 'image',
            ]);
            $asset['favorite'] = isset($favoriteLookup[(int)$row['id']]);
            $asset['favoriteRank'] = $favoritePosition[(int)$row['id']] ?? PHP_INT_MAX;
            $asset['createdAt'] = (string)$row['created_at'];
            $mockups[] = $asset;
        }
        usort($mockups, static function (array $left, array $right): int {
            if ((bool)$left['favorite'] !== (bool)$right['favorite']) return $left['favorite'] ? -1 : 1;
            if ($left['favorite'] && $right['favorite']) return (int)$left['favoriteRank'] <=> (int)$right['favoriteRank'];
            return strcmp((string)$right['createdAt'], (string)$left['createdAt']);
        });

        $clipStmt = $this->pdo->prepare("SELECT j.id,j.video_project_id,j.video_scene_id,j.artwork_id,j.series_id,
                j.output_path,j.thumbnail_path,j.generated_duration_seconds,j.aspect_ratio,j.model,j.active_slot,j.created_at,
                sc.title,p.title AS project_title,a.final_title,a.artwork_group_id,ag.canonical_artwork_id,
                ag.title AS group_title,sh.title AS sheet_title,ser.title AS series_title,
                (SELECT COUNT(*) FROM video_generation_jobs jv
                    WHERE jv.video_scene_id=j.video_scene_id AND jv.status='succeeded' AND jv.id<=j.id) AS generation_version
            FROM video_generation_jobs j
            INNER JOIN video_projects p ON p.id=j.video_project_id AND p.user_id=j.user_id
            LEFT JOIN video_scenes sc ON sc.id=j.video_scene_id
            LEFT JOIN artworks a ON a.id=j.artwork_id AND a.user_id=j.user_id
            LEFT JOIN artwork_groups ag ON ag.id=a.artwork_group_id AND ag.user_id=j.user_id AND ag.status='active'
            LEFT JOIN artwork_sheets sh ON sh.id=(SELECT MAX(sh2.id) FROM artwork_sheets sh2 WHERE sh2.user_id=j.user_id AND sh2.canonical_artwork_id=a.id)
            LEFT JOIN artwork_series ser ON ser.id=j.series_id AND ser.user_id=j.user_id
            WHERE j.user_id=? AND j.status='succeeded' AND j.output_path<>'' ORDER BY j.id DESC LIMIT 120");
        $clipStmt->execute([$userId]);
        $clips = [];
        foreach ($clipStmt->fetchAll() as $row) {
            $individualTitle = self::artworkTitle(
                $row,
                (int)($row['artwork_id'] ?? 0) > 0 ? 'Artwork #' . (int)$row['artwork_id'] : ''
            );
            $groupTitle = trim((string)($row['group_title'] ?? ''));
            $artworkTitle = (int)($row['artwork_group_id'] ?? 0) > 0 && $groupTitle !== '' ? $groupTitle : $individualTitle;
            $clips[] = [
                'assetKey' => 'generation_job:' . (int)$row['id'],
                'type' => 'generation_job',
                'id' => (int)$row['id'],
                'projectId' => (int)$row['video_project_id'],
                'sceneId' => (int)($row['video_scene_id'] ?? 0),
                'mediaType' => 'video',
                'label' => trim((string)($row['title'] ?? '')) ?: 'Generated Clip #' . (int)$row['id'],
                'projectTitle' => (string)$row['project_title'],
                'generationVersion' => max(1, (int)($row['generation_version'] ?? 1)),
                'artworkId' => (int)($row['artwork_id'] ?? 0),
                'artworkTitle' => $artworkTitle,
                'individualArtworkTitle' => $individualTitle,
                'artworkGroupId' => (int)($row['artwork_group_id'] ?? 0),
                'canonicalArtworkId' => (int)($row['canonical_artwork_id'] ?? $row['artwork_id'] ?? 0),
                'groupTitle' => $groupTitle,
                'seriesId' => (int)($row['series_id'] ?? 0),
                'seriesTitle' => trim((string)($row['series_title'] ?? '')),
                'thumbnailUrl' => (string)$row['thumbnail_path'] !== '' ? 'video_media.php?generation_id=' . (int)$row['id'] . '&thumbnail=1' : '',
                'previewUrl' => 'video_media.php?generation_id=' . (int)$row['id'],
                'orientation' => self::orientationFromAspect((string)$row['aspect_ratio']),
                'width' => null,
                'height' => null,
                'status' => 'available',
                'durationSeconds' => (float)$row['generated_duration_seconds'],
                'model' => (string)$row['model'],
                'aspectRatio' => (string)$row['aspect_ratio'],
                'active' => (int)($row['active_slot'] ?? 0) === 1,
                'createdAt' => (string)$row['created_at'],
            ];
        }

        $uploadedStmt = $this->pdo->prepare('SELECT * FROM video_reference_assets WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 200');
        $uploadedStmt->execute([$userId]);
        $uploadedReferences = [];
        foreach ($uploadedStmt->fetchAll() as $row) {
            $assetId = (int)$row['id'];
            $mediaType = (string)$row['media_type'];
            $previewUrl = 'video_reference_media.php?asset_id=' . $assetId;
            $uploadedReferences[] = [
                'assetKey' => 'reference_asset:' . $assetId,
                'type' => 'reference_asset',
                'id' => $assetId,
                'mediaType' => $mediaType,
                'label' => (string)$row['original_name'],
                'contextTitle' => (string)$row['original_name'],
                'artworkId' => 0,
                'artworkTitle' => '',
                'seriesId' => 0,
                'seriesTitle' => '',
                'thumbnailUrl' => $mediaType === 'image' ? $previewUrl : '',
                'previewUrl' => $previewUrl,
                'orientation' => self::orientationFromDimensions($row['width'], $row['height']),
                'width' => $row['width'] === null ? null : (int)$row['width'],
                'height' => $row['height'] === null ? null : (int)$row['height'],
                'status' => 'available',
                'mimeType' => (string)$row['mime_type'],
                'byteSize' => (int)$row['byte_size'],
                'createdAt' => (string)$row['created_at'],
            ];
        }

        return ['mockups' => $mockups, 'rootArtworks' => $artworks, 'generatedClips' => $clips, 'uploadedReferences' => $uploadedReferences];
    }

    public function latestExport(int $userId, int $projectId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT e.* FROM video_exports e INNER JOIN video_projects p ON p.id=e.video_project_id
            WHERE e.video_project_id=? AND e.user_id=? AND p.user_id=? ORDER BY e.id DESC LIMIT 1");
        $stmt->execute([$projectId, $userId, $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->normalizeExport($row) : null;
    }

    public function finalVideos(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT e.*,p.title AS project_title,p.artwork_id AS project_artwork_id FROM video_exports e
            INNER JOIN video_projects p ON p.id=e.video_project_id AND p.user_id=e.user_id
            WHERE e.user_id=? AND e.status='succeeded' AND e.output_path<>''
            ORDER BY e.updated_at DESC,e.id DESC LIMIT 100");
        $stmt->execute([$userId]);
        $rows = [];
        $artworkIds = [];
        foreach ($stmt->fetchAll() as $row) {
            $snapshot = json_decode((string)($row['timeline_snapshot_json'] ?? ''), true);
            if (!is_array($snapshot)) $snapshot = [];
            $kind = (string)($snapshot['kind'] ?? '');
            if (!in_array($kind, ['final','uploaded_final'], true)) continue;

            $artworkId = (int)($snapshot['artworkId'] ?? $row['project_artwork_id'] ?? 0);
            if ($artworkId > 0) $artworkIds[$artworkId] = $artworkId;
            $rows[] = ['row' => $row, 'snapshot' => $snapshot, 'kind' => $kind, 'artworkId' => $artworkId];
        }

        $artworks = $this->artworkIdentityMap($userId, array_values($artworkIds));
        $artistName = trim((string)(ArtistProfile::findForUser($userId)['artist_name'] ?? ''));
        $finals = [];
        foreach ($rows as $item) {
            $row = $item['row'];
            $snapshot = $item['snapshot'];
            $kind = (string)$item['kind'];
            $artworkId = (int)$item['artworkId'];
            $artwork = $artworks[$artworkId] ?? null;
            $artworkTitle = is_array($artwork) ? trim((string)$artwork['artworkTitle']) : '';
            $projectTitle = trim((string)$row['project_title']) ?: 'Video final';
            $displayTitle = $artworkTitle !== ''
                ? trim($artworkTitle . ($artistName !== '' ? ' — ' . $artistName : ''))
                : $projectTitle;
            $fileTitle = $artworkTitle !== '' ? $artworkTitle : $projectTitle;
            $seoFileBase = Display::slugify(trim($fileTitle . ($artistName !== '' ? ' ' . $artistName : '') . ' art video'));

            $final = $this->normalizeExport($row);
            $final['projectId'] = (int)$row['video_project_id'];
            $final['projectTitle'] = $projectTitle;
            $final['source'] = $kind === 'uploaded_final' ? 'desktop' : 'studio';
            $final['originalName'] = (string)($snapshot['originalName'] ?? '');
            $final['artworkId'] = $artworkId;
            $final['canonicalArtworkId'] = is_array($artwork) ? (int)$artwork['canonicalArtworkId'] : 0;
            $final['artworkGroupId'] = is_array($artwork) ? (int)$artwork['artworkGroupId'] : 0;
            $final['artworkTitle'] = $artworkTitle;
            $final['artistName'] = $artistName;
            $final['displayTitle'] = $displayTitle;
            $final['seoFileBase'] = $seoFileBase !== '' ? $seoFileBase : 'video-final';
            $final['associationMissing'] = $artworkTitle === '';
            $finals[] = $final;
        }
        return $finals;
    }

    public function finalVideosForArtwork(int $userId, int $artworkId): array
    {
        $target = $this->artworkIdentityMap($userId, [$artworkId])[$artworkId] ?? null;
        if (!is_array($target)) return [];

        return array_values(array_filter($this->finalVideos($userId), static function (array $final) use ($target): bool {
            $targetGroupId = (int)$target['artworkGroupId'];
            $finalGroupId = (int)($final['artworkGroupId'] ?? 0);
            if ($targetGroupId > 0 && $finalGroupId > 0) return $targetGroupId === $finalGroupId;

            $targetCanonicalId = (int)$target['canonicalArtworkId'];
            $finalCanonicalId = (int)($final['canonicalArtworkId'] ?? 0);
            return $targetCanonicalId > 0 && $targetCanonicalId === $finalCanonicalId;
        }));
    }

    public function artworkIdentity(int $userId, int $artworkId): ?array
    {
        $identity = $this->artworkIdentityMap($userId, [$artworkId])[$artworkId] ?? null;
        return is_array($identity) ? $identity : null;
    }

    public function assignFinalArtwork(int $userId, int $exportId, int $artworkId): array
    {
        $artwork = $this->artworkIdentityMap($userId, [$artworkId])[$artworkId] ?? null;
        if (!is_array($artwork)) throw new OutOfBoundsException('Obra no encontrada.');

        $stmt = $this->pdo->prepare("SELECT e.id,e.timeline_snapshot_json FROM video_exports e
            INNER JOIN video_projects p ON p.id=e.video_project_id AND p.user_id=e.user_id
            WHERE e.id=? AND e.user_id=? AND e.status='succeeded' LIMIT 1");
        $stmt->execute([$exportId, $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new OutOfBoundsException('Video final no encontrado.');

        $snapshot = json_decode((string)($row['timeline_snapshot_json'] ?? ''), true);
        if (!is_array($snapshot)) $snapshot = [];
        if (!in_array((string)($snapshot['kind'] ?? ''), ['final', 'uploaded_final'], true)) {
            throw new DomainException('Este archivo no es un video final.');
        }

        $snapshot['artworkId'] = (int)$artwork['canonicalArtworkId'];
        $snapshot['artworkGroupId'] = (int)$artwork['artworkGroupId'];
        $snapshot['artworkTitle'] = (string)$artwork['artworkTitle'];
        $update = $this->pdo->prepare('UPDATE video_exports SET timeline_snapshot_json=?,updated_at=? WHERE id=? AND user_id=?');
        $update->execute([self::encode($snapshot), date('c'), $exportId, $userId]);

        foreach ($this->finalVideos($userId) as $final) {
            if ((int)$final['id'] === $exportId) return $final;
        }
        throw new RuntimeException('No se pudo actualizar la asociación del video.');
    }

    public function begin(): void
    {
        Database::beginWriteTransaction($this->pdo);
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function assetPayload(string $type, int $id, string $file, array $metadata): array
    {
        $width = is_numeric($metadata['width'] ?? null) ? (float)$metadata['width'] : null;
        $height = is_numeric($metadata['height'] ?? null) ? (float)$metadata['height'] : null;
        $orientation = $width && $height ? ($width > $height ? 'landscape' : ($height > $width ? 'portrait' : 'square')) : 'unknown';
        return [
            'assetKey' => $type . ':' . $id,
            'type' => $type,
            'id' => $id,
            'mediaType' => 'image',
            'label' => (string)$metadata['label'],
            'artworkId' => (int)($metadata['artworkId'] ?? 0),
            'artworkTitle' => (string)($metadata['artworkTitle'] ?? ''),
            'individualArtworkTitle' => (string)($metadata['individualArtworkTitle'] ?? $metadata['artworkTitle'] ?? ''),
            'artworkGroupId' => (int)($metadata['artworkGroupId'] ?? 0),
            'canonicalArtworkId' => (int)($metadata['canonicalArtworkId'] ?? $metadata['artworkId'] ?? 0),
            'groupTitle' => (string)($metadata['groupTitle'] ?? ''),
            'seriesId' => (int)($metadata['seriesId'] ?? 0),
            'seriesTitle' => (string)($metadata['seriesTitle'] ?? ''),
            'contextTitle' => (string)($metadata['contextTitle'] ?? ''),
            'thumbnailUrl' => 'media.php?file=' . rawurlencode($file) . '&thumb=1&w=560',
            'previewUrl' => 'media.php?file=' . rawurlencode($file),
            'orientation' => $orientation,
            'width' => $width,
            'height' => $height,
            'status' => 'available',
        ];
    }

    private function normalizeProject(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'description' => (string)$row['description'],
            'globalPrompt' => (string)$row['global_prompt'],
            'artworkId' => $row['artwork_id'] === null ? null : (int)$row['artwork_id'],
            'seriesId' => $row['series_id'] === null ? null : (int)$row['series_id'],
            'aspectRatio' => (string)$row['aspect_ratio'],
            'targetDurationSeconds' => (float)$row['target_duration_seconds'],
            'projectType' => (string)$row['project_type'],
            'status' => (string)$row['status'],
            'masterVolume' => (float)$row['master_volume'],
            'version' => (int)$row['version'],
            'sceneCount' => (int)($row['scene_count'] ?? 0),
            'generatedClipCount' => (int)($row['generated_clip_count'] ?? 0),
            'createdAt' => (string)$row['created_at'],
            'updatedAt' => (string)$row['updated_at'],
        ];
    }

    private function normalizeScene(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'projectId' => (int)$row['video_project_id'],
            'position' => (int)$row['position'],
            'title' => (string)$row['title'],
            'purpose' => (string)$row['purpose'],
            'prompt' => (string)$row['prompt'],
            'durationSeconds' => (float)$row['duration_seconds'],
            'generationMode' => (string)$row['generation_mode'],
            'artworkMotion' => (string)$row['artwork_motion'],
            'cameraMovement' => (string)$row['camera_movement'],
            'customCameraMovement' => (string)$row['custom_camera_movement'],
            'motionIntensity' => (string)$row['motion_intensity'],
            'transitionOut' => [
                'type' => (string)$row['transition_out_type'],
                'durationSeconds' => (float)$row['transition_out_duration_seconds'],
            ],
            'audioMode' => (string)$row['audio_mode'],
            'status' => (string)$row['status'],
            'editingLocked' => (bool)$row['is_locked'],
            'createdAt' => (string)$row['created_at'],
            'updatedAt' => (string)$row['updated_at'],
        ];
    }

    private function normalizeReference(array $row): array
    {
        $metadata = json_decode((string)$row['metadata_json'], true);
        if (!is_array($metadata)) $metadata = [];
        $type = (string)$row['source_type'];
        $sourceId = (int)$row['source_id'];
        $mediaType = (string)($metadata['mediaType'] ?? ($type === 'generation_job' ? 'video' : 'image'));
        $previewUrl = match ($type) {
            'generation_job' => 'video_media.php?generation_id=' . $sourceId,
            'reference_asset' => 'video_reference_media.php?asset_id=' . $sourceId,
            default => 'media.php?file=' . rawurlencode(basename((string)$row['file_path'])),
        };
        return [
            'id' => (int)$row['id'],
            'role' => (string)$row['role'],
            'sourceType' => $type,
            'sourceId' => $sourceId,
            'position' => (int)$row['position'],
            'label' => (string)($metadata['label'] ?? ucfirst(str_replace('_', ' ', $type)) . ' #' . $sourceId),
            'mediaType' => $mediaType,
            'previewUrl' => $previewUrl,
            'thumbnailUrl' => $mediaType === 'image' ? ($type === 'reference_asset' ? $previewUrl : $previewUrl . '&thumb=1&w=560') : '',
            'metadata' => $metadata,
        ];
    }

    public function normalizeJob(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'sceneId' => $row['video_scene_id'] === null ? null : (int)$row['video_scene_id'],
            'provider' => (string)$row['provider'],
            'model' => (string)$row['model'],
            'mode' => (string)$row['generation_mode'],
            'status' => (string)$row['status'],
            'requestedDurationSeconds' => (float)$row['requested_duration_seconds'],
            'generatedDurationSeconds' => (float)$row['generated_duration_seconds'],
            'aspectRatio' => (string)$row['aspect_ratio'],
            'error' => (string)$row['error'],
            'costEstimate' => $row['cost_estimate'] === null ? null : (float)$row['cost_estimate'],
            'costCurrency' => (string)$row['cost_currency'],
            'previewUrl' => (string)$row['output_path'] !== '' ? 'video_media.php?generation_id=' . (int)$row['id'] : '',
            'thumbnailUrl' => (string)$row['thumbnail_path'] !== '' ? 'video_media.php?generation_id=' . (int)$row['id'] . '&thumbnail=1' : '',
            'createdAt' => (string)$row['created_at'],
            'updatedAt' => (string)$row['updated_at'],
        ];
    }

    private function normalizeExport(array $row): array
    {
        $snapshot = json_decode((string)($row['timeline_snapshot_json'] ?? ''), true);
        if (!is_array($snapshot)) $snapshot = [];
        return [
            'id' => (int)$row['id'],
            'status' => (string)$row['status'],
            'aspectRatio' => (string)$row['aspect_ratio'],
            'durationSeconds' => (float)$row['duration_seconds'],
            'bytes' => (int)$row['bytes'],
            'error' => (string)$row['error'],
            'previewUrl' => (string)$row['output_path'] !== '' ? 'video_media.php?export_id=' . (int)$row['id'] : '',
            'thumbnailUrl' => trim((string)($snapshot['thumbnailPath'] ?? '')) !== '' ? 'video_media.php?export_id=' . (int)$row['id'] . '&thumbnail=1' : '',
            'createdAt' => (string)$row['created_at'],
            'updatedAt' => (string)$row['updated_at'],
        ];
    }

    private static function orientationFromAspect(string $aspect): string
    {
        return match ($aspect) {
            '9:16', '4:5' => 'portrait',
            '16:9' => 'landscape',
            '1:1' => 'square',
            default => 'unknown',
        };
    }

    private static function artworkTitle(array $row, string $fallback): string
    {
        return trim((string)($row['final_title'] ?? ''))
            ?: trim((string)($row['sheet_title'] ?? ''))
            ?: trim((string)($row['group_title'] ?? ''))
            ?: $fallback;
    }

    private function artworkIdentityMap(int $userId, array $artworkIds): array
    {
        $artworkIds = array_values(array_unique(array_filter(array_map('intval', $artworkIds), static fn(int $id): bool => $id > 0)));
        if ($artworkIds === []) return [];

        $placeholders = implode(',', array_fill(0, count($artworkIds), '?'));
        $stmt = $this->pdo->prepare("SELECT a.id,a.artwork_group_id,a.final_title,
                COALESCE(g.canonical_artwork_id,a.id) AS canonical_artwork_id,g.title AS group_title,
                sh.title AS sheet_title
            FROM artworks a
            LEFT JOIN artwork_groups g ON g.id=a.artwork_group_id AND g.user_id=a.user_id AND g.status='active'
            LEFT JOIN artwork_sheets sh ON sh.id=(SELECT MAX(sh2.id) FROM artwork_sheets sh2
                WHERE sh2.user_id=a.user_id AND sh2.canonical_artwork_id=COALESCE(g.canonical_artwork_id,a.id))
            WHERE a.user_id=? AND a.id IN ({$placeholders})");
        $stmt->execute(array_merge([$userId], $artworkIds));

        $identities = [];
        foreach ($stmt->fetchAll() as $row) {
            $groupTitle = trim((string)($row['group_title'] ?? ''));
            $title = $groupTitle
                ?: trim((string)($row['sheet_title'] ?? ''))
                ?: trim((string)($row['final_title'] ?? ''))
                ?: 'Artwork #' . (int)$row['id'];
            $identities[(int)$row['id']] = [
                'artworkId' => (int)$row['id'],
                'canonicalArtworkId' => (int)($row['canonical_artwork_id'] ?? $row['id']),
                'artworkGroupId' => (int)($row['artwork_group_id'] ?? 0),
                'artworkTitle' => $title,
            ];
        }
        return $identities;
    }

    private static function orientationFromDimensions(mixed $width, mixed $height): string
    {
        $width = is_numeric($width) ? (int)$width : 0;
        $height = is_numeric($height) ? (int)$height : 0;
        if ($width <= 0 || $height <= 0) return 'unknown';
        if ($width === $height) return 'square';
        return $width > $height ? 'landscape' : 'portrait';
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
