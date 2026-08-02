<?php
declare(strict_types=1);

/**
 * The cut list of a montage: one block per piece of video on the line, each
 * pointing at a generated clip with its own in and out points.
 *
 * Splitting turns one block into two that share a clip; duplicating adds
 * another block over the same one. Nothing is copied on disk — a block is a
 * pair of times, and the clip it names stays where it was generated.
 *
 * A project with no stored list still means every sequence, whole and in
 * order, so cutting is something the artist opts into rather than a state the
 * montage starts in.
 */
final class VideoTimelineService
{
    /** Below this a block is too short to be seen, let alone to carry a cut. */
    private const MIN_BLOCK_SECONDS = 0.2;

    public function __construct(private VideoStudioRepository $repository, private VideoJobRepository $jobs) {}

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(int $userId, int $projectId, int $version, array $input): array
    {
        $project = $this->repository->findProject($userId, $projectId);
        if (!$project) throw new OutOfBoundsException('Video project not found.');

        // Clearing the cut is not the same as an empty montage: it hands the
        // line back to the sequences as they were generated.
        if (!empty($input['reset'])) {
            $this->write($userId, $projectId, $version, null);
            return $this->payload($userId, $projectId);
        }

        $blocks = $input['blocks'] ?? null;
        if (!is_array($blocks)) throw new InvalidArgumentException('The timeline needs a list of blocks.');

        $lengths = $this->clipLengths($userId, $projectId);
        $clean = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) continue;
            $generationId = (int)($block['generationId'] ?? 0);
            if (!array_key_exists($generationId, $lengths)) continue;

            $length = $lengths[$generationId];
            $start = max(0.0, (float)($block['startSeconds'] ?? 0));
            $end = (float)($block['endSeconds'] ?? 0);
            if ($end <= 0 || ($length > 0 && $end > $length)) $end = $length;
            if ($end - $start < self::MIN_BLOCK_SECONDS) continue;

            $clean[] = [
                'generationId' => $generationId,
                'startSeconds' => round($start, 3),
                'endSeconds' => round($end, 3),
            ];
        }
        if ($clean === []) throw new DomainException('A montage needs at least one block of video.');

        $this->write($userId, $projectId, $version, $clean);
        return $this->payload($userId, $projectId);
    }

    /**
     * How long each generated clip actually runs, so a block can never claim
     * more of a clip than exists.
     *
     * @return array<int,float>
     */
    private function clipLengths(int $userId, int $projectId): array
    {
        $lengths = [];
        foreach ($this->jobs->exportTimeline($userId, $projectId) as $scene) {
            if ($scene['generationId'] === null || $scene['outputPath'] === '') continue;
            $lengths[(int)$scene['generationId']] = (float)($scene['generatedDurationSeconds'] ?: $scene['durationSeconds']);
        }
        return $lengths;
    }

    /** @param list<array<string,mixed>>|null $blocks */
    private function write(int $userId, int $projectId, int $version, ?array $blocks): void
    {
        $stored = $blocks === null
            ? null
            : json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!$this->repository->updateProject($userId, $projectId, $version, ['timeline_json' => $stored])) {
            throw new DomainException('This project changed in another session. Reload before saving.');
        }
    }

    /** @return array<string,mixed> */
    private function payload(int $userId, int $projectId): array
    {
        return (new VideoStudioService($this->repository))->studioPayload($userId, $projectId);
    }
}
