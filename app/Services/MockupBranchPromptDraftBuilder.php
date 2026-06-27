<?php
declare(strict_types=1);

class MockupBranchPromptDraftBuilder
{
    /**
     * Builds mockup prompt drafts for an artwork based on its CORE JSON and branches JSON.
     *
     * @param int $artworkId The ID of the artwork.
     * @return array The generated mockup prompt drafts structure.
     * @throws RuntimeException If the CORE JSON is missing or files cannot be read.
     */
    public function buildForArtwork(int $artworkId): array
    {
        Logger::log("MOCKUP_PROMPT_DRAFT_BUILD_START for artwork_id: {$artworkId}", 'info');

        $baseDir = dirname(__DIR__, 2);
        $corePath = $baseDir . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . $artworkId . '.core.json';
        $branchesPath = $baseDir . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'mockup-branches' . DIRECTORY_SEPARATOR . $artworkId . '.branches.json';

        // 1. Read CORE JSON
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

        // 2. Read Mockup Branches JSON (with auto-generation fallback)
        if (!is_file($branchesPath)) {
            Logger::log("Branches JSON missing for artwork ID {$artworkId}, attempting to build via MockupBranchContextBuilder", 'info');
            require_once __DIR__ . '/MockupBranchContextBuilder.php';
            $contextBuilder = new MockupBranchContextBuilder();
            $branchesJson = $contextBuilder->buildForArtwork($artworkId);
        } else {
            $branchesContent = file_get_contents($branchesPath);
            if ($branchesContent === false) {
                throw new RuntimeException("Failed to read branches JSON file for artwork ID {$artworkId}");
            }
            $branchesJson = json_decode($branchesContent, true);
            if (!is_array($branchesJson)) {
                throw new RuntimeException("Failed to parse branches JSON file for artwork ID {$artworkId}");
            }
        }

        // 3. Extract dimensions and physical reference
        $artworkData = $coreJson['artwork'] ?? [];
        $dims = $artworkData['dimensions'] ?? [];
        $width = $dims['width_cm'] ?? null;
        $height = $dims['height_cm'] ?? null;
        $depth = $dims['depth_cm'] ?? null;
        $orientation = $dims['orientation'] ?? 'unknown';

        $physicalRef = $coreJson['physical_artwork_reference'] ?? [];
        $objectType = $physicalRef['object_type'] ?? 'stretched_canvas';
        $hasVisibleEdges = $physicalRef['has_visible_edges'] ?? true;
        $paintContinuesOnEdges = $physicalRef['paint_continues_on_edges'] ?? null;
        $edgeFinish = $physicalRef['edge_finish'] ?? null;

        if ($depth === null) {
            $depth = $physicalRef['depth_cm'] ?? 4;
        }

        // 4. Loop branches and generate drafts
        $branches = $branchesJson['branches'] ?? [];
        $promptDrafts = [];
        $warnings = [];

        if (empty($branches)) {
            $warnings[] = "No branches found in branches JSON.";
        }

        foreach ($branches as $index => $branch) {
            $branchId = $branch['branch_id'] ?? ($index + 1);
            $contextName = $branch['source_title'] ?? 'Unnamed Branch';
            $sourceSubtitle = $branch['source_subtitle'] ?? '';
            $sourceDescription = $branch['source_description'] ?? '';

            // Resolve root view strategy for the branch
            $strategy = $branch['root_view_strategy'] ?? [];
            $mockupDirection = $branch['mockup_direction'] ?? [];
            
            $cameraBias = strtolower(trim((string)($mockupDirection['camera_bias'] ?? '')));
            $resolvedFile = null;
            $resolvedType = 'fallback';
            $fallbackUsed = false;

            // Resolve based on camera bias keywords
            if (str_contains($cameraBias, 'frontal')) {
                if (isset($strategy['frontal']) && $strategy['frontal'] !== null) {
                    $resolvedFile = $strategy['frontal'];
                    $resolvedType = 'frontal';
                } else {
                    $resolvedFile = $strategy['fallback'] ?? null;
                    $resolvedType = 'fallback';
                    $fallbackUsed = true;
                }
            } elseif (str_contains($cameraBias, 'left') || str_contains($cameraBias, 'oblique')) {
                if (isset($strategy['three_quarter_left']) && $strategy['three_quarter_left'] !== null) {
                    $resolvedFile = $strategy['three_quarter_left'];
                    $resolvedType = 'three_quarter_left';
                } elseif (isset($strategy['three_quarter_right']) && $strategy['three_quarter_right'] !== null) {
                    $resolvedFile = $strategy['three_quarter_right'];
                    $resolvedType = 'three_quarter_right';
                } else {
                    $resolvedFile = $strategy['fallback'] ?? null;
                    $resolvedType = 'fallback';
                    $fallbackUsed = true;
                }
            } elseif (str_contains($cameraBias, 'right')) {
                if (isset($strategy['three_quarter_right']) && $strategy['three_quarter_right'] !== null) {
                    $resolvedFile = $strategy['three_quarter_right'];
                    $resolvedType = 'three_quarter_right';
                } else {
                    $resolvedFile = $strategy['fallback'] ?? null;
                    $resolvedType = 'fallback';
                    $fallbackUsed = true;
                }
            } else {
                $resolvedFile = $strategy['fallback'] ?? null;
                $resolvedType = 'fallback';
                $fallbackUsed = true;
            }

            if ($resolvedFile === null) {
                $resolvedFile = $strategy['fallback'] ?? null;
                $resolvedType = 'fallback';
                $fallbackUsed = true;
            }

            $resolvedRootView = [
                'view_type' => $resolvedType,
                'file' => $resolvedFile !== null ? basename((string)$resolvedFile) : null,
                'fallback_used' => $fallbackUsed,
            ];

            // Resolve context parameters
            $atmosphere = $mockupDirection['atmosphere'] ?? 'refined and contemplative';
            $spaceCharacter = $mockupDirection['space_character'] ?? 'gallery/residential space';
            $lightingBias = $mockupDirection['lighting_bias'] ?? 'soft natural light';
            $materialAffinity = $mockupDirection['material_affinity'] ?? [];
            $materialsStr = !empty($materialAffinity) ? implode(', ', $materialAffinity) : 'refined materials';
            $commercialPositioning = $mockupDirection['commercial_positioning'] ?? 'premium exhibition';

            // Build Prompt Blocks
            $blocks = [
                'artwork_reference' => sprintf(
                    "Use the resolved root view file '%s' as the authoritative physical reference for the artwork. The artwork's original composition, proportions, colors, marks, textures, and overall visual identity must be preserved exactly, with no modifications.",
                    $resolvedRootView['file'] ?? ''
                ),
                'physical_artwork_spec' => sprintf(
                    "The physical object is a '%s' with dimensions of %scm width × %scm height × %scm depth (%s orientation). Visible edges: %s. Edge finish: %s. Paint continues on edges: %s.",
                    $objectType,
                    $width !== null ? (string)$width : 'unspecified',
                    $height !== null ? (string)$height : 'unspecified',
                    $depth !== null ? (string)$depth : '4',
                    $orientation,
                    $hasVisibleEdges ? 'Yes' : 'No',
                    $edgeFinish !== null ? (string)$edgeFinish : 'not specified',
                    $paintContinuesOnEdges === true ? 'Yes' : ($paintContinuesOnEdges === false ? 'No' : 'not detected')
                ),
                'scene_context' => sprintf(
                    "The scene is a %s with a %s atmosphere. Context description: %s. %s",
                    $spaceCharacter,
                    $atmosphere,
                    $sourceSubtitle,
                    $sourceDescription
                ),
                'artwork_placement' => sprintf(
                    "The artwork must be hung flat and centered on a vertical wall. All edges of the canvas must be visible to show its %scm depth, casting realistic, soft shadows onto the wall.",
                    $depth !== null ? (string)$depth : '4'
                ),
                'camera_direction' => sprintf(
                    "The camera view is biased towards a %s angle. The composition must be a close-up or near close-up, focusing on the artwork as the primary visual subject. Avoid wide shots, fisheye lenses, and distorted room architecture.",
                    $cameraBias !== '' ? $cameraBias : 'frontal'
                ),
                'scale_direction' => sprintf(
                    "Maintain realistic physical scale. The artwork's physical dimensions of %scm width by %scm height must look accurate and proportionate relative to its immediate surroundings.",
                    $width !== null ? (string)$width : 'unspecified',
                    $height !== null ? (string)$height : 'unspecified'
                ),
                'lighting_direction' => sprintf(
                    "The lighting in the room is %s. The light must cast subtle, soft shadows from the canvas onto the wall to emphasize the canvas depth of %scm.",
                    $lightingBias,
                    $depth !== null ? (string)$depth : '4'
                ),
                'material_direction' => sprintf(
                    "The materials in the space feature %s, providing a clean and tactile texture to the surrounding environment.",
                    $materialsStr
                ),
                'commercial_direction' => sprintf(
                    "The environment is styled for a %s presentation, avoiding a generic stock-room appearance.",
                    $commercialPositioning
                ),
                'negative_prompt' => "Avoid distorted perspective, cropped edges, altered colors, simplified artwork, wide shots (unless explicitly requested), fisheye perspective, distorted room architecture, generic stock-room appearance, excessive depth-of-field blur, or any redesign/repainting of the artwork."
            ];

            // Build final prompt
            $finalPrompt = "";
            $finalPrompt .= "[ARTWORK REFERENCE]\n" . $blocks['artwork_reference'] . "\n\n";
            $finalPrompt .= "[PHYSICAL ARTWORK SPECIFICATION]\n" . $blocks['physical_artwork_spec'] . "\n\n";
            $finalPrompt .= "[SCENE CONTEXT]\n" . $blocks['scene_context'] . "\n\n";
            $finalPrompt .= "[ARTWORK PLACEMENT]\n" . $blocks['artwork_placement'] . "\n\n";
            $finalPrompt .= "[CAMERA DIRECTION]\n" . $blocks['camera_direction'] . "\n\n";
            $finalPrompt .= "[SCALE DIRECTION]\n" . $blocks['scale_direction'] . "\n\n";
            $finalPrompt .= "[LIGHTING DIRECTION]\n" . $blocks['lighting_direction'] . "\n\n";
            $finalPrompt .= "[MATERIAL DIRECTION]\n" . $blocks['material_direction'] . "\n\n";
            $finalPrompt .= "[COMMERCIAL DIRECTION]\n" . $blocks['commercial_direction'] . "\n\n";
            $finalPrompt .= "[NEGATIVE PROMPT]\n" . $blocks['negative_prompt'];

            $promptDrafts[] = [
                'branch_index' => $branchId,
                'context_name' => $contextName,
                'source_branch_label' => "Branch {$branchId}: {$contextName}",
                'resolved_root_view' => $resolvedRootView,
                'prompt_blocks' => $blocks,
                'final_prompt' => $finalPrompt,
            ];
        }

        $result = [
            'schema' => 'mockup_prompt_drafts.v1',
            'artwork_id' => (int)$artworkId,
            'source_core_json' => "analysis/core/{$artworkId}.core.json",
            'source_branches_json' => "analysis/mockup-branches/{$artworkId}.branches.json",
            'generated_at' => date(DATE_ATOM),
            'generation_mode' => 'read_only_prompt_draft',
            'prompt_drafts' => $promptDrafts,
            'warnings' => $warnings,
        ];

        // 5. Write to analysis/mockup-prompt-drafts/{artwork_id}.prompt-drafts.json
        $promptDraftsDir = $baseDir . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'mockup-prompt-drafts';
        if (!is_dir($promptDraftsDir)) {
            if (!mkdir($promptDraftsDir, 0775, true) && !is_dir($promptDraftsDir)) {
                throw new RuntimeException("Failed to create folder: {$promptDraftsDir}");
            }
        }

        $outputPath = $promptDraftsDir . DIRECTORY_SEPARATOR . $artworkId . '.prompt-drafts.json';
        $jsonString = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonString === false) {
            throw new RuntimeException("Failed to encode prompt draft JSON.");
        }

        if (file_put_contents($outputPath, $jsonString) === false) {
            throw new RuntimeException("Failed to write prompt draft JSON to disk at: {$outputPath}");
        }

        Logger::log("MOCKUP_PROMPT_DRAFT_BUILD_SUCCESS for artwork_id: {$artworkId}. Written to {$outputPath}", 'info');
        return $result;
    }
}
