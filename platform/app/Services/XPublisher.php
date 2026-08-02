<?php
declare(strict_types=1);

/**
 * Posts to X, with the artwork's own images or its page video attached.
 *
 * Media costs more credits than text against the monthly allowance, but a post
 * about a painting that shows no painting is not worth saving credits over.
 * A still image uploads in one request; a video goes through X's chunked
 * upload (INIT/APPEND/FINALIZE) and is polled until X finishes processing it,
 * because a tweet that references the media before that point is rejected.
 */
final class XPublisher
{
    private const POST_URL = 'https://api.x.com/2/tweets';
    private const MEDIA_URL = 'https://api.x.com/2/media/upload';
    /**
     * X retired the single "POST /2/media/upload?command=INIT|APPEND|FINALIZE"
     * shape in 2025 — each step is now its own resource. STATUS is the one
     * survivor of the old query-param form; the other three moved under
     * /2/media/upload/{id}/... and INIT wants JSON, not multipart.
     */
    private const MEDIA_INIT_URL = 'https://api.x.com/2/media/upload/initialize';
    /** X shows at most four images in one post. */
    private const MAX_MEDIA = 4;
    /** Comfortably under X's per-chunk ceiling for the APPEND command. */
    private const APPEND_CHUNK_BYTES = 4 * 1024 * 1024;
    /** X's own ceiling for a video attached to a post. */
    private const MAX_VIDEO_BYTES = 512 * 1024 * 1024;
    /** A page montage is short; if X hasn't finished processing by then, something is wrong. */
    private const MAX_PROCESSING_WAIT_SECONDS = 180;

    public function __construct(private XIntegrationService $integration) {}

    /**
     * @param list<string> $imagePaths local files; whatever cannot be uploaded is
     *                                 left out rather than sinking the post
     * @return array{id:string,url:string,response:array<string,mixed>}
     */
    public function publishText(int $userId, string $text, string $purpose = 'artist', array $imagePaths = []): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('There is nothing to post on X.');
        }
        $context = $this->integration->publishingContext($userId, $purpose);

        $mediaIds = [];
        foreach (array_slice($imagePaths, 0, self::MAX_MEDIA) as $path) {
            try {
                $mediaIds[] = $this->uploadImage((string)$context['access_token'], (string)$path);
            } catch (Throwable $e) {
                // A post with fewer images still says what it came to say.
                error_log('X media upload skipped for '.basename((string)$path).': '.$e->getMessage());
            }
        }
        return $this->postTweet($context, $text, $mediaIds);
    }

    /**
     * The page's own video, sent as its single attachment — a tweet carries
     * either images or one video, never both.
     *
     * @return array{id:string,url:string,response:array<string,mixed>}
     */
    public function publishVideo(int $userId, string $text, string $purpose, string $videoPath): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('There is nothing to post on X.');
        }
        if ($videoPath === '' || !is_file($videoPath)) {
            throw new RuntimeException('The video is not available on disk.');
        }
        $bytes = (int)(filesize($videoPath) ?: 0);
        if ($bytes <= 0) {
            throw new RuntimeException('The video file is empty.');
        }
        if ($bytes > self::MAX_VIDEO_BYTES) {
            throw new RuntimeException('The video exceeds the 512 MB limit X accepts.');
        }

        $context = $this->integration->publishingContext($userId, $purpose);
        $mediaId = $this->uploadVideo((string)$context['access_token'], $videoPath, $bytes);
        return $this->postTweet($context, $text, [$mediaId]);
    }

    /** @param array<string,mixed> $context @param list<string> $mediaIds */
    private function postTweet(array $context, string $text, array $mediaIds): array
    {
        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => self::POST_URL,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$context['access_token'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(
                $mediaIds === [] ? ['text' => $text] : ['text' => $text, 'media' => ['media_ids' => $mediaIds]],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new RuntimeException('X could not be reached: '.$error);
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('X answered with something that is not JSON.');
        }
        if ($status >= 400 || isset($decoded['errors']) || isset($decoded['title'])) {
            $errors = array_values((array)($decoded['errors'] ?? []));
            $first = (array)($errors[0] ?? []);
            $reason = trim((string)(
                $first['detail'] ?? $first['message'] ?? $decoded['detail'] ?? $decoded['title'] ?? ''
            ));
            throw new RuntimeException('X refused the post: '.($reason !== '' ? $reason : (string)$body));
        }

        $data = (array)($decoded['data'] ?? []);
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('X did not return the published post.');
        }
        $username = trim((string)$context['username']);

        return [
            'id' => $id,
            'url' => $username !== '' ? 'https://x.com/'.rawurlencode($username).'/status/'.rawurlencode($id) : '',
            'response' => $decoded,
        ];
    }

    /** Uploads one image and returns its media id. */
    private function uploadImage(string $accessToken, string $path): string
    {
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('The image is not available on disk.');
        }
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new RuntimeException('X does not accept this image type: '.$mime);
        }

        $data = $this->mediaMultipart($accessToken, self::MEDIA_URL, [
            'media' => new CURLFile($path, $mime, basename($path)),
            'media_category' => 'tweet_image',
        ]);
        $mediaId = trim((string)($data['id'] ?? $data['media_id_string'] ?? ''));
        if ($mediaId === '') {
            throw new RuntimeException('X did not return a media id.');
        }
        return $mediaId;
    }

    /**
     * Chunked upload across three dedicated resources: INIT reserves the media
     * id, APPEND sends the file in pieces, FINALIZE closes it — then X
     * processes the video asynchronously, so the caller waits on STATUS
     * before the id is safe to post with.
     */
    private function uploadVideo(string $accessToken, string $path, int $bytes): string
    {
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['video/mp4', 'video/quicktime'], true)) {
            throw new RuntimeException('X does not accept this video type: '.$mime);
        }

        $init = $this->mediaJson($accessToken, self::MEDIA_INIT_URL, [
            'media_type' => $mime,
            'media_category' => 'tweet_video',
            'total_bytes' => $bytes,
        ]);
        $mediaId = trim((string)($init['id'] ?? ''));
        if ($mediaId === '') {
            throw new RuntimeException('X did not return a media id for the video.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The video could not be opened for reading.');
        }
        try {
            for ($segment = 0; !feof($handle); $segment++) {
                $chunk = fread($handle, self::APPEND_CHUNK_BYTES);
                if ($chunk === false || $chunk === '') break;
                $chunkPath = tempnam(sys_get_temp_dir(), 'xvid');
                if ($chunkPath === false) {
                    throw new RuntimeException('A temporary file could not be created for the upload.');
                }
                try {
                    file_put_contents($chunkPath, $chunk);
                    $this->mediaMultipart($accessToken, self::MEDIA_URL.'/'.$mediaId.'/append', [
                        'segment_index' => (string)$segment,
                        'media' => new CURLFile($chunkPath, 'application/octet-stream', 'chunk'),
                    ]);
                } finally {
                    @unlink($chunkPath);
                }
            }
        } finally {
            fclose($handle);
        }

        $finalized = $this->mediaJson($accessToken, self::MEDIA_URL.'/'.$mediaId.'/finalize', null);
        $this->awaitProcessing($accessToken, $mediaId, (array)($finalized['processing_info'] ?? []));
        return $mediaId;
    }

    /** Polls STATUS — the one step X left on the old query-param endpoint. */
    private function awaitProcessing(string $accessToken, string $mediaId, array $info): void
    {
        $deadline = time() + self::MAX_PROCESSING_WAIT_SECONDS;
        while ((string)($info['state'] ?? 'succeeded') !== 'succeeded') {
            if ((string)($info['state'] ?? '') === 'failed') {
                $reason = (array)($info['error'] ?? []);
                throw new RuntimeException('X could not process the video: '.trim((string)($reason['message'] ?? 'unknown error')));
            }
            if (time() >= $deadline) {
                throw new RuntimeException('X is still processing the video; try posting again in a minute.');
            }
            sleep(max(1, min(10, (int)($info['check_after_secs'] ?? 3))));
            $status = $this->mediaGet($accessToken, self::MEDIA_URL.'?'.http_build_query(['command' => 'STATUS', 'media_id' => $mediaId]));
            $info = (array)($status['processing_info'] ?? []);
        }
    }

    /** @param array<string,mixed>|null $fields null → an empty body, still POSTed (FINALIZE takes no fields) */
    private function mediaJson(string $accessToken, string $url, ?array $fields): array
    {
        return $this->mediaRequest($url, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$accessToken, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $fields === null ? '' : (json_encode($fields, JSON_UNESCAPED_UNICODE) ?: '{}'),
        ]);
    }

    /** @param array<string,mixed> $fields a CURLFile among them makes cURL encode this as multipart on its own */
    private function mediaMultipart(string $accessToken, string $url, array $fields): array
    {
        return $this->mediaRequest($url, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$accessToken],
            CURLOPT_POSTFIELDS => $fields,
        ]);
    }

    private function mediaGet(string $accessToken, string $url): array
    {
        return $this->mediaRequest($url, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$accessToken],
        ]);
    }

    /**
     * One HTTP call to a media-upload resource. APPEND commonly answers with
     * no body at all — that is success, not a parse failure.
     *
     * @param array<int,mixed> $options extra curl_setopt_array options for this call
     * @return array<string,mixed>
     */
    private function mediaRequest(string $url, array $options): array
    {
        $handle = curl_init();
        curl_setopt_array($handle, $options + [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new RuntimeException('X could not be reached: '.$error);
        }
        $decoded = trim((string)$body) === '' ? [] : json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('X answered with something that is not JSON.');
        }
        if ($status >= 400 || isset($decoded['errors']) || isset($decoded['title'])) {
            $errors = array_values((array)($decoded['errors'] ?? []));
            $first = (array)($errors[0] ?? []);
            $reason = trim((string)(
                $first['detail'] ?? $first['message'] ?? $decoded['detail'] ?? $decoded['title'] ?? ''
            ));
            throw new RuntimeException('X refused the video: '.($reason !== '' ? $reason : (string)$body));
        }
        return (array)($decoded['data'] ?? $decoded);
    }
}
