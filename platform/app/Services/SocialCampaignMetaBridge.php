<?php
declare(strict_types=1);

final class SocialCampaignMetaBridge
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array{campaign:array,payload:array,mockup_ids:array<int,int>,batch_id:int,batches:array,available_destinations:array<int,string>}
     */
    public function preparation(int $campaignId, int $userId): array
    {
        if ($campaignId <= 0 || !$this->tableExists()) {
            throw new RuntimeException('Social campaign not found.');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM social_campaigns WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$campaignId, $userId]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($campaign)) {
            throw new RuntimeException('Social campaign not found.');
        }
        $payload = json_decode((string)$campaign['payload_json'], true);
        if (!is_array($payload)) {
            throw new RuntimeException('The social campaign payload is invalid.');
        }
        $channels = array_values(array_unique(array_map('strval', (array)($payload['channels'] ?? []))));
        if (!in_array('meta_media', $channels, true)) {
            throw new RuntimeException('Meta Media is not selected for this campaign.');
        }
        $mockupIds = array_values(array_unique(array_filter(
            array_map('intval', (array)($payload['mockup_ids'] ?? [])),
            static fn (int $id): bool => $id > 0
        )));
        if (!$mockupIds || count($mockupIds) > 10) {
            throw new RuntimeException('A Meta campaign must contain between 1 and 10 mockups.');
        }
        $batches = $this->normalizeBatches($payload);
        $usedDestinations = [];
        foreach ($batches as $batch) {
            $usedDestinations = array_merge($usedDestinations, $batch['destinations']);
        }
        $usedDestinations = array_values(array_unique($usedDestinations));
        return [
            'campaign' => $campaign,
            'payload' => $payload,
            'mockup_ids' => $mockupIds,
            'batch_id' => max(0, (int)($payload['meta']['batch_id'] ?? 0)),
            'batches' => $batches,
            'available_destinations' => array_values(array_diff(['facebook', 'instagram'], $usedDestinations)),
        ];
    }

    public function attachBatch(
        int $campaignId,
        int $userId,
        int $batchId,
        string $purpose,
        array $channels
    ): void {
        $prepared = $this->preparation($campaignId, $userId);
        $channels = array_values(array_intersect(['facebook', 'instagram'], array_unique(array_map('strval', $channels))));
        if (!$channels) {
            throw new InvalidArgumentException('Select a Meta destination.');
        }
        $existingBatches = $prepared['batches'];
        $usedDestinations = [];
        foreach ($existingBatches as $existingBatch) {
            $usedDestinations = array_merge($usedDestinations, $existingBatch['destinations']);
        }
        $duplicates = array_values(array_intersect($channels, array_unique($usedDestinations)));
        if ($duplicates) {
            throw new InvalidArgumentException(ucfirst(implode(' and ', $duplicates)) . ' already has a publication batch for this campaign.');
        }
        $payload = $prepared['payload'];
        $existingBatches[] = [
            'batch_id' => $batchId,
            'purpose' => $purpose,
            'destinations' => $channels,
            'status' => 'review',
        ];
        $payload['meta'] = $this->metaSummary($existingBatches);
        $payload['channel_status'] = is_array($payload['channel_status'] ?? null)
            ? $payload['channel_status']
            : [];
        $payload['channel_status']['meta_media'] = 'review';
        $payload['phase'] = 'channel_review';
        $payload['next_step'] = 'review Facebook and Instagram copy, crop, destination and identity before approval';
        $this->pdo->prepare("UPDATE social_campaigns SET status='in_progress',payload_json=?,updated_at=? WHERE id=? AND user_id=?")
            ->execute([
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                date('c'),
                $campaignId,
                $userId,
            ]);
    }

    public function linkedCampaign(int $batchId, int $userId): ?array
    {
        if ($batchId <= 0 || !$this->tableExists()) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM social_campaigns WHERE user_id=? ORDER BY id DESC LIMIT 200');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $campaign) {
            $payload = json_decode((string)$campaign['payload_json'], true);
            $linked = false;
            if (is_array($payload)) {
                foreach ($this->normalizeBatches($payload) as $batch) {
                    if ((int)$batch['batch_id'] === $batchId) {
                        $linked = true;
                        break;
                    }
                }
            }
            if ($linked) {
                $campaign['payload'] = $payload;
                return $campaign;
            }
        }
        return null;
    }

    public function markBatchOutcome(int $batchId, int $userId, string $metaStatus): void
    {
        if (!in_array($metaStatus, ['review', 'needs_attention', 'published'], true)) {
            throw new InvalidArgumentException('Invalid Meta campaign status.');
        }
        $campaign = $this->linkedCampaign($batchId, $userId);
        if (!$campaign) {
            return;
        }
        $payload = (array)$campaign['payload'];
        $batches = $this->normalizeBatches($payload);
        foreach ($batches as &$batch) {
            if ((int)$batch['batch_id'] === $batchId) {
                $batch['status'] = $metaStatus;
                break;
            }
        }
        unset($batch);
        $payload['meta'] = $this->metaSummary($batches);
        $payload['channel_status'] = is_array($payload['channel_status'] ?? null)
            ? $payload['channel_status']
            : [];
        $payload['channel_status']['meta_media'] = (string)$payload['meta']['status'];

        $allPublished = $payload['channel_status'] !== [];
        foreach ($payload['channel_status'] as $status) {
            if ($status !== 'published') {
                $allPublished = false;
                break;
            }
        }
        $campaignStatus = $allPublished ? 'published' : 'in_progress';
        $payload['phase'] = $allPublished ? 'published' : 'channel_review';
        $payload['next_step'] = match ((string)$payload['meta']['status']) {
            'published' => $allPublished ? 'campaign complete' : 'complete the remaining social channels',
            'needs_attention' => 'review failed Facebook or Instagram items before trying again',
            default => 'review Facebook and Instagram content before approval',
        };
        $this->pdo->prepare('UPDATE social_campaigns SET status=?,payload_json=?,updated_at=? WHERE id=? AND user_id=?')
            ->execute([
                $campaignStatus,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                date('c'),
                (int)$campaign['id'],
                $userId,
            ]);
    }

    private function tableExists(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM social_campaigns LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizeBatches(array $payload): array
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $source = is_array($meta['batches'] ?? null) ? $meta['batches'] : [];
        if (!$source && (int)($meta['batch_id'] ?? 0) > 0) {
            $source[] = $meta;
        }
        $normalized = [];
        foreach ($source as $batch) {
            if (!is_array($batch) || (int)($batch['batch_id'] ?? 0) <= 0) {
                continue;
            }
            $destinations = array_values(array_intersect(
                ['facebook', 'instagram'],
                array_unique(array_map(static fn ($value): string => strtolower(trim((string)$value)), (array)($batch['destinations'] ?? [])))
            ));
            if (!$destinations) {
                continue;
            }
            $normalized[] = [
                'batch_id' => (int)$batch['batch_id'],
                'purpose' => (string)($batch['purpose'] ?? 'artist'),
                'destinations' => $destinations,
                'status' => (string)($batch['status'] ?? 'review'),
            ];
        }
        return $normalized;
    }

    private function metaSummary(array $batches): array
    {
        $last = $batches ? $batches[array_key_last($batches)] : [];
        $destinations = [];
        $status = 'published';
        foreach ($batches as $batch) {
            $destinations = array_merge($destinations, (array)$batch['destinations']);
            if ((string)$batch['status'] === 'needs_attention') {
                $status = 'needs_attention';
            } elseif ((string)$batch['status'] !== 'published' && $status !== 'needs_attention') {
                $status = 'review';
            }
        }
        if (!$batches) {
            $status = 'draft';
        }
        return [
            'batch_id' => (int)($last['batch_id'] ?? 0),
            'purpose' => (string)($last['purpose'] ?? 'artist'),
            'destinations' => array_values(array_unique($destinations)),
            'status' => $status,
            'batches' => array_values($batches),
        ];
    }
}
