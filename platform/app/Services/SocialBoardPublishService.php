<?php
declare(strict_types=1);

final class SocialBoardPublishService
{
    private const CHANNEL_LIMITS = ['pinterest' => 50, 'instagram' => 10, 'facebook' => 3];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SocialPublishJobService $jobs
    ) {}

    /** @return array{jobs:array<int,array>,publication_count:int,scheduled_at:array<int,string>,delivery_mode:string} */
    public function schedule(array $user, array $input): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) throw new RuntimeException('The social publication session is invalid.');

        $timezone = $this->timezone((string)($input['timezone'] ?? 'UTC'));
        $defaultSchedule = (array)($input['schedule'] ?? []);
        $pinterestItems = array_values((array)($input['pinterest'] ?? []));
        $instagramGroups = array_values((array)($input['instagram'] ?? []));
        $facebookGroups = array_values((array)($input['facebook'] ?? []));
        $pinterestPurpose = $this->pinterestPurpose($user, (string)($input['pinterest_purpose'] ?? 'artist'));
        $deliveryMode = strtolower(trim((string)($defaultSchedule['mode'] ?? 'scheduled'))) === 'now' ? 'now' : 'scheduled';
        $publicationCount = count($pinterestItems) + count($instagramGroups) + count($facebookGroups);
        if ($publicationCount === 0) throw new InvalidArgumentException('Add at least one publication before confirming.');
        if ($publicationCount > 80) throw new InvalidArgumentException('Schedule at most 80 publications at a time.');

        $this->assertPublicRuntime($pinterestItems, $instagramGroups, $facebookGroups);
        $pinterestBoards = $pinterestItems ? $this->pinterestBoards($userId, $pinterestPurpose) : [];
        $this->assertMetaConnections($userId, $instagramGroups, $facebookGroups);

        $created = [];
        $scheduled = [];
        foreach ($pinterestItems as $position => $item) {
            if (!is_array($item)) throw new InvalidArgumentException('Invalid Pinterest publication.');
            $when = $this->scheduledAt((array)($item['schedule'] ?? $defaultSchedule), $timezone);
            $job = $this->preparePinterest($user, $item, $pinterestBoards, $pinterestPurpose, $when, $position);
            $created[] = $this->enqueueIfNeeded($job, $when, $deliveryMode === 'now');
            $scheduled[] = $when->format(DateTimeInterface::ATOM);
        }
        foreach (['instagram' => $instagramGroups, 'facebook' => $facebookGroups] as $channel => $groups) {
            foreach ($groups as $position => $group) {
                if (!is_array($group)) throw new InvalidArgumentException('Invalid ' . ucfirst($channel) . ' publication.');
                $when = $this->scheduledAt((array)($group['schedule'] ?? $defaultSchedule), $timezone);
                $job = $this->prepareMeta($user, $channel, $group, $when, $position);
                $created[] = $this->enqueueIfNeeded($job, $when, false);
                $scheduled[] = $when->format(DateTimeInterface::ATOM);
            }
        }

        return ['jobs' => $created, 'publication_count' => $publicationCount, 'scheduled_at' => $scheduled, 'delivery_mode' => $deliveryMode];
    }

    private function preparePinterest(array $user, array $item, array $boards, string $purpose, DateTimeImmutable $when, int $position): array
    {
        $userId = (int)$user['id'];
        $mockupId = max(0, (int)($item['mockup_id'] ?? 0));
        $boardId = trim((string)($item['board_id'] ?? ''));
        $title = trim((string)($item['title'] ?? ''));
        $description = trim((string)($item['description'] ?? ''));
        $destinationUrl = $this->httpsUrl((string)($item['destination_url'] ?? ''), 'Pinterest destination');
        if ($mockupId <= 0) throw new InvalidArgumentException('A Pinterest Pin has no mockup.');
        if ($boardId === '') throw new InvalidArgumentException('Select a Pinterest board for every Pin.');
        if ($title === '') throw new InvalidArgumentException('Every Pinterest Pin needs a title.');
        $this->assertMockupsOwned($userId, [$mockupId]);

        $normalized = [
            'client_key' => trim((string)($item['client_key'] ?? 'pin-' . $mockupId . '-' . $position)),
            'mockup_id' => $mockupId,
            'board_id' => $boardId,
            'title' => mb_substr($title, 0, 100),
            'description' => mb_substr($description, 0, 500),
            'destination_url' => $destinationUrl,
            'purpose' => $purpose,
            'scheduled_at' => $when->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
        ];
        $key = $this->idempotencyKey($userId, 'pinterest', $normalized);
        $existing = $this->jobs->findByKey($userId, $key);
        if ($existing) return $existing;

        $drafts = new MockupPinterestDraftService($this->pdo);
        $draft = $drafts->create($mockupId, $user, $purpose, $destinationUrl);
        $draftId = (int)$draft['id'];
        $drafts->updateContent($draftId, $userId, $title, $description, (string)($item['alt_text'] ?? ''));
        $drafts->selectBoard($draftId, $userId, $boardId, $boards);
        $drafts->saveCrop($draftId, $userId, 0.5, 0.5, 1.0);

        return $this->jobs->create($userId, 'pinterest', $purpose, $when, [
            'schema_version' => 'social-board-job.v1',
            'draft_ids' => [$draftId],
            'client_key' => $normalized['client_key'],
        ], $key);
    }

    private function prepareMeta(array $user, string $channel, array $group, DateTimeImmutable $when, int $position): array
    {
        $userId = (int)$user['id'];
        $limit = self::CHANNEL_LIMITS[$channel];
        $mockupIds = array_values(array_unique(array_filter(array_map('intval', (array)($group['mockup_ids'] ?? [])))));
        if (!$mockupIds) throw new InvalidArgumentException(ucfirst($channel) . ' has an empty publication.');
        if (count($mockupIds) > $limit) {
            throw new InvalidArgumentException(ucfirst($channel) . ' accepts at most ' . $limit . ' images in one publication.');
        }
        $copy = trim((string)($group['copy'] ?? ''));
        if ($copy === '') throw new InvalidArgumentException('Every ' . ucfirst($channel) . ' publication needs text.');
        if ($channel === 'instagram' && mb_strlen($copy) > 2200) {
            throw new InvalidArgumentException('Instagram captions accept at most 2200 characters.');
        }
        if ($channel === 'facebook' && mb_strlen($copy) > 5000) {
            throw new InvalidArgumentException('Facebook publication text is too long.');
        }
        $destinationUrl = $this->httpsUrl((string)($group['destination_url'] ?? ''), ucfirst($channel) . ' destination');
        $this->assertMockupsOwned($userId, $mockupIds);

        $normalized = [
            'client_key' => trim((string)($group['client_key'] ?? $channel . '-' . $position)),
            'mockup_ids' => $mockupIds,
            'copy' => $copy,
            'destination_url' => $destinationUrl,
            'scheduled_at' => $when->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
        ];
        $key = $this->idempotencyKey($userId, $channel, $normalized);
        $existing = $this->jobs->findByKey($userId, $key);
        if ($existing) return $existing;

        $drafts = new MetaSocialDraftService($this->pdo);
        $draftIds = [];
        foreach ($mockupIds as $mockupId) {
            $draftId = $drafts->create($mockupId, $user, $channel, $destinationUrl, 'artist');
            $draft = $drafts->draft($draftId, $userId);
            $drafts->updateContent(
                $draftId,
                $userId,
                (string)($draft['title'] ?? ''),
                $copy,
                [],
                (string)($draft['alt_text'] ?? ''),
                $destinationUrl
            );
            $draftIds[] = $draftId;
        }

        return $this->jobs->create($userId, $channel, 'artist', $when, [
            'schema_version' => 'social-board-job.v1',
            'draft_ids' => $draftIds,
            'client_key' => $normalized['client_key'],
        ], $key);
    }

    private function enqueueIfNeeded(array $job, DateTimeImmutable $when, bool $publishDirectly = false): array
    {
        $status = (string)($job['status'] ?? '');
        if ($status === 'failed') {
            throw new RuntimeException('Esta publicación falló anteriormente. Usa la acción Reintentar para no crear un duplicado incierto.');
        }
        if ($status === 'needs_verification') {
            throw new RuntimeException('Verifica primero la cuenta social real antes de intentar esta publicación nuevamente.');
        }
        if ($status === 'published' || trim((string)($job['task_name'] ?? '')) !== '') {
            return $this->summary($job);
        }
        if ($publishDirectly && (string)($job['channel'] ?? '') === 'pinterest' && !CloudTasksService::isAvailable()) {
            return $this->publishPinterestDirectly($job);
        }
        try {
            $taskName = CloudTasksService::enqueueSocialPublication((int)$job['id'], $when);
            $this->jobs->attachTask((int)$job['id'], (int)$job['user_id'], $taskName);
        } catch (Throwable $e) {
            $this->jobs->markEnqueueFailed((int)$job['id'], (int)$job['user_id'], $e->getMessage());
            throw new RuntimeException('The publication was prepared but could not be added to the scheduler. ' . $e->getMessage(), 0, $e);
        }
        return $this->summary($this->jobs->job((int)$job['id'], (int)$job['user_id']));
    }

    private function publishPinterestDirectly(array $job): array
    {
        $jobId = (int)$job['id'];
        $userId = (int)$job['user_id'];
        $claimed = $this->jobs->claim($jobId);
        if (!is_array($claimed)) return $this->summary($this->jobs->job($jobId, $userId));
        $attemptId = (string)$claimed['publish_attempt_id'];
        $draftService = new MockupPinterestDraftService($this->pdo);
        $draftId = 0;
        try {
            $jobPayload = json_decode((string)$claimed['payload_json'], true);
            $draftIds = array_values(array_unique(array_filter(array_map('intval', (array)($jobPayload['draft_ids'] ?? [])))));
            $draftId = (int)($draftIds[0] ?? 0);
            if ($draftId <= 0) throw new RuntimeException('La publicación no tiene una imagen preparada.');
            $draft = $draftService->draft($draftId, $userId);
            $variant = basename((string)($draft['variant_file'] ?? ''));
            if ($variant === '' || trim((string)($draft['board_id'] ?? '')) === '') {
                throw new RuntimeException('El Pin de Pinterest no está completamente preparado.');
            }
            $imagePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pinterest_drafts' . DIRECTORY_SEPARATOR . $variant;
            if (!is_file($imagePath) && StorageService::isGcsActive()) {
                StorageService::downloadFile('pinterest-drafts/' . $variant, $imagePath);
            }
            $pinPayload = (new PinterestPublisher())->imageBase64PinPayload(
                ['title' => $draft['title'], 'description' => $draft['description']],
                $draft,
                (string)$draft['board_id'],
                (string)$draft['destination_url'],
                $imagePath
            );
            $response = (new PinterestIntegrationService($this->pdo))->createPin($userId, $pinPayload, (string)$draft['purpose']);
            $externalId = trim((string)($response['id'] ?? ''));
            if ($externalId === '') throw new RuntimeException('Pinterest no devolvió el identificador del Pin.');
            $externalUrl = trim((string)($response['link'] ?? ''));
            if ($externalUrl === '') $externalUrl = 'https://www.pinterest.com/pin/' . rawurlencode($externalId) . '/';
            $draftService->markPublished($draftId, $userId, $externalId, $externalUrl, $response);
            $this->jobs->markPublished($jobId, $attemptId, $externalId, $externalUrl);
            return $this->summary($this->jobs->job($jobId, $userId));
        } catch (Throwable $e) {
            if ($draftId > 0) $draftService->markFailed($draftId, $userId, $e->getMessage());
            if ($e instanceof PinterestTransportException) $this->jobs->markNeedsVerification($jobId, $attemptId, $e->getMessage());
            else $this->jobs->markFailed($jobId, $attemptId, $e->getMessage());
            throw $e;
        }
    }

    private function summary(array $job): array
    {
        $payload = json_decode((string)($job['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        return [
            'id' => (int)$job['id'],
            'channel' => (string)$job['channel'],
            'status' => (string)$job['status'],
            'scheduled_at' => (string)$job['scheduled_at'],
            'client_key' => (string)($payload['client_key'] ?? ''),
        ];
    }

    private function assertPublicRuntime(array $pinterest, array $instagram, array $facebook): void
    {
        $base = rtrim(app_env('APP_PUBLIC_URL', ''), '/');
        $needsPublicMedia = (bool)($instagram || $facebook || ($pinterest && CloudTasksService::isAvailable()));
        if ($needsPublicMedia && !str_starts_with(strtolower($base), 'https://')) {
            throw new RuntimeException('Real publication requires the public HTTPS site. Localhost can only validate the design.');
        }
        if ($pinterest && (app_env('PINTEREST_LIVE_PUBLISH_ENABLED', 'false') !== 'true'
            || (CloudTasksService::isAvailable() && app_env('PINTEREST_DRAFT_PUBLIC_MEDIA_ENABLED', 'false') !== 'true'))) {
            throw new RuntimeException('Pinterest live publication is not enabled in this environment.');
        }
        if ($facebook && (app_env('META_LIVE_PUBLISH_ENABLED', 'false') !== 'true'
            || app_env('META_DRAFT_PUBLIC_MEDIA_ENABLED', 'false') !== 'true')) {
            throw new RuntimeException('Facebook live publication is not enabled in this environment.');
        }
        if ($instagram && (app_env('INSTAGRAM_LIVE_PUBLISH_ENABLED', 'false') !== 'true'
            || app_env('INSTAGRAM_DRAFT_PUBLIC_MEDIA_ENABLED', 'false') !== 'true')) {
            throw new RuntimeException('Instagram live publication is not enabled in this environment.');
        }
    }

    private function assertMetaConnections(int $userId, array $instagram, array $facebook): void
    {
        if ($facebook) (new MetaIntegrationService($this->pdo))->assertPublishingReady($userId, 'artist', ['facebook']);
        if ($instagram) (new InstagramIntegrationService($this->pdo))->publishingContext($userId, 'artist');
    }

    private function pinterestBoards(int $userId, string $purpose): array
    {
        return (new PinterestIntegrationService($this->pdo))->boards($userId, $purpose);
    }

    private function pinterestPurpose(array $user, string $purpose): string
    {
        $purpose = strtolower(trim($purpose));
        if (!in_array($purpose, ['artist', 'platform'], true)) {
            throw new InvalidArgumentException('Invalid Pinterest identity.');
        }
        if ($purpose === 'platform' && (int)($user['is_admin'] ?? 0) !== 1) {
            throw new RuntimeException('The Artworks Mockups Pinterest identity is available to administrators only.');
        }
        return $purpose;
    }

    private function assertMockupsOwned(int $userId, array $mockupIds): void
    {
        $mockupIds = array_values(array_unique(array_map('intval', $mockupIds)));
        if (!$mockupIds) throw new InvalidArgumentException('No mockups selected.');
        $marks = implode(',', array_fill(0, count($mockupIds), '?'));
        $stmt = $this->pdo->prepare("SELECT id FROM mockups WHERE user_id=? AND id IN ({$marks})");
        $stmt->execute(array_merge([$userId], $mockupIds));
        $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        sort($found);
        $expected = $mockupIds;
        sort($expected);
        if ($found !== $expected) throw new RuntimeException('One or more selected mockups are unavailable.');
    }

    private function scheduledAt(array $schedule, DateTimeZone $timezone): DateTimeImmutable
    {
        if (strtolower(trim((string)($schedule['mode'] ?? 'scheduled'))) === 'now') {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
        $date = trim((string)($schedule['date'] ?? ''));
        $time = trim((string)($schedule['time'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            throw new InvalidArgumentException('Choose a valid publication date and time.');
        }
        $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$value || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            throw new InvalidArgumentException('Choose a valid publication date and time.');
        }
        $now = new DateTimeImmutable('now', $timezone);
        if ($value < $now->modify('-1 minute')) throw new InvalidArgumentException('The publication date cannot be in the past.');
        if ($value > $now->modify('+30 days')) throw new InvalidArgumentException('Cloud Tasks allows scheduling up to 30 days ahead.');
        return $value < $now ? $now : $value;
    }

    private function timezone(string $value): DateTimeZone
    {
        try {
            return new DateTimeZone(trim($value) !== '' ? trim($value) : 'UTC');
        } catch (Throwable) {
            throw new InvalidArgumentException('Invalid publication timezone.');
        }
    }

    private function httpsUrl(string $url, string $label): string
    {
        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new InvalidArgumentException($label . ' must be a public HTTPS URL.');
        }
        return $url;
    }

    private function idempotencyKey(int $userId, string $channel, array $normalized): string
    {
        return hash('sha256', json_encode([$userId, $channel, $normalized], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
