<?php
declare(strict_types=1);

final class ExternalMockupUploadService
{
    public const MAX_FILE_BYTES = 20 * 1024 * 1024;
    public const MAX_IMAGE_PIXELS = 120000000;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $file
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function upload(int $userId, int $artworkId, array $file, array $metadata = []): array
    {
        if ($userId <= 0 || $artworkId <= 0) {
            throw new DomainException('Selecciona una obra válida antes de subir los mockups.');
        }

        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new DomainException($this->uploadErrorMessage($uploadError));
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new DomainException('El archivo recibido no es una subida válida.');
        }

        $image = self::inspectImage($tmpPath);
        $artwork = $this->artworkForUser($userId, $artworkId);
        $artworkFile = basename((string)($artwork['root_file'] ?: $artwork['main_file'] ?: ''));
        if ($artworkFile === '') {
            throw new DomainException('La obra seleccionada todavía no tiene una imagen raíz disponible.');
        }

        if (!is_dir(RESULTS_DIR) && !mkdir(RESULTS_DIR, 0775, true) && !is_dir(RESULTS_DIR)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento de mockups.');
        }

        $storedName = $this->uniqueStoredName($artworkId, (string)$image['extension']);
        $storedPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($tmpPath, $storedPath)) {
            throw new RuntimeException('No se pudo guardar el mockup recibido.');
        }

        try {
            if (StorageService::isGcsActive()
                && !StorageService::uploadFile('results/' . $storedName, $storedPath)) {
                throw new RuntimeException('No se pudo guardar el mockup en el almacenamiento remoto.');
            }

            $now = date('c');
            $originalName = self::cleanOriginalName((string)($file['name'] ?? 'mockup'));
            $batchId = self::cleanBatchId((string)($metadata['batch_id'] ?? ''));
            $relativePath = self::normalizeRelativePath((string)($metadata['relative_path'] ?? $originalName));
            $sortOrder = max(0, min(9999, (int)($metadata['sort_order'] ?? 0)));
            $selectorState = [
                'generation_source' => 'external_upload',
                'import' => [
                    'batch_id' => $batchId,
                    'sort_order' => $sortOrder,
                    'original_filename' => $originalName,
                    'original_relative_path' => $relativePath,
                    'mime_type' => (string)$image['mime'],
                    'file_size' => (int)$image['size'],
                    'width' => (int)$image['width'],
                    'height' => (int)$image['height'],
                    'uploaded_at' => $now,
                ],
            ];
            $selectorStateJson = json_encode(
                $selectorState,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );

            $mockupId = (int)Database::withBusyRetry(function () use (
                $userId,
                $artworkId,
                $artwork,
                $artworkFile,
                $storedName,
                $selectorStateJson,
                $now
            ): int {
                $stmt = $this->pdo->prepare('
                    INSERT INTO mockups (
                        user_id, artwork_group_id, source_artwork_id, series_id,
                        artwork_file, mockup_file, context_id, prompt_file,
                        selector_state_json, created_at
                    ) VALUES (
                        :user_id, :artwork_group_id, :source_artwork_id, :series_id,
                        :artwork_file, :mockup_file, :context_id, NULL,
                        :selector_state_json, :created_at
                    )
                ');
                $stmt->execute([
                    'user_id' => $userId,
                    'artwork_group_id' => ((int)($artwork['artwork_group_id'] ?? 0) > 0)
                        ? (int)$artwork['artwork_group_id']
                        : null,
                    'source_artwork_id' => $artworkId,
                    'series_id' => ((int)($artwork['series_id'] ?? 0) > 0)
                        ? (int)$artwork['series_id']
                        : null,
                    'artwork_file' => $artworkFile,
                    'mockup_file' => $storedName,
                    'context_id' => 'external_upload',
                    'selector_state_json' => $selectorStateJson,
                    'created_at' => $now,
                ]);

                return (int)$this->pdo->lastInsertId();
            }, 12);
        } catch (Throwable $e) {
            $this->removeStoredFile($storedName);
            throw $e;
        }

        return [
            'id' => $mockupId,
            'file' => $storedName,
            'original_name' => $originalName,
            'width' => (int)$image['width'],
            'height' => (int)$image['height'],
            'size' => (int)$image['size'],
            'media_url' => 'media.php?file=' . rawurlencode($storedName) . '&thumb=1&w=720',
            'viewer_url' => 'viewer.php?id=' . $mockupId,
        ];
    }

    /** @return array{mime:string,extension:string,width:int,height:int,size:int} */
    public static function inspectImage(string $path): array
    {
        if (!is_file($path)) {
            throw new DomainException('No se pudo leer el archivo seleccionado.');
        }

        $size = (int)(filesize($path) ?: 0);
        if ($size <= 0) {
            throw new DomainException('El archivo está vacío.');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new DomainException('Cada mockup puede pesar hasta 20 MB.');
        }

        $imageInfo = @getimagesize($path);
        if (!is_array($imageInfo)) {
            throw new DomainException('El archivo no contiene una imagen válida.');
        }

        $mime = '';
        if (class_exists(finfo::class)) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = strtolower(trim((string)$finfo->file($path)));
        }
        if ($mime === '') {
            $mime = strtolower(trim((string)($imageInfo['mime'] ?? '')));
        }

        $mimeMap = self::allowedMimeTypes();
        if (!isset($mimeMap[$mime])) {
            throw new DomainException('Formato no compatible. Usa archivos JPG, PNG o WebP.');
        }

        $width = (int)($imageInfo[0] ?? 0);
        $height = (int)($imageInfo[1] ?? 0);
        if ($width <= 0 || $height <= 0
            || $width > 30000 || $height > 30000
            || ($width * $height) > self::MAX_IMAGE_PIXELS) {
            throw new DomainException('La imagen tiene dimensiones demasiado grandes o no válidas.');
        }

        return [
            'mime' => $mime,
            'extension' => $mimeMap[$mime],
            'width' => $width,
            'height' => $height,
            'size' => $size,
        ];
    }

    /** @return array<string,string> */
    public static function allowedMimeTypes(): array
    {
        return [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
    }

    public static function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            $segments[] = $segment;
        }

        $normalized = implode('/', $segments);
        return mb_substr($normalized !== '' ? $normalized : 'mockup', 0, 500);
    }

    /** @return array<string,mixed> */
    private function artworkForUser(int $userId, int $artworkId): array
    {
        ArtworkSeries::ensureSchema($this->pdo);
        $stmt = $this->pdo->prepare('
            SELECT id, artwork_group_id, series_id, root_file, main_file, status
            FROM artworks
            WHERE id = :id AND user_id = :user_id AND status = :status
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $artworkId,
            'user_id' => $userId,
            'status' => 'done',
        ]);
        $artwork = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($artwork)) {
            throw new DomainException('La obra seleccionada no existe o no pertenece a tu cuenta.');
        }

        return $artwork;
    }

    private function uniqueStoredName(int $artworkId, string $extension): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $name = sprintf(
                'uploaded-mockup-%d-%s-%s.%s',
                $artworkId,
                gmdate('YmdHis'),
                bin2hex(random_bytes(6)),
                $extension
            );
            if (!is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $name)) {
                return $name;
            }
        }

        throw new RuntimeException('No se pudo generar un nombre único para el mockup.');
    }

    private static function cleanOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        return mb_substr($name !== '' ? $name : 'mockup', 0, 255);
    }

    private static function cleanBatchId(string $batchId): string
    {
        $batchId = trim($batchId);
        if ($batchId !== '' && preg_match('/^[a-z0-9-]{1,80}$/i', $batchId)) {
            return $batchId;
        }

        return bin2hex(random_bytes(12));
    }

    private function removeStoredFile(string $storedName): void
    {
        if (StorageService::isGcsActive()) {
            StorageService::delete('results/' . $storedName);
        }
        $path = RESULTS_DIR . DIRECTORY_SEPARATOR . basename($storedName);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El mockup supera el límite permitido de 20 MB.',
            UPLOAD_ERR_PARTIAL => 'La carga del mockup quedó incompleta. Inténtalo otra vez.',
            UPLOAD_ERR_NO_FILE => 'No se recibió ningún archivo.',
            default => 'No se pudo recibir el mockup seleccionado.',
        };
    }
}
