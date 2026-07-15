<?php
declare(strict_types=1);

final class SocialCampaignPinterestBridge
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array{campaign:array,payload:array,mockup_ids:array<int,int>,batch_id:int}
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
        if (!in_array('pinterest', $channels, true)) {
            throw new RuntimeException('Pinterest is not selected for this campaign.');
        }

        $mockupIds = array_values(array_unique(array_filter(
            array_map('intval', (array)($payload['mockup_ids'] ?? [])),
            static fn (int $id): bool => $id > 0
        )));
        if (!$mockupIds || count($mockupIds) > 10) {
            throw new RuntimeException('A Pinterest campaign must contain between 1 and 10 mockups.');
        }

        return [
            'campaign' => $campaign,
            'payload' => $payload,
            'mockup_ids' => $mockupIds,
            'batch_id' => max(0, (int)($payload['pinterest']['batch_id'] ?? 0)),
        ];
    }

    public function attachBatch(
        int $campaignId,
        int $userId,
        int $batchId,
        string $purpose,
        string $destinationUrl
    ): void {
        $prepared = $this->preparation($campaignId, $userId);
        $payload = $prepared['payload'];
        $payload['pinterest'] = [
            'batch_id' => $batchId,
            'purpose' => $purpose,
            'destination_url' => $destinationUrl,
            'status' => 'review',
        ];
        $payload['channel_status'] = is_array($payload['channel_status'] ?? null)
            ? $payload['channel_status']
            : [];
        $payload['channel_status']['pinterest'] = 'review';
        $payload['phase'] = 'channel_review';
        $payload['next_step'] = 'review Pinterest boards, copy, crop and destination before approval';

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
            if (is_array($payload) && (int)($payload['pinterest']['batch_id'] ?? 0) === $batchId) {
                $campaign['payload'] = $payload;
                return $campaign;
            }
        }
        return null;
    }

    public function markBatchOutcome(int $batchId, int $userId, string $pinterestStatus): void
    {
        if (!in_array($pinterestStatus, ['review', 'needs_attention', 'published'], true)) {
            throw new InvalidArgumentException('Invalid Pinterest campaign status.');
        }

        $campaign = $this->linkedCampaign($batchId, $userId);
        if (!$campaign) {
            return;
        }

        $payload = (array)$campaign['payload'];
        $payload['pinterest'] = is_array($payload['pinterest'] ?? null) ? $payload['pinterest'] : [];
        $payload['pinterest']['status'] = $pinterestStatus;
        $payload['channel_status'] = is_array($payload['channel_status'] ?? null)
            ? $payload['channel_status']
            : [];
        $payload['channel_status']['pinterest'] = $pinterestStatus;

        $allPublished = $payload['channel_status'] !== [];
        foreach ($payload['channel_status'] as $status) {
            if ($status !== 'published') {
                $allPublished = false;
                break;
            }
        }

        $campaignStatus = $allPublished ? 'published' : 'in_progress';
        $payload['phase'] = $allPublished ? 'published' : 'channel_review';
        $payload['next_step'] = match ($pinterestStatus) {
            'published' => $allPublished ? 'campaign complete' : 'complete the remaining social channels',
            'needs_attention' => 'review failed Pinterest destinations and try again',
            default => 'review Pinterest boards, copy, crop and destination before approval',
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
}
