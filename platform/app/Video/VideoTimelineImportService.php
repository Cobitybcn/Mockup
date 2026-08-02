<?php
declare(strict_types=1);

final class VideoTimelineImportService
{
    private const MAX_BYTES = 200 * 1024 * 1024;
    private const TYPES = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm'];

    public function __construct(private VideoStudioRepository $repository) {}

    public function signedDestination(int $userId, int $projectId, int $version, string $contentType, int $bytes): ?array
    {
        $this->project($userId, $projectId, $version);
        if ($bytes <= 0 || $bytes > self::MAX_BYTES) throw new InvalidArgumentException('An imported clip can be up to 200 MB.');
        $mime = strtolower(trim($contentType));
        $extension = self::TYPES[$mime] ?? null;
        if ($extension === null) throw new InvalidArgumentException('Use MP4, MOV or WebM.');
        $key = sprintf('storage/video/timeline/%d/%s.%s', $userId, bin2hex(random_bytes(18)), $extension);
        $url = StorageService::getSignedUploadUrl($key, $mime);
        return $url === null ? null : ['uploadUrl' => $url, 'objectKey' => $key, 'contentType' => $mime];
    }

    public function attachObject(int $userId, int $projectId, int $version, string $objectKey, string $originalName): array
    {
        $this->project($userId, $projectId, $version);
        if (!str_starts_with($objectKey, sprintf('storage/video/timeline/%d/', $userId))) throw new InvalidArgumentException('The imported file is invalid.');
        $bytes = StorageService::objectSize($objectKey);
        if ($bytes === null || $bytes <= 0 || $bytes > self::MAX_BYTES) {
            StorageService::delete($objectKey);
            throw new InvalidArgumentException('An imported clip can be up to 200 MB.');
        }
        $readable = StorageService::isGcsActive()
            ? (string)(StorageService::getSignedUrl($objectKey, 30) ?? '')
            : (new VideoMediaStorage())->localObjectPath($objectKey);
        if ($readable === '' || (!StorageService::isGcsActive() && !is_file($readable))) {
            StorageService::delete($objectKey);
            throw new RuntimeException('The imported video could not be read.');
        }
        $duration = VideoFfmpeg::duration($readable);
        if ($duration < 0.2) {
            StorageService::delete($objectKey);
            throw new InvalidArgumentException('The video duration could not be read.');
        }
        return $this->registerStored($userId, $projectId, $version, $objectKey, $originalName, (int)$bytes, $duration, VideoFfmpeg::hasAudio($readable));
    }

    public function upload(int $userId, int $projectId, int $version, array $file): array
    {
        $this->project($userId, $projectId, $version);
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Select an MP4, MOV or WebM video.');
        $path = (string)($file['tmp_name'] ?? '');
        if ($path === '' || !is_file($path) || (PHP_SAPI !== 'cli' && !is_uploaded_file($path))) throw new InvalidArgumentException('The imported file is invalid.');
        $bytes = (int)(filesize($path) ?: 0);
        if ($bytes <= 0 || $bytes > self::MAX_BYTES) throw new InvalidArgumentException('An imported clip can be up to 200 MB.');
        $mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file($path));
        $extension = self::TYPES[$mime] ?? null;
        if ($extension === null) throw new InvalidArgumentException('Use MP4, MOV or WebM.');
        $duration = VideoFfmpeg::duration($path);
        if ($duration < 0.2) throw new InvalidArgumentException('The video duration could not be read.');
        $key = sprintf('storage/video/timeline/%d/%s.%s', $userId, bin2hex(random_bytes(18)), $extension);
        if (!StorageService::uploadFile($key, $path)) throw new RuntimeException('The video could not be stored.');
        return $this->registerStored($userId, $projectId, $version, $key, (string)($file['name'] ?? 'Imported video'), $bytes, $duration, VideoFfmpeg::hasAudio($path));
    }

    private function registerStored(int $userId, int $projectId, int $version, string $key, string $originalName, int $bytes, float $duration, bool $hasAudio): array
    {
        $extension = strtolower((string)pathinfo($key, PATHINFO_EXTENSION));
        $mime = array_search($extension, self::TYPES, true) ?: 'video/mp4';
        try {
            $assetId = $this->repository->createReferenceAsset($userId, [
                'filePath' => $key,
                'originalName' => $this->cleanName($originalName),
                'mimeType' => $mime,
                'mediaType' => 'video',
                'byteSize' => $bytes,
                'durationSeconds' => $duration,
                'hasAudio' => $hasAudio,
            ]);
            $this->repository->touchProject($userId, $projectId, $version);
        } catch (Throwable $e) {
            StorageService::delete($key);
            throw $e;
        }
        $studio = new VideoStudioService($this->repository);
        return $studio->studioPayload($userId, $projectId) + [
            'assets' => $studio->library($userId),
            'importedAssetKey' => 'reference_asset:' . $assetId,
        ];
    }

    private function project(int $userId, int $projectId, int $version): array
    {
        $project = $this->repository->findProject($userId, $projectId);
        if (!$project) throw new OutOfBoundsException('Video project not found.');
        if ((int)$project['version'] !== $version) throw new DomainException('The project changed. Reload before importing.');
        return $project;
    }

    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));
        return mb_substr($name !== '' ? $name : 'Imported video', 0, 255);
    }
}
