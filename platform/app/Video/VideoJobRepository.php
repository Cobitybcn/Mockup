<?php
declare(strict_types=1);

final class VideoJobRepository
{
    private const MAX_STORED_RESPONSE_BYTES = 2 * 1024 * 1024;
    private const MAX_STORED_RESPONSE_STRING_BYTES = 64 * 1024;

    public function __construct(private PDO $pdo) {}

    public function generationContext(int $userId, int $sceneId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT
            s.*,p.user_id,p.title AS project_title,p.global_prompt,p.aspect_ratio,p.version AS project_version,
            p.artwork_id AS project_artwork_id,p.series_id AS project_series_id
            FROM video_scenes s
            INNER JOIN video_projects p ON p.id=s.video_project_id
            WHERE s.id=? AND p.user_id=?
            LIMIT 1");
        $stmt->execute([$sceneId, $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) return null;

        $refStmt = $this->pdo->prepare('SELECT id,role,source_type,source_id,position,file_path,metadata_json
            FROM video_scene_references WHERE video_scene_id=? ORDER BY position,id');
        $refStmt->execute([$sceneId]);
        $references = [];
        $referenceList = [];
        foreach ($refStmt->fetchAll() as $reference) {
            $normalized = [
                'id' => (int)$reference['id'],
                'role' => (string)$reference['role'],
                'sourceType' => (string)$reference['source_type'],
                'sourceId' => (int)$reference['source_id'],
                'position' => (int)$reference['position'],
                'filePath' => (string)$reference['file_path'],
                'metadata' => json_decode((string)$reference['metadata_json'], true) ?: [],
            ];
            $referenceList[] = $normalized;
            $references[(string)$reference['role']] ??= $normalized;
        }
        $primaryReference = $references['main'] ?? $references['start_frame'] ?? null;
        if ($primaryReference === null) {
            foreach ($referenceList as $candidate) {
                if (($candidate['metadata']['mediaType'] ?? 'image') === 'image') {
                    $primaryReference = $candidate;
                    break;
                }
            }
        }

        return [
            'project' => [
                'id' => (int)$row['video_project_id'],
                'userId' => (int)$row['user_id'],
                'title' => (string)$row['project_title'],
                'globalPrompt' => (string)$row['global_prompt'],
                'aspectRatio' => (string)$row['aspect_ratio'],
                'version' => (int)$row['project_version'],
                'artworkId' => $row['project_artwork_id'] === null ? null : (int)$row['project_artwork_id'],
                'seriesId' => $row['project_series_id'] === null ? null : (int)$row['project_series_id'],
            ],
            'scene' => [
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'prompt' => (string)$row['prompt'],
                'durationSeconds' => (float)$row['duration_seconds'],
                'generationMode' => (string)$row['generation_mode'],
                'artworkMotion' => (string)$row['artwork_motion'],
                'cameraMovement' => (string)$row['camera_movement'],
                'customCameraMovement' => (string)$row['custom_camera_movement'],
                'motionIntensity' => (string)$row['motion_intensity'],
                'editingLocked' => (bool)$row['is_locked'],
            ],
            'reference' => $primaryReference,
            'references' => $references,
            'referenceList' => $referenceList,
        ];
    }

    public function previousSuccessfulGeneration(int $userId, int $sceneId): ?array
    {
        $sceneStmt = $this->pdo->prepare('SELECT previous.id
            FROM video_scenes current
            INNER JOIN video_projects p ON p.id=current.video_project_id
            INNER JOIN video_scenes previous ON previous.video_project_id=current.video_project_id AND previous.position<current.position
            WHERE current.id=? AND p.user_id=?
            ORDER BY previous.position DESC,previous.id DESC LIMIT 1');
        $sceneStmt->execute([$sceneId, $userId]);
        $previousSceneId = (int)$sceneStmt->fetchColumn();
        if ($previousSceneId <= 0) return null;

        $jobStmt = $this->pdo->prepare("SELECT * FROM video_generation_jobs
            WHERE video_scene_id=? AND user_id=? AND status='succeeded' AND active_slot=1
            ORDER BY id DESC LIMIT 1");
        $jobStmt->execute([$previousSceneId, $userId]);
        $job = $jobStmt->fetch();
        if (!is_array($job)) return null;
        $job['previous_scene_id'] = $previousSceneId;
        return $job;
    }

    public function activeGenerationForScene(int $userId, int $sceneId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT j.* FROM video_generation_jobs j
            INNER JOIN video_projects p ON p.id=j.video_project_id AND p.user_id=j.user_id
            WHERE j.user_id=? AND j.video_scene_id=? AND j.status='succeeded' AND j.active_slot=1 AND p.user_id=?
            ORDER BY j.id DESC LIMIT 1");
        $stmt->execute([$userId, $sceneId, $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function pendingGeneration(int $userId, int $sceneId, string $inputHash): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM video_generation_jobs
            WHERE user_id=? AND video_scene_id=? AND input_hash=? AND status IN ('queued','submitting','polling','processing')
            ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId, $sceneId, $inputHash]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array{artworkId:?int,artworkGroupId:?int,canonicalArtworkId:?int,seriesId:?int} */
    public function associationForReference(int $userId, array $reference): array
    {
        $type = (string)($reference['sourceType'] ?? '');
        $sourceId = (int)($reference['sourceId'] ?? 0);
        if ($sourceId <= 0) return self::emptyArtworkAssociation();

        if ($type === 'mockup') {
            $stmt = $this->pdo->prepare('SELECT m.source_artwork_id AS artwork_id,
                    COALESCE(m.artwork_group_id,a.artwork_group_id) AS artwork_group_id,g.canonical_artwork_id,
                    COALESCE(m.series_id,canonical.series_id,a.series_id) AS series_id
                FROM mockups m
                LEFT JOIN artworks a ON a.id=m.source_artwork_id AND a.user_id=m.user_id
                LEFT JOIN artwork_groups g ON g.id=COALESCE(m.artwork_group_id,a.artwork_group_id) AND g.user_id=m.user_id AND g.status=\'active\'
                LEFT JOIN artworks canonical ON canonical.id=g.canonical_artwork_id AND canonical.user_id=m.user_id
                WHERE m.id=? AND m.user_id=? LIMIT 1');
            $stmt->execute([$sourceId, $userId]);
        } elseif ($type === 'artwork') {
            $stmt = $this->pdo->prepare('SELECT a.id AS artwork_id,a.artwork_group_id,g.canonical_artwork_id,
                    COALESCE(canonical.series_id,a.series_id) AS series_id
                FROM artworks a
                LEFT JOIN artwork_groups g ON g.id=a.artwork_group_id AND g.user_id=a.user_id AND g.status=\'active\'
                LEFT JOIN artworks canonical ON canonical.id=g.canonical_artwork_id AND canonical.user_id=a.user_id
                WHERE a.id=? AND a.user_id=? LIMIT 1');
            $stmt->execute([$sourceId, $userId]);
        } else {
            return self::emptyArtworkAssociation();
        }

        $row = $stmt->fetch();
        if (!is_array($row)) return self::emptyArtworkAssociation();
        return [
            'artworkId' => $row['artwork_id'] === null ? null : (int)$row['artwork_id'],
            'artworkGroupId' => $row['artwork_group_id'] === null ? null : (int)$row['artwork_group_id'],
            'canonicalArtworkId' => $row['canonical_artwork_id'] === null ? null : (int)$row['canonical_artwork_id'],
            'seriesId' => $row['series_id'] === null ? null : (int)$row['series_id'],
        ];
    }

    private static function emptyArtworkAssociation(): array
    {
        return ['artworkId' => null, 'artworkGroupId' => null, 'canonicalArtworkId' => null, 'seriesId' => null];
    }

    public function createGeneration(array $values): int
    {
        $now = date('c');
        $stmt = $this->pdo->prepare('INSERT INTO video_generation_jobs
            (user_id,video_project_id,video_scene_id,artwork_id,series_id,provider,model,generation_mode,status,idempotency_key,scene_version,input_hash,requested_duration_seconds,aspect_ratio,prompt_text,request_json,response_json,error,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?, ?,\'queued\',?,?,?,?,?,?,?,\'\',\'\',?,?)');
        $stmt->execute([
            $values['user_id'],$values['project_id'],$values['scene_id'],$values['artwork_id'] ?? null,$values['series_id'] ?? null,
            $values['provider'],$values['model'],$values['mode'],
            $values['idempotency_key'],$values['scene_version'],$values['input_hash'],$values['duration_seconds'],$values['aspect_ratio'],
            $values['prompt'],$values['request_json'],$now,$now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findGeneration(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_generation_jobs WHERE id=? LIMIT 1');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function findGenerationForUser(int $userId, int $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_generation_jobs WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$jobId, $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function claimGeneration(int $jobId): ?array
    {
        $now = date('c');
        $stmt = $this->pdo->prepare("UPDATE video_generation_jobs SET status='submitting',attempts=attempts+1,started_at=COALESCE(started_at,?),updated_at=? WHERE id=? AND status='queued'");
        $stmt->execute([$now,$now,$jobId]);
        return $stmt->rowCount() === 1 ? $this->findGeneration($jobId) : null;
    }

    public function markGenerationPolling(int $jobId, string $externalId, array $response, string $nextPollAt): void
    {
        $stmt = $this->pdo->prepare("UPDATE video_generation_jobs SET status='polling',external_job_id=?,response_json=?,next_poll_at=?,error='',updated_at=? WHERE id=? AND status IN ('submitting','polling')");
        $stmt->execute([$externalId,self::encodeProviderResponse($response),$nextPollAt,date('c'),$jobId]);
    }

    public function markGenerationProcessing(int $jobId, array $response, string $nextPollAt): void
    {
        $stmt = $this->pdo->prepare("UPDATE video_generation_jobs SET status='polling',response_json=?,next_poll_at=?,updated_at=? WHERE id=? AND status IN ('polling','processing')");
        $stmt->execute([self::encodeProviderResponse($response),$nextPollAt,date('c'),$jobId]);
    }

    public function markGenerationSucceeded(int $jobId, string $outputPath, string $thumbnailPath, float $duration, array $response): void
    {
        $job = $this->findGeneration($jobId);
        if (!$job) throw new OutOfBoundsException('Video generation job not found.');
        $this->pdo->beginTransaction();
        try {
            if ($job['video_scene_id'] !== null) {
                $this->pdo->prepare('UPDATE video_generation_jobs SET active_slot=NULL WHERE video_scene_id=? AND active_slot=1')->execute([(int)$job['video_scene_id']]);
            }
            $stmt = $this->pdo->prepare("UPDATE video_generation_jobs SET status='succeeded',active_slot=1,output_path=?,thumbnail_path=?,generated_duration_seconds=?,response_json=?,next_poll_at=NULL,error='',completed_at=?,updated_at=? WHERE id=?");
            $now = date('c');
            $stmt->execute([$outputPath,$thumbnailPath,$duration,self::encodeProviderResponse($response),$now,$now,$jobId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function markGenerationFailed(int $jobId, string $error, array $response = []): void
    {
        $stmt = $this->pdo->prepare("UPDATE video_generation_jobs SET status='failed',active_slot=NULL,response_json=?,error=?,next_poll_at=NULL,completed_at=?,updated_at=? WHERE id=?");
        $now = date('c');
        $stmt->execute([self::encodeProviderResponse($response),mb_substr($error,0,4000),$now,$now,$jobId]);
    }

    public function updateGenerationTask(int $jobId, string $taskName): void
    {
        $this->pdo->prepare('UPDATE video_generation_jobs SET task_name=?,updated_at=? WHERE id=?')->execute([$taskName,date('c'),$jobId]);
    }

    public function latestGenerationForProject(int $userId, int $projectId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_generation_jobs WHERE user_id=? AND video_project_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId,$projectId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function createExport(array $values): int
    {
        $now = date('c');
        $stmt = $this->pdo->prepare("INSERT INTO video_exports
            (user_id,video_project_id,status,format,video_codec,audio_codec,aspect_ratio,timeline_snapshot_json,error,created_at,updated_at)
            VALUES (?,?,'queued','mp4','h264','aac',?,?,'',?,?)");
        $stmt->execute([$values['user_id'],$values['project_id'],$values['aspect_ratio'],self::encode($values['snapshot']),$now,$now]);
        return (int)$this->pdo->lastInsertId();
    }

    public function createUploadedFinal(array $values): int
    {
        $now = date('c');
        $stmt = $this->pdo->prepare("INSERT INTO video_exports
            (user_id,video_project_id,status,format,video_codec,audio_codec,aspect_ratio,timeline_snapshot_json,output_path,duration_seconds,bytes,error,started_at,completed_at,created_at,updated_at)
            VALUES (?,?,'succeeded','mp4','h264','aac',?,?,?, ?,?,'',?,?,?,?)");
        $stmt->execute([
            $values['user_id'],$values['project_id'],$values['aspect_ratio'],self::encode($values['snapshot']),$values['output_path'],
            $values['duration_seconds'],$values['bytes'],$now,$now,$now,$now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function exportTimeline(int $userId, int $projectId): array
    {
        $stmt = $this->pdo->prepare("SELECT s.id,s.position,s.title,s.duration_seconds,s.transition_out_type,s.transition_out_duration_seconds,
            j.id AS generation_id,j.output_path,j.generated_duration_seconds
            FROM video_scenes s
            INNER JOIN video_projects p ON p.id=s.video_project_id
            LEFT JOIN video_generation_jobs j ON j.video_scene_id=s.id AND j.active_slot=1 AND j.status='succeeded'
            WHERE s.video_project_id=? AND p.user_id=? ORDER BY s.position,s.id");
        $stmt->execute([$projectId,$userId]);
        return array_map(static fn(array $row): array => [
            'sceneId' => (int)$row['id'],
            'position' => (int)$row['position'],
            'title' => (string)$row['title'],
            'durationSeconds' => (float)$row['duration_seconds'],
            'transition' => ['type' => (string)$row['transition_out_type'], 'durationSeconds' => (float)$row['transition_out_duration_seconds']],
            'generationId' => $row['generation_id'] === null ? null : (int)$row['generation_id'],
            'outputPath' => (string)($row['output_path'] ?? ''),
            'generatedDurationSeconds' => (float)($row['generated_duration_seconds'] ?? 0),
        ], $stmt->fetchAll());
    }

    public function pendingExport(int $userId, int $projectId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM video_exports WHERE user_id=? AND video_project_id=? AND status IN ('queued','processing') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId,$projectId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function findExport(int $exportId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_exports WHERE id=? LIMIT 1');
        $stmt->execute([$exportId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function claimExport(int $exportId): ?array
    {
        $now = date('c');
        $stmt = $this->pdo->prepare("UPDATE video_exports SET status='processing',started_at=COALESCE(started_at,?),updated_at=? WHERE id=? AND status='queued'");
        $stmt->execute([$now,$now,$exportId]);
        return $stmt->rowCount() === 1 ? $this->findExport($exportId) : null;
    }

    public function updateExportTask(int $exportId, string $taskName): void
    {
        $this->pdo->prepare('UPDATE video_exports SET task_name=?,updated_at=? WHERE id=?')->execute([$taskName,date('c'),$exportId]);
    }

    public function markExportSucceeded(int $exportId, string $path, float $duration, int $bytes): void
    {
        $now = date('c');
        $stmt = $this->pdo->prepare("UPDATE video_exports SET status='succeeded',output_path=?,duration_seconds=?,bytes=?,error='',completed_at=?,updated_at=? WHERE id=?");
        $stmt->execute([$path,$duration,$bytes,$now,$now,$exportId]);
    }

    public function markExportFailed(int $exportId, string $error): void
    {
        $now = date('c');
        $stmt = $this->pdo->prepare("UPDATE video_exports SET status='failed',error=?,completed_at=?,updated_at=? WHERE id=?");
        $stmt->execute([mb_substr($error,0,4000),$now,$now,$exportId]);
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private static function encodeProviderResponse(array $value): string
    {
        $compacted = self::compactProviderResponse($value);
        $encoded = self::encode(is_array($compacted) ? $compacted : []);
        if (strlen($encoded) <= self::MAX_STORED_RESPONSE_BYTES) return $encoded;

        $summary = [];
        foreach (['id','status','model','name','create_time','update_time','error'] as $key) {
            if (array_key_exists($key, $value)) $summary[$key] = self::compactProviderResponse($value[$key], $key);
        }
        return self::encode([
            '_storage_notice' => 'Provider response metadata exceeded the diagnostic storage limit and was summarized.',
            'compacted_bytes' => strlen($encoded),
            'summary' => $summary,
        ]);
    }

    private static function compactProviderResponse(mixed $value, string $field = ''): mixed
    {
        if (is_string($value) && strlen($value) > self::MAX_STORED_RESPONSE_STRING_BYTES) {
            return [
                '_omitted' => 'Large provider payload stored separately from the database.',
                'field' => $field,
                'bytes' => strlen($value),
                'sha256' => hash('sha256', $value),
            ];
        }
        if (!is_array($value)) return $value;

        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = self::compactProviderResponse($item, (string)$key);
        }
        return $result;
    }
}
