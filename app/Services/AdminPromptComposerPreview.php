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

        // 1.2 Fallback to Artwork Details in database if dimensions not found in CORE JSON
        if ($artworkId > 0 && ($width === null || $height === null || $depth === null)) {
            try {
                $pdo = Database::connection();
                $stmt = $pdo->prepare("SELECT width, height, depth FROM artworks WHERE id = :id LIMIT 1");
                $stmt->execute(['id' => $artworkId]);
                $dbArtwork = $stmt->fetch();
                if ($dbArtwork) {
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

        // Check if the ADMIN master prompt template contains variables for physical size
        $hasDimensionVariables = (strpos($adminPrompt, '{{ARTWORK_WIDTH_CM}}') !== false
            || strpos($adminPrompt, '{{ARTWORK_HEIGHT_CM}}') !== false
            || strpos($adminPrompt, '{{ARTWORK_DEPTH_CM}}') !== false);

        // First replace the variables if present
        $normalizedAdmin = str_replace(
            ['{{ARTWORK_WIDTH_CM}}', '{{ARTWORK_HEIGHT_CM}}', '{{ARTWORK_DEPTH_CM}}'],
            [(string)$width, (string)$height, (string)$depth],
            $normalizedAdmin
        );

        // Build the dynamic scale rules text blocks
        $scaleCorrectionRule = "Scale correction rule:\n"
            . "The artwork must be rendered at its actual physical size according to the Core JSON dimensions. Do not enlarge the canvas for visual dominance or dramatic impact. When a human figure is present, use the person as the primary scale reference. The artwork should appear as a real collectible artwork of the stated dimensions, not as an oversized installation piece. The human figure may be naturally cropped in close-up, near close-up, high-angle, low-angle, or low floor compositions. Do not enlarge the artwork or canvas to force full-body human figures, furniture, or the entire room into the frame.";

        if ($hasDimensionVariables) {
            // Do not inject a duplicate size statement since the variables were already present (and replaced)
            $dimensionsBlock = $scaleCorrectionRule;
        } else {
            // Inject the physical size sentence explicitly along with the scale rules
            $dimensionsLine = "The artwork physical size is {$width} cm wide × {$height} cm high × {$depth} cm deep.";
            $dimensionsBlock = "Artwork physical dimensions:\n"
                . $dimensionsLine . "\n\n"
                . $scaleCorrectionRule;
        }

        $negativeScaleRule = "Negative scale rule:\n"
            . "No oversized artwork. No enlarged canvas for impact. No gallery-installation scale unless the Core JSON dimensions justify it. No artwork larger than its declared physical dimensions when compared to a standing person, furniture, windows, doorways, floorboards, or wall height. Do not create a monumental canvas unless the actual artwork dimensions justify it. No mural-scale painting. No oversized installation artwork. No physically impossible scale compared with the human figure, furniture, doors, windows, floorboards, or wall height.";

        $target = '* Keep the physical scale believable.';
        $replacement = "* Keep the physical scale believable.\n\n"
            . "  " . str_replace("\n", "\n  ", $dimensionsBlock) . "\n\n"
            . "  " . str_replace("\n", "\n  ", $negativeScaleRule);

        if (strpos($normalizedAdmin, $target) !== false) {
            $normalizedAdmin = str_replace($target, $replacement, $normalizedAdmin);
        } else {
            $header = "PHYSICAL ARTWORK RULES:";
            if (strpos($normalizedAdmin, $header) !== false) {
                $normalizedAdmin = str_replace(
                    $header, 
                    $header . "\n\n" . $dimensionsBlock . "\n\n" . $negativeScaleRule, 
                    $normalizedAdmin
                );
            }
        }

        // Parse context fields
        $fields = $this->parseContextFields($contextProposal);
        
        // Build context block
        $contextBlock = $this->buildContextBlock($fields);
        
        // Perform replacement exactly
        $composed = str_replace('{{MOCKUP_CONTEXT_PROPOSAL}}', $contextBlock, $normalizedAdmin);
        
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
        
        // scene_description maps to mockup_prompt, scene or scene_description
        $sceneDesc = $json['scene_description'] ?? $proposal['scene_description'] ?? $json['scene'] ?? $proposal['scene'] ?? $json['mockup_prompt'] ?? $proposal['mockup_prompt'] ?? '';
        
        $materials = $json['materials'] ?? $proposal['materials'] ?? '';
        if (is_array($materials)) {
            $materials = implode(', ', $materials);
        }

        $lighting = $json['lighting'] ?? $proposal['lighting'] ?? '';
        $placement = $json['placement'] ?? $proposal['placement'] ?? '';
        $cameraView = $json['camera_view'] ?? $proposal['camera_view'] ?? '';
        $cameraDistance = $json['camera_distance'] ?? $proposal['camera_distance'] ?? '';
        $cameraNotes = $json['camera_angle_notes'] ?? $proposal['camera_angle_notes'] ?? $json['camera_notes'] ?? $proposal['camera_notes'] ?? '';

        return [
            'context_name' => trim((string)$name),
            'purpose' => trim((string)$purpose),
            'scene_description' => trim((string)$sceneDesc),
            'materials' => trim((string)$materials),
            'lighting' => trim((string)$lighting),
            'placement' => trim((string)$placement),
            'camera_view' => trim((string)$cameraView),
            'camera_distance' => trim((string)$cameraDistance),
            'camera_angle_notes' => trim((string)$cameraNotes),
        ];
    }

    /**
     * Builds the exact subordinated context block.
     */
    private function buildContextBlock(array $fields): string
    {
        return "MOCKUP CONTEXT PROPOSAL:\n"
            . "Use the following context only as subordinated scene data. These values define the\n"
            . "environment, placement, lighting and camera direction, but they do not override the\n"
            . "artwork fidelity rules, scale rules, human figure policy, camera proximity rules, or\n"
            . "negative directives stated in the admin master prompt.\n\n"
            . "* Scene Name: {$fields['context_name']}\n"
            . "* Purpose: {$fields['purpose']}\n"
            . "* Scene Description: {$fields['scene_description']}\n"
            . "* Materials: {$fields['materials']}\n"
            . "* Lighting: {$fields['lighting']}\n"
            . "* Placement: {$fields['placement']}\n"
            . "* Camera View: {$fields['camera_view']}\n"
            . "* Camera Distance: {$fields['camera_distance']}\n"
            . "* Camera Notes: {$fields['camera_angle_notes']}";
    }
}
