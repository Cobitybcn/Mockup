<?php
declare(strict_types=1);

final class VideoExportService
{
    public function __construct(
        private VideoStudioRepository $studio,
        private VideoJobRepository $jobs,
        private VideoTaskDispatcher $dispatcher,
        private VideoExportBuilder $builder
    ) {}

    public function start(int $userId, int $projectId, int $version, string $kind): array
    {
        $project = $this->studio->findProject($userId, $projectId);
        if (!$project) throw new OutOfBoundsException('Video project not found.');
        if ((int)$project['version'] !== $version) throw new DomainException('This project changed. Reload before exporting.');
        $kind = strtolower(trim($kind));
        if (!in_array($kind, ['preview','final'], true)) throw new InvalidArgumentException('Invalid export type.');
        $timeline = $this->jobs->exportTimeline($userId, $projectId);
        if ($timeline === []) throw new InvalidArgumentException('Add scenes before exporting.');
        foreach ($timeline as $scene) {
            if ($scene['generationId'] === null || $scene['outputPath'] === '') {
                throw new DomainException('Generate every scene before building the project montage.');
            }
        }
        $pending = $this->jobs->pendingExport($userId, $projectId);
        if ($pending) return $this->payload($userId, $projectId, (int)$pending['id']);

        $pdo = $this->studio->pdo();
        Database::beginWriteTransaction($pdo);
        try {
            $exportId = $this->jobs->createExport([
                'user_id' => $userId, 'project_id' => $projectId, 'aspect_ratio' => $project['aspectRatio'],
                'snapshot' => ['kind' => $kind, 'projectVersion' => $version, 'createdAt' => date('c'), 'scenes' => $timeline],
            ]);
            $this->studio->touchProject($userId, $projectId, $version);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        try {
            $task = $this->dispatcher->dispatchExport($exportId);
            $this->jobs->updateExportTask($exportId, $task);
        } catch (Throwable $e) {
            $this->jobs->markExportFailed($exportId, 'Could not enqueue export: ' . $e->getMessage());
            throw $e;
        }
        return $this->payload($userId, $projectId, $exportId);
    }

    public function status(int $userId, int $projectId): array
    {
        if (!$this->studio->findProject($userId, $projectId)) throw new OutOfBoundsException('Video project not found.');
        return $this->payload($userId, $projectId, null);
    }

    public function process(int $exportId): array
    {
        $export = $this->jobs->findExport($exportId);
        if (!$export) throw new OutOfBoundsException('Video export not found.');
        if (in_array($export['status'], ['succeeded','failed'], true)) return ['status' => $export['status'], 'exportId' => $exportId];
        try {
            if ($export['status'] === 'queued') {
                $export = $this->jobs->claimExport($exportId);
                if (!$export) {
                    $current = $this->jobs->findExport($exportId);
                    return ['status' => (string)($current['status'] ?? 'unknown'), 'exportId' => $exportId];
                }
            }
            if ($export['status'] !== 'processing') throw new RuntimeException('Export could not be claimed.');
            $result = $this->builder->build($export);
            $this->jobs->markExportSucceeded($exportId, $result['path'], $result['durationSeconds'], $result['bytes']);
            return ['status' => 'succeeded', 'exportId' => $exportId];
        } catch (Throwable $e) {
            $this->jobs->markExportFailed($exportId, $e->getMessage());
            throw $e;
        }
    }

    private function payload(int $userId, int $projectId, ?int $exportId): array
    {
        return (new VideoStudioService($this->studio))->studioPayload($userId, $projectId) + ['exportId' => $exportId];
    }
}
