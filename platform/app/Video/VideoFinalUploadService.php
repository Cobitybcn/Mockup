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

    /**
     * Attaching a finished desktop edit must not make the artist think about
     * "video projects": reuse the artwork's project when one exists, create it
     * silently otherwise. Used by the Publication section, where the artist is
     * composing the page and simply has a finished file to attach.
     */
    public function uploadForArtwork(int $userId, int $artworkId, array $file): array
    {
        if ($artworkId <= 0) throw new InvalidArgumentException('Selecciona la obra correspondiente al video final.');
        $artwork = $this->studio->artworkIdentity($userId, $artworkId);
        if (!$artwork) throw new OutOfBoundsException('Obra no encontrada.');
        $canonicalId = (int)($artwork['canonicalArtworkId'] ?? $artworkId);

        $projectId = 0;
        foreach ($this->studio->listProjects($userId) as $project) {
            if ((int)($project['artworkId'] ?? 0) === $canonicalId) {
                $projectId = (int)$project['id'];
                break;
            }
        }
        if ($projectId <= 0) {
            $created = (new VideoStudioService($this->studio))->createProject($userId, [
                'artworkId' => $canonicalId,
                'aspectRatio' => '9:16',
                'projectType' => 'social_clip',
            ]);
            $projectId = (int)($created['project']['id'] ?? 0);
        }
        if ($projectId <= 0) throw new RuntimeException('No se pudo preparar el destino del video final.');

        return $this->upload($userId, $projectId, $file, $canonicalId);
    }

    /**
     * Where the browser should PUT a finished video, and under what name. The
     * file goes straight to the bucket: Cloud Run refuses a request over 32 MiB,
     * which every real final video exceeds.
     *
     * @return array{uploadUrl:string,objectKey:string,contentType:string}|null
     *         null when there is no bucket, so the caller posts the file instead.
     */
    public function signedDestination(int $userId, int $projectId, string $fileName, string $contentType, int $bytes): ?array
    {
        if ($bytes > self::MAX_BYTES) throw new InvalidArgumentException('El video final puede ocupar hasta 500 MB.');
        if ($bytes <= 0) throw new InvalidArgumentException('El video está vacío.');
        $extension = self::TYPES[strtolower(trim($contentType))] ?? null;
        if ($extension === null) throw new InvalidArgumentException('Usa un video MP4, MOV o WebM.');
        if (!$this->studio->findProject($userId, $projectId)) throw new OutOfBoundsException('Proyecto de video no encontrado.');

        $key = sprintf('storage/video/finals/%d/%d/%s.%s', $userId, $projectId, bin2hex(random_bytes(16)), $extension);
        $url = StorageService::getSignedUploadUrl($key, strtolower(trim($contentType)));
        return $url === null ? null : ['uploadUrl' => $url, 'objectKey' => $key, 'contentType' => strtolower(trim($contentType))];
    }

    /**
     * Register a video the browser already placed in the bucket. It is probed
     * over a signed URL rather than downloaded: the container's temp space is
     * memory, and a finished video would sit in it whole.
     */
    public function attachObject(int $userId, int $projectId, string $objectKey, string $originalName, int $artworkId = 0): array
    {
        $expectedPrefix = sprintf('storage/video/finals/%d/', $userId);
        if (!str_starts_with($objectKey, $expectedPrefix)) {
            throw new InvalidArgumentException('El archivo recibido no es válido.');
        }
        $bytes = StorageService::objectSize($objectKey);
        if ($bytes === null || $bytes <= 0) throw new InvalidArgumentException('La subida no llegó completa. Intentá de nuevo.');
        if ($bytes > self::MAX_BYTES) {
            StorageService::delete($objectKey);
            throw new InvalidArgumentException('El video final puede ocupar hasta 500 MB.');
        }

        // ffprobe reads the object where it lies — a signed URL in the cloud, the
        // file itself on a local install — so a large video is never pulled into
        // the container's memory-backed temp space just to be measured.
        $readable = StorageService::isGcsActive()
            ? (string)(StorageService::getSignedUrl($objectKey, 30) ?? '')
            : (new VideoMediaStorage())->localObjectPath($objectKey);
        if ($readable === '' || (!StorageService::isGcsActive() && !is_file($readable))) {
            StorageService::delete($objectKey);
            throw new RuntimeException('No se pudo leer el video subido.');
        }
        $duration = VideoFfmpeg::duration($readable);
        if ($duration <= 0) {
            // Nothing ffprobe recognises as video: reject it and keep the bucket clean.
            StorageService::delete($objectKey);
            throw new InvalidArgumentException('El archivo no es un video que podamos leer. Usa MP4, MOV o WebM.');
        }

        $name = basename(str_replace('\\', '/', $originalName));
        $name = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));

        return $this->register($userId, $projectId, $artworkId, [
            'source' => $readable,
            'objectKey' => $objectKey,
            'bytes' => $bytes,
            'duration' => $duration,
            'name' => mb_substr($name !== '' ? $name : 'Video final', 0, 255),
        ]);
    }

    public function upload(int $userId, int $projectId, array $file, int $artworkId = 0): array
    {
        // register() resolves the project and artwork; this only has to reject a
        // bad file before it is worth uploading anything.
        $upload = $this->inspect($file);
        $outputKey = sprintf(
            'storage/video/finals/%d/%d/%s.%s',
            $userId,
            $projectId,
            bin2hex(random_bytes(16)),
            $upload['extension']
        );
        if (!StorageService::uploadFile($outputKey, $upload['path'])) {
            throw new RuntimeException('No se pudo guardar el video final.');
        }

        try {
            return $this->register($userId, $projectId, $artworkId, [
                'source' => $upload['path'],
                'objectKey' => $outputKey,
                'bytes' => $upload['bytes'],
                'duration' => $upload['duration'],
                'name' => $upload['name'],
            ]);
        } catch (Throwable $e) {
            StorageService::delete($outputKey);
            throw $e;
        }
    }

    /**
     * Shared tail of both routes: the video is already in the bucket, so all that
     * is left is a poster frame and the row that makes it a final video.
     *
     * @param array{source:string,objectKey:string,bytes:int,duration:float,name:string} $media
     */
    private function register(int $userId, int $projectId, int $artworkId, array $media): array
    {
        $project = $this->studio->findProject($userId, $projectId);
        if (!$project) throw new OutOfBoundsException('Proyecto de video no encontrado.');
        if ($artworkId <= 0) $artworkId = (int)($project['artworkId'] ?? 0);
        if ($artworkId <= 0) throw new InvalidArgumentException('Selecciona la obra correspondiente al video final.');
        $artwork = $this->studio->artworkIdentity($userId, $artworkId);
        if (!$artwork) throw new OutOfBoundsException('Obra no encontrada.');

        $thumbnailKey = (string)preg_replace('/\.[a-z0-9]+$/i', '.jpg', $media['objectKey']);
        $thumbnail = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'artworkmockups-final-' . bin2hex(random_bytes(8)) . '.jpg';
        $storedThumbnail = '';

        try {
            if (VideoFfmpeg::thumbnail($media['source'], $thumbnail) && StorageService::uploadFile($thumbnailKey, $thumbnail)) {
                $storedThumbnail = $thumbnailKey;
            }
            $exportId = $this->jobs->createUploadedFinal([
                'user_id' => $userId,
                'project_id' => $projectId,
                'aspect_ratio' => (string)$project['aspectRatio'],
                'output_path' => $media['objectKey'],
                'duration_seconds' => $media['duration'],
                'bytes' => $media['bytes'],
                'snapshot' => [
                    'kind' => 'uploaded_final',
                    'source' => 'desktop',
                    'originalName' => $media['name'],
                    'thumbnailPath' => $storedThumbnail,
                    'artworkId' => (int)$artwork['canonicalArtworkId'],
                    'artworkGroupId' => (int)$artwork['artworkGroupId'],
                    'artworkTitle' => (string)$artwork['artworkTitle'],
                ],
            ]);
        } catch (Throwable $e) {
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
