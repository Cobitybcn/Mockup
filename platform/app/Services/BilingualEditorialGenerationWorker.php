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
            $adapter = $this->adapter ?? new BilingualEditorialAdapterService($this->pdo);
            $editorial = new BilingualEditorialService($this->pdo);

            if ($action === 'prepare' && $entityType === 'series') {
                $result = $adapter->prepareBilingualSeries(
                    $userId,
                    $entityId,
                    is_array($payload['current_spanish'] ?? null) ? $payload['current_spanish'] : null,
                    array_key_exists('private_memo', $payload) ? (string)$payload['private_memo'] : null
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
                // The Spanish master reaches the website before the English
                // phase begins. A later adaptation failure cannot hide it.
                $editorial->setSpanishPublished($userId, $entityType, $entityId, true);
                $english = $adapter->adaptMissing($userId, $entityType, $entityId, 'es', 'en');
                $result = [
                    'spanish_content' => $spanishContent,
                    'english_content' => (array)($english['content'] ?? []),
                    'english_status' => (string)($english['english_status'] ?? 'current'),
                    'spanish_published' => true,
                ];
            } else {
                $english = $adapter->adaptMissing($userId, $entityType, $entityId, 'es', 'en');
                $result = [
                    'english_content' => (array)($english['content'] ?? []),
                    'english_status' => (string)($english['english_status'] ?? 'current'),
                ];
            }

            $jobs->complete($jobId, $result);
            return ['ok' => true, 'job' => $jobs->publicState($jobs->job($jobId))];
        } catch (Throwable $error) {
            $jobs->fail($jobId, $error->getMessage());
            return ['ok' => false, 'job' => $jobs->publicState($jobs->job($jobId)), 'error' => $error->getMessage()];
        }
    }
}
