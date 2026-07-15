<?php
declare(strict_types=1);

final class VideoGenerationService
{
    private const DURATIONS = [4,6,8];
    private const ASPECTS = ['9:16','16:9'];

    public function __construct(
        private VideoStudioRepository $studio,
        private VideoJobRepository $jobs,
        private VideoTaskDispatcher $dispatcher,
        private VideoMediaStorage $storage
    ) {}

    public function start(int $userId, int $sceneId, int $version): array
    {
        if (!ProviderSettings::allowRealApi()) throw new DomainException('Real API generation is disabled. Enable it explicitly in API Settings before generating video.');
        $context = $this->jobs->generationContext($userId, $sceneId);
        if (!$context) throw new OutOfBoundsException('Scene not found.');
        if ((int)$context['project']['version'] !== $version) throw new DomainException('This project changed. Reload it before generating.');
        $scene = $context['scene'];
        $project = $context['project'];
        $reference = $context['reference'];
        $references = is_array($context['references'] ?? null) ? $context['references'] : [];
        $mode = (string)$scene['generationMode'];
        if (!in_array($mode, ['image_to_video','first_last_frame'], true)) throw new DomainException('This Video generation mode is not connected yet.');
        if ($mode === 'image_to_video' && (!$reference || !in_array($reference['sourceType'], ['mockup','artwork'], true))) {
            throw new InvalidArgumentException('Add a main image reference before generating.');
        }
        if ($mode === 'first_last_frame') {
            foreach (['start_frame' => 'Start Frame','end_frame' => 'End Frame'] as $role => $label) {
                if (!isset($references[$role]) || !in_array((string)$references[$role]['sourceType'], ['mockup','artwork'], true)) {
                    throw new InvalidArgumentException($label . ' requires an image before generating.');
                }
            }
        }
        $duration = (int)round((float)$scene['durationSeconds']);
        if (!in_array($duration, self::DURATIONS, true)) throw new InvalidArgumentException('Veo 3.1 scene duration must be 4, 6 or 8 seconds.');
        if (!in_array($project['aspectRatio'], self::ASPECTS, true)) throw new InvalidArgumentException('Veo 3.1 generation currently supports 9:16 or 16:9 in this studio.');

        $associationReference = $reference;
        if ($mode === 'first_last_frame') {
            $startAssociation = $this->jobs->associationForReference($userId, $references['start_frame']);
            $endAssociation = $this->jobs->associationForReference($userId, $references['end_frame']);
            if ($startAssociation['artworkId'] !== null && $endAssociation['artworkId'] !== null
                && $startAssociation['artworkId'] !== $endAssociation['artworkId']) {
                throw new InvalidArgumentException('Start Frame and End Frame must belong to the same artwork.');
            }
            $association = $startAssociation;
            $associationReference = $references['start_frame'];
        } else {
            $association = $this->jobs->associationForReference($userId, $associationReference);
        }
        $artworkId = $association['artworkId'] ?? $project['artworkId'] ?? null;
        $seriesId = $association['seriesId'] ?? $project['seriesId'] ?? null;

        $provider = new VertexVeoProvider();
        $prompt = VideoPromptComposer::compose($project, $scene);
        $snapshot = [
            'projectId' => $project['id'], 'sceneId' => $scene['id'], 'mode' => $mode,
            'durationSeconds' => $duration, 'aspectRatio' => $project['aspectRatio'], 'resolution' => app_env('VIDEO_VEO_RESOLUTION', '720p'),
            'artworkId' => $artworkId, 'seriesId' => $seriesId,
        ];
        if ($mode === 'first_last_frame') {
            $snapshot['startReferenceId'] = (int)$references['start_frame']['id'];
            $snapshot['startReferenceFile'] = basename((string)$references['start_frame']['filePath']);
            $snapshot['endReferenceId'] = (int)$references['end_frame']['id'];
            $snapshot['endReferenceFile'] = basename((string)$references['end_frame']['filePath']);
        } else {
            $snapshot['referenceId'] = (int)$reference['id'];
            $snapshot['referenceFile'] = basename((string)$reference['filePath']);
            $snapshot['referenceType'] = (string)$reference['sourceType'];
        }
        $inputHash = hash('sha256', json_encode([$snapshot,$prompt,$provider->model()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $pending = $this->jobs->pendingGeneration($userId, $sceneId, $inputHash);
        if ($pending) return $this->payload($userId, (int)$project['id'], $sceneId, (int)$pending['id']);

        $pdo = $this->studio->pdo();
        Database::beginWriteTransaction($pdo);
        try {
            $jobId = $this->jobs->createGeneration([
                'user_id' => $userId, 'project_id' => $project['id'], 'scene_id' => $sceneId,
                'artwork_id' => $artworkId, 'series_id' => $seriesId,
                'provider' => $provider->name(), 'model' => $provider->model(), 'mode' => $scene['generationMode'],
                'idempotency_key' => bin2hex(random_bytes(32)), 'scene_version' => $version, 'input_hash' => $inputHash,
                'duration_seconds' => $duration, 'aspect_ratio' => $project['aspectRatio'], 'prompt' => $prompt,
                'request_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ]);
            $this->studio->touchProject($userId, (int)$project['id'], $version);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        try {
            $task = $this->dispatcher->dispatchGeneration($jobId);
            $this->jobs->updateGenerationTask($jobId, $task);
        } catch (Throwable $e) {
            $this->jobs->markGenerationFailed($jobId, 'Could not enqueue generation: ' . $e->getMessage());
            throw $e;
        }
        return $this->payload($userId, (int)$project['id'], $sceneId, $jobId);
    }

    public function status(int $userId, int $projectId): array
    {
        $project = $this->studio->findProject($userId, $projectId);
        if (!$project) throw new OutOfBoundsException('Video project not found.');
        $latest = $this->jobs->latestGenerationForProject($userId, $projectId);
        return $this->payload($userId, $projectId, null, $latest ? (int)$latest['id'] : null);
    }

    public function process(int $jobId): array
    {
        $job = $this->jobs->findGeneration($jobId);
        if (!$job) throw new OutOfBoundsException('Video generation job not found.');
        if (in_array($job['status'], ['succeeded','failed'], true)) return ['status' => $job['status'], 'jobId' => $jobId];

        try {
            if ($job['status'] === 'queued') {
                $job = $this->jobs->claimGeneration($jobId);
                if (!$job) {
                    $current = $this->jobs->findGeneration($jobId);
                    return ['status' => (string)($current['status'] ?? 'unknown'), 'jobId' => $jobId];
                }
                $request = json_decode((string)$job['request_json'], true);
                if (!is_array($request)) throw new RuntimeException('Generation request snapshot is invalid.');
                $provider = $this->providerForJob($job);
                $storageUri = '';
                $bucket = app_env('GCS_BUCKET_NAME', '');
                if ($bucket !== '') $storageUri = sprintf('gs://%s/video/vertex-output/%d/%d', $bucket, (int)$job['user_id'], $jobId);
                $common = [
                    'prompt' => (string)$job['prompt_text'],
                    'durationSeconds' => (int)$job['requested_duration_seconds'],
                    'aspectRatio' => (string)$job['aspect_ratio'],
                    'resolution' => (string)($request['resolution'] ?? '720p'),
                    'storageUri' => $storageUri,
                ];
                if ((string)$job['generation_mode'] === 'first_last_frame') {
                    $start = null;
                    $end = null;
                    try {
                        $start = $this->storage->prepareReferenceImage((string)($request['startReferenceFile'] ?? ''));
                        $end = $this->storage->prepareReferenceImage((string)($request['endReferenceFile'] ?? ''));
                        $submission = $provider->generateFromFrames($common + [
                            'startImagePath' => $start['path'], 'startMimeType' => $start['mimeType'],
                            'endImagePath' => $end['path'], 'endMimeType' => $end['mimeType'],
                        ]);
                    } finally {
                        if (is_array($start) && !empty($start['temporary'])) @unlink((string)$start['path']);
                        if (is_array($end) && !empty($end['temporary'])) @unlink((string)$end['path']);
                    }
                } else {
                    $image = $this->storage->prepareReferenceImage((string)($request['referenceFile'] ?? ''));
                    try {
                        $submission = $provider->generateFromImage($common + [
                            'imagePath' => $image['path'], 'mimeType' => $image['mimeType'],
                        ]);
                    } finally {
                        if (!empty($image['temporary'])) @unlink((string)$image['path']);
                    }
                }
                $next = date('c', time() + 15);
                $this->jobs->markGenerationPolling($jobId, $submission['jobId'], $submission['response'], $next);
                $this->schedulePoll($jobId);
                return ['status' => 'polling', 'jobId' => $jobId];
            }

            if (in_array($job['status'], ['polling','processing'], true)) {
                $provider = $this->providerForJob($job);
                $result = $provider->getJobStatus((string)$job['external_job_id']);
                if ($result['status'] === 'processing') {
                    $next = date('c', time() + 15);
                    $this->jobs->markGenerationProcessing($jobId, $result['response'], $next);
                    $this->schedulePoll($jobId);
                    return ['status' => 'polling', 'jobId' => $jobId];
                }
                if ($result['status'] === 'failed') {
                    $this->jobs->markGenerationFailed($jobId, (string)($result['error'] ?? 'Veo generation failed.'), $result['response']);
                    return ['status' => 'failed', 'jobId' => $jobId];
                }
                $stored = $this->storage->storeGeneratedOutput((array)$result['output'], $job);
                $this->jobs->markGenerationSucceeded($jobId, $stored['path'], $stored['thumbnailPath'], $stored['durationSeconds'], $result['response']);
                return ['status' => 'succeeded', 'jobId' => $jobId];
            }
            throw new RuntimeException('Generation job is in an unsupported state.');
        } catch (Throwable $e) {
            if ((int)$e->getCode() !== 503) $this->jobs->markGenerationFailed($jobId, $e->getMessage());
            throw $e;
        }
    }

    private function providerForJob(array $job): VideoGenerationProvider
    {
        if ($job['provider'] !== 'vertex_veo') throw new RuntimeException('Unsupported video provider.');
        return new VertexVeoProvider((string)$job['model']);
    }

    private function schedulePoll(int $jobId): void
    {
        try {
            $task = $this->dispatcher->dispatchGeneration($jobId, 15);
            $this->jobs->updateGenerationTask($jobId, $task);
        } catch (Throwable $e) {
            Logger::log('Could not schedule Veo poll for job ' . $jobId . ': ' . $e->getMessage(), 'error');
            throw new RuntimeException('Could not schedule the next Veo status check.', 503, $e);
        }
    }

    private function payload(int $userId, int $projectId, ?int $sceneId, ?int $jobId): array
    {
        $service = new VideoStudioService($this->studio);
        return $service->studioPayload($userId, $projectId) + ['selectedSceneId' => $sceneId, 'generationJobId' => $jobId];
    }
}
