<?php
declare(strict_types=1);

/**
 * Posts to X, with the artwork's own images attached.
 *
 * Media costs more credits than text against the monthly allowance, but a post
 * about a painting that shows no painting is not worth saving credits over.
 * Each image is uploaded first and referenced by id in the post itself.
 */
final class XPublisher
{
    private const POST_URL = 'https://api.x.com/2/tweets';
    private const MEDIA_URL = 'https://api.x.com/2/media/upload';
    /** X shows at most four images in one post. */
    private const MAX_MEDIA = 4;

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

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => self::MEDIA_URL,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$accessToken],
            CURLOPT_POSTFIELDS => ['media' => new CURLFile($path, $mime, basename($path))],
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new RuntimeException('X could not be reached: '.$error);
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded) || $status >= 400) {
            throw new RuntimeException('X refused the image: '.mb_substr((string)$body, 0, 300));
        }
        $data = (array)($decoded['data'] ?? $decoded);
        $mediaId = trim((string)($data['id'] ?? $data['media_id_string'] ?? ''));
        if ($mediaId === '') {
            throw new RuntimeException('X did not return a media id.');
        }
        return $mediaId;
    }
}
