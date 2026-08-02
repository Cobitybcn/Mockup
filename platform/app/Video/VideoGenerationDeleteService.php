<?php
declare(strict_types=1);

/** Permanently removes one generated clip and every live pointer to it. */
final class VideoGenerationDeleteService
{
    public function __construct(private VideoStudioRepository $repository) {}

    public function delete(int $userId, int $generationId): array
    {
        $pdo = $this->repository->pdo();
        $stmt = $pdo->prepare('SELECT * FROM video_generation_jobs WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$generationId, $userId]);
        $job = $stmt->fetch();
        if (!is_array($job)) throw new OutOfBoundsException('Video generated not found.');

        $paths = array_values(array_unique(array_filter([
            trim((string)($job['output_path'] ?? '')),
            trim((string)($job['thumbnail_path'] ?? '')),
        ])));
        $freedBytes = 0;
        foreach ($paths as $path) {
            $freedBytes += max(0, (int)(StorageService::objectSize($path) ?? 0));
            if (!StorageService::delete($path)) throw new RuntimeException('The stored video could not be deleted. Try again.');
        }

        $pdo->beginTransaction();
        try {
            $affectedProjects = [(int)$job['video_project_id'] => true];
            $projectStmt = $pdo->prepare("SELECT DISTINCT s.video_project_id FROM video_scene_references r
                INNER JOIN video_scenes s ON s.id=r.video_scene_id
                INNER JOIN video_projects p ON p.id=s.video_project_id
                WHERE r.source_type='generation_job' AND r.source_id=? AND p.user_id=?");
            $projectStmt->execute([$generationId, $userId]);
            foreach ($projectStmt->fetchAll(PDO::FETCH_COLUMN) as $id) $affectedProjects[(int)$id] = true;

            $pdo->prepare("DELETE FROM video_scene_references
                WHERE source_type='generation_job' AND source_id=? AND video_scene_id IN (
                    SELECT s.id FROM video_scenes s INNER JOIN video_projects p ON p.id=s.video_project_id WHERE p.user_id=?
                )")->execute([$generationId, $userId]);

            $timelineStmt = $pdo->prepare('SELECT id,timeline_json FROM video_projects WHERE user_id=? AND timeline_json IS NOT NULL');
            $timelineStmt->execute([$userId]);
            $updateTimeline = $pdo->prepare('UPDATE video_projects SET timeline_json=?,version=version+1,updated_at=? WHERE id=? AND user_id=?');
            $timelineUpdated = [];
            foreach ($timelineStmt->fetchAll() as $project) {
                $timeline = json_decode((string)$project['timeline_json'], true);
                if (!is_array($timeline)) continue;
                [$clean, $changed] = $this->withoutGeneration($timeline, $generationId);
                if (!$changed) continue;
                $encoded = $clean === null ? null : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $updateTimeline->execute([$encoded, date('c'), (int)$project['id'], $userId]);
                $affectedProjects[(int)$project['id']] = true;
                $timelineUpdated[(int)$project['id']] = true;
            }

            $deleted = $pdo->prepare('DELETE FROM video_generation_jobs WHERE id=? AND user_id=?');
            $deleted->execute([$generationId, $userId]);
            if ($deleted->rowCount() !== 1) throw new RuntimeException('The generated video could not be deleted.');

            if ($affectedProjects !== []) {
                $touch = $pdo->prepare('UPDATE video_projects SET version=version+1,updated_at=? WHERE id=? AND user_id=?');
                foreach (array_keys($affectedProjects) as $projectId) {
                    if (isset($timelineUpdated[$projectId])) continue;
                    $touch->execute([date('c'), $projectId, $userId]);
                }
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }

        return ['deletedGenerationId' => $generationId, 'freedBytes' => $freedBytes];
    }

    /** @return array{0:?array,1:bool} */
    private function withoutGeneration(array $timeline, int $generationId): array
    {
        if (isset($timeline['videoBlocks']) || isset($timeline['audioBlocks'])) {
            $beforeVideo = count((array)($timeline['videoBlocks'] ?? []));
            $beforeAudio = count((array)($timeline['audioBlocks'] ?? []));
            $keep = static fn(mixed $block): bool => !is_array($block)
                || (string)($block['sourceType'] ?? 'generation_job') !== 'generation_job'
                || (int)($block['sourceId'] ?? $block['generationId'] ?? 0) !== $generationId;
            $timeline['videoBlocks'] = array_values(array_filter((array)($timeline['videoBlocks'] ?? []), $keep));
            $timeline['audioBlocks'] = array_values(array_filter((array)($timeline['audioBlocks'] ?? []), $keep));
            $changed = $beforeVideo !== count($timeline['videoBlocks']) || $beforeAudio !== count($timeline['audioBlocks']);
            return [$timeline['videoBlocks'] === [] ? null : $timeline, $changed];
        }
        $before = count($timeline);
        $timeline = array_values(array_filter($timeline, static fn(mixed $block): bool => !is_array($block)
            || (int)($block['generationId'] ?? 0) !== $generationId));
        return [$timeline === [] ? null : $timeline, $before !== count($timeline)];
    }
}
