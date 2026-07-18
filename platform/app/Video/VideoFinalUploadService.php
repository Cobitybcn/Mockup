<?php
declare(strict_types=1);

final class VideoFinalUploadService
{
    private const MAX_BYTES = 500 * 1024 * 1024;
    private const TYPES = [
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
    ];

    public function __construct(
        private VideoStudioRepository $studio,
        private VideoJobRepository $jobs
    ) {}

    public function upload(int $userId, int $projectId, array $file, int $artworkId = 0): array
    {
        $project = $this->studio->findProject($userId, $projectId);
        if (!$project) throw new OutOfBoundsException('Proyecto de video no encontrado.');
        if ($artworkId <= 0) $artworkId = (int)($project['artworkId'] ?? 0);
        if ($artworkId <= 0) throw new InvalidArgumentException('Selecciona la obra correspondiente al video final.');
        $artwork = $this->studio->artworkIdentity($userId, $artworkId);
        if (!$artwork) throw new OutOfBoundsException('Obra no encontrada.');
        $upload = $this->inspect($file);
        $idToken = bin2hex(random_bytes(16));
        $outputKey = sprintf('storage/video/finals/%d/%d/%s.%s', $userId, $projectId, $idToken, $upload['extension']);
        $thumbnailKey = sprintf('storage/video/finals/%d/%d/%s.jpg', $userId, $projectId, $idToken);
        $thumbnail = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'artworkmockups-final-' . $idToken . '.jpg';
        $storedThumbnail = '';

        try {
            if (!StorageService::uploadFile($outputKey, $upload['path'])) {
                throw new RuntimeException('No se pudo guardar el video final.');
            }
            if (VideoFfmpeg::thumbnail($upload['path'], $thumbnail) && StorageService::uploadFile($thumbnailKey, $thumbnail)) {
                $storedThumbnail = $thumbnailKey;
            }
            $exportId = $this->jobs->createUploadedFinal([
                'user_id' => $userId,
                'project_id' => $projectId,
                'aspect_ratio' => (string)$project['aspectRatio'],
                'output_path' => $outputKey,
                'duration_seconds' => $upload['duration'],
                'bytes' => $upload['bytes'],
                'snapshot' => [
                    'kind' => 'uploaded_final',
                    'source' => 'desktop',
                    'originalName' => $upload['name'],
                    'thumbnailPath' => $storedThumbnail,
                    'artworkId' => (int)$artwork['canonicalArtworkId'],
                    'artworkGroupId' => (int)$artwork['artworkGroupId'],
                    'artworkTitle' => (string)$artwork['artworkTitle'],
                ],
            ]);
        } catch (Throwable $e) {
            StorageService::delete($outputKey);
            if ($storedThumbnail !== '') StorageService::delete($storedThumbnail);
            throw $e;
        } finally {
            @unlink($thumbnail);
        }

        return ['final' => ['id' => $exportId, 'previewUrl' => 'video_media.php?export_id=' . $exportId]];
    }

    private function inspect(array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($error === UPLOAD_ERR_NO_FILE ? 'Selecciona un video final.' : 'No se pudo recibir el video final.');
        }
        $path = (string)($file['tmp_name'] ?? '');
        if ($path === '' || !is_file($path) || (PHP_SAPI !== 'cli' && !is_uploaded_file($path))) {
            throw new InvalidArgumentException('El archivo recibido no es válido.');
        }
        $bytes = filesize($path);
        if ($bytes === false || $bytes <= 0) throw new InvalidArgumentException('El video está vacío.');
        if ($bytes > self::MAX_BYTES) throw new InvalidArgumentException('El video final puede ocupar hasta 500 MB.');
        $mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file($path));
        $extension = self::TYPES[$mime] ?? null;
        if ($extension === null) throw new InvalidArgumentException('Usa un video MP4, MOV o WebM.');
        $duration = VideoFfmpeg::duration($path);
        if ($duration <= 0) throw new InvalidArgumentException('No se pudo validar la duración del video.');
        $name = basename(str_replace('\\', '/', (string)($file['name'] ?? 'video-final')));
        $name = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));
        return [
            'path' => $path,
            'bytes' => (int)$bytes,
            'duration' => $duration,
            'extension' => $extension,
            'name' => mb_substr($name !== '' ? $name : 'Video final', 0, 255),
        ];
    }
}
