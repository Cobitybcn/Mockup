<?php
declare(strict_types=1);

class AdminPromptComposerPreview
{
    /**
     * Composes the final prompt by loading the sovereign Admin V7 prompt
     * and injecting the context block.
     *
     * @param array $contextProposal Raw row/array from mockup_contexts
     * @return string
     * @throws RuntimeException
     */
    public function compose(array $contextProposal): string
    {
        $adminPrompt = PromptSettings::mockupFinalRequest();
        
        // Normalize any carriage returns first for check
        $normalizedAdmin = str_replace("\r\n", "\n", $adminPrompt);
        
        if (strpos($normalizedAdmin, '{{MOCKUP_CONTEXT_PROPOSAL}}') === false) {
            throw new RuntimeException('The Admin V7 prompt template does not contain the required {{MOCKUP_CONTEXT_PROPOSAL}} placeholder.');
        }

        // 1. Get dynamic scale rules based on artwork dimensions from CORE JSON or database details
        $artworkId = isset($contextProposal['artwork_id']) ? (int)$contextProposal['artwork_id'] : 0;
        $width = null;
        $height = null;
        $depth = null;
        $fallbackUsed = false;
        $fallbackReason = [];

        // 1.1 Try to read from CORE JSON
        if ($artworkId > 0) {
            $corePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . $artworkId . '.core.json';
            if (is_file($corePath)) {
                $coreContent = file_get_contents($corePath);
                if ($coreContent !== false) {
                    $coreJson = json_decode($coreContent, true);
                    if (is_array($coreJson)) {
                        // Read from artwork -> dimensions
                        $artworkData = $coreJson['artwork'] ?? [];
                        $dims = $artworkData['dimensions'] ?? [];
                        
                        if (isset($dims['width_cm']) && trim((string)$dims['width_cm']) !== '') {
                            $width = (float)$dims['width_cm'];
                        }
                        if (isset($dims['height_cm']) && trim((string)$dims['height_cm']) !== '') {
                            $height = (float)$dims['height_cm'];
                        }
                        if (isset($dims['depth_cm']) && trim((string)$dims['depth_cm']) !== '') {
                            $depth = (float)$dims['depth_cm'];
                        }

                        // Preferred source check: physical_artwork_reference
                        $physRef = $coreJson['physical_artwork_reference'] ?? [];
                        if (isset($physRef['depth_cm']) && trim((string)$physRef['depth_cm']) !== '') {
                            $depth = (float)$physRef['depth_cm'];
                        }
                    }
                }
            }
        }

        // 1.2 Load root_file from database and fallback to Artwork Details dimensions if not found in CORE JSON
        $rootFile = '';
        if ($artworkId > 0) {
            try {
                $pdo = Database::connection();
                $stmt = $pdo->prepare("SELECT root_file, width, height, depth FROM artworks WHERE id = :id LIMIT 1");
                $stmt->execute(['id' => $artworkId]);
                $dbArtwork = $stmt->fetch();
                if ($dbArtwork) {
                    $rootFile = basename((string)($dbArtwork['root_file'] ?? ''));
                    if ($width === null && isset($dbArtwork['width']) && (float)$dbArtwork['width'] > 0) {
                        $width = (float)$dbArtwork['width'];
                        $fallbackUsed = true;
                        $fallbackReason[] = "width_cm loaded from database (artworks.width)";
                    }
                    if ($height === null && isset($dbArtwork['height']) && (float)$dbArtwork['height'] > 0) {
                        $height = (float)$dbArtwork['height'];
                        $fallbackUsed = true;
                        $fallbackReason[] = "height_cm loaded from database (artworks.height)";
                    }
                    if ($depth === null && isset($dbArtwork['depth']) && (float)$dbArtwork['depth'] > 0) {
                        $depth = (float)$dbArtwork['depth'];
                        $fallbackUsed = true;
                        $fallbackReason[] = "depth_cm loaded from database (artworks.depth)";
                    }
                }
            } catch (Throwable $dbEx) {
                // Ignore DB access issues but fallback values will apply
            }
        }

        // 1.3 Fallback only if missing
        if ($width === null || $width <= 0) {
            $width = 120.0;
            $fallbackUsed = true;
            $fallbackReason[] = "width_cm defaulted to 120 cm";
            Logger::log("Fallback warning: artwork_id={$artworkId} was missing width_cm. Defaulted to 120 cm.", "warning");
        }
        if ($height === null || $height <= 0) {
            $height = 80.0;
            $fallbackUsed = true;
            $fallbackReason[] = "height_cm defaulted to 80 cm";
            Logger::log("Fallback warning: artwork_id={$artworkId} was missing height_cm. Defaulted to 80 cm.", "warning");
        }
        if ($depth === null || $depth <= 0) {
            $depth = 4.0;
            $fallbackUsed = true;
            $fallbackReason[] = "depth_cm defaulted to 4 cm";
            Logger::log("Fallback warning: artwork_id={$artworkId} was missing depth_cm. Defaulted to 4 cm.", "warning");
        }

        if ($fallbackUsed) {
            $reasonStr = implode(', ', $fallbackReason);
            Logger::log("Artwork dimensions fallback used for artwork_id={$artworkId}. Reasons: {$reasonStr}", "warning");
        }

        // Calculate orientation
        $orientation = 'landscape';
        if ($width > $height) {
            $orientation = 'landscape';
        } elseif ($width < $height) {
            $orientation = 'portrait';
        } else {
            $orientation = 'square';
        }

        // Parse context fields
        $fields = $this->parseContextFields($contextProposal);
        
        // Build context block
        $contextBlock = $this->buildContextBlock($fields);
        
        // Replace template variables
        $replacements = [
            '{{MOCKUP_CONTEXT_PROPOSAL}}' => $contextBlock,
            '{{MOCKUP_CONTEXT_NEGATIVE_PROMPT}}' => $fields['negative_prompt'],
            '{{ARTWORK_WIDTH_CM}}' => (string)$width,
            '{{ARTWORK_HEIGHT_CM}}' => (string)$height,
            '{{ARTWORK_DEPTH_CM}}' => (string)$depth,
            '{{ARTWORK_ORIENTATION}}' => $orientation,
            '{{ARTWORK_ROOT_FILE}}' => $rootFile,
        ];

        $composed = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $normalizedAdmin
        );
        
        return $composed;
    }

    /**
     * Parses the context proposal fields from a flat array or DB row.
     */
    private function parseContextFields(array $proposal): array
    {
        // If context_json is present as string, decode it
        $json = [];
        if (isset($proposal['context_json']) && is_string($proposal['context_json'])) {
            $decoded = json_decode($proposal['context_json'], true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        // Merge fields logically: row level or json level
        $name = $proposal['context_name'] ?? $json['context_name'] ?? $proposal['name'] ?? $json['name'] ?? '';
        $purpose = $json['context_role'] ?? $proposal['context_role'] ?? $proposal['purpose'] ?? $json['purpose'] ?? '';
        $spaceType = $json['space_type'] ?? $proposal['space_type'] ?? '';
        $atmosphere = $json['atmosphere'] ?? $proposal['atmosphere'] ?? '';
        
        $materials = $json['materials'] ?? $proposal['materials'] ?? '';
        if (is_array($materials)) {
            $materials = implode(', ', $materials);
        }

        $lighting = $json['lighting'] ?? $proposal['lighting'] ?? '';
        $placement = $json['placement'] ?? $proposal['placement'] ?? '';
        $cameraView = $json['camera_view_expected'] ?? $proposal['camera_view_expected'] ?? $json['camera_view'] ?? $proposal['camera_view'] ?? '';
        $cameraGroup = $json['camera_group'] ?? $proposal['camera_group'] ?? $json['camera_group_expected'] ?? $proposal['camera_group_expected'] ?? '';
        $cameraDistance = $json['camera_distance_expected'] ?? $proposal['camera_distance_expected'] ?? $json['camera_distance'] ?? $proposal['camera_distance'] ?? '';
        $cameraNotes = $json['camera_angle_notes_expected'] ?? $proposal['camera_angle_notes_expected'] ?? $json['camera_angle_notes'] ?? $proposal['camera_angle_notes'] ?? $json['camera_notes'] ?? $proposal['camera_notes'] ?? '';
        $humanPresence = $json['human_presence'] ?? $proposal['human_presence'] ?? 'none';
        $curatorialReason = $json['curatorial_reason'] ?? $proposal['curatorial_reason'] ?? '';
        $commercialReason = $json['commercial_reason'] ?? $proposal['commercial_reason'] ?? '';
        
        // mockup_prompt
        $mockupPrompt = $json['mockup_prompt'] ?? $proposal['mockup_prompt'] ?? $json['scene_description'] ?? $proposal['scene_description'] ?? $json['scene'] ?? $proposal['scene'] ?? '';
        
        // negative_prompt
        $negPrompt = $json['negative_prompt'] ?? $proposal['negative_prompt'] ?? $proposal['prompt_negative'] ?? $json['prompt_negative'] ?? '';

        return [
            'context_name' => trim((string)$name),
            'purpose' => trim((string)$purpose),
            'space_type' => trim((string)$spaceType),
            'atmosphere' => trim((string)$atmosphere),
            'materials' => trim((string)$materials),
            'lighting' => trim((string)$lighting),
            'placement' => trim((string)$placement),
            'camera_view' => trim((string)$cameraView),
            'camera_group' => trim((string)$cameraGroup),
            'camera_distance' => trim((string)$cameraDistance),
            'camera_angle_notes' => trim((string)$cameraNotes),
            'human_presence' => trim((string)$humanPresence),
            'curatorial_reason' => trim((string)$curatorialReason),
            'commercial_reason' => trim((string)$commercialReason),
            'mockup_prompt' => trim((string)$mockupPrompt),
            'negative_prompt' => trim((string)$negPrompt),
        ];
    }

    /**
     * Builds the exact subordinated context block.
     */
    private function buildContextBlock(array $fields): string
    {
        return "MOCKUP CONTEXT PROPOSAL:\n"
            . "* Scene Name: {$fields['context_name']}\n"
            . "* Purpose: {$fields['purpose']}\n"
            . "* Space Type: {$fields['space_type']}\n"
            . "* Atmosphere: {$fields['atmosphere']}\n"
            . "* Materials: {$fields['materials']}\n"
            . "* Lighting: {$fields['lighting']}\n"
            . "* Placement: {$fields['placement']}\n"
            . "* Camera View: {$fields['camera_view']}\n"
            . "* Camera Group: {$fields['camera_group']}\n"
            . "* Camera Distance: {$fields['camera_distance']}\n"
            . "* Camera Notes: {$fields['camera_angle_notes']}\n"
            . "* Human Presence: {$fields['human_presence']}\n"
            . "* Curatorial Reason: {$fields['curatorial_reason']}\n"
            . "* Commercial Reason: {$fields['commercial_reason']}\n"
            . "* Mockup Prompt: {$fields['mockup_prompt']}\n"
            . "* Negative Prompt: {$fields['negative_prompt']}";
    }
}
