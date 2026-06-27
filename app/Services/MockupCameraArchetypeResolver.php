<?php
declare(strict_types=1);

class MockupCameraArchetypeResolver
{
    private string $baseDir;
    private array $config;

    public function __construct(?string $baseDir = null, ?array $config = null)
    {
        $this->baseDir = $baseDir ?? dirname(__DIR__, 2);
        $this->config = $config ?? $this->loadConfig();
    }

    public function resolveForArtwork(int $artworkId): array
    {
        $corePath = $this->baseDir . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . $artworkId . '.core.json';
        $fallback = $this->fallbackResult($corePath, 'core_json_not_loaded');

        if ($artworkId <= 0 || !is_file($corePath)) {
            return $fallback;
        }

        $content = file_get_contents($corePath);
        if ($content === false) {
            return $fallback;
        }

        $coreJson = json_decode($content, true);
        if (!is_array($coreJson)) {
            return $this->fallbackResult($corePath, 'core_json_invalid');
        }

        $dims = $coreJson['artwork']['dimensions'] ?? [];
        if (!is_array($dims)) {
            return $this->fallbackResult($corePath, 'core_dimensions_missing');
        }

        $width = $this->positiveFloat($dims['width_cm'] ?? null);
        $height = $this->positiveFloat($dims['height_cm'] ?? null);
        $depth = $this->positiveFloat($dims['depth_cm'] ?? null);
        $orientation = $this->normalizeOrientation($dims['orientation'] ?? null);
        $aspectRatio = $this->positiveFloat($dims['aspect_ratio'] ?? null);

        if ($aspectRatio === null && $width !== null && $height !== null && $height > 0) {
            $aspectRatio = round($width / $height, 4);
        }

        if ($orientation === null && $width !== null && $height !== null) {
            $orientation = $width > $height ? 'horizontal' : ($height > $width ? 'vertical' : 'square');
        }

        $longestSide = null;
        if ($width !== null || $height !== null) {
            $longestSide = max($width ?? 0.0, $height ?? 0.0);
        }

        if ($width === null || $height === null || $longestSide === null || $orientation === null || $aspectRatio === null) {
            $result = $this->fallbackResult($corePath, 'core_dimensions_incomplete');
            $result['dimensions'] = [
                'width_cm' => $width,
                'height_cm' => $height,
                'depth_cm' => $depth,
                'orientation_resolved' => $orientation,
                'aspect_ratio_resolved' => $aspectRatio,
                'longest_side_cm' => $longestSide,
                'size_class' => $this->sizeClass($longestSide),
                'xl_class' => 'fallback',
            ];
            return $result;
        }

        $sizeClass = $this->sizeClass($longestSide);
        $xlClass = ($longestSide >= 80.0 && $longestSide <= 140.0) ? 'xl' : 'not_xl';
        $setId = $xlClass === 'xl' ? 'xl_default_v1' : null;
        $set = $setId !== null ? ($this->config['sets'][$setId] ?? null) : null;
        $selectionStatus = 'not_xl';
        $fallbackUsed = false;
        $fallbackReason = null;

        if ($xlClass === 'xl') {
            if (!is_array($set)) {
                $selectionStatus = 'config_set_missing';
                $fallbackUsed = true;
                $fallbackReason = 'config_set_missing';
                $set = null;
            } elseif (!$this->hasValidSlots($set)) {
                $selectionStatus = 'slots_missing';
                $fallbackUsed = true;
                $fallbackReason = 'slots_missing';
                $set = null;
            } else {
                $selectionStatus = 'valid_archetype_set_selected';
            }
        }

        return [
            'ok' => !$fallbackUsed,
            'fallback_used' => $fallbackUsed,
            'fallback_reason' => $fallbackReason,
            'core_json_exists' => true,
            'core_json_path' => $corePath,
            'artwork_dimensions_source' => 'analysis/core/' . $artworkId . '.core.json',
            'selection_status' => $selectionStatus,
            'selected_set_id' => is_array($set) ? $setId : null,
            'selected_set' => is_array($set) ? $set : null,
            'dimensions' => [
                'width_cm' => $width,
                'height_cm' => $height,
                'depth_cm' => $depth,
                'orientation_resolved' => $orientation,
                'aspect_ratio_resolved' => $aspectRatio,
                'longest_side_cm' => $longestSide,
                'size_class' => $sizeClass,
                'xl_class' => $xlClass,
            ],
        ];
    }

    private function loadConfig(): array
    {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'mockup_camera_archetypes.php';
        $config = is_file($path) ? require $path : [];
        return is_array($config) ? $config : [];
    }

    private function fallbackResult(string $corePath, string $reason): array
    {
        return [
            'ok' => false,
            'fallback_used' => true,
            'fallback_reason' => $reason,
            'core_json_exists' => is_file($corePath),
            'core_json_path' => $corePath,
            'artwork_dimensions_source' => null,
            'selected_set_id' => null,
            'selected_set' => null,
            'selection_status' => $reason,
            'dimensions' => [
                'width_cm' => null,
                'height_cm' => null,
                'depth_cm' => null,
                'orientation_resolved' => null,
                'aspect_ratio_resolved' => null,
                'longest_side_cm' => null,
                'size_class' => 'unknown',
                'xl_class' => 'fallback',
            ],
        ];
    }

    private function positiveFloat($value): ?float
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $float = (float)str_replace(',', '.', (string)$value);
        return $float > 0 ? $float : null;
    }

    private function normalizeOrientation($value): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $value = strtolower(trim((string)$value));
        if (str_contains($value, 'horizontal') || str_contains($value, 'landscape')) {
            return 'horizontal';
        }
        if (str_contains($value, 'vertical') || str_contains($value, 'portrait')) {
            return 'vertical';
        }
        if (str_contains($value, 'square')) {
            return 'square';
        }
        return null;
    }

    private function hasValidSlots(array $set): bool
    {
        $slots = $set['slots'] ?? null;
        if (!is_array($slots)) {
            return false;
        }

        $requiredFields = [
            'camera_group',
            'camera_view',
            'camera_distance',
            'camera_angle_notes',
            'camera_archetype_id',
            'camera_archetype_name',
            'camera_archetype_reason',
        ];

        for ($slot = 1; $slot <= 6; $slot++) {
            if (!is_array($slots[$slot] ?? null)) {
                return false;
            }
            foreach ($requiredFields as $field) {
                if (!isset($slots[$slot][$field]) || trim((string)$slots[$slot][$field]) === '') {
                    return false;
                }
            }
        }

        return true;
    }

    private function sizeClass(?float $longestSide): string
    {
        if ($longestSide === null || $longestSide <= 0) {
            return 'unknown';
        }
        if ($longestSide < 80.0) {
            return 'standard';
        }
        if ($longestSide <= 140.0) {
            return 'xl';
        }
        return 'oversize';
    }
}
