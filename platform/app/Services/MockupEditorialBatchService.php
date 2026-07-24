<?php
declare(strict_types=1);

final class MockupEditorialBatchService
{
    /** @var list<string> */
    private const REQUIRED_PATHS = [
        'description',
        'tags',
        'search_terms',
        'seo_title',
        'seo_description',
        'alt_text',
        'caption',
        'social.website.description',
        'social.website.caption',
        'social.website.alt_text',
        'social.pinterest.title',
        'social.pinterest.description',
        'social.pinterest.board_suggestions',
        'social.pinterest.topic_suggestions',
        'social.pinterest.keywords',
        'social.instagram.caption',
        'social.instagram.hook',
        'social.instagram.hashtags',
        'social.instagram.cta',
        'social.facebook.headline',
        'social.facebook.post_text',
        'social.facebook.link_description',
        'social.facebook.cta',
        'social.tiktok.visual_hook',
        'social.tiktok.suggested_motion',
        'social.tiktok.sequence_role',
        'social.tiktok.caption_seed',
        'social.tiktok.video_notes',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array{
     *   user_id:int,email:string,total_active_mockups:int,incomplete_count:int,
     *   already_queued_count:int,audit_token:string,items:list<array<string,mixed>>
     * }
     */
    public function audit(string $email): array
    {
        $user = $this->userByEmail($email);
        $userId = (int)$user['id'];
        $rows = $this->activeMockups($userId);
        $content = $this->contentByMockup($userId);
        $activeJobs = $this->activeJobsByMockup($userId);
        $items = [];

        foreach ($rows as $row) {
            $mockupId = (int)$row['id'];
            $spanish = $content[$mockupId]['es'] ?? $this->emptyEditorialRow();
            $english = $content[$mockupId]['en'] ?? $this->emptyEditorialRow();
            $missingSpanish = $this->missingPaths((array)$spanish['content']);
            $missingEnglish = $this->missingPaths((array)$english['content']);
            $englishStatus = trim((string)$english['status']);
            if ($missingSpanish === [] && $missingEnglish === [] && $englishStatus === 'current') {
                continue;
            }

            $items[] = [
                'mockup_id' => $mockupId,
                'artwork_id' => (int)$row['source_artwork_id'],
                'artwork_title' => trim((string)$row['artwork_title']),
                'mockup_file' => basename((string)$row['mockup_file']),
                'missing_es' => $missingSpanish,
                'missing_en' => $missingEnglish,
                'english_status' => $englishStatus !== '' ? $englishStatus : 'unprepared',
                'active_job_id' => isset($activeJobs[$mockupId]) ? (int)$activeJobs[$mockupId] : 0,
                'state_version' => hash('sha256', implode('|', [
                    (string)($spanish['updated_at'] ?? ''),
                    (string)($english['updated_at'] ?? ''),
                    (string)($spanish['status'] ?? ''),
                    (string)($english['status'] ?? ''),
                ])),
            ];
        }

        usort($items, static fn(array $left, array $right): int =>
            [$left['artwork_id'], $left['mockup_id']] <=> [$right['artwork_id'], $right['mockup_id']]
        );
        $tokenPayload = array_map(
            static fn(array $item): array => [
                (int)$item['mockup_id'],
                (string)$item['state_version'],
                (int)$item['active_job_id'],
            ],
            $items
        );

        return [
            'user_id' => $userId,
            'email' => strtolower(trim((string)$user['email'])),
            'total_active_mockups' => count($rows),
            'incomplete_count' => count($items),
            'already_queued_count' => count(array_filter(
                $items,
                static fn(array $item): bool => (int)$item['active_job_id'] > 0
            )),
            'audit_token' => hash('sha256', json_encode($tokenPayload, JSON_UNESCAPED_SLASHES)),
            'items' => $items,
        ];
    }

    /**
     * @return array{audit_token:string,eligible_count:int,enqueued_count:int,reused_count:int,jobs:list<array<string,mixed>>}
     */
    public function enqueue(string $email, string $expectedAuditToken): array
    {
        $audit = $this->audit($email);
        if ($expectedAuditToken === '' || !hash_equals($audit['audit_token'], $expectedAuditToken)) {
            throw new RuntimeException('El conjunto editorial cambió desde la auditoría. Repetí la auditoría antes de encolar.');
        }

        $editorial = new BilingualEditorialService($this->pdo);
        $jobs = new BilingualEditorialJobService($this->pdo);
        $jobRows = [];
        $enqueued = 0;
        $reused = 0;

        foreach ($audit['items'] as $item) {
            $mockupId = (int)$item['mockup_id'];
            $existingJobId = (int)$item['active_job_id'];
            if ($existingJobId > 0) {
                $jobRows[] = ['mockup_id' => $mockupId, 'job_id' => $existingJobId, 'status' => 'reused'];
                $reused++;
                continue;
            }

            $spanish = $editorial->get((int)$audit['user_id'], 'mockup', $mockupId, 'es');
            $job = $jobs->createOrReuse(
                (int)$audit['user_id'],
                'mockup',
                $mockupId,
                'prepare',
                [
                    'current_spanish' => (array)$spanish['content'],
                    'private_memo' => (string)$spanish['private_memo'],
                    'batch_reason' => 'incomplete_editorial_content',
                ]
            );

            if ((string)$job['status'] === 'queued' && trim((string)$job['task_name']) === '') {
                $taskName = CloudTasksService::enqueueEditorialGeneration((int)$job['id']);
                $jobs->attachTask((int)$job['id'], (int)$audit['user_id'], $taskName);
            }
            $job = $jobs->job((int)$job['id'], (int)$audit['user_id']);
            $jobRows[] = [
                'mockup_id' => $mockupId,
                'job_id' => (int)$job['id'],
                'status' => (string)$job['status'],
            ];
            $enqueued++;
        }

        return [
            'audit_token' => $audit['audit_token'],
            'eligible_count' => count($audit['items']),
            'enqueued_count' => $enqueued,
            'reused_count' => $reused,
            'jobs' => $jobRows,
        ];
    }

    private function userByEmail(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            throw new InvalidArgumentException('Missing artist email.');
        }
        $stmt = $this->pdo->prepare('SELECT id,email FROM users WHERE LOWER(email)=? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Artist account not found.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function activeMockups(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.id,m.source_artwork_id,m.mockup_file,COALESCE(a.final_title,'') artwork_title
             FROM mockups m
             INNER JOIN artworks a ON a.id=m.source_artwork_id AND a.user_id=m.user_id
             WHERE m.user_id=?
             ORDER BY m.source_artwork_id,m.id"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,array<string,mixed>>> */
    private function contentByMockup(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT entity_id,locale,content_json,status,updated_at
             FROM bilingual_editorial_content
             WHERE user_id=? AND entity_type='mockup' AND locale IN ('es','en')"
        );
        $stmt->execute([$userId]);
        $content = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $decoded = json_decode((string)$row['content_json'], true);
            $content[(int)$row['entity_id']][(string)$row['locale']] = [
                'content' => is_array($decoded) ? $decoded : [],
                'status' => (string)$row['status'],
                'updated_at' => (string)$row['updated_at'],
            ];
        }
        return $content;
    }

    /** @return array<int,int> */
    private function activeJobsByMockup(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT entity_id,MAX(id) job_id
             FROM bilingual_editorial_jobs
             WHERE user_id=? AND entity_type='mockup' AND status IN ('queued','processing')
             GROUP BY entity_id"
        );
        $stmt->execute([$userId]);
        $jobs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $jobs[(int)$row['entity_id']] = (int)$row['job_id'];
        }
        return $jobs;
    }

    /** @return list<string> */
    private function missingPaths(array $content): array
    {
        $missing = [];
        foreach (self::REQUIRED_PATHS as $path) {
            $value = $content;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = '';
                    break;
                }
                $value = $value[$segment];
            }
            if (!is_scalar($value) || trim((string)$value) === '') {
                $missing[] = $path;
            }
        }
        return $missing;
    }

    /** @return array{content:array,status:string,updated_at:string} */
    private function emptyEditorialRow(): array
    {
        return ['content' => [], 'status' => '', 'updated_at' => ''];
    }
}
