<?php
declare(strict_types=1);

final class BilingualEditorialGenerationWorker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?BilingualEditorialAdapterService $adapter = null
    ) {}

    public function process(int $jobId): array
    {
        $jobs = new BilingualEditorialJobService($this->pdo);
        $existing = $jobs->job($jobId);
        if ((string)$existing['status'] === 'completed') {
            return ['ok' => true, 'job' => $jobs->publicState($existing), 'idempotent' => true];
        }

        $job = $jobs->claim($jobId);
        if (!$job || (string)$job['status'] === 'completed') {
            return ['ok' => true, 'job' => $jobs->publicState($jobs->job($jobId)), 'claimed' => false];
        }

        try {
            $userId = (int)$job['user_id'];
            $entityType = (string)$job['entity_type'];
            $entityId = (int)$job['entity_id'];
            $action = (string)$job['action'];
            $payload = json_decode((string)$job['payload_json'], true);
            $payload = is_array($payload) ? $payload : [];
            $publishSpanish = !array_key_exists('publish_spanish', $payload) || !empty($payload['publish_spanish']);
            $adapter = $this->adapter ?? new BilingualEditorialAdapterService($this->pdo);
            $editorial = new BilingualEditorialService($this->pdo);

            if ($action === 'prepare' && $entityType === 'series') {
                $result = $adapter->prepareBilingualSeries(
                    $userId,
                    $entityId,
                    is_array($payload['current_spanish'] ?? null) ? $payload['current_spanish'] : null,
                    array_key_exists('private_memo', $payload) ? (string)$payload['private_memo'] : null,
                    $publishSpanish
                );
            } elseif ($action === 'prepare') {
                $spanish = $adapter->generateSpanishDraft(
                    $userId,
                    $entityType,
                    $entityId,
                    is_array($payload['current_spanish'] ?? null) ? $payload['current_spanish'] : null,
                    array_key_exists('private_memo', $payload) ? (string)$payload['private_memo'] : null
                );
                $spanishContent = (array)($spanish['content'] ?? []);
                $editorial->save(
                    $userId,
                    $entityType,
                    $entityId,
                    'es',
                    $spanishContent,
                    (string)($payload['private_memo'] ?? '')
                );
                // Existing single-entity actions preserve their publication
                // behavior; coordinated packages explicitly create drafts.
                if ($publishSpanish) {
                    $editorial->setSpanishPublished($userId, $entityType, $entityId, true);
                }
                $english = $adapter->adaptMissing($userId, $entityType, $entityId, 'es', 'en');
                $result = [
                    'spanish_content' => $spanishContent,
                    'english_content' => (array)($english['content'] ?? []),
                    'english_status' => (string)($english['english_status'] ?? 'current'),
                    'spanish_published' => $publishSpanish,
                ];
            } else {
                $english = $adapter->adaptMissing($userId, $entityType, $entityId, 'es', 'en');
                $result = [
                    'english_content' => (array)($english['content'] ?? []),
                    'english_status' => (string)($english['english_status'] ?? 'current'),
                ];
            }

            $jobs->complete($jobId, $result);
            $this->refreshEditorialPackages($jobId);
            return ['ok' => true, 'job' => $jobs->publicState($jobs->job($jobId))];
        } catch (Throwable $error) {
            $jobs->fail($jobId, $error->getMessage());
            $this->refreshEditorialPackages($jobId);
            return ['ok' => false, 'job' => $jobs->publicState($jobs->job($jobId)), 'error' => $error->getMessage()];
        }
    }

    private function refreshEditorialPackages(int $jobId): void
    {
        if (!class_exists(ArtworkEditorialPackageService::class)) {
            return;
        }
        try {
            (new ArtworkEditorialPackageService($this->pdo))->refreshPackagesForEditorialJob($jobId);
        } catch (Throwable $error) {
            error_log('Editorial package refresh failed for job ' . $jobId . ': ' . $error->getMessage());
        }
    }
}
