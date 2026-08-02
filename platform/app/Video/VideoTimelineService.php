<?php
declare(strict_types=1);

/**
 * Canonical V2 timeline. Video tracks are numbered 1..3 and audio tracks 1..2.
 * Old projects keep their linear cut list; it is promoted to V1 on first edit.
 */
final class VideoTimelineService
{
    private const MIN_BLOCK_SECONDS = 0.2;

    public function __construct(private VideoStudioRepository $repository, private VideoJobRepository $jobs) {}

    /** @param array<string,mixed> $input */
    public function update(int $userId, int $projectId, int $version, array $input): array
    {
        $project = $this->repository->findProject($userId, $projectId);
        if (!$project) throw new OutOfBoundsException('Video project not found.');
        if (!empty($input['reset'])) {
            $this->write($userId, $projectId, $version, null);
            return $this->payload($userId, $projectId);
        }

        if (isset($input['videoBlocks']) || isset($input['audioBlocks'])) {
            $timeline = $this->cleanTimeline($userId, $projectId, $input);
        } else {
            $blocks = $input['blocks'] ?? null;
            if (!is_array($blocks)) throw new InvalidArgumentException('The timeline needs video blocks.');
            $timeline = $this->legacyTimeline($userId, $projectId, $blocks);
        }
        if ($timeline['videoBlocks'] === []) throw new DomainException('A montage needs at least one video clip.');
        $this->write($userId, $projectId, $version, $timeline);
        return $this->payload($userId, $projectId);
    }

    /** @return array{schemaVersion:int,videoBlocks:list<array<string,mixed>>,audioBlocks:list<array<string,mixed>>} */
    private function cleanTimeline(int $userId, int $projectId, array $input): array
    {
        $sources = $this->sources($userId, $projectId);
        $video = [];
        foreach ((array)($input['videoBlocks'] ?? []) as $index => $block) {
            if (!is_array($block)) continue;
            $clean = $this->cleanBlock($block, $sources, 'video', $index);
            if ($clean !== null) $video[] = $clean;
        }
        $audio = [];
        foreach ((array)($input['audioBlocks'] ?? []) as $index => $block) {
            if (!is_array($block)) continue;
            $clean = $this->cleanBlock($block, $sources, 'audio', $index);
            if ($clean !== null && !empty($sources[$clean['sourceType'] . ':' . $clean['sourceId']]['hasAudio'])) $audio[] = $clean;
        }
        return ['schemaVersion' => 2, 'videoBlocks' => $video, 'audioBlocks' => $audio];
    }

    /** @return array<string,mixed>|null */
    private function cleanBlock(array $block, array $sources, string $kind, int $index): ?array
    {
        $sourceType = (string)($block['sourceType'] ?? 'generation_job');
        $sourceId = (int)($block['sourceId'] ?? $block['generationId'] ?? 0);
        $source = $sources[$sourceType . ':' . $sourceId] ?? null;
        if (!is_array($source)) return null;
        $length = (float)$source['durationSeconds'];
        $start = max(0.0, (float)($block['sourceStart'] ?? $block['startSeconds'] ?? 0));
        $end = (float)($block['sourceEnd'] ?? $block['endSeconds'] ?? 0);
        if ($end <= 0 || $end > $length) $end = $length;
        if ($end - $start < self::MIN_BLOCK_SECONDS) return null;
        $trackMax = $kind === 'video' ? 3 : 2;
        $id = trim((string)($block['id'] ?? ''));
        if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $id)) $id = $kind[0] . '-' . $sourceId . '-' . $index;
        return [
            'id' => $id,
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
            'track' => max(1, min($trackMax, (int)($block['track'] ?? 1))),
            'timelineStart' => round(max(0.0, (float)($block['timelineStart'] ?? 0)), 3),
            'sourceStart' => round($start, 3),
            'sourceEnd' => round($end, 3),
            'enabled' => !array_key_exists('enabled', $block) || (bool)$block['enabled'],
            'volume' => $kind === 'audio' ? round(max(0.0, min(4.0, (float)($block['volume'] ?? 1))), 3) : 1.0,
            'linkGroup' => mb_substr(trim((string)($block['linkGroup'] ?? '')), 0, 80),
        ];
    }

    private function legacyTimeline(int $userId, int $projectId, array $blocks): array
    {
        $sources = $this->sources($userId, $projectId);
        $video = [];
        $at = 0.0;
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) continue;
            $block['sourceType'] = 'generation_job';
            $block['sourceId'] = (int)($block['generationId'] ?? 0);
            $block['timelineStart'] = $at;
            $clean = $this->cleanBlock($block, $sources, 'video', $index);
            if ($clean === null) continue;
            $video[] = $clean;
            $at += $clean['sourceEnd'] - $clean['sourceStart'];
        }
        return ['schemaVersion' => 2, 'videoBlocks' => $video, 'audioBlocks' => []];
    }

    /** @return array<string,array<string,mixed>> */
    private function sources(int $userId, int $projectId): array
    {
        $sources = [];
        foreach ($this->jobs->exportTimeline($userId, $projectId) as $scene) {
            if ($scene['generationId'] === null || $scene['outputPath'] === '') continue;
            $id = (int)$scene['generationId'];
            $sources['generation_job:' . $id] = [
                'durationSeconds' => (float)($scene['generatedDurationSeconds'] ?: $scene['durationSeconds']),
                'hasAudio' => false,
            ];
        }
        $stmt = $this->repository->pdo()->prepare("SELECT id,duration_seconds,has_audio FROM video_reference_assets
            WHERE user_id=? AND media_type='video'");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $id = (int)$row['id'];
            $sources['reference_asset:' . $id] = [
                'durationSeconds' => (float)($row['duration_seconds'] ?? 0),
                'hasAudio' => (bool)($row['has_audio'] ?? false),
            ];
        }
        return $sources;
    }

    private function write(int $userId, int $projectId, int $version, ?array $timeline): void
    {
        $stored = $timeline === null ? null : json_encode($timeline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!$this->repository->updateProject($userId, $projectId, $version, ['timeline_json' => $stored])) {
            throw new DomainException('This project changed in another session. Reload before saving.');
        }
    }

    private function payload(int $userId, int $projectId): array
    {
        return (new VideoStudioService($this->repository))->studioPayload($userId, $projectId);
    }
}
