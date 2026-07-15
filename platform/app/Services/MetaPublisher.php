<?php
declare(strict_types=1);

final class MetaPublisher
{
    public function __construct(private readonly MetaIntegrationService $integration) {}

    /** @return array{id:string,url:string,response:array} */
    public function publishDraft(array $draft, int $userId, string $publicImageUrl): array
    {
        if (!$this->isHttpsUrl($publicImageUrl)) {
            throw new InvalidArgumentException('Meta requires a public HTTPS image URL.');
        }
        $channel = strtolower((string)($draft['channel'] ?? ''));
        if ($channel !== 'facebook') {
            throw new InvalidArgumentException('The Facebook publisher only accepts Facebook drafts.');
        }
        $purpose = (string)($draft['purpose'] ?? 'artist');
        $context = $this->integration->publishingContext($userId, $purpose, ['facebook']);
        $graph = new MetaGraphClient((string)$context['graph_version']);
        return $this->publishFacebook($graph, $context, $draft, $publicImageUrl);
    }

    /** @return array{id:string,url:string,response:array} */
    public function publishGroup(array $drafts, int $userId, array $publicImageUrls): array
    {
        $drafts = array_values($drafts);
        $publicImageUrls = array_values(array_map('strval', $publicImageUrls));
        if (!$drafts || count($drafts) !== count($publicImageUrls) || count($drafts) > 3) {
            throw new InvalidArgumentException('Facebook publications require one to three reviewed images.');
        }
        foreach ($publicImageUrls as $url) {
            if (!$this->isHttpsUrl($url)) throw new InvalidArgumentException('Facebook requires public HTTPS image URLs.');
        }
        foreach ($drafts as $draft) {
            if (strtolower((string)($draft['channel'] ?? '')) !== 'facebook') {
                throw new InvalidArgumentException('The Facebook publisher only accepts Facebook drafts.');
            }
        }
        if (count($drafts) === 1) return $this->publishDraft($drafts[0], $userId, $publicImageUrls[0]);

        $purpose = (string)($drafts[0]['purpose'] ?? 'artist');
        $context = $this->integration->publishingContext($userId, $purpose, ['facebook']);
        $graph = new MetaGraphClient((string)$context['graph_version']);
        $mediaIds = [];
        $uploads = [];
        foreach ($drafts as $index => $draft) {
            $payload = self::facebookUnpublishedPhotoPayload($draft, $publicImageUrls[$index]);
            $uploaded = $graph->request(
                'POST',
                '/' . rawurlencode((string)$context['page_id']) . '/photos',
                $payload,
                (string)$context['access_token'],
                (string)$context['app_secret']
            );
            $mediaId = trim((string)($uploaded['id'] ?? ''));
            if ($mediaId === '') throw new RuntimeException('Facebook did not return an uploaded photo ID.');
            $mediaIds[] = $mediaId;
            $uploads[] = $uploaded;
        }

        $response = $graph->request(
            'POST',
            '/' . rawurlencode((string)$context['page_id']) . '/feed',
            self::facebookMultiPhotoPayload($drafts[0], $mediaIds),
            (string)$context['access_token'],
            (string)$context['app_secret']
        );
        $externalId = trim((string)($response['id'] ?? ''));
        if ($externalId === '') throw new RuntimeException('Facebook did not return a publication ID.');
        $url = $this->facebookPermalink($graph, $context, $externalId);
        return [
            'id' => $externalId,
            'url' => $url,
            'response' => ['uploads' => $uploads, 'publication' => $response],
        ];
    }

    public static function facebookPayload(array $draft, string $publicImageUrl): array
    {
        $payload = [
            'url' => $publicImageUrl,
            'caption' => self::facebookCaption($draft),
            'published' => 'true',
        ];
        $altText = trim((string)($draft['alt_text'] ?? ''));
        if ($altText !== '') {
            $payload['alt_text_custom'] = mb_substr($altText, 0, 1000);
        }
        return $payload;
    }

    public static function facebookUnpublishedPhotoPayload(array $draft, string $publicImageUrl): array
    {
        $payload = ['url' => $publicImageUrl, 'published' => 'false'];
        $altText = trim((string)($draft['alt_text'] ?? ''));
        if ($altText !== '') $payload['alt_text_custom'] = mb_substr($altText, 0, 1000);
        return $payload;
    }

    public static function facebookMultiPhotoPayload(array $draft, array $mediaIds): array
    {
        $mediaIds = array_values(array_filter(array_map(static fn ($id): string => trim((string)$id), $mediaIds)));
        if (count($mediaIds) < 2 || count($mediaIds) > 3) {
            throw new InvalidArgumentException('Facebook multi-photo publications require two or three uploaded photos.');
        }
        return [
            'message' => self::facebookCaption($draft),
            'attached_media' => array_map(
                static fn (string $id): string => json_encode(['media_fbid' => $id], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $mediaIds
            ),
        ];
    }

    public static function instagramContainerPayload(array $draft, string $publicImageUrl): array
    {
        $payload = [
            'image_url' => $publicImageUrl,
            'caption' => self::instagramCaption($draft),
        ];
        $altText = trim((string)($draft['alt_text'] ?? ''));
        if ($altText !== '') {
            $payload['alt_text'] = mb_substr($altText, 0, 1000);
        }
        return $payload;
    }

    public static function facebookCaption(array $draft): string
    {
        $parts = [];
        $title = trim((string)($draft['title'] ?? ''));
        $description = trim((string)($draft['description'] ?? ''));
        $destination = trim((string)($draft['destination_url'] ?? ''));
        $hashtags = json_decode((string)($draft['hashtags'] ?? '[]'), true);
        $hashtags = is_array($hashtags) ? array_values(array_filter(array_map('strval', $hashtags))) : [];
        if ($title !== '') $parts[] = $title;
        if ($description !== '') $parts[] = $description;
        if ($destination !== '') $parts[] = $destination;
        if ($hashtags) $parts[] = implode(' ', $hashtags);
        return mb_substr(implode("\n\n", $parts), 0, 5000);
    }

    public static function instagramCaption(array $draft): string
    {
        $description = trim((string)($draft['description'] ?? ''));
        $hashtags = json_decode((string)($draft['hashtags'] ?? '[]'), true);
        $hashtags = is_array($hashtags) ? array_values(array_filter(array_map('strval', $hashtags))) : [];
        return mb_substr(trim($description . ($hashtags ? "\n\n" . implode(' ', $hashtags) : '')), 0, 2200);
    }

    /** @return array{id:string,url:string,response:array} */
    private function publishFacebook(MetaGraphClient $graph, array $context, array $draft, string $imageUrl): array
    {
        $response = $graph->request(
            'POST',
            '/' . rawurlencode((string)$context['page_id']) . '/photos',
            self::facebookPayload($draft, $imageUrl),
            (string)$context['access_token'],
            (string)$context['app_secret']
        );
        $externalId = trim((string)($response['post_id'] ?? $response['id'] ?? ''));
        if ($externalId === '') {
            throw new RuntimeException('Facebook did not return a publication ID.');
        }
        $url = $this->facebookPermalink($graph, $context, $externalId);
        return ['id' => $externalId, 'url' => $url, 'response' => $response];
    }

    private function facebookPermalink(MetaGraphClient $graph, array $context, string $externalId): string
    {
        try {
            $details = $graph->request(
                'GET',
                '/' . rawurlencode($externalId),
                ['fields' => 'permalink_url'],
                (string)$context['access_token'],
                (string)$context['app_secret']
            );
            return trim((string)($details['permalink_url'] ?? ''));
        } catch (Throwable) {
            return '';
        }
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
