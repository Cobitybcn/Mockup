<?php
declare(strict_types=1);

class MockupBranchContextBuilder
{
    /**
     * Builds mockup branch contexts for an artwork based on its CORE JSON 1.1.
     *
     * @param int $artworkId The ID of the artwork.
     * @return array The generated mockup branch context array.
     * @throws RuntimeException If the CORE JSON file is missing or invalid.
     */
    public function buildForArtwork(int $artworkId): array
    {
        Logger::log("MOCKUP_BRANCH_BUILD_START for artwork_id: {$artworkId}", 'info');

        $corePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . $artworkId . '.core.json';
        if (!is_file($corePath)) {
            throw new RuntimeException("CORE JSON 1.1 file not found for artwork ID {$artworkId} at path: {$corePath}");
        }

        $coreContent = file_get_contents($corePath);
        if ($coreContent === false) {
            throw new RuntimeException("Failed to read CORE JSON 1.1 file for artwork ID {$artworkId}");
        }

        $coreJson = json_decode($coreContent, true);
        if (!is_array($coreJson)) {
            throw new RuntimeException("Failed to parse CORE JSON 1.1 file for artwork ID {$artworkId}");
        }

        $schemaVersion = $coreJson['core_schema_version'] ?? '1.0';

        // 1. Resolve root view strategy
        $rootViews = $coreJson['root_artwork_views'] ?? [];
        $frontalFile = $rootViews['frontal']['file'] ?? null;
        $threeQuarterLeftFile = $rootViews['three_quarter_left']['file'] ?? null;
        $threeQuarterRightFile = $rootViews['three_quarter_right']['file'] ?? null;
        $selectedViewName = $rootViews['selected_view'] ?? null;

        // Resolve fallback file from selected view or standard order
        $fallbackFile = null;
        if ($selectedViewName !== null) {
            $fallbackFile = $rootViews[$selectedViewName]['file'] ?? null;
        }
        if ($fallbackFile === null) {
            $fallbackFile = $frontalFile ?? $threeQuarterLeftFile ?? $threeQuarterRightFile ?? null;
        }

        $rootViewStrategy = [
            'frontal' => $frontalFile,
            'three_quarter_left' => $threeQuarterLeftFile,
            'three_quarter_right' => $threeQuarterRightFile,
            'fallback' => $fallbackFile,
        ];

        // 2. Parse branches
        $suggestedTitles = $coreJson['publishing_texts']['suggested_titles'] ?? [];
        if (!is_array($suggestedTitles)) {
            $suggestedTitles = [];
        }

        $branches = [];
        for ($i = 0; $i < 3; $i++) {
            $branchId = $i + 1;
            $title = null;
            $subtitle = null;
            $description = null;

            if (isset($suggestedTitles[$i]) && is_array($suggestedTitles[$i])) {
                $title = $suggestedTitles[$i]['title'] ?? null;
                $subtitle = $suggestedTitles[$i]['subtitle'] ?? null;
                $description = $suggestedTitles[$i]['description'] ?? null;
            }

            // Combine fields to scan keywords conservatively
            $scanText = trim(implode(' ', array_filter([$title, $subtitle, $description])));

            $branches[] = [
                'branch_id' => $branchId,
                'source_title' => $title,
                'source_subtitle' => $subtitle,
                'source_description' => $description,
                'branch_role' => 'curatorial_exploration_direction',
                'mockup_direction' => [
                    'atmosphere' => $this->extractAtmosphere($scanText),
                    'space_character' => $this->extractSpaceCharacter($scanText),
                    'lighting_bias' => $this->extractLightingBias($scanText),
                    'material_affinity' => $this->extractMaterials($scanText),
                    'camera_bias' => $this->extractCameraBias($scanText),
                    'commercial_positioning' => $this->extractCommercialPositioning($scanText),
                ],
                'root_view_strategy' => $rootViewStrategy,
            ];
        }

        $result = [
            'artwork_id' => (int)$artworkId,
            'core_schema_version' => $schemaVersion,
            'source_core_json' => "analysis/core/{$artworkId}.core.json",
            'branches' => $branches,
        ];

        // 3. Write result to analysis/mockup-branches/{artwork_id}.branches.json
        $branchesDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'mockup-branches';
        if (!is_dir($branchesDir)) {
            if (!mkdir($branchesDir, 0775, true) && !is_dir($branchesDir)) {
                throw new RuntimeException("Failed to create folder: {$branchesDir}");
            }
        }

        $outputPath = $branchesDir . DIRECTORY_SEPARATOR . $artworkId . '.branches.json';
        $jsonString = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonString === false) {
            throw new RuntimeException("Failed to encode branch context JSON.");
        }

        if (file_put_contents($outputPath, $jsonString) === false) {
            throw new RuntimeException("Failed to write branch context JSON to disk at: {$outputPath}");
        }

        Logger::log("MOCKUP_BRANCH_BUILD_SUCCESS for artwork_id: {$artworkId}. Written to {$outputPath}", 'info');
        return $result;
    }

    private function extractAtmosphere(string $text): ?string
    {
        $keywords = ['contemplative', 'silent', 'intense', 'calm', 'restrained tension', 'nocturnal', 'poetic', 'sober', 'warm', 'cool', 'dramatic'];
        foreach ($keywords as $kw) {
            if (stripos($text, $kw) !== false) {
                return $kw;
            }
        }
        return null;
    }

    private function extractSpaceCharacter(string $text): ?string
    {
        $keywords = ['minimalist', 'interior', 'gallery', 'study', 'office', 'living room', 'architectural space', 'residential', 'luxury'];
        foreach ($keywords as $kw) {
            if (stripos($text, $kw) !== false) {
                return $kw;
            }
        }
        return null;
    }

    private function extractLightingBias(string $text): ?string
    {
        $keywords = ['sunset', 'daylight', 'day light', 'soft light', 'natural light', 'warm light', 'nocturnal', 'shadows'];
        foreach ($keywords as $kw) {
            if (stripos($text, $kw) !== false) {
                return $kw;
            }
        }
        return null;
    }

    private function extractMaterials(string $text): array
    {
        $keywords = ['wood', 'stone', 'leather', 'plaster', 'concrete', 'metal', 'velvet', 'glass', 'linen'];
        $found = [];
        foreach ($keywords as $kw) {
            if (stripos($text, $kw) !== false) {
                $found[] = $kw;
            }
        }
        return $found;
    }

    private function extractCameraBias(string $text): ?string
    {
        $keywords = ['frontal', 'three-quarter', 'oblique', 'close-up', 'wide shot', 'eye-level'];
        foreach ($keywords as $kw) {
            if (stripos($text, $kw) !== false) {
                return $kw;
            }
        }
        return null;
    }

    private function extractCommercialPositioning(string $text): ?string
    {
        $keywords = ['premium', 'collector', 'gallery', 'corporate', 'residential', 'exhibition'];
        foreach ($keywords as $kw) {
            if (stripos($text, $kw) !== false) {
                return $kw;
            }
        }
        return null;
    }
}
