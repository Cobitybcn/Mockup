<?php
declare(strict_types=1);

final class VideoStudioService
{
    private const ASPECT_RATIOS = ['9:16','16:9','1:1','4:5'];
    private const PROJECT_TYPES = ['social_clip','artist_reel','short_film','custom'];
    private const PROJECT_STATUSES = ['draft','active','archived','deleted'];
    private const PURPOSES = ['opening','establishing','artwork_reveal','texture_detail','human_scale','atmosphere','transition','closing','custom'];
    private const GENERATION_MODES = ['image_to_video','first_last_frame','extend_video'];
    private const ARTWORK_MOTION = ['locked','minimal','creative'];
    private const CAMERA_MOVEMENTS = ['static','slow_push_in','slow_pull_back','pan_left','pan_right','tilt_up','tilt_down','orbit_left','orbit_right','handheld_subtle','custom'];
    private const MOTION_INTENSITIES = ['very_low','low','medium','high'];
    private const TRANSITIONS = ['cut','fade','cross_dissolve','dip_black','dip_white','ai_transition'];
    private const AUDIO_MODES = ['silence','ambient_sound','generated_audio','uploaded_audio','continue_previous'];

    public function __construct(private VideoStudioRepository $repository)
    {
    }

    public function listProjects(int $userId): array
    {
        return $this->repository->listProjects($userId);
    }

    public function studioPayload(int $userId, int $projectId): array
    {
        $project = $this->requireProject($userId, $projectId);
        $scenes = $this->repository->scenes($projectId);
        return [
            'project' => $project,
            'scenes' => $scenes,
            'latestExport' => $this->repository->latestExport($userId, $projectId),
            'summary' => [
                'sceneCount' => count($scenes),
                'durationSeconds' => array_sum(array_map(static fn(array $scene): float => (float)$scene['durationSeconds'], $scenes)),
                'generatedCount' => count(array_filter($scenes, static fn(array $scene): bool => is_array($scene['active_generation'] ?? null))),
            ],
        ];
    }

    public function library(int $userId): array
    {
        return $this->repository->library($userId);
    }

    public function capabilities(): array
    {
        $provider = VideoProviderRegistry::configuredName();
        $omni = $provider === VideoProviderRegistry::OMNI;
        return [
            'aspectRatios' => self::ASPECT_RATIOS,
            'generationAspectRatios' => ['9:16','16:9'],
            'durations' => VideoProviderRegistry::durations($provider),
            'defaultDuration' => VideoProviderRegistry::defaultDuration($provider),
            'defaultMode' => VideoProviderRegistry::defaultMode($provider),
            'modes' => [
                ['id' => 'image_to_video', 'label' => 'Animate Mockup', 'available' => true],
                ['id' => 'first_last_frame', 'label' => 'Start Frame → End Frame', 'available' => !$omni],
                ['id' => 'extend_video', 'label' => 'Continue Previous Clip', 'available' => false],
            ],
            'referenceRoles' => VideoReferencePolicy::roles(),
            'referenceLimits' => [
                'images' => VideoReferencePolicy::MAX_IMAGES,
                'videos' => VideoReferencePolicy::MAX_VIDEOS,
                'videoSeconds' => VideoReferencePolicy::MAX_VIDEO_SECONDS,
            ],
            'audioModes' => [
                ['id' => 'silence', 'label' => 'Silence', 'available' => true],
                ['id' => 'ambient_sound', 'label' => 'Ambient Sound', 'available' => false],
            ],
            'continuity' => [
                'automatic' => true,
                'strategy' => 'previous_last_frame',
                'temporalExtension' => false,
                'note' => 'The previous result is handed off through its last frame. This improves continuity but does not guarantee it.',
            ],
            'artworkMotion' => self::ARTWORK_MOTION,
            'transitions' => self::TRANSITIONS,
            'projectTypes' => self::PROJECT_TYPES,
            'purposes' => self::PURPOSES,
            'sceneStatuses' => ['draft','ready','approved'],
            'generationProvider' => $provider,
            'generationModel' => VideoProviderRegistry::configuredModel(),
        ];
    }

    public function createProject(int $userId, array $input): array
    {
        $artworkId = $this->nullablePositiveInt($input['artworkId'] ?? null);
        $seriesId = $this->nullablePositiveInt($input['seriesId'] ?? null);
        $this->assertArtworkOwnership($userId, $artworkId);
        if ($artworkId !== null) {
            $seriesId = $this->seriesForArtwork($userId, $artworkId);
        } else {
            $this->assertSeriesOwnership($userId, $seriesId);
        }

        $title = $this->text($input['title'] ?? '', 255);
        if ($title === '') $title = $this->defaultProjectTitle($userId, $artworkId);
        $values = [
            'title' => $title,
            'description' => $this->text($input['description'] ?? '', 2000),
            'global_prompt' => $this->longText($input['globalPrompt'] ?? '', 20000),
            'artwork_id' => $artworkId,
            'series_id' => $seriesId,
            'aspect_ratio' => $this->choice($input['aspectRatio'] ?? '9:16', self::ASPECT_RATIOS, 'aspect ratio'),
            'target_duration_seconds' => $this->number($input['targetDurationSeconds'] ?? 30, 4, 600, 'target duration'),
            'project_type' => $this->choice($input['projectType'] ?? 'custom', self::PROJECT_TYPES, 'project type'),
        ];
        return $this->transaction(function () use ($userId, $values): array {
            $projectId = $this->repository->createProject($userId, $values);
            $defaultMode = VideoProviderRegistry::defaultMode();
            $defaultDuration = VideoProviderRegistry::defaultDuration();
            for ($number = 1; $number <= 3; $number++) {
                $position = $number * 10;
                $this->repository->createScene($projectId, $position, $this->sceneValues([
                    'title' => 'Sequence ' . $number,
                    'durationSeconds' => $defaultDuration,
                    'generationMode' => $defaultMode,
                ], $number));
            }
            return $this->studioPayload($userId, $projectId);
        });
    }

    public function updateProject(int $userId, int $projectId, int $version, array $input): array
    {
        $this->requireProject($userId, $projectId);
        $allowed = [];
        if (array_key_exists('title', $input)) {
            $allowed['title'] = $this->text($input['title'], 255);
            if ($allowed['title'] === '') throw new InvalidArgumentException('Project title is required.');
        }
        if (array_key_exists('description', $input)) $allowed['description'] = $this->text($input['description'], 2000);
        if (array_key_exists('globalPrompt', $input)) $allowed['global_prompt'] = $this->longText($input['globalPrompt'], 20000);
        if (array_key_exists('artworkId', $input)) {
            $allowed['artwork_id'] = $this->nullablePositiveInt($input['artworkId']);
            $this->assertArtworkOwnership($userId, $allowed['artwork_id']);
            $allowed['series_id'] = $allowed['artwork_id'] === null
                ? null
                : $this->seriesForArtwork($userId, $allowed['artwork_id']);
        }
        if (!array_key_exists('artworkId', $input) && array_key_exists('seriesId', $input)) {
            $allowed['series_id'] = $this->nullablePositiveInt($input['seriesId']);
            $this->assertSeriesOwnership($userId, $allowed['series_id']);
        }
        if (array_key_exists('aspectRatio', $input)) $allowed['aspect_ratio'] = $this->choice($input['aspectRatio'], self::ASPECT_RATIOS, 'aspect ratio');
        if (array_key_exists('targetDurationSeconds', $input)) $allowed['target_duration_seconds'] = $this->number($input['targetDurationSeconds'], 4, 600, 'target duration');
        if (array_key_exists('projectType', $input)) $allowed['project_type'] = $this->choice($input['projectType'], self::PROJECT_TYPES, 'project type');
        if (array_key_exists('status', $input)) $allowed['status'] = $this->choice($input['status'], self::PROJECT_STATUSES, 'project status');
        if (!$this->repository->updateProject($userId, $projectId, $version, $allowed)) {
            throw new DomainException('This project changed in another session. Reload before saving.');
        }
        return $this->studioPayload($userId, $projectId);
    }

    public function deleteProject(int $userId, int $projectId, int $version): array
    {
        $this->requireProject($userId, $projectId);
        if (!$this->repository->updateProject($userId, $projectId, $version, ['status' => 'deleted'])) {
            throw new DomainException('This project changed in another session. Reload before deleting it.');
        }
        return [
            'deletedProjectId' => $projectId,
            'projects' => $this->repository->listProjects($userId),
        ];
    }

    public function createScene(int $userId, int $projectId, int $version, array $input): array
    {
        $this->requireProject($userId, $projectId);
        return $this->transaction(function () use ($userId, $projectId, $version, $input): array {
            $position = $this->repository->nextScenePosition($projectId);
            $values = $this->sceneValues($input, $position / 10);
            $sceneId = $this->repository->createScene($projectId, $position, $values);
            if (isset($input['sourceType'], $input['sourceId'])) {
                $source = $this->resolveSource($userId, (string)$input['sourceType'], (int)$input['sourceId']);
                $role = (string)($input['role'] ?? 'reference');
                $role = $this->choice($role, VideoReferencePolicy::roles(), 'reference role');
                $this->repository->replaceReference($sceneId, $role, $source);
            }
            $this->repository->touchProject($userId, $projectId, $version);
            return $this->studioPayload($userId, $projectId) + ['selectedSceneId' => $sceneId];
        });
    }

    public function updateScene(int $userId, int $sceneId, int $version, array $input): array
    {
        $scene = $this->requireScene($userId, $sceneId);
        if ($scene['editingLocked'] && !array_key_exists('editingLocked', $input)) {
            throw new DomainException('Unlock this scene before editing it.');
        }
        $changes = [];
        $generationChanged = false;
        if (array_key_exists('title', $input)) {
            $changes['title'] = $this->text($input['title'], 255);
            if ($changes['title'] === '') throw new InvalidArgumentException('Scene title is required.');
        }
        if (array_key_exists('purpose', $input)) $changes['purpose'] = $this->choice($input['purpose'], self::PURPOSES, 'scene purpose');
        if (array_key_exists('prompt', $input)) { $changes['prompt'] = $this->longText($input['prompt'], 20000); $generationChanged = $generationChanged || $changes['prompt'] !== $scene['prompt']; }
        if (array_key_exists('durationSeconds', $input)) { $changes['duration_seconds'] = $this->number($input['durationSeconds'], 1, 180, 'scene duration'); $generationChanged = $generationChanged || abs($changes['duration_seconds'] - (float)$scene['durationSeconds']) > 0.001; }
        if (array_key_exists('generationMode', $input)) { $changes['generation_mode'] = $this->choice($input['generationMode'], self::GENERATION_MODES, 'generation mode'); $generationChanged = $generationChanged || $changes['generation_mode'] !== $scene['generationMode']; }
        if (array_key_exists('artworkMotion', $input)) { $changes['artwork_motion'] = $this->choice($input['artworkMotion'], self::ARTWORK_MOTION, 'artwork motion'); $generationChanged = $generationChanged || $changes['artwork_motion'] !== $scene['artworkMotion']; }
        if (array_key_exists('cameraMovement', $input)) { $changes['camera_movement'] = $this->choice($input['cameraMovement'], self::CAMERA_MOVEMENTS, 'camera movement'); $generationChanged = $generationChanged || $changes['camera_movement'] !== $scene['cameraMovement']; }
        if (array_key_exists('customCameraMovement', $input)) { $changes['custom_camera_movement'] = $this->text($input['customCameraMovement'], 255); $generationChanged = $generationChanged || $changes['custom_camera_movement'] !== $scene['customCameraMovement']; }
        if (array_key_exists('motionIntensity', $input)) { $changes['motion_intensity'] = $this->choice($input['motionIntensity'], self::MOTION_INTENSITIES, 'motion intensity'); $generationChanged = $generationChanged || $changes['motion_intensity'] !== $scene['motionIntensity']; }
        if (array_key_exists('transitionType', $input)) $changes['transition_out_type'] = $this->choice($input['transitionType'], self::TRANSITIONS, 'transition');
        if (array_key_exists('transitionDurationSeconds', $input)) $changes['transition_out_duration_seconds'] = $this->number($input['transitionDurationSeconds'], 0, 5, 'transition duration');
        if (array_key_exists('audioMode', $input)) $changes['audio_mode'] = $this->choice($input['audioMode'], self::AUDIO_MODES, 'audio mode');
        if (array_key_exists('editingLocked', $input)) $changes['is_locked'] = !empty($input['editingLocked']) ? 1 : 0;
        if (array_key_exists('status', $input)) $changes['status'] = $this->choice($input['status'], ['draft','ready','approved'], 'scene status');
        if ($generationChanged) $changes['status'] = 'draft';

        $projectId = (int)$scene['projectId'];
        return $this->transaction(function () use ($userId, $projectId, $version, $sceneId, $changes): array {
            $this->repository->updateScene($sceneId, $changes);
            $this->repository->touchProject($userId, $projectId, $version);
            return $this->studioPayload($userId, $projectId) + ['selectedSceneId' => $sceneId];
        });
    }

    public function reorderScenes(int $userId, int $projectId, int $version, array $sceneIds): array
    {
        $this->requireProject($userId, $projectId);
        $sceneIds = array_values(array_unique(array_map('intval', $sceneIds)));
        $existing = array_map(static fn(array $scene): int => (int)$scene['id'], $this->repository->scenes($projectId));
        $sortedSubmitted = $sceneIds; sort($sortedSubmitted);
        $sortedExisting = $existing; sort($sortedExisting);
        if ($sortedSubmitted !== $sortedExisting) {
            throw new InvalidArgumentException('The scene order must include every scene in this project exactly once.');
        }
        return $this->transaction(function () use ($userId, $projectId, $version, $sceneIds): array {
            $this->repository->reorderScenes($projectId, $sceneIds);
            $this->repository->touchProject($userId, $projectId, $version);
            return $this->studioPayload($userId, $projectId);
        });
    }

    public function duplicateScene(int $userId, int $sceneId, int $version): array
    {
        $scene = $this->requireScene($userId, $sceneId);
        $projectId = (int)$scene['projectId'];
        return $this->transaction(function () use ($userId, $projectId, $version, $sceneId): array {
            $newId = $this->repository->duplicateScene($sceneId, $this->repository->nextScenePosition($projectId));
            $this->repository->touchProject($userId, $projectId, $version);
            return $this->studioPayload($userId, $projectId) + ['selectedSceneId' => $newId];
        });
    }

    public function deleteScene(int $userId, int $sceneId, int $version): array
    {
        $scene = $this->requireScene($userId, $sceneId);
        $projectId = (int)$scene['projectId'];
        return $this->transaction(function () use ($userId, $projectId, $version, $sceneId): array {
            $this->repository->deleteScene($sceneId);
            $remaining = array_map(static fn(array $scene): int => (int)$scene['id'], $this->repository->scenes($projectId));
            if ($remaining !== []) $this->repository->reorderScenes($projectId, $remaining);
            $this->repository->touchProject($userId, $projectId, $version);
            return $this->studioPayload($userId, $projectId);
        });
    }

    public function addReference(int $userId, int $sceneId, int $version, array $input): array
    {
        $scene = $this->requireScene($userId, $sceneId);
        if ($scene['editingLocked']) throw new DomainException('Unlock this scene before changing references.');
        $source = $this->resolveSource($userId, (string)($input['sourceType'] ?? ''), (int)($input['sourceId'] ?? 0));
        $role = $this->choice($input['role'] ?? 'reference', VideoReferencePolicy::roles(), 'reference role');
        $metadata = json_decode((string)($source['metadata_json'] ?? ''), true);
        if (!is_array($metadata)) $metadata = [];
        $mediaType = (string)($metadata['mediaType'] ?? 'image');
        if ($mediaType === 'video') {
            $generatedContinuation = ($source['source_type'] ?? '') === 'generation_job' && $role === 'start_frame';
            if (!$generatedContinuation && $role !== 'source_video') {
                throw new InvalidArgumentException('Los videos deben colocarse en Video base para editar.');
            }
        } elseif (!VideoReferencePolicy::isImageRole($role)) {
            throw new InvalidArgumentException('Esta área admite únicamente un video.');
        }
        if (($source['source_type'] ?? '') === 'generation_job' && (int)($metadata['sceneId'] ?? 0) === $sceneId) {
            throw new InvalidArgumentException('A generated result cannot be used as a reference by its own scene.');
        }
        $this->assertReferenceCapacity($sceneId, $role, $mediaType);
        $instruction = $this->longText($input['instruction'] ?? VideoReferencePolicy::defaultInstruction($role), 1000);
        $metadata['instruction'] = $instruction;
        $source['metadata_json'] = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $projectId = (int)$scene['projectId'];
        return $this->transaction(function () use ($userId, $projectId, $version, $sceneId, $role, $source): array {
            $this->repository->replaceReference($sceneId, $role, $source);
            $this->repository->updateScene($sceneId, ['status' => 'draft']);
            $this->repository->touchProject($userId, $projectId, $version);
            return $this->studioPayload($userId, $projectId) + ['selectedSceneId' => $sceneId];
        });
    }

    public function removeReference(int $userId, int $referenceId, int $version): array
    {
        return $this->transaction(function () use ($userId, $referenceId, $version): array {
            $projectId = $this->repository->removeReference($userId, $referenceId);
            $this->repository->touchProject($userId, $projectId, $version);
            return $this->studioPayload($userId, $projectId);
        });
    }

    public function updateReferenceInstruction(int $userId, int $referenceId, int $version, mixed $instruction): array
    {
        $instruction = $this->longText($instruction, 1000);
        return $this->transaction(function () use ($userId, $referenceId, $version, $instruction): array {
            $projectId = $this->repository->updateReferenceInstruction($userId, $referenceId, $instruction);
            $this->repository->touchProject($userId, $projectId, $version);
            return $this->studioPayload($userId, $projectId);
        });
    }

    private function assertReferenceCapacity(int $sceneId, string $incomingRole, string $incomingMediaType): void
    {
        $references = $this->repository->referencesForScene($sceneId);
        if ($incomingMediaType === 'video') return;

        $count = 0;
        foreach ($references as $reference) {
            if (($reference['mediaType'] ?? 'image') !== 'image') continue;
            if (VideoReferencePolicy::isSingle($incomingRole) && (string)$reference['role'] === $incomingRole) continue;
            $count++;
        }
        if ($count + 1 > VideoReferencePolicy::MAX_IMAGES) {
            throw new InvalidArgumentException('Omni admite un máximo de 10 imágenes por secuencia.');
        }
    }

    private function sceneValues(array $input, float $number): array
    {
        $title = $this->text($input['title'] ?? '', 255);
        if ($title === '') $title = 'Scene ' . max(1, (int)$number);
        return [
            'title' => $title,
            'purpose' => $this->choice($input['purpose'] ?? 'custom', self::PURPOSES, 'scene purpose'),
            'prompt' => $this->longText($input['prompt'] ?? '', 20000),
            'duration_seconds' => $this->number($input['durationSeconds'] ?? VideoProviderRegistry::defaultDuration(), 1, 180, 'scene duration'),
            'generation_mode' => $this->choice($input['generationMode'] ?? VideoProviderRegistry::defaultMode(), self::GENERATION_MODES, 'generation mode'),
            'artwork_motion' => $this->choice($input['artworkMotion'] ?? 'locked', self::ARTWORK_MOTION, 'artwork motion'),
            'camera_movement' => $this->choice($input['cameraMovement'] ?? 'static', self::CAMERA_MOVEMENTS, 'camera movement'),
            'custom_camera_movement' => $this->text($input['customCameraMovement'] ?? '', 255),
            'motion_intensity' => $this->choice($input['motionIntensity'] ?? 'low', self::MOTION_INTENSITIES, 'motion intensity'),
            'transition_out_type' => $this->choice($input['transitionType'] ?? 'cut', self::TRANSITIONS, 'transition'),
            'transition_out_duration_seconds' => $this->number($input['transitionDurationSeconds'] ?? 0, 0, 5, 'transition duration'),
            'audio_mode' => $this->choice($input['audioMode'] ?? 'silence', self::AUDIO_MODES, 'audio mode'),
        ];
    }

    private function requireProject(int $userId, int $projectId): array
    {
        if ($projectId <= 0) throw new InvalidArgumentException('A valid project ID is required.');
        $project = $this->repository->findProject($userId, $projectId);
        if (!$project) throw new OutOfBoundsException('Video project not found.');
        return $project;
    }

    private function requireScene(int $userId, int $sceneId): array
    {
        if ($sceneId <= 0) throw new InvalidArgumentException('A valid scene ID is required.');
        $scene = $this->repository->findScene($userId, $sceneId);
        if (!$scene) throw new OutOfBoundsException('Scene not found.');
        return $scene;
    }

    private function resolveSource(int $userId, string $type, int $id): array
    {
        if (!in_array($type, ['mockup','artwork','generation_job','reference_asset'], true) || $id <= 0) {
            throw new InvalidArgumentException('Invalid scene reference.');
        }
        $source = $this->repository->referenceSource($userId, $type, $id);
        if (!$source) throw new OutOfBoundsException('The selected reference was not found.');
        return $source;
    }

    private function assertArtworkOwnership(int $userId, ?int $artworkId): void
    {
        if ($artworkId === null) return;
        $stmt = $this->repository->pdo()->prepare('SELECT 1 FROM artworks WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$artworkId, $userId]);
        if (!$stmt->fetchColumn()) throw new OutOfBoundsException('Artwork not found.');
    }

    private function assertSeriesOwnership(int $userId, ?int $seriesId): void
    {
        if ($seriesId === null) return;
        $stmt = $this->repository->pdo()->prepare('SELECT 1 FROM artwork_series WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$seriesId, $userId]);
        if (!$stmt->fetchColumn()) throw new OutOfBoundsException('Series not found.');
    }

    private function seriesForArtwork(int $userId, int $artworkId): ?int
    {
        $stmt = $this->repository->pdo()->prepare('SELECT series_id FROM artworks WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$artworkId, $userId]);
        $seriesId = $stmt->fetchColumn();
        return $seriesId === false || $seriesId === null || $seriesId === '' ? null : (int)$seriesId;
    }

    private function defaultProjectTitle(int $userId, ?int $artworkId): string
    {
        $base = 'Video';
        if ($artworkId !== null) {
            $stmt = $this->repository->pdo()->prepare("SELECT a.final_title,ag.title AS group_title,
                    (SELECT sh.title FROM artwork_sheets sh WHERE sh.user_id=a.user_id AND sh.canonical_artwork_id=a.id ORDER BY sh.id DESC LIMIT 1) AS sheet_title
                FROM artworks a
                LEFT JOIN artwork_groups ag ON ag.id=a.artwork_group_id AND ag.user_id=a.user_id AND ag.status='active'
                WHERE a.id=? AND a.user_id=? LIMIT 1");
            $stmt->execute([$artworkId, $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $base = trim((string)($row['group_title'] ?? ''))
                    ?: trim((string)($row['final_title'] ?? ''))
                    ?: trim((string)($row['sheet_title'] ?? ''))
                    ?: 'Artwork #' . $artworkId;
            }
            $count = $this->repository->pdo()->prepare('SELECT COUNT(*) FROM video_projects WHERE user_id=? AND artwork_id=?');
            $count->execute([$userId, $artworkId]);
        } else {
            $count = $this->repository->pdo()->prepare('SELECT COUNT(*) FROM video_projects WHERE user_id=?');
            $count->execute([$userId]);
        }
        $number = (int)$count->fetchColumn() + 1;
        $numberLabel = str_pad((string)$number, 2, '0', STR_PAD_LEFT);
        return $artworkId === null ? 'Video ' . $numberLabel : mb_substr($base, 0, 225) . ' — Video ' . $numberLabel;
    }

    private function transaction(callable $callback): array
    {
        $this->repository->begin();
        try {
            $result = $callback();
            $this->repository->commit();
            return $result;
        } catch (Throwable $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    private function choice(mixed $value, array $allowed, string $label): string
    {
        $value = strtolower(trim((string)$value));
        if (!in_array($value, $allowed, true)) throw new InvalidArgumentException('Invalid ' . $label . '.');
        return $value;
    }

    private function number(mixed $value, float $min, float $max, string $label): float
    {
        if (!is_numeric($value)) throw new InvalidArgumentException('Invalid ' . $label . '.');
        $number = round((float)$value, 2);
        if ($number < $min || $number > $max) throw new InvalidArgumentException(ucfirst($label) . ' is outside the allowed range.');
        return $number;
    }

    private function text(mixed $value, int $max): string
    {
        $value = trim((string)$value);
        if (mb_strlen($value) > $max) throw new InvalidArgumentException('A text field is too long.');
        return $value;
    }

    private function longText(mixed $value, int $max): string
    {
        $value = trim((string)$value);
        if (mb_strlen($value) > $max) throw new InvalidArgumentException('A prompt is too long.');
        return $value;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }
}
