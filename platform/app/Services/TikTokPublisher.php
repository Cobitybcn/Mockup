<?php
declare(strict_types=1);

/**
 * TikTok Content Posting API — publishes a finished video by uploading its
 * bytes to the one-time URL returned by TikTok. Unaudited apps may only post
 * as SELF_ONLY (private,
 * visible only to the connected creator inside TikTok) until TikTok approves
 * the app for public posting — this service always asks TikTok which privacy
 * levels the connection is currently allowed to use instead of assuming.
 */
final class TikTokPublisher
{
    private const CREATOR_INFO_URL = 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/';
    private const PUBLISH_INIT_URL = 'https://open.tiktokapis.com/v2/post/publish/video/init/';
    private const PUBLISH_STATUS_URL = 'https://open.tiktokapis.com/v2/post/publish/status/fetch/';

    public function __construct(private readonly TikTokIntegrationService $integration) {}

    /** @return array{privacyOptions:list<string>,maxDurationSeconds:int,nickname:string,commentDisabled:bool,duetDisabled:bool,stitchDisabled:bool} */
    public function creatorInfo(int $userId, string $purpose = 'artist'): array
    {
        $context = $this->integration->publishingContext($userId, $purpose);
        $response = $this->request('POST', self::CREATOR_INFO_URL, null, $context['access_token']);
        $data = (array)($response['data'] ?? []);
        return [
            'privacyOptions' => array_values(array_map('strval', (array)($data['privacy_level_options'] ?? []))),
            'maxDurationSeconds' => (int)($data['max_video_post_duration_sec'] ?? 0),
            'nickname' => trim((string)($data['creator_nickname'] ?? '')),
            'commentDisabled' => (bool)($data['comment_disabled'] ?? false),
            'duetDisabled' => (bool)($data['duet_disabled'] ?? false),
            'stitchDisabled' => (bool)($data['stitch_disabled'] ?? false),
        ];
    }

    /** @return array{publishId:string,privacyLevel:string} */
    public function publishVideoFile(int $userId, string $videoPath, string $caption, string $purpose = 'artist'): array
    {
        $videoSize = is_file($videoPath) ? filesize($videoPath) : false;
        if ($videoSize === false || $videoSize <= 0) {
            throw new InvalidArgumentException('El archivo de video para TikTok no está disponible.');
        }
        if ($videoSize > 4 * 1024 * 1024 * 1024) {
            throw new InvalidArgumentException('TikTok admite videos de hasta 4 GB.');
        }
        $context = $this->integration->publishingContext($userId, $purpose);
        $creator = $this->creatorInfo($userId, $purpose);
        $privacyLevel = in_array('SELF_ONLY', $creator['privacyOptions'], true)
            ? 'SELF_ONLY'
            : (string)($creator['privacyOptions'][0] ?? '');
        if ($privacyLevel === '') {
            throw new RuntimeException('TikTok no devolvió niveles de privacidad disponibles para esta cuenta.');
        }
        $chunkSize = min($videoSize, 10 * 1024 * 1024);
        $totalChunkCount = max(1, intdiv($videoSize, $chunkSize));

        $payload = [
            'post_info' => [
                'title' => mb_substr(trim($caption), 0, 2200),
                'privacy_level' => $privacyLevel,
                'disable_duet' => $creator['duetDisabled'],
                'disable_comment' => $creator['commentDisabled'],
                'disable_stitch' => $creator['stitchDisabled'],
            ],
            'source_info' => [
                'source' => 'FILE_UPLOAD',
                'video_size' => $videoSize,
                'chunk_size' => $chunkSize,
                'total_chunk_count' => $totalChunkCount,
            ],
        ];
        $response = $this->requestJsonBody(self::PUBLISH_INIT_URL, $payload, $context['access_token']);
        $publishId = trim((string)($response['data']['publish_id'] ?? ''));
        $uploadUrl = trim((string)($response['data']['upload_url'] ?? ''));
        if ($publishId === '') {
            throw new RuntimeException('TikTok no devolvió un ID de publicación.');
        }
        if ($uploadUrl === '') {
            throw new RuntimeException('TikTok no devolvió una URL para cargar el video.');
        }
        $this->uploadFile($uploadUrl, $videoPath, $videoSize, $chunkSize);
        return ['publishId' => $publishId, 'privacyLevel' => $privacyLevel];
    }

    /** @return array{status:string,failReason:string,publiclyAvailableUrl:string} */
    public function fetchStatus(int $userId, string $publishId, string $purpose = 'artist'): array
    {
        $context = $this->integration->publishingContext($userId, $purpose);
        $response = $this->requestJsonBody(self::PUBLISH_STATUS_URL, ['publish_id' => $publishId], $context['access_token']);
        $data = (array)($response['data'] ?? []);
        return [
            'status' => (string)($data['status'] ?? 'UNKNOWN'),
            'failReason' => (string)($data['fail_reason'] ?? ''),
            'publiclyAvailableUrl' => (string)($data['publicaly_available_post_id'][0] ?? ''),
        ];
    }

    private function requestJsonBody(string $url, array $payload, string $accessToken): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json; charset=UTF-8',
                'Authorization: Bearer '.$accessToken,
            ],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        return $this->decode($body, $status, $error);
    }

    private function request(string $method, string $url, ?array $parameters, string $accessToken): array
    {
        $curl = curl_init();
        $headers = ['Accept: application/json', 'Authorization: Bearer '.$accessToken];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ];
        if (strtoupper($method) === 'POST') {
            $options[CURLOPT_URL] = $url;
            $options[CURLOPT_POST] = true;
            if ($parameters !== null) {
                $options[CURLOPT_POSTFIELDS] = json_encode($parameters, JSON_THROW_ON_ERROR);
            }
            $headers[] = 'Content-Type: application/json; charset=UTF-8';
        } else {
            $options[CURLOPT_URL] = $url.($parameters ? '?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986) : '');
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        return $this->decode($body, $status, $error);
    }

    private function decode(string|false $body, int $status, string $curlError): array
    {
        $data = is_string($body) ? json_decode($body, true) : null;
        $apiError = is_array($data) ? (string)($data['error']['code'] ?? '') : '';
        if ($curlError !== '' || $status < 200 || $status >= 300 || !is_array($data) || ($apiError !== '' && $apiError !== 'ok')) {
            $message = is_array($data) ? trim((string)($data['error']['message'] ?? '')) : '';
            throw new RuntimeException('TikTok API respondió con un error (HTTP '.$status.').'.($message !== '' ? ' '.mb_substr($message, 0, 240) : ''));
        }
        return $data;
    }

    private function uploadFile(string $uploadUrl, string $videoPath, int $videoSize, int $chunkSize): void
    {
        $handle = fopen($videoPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el video para enviarlo a TikTok.');
        }
        $mime = @mime_content_type($videoPath) ?: 'video/mp4';
        if (!in_array($mime, ['video/mp4', 'video/quicktime', 'video/webm'], true)) {
            $mime = 'video/mp4';
        }
        try {
            foreach (self::uploadChunks($videoSize, $chunkSize) as $chunk) {
                $start = $chunk['start'];
                $length = $chunk['length'];
                if (fseek($handle, $start) !== 0) {
                    throw new RuntimeException('No se pudo preparar un fragmento del video para TikTok.');
                }
                $bytes = fread($handle, $length);
                if ($bytes === false || strlen($bytes) !== $length) {
                    throw new RuntimeException('No se pudo leer el video completo para TikTok.');
                }
                $end = $start + $length - 1;
                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => $uploadUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 180,
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_POSTFIELDS => $bytes,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: ' . $mime,
                        'Content-Length: ' . $length,
                        'Content-Range: bytes ' . $start . '-' . $end . '/' . $videoSize,
                        'Expect:',
                    ],
                ]);
                $body = curl_exec($curl);
                $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $error = curl_error($curl);
                curl_close($curl);
                $expectedStatus = $end === $videoSize - 1 ? 201 : 206;
                if ($error !== '' || $status !== $expectedStatus) {
                    $detail = is_string($body) ? trim($body) : '';
                    throw new RuntimeException(
                        'TikTok rechazó la carga del video (HTTP ' . $status . ').'
                        . ($detail !== '' ? ' ' . mb_substr($detail, 0, 240) : '')
                    );
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return list<array{start:int,length:int}> */
    private static function uploadChunks(int $videoSize, int $chunkSize): array
    {
        $totalChunkCount = max(1, intdiv($videoSize, $chunkSize));
        $chunks = [];
        for ($index = 0; $index < $totalChunkCount; $index++) {
            $start = $index * $chunkSize;
            $length = $index === $totalChunkCount - 1
                ? $videoSize - $start
                : $chunkSize;
            $chunks[] = ['start' => $start, 'length' => $length];
        }
        return $chunks;
    }
}
