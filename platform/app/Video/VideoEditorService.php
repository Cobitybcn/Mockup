<?php
declare(strict_types=1);

final class VideoEditorService
{
    private const MAX_IMAGES = 10;
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;
    private const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private PDO $pdo,
        private VideoJobRepository $jobs,
        private VideoTaskDispatcher $dispatcher
    ) {}

    public function source(int $userId, string $type, int $id): ?array
    {
        if ($id <= 0) return null;
        if ($type === 'generation') {
            $stmt = $this->pdo->prepare("SELECT j.*,p.title AS project_title FROM video_generation_jobs j
                INNER JOIN video_projects p ON p.id=j.video_project_id AND p.user_id=j.user_id
                WHERE j.id=? AND j.user_id=? AND j.status='succeeded' AND j.output_path<>'' LIMIT 1");
            $stmt->execute([$id,$userId]);
            $row = $stmt->fetch();
            if (!is_array($row)) return null;
            return [
                'type' => 'generation','id' => $id,'projectId' => (int)$row['video_project_id'],
                'projectTitle' => (string)$row['project_title'],'title' => 'Video generado',
                'path' => (string)$row['output_path'],'previewUrl' => 'video_media.php?generation_id=' . $id,
                'durationSeconds' => (float)$row['generated_duration_seconds'],'aspectRatio' => (string)$row['aspect_ratio'],
                'provider' => (string)$row['provider'],'model' => (string)$row['model'],'interactionId' => (string)$row['external_job_id'],
                'artworkId' => $row['artwork_id'] === null ? null : (int)$row['artwork_id'],
                'seriesId' => $row['series_id'] === null ? null : (int)$row['series_id'],
            ];
        }
        if ($type === 'export') {
            $stmt = $this->pdo->prepare("SELECT e.*,p.title AS project_title FROM video_exports e
                INNER JOIN video_projects p ON p.id=e.video_project_id AND p.user_id=e.user_id
                WHERE e.id=? AND e.user_id=? AND e.status='succeeded' AND e.output_path<>'' LIMIT 1");
            $stmt->execute([$id,$userId]);
            $row = $stmt->fetch();
            if (!is_array($row)) return null;
            return [
                'type' => 'export','id' => $id,'projectId' => (int)$row['video_project_id'],
                'projectTitle' => (string)$row['project_title'],'title' => 'Video final',
                'path' => (string)$row['output_path'],'previewUrl' => 'video_media.php?export_id=' . $id,
                'durationSeconds' => (float)$row['duration_seconds'],'aspectRatio' => (string)$row['aspect_ratio'],
                'provider' => '','model' => '','interactionId' => '','artworkId' => null,'seriesId' => null,
            ];
        }
        if ($type === 'reference_asset') {
            $stmt = $this->pdo->prepare("SELECT r.*,sr.metadata_json,p.id AS project_id,p.title AS project_title,p.aspect_ratio
                FROM video_reference_assets r
                INNER JOIN video_scene_references sr ON sr.source_type='reference_asset' AND sr.source_id=r.id
                INNER JOIN video_scenes s ON s.id=sr.video_scene_id
                INNER JOIN video_projects p ON p.id=s.video_project_id AND p.user_id=r.user_id
                WHERE r.id=? AND r.user_id=? AND r.media_type='video' LIMIT 1");
            $stmt->execute([$id,$userId]);
            $row = $stmt->fetch();
            if (!is_array($row)) return null;
            $metadata = json_decode((string)$row['metadata_json'], true);
            if (!is_array($metadata)) $metadata = [];
            return [
                'type' => 'reference_asset','id' => $id,'projectId' => (int)$row['project_id'],
                'projectTitle' => (string)$row['project_title'],'title' => 'Video base',
                'path' => (string)$row['file_path'],'previewUrl' => 'video_reference_media.php?asset_id=' . $id,
                'durationSeconds' => (float)($metadata['durationSeconds'] ?? 0),'aspectRatio' => (string)$row['aspect_ratio'],
                'provider' => '','model' => '','interactionId' => '','artworkId' => null,'seriesId' => null,
            ];
        }
        return null;
    }

    /** @param list<array<string,mixed>> $files */
    public function start(int $userId, string $sourceType, int $sourceId, string $prompt, array $files): array
    {
        if (!ProviderSettings::allowRealApi()) throw new DomainException('Activa la generación real en API Settings antes de editar.');
        $source = $this->source($userId, $sourceType, $sourceId);
        if (!$source) throw new OutOfBoundsException('Video de origen no encontrado.');
        $prompt = trim(mb_substr($prompt, 0, 20000));
        if ($prompt === '') throw new InvalidArgumentException('Describe el cambio que quieres aplicar.');
        $duration = (float)$source['durationSeconds'];
        if ($duration <= 0 || $duration > VideoReferencePolicy::MAX_VIDEO_SECONDS + .05) {
            throw new DomainException('Omni solo puede editar un video de hasta 10 segundos.');
        }
        if (count($files) > self::MAX_IMAGES) throw new InvalidArgumentException('Puedes añadir hasta 10 imágenes de referencia.');

        $records = [];
        $stored = [];
        try {
            foreach (array_values($files) as $index => $file) {
                $image = $this->storeImage($userId, $file);
                $stored[] = $image['path'];
                $records[] = [
                    'id' => $index + 1,'role' => 'reference','sourceType' => 'editor_upload','sourceId' => 0,
                    'position' => $index + 1,'promptNumber' => $index + 1,'file' => $image['path'],
                    'mediaType' => 'image','mimeType' => $image['mimeType'],
                    'instruction' => 'Usar según la instrucción del prompt.',
                ];
            }

            $providerName = VideoProviderRegistry::OMNI;
            $model = trim(app_env('VIDEO_OMNI_MODEL', 'gemini-omni-flash-preview'));
            $snapshot = [
                'projectId' => (int)$source['projectId'],'intent' => 'standalone_edit','mode' => 'edit',
                'durationSeconds' => (int)max(3, min(10, round($duration))),
                'aspectRatio' => (string)$source['aspectRatio'],'resolution' => app_env('VIDEO_VEO_RESOLUTION', '720p'),
                'parent' => ['type' => $sourceType,'id' => $sourceId],
            ];
            // The current Omni preview rejects previous_interaction_id when the
            // request is a video edit task. Always submit the actual clip as the
            // source video; references and prompt remain fully supported.
            array_unshift($records, [
                'id' => $sourceId,'role' => 'source_video','sourceType' => $sourceType,'sourceId' => $sourceId,
                'position' => 0,'file' => (string)$source['path'],'mediaType' => 'video','mimeType' => '',
                'instruction' => 'Editar este video conservando lo no solicitado.',
            ]);
            $snapshot['references'] = $records;

            $hash = hash('sha256', json_encode([$snapshot,$prompt,$model,microtime(true)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $jobId = $this->jobs->createGeneration([
                'user_id' => $userId,'project_id' => (int)$source['projectId'],'scene_id' => null,
                'artwork_id' => $source['artworkId'],'series_id' => $source['seriesId'],
                'provider' => $providerName,'model' => $model,'mode' => 'edit',
                'idempotency_key' => bin2hex(random_bytes(32)),'scene_version' => 0,'input_hash' => $hash,
                'duration_seconds' => $snapshot['durationSeconds'],'aspect_ratio' => (string)$source['aspectRatio'],
                'prompt' => $prompt,'request_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ]);
            $task = $this->dispatcher->dispatchGeneration($jobId);
            $this->jobs->updateGenerationTask($jobId, $task);
        } catch (Throwable $e) {
            if (isset($jobId)) $this->jobs->markGenerationFailed($jobId, 'No se pudo iniciar la edición: ' . $e->getMessage());
            foreach ($stored as $path) StorageService::delete($path);
            throw $e;
        }
        return ['job' => $this->status($userId, $jobId)];
    }

    public function status(int $userId, int $jobId): array
    {
        $job = $this->jobs->findGenerationForUser($userId, $jobId);
        if (!$job) throw new OutOfBoundsException('Edición no encontrada.');
        return [
            'id' => (int)$job['id'],'status' => (string)$job['status'],'error' => (string)$job['error'],
            'previewUrl' => (string)$job['output_path'] !== '' ? 'video_media.php?generation_id=' . (int)$job['id'] : '',
            'editorUrl' => 'video_editor.php?generation_id=' . (int)$job['id'],
        ];
    }

    private function storeImage(int $userId, array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException('No se pudo recibir una imagen de referencia.');
        $temporary = (string)($file['tmp_name'] ?? '');
        if ($temporary === '' || !is_file($temporary) || (PHP_SAPI !== 'cli' && !is_uploaded_file($temporary))) {
            throw new InvalidArgumentException('Una imagen de referencia no es válida.');
        }
        $bytes = filesize($temporary);
        if ($bytes === false || $bytes <= 0 || $bytes > self::MAX_IMAGE_BYTES) throw new InvalidArgumentException('Cada imagen puede ocupar hasta 20 MB.');
        $mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file($temporary));
        $extension = self::IMAGE_TYPES[$mime] ?? null;
        if ($extension === null || !is_array(@getimagesize($temporary))) throw new InvalidArgumentException('Usa imágenes JPG, PNG, WebP o GIF.');
        $path = sprintf('storage/video/editor-references/%d/%s.%s', $userId, bin2hex(random_bytes(18)), $extension);
        if (!StorageService::uploadFile($path, $temporary)) throw new RuntimeException('No se pudo guardar una referencia.');
        return ['path' => $path,'mimeType' => $mime];
    }
}
