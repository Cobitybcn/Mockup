<?php
declare(strict_types=1);

final class ReferenceAssetService
{
    private const MAX_FILE_BYTES = 20_971_520;
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function upload(int $userId, array $file, string $title, string $category): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->uploadErrorMessage($error));
        }

        $temporaryPath = (string)($file['tmp_name'] ?? '');
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath) || !is_file($temporaryPath)) {
            throw new InvalidArgumentException('The uploaded reference is not valid.');
        }

        $originalName = trim(basename((string)($file['name'] ?? 'reference')));
        return $this->storeImage($userId, $temporaryPath, $originalName, $title, $category);
    }

    public function importFromUrl(int $userId, string $url, string $title, string $category): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Remote image import is not available on this server.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'visual_dna_remote_');
        if (!is_string($temporaryPath)) {
            throw new RuntimeException('A temporary file could not be created for the remote image.');
        }

        $currentUrl = trim($url);
        $originalName = 'remote-reference';
        try {
            for ($redirect = 0; $redirect <= 3; $redirect++) {
                $target = $this->assertPublicRemoteUrl($currentUrl);
                $pathName = basename((string)(parse_url($currentUrl, PHP_URL_PATH) ?: ''));
                if ($pathName !== '' && $pathName !== '/' && $pathName !== '.') {
                    $originalName = $pathName;
                }

                $responseHeaders = [];
                $status = 0;
                $curlError = '';
                $connected = false;
                foreach ($this->orderConnectionIps($target['ips']) as $ip) {
                    $handle = fopen($temporaryPath, 'wb');
                    if (!is_resource($handle)) {
                        throw new RuntimeException('The remote image could not be prepared.');
                    }
                    $receivedBytes = 0;
                    $tooLarge = false;
                    $responseHeaders = [];
                    $curl = curl_init($currentUrl);
                    if ($curl === false) {
                        fclose($handle);
                        throw new RuntimeException('The remote image request could not be initialized.');
                    }

                    $resolvedIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
                    curl_setopt_array($curl, [
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_CONNECTTIMEOUT => 8,
                        CURLOPT_TIMEOUT => 35,
                        CURLOPT_USERAGENT => 'ArtworkMockups-VisualDNA/1.0',
                        CURLOPT_ENCODING => '',
                        CURLOPT_HTTPHEADER => ['Accept: image/jpeg,image/png,image/webp,image/*;q=0.8'],
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $resolvedIp],
                        CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $header) use (&$responseHeaders): int {
                            $length = strlen($header);
                            $separator = strpos($header, ':');
                            if ($separator !== false) {
                                $name = strtolower(trim(substr($header, 0, $separator)));
                                $responseHeaders[$name] = trim(substr($header, $separator + 1));
                            }
                            return $length;
                        },
                        CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $chunk) use ($handle, &$receivedBytes, &$tooLarge): int {
                            $length = strlen($chunk);
                            $receivedBytes += $length;
                            if ($receivedBytes > self::MAX_FILE_BYTES) {
                                $tooLarge = true;
                                return 0;
                            }
                            return fwrite($handle, $chunk) === $length ? $length : 0;
                        },
                    ]);

                    $executed = curl_exec($curl);
                    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                    $curlError = curl_error($curl);
                    curl_close($curl);
                    fclose($handle);

                    if ($tooLarge) {
                        throw new InvalidArgumentException('Reference images must be smaller than 20 MB.');
                    }
                    if ($executed !== false) {
                        $connected = true;
                        break;
                    }
                }

                if (!$connected) {
                    throw new InvalidArgumentException('The image could not be read from the other website.' . ($curlError !== '' ? ' ' . $curlError : ''));
                }
                if ($status >= 300 && $status < 400) {
                    $location = trim((string)($responseHeaders['location'] ?? ''));
                    if ($location === '' || $redirect === 3) {
                        throw new InvalidArgumentException('The external image redirected too many times.');
                    }
                    $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
                    continue;
                }
                if ($status < 200 || $status >= 300) {
                    throw new InvalidArgumentException('The other website did not provide an accessible image.');
                }

                return $this->storeImage($userId, $temporaryPath, $originalName, $title, $category);
            }
            throw new InvalidArgumentException('The external image could not be imported.');
        } finally {
            @unlink($temporaryPath);
        }
    }

    private function storeImage(int $userId, string $temporaryPath, string $originalName, string $title, string $category): array
    {
        $fileSize = (int)(filesize($temporaryPath) ?: 0);
        if ($fileSize <= 0 || $fileSize > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('Reference images must be smaller than 20 MB.');
        }

        $imageInfo = @getimagesize($temporaryPath);
        $mimeType = is_array($imageInfo) ? (string)($imageInfo['mime'] ?? '') : '';
        if (!isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            throw new InvalidArgumentException('Use a JPG, PNG, or WebP reference image.');
        }

        $originalName = trim(basename($originalName));
        $originalName = mb_substr($originalName !== '' ? $originalName : 'reference', 0, 255);
        $title = trim($title);
        if ($title === '') {
            $title = trim((string)pathinfo($originalName, PATHINFO_FILENAME));
        }
        if ($title === '') {
            $title = 'Visual reference';
        }
        if (mb_strlen($title) > 255) {
            throw new InvalidArgumentException('The reference title is too long.');
        }

        $category = $this->normalizeCategory($category);
        $extension = self::ALLOWED_MIME_TYPES[$mimeType];
        $storagePath = 'storage/visual_dna/' . $userId . '/'
            . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

        if (!StorageService::uploadFile($storagePath, $temporaryPath)) {
            throw new RuntimeException('The reference image could not be stored.');
        }

        $now = date('c');
        try {
            $stmt = $this->pdo->prepare('INSERT INTO reference_assets
                (user_id, title, category, storage_path, original_name, mime_type, file_size, created_at, updated_at)
                VALUES (:user_id, :title, :category, :storage_path, :original_name, :mime_type, :file_size, :created_at, :updated_at)');
            $stmt->execute([
                'user_id' => $userId,
                'title' => $title,
                'category' => $category,
                'storage_path' => $storagePath,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $error) {
            throw new RuntimeException('The reference metadata could not be saved.', 0, $error);
        }

        return $this->findForUser($userId, (int)$this->pdo->lastInsertId())
            ?? throw new RuntimeException('The saved reference could not be loaded.');
    }

    private function assertPublicRemoteUrl(string $url): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('The dropped image URL is not valid.');
        }
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Only public HTTP or HTTPS image URLs can be imported.');
        }
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            throw new InvalidArgumentException('External image URLs must use their standard web port.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('Local or private image URLs cannot be imported.');
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            foreach (@gethostbynamel($host) ?: [] as $ip) {
                $ips[] = $ip;
            }
            if (function_exists('dns_get_record')) {
                foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                    $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                    if ($ip !== '') {
                        $ips[] = $ip;
                    }
                }
            }
        }
        $ips = array_values(array_unique($ips));
        if (!$ips) {
            throw new InvalidArgumentException('The external image host could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidArgumentException('Local or private image URLs cannot be imported.');
            }
        }

        return ['host' => $host, 'port' => $port, 'ips' => $ips];
    }

    private function orderConnectionIps(array $ips): array
    {
        usort($ips, static function (string $left, string $right): int {
            return (int)str_contains($left, ':') <=> (int)str_contains($right, ':');
        });
        return $ips;
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $location;
        }
        $base = parse_url($baseUrl);
        $scheme = (string)($base['scheme'] ?? 'https');
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        $host = (string)($base['host'] ?? '');
        $port = isset($base['port']) ? ':' . (int)$base['port'] : '';
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }
        $path = (string)($base['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
        return $scheme . '://' . $host . $port . ($directory !== '' ? $directory : '') . '/' . ltrim($location, '/');
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM reference_assets WHERE user_id = :user_id ORDER BY created_at DESC, id DESC');
        $stmt->execute(['user_id' => $userId]);
        return array_map(fn(array $row): array => $this->normalize($row), $stmt->fetchAll());
    }

    public function findForUser(int $userId, int $assetId): ?array
    {
        if ($assetId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM reference_assets WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute(['id' => $assetId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->normalize($row) : null;
    }

    public function catalogMapForUser(int $userId): array
    {
        $catalog = [];
        foreach ($this->listForUser($userId) as $asset) {
            $key = 'asset:' . (int)$asset['id'];
            $catalog[$key] = [
                'id' => $key,
                'title' => (string)$asset['title'],
                'category' => (string)$asset['category'],
                'image' => (string)$asset['image'],
                'reference_asset_id' => (int)$asset['id'],
                'is_demo' => false,
            ];
        }
        return $catalog;
    }

    public function referencesForSet(int $userId, int $referenceSetId, int $limit = 6): array
    {
        $limit = max(1, min(8, $limit));
        $stmt = $this->pdo->prepare('SELECT ra.*, rsi.position
            FROM reference_set_items rsi
            INNER JOIN reference_sets rs ON rs.id = rsi.reference_set_id
            INNER JOIN reference_assets ra ON ra.id = rsi.reference_asset_id AND ra.user_id = rs.user_id
            WHERE rsi.reference_set_id = :reference_set_id AND rs.user_id = :user_id
            ORDER BY rsi.position ASC, rsi.id ASC');
        $stmt->execute(['reference_set_id' => $referenceSetId, 'user_id' => $userId]);

        $references = [];
        foreach ($stmt->fetchAll() as $row) {
            $asset = $this->normalize($row);
            $localPath = $this->ensureLocalPath($asset);
            if ($localPath === '') {
                continue;
            }
            $asset['local_path'] = $localPath;
            $references[] = $asset;
            if (count($references) >= $limit) {
                break;
            }
        }
        return $references;
    }

    public function ensureLocalPath(array $asset): string
    {
        $storagePath = ltrim(str_replace('\\', '/', (string)($asset['storage_path'] ?? '')), '/');
        if (!preg_match('#^storage/visual_dna/[0-9]+/[A-Za-z0-9_.-]+$#', $storagePath)) {
            return '';
        }
        $localPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $storagePath);
        if (is_file($localPath)) {
            return $localPath;
        }
        if (StorageService::isGcsActive() && StorageService::downloadFile($storagePath, $localPath) && is_file($localPath)) {
            return $localPath;
        }
        return '';
    }

    private function normalize(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['file_size'] = (int)$row['file_size'];
        $row['reference_key'] = 'asset:' . $row['id'];
        $row['image'] = 'visual_dna_media.php?id=' . $row['id'] . '&thumb=1&w=640';
        return $row;
    }

    private function normalizeCategory(string $category): string
    {
        $category = trim($category);
        $allowed = array_merge(StudioReferenceCatalog::categories(), ['Other']);
        return in_array($category, $allowed, true) ? $category : 'Other';
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The reference image is too large.',
            UPLOAD_ERR_NO_FILE => 'Choose an image to upload.',
            default => 'The reference image could not be uploaded.',
        };
    }
}
