<?php
declare(strict_types=1);

final class VideoGenerationService
{
    private const ASPECTS = ['9:16','16:9'];

    public function __construct(
        private VideoStudioRepository $studio,
        private VideoJobRepository $jobs,
        private VideoTaskDispatcher $dispatcher,
        private VideoMediaStorage $storage
    ) {}

    public function start(int $userId, int $sceneId, int $version, string $intent = 'generate', string $adjustPrompt = ''): array
    {
        if (!ProviderSettings::allowRealApi()) throw new DomainException('Real API generation is disabled. Enable it explicitly in API Settings before generating video.');
        $context = $this->jobs->generationContext($userId, $sceneId);
        if (!$context) throw new OutOfBoundsException('Scene not found.');
        if ((int)$context['project']['version'] !== $version) throw new DomainException('This project changed. Reload it before generating.');
        $scene = $context['scene'];
        $project = $context['project'];
        $reference = $context['reference'];
        $references = is_array($context['references'] ?? null) ? $context['references'] : [];
        $referenceList = is_array($context['referenceList'] ?? null) ? $context['referenceList'] : [];
        $provider = VideoProviderRegistry::make();
        $intent = strtolower(trim($intent));
        if ($intent === 'adjust') {
            return $this->startAdjustment($userId, $sceneId, $version, $context, $provider, $adjustPrompt);
        }
        if ($intent !== 'generate') throw new InvalidArgumentException('Unknown video generation intent.');

        $startCandidate = isset($references['start_frame']) ? $references['start_frame'] : null;
        $sourceVideo = isset($references['source_video']) ? $references['source_video'] : null;
        if ($sourceVideo === null && is_array($startCandidate)
            && ($startCandidate['metadata']['mediaType'] ?? '') === 'video'
            && ($startCandidate['sourceType'] ?? '') === 'reference_asset') {
            $sourceVideo = $startCandidate;
        }
        if ($sourceVideo === null) {
            foreach ($referenceList as $candidate) {
                if (($candidate['metadata']['mediaType'] ?? '') === 'video' && ($candidate['sourceType'] ?? '') === 'reference_asset') {
                    $sourceVideo = $candidate;
                    break;
                }
            }
        }
        $explicitStart = $sourceVideo === $startCandidate ? null : $startCandidate;
        $mode = (string)$scene['generationMode'];
        // Legacy projects can contain modes that Omni does not support. Their
        // media remains available as optional references without rewriting history.
        if ($provider->name() === VideoProviderRegistry::OMNI && in_array($mode, ['first_last_frame','extend_video'], true)) {
            $mode = 'image_to_video';
        }
        if ($sourceVideo !== null) {
            if ($provider->name() !== VideoProviderRegistry::OMNI) throw new DomainException('El video base para editar requiere Gemini Omni.');
            $mode = 'edit';
        }
        if ($mode !== 'edit' && !in_array($mode, VideoProviderRegistry::generationModes($provider->name()), true)) {
            throw new DomainException($provider->model() . ' does not support this generation mode in Video Lab.');
        }
        if ($mode === 'first_last_frame') {
            foreach (['start_frame' => 'Start Frame','end_frame' => 'End Frame'] as $role => $label) {
                if (!isset($references[$role]) || !in_array((string)$references[$role]['sourceType'], ['mockup','artwork'], true)) {
                    throw new InvalidArgumentException($label . ' requires an image before generating.');
                }
            }
        }
        $duration = (int)round((float)$scene['durationSeconds']);
        if (!in_array($duration, VideoProviderRegistry::durations($provider->name()), true)) {
            throw new InvalidArgumentException($provider->model() . ' does not support the selected scene duration.');
        }
        if (!in_array($project['aspectRatio'], self::ASPECTS, true)) {
            throw new InvalidArgumentException($provider->model() . ' currently supports 9:16 or 16:9 in this studio.');
        }

        $associationReference = $sourceVideo ?? $reference;
        if ($mode === 'first_last_frame') {
            $startAssociation = $this->jobs->associationForReference($userId, $references['start_frame']);
            $endAssociation = $this->jobs->associationForReference($userId, $references['end_frame']);
            if (!self::sameUnifiedArtwork($startAssociation, $endAssociation)) {
                throw new InvalidArgumentException('Start Frame and End Frame must belong to the same artwork.');
            }
            $association = $startAssociation;
            $associationReference = $references['start_frame'];
        } elseif ($associationReference !== null) {
            $association = $this->jobs->associationForReference($userId, $associationReference);
        } else {
            $association = ['artworkId' => null, 'artworkGroupId' => null, 'canonicalArtworkId' => null, 'seriesId' => null];
        }
        $artworkId = $association['artworkId'] ?? $project['artworkId'] ?? null;
        $seriesId = $association['seriesId'] ?? $project['seriesId'] ?? null;

        $previousGeneration = $explicitStart === null && $sourceVideo === null ? $this->jobs->previousSuccessfulGeneration($userId, $sceneId) : null;
        $hasContinuity = is_array($previousGeneration) && trim((string)($previousGeneration['output_path'] ?? '')) !== '';
        $imageInputCount = count(array_filter($referenceList, static fn(array $item): bool => ($item['metadata']['mediaType'] ?? 'image') === 'image'));
        if (is_array($explicitStart) && ($explicitStart['metadata']['mediaType'] ?? '') === 'video') $imageInputCount++;
        if ($hasContinuity) $imageInputCount++;
        if ($imageInputCount > VideoReferencePolicy::MAX_IMAGES) {
            throw new InvalidArgumentException('Omni admite un máximo de 10 imágenes por solicitud, incluida la continuidad automática.');
        }
        $prompt = VideoPromptComposer::compose($project, $scene, $hasContinuity || $explicitStart !== null);
        $snapshot = [
            'projectId' => $project['id'], 'sceneId' => $scene['id'], 'intent' => $sourceVideo !== null ? 'edit_uploaded_video' : 'generate', 'mode' => $mode,
            'durationSeconds' => $duration, 'aspectRatio' => $project['aspectRatio'], 'resolution' => app_env('VIDEO_VEO_RESOLUTION', '720p'),
            'artworkId' => $artworkId, 'seriesId' => $seriesId,
            'references' => array_map(static function (array $item): array {
                return [
                    'id' => (int)$item['id'],
                    'role' => (string)$item['role'],
                    'sourceType' => (string)$item['sourceType'],
                    'sourceId' => (int)$item['sourceId'],
                    'position' => (int)($item['position'] ?? 0),
                    'promptNumber' => VideoReferencePolicy::promptNumber((string)$item['role'], (int)($item['position'] ?? 1)),
                    'file' => (string)$item['filePath'],
                    'mediaType' => (string)($item['metadata']['mediaType'] ?? ($item['sourceType'] === 'generation_job' ? 'video' : 'image')),
                    'mimeType' => (string)($item['metadata']['mimeType'] ?? ''),
                    'instruction' => (string)($item['metadata']['instruction'] ?? VideoReferencePolicy::defaultInstruction((string)$item['role'])),
                ];
            }, $referenceList),
            'imageInputCount' => $imageInputCount,
        ];
        if ($hasContinuity) {
            $snapshot['continuity'] = [
                'strategy' => 'previous_last_frame',
                'sceneId' => (int)$previousGeneration['previous_scene_id'],
                'generationId' => (int)$previousGeneration['id'],
                'provider' => (string)$previousGeneration['provider'],
                'model' => (string)$previousGeneration['model'],
                'outputFile' => (string)$previousGeneration['output_path'],
            ];
        }
        if ($mode === 'first_last_frame') {
            $snapshot['startReferenceId'] = (int)$references['start_frame']['id'];
            $snapshot['startReferenceFile'] = basename((string)$references['start_frame']['filePath']);
            $snapshot['endReferenceId'] = (int)$references['end_frame']['id'];
            $snapshot['endReferenceFile'] = basename((string)$references['end_frame']['filePath']);
        } elseif ($reference !== null) {
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
                'provider' => $provider->name(), 'model' => $provider->model(), 'mode' => $mode,
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

    private static function sameUnifiedArtwork(array $left, array $right): bool
    {
        $leftArtworkId = (int)($left['artworkId'] ?? 0);
        $rightArtworkId = (int)($right['artworkId'] ?? 0);
        if ($leftArtworkId <= 0 || $rightArtworkId <= 0) return true;

        $leftGroupId = (int)($left['artworkGroupId'] ?? 0);
        $rightGroupId = (int)($right['artworkGroupId'] ?? 0);
        if ($leftGroupId > 0 || $rightGroupId > 0) {
            return $leftGroupId > 0 && $leftGroupId === $rightGroupId;
        }

        return $leftArtworkId === $rightArtworkId;
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
                if (!empty($request['previousInteractionId'])) {
                    $submission = $provider->editInteraction($common + [
                        'previousInteractionId' => (string)$request['previousInteractionId'],
                    ]);
                } elseif ((string)$job['generation_mode'] === 'first_last_frame') {
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
                    $cleanup = [];
                    try {
                        $records = is_array($request['references'] ?? null) ? array_values($request['references']) : [];
                        if ($records === [] && !empty($request['referenceFile'])) {
                            $records[] = [
                                'id' => (int)($request['referenceId'] ?? 0),
                                'role' => 'main',
                                'file' => (string)$request['referenceFile'],
                                'mediaType' => 'image',
                            ];
                        }

                        $records = $this->orderedRecords($records);
                        $sourceVideoRecord = $this->sourceVideoRecord($records);

                        if ($sourceVideoRecord !== null) {
                            $referenceImages = $this->prepareReferenceImages($records, null, $cleanup);
                            $videoFile = (string)($sourceVideoRecord['file'] ?? '');
                            $videoMime = (string)($sourceVideoRecord['mimeType'] ?? '');
                            if ($videoMime === '') {
                                $videoMime = match (strtolower(pathinfo($videoFile, PATHINFO_EXTENSION))) {
                                    'mov' => 'video/quicktime',
                                    'webm' => 'video/webm',
                                    default => 'video/mp4',
                                };
                            }
                            $videoUri = '';
                            if ($bucket !== '' && StorageService::isGcsActive()) {
                                $videoUri = 'gs://' . $bucket . '/' . ltrim(str_replace('\\', '/', $videoFile), '/');
                            }
                            $videoPath = '';
                            if ($videoUri === '') {
                                $preparedVideo = $this->storage->prepareReferenceVideo($videoFile);
                                $videoPath = $preparedVideo['path'];
                                foreach ($preparedVideo['temporaryPaths'] as $path) $cleanup[] = $path;
                            }
                            $submission = $provider->editVideo($common + [
                                'videoUri' => $videoUri,
                                'videoPath' => $videoPath,
                                'videoMimeType' => $videoMime,
                                'referenceImages' => $referenceImages,
                            ]);
                        }

                        if ($sourceVideoRecord === null) {
                            $firstRecord = $this->firstInputRecord($records);

                            $firstFrame = null;
                            if (is_array($firstRecord)) {
                                if (($firstRecord['mediaType'] ?? 'image') === 'video') {
                                    $preparedVideo = $this->storage->prepareReferenceVideoFrame((string)($firstRecord['file'] ?? ''));
                                    $firstFrame = ['path' => $preparedVideo['path'], 'mimeType' => $preparedVideo['mimeType']];
                                    foreach ($preparedVideo['temporaryPaths'] as $path) $cleanup[] = $path;
                                } else {
                                    $prepared = $this->storage->prepareReferenceImage((string)($firstRecord['file'] ?? ''));
                                    $firstFrame = ['path' => $prepared['path'], 'mimeType' => $prepared['mimeType']];
                                    if (!empty($prepared['temporary'])) $cleanup[] = (string)$prepared['path'];
                                }
                            }

                            $continuityFrame = null;
                            $continuity = is_array($request['continuity'] ?? null) ? $request['continuity'] : null;
                            if ($continuity !== null && !empty($continuity['outputFile'])) {
                                $continuityFrame = $this->storage->prepareContinuityFrame((string)$continuity['outputFile']);
                                foreach ($continuityFrame['temporaryPaths'] as $path) $cleanup[] = $path;
                                if ($firstFrame === null) {
                                    $firstFrame = ['path' => $continuityFrame['path'], 'mimeType' => $continuityFrame['mimeType']];
                                }
                            }

                            $referenceImages = $this->prepareReferenceImages($records, $firstRecord, $cleanup);

                            // Veo's asset-reference mode is only documented for an
                            // 8-second request and cannot be combined with `image`.
                            // For a 4/6-second clip with no continuity frame, use the
                            // first generic image as the supported initial frame.
                            if ($provider->name() === VideoProviderRegistry::VEO) {
                                if ($firstFrame === null && (int)$job['requested_duration_seconds'] !== 8 && $referenceImages !== []) {
                                    $firstFrame = array_shift($referenceImages);
                                }
                                if ($firstFrame !== null) $referenceImages = [];
                            }

                            $submission = $provider->generateFromImage($common + [
                                'firstFrame' => $firstFrame,
                                'referenceImages' => $referenceImages,
                            ]);
                        }
                    } finally {
                        foreach (array_unique($cleanup) as $path) @unlink($path);
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
                    $this->jobs->markGenerationFailed($jobId, (string)($result['error'] ?? 'Video generation failed.'), $result['response']);
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
        try {
            return VideoProviderRegistry::make((string)$job['provider'], (string)$job['model']);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('Unsupported video provider.', 0, $e);
        }
    }

    private function firstInputRecord(array $records): ?array
    {
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            if (($record['role'] ?? '') !== 'start_frame') continue;
            $mediaType = (string)($record['mediaType'] ?? 'image');
            if (in_array($mediaType, ['image','video'], true)) return $record;
        }
        return null;
    }

    private function sourceVideoRecord(array $records): ?array
    {
        foreach ($records as $record) {
            if (!is_array($record) || ($record['mediaType'] ?? '') !== 'video') continue;
            if (($record['role'] ?? '') === 'source_video') return $record;
            if (($record['sourceType'] ?? '') === 'reference_asset') return $record;
        }
        return null;
    }

    private function orderedRecords(array $records): array
    {
        usort($records, static function (mixed $left, mixed $right): int {
            if (!is_array($left)) return 1;
            if (!is_array($right)) return -1;
            $weight = VideoReferencePolicy::sortWeight((string)($left['role'] ?? 'reference'))
                <=> VideoReferencePolicy::sortWeight((string)($right['role'] ?? 'reference'));
            if ($weight !== 0) return $weight;
            $position = (int)($left['position'] ?? 0) <=> (int)($right['position'] ?? 0);
            return $position !== 0 ? $position : (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0);
        });
        return $records;
    }

    private function prepareReferenceImages(array $records, ?array $excludedRecord, array &$cleanup): array
    {
        $images = [];
        foreach ($records as $record) {
            if (!is_array($record) || ($record['mediaType'] ?? 'image') !== 'image') continue;
            if ($excludedRecord !== null && (int)($record['id'] ?? 0) === (int)($excludedRecord['id'] ?? 0)) continue;
            $prepared = $this->storage->prepareReferenceImage((string)($record['file'] ?? ''));
            $images[] = [
                'path' => $prepared['path'],
                'mimeType' => $prepared['mimeType'],
                'role' => (string)($record['role'] ?? 'reference'),
                'promptNumber' => (int)($record['promptNumber'] ?? VideoReferencePolicy::promptNumber((string)($record['role'] ?? 'reference'), (int)($record['position'] ?? 1))),
                'instruction' => trim((string)($record['instruction'] ?? VideoReferencePolicy::defaultInstruction((string)($record['role'] ?? 'reference')))),
            ];
            if (!empty($prepared['temporary'])) $cleanup[] = (string)$prepared['path'];
        }
        return $images;
    }

    private function startAdjustment(
        int $userId,
        int $sceneId,
        int $version,
        array $context,
        VideoGenerationProvider $provider,
        string $adjustPrompt
    ): array {
        if ($provider->name() !== VideoProviderRegistry::OMNI) {
            throw new DomainException('Ajustar el resultado mediante conversación requiere Gemini Omni.');
        }
        $prompt = trim(mb_substr($adjustPrompt, 0, 20000));
        if ($prompt === '') throw new InvalidArgumentException('Describe el ajuste que quieres aplicar.');
        $active = $this->jobs->activeGenerationForScene($userId, $sceneId);
        if (!is_array($active) || trim((string)($active['output_path'] ?? '')) === '') {
            throw new DomainException('Esta secuencia todavía no tiene un resultado Omni editable.');
        }
        if ((string)$active['provider'] !== VideoProviderRegistry::OMNI || (string)$active['model'] !== $provider->model()) {
            throw new DomainException('El resultado activo no pertenece a la interacción Omni actual.');
        }

        $scene = $context['scene'];
        $project = $context['project'];
        $duration = (int)round((float)$scene['durationSeconds']);
        if (!in_array($duration, VideoProviderRegistry::durations($provider->name()), true)) {
            throw new InvalidArgumentException($provider->model() . ' does not support the selected scene duration.');
        }
        if (!in_array((string)$project['aspectRatio'], self::ASPECTS, true)) {
            throw new InvalidArgumentException($provider->model() . ' currently supports 9:16 or 16:9 in this studio.');
        }
        $snapshot = [
            'projectId' => (int)$project['id'],
            'sceneId' => $sceneId,
            'intent' => 'adjust',
            'mode' => 'edit',
            'durationSeconds' => $duration,
            'aspectRatio' => (string)$project['aspectRatio'],
            'resolution' => app_env('VIDEO_VEO_RESOLUTION', '720p'),
            'parentGenerationId' => (int)$active['id'],
            'references' => [[
                'id' => (int)$active['id'],
                'role' => 'source_video',
                'sourceType' => 'generation_job',
                'sourceId' => (int)$active['id'],
                'position' => 0,
                'file' => (string)$active['output_path'],
                'mediaType' => 'video',
                'mimeType' => 'video/mp4',
                'instruction' => 'Editar este video conservando lo no solicitado.',
            ]],
        ];
        $inputHash = hash('sha256', json_encode([$snapshot,$prompt,$provider->model()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $pending = $this->jobs->pendingGeneration($userId, $sceneId, $inputHash);
        if ($pending) return $this->payload($userId, (int)$project['id'], $sceneId, (int)$pending['id']);

        $pdo = $this->studio->pdo();
        Database::beginWriteTransaction($pdo);
        try {
            $jobId = $this->jobs->createGeneration([
                'user_id' => $userId,
                'project_id' => (int)$project['id'],
                'scene_id' => $sceneId,
                'artwork_id' => $active['artwork_id'] ?? $project['artworkId'] ?? null,
                'series_id' => $active['series_id'] ?? $project['seriesId'] ?? null,
                'provider' => $provider->name(),
                'model' => $provider->model(),
                'mode' => 'edit',
                'idempotency_key' => bin2hex(random_bytes(32)),
                'scene_version' => $version,
                'input_hash' => $inputHash,
                'duration_seconds' => $duration,
                'aspect_ratio' => (string)$project['aspectRatio'],
                'prompt' => $prompt,
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

    private function schedulePoll(int $jobId): void
    {
        try {
            $task = $this->dispatcher->dispatchGeneration($jobId, 15);
            $this->jobs->updateGenerationTask($jobId, $task);
        } catch (Throwable $e) {
            Logger::log('Could not schedule video provider poll for job ' . $jobId . ': ' . $e->getMessage(), 'error');
            throw new RuntimeException('Could not schedule the next video provider status check.', 503, $e);
        }
    }

    private function payload(int $userId, int $projectId, ?int $sceneId, ?int $jobId): array
    {
        $service = new VideoStudioService($this->studio);
        return $service->studioPayload($userId, $projectId) + ['selectedSceneId' => $sceneId, 'generationJobId' => $jobId];
    }
}
