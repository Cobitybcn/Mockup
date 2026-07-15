<?php
declare(strict_types=1);

final class InstagramPublisher
{
    public function __construct(private readonly InstagramIntegrationService $integration) {}

    /** @return array{id:string,url:string,response:array} */
    public function publishDraft(array $draft, int $userId, string $publicImageUrl): array
    {
        if (!$this->isHttpsUrl($publicImageUrl)) {
            throw new InvalidArgumentException('Instagram requires a public HTTPS image URL.');
        }
        if (strtolower((string)($draft['channel'] ?? '')) !== 'instagram') {
            throw new InvalidArgumentException('The direct Instagram publisher only accepts Instagram drafts.');
        }

        $purpose = (string)($draft['purpose'] ?? 'artist');
        $context = $this->integration->publishingContext($userId, $purpose);
        $graph = new InstagramGraphClient((string)$context['graph_version']);
        $instagramId = trim((string)$context['instagram_user_id']);
        $token = (string)$context['access_token'];

        $container = $graph->request(
            'POST',
            '/'.rawurlencode($instagramId).'/media',
            MetaPublisher::instagramContainerPayload($draft, $publicImageUrl),
            $token
        );
        $containerId = trim((string)($container['id'] ?? ''));
        if ($containerId === '') {
            throw new RuntimeException('Instagram did not return a media container ID.');
        }

        $finished = false;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $status = $graph->request(
                'GET',
                '/'.rawurlencode($containerId),
                ['fields' => 'status_code,status'],
                $token
            );
            $code = strtoupper(trim((string)($status['status_code'] ?? '')));
            if ($code === 'FINISHED') {
                $finished = true;
                break;
            }
            if (in_array($code, ['ERROR', 'EXPIRED'], true)) {
                throw new RuntimeException('Instagram could not prepare the image container. '.mb_substr((string)($status['status'] ?? ''), 0, 240));
            }
            usleep(750000);
        }
        if (!$finished) {
            throw new RuntimeException('Instagram did not finish preparing the image in time. Try this item again later.');
        }

        $published = $graph->request(
            'POST',
            '/'.rawurlencode($instagramId).'/media_publish',
            ['creation_id' => $containerId],
            $token
        );
        $mediaId = trim((string)($published['id'] ?? ''));
        if ($mediaId === '') {
            throw new RuntimeException('Instagram did not return a published media ID.');
        }

        $url = '';
        try {
            $details = $graph->request('GET', '/'.rawurlencode($mediaId), ['fields' => 'permalink'], $token);
            $url = trim((string)($details['permalink'] ?? ''));
        } catch (Throwable) {
            // Publication already succeeded; do not turn a permalink lookup into a duplicate retry.
        }
        return [
            'id' => $mediaId,
            'url' => $url,
            'response' => ['container_id' => $containerId, 'published' => $published],
        ];
    }

    /** @return array{id:string,url:string,response:array} */
    public function publishGroup(array $drafts, int $userId, array $publicImageUrls): array
    {
        $drafts = array_values($drafts);
        $publicImageUrls = array_values(array_map('strval', $publicImageUrls));
        if (!$drafts || count($drafts) !== count($publicImageUrls) || count($drafts) > 10) {
            throw new InvalidArgumentException('Instagram publications require one to ten reviewed images.');
        }
        foreach ($drafts as $draft) {
            if (strtolower((string)($draft['channel'] ?? '')) !== 'instagram') {
                throw new InvalidArgumentException('The direct Instagram publisher only accepts Instagram drafts.');
            }
        }
        foreach ($publicImageUrls as $url) {
            if (!$this->isHttpsUrl($url)) throw new InvalidArgumentException('Instagram requires public HTTPS image URLs.');
        }
        if (count($drafts) === 1) return $this->publishDraft($drafts[0], $userId, $publicImageUrls[0]);

        $purpose = (string)($drafts[0]['purpose'] ?? 'artist');
        $context = $this->integration->publishingContext($userId, $purpose);
        $graph = new InstagramGraphClient((string)$context['graph_version']);
        $instagramId = trim((string)$context['instagram_user_id']);
        $token = (string)$context['access_token'];
        $childIds = [];
        $children = [];
        foreach ($drafts as $index => $draft) {
            $child = $graph->request(
                'POST',
                '/'.rawurlencode($instagramId).'/media',
                self::carouselItemPayload($draft, $publicImageUrls[$index]),
                $token
            );
            $childId = trim((string)($child['id'] ?? ''));
            if ($childId === '') throw new RuntimeException('Instagram did not return a carousel item container ID.');
            $this->waitUntilFinished($graph, $childId, $token, 'carousel image');
            $childIds[] = $childId;
            $children[] = $child;
        }

        $parent = $graph->request(
            'POST',
            '/'.rawurlencode($instagramId).'/media',
            self::carouselContainerPayload($drafts[0], $childIds),
            $token
        );
        $parentId = trim((string)($parent['id'] ?? ''));
        if ($parentId === '') throw new RuntimeException('Instagram did not return a carousel container ID.');
        $this->waitUntilFinished($graph, $parentId, $token, 'carousel');

        $published = $graph->request(
            'POST',
            '/'.rawurlencode($instagramId).'/media_publish',
            ['creation_id' => $parentId],
            $token
        );
        $mediaId = trim((string)($published['id'] ?? ''));
        if ($mediaId === '') throw new RuntimeException('Instagram did not return a published carousel ID.');
        $url = $this->permalink($graph, $mediaId, $token);
        return [
            'id' => $mediaId,
            'url' => $url,
            'response' => [
                'children' => $children,
                'child_ids' => $childIds,
                'container_id' => $parentId,
                'published' => $published,
            ],
        ];
    }

    public static function carouselItemPayload(array $draft, string $publicImageUrl): array
    {
        $payload = ['image_url' => $publicImageUrl, 'is_carousel_item' => 'true'];
        $altText = trim((string)($draft['alt_text'] ?? ''));
        if ($altText !== '') $payload['alt_text'] = mb_substr($altText, 0, 1000);
        return $payload;
    }

    public static function carouselContainerPayload(array $draft, array $childIds): array
    {
        $childIds = array_values(array_filter(array_map(static fn ($id): string => trim((string)$id), $childIds)));
        if (count($childIds) < 2 || count($childIds) > 10) {
            throw new InvalidArgumentException('Instagram carousels require two to ten media containers.');
        }
        return [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $childIds),
            'caption' => MetaPublisher::instagramCaption($draft),
        ];
    }

    private function waitUntilFinished(InstagramGraphClient $graph, string $containerId, string $token, string $label): void
    {
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $status = $graph->request('GET', '/'.rawurlencode($containerId), ['fields' => 'status_code,status'], $token);
            $code = strtoupper(trim((string)($status['status_code'] ?? '')));
            if ($code === 'FINISHED') return;
            if (in_array($code, ['ERROR', 'EXPIRED'], true)) {
                throw new RuntimeException('Instagram could not prepare the ' . $label . '. ' . mb_substr((string)($status['status'] ?? ''), 0, 240));
            }
            usleep(750000);
        }
        throw new RuntimeException('Instagram did not finish preparing the ' . $label . ' in time.');
    }

    private function permalink(InstagramGraphClient $graph, string $mediaId, string $token): string
    {
        try {
            $details = $graph->request('GET', '/'.rawurlencode($mediaId), ['fields' => 'permalink'], $token);
            return trim((string)($details['permalink'] ?? ''));
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
