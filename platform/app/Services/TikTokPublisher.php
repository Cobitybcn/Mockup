<?php
declare(strict_types=1);

/**
 * TikTok Content Posting API — publishes a finished video by handing TikTok
 * a public URL to pull from (PULL_FROM_URL), rather than uploading bytes
 * through this server. Unaudited apps may only post as SELF_ONLY (private,
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

    /** @return array{privacyOptions:list<string>,maxDurationSeconds:int,nickname:string} */
    public function creatorInfo(int $userId, string $purpose = 'artist'): array
    {
        $context = $this->integration->publishingContext($userId, $purpose);
        $response = $this->request('POST', self::CREATOR_INFO_URL, [], $context['access_token']);
        $data = (array)($response['data'] ?? []);
        return [
            'privacyOptions' => array_values(array_map('strval', (array)($data['privacy_level_options'] ?? []))),
            'maxDurationSeconds' => (int)($data['max_video_post_duration_sec'] ?? 0),
            'nickname' => trim((string)($data['creator_nickname'] ?? '')),
        ];
    }

    /** @return array{publishId:string,privacyLevel:string} */
    public function publishVideo(int $userId, string $publicVideoUrl, string $caption, string $purpose = 'artist'): array
    {
        if (!$this->isHttpsUrl($publicVideoUrl)) {
            throw new InvalidArgumentException('TikTok requires a public HTTPS video URL.');
        }
        $context = $this->integration->publishingContext($userId, $purpose);
        $creator = $this->creatorInfo($userId, $purpose);
        $privacyLevel = in_array('SELF_ONLY', $creator['privacyOptions'], true)
            ? 'SELF_ONLY'
            : (string)($creator['privacyOptions'][0] ?? '');
        if ($privacyLevel === '') {
            throw new RuntimeException('TikTok no devolvió niveles de privacidad disponibles para esta cuenta.');
        }

        $payload = [
            'post_info' => [
                'title' => mb_substr(trim($caption), 0, 2200),
                'privacy_level' => $privacyLevel,
                'disable_duet' => false,
                'disable_comment' => false,
                'disable_stitch' => false,
            ],
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'video_url' => $publicVideoUrl,
            ],
        ];
        $response = $this->requestJsonBody(self::PUBLISH_INIT_URL, $payload, $context['access_token']);
        $publishId = trim((string)($response['data']['publish_id'] ?? ''));
        if ($publishId === '') {
            throw new RuntimeException('TikTok no devolvió un ID de publicación.');
        }
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

    private function request(string $method, string $url, array $parameters, string $accessToken): array
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
            $options[CURLOPT_POSTFIELDS] = json_encode($parameters, JSON_THROW_ON_ERROR);
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

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
