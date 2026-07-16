<?php
declare(strict_types=1);

final class VideoReferenceUploadService
{
    private const MAX_FILES = 10;
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;
    private const MAX_VIDEO_BYTES = 200 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 500 * 1024 * 1024;
    private const MAX_IMAGE_PIXELS = 120000000;
    private const MIME_TYPES = [
        'image/jpeg' => ['mediaType' => 'image', 'extension' => 'jpg'],
        'image/png' => ['mediaType' => 'image', 'extension' => 'png'],
        'image/webp' => ['mediaType' => 'image', 'extension' => 'webp'],
        'image/gif' => ['mediaType' => 'image', 'extension' => 'gif'],
        'video/mp4' => ['mediaType' => 'video', 'extension' => 'mp4'],
        'video/quicktime' => ['mediaType' => 'video', 'extension' => 'mov'],
        'video/webm' => ['mediaType' => 'video', 'extension' => 'webm'],
    ];

    public function __construct(private VideoStudioRepository $repository)
    {
    }

    /** @param list<array<string,mixed>> $files */
    public function upload(int $userId, int $sceneId, int $version, array $files, string $role = 'reference'): array
    {
        if ($files === [] || count($files) > self::MAX_FILES) {
            throw new InvalidArgumentException('Selecciona entre 1 y ' . self::MAX_FILES . ' archivos por carga.');
        }
        if (!in_array($role, VideoReferencePolicy::roles(), true)) {
            throw new InvalidArgumentException('El área de referencia seleccionada no es válida.');
        }
        if (VideoReferencePolicy::isSingle($role) && count($files) !== 1) {
            throw new InvalidArgumentException('Esta referencia admite un solo archivo.');
        }
        $scene = $this->repository->findScene($userId, $sceneId);
        if (!is_array($scene)) throw new OutOfBoundsException('Secuencia no encontrada.');
        if ((int)$scene['project_version'] !== $version) {
            throw new DomainException('El proyecto cambió. Recarga antes de subir referencias.');
        }
        if (!empty($scene['editingLocked'])) {
            throw new DomainException('Desbloquea esta secuencia antes de añadir referencias.');
        }

        $prepared = [];
        $totalBytes = 0;
        try {
            foreach ($files as $file) {
                $asset = $this->inspectUpload($file);
                $totalBytes += (int)$asset['byteSize'];
                if ($totalBytes > self::MAX_TOTAL_BYTES) {
                    throw new InvalidArgumentException('La carga completa puede ocupar hasta 500 MB.');
                }
                $prepared[] = $asset;
            }

            $this->assertRoleMedia($role, $prepared);
            $this->assertCapacity($sceneId, $role, $prepared);

            foreach ($prepared as &$asset) {
                $asset['filePath'] = sprintf(
                    'storage/video/references/%d/%s.%s',
                    $userId,
                    bin2hex(random_bytes(18)),
                    $asset['extension']
                );
                if (!StorageService::uploadFile($asset['filePath'], $asset['temporaryPath'])) {
                    throw new RuntimeException('No se pudo guardar “' . $asset['originalName'] . '”.');
                }
            }
            unset($asset);
        } catch (Throwable $e) {
            foreach ($prepared as $asset) {
                if (!empty($asset['filePath'])) StorageService::delete((string)$asset['filePath']);
            }
            throw $e;
        }

        $projectId = (int)$scene['projectId'];
        $this->repository->begin();
        try {
            foreach ($prepared as $asset) {
                $assetId = $this->repository->createReferenceAsset($userId, $asset);
                $metadata = [
                    'label' => $asset['originalName'],
                    'mediaType' => $asset['mediaType'],
                    'mimeType' => $asset['mimeType'],
                    'width' => $asset['width'],
                    'height' => $asset['height'],
                    'byteSize' => $asset['byteSize'],
                    'durationSeconds' => $asset['durationSeconds'],
                    'instruction' => VideoReferencePolicy::defaultInstruction($role),
                    'uploaded' => true,
                ];
                $this->repository->replaceReference($sceneId, $role, [
                    'source_type' => 'reference_asset',
                    'source_id' => $assetId,
                    'file_path' => $asset['filePath'],
                    'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                ]);
            }
            $this->repository->updateScene($sceneId, ['status' => 'draft']);
            $this->repository->touchProject($userId, $projectId, $version);
            $this->repository->commit();
        } catch (Throwable $e) {
            $this->repository->rollback();
            foreach ($prepared as $asset) StorageService::delete((string)$asset['filePath']);
            throw $e;
        }

        $studio = new VideoStudioService($this->repository);
        return $studio->studioPayload($userId, $projectId) + [
            'selectedSceneId' => $sceneId,
            'assets' => $studio->library($userId),
            'uploadedCount' => count($prepared),
        ];
    }

    /** @return array<string,mixed> */
    private function inspectUpload(array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException($this->uploadError($error));
        $path = (string)($file['tmp_name'] ?? '');
        if ($path === '' || !is_file($path) || (PHP_SAPI !== 'cli' && !is_uploaded_file($path))) {
            throw new InvalidArgumentException('El archivo recibido no es una subida válida.');
        }
        $bytes = filesize($path);
        if ($bytes === false || $bytes <= 0) throw new InvalidArgumentException('El archivo está vacío.');

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string)$finfo->file($path));
        $definition = self::MIME_TYPES[$mime] ?? null;
        if (!is_array($definition)) {
            throw new InvalidArgumentException('Formato no compatible. Usa JPG, PNG, WebP, GIF, MP4, MOV o WebM.');
        }
        $mediaType = (string)$definition['mediaType'];
        $limit = $mediaType === 'image' ? self::MAX_IMAGE_BYTES : self::MAX_VIDEO_BYTES;
        if ($bytes > $limit) {
            throw new InvalidArgumentException($mediaType === 'image'
                ? 'Las imágenes pueden ocupar hasta 20 MB.'
                : 'Los videos pueden ocupar hasta 200 MB.');
        }

        $width = null;
        $height = null;
        $durationSeconds = null;
        if ($mediaType === 'image') {
            $dimensions = @getimagesize($path);
            if (!is_array($dimensions) || (int)$dimensions[0] <= 0 || (int)$dimensions[1] <= 0) {
                throw new InvalidArgumentException('No se pudo validar la imagen.');
            }
            $width = (int)$dimensions[0];
            $height = (int)$dimensions[1];
            if ($width * $height > self::MAX_IMAGE_PIXELS) {
                throw new InvalidArgumentException('La imagen tiene demasiados píxeles.');
            }
        } else {
            $durationSeconds = VideoFfmpeg::duration($path);
            if ($durationSeconds <= 0) {
                throw new InvalidArgumentException('No se pudo validar la duración del video.');
            }
            if ($durationSeconds > VideoReferencePolicy::MAX_VIDEO_SECONDS + 0.05) {
                throw new InvalidArgumentException('El video base puede durar hasta 10 segundos.');
            }
        }

        return [
            'temporaryPath' => $path,
            'originalName' => $this->cleanName((string)($file['name'] ?? 'referencia')),
            'mimeType' => $mime,
            'mediaType' => $mediaType,
            'extension' => (string)$definition['extension'],
            'byteSize' => (int)$bytes,
            'width' => $width,
            'height' => $height,
            'durationSeconds' => $durationSeconds,
        ];
    }

    /** @param list<array<string,mixed>> $assets */
    private function assertRoleMedia(string $role, array $assets): void
    {
        foreach ($assets as $asset) {
            $mediaType = (string)($asset['mediaType'] ?? '');
            if ($role === 'source_video' && $mediaType !== 'video') {
                throw new InvalidArgumentException('Video base para editar admite únicamente un video.');
            }
            if ($role !== 'source_video' && $mediaType !== 'image') {
                throw new InvalidArgumentException('Las referencias visuales admiten únicamente imágenes.');
            }
        }
    }

    /** @param list<array<string,mixed>> $assets */
    private function assertCapacity(int $sceneId, string $role, array $assets): void
    {
        $incomingImages = count(array_filter($assets, static fn(array $asset): bool => ($asset['mediaType'] ?? '') === 'image'));
        if ($incomingImages === 0) return;

        $currentImages = 0;
        foreach ($this->repository->referencesForScene($sceneId) as $reference) {
            if (($reference['mediaType'] ?? 'image') !== 'image') continue;
            if (VideoReferencePolicy::isSingle($role) && (string)$reference['role'] === $role) continue;
            $currentImages++;
        }
        if ($currentImages + $incomingImages > VideoReferencePolicy::MAX_IMAGES) {
            throw new InvalidArgumentException('Omni admite un máximo de 10 imágenes por secuencia.');
        }
    }

    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));
        return mb_substr($name !== '' ? $name : 'Referencia', 0, 255);
    }

    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el límite permitido por el servidor.',
            UPLOAD_ERR_PARTIAL => 'La carga quedó incompleta. Inténtalo de nuevo.',
            UPLOAD_ERR_NO_FILE => 'No se recibió ningún archivo.',
            default => 'No se pudo recibir el archivo.',
        };
    }
}
