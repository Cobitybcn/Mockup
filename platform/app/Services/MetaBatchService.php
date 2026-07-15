<?php
declare(strict_types=1);

final class MetaBatchService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MetaSocialDraftService $drafts
    ) {}

    public function create(
        array $mockupIds,
        array $user,
        string $purpose,
        array $channels,
        string $destinationUrl = ''
    ): int {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $mockupIds),
            static fn (int $id): bool => $id > 0
        )));
        if (!$ids || count($ids) > 10) {
            throw new InvalidArgumentException('Select between 1 and 10 mockups for a Meta batch.');
        }
        $channels = $this->normalizeChannels($channels);
        if (!$channels) {
            throw new InvalidArgumentException('Select Facebook, Instagram, or both.');
        }

        $now = date('c');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO meta_batches (user_id,purpose,status,created_at,updated_at) VALUES (?,?,?,?,?)'
            )->execute([(int)$user['id'], $purpose, 'review', $now, $now]);
            $batchId = (int)$this->pdo->lastInsertId();
            $position = 0;
            foreach ($ids as $mockupId) {
                foreach ($channels as $channel) {
                    $draftId = $this->drafts->create(
                        $mockupId,
                        $user,
                        $channel,
                        $destinationUrl,
                        $purpose
                    );
                    $this->pdo->prepare(
                        'INSERT INTO meta_batch_items (batch_id,draft_id,position,status) VALUES (?,?,?,?)'
                    )->execute([$batchId, $draftId, $position++, 'draft']);
                }
            }
            $this->pdo->commit();
            return $batchId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function batch(int $batchId, int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM meta_batches WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$batchId, $userId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($batch)) {
            throw new RuntimeException('Meta batch not found.');
        }
        return $batch;
    }

    public function items(int $batchId, int $userId): array
    {
        $this->batch($batchId, $userId);
        $stmt = $this->pdo->prepare(
            'SELECT d.*,m.mockup_file,bi.position,bi.status item_status
             FROM meta_batch_items bi
             JOIN social_channel_drafts d ON d.id=bi.draft_id
             JOIN mockups m ON m.id=d.mockup_id
             WHERE bi.batch_id=? ORDER BY bi.position,bi.id'
        );
        $stmt->execute([$batchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function channels(int $batchId, int $userId): array
    {
        $channels = array_map(
            static fn (array $item): string => (string)$item['channel'],
            $this->items($batchId, $userId)
        );
        return $this->normalizeChannels($channels);
    }

    public function updateOutcome(int $batchId, int $userId): string
    {
        $items = $this->items($batchId, $userId);
        if (!$items) {
            throw new RuntimeException('Meta batch has no items.');
        }
        $published = 0;
        $needsAttention = false;
        foreach ($items as $item) {
            $status = (string)$item['status'];
            if ($status === 'published') {
                $published++;
            } elseif ($status === 'failed') {
                $needsAttention = true;
            } else {
                $needsAttention = true;
            }
            $this->pdo->prepare('UPDATE meta_batch_items SET status=? WHERE batch_id=? AND draft_id=?')
                ->execute([$status, $batchId, (int)$item['id']]);
        }
        $status = $published === count($items)
            ? 'published'
            : ($needsAttention ? 'needs_attention' : 'review');
        $this->pdo->prepare('UPDATE meta_batches SET status=?,updated_at=? WHERE id=? AND user_id=?')
            ->execute([$status, date('c'), $batchId, $userId]);
        return $status;
    }

    private function normalizeChannels(array $channels): array
    {
        $channels = array_values(array_unique(array_map(
            static fn ($channel): string => strtolower(trim((string)$channel)),
            $channels
        )));
        return array_values(array_intersect(['facebook', 'instagram'], $channels));
    }
}
