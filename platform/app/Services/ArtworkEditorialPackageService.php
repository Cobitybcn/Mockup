<?php
declare(strict_types=1);

final class ArtworkEditorialPackageService
{
    /** @var array<string,list<string>> */
    private const REQUIRED_PATHS = [
        'series' => [
            'subtitle', 'short_description', 'description', 'tags',
            'search_terms', 'seo_title', 'seo_description',
        ],
        'artwork' => [
            'subtitle', 'description', 'short_description', 'tags', 'search_terms',
            'seo_title', 'seo_description', 'alt_text', 'caption',
        ],
        'mockup' => [
            'description', 'tags', 'search_terms', 'seo_title', 'seo_description',
            'alt_text', 'caption', 'social.website.description', 'social.website.caption',
            'social.website.alt_text', 'social.pinterest.title', 'social.pinterest.description',
            'social.pinterest.board_suggestions', 'social.pinterest.topic_suggestions',
            'social.pinterest.keywords', 'social.instagram.caption', 'social.instagram.hook',
            'social.instagram.hashtags', 'social.instagram.cta', 'social.facebook.headline',
            'social.facebook.post_text', 'social.facebook.link_description', 'social.facebook.cta',
            'social.tiktok.visual_hook', 'social.tiktok.suggested_motion',
            'social.tiktok.sequence_role', 'social.tiktok.caption_seed', 'social.tiktok.video_notes',
        ],
    ];

    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function audit(int $userId, int $artworkId): array
    {
        $artwork = $this->artwork($userId, $artworkId);
        $profileReady = $this->artistProfileReady($userId);
        $title = trim((string)($artwork['final_title'] ?? ''));
        $titleReady = $title !== '' && strcasecmp($title, 'Untitled') !== 0;
        $seriesId = max(0, (int)($artwork['series_id'] ?? 0));
        $series = $seriesId > 0 ? $this->series($userId, $seriesId) : null;
        $seriesContextReady = $series === null || $this->seriesHasInstructions($series);
        $mockups = $this->mockups($userId, $artwork);
        $editorial = new BilingualEditorialService($this->pdo);
        $items = [];

        if ($series !== null) {
            $need = $this->editorialNeed($editorial, $userId, 'series', $seriesId);
            if ($need !== null) {
                $items[] = $this->scopeItem('series', $seriesId, 10, $need);
            }
        }

        $artworkNeed = $this->editorialNeed($editorial, $userId, 'artwork', $artworkId);
        if ($artworkNeed !== null) {
            $items[] = $this->scopeItem('artwork', $artworkId, 20, $artworkNeed);
        }

        foreach ($mockups as $mockup) {
            $mockupId = (int)$mockup['id'];
            $need = $this->editorialNeed($editorial, $userId, 'mockup', $mockupId);
            if ($need !== null) {
                $items[] = $this->scopeItem('mockup', $mockupId, 30, $need);
            }
        }

        $visiblePackage = $this->activePackage($userId, $artworkId);
        if ($visiblePackage) {
            $this->refreshPackage((int)$visiblePackage['id']);
            $visiblePackage = $this->package((int)$visiblePackage['id'], $userId);
        } else {
            $visiblePackage = $this->latestRetryablePackage($userId, $artworkId);
        }

        $mockupsPending = count(array_filter(
            $items,
            static fn(array $item): bool => $item['entity_type'] === 'mockup'
        ));
        $prerequisitesReady = $profileReady && $titleReady && $seriesContextReady && $mockups !== [];

        return [
            'artwork_id' => $artworkId,
            'title' => $title,
            'profile_ready' => $profileReady,
            'series' => $series === null ? null : [
                'id' => $seriesId,
                'title' => trim((string)($series['title'] ?? '')),
                'context_ready' => $seriesContextReady,
            ],
            'series_optional' => $series === null,
            'title_ready' => $titleReady,
            'mockups_total' => count($mockups),
            'mockups_pending' => $mockupsPending,
            'editorial_pending' => [
                'series' => count(array_filter($items, static fn(array $item): bool => $item['entity_type'] === 'series')),
                'artwork' => count(array_filter($items, static fn(array $item): bool => $item['entity_type'] === 'artwork')),
                'mockups' => $mockupsPending,
                'total' => count($items),
            ],
            'prerequisites_ready' => $prerequisitesReady,
            'can_start' => $prerequisitesReady && $items !== [] && $visiblePackage === null,
            'items' => $items,
            'package' => $visiblePackage ? $this->publicPackage($visiblePackage) : null,
        ];
    }

    /** @return array<string,mixed> */
    public function start(int $userId, int $artworkId): array
    {
        $this->beginWrite();
        try {
            $artwork = $this->lockArtwork($userId, $artworkId);
            if ($this->activePackage($userId, $artworkId)) {
                throw new RuntimeException('An editorial package is already being prepared for this artwork.');
            }
            $audit = $this->audit($userId, $artworkId);
            if (empty($audit['prerequisites_ready'])) {
                throw new RuntimeException('Complete the editorial preparation checklist before starting.');
            }
            if (empty($audit['items'])) {
                throw new RuntimeException('The editorial package is already complete.');
            }

            $now = date(DATE_ATOM);
            $scopeJson = json_encode([
                'title' => (string)$audit['title'],
                'series' => $audit['series'],
                'mockups_total' => (int)$audit['mockups_total'],
                'items' => $audit['items'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $stmt = $this->pdo->prepare(
                "INSERT INTO artwork_editorial_packages
                 (user_id,artwork_id,series_id,status,current_stage,scope_json,error,created_at,updated_at,completed_at)
                 VALUES (?,?,?,'queued',0,?,'',?,?,NULL)"
            );
            $stmt->execute([
                $userId,
                $artworkId,
                max(0, (int)($artwork['series_id'] ?? 0)) ?: null,
                $scopeJson,
                $now,
                $now,
            ]);
            $packageId = (int)$this->pdo->lastInsertId();
            $insertItem = $this->pdo->prepare(
                "INSERT INTO artwork_editorial_package_items
                 (package_id,entity_type,entity_id,stage_order,action,status,editorial_job_id,error,created_at,updated_at)
                 VALUES (?,?,?,?,?,'pending',NULL,'',?,?)"
            );
            foreach ($audit['items'] as $item) {
                $insertItem->execute([
                    $packageId,
                    (string)$item['entity_type'],
                    (int)$item['entity_id'],
                    (int)$item['stage_order'],
                    (string)$item['action'],
                    $now,
                    $now,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        $this->dispatchNextStage($packageId);
        return $this->publicPackage($this->package($packageId, $userId));
    }

    /** @return array<string,mixed> */
    public function retryFailed(int $userId, int $packageId): array
    {
        $package = $this->package($packageId, $userId);
        if (!in_array((string)$package['status'], ['failed', 'partial'], true)) {
            throw new RuntimeException('This editorial package has no failed items to retry.');
        }
        $now = date(DATE_ATOM);
        $stmt = $this->pdo->prepare(
            "UPDATE artwork_editorial_package_items
             SET status='pending',editorial_job_id=NULL,error='',updated_at=?
             WHERE package_id=? AND status='failed'"
        );
        $stmt->execute([$now, $packageId]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('No failed editorial items were found.');
        }
        $this->pdo->prepare(
            "UPDATE artwork_editorial_packages
             SET status='queued',current_stage=0,error='',completed_at=NULL,updated_at=?
             WHERE id=? AND user_id=?"
        )->execute([$now, $packageId, $userId]);
        $this->dispatchNextStage($packageId);
        return $this->publicPackage($this->package($packageId, $userId));
    }

    public function refreshPackagesForEditorialJob(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT package_id FROM artwork_editorial_package_items WHERE editorial_job_id=?'
        );
        $stmt->execute([$jobId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $packageId) {
            $this->refreshPackage((int)$packageId);
        }
    }

    public function refreshPackage(int $packageId): void
    {
        $package = $this->package($packageId);
        if (!in_array((string)$package['status'], ['queued', 'processing'], true)) {
            return;
        }
        $now = date(DATE_ATOM);
        $items = $this->items($packageId);
        foreach ($items as $item) {
            $jobId = max(0, (int)($item['editorial_job_id'] ?? 0));
            if ($jobId <= 0 || !in_array((string)$item['status'], ['queued', 'processing'], true)) {
                continue;
            }
            try {
                $job = (new BilingualEditorialJobService($this->pdo))->job($jobId);
                $jobStatus = (string)$job['status'];
                $status = match ($jobStatus) {
                    'completed' => 'completed',
                    'failed', 'enqueue_failed' => 'failed',
                    'processing' => 'processing',
                    default => 'queued',
                };
                $this->pdo->prepare(
                    'UPDATE artwork_editorial_package_items SET status=?,error=?,updated_at=? WHERE id=?'
                )->execute([
                    $status,
                    $status === 'failed' ? (string)$job['error'] : '',
                    $now,
                    (int)$item['id'],
                ]);
            } catch (Throwable $error) {
                $this->pdo->prepare(
                    "UPDATE artwork_editorial_package_items SET status='failed',error=?,updated_at=? WHERE id=?"
                )->execute([$error->getMessage(), $now, (int)$item['id']]);
            }
        }

        $items = $this->items($packageId);
        $active = array_filter($items, static fn(array $item): bool =>
            in_array((string)$item['status'], ['queued', 'processing'], true)
        );
        if ($active !== []) {
            return;
        }

        $failed = array_values(array_filter($items, static fn(array $item): bool =>
            (string)$item['status'] === 'failed'
        ));
        $pending = array_values(array_filter($items, static fn(array $item): bool =>
            (string)$item['status'] === 'pending'
        ));
        $failedBlocking = array_values(array_filter($failed, static fn(array $item): bool =>
            (int)$item['stage_order'] < 30
        ));
        if ($failedBlocking !== []) {
            $this->finishPackage($packageId, 'failed', count($failedBlocking) . ' prerequisite editorial item(s) failed.');
            return;
        }
        if ($pending !== []) {
            $this->dispatchNextStage($packageId);
            return;
        }
        $this->finishPackage(
            $packageId,
            $failed === [] ? 'completed' : 'partial',
            $failed === [] ? '' : count($failed) . ' mockup editorial item(s) failed.'
        );
    }

    /** @return array<string,mixed> */
    public function package(int $packageId, ?int $userId = null): array
    {
        $sql = 'SELECT * FROM artwork_editorial_packages WHERE id=?';
        $params = [$packageId];
        if ($userId !== null) {
            $sql .= ' AND user_id=?';
            $params[] = $userId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Editorial package not found.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function publicPackage(array $package): array
    {
        $items = $this->items((int)$package['id']);
        $counts = ['total' => count($items), 'pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        $failedItems = [];
        foreach ($items as $item) {
            $status = (string)$item['status'];
            if (isset($counts[$status])) {
                $counts[$status]++;
            } elseif ($status === 'queued') {
                $counts['pending']++;
            }
            if ($status === 'failed') {
                $failedItems[] = [
                    'entity_type' => (string)$item['entity_type'],
                    'entity_id' => (int)$item['entity_id'],
                    'error' => (string)$item['error'],
                ];
            }
        }
        return [
            'id' => (int)$package['id'],
            'artwork_id' => (int)$package['artwork_id'],
            'status' => (string)$package['status'],
            'current_stage' => (int)$package['current_stage'],
            'stage_label' => $this->stageLabel((int)$package['current_stage']),
            'counts' => $counts,
            'failed_items' => $failedItems,
            'error' => (string)$package['error'],
            'created_at' => (string)$package['created_at'],
            'updated_at' => (string)$package['updated_at'],
            'completed_at' => (string)($package['completed_at'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function artwork(int $userId, int $artworkId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id,user_id,final_title,series_id,artwork_group_id,root_file FROM artworks WHERE id=? AND user_id=? LIMIT 1'
        );
        $stmt->execute([$artworkId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Artwork not found.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function lockArtwork(int $userId, int $artworkId): array
    {
        $suffix = strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $this->pdo->prepare(
            'SELECT id,user_id,final_title,series_id,artwork_group_id,root_file FROM artworks WHERE id=? AND user_id=? LIMIT 1' . $suffix
        );
        $stmt->execute([$artworkId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Artwork not found.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function series(int $userId, int $seriesId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id,title,description,long_description,conceptual_core,interpretive_limits FROM artwork_series WHERE id=? AND user_id=? LIMIT 1'
        );
        $stmt->execute([$seriesId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Assigned series not found.');
        }
        return $row;
    }

    private function seriesHasInstructions(array $series): bool
    {
        foreach (['conceptual_core', 'interpretive_limits', 'description', 'long_description'] as $field) {
            if (trim((string)($series[$field] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    private function artistProfileReady(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artist_profiles WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($profile) && ArtistProfile::hasContent($profile);
    }

    /** @return list<array<string,mixed>> */
    private function mockups(int $userId, array $artwork): array
    {
        $groupId = max(0, (int)($artwork['artwork_group_id'] ?? 0));
        if ($groupId > 0) {
            $stmt = $this->pdo->prepare(
                "SELECT id,mockup_file FROM mockups
                 WHERE user_id=? AND artwork_group_id=? AND COALESCE(mockup_file,'')<>''
                 ORDER BY id"
            );
            $stmt->execute([$userId, $groupId]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT id,mockup_file FROM mockups
                 WHERE user_id=? AND (source_artwork_id=? OR artwork_file=?)
                 AND COALESCE(mockup_file,'')<>'' ORDER BY id"
            );
            $stmt->execute([$userId, (int)$artwork['id'], basename((string)$artwork['root_file'])]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function editorialNeed(
        BilingualEditorialService $editorial,
        int $userId,
        string $entityType,
        int $entityId
    ): ?string {
        $spanish = $editorial->get($userId, $entityType, $entityId, 'es');
        $english = $editorial->get($userId, $entityType, $entityId, 'en');
        if (!$this->hasRequiredContent($entityType, (array)$spanish['content'])) {
            return 'prepare';
        }
        if ((string)$english['status'] !== 'current'
            || !$this->hasRequiredContent($entityType, (array)$english['content'])) {
            return 'adapt';
        }
        return null;
    }

    private function hasRequiredContent(string $entityType, array $content): bool
    {
        foreach (self::REQUIRED_PATHS[$entityType] ?? [] as $path) {
            $value = $content;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return false;
                }
                $value = $value[$segment];
            }
            if (!is_scalar($value) || trim((string)$value) === '') {
                return false;
            }
        }
        return true;
    }

    /** @return array{entity_type:string,entity_id:int,stage_order:int,action:string} */
    private function scopeItem(string $entityType, int $entityId, int $stageOrder, string $action): array
    {
        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'stage_order' => $stageOrder,
            'action' => $action,
        ];
    }

    /** @return array<string,mixed>|null */
    private function activePackage(int $userId, int $artworkId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM artwork_editorial_packages
             WHERE user_id=? AND artwork_id=? AND status IN ('queued','processing')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId, $artworkId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function latestRetryablePackage(int $userId, int $artworkId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.* FROM artwork_editorial_packages p
             WHERE p.user_id=? AND p.artwork_id=? AND p.status IN ('failed','partial')
             AND EXISTS (
                 SELECT 1 FROM artwork_editorial_package_items i
                 WHERE i.package_id=p.id AND i.status='failed'
             )
             ORDER BY p.id DESC LIMIT 1"
        );
        $stmt->execute([$userId, $artworkId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function items(int $packageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM artwork_editorial_package_items WHERE package_id=? ORDER BY stage_order,id'
        );
        $stmt->execute([$packageId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function dispatchNextStage(int $packageId): void
    {
        $package = $this->package($packageId);
        if (!in_array((string)$package['status'], ['queued', 'processing'], true)) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "SELECT MIN(stage_order) FROM artwork_editorial_package_items
             WHERE package_id=? AND status='pending'"
        );
        $stmt->execute([$packageId]);
        $stage = (int)($stmt->fetchColumn() ?: 0);
        if ($stage <= 0) {
            $this->refreshPackage($packageId);
            return;
        }
        $now = date(DATE_ATOM);
        $this->pdo->prepare(
            "UPDATE artwork_editorial_packages SET status='processing',current_stage=?,updated_at=? WHERE id=?"
        )->execute([$stage, $now, $packageId]);
        $stmt = $this->pdo->prepare(
            "SELECT * FROM artwork_editorial_package_items
             WHERE package_id=? AND stage_order=? AND status='pending' ORDER BY id"
        );
        $stmt->execute([$packageId, $stage]);
        $stageItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $jobsService = new BilingualEditorialJobService($this->pdo);
        $editorial = new BilingualEditorialService($this->pdo);
        $localJobs = [];

        foreach ($stageItems as $item) {
            try {
                $entityType = (string)$item['entity_type'];
                $entityId = (int)$item['entity_id'];
                $spanish = $editorial->get((int)$package['user_id'], $entityType, $entityId, 'es');
                $job = $jobsService->createOrReuse(
                    (int)$package['user_id'],
                    $entityType,
                    $entityId,
                    (string)$item['action'],
                    [
                        'current_spanish' => (array)$spanish['content'],
                        'private_memo' => (string)$spanish['private_memo'],
                        'publish_spanish' => false,
                        'editorial_package_id' => $packageId,
                    ]
                );
                $this->pdo->prepare(
                    "UPDATE artwork_editorial_package_items
                     SET editorial_job_id=?,status=?,error='',updated_at=? WHERE id=?"
                )->execute([
                    (int)$job['id'],
                    (string)$job['status'] === 'processing' ? 'processing' : 'queued',
                    $now,
                    (int)$item['id'],
                ]);
                if ((string)$job['status'] === 'queued' && trim((string)$job['task_name']) === '') {
                    if (CloudTasksService::isAvailable()) {
                        $taskName = CloudTasksService::enqueueEditorialGeneration((int)$job['id']);
                        $jobsService->attachTask((int)$job['id'], (int)$package['user_id'], $taskName);
                    } else {
                        $localJobs[] = (int)$job['id'];
                    }
                }
            } catch (Throwable $error) {
                $this->pdo->prepare(
                    "UPDATE artwork_editorial_package_items
                     SET status='failed',error=?,updated_at=? WHERE id=?"
                )->execute([$error->getMessage(), $now, (int)$item['id']]);
            }
        }

        foreach ($localJobs as $jobId) {
            (new BilingualEditorialGenerationWorker($this->pdo))->process($jobId);
        }
        if ($localJobs === []) {
            $this->refreshPackage($packageId);
        }
    }

    private function finishPackage(int $packageId, string $status, string $error): void
    {
        $now = date(DATE_ATOM);
        $this->pdo->prepare(
            'UPDATE artwork_editorial_packages SET status=?,error=?,completed_at=?,updated_at=? WHERE id=?'
        )->execute([$status, $error, $now, $now, $packageId]);
    }

    private function stageLabel(int $stage): string
    {
        return match ($stage) {
            10 => 'Series',
            20 => 'Artwork',
            30 => 'Mockups',
            default => '',
        };
    }

    private function beginWrite(): void
    {
        if ($this->pdo->inTransaction()) {
            return;
        }
        if (strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE TRANSACTION');
            return;
        }
        $this->pdo->beginTransaction();
    }
}
