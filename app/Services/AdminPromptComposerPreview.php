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
        $artworkTitle = '';
        if ($artworkId > 0) {
            try {
                $pdo = Database::connection();
                $stmt = $pdo->prepare("SELECT root_file, final_title, width, height, depth FROM artworks WHERE id = :id LIMIT 1");
                $stmt->execute(['id' => $artworkId]);
                $dbArtwork = $stmt->fetch();
                if ($dbArtwork) {
                    $rootFile = basename((string)($dbArtwork['root_file'] ?? ''));
                    $artworkTitle = trim((string)($dbArtwork['final_title'] ?? ''));
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
        if ($artworkTitle === '' && $rootFile !== '') {
            $artworkTitle = Display::artworkTitle($rootFile);
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
        $sizeClass = $this->artworkSizeClass($width, $height);

        // Parse context fields
        $fields = $this->parseContextFields($contextProposal);
        
        // Build context block
        $contextBlock = $this->buildContextBlock($fields);
        
        // Replace template variables
        $replacements = [
            '{{MOCKUP_CONTEXT_PROPOSAL}}' => $contextBlock,
            '{{MOCKUP_CONTEXT_NEGATIVE_PROMPT}}' => $fields['negative_prompt'],
            '{{ARTWORK_TITLE}}' => $artworkTitle,
            '{{ARTWORK_WIDTH_CM}}' => (string)$width,
            '{{ARTWORK_HEIGHT_CM}}' => (string)$height,
            '{{ARTWORK_DEPTH_CM}}' => (string)$depth,
            '{{ARTWORK_ORIENTATION}}' => $orientation,
            '{{ARTWORK_SIZE_CLASS}}' => $sizeClass,
            '{{ARTWORK_ROOT_FILE}}' => $rootFile,
            '{{CAMERA_SLOT_ID}}' => $fields['camera_slot_id'],
            '{{CAMERA_SLOT_NAME}}' => $fields['camera_slot_name'],
            '{{WORLD_MOTHER_REFERENCE_IMAGE}}' => $fields['world_mother_reference_image'],
            '{{NEGATIVE_PROMPT}}' => $fields['negative_prompt'],
        ];

        $slotFullPrompt = self::slotFullPromptTemplate($fields['camera_slot_id']);
        if ($slotFullPrompt !== '') {
            $slotFullPrompt = rtrim($slotFullPrompt)
                . "\n\n" . ArtworkPhysicalIntegrityPolicy::environmentalScaleReasoningBlock()
                . "\n\n" . self::slotFullPromptWorldMotherArtworkQuarantine();
            return $this->sanitizeRenderableMeasurementLabels(str_replace(
                array_keys($replacements),
                array_values($replacements),
                str_replace("\r\n", "\n", $slotFullPrompt)
            ));
        }

        $composed = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $normalizedAdmin
        );
        $composed = $this->sanitizeRenderableMeasurementLabels($composed);

        $composed = rtrim($composed) . "\n\n" . ArtworkPhysicalIntegrityPolicy::promptBlock(
            $width,
            $height,
            $depth,
            $orientation,
            $fields['camera_slot_id']
        );
        $worldMotherAuthorityPolicy = WorldMotherCameraAuthorityPolicy::promptBlock($fields['camera_slot_id']);
        if ($worldMotherAuthorityPolicy !== '') {
            $composed = rtrim($composed) . "\n\n" . $worldMotherAuthorityPolicy;
        }
        
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
        $cameraSlotName = $json['camera_slot_name'] ?? $proposal['camera_slot_name'] ?? '';
        $cameraSlotId = $json['camera_slot_id'] ?? $proposal['camera_slot_id'] ?? '';
        $cameraSlotGeometry = $json['camera_slot_geometry'] ?? $proposal['camera_slot_geometry'] ?? '';
        $worldMotherCategory = $json['world_mother_category'] ?? $proposal['world_mother_category'] ?? '';
        $worldMotherReferenceImage = $json['world_mother_reference_image'] ?? $proposal['world_mother_reference_image'] ?? '';
        $combinationNotes = $json['mockup_combination_notes'] ?? $proposal['mockup_combination_notes'] ?? '';
        $humanPresence = $json['human_presence'] ?? $proposal['human_presence'] ?? 'none';
        $curatorialReason = $json['curatorial_reason'] ?? $proposal['curatorial_reason'] ?? '';
        $commercialReason = $json['commercial_reason'] ?? $proposal['commercial_reason'] ?? '';
        $directWorldMotherMode = !empty($json['direct_world_mother_mode']) || !empty($proposal['direct_world_mother_mode']);
        
        // mockup_prompt
        $mockupPrompt = $json['mockup_prompt'] ?? $proposal['mockup_prompt'] ?? $json['scene_description'] ?? $proposal['scene_description'] ?? $json['scene'] ?? $proposal['scene'] ?? '';
        $mockupPrompt = $this->sanitizeMockupPromptCameraNarration((string)$mockupPrompt);
        
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
            'camera_slot_id' => trim((string)$cameraSlotId),
            'camera_slot_name' => trim((string)$cameraSlotName),
            'camera_slot_geometry' => trim((string)$cameraSlotGeometry),
            'world_mother_category' => trim((string)$worldMotherCategory),
            'world_mother_reference_image' => trim((string)$worldMotherReferenceImage),
            'mockup_combination_notes' => trim((string)$combinationNotes),
            'human_presence' => trim((string)$humanPresence),
            'curatorial_reason' => trim((string)$curatorialReason),
            'commercial_reason' => trim((string)$commercialReason),
            'mockup_prompt' => trim((string)$mockupPrompt),
            'negative_prompt' => trim((string)$negPrompt),
            'direct_world_mother_mode' => $directWorldMotherMode,
        ];
    }

    private function sanitizeMockupPromptCameraNarration(string $text): string
    {
        $text = preg_replace('/\b(?:Camera\s*(?::|view\s+is|angle\s+is|direction\s+is)|Viewed\s+from|Shot\s+from)\s+[^.]+\.?/i', '', $text);
        return trim((string)preg_replace('/\s{2,}/', ' ', (string)$text));
    }

    private function sanitizeRenderableMeasurementLabels(string $text): string
    {
        $text = preg_replace(
            '/(\*\s*depth\s*:\s*)[0-9]+(?:\.[0-9]+)?\s*cm/i',
            '$1supplied physical canvas/object depth metadata only; do not render as visible text',
            $text
        );
        $text = preg_replace(
            '/(Artwork\s+physical\s+depth\s*:\s*)[0-9]+(?:\.[0-9]+)?\s*cm/i',
            '$1supplied physical canvas/object depth metadata only; do not render as visible text',
            (string)$text
        );

        return (string)$text;
    }

    private function artworkSizeClass(float $width, float $height): string
    {
        $longestSide = max($width, $height);
        if ($longestSide <= 70.0) {
            return 'M';
        }
        if ($longestSide <= 130.0) {
            return 'L';
        }
        if ($longestSide <= 180.0) {
            return 'XL';
        }

        return 'Monumental/XXL';
    }

    public static function hasSlotFullPromptTemplate(string $slotId): bool
    {
        return self::slotFullPromptTemplate($slotId) !== '';
    }

    private static function slotFullPromptTemplate(string $slotId): string
    {
        if ($slotId === '') {
            return '';
        }

        $requestedSlotId = $slotId;
        $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'mockup_camera_slots.php';
        $config = is_file($configPath) ? require $configPath : [];
        $template = $config['slots'][$requestedSlotId]['full_prompt_template'] ?? '';

        return is_string($template) ? trim($template) : '';
    }

    private static function slotFullPromptWorldMotherArtworkQuarantine(): string
    {
        return trim(<<<'TEXT'
WORLD MOTHER ARTWORK QUARANTINE:
IMAGE 2 may contain paintings, murals, wall drawings, posters, framed images, easel canvases, sketches, portraits, figures, decorative marks, or unfinished studio artwork. Treat all of those as unsafe environmental artifacts, not as content sources.

Never copy, preserve, enlarge, translate, restyle, echo, or remix any artwork, mural, face, figure, brushwork, color composition, easel canvas, poster, frame content, or wall drawing from IMAGE 2 into IMAGE 1 or into the installed artwork. IMAGE 1 is the only artwork content allowed.

If IMAGE 2 contains a large mural, figurative wall painting, colorful wall drawing, poster cluster, or competing artwork, neutralize that area into compatible blank wall, aged plaster, shelving, stacked blank canvases, furniture, shadow, or ordinary studio clutter. Do not keep it as a second prominent artwork in the final image.

The final mockup must contain one primary artwork only: the supplied IMAGE 1 canvas/object. Any secondary canvas-like object may appear only as a blank support, turned-away canvas, neutral stretcher, or indistinct studio prop with no readable image, no face, no figure, and no decorative composition.
TEXT);
    }

    /**
     * Builds the exact subordinated context block.
     */
    private function buildContextBlock(array $fields): string
    {
        if (!empty($fields['direct_world_mother_mode'])) {
            return "MOCKUP CONTEXT PROPOSAL:\n"
                . "* World Mother Category: {$fields['world_mother_category']}\n"
                . "* World Mother Reference Image: {$fields['world_mother_reference_image']}\n"
                . "* Mode: direct world mother plus selected camera slot; no artwork analysis, title, description, curatorial reading, or context ranking was used.\n"
                . "* Space Type: {$fields['space_type']}\n"
                . "* Materials: {$fields['materials']}\n"
                . "* Lighting: {$fields['lighting']}\n"
                . "* Placement: {$fields['placement']}\n"
                . "* Camera View: {$fields['camera_view']}\n"
                . "* Camera Group: {$fields['camera_group']}\n"
                . "* Camera Distance: {$fields['camera_distance']}\n"
                . "* Camera Notes: {$fields['camera_angle_notes']}\n"
                . "* Camera Slot ID: {$fields['camera_slot_id']}\n"
                . "* Camera Slot: {$fields['camera_slot_name']}\n"
                . "* Camera Slot Geometry:\n{$fields['camera_slot_geometry']}\n"
                . "* Mockup Combination Notes: {$fields['mockup_combination_notes']}\n"
                . "* Human Presence: {$fields['human_presence']}\n"
                . "* Mockup Prompt: {$fields['mockup_prompt']}\n"
                . "* Negative Prompt: {$fields['negative_prompt']}";
        }

        return "MOCKUP CONTEXT PROPOSAL:\n"
            . "* Scene Name: {$fields['context_name']}\n"
            . "* World Mother Category: {$fields['world_mother_category']}\n"
            . "* World Mother Reference Image: {$fields['world_mother_reference_image']}\n"
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
            . "* Camera Slot ID: {$fields['camera_slot_id']}\n"
            . "* Camera Slot: {$fields['camera_slot_name']}\n"
            . "* Camera Slot Geometry:\n{$fields['camera_slot_geometry']}\n"
            . "* Mockup Combination Notes: {$fields['mockup_combination_notes']}\n"
            . "* Human Presence: {$fields['human_presence']}\n"
            . "* Curatorial Reason: {$fields['curatorial_reason']}\n"
            . "* Commercial Reason: {$fields['commercial_reason']}\n"
            . "* Mockup Prompt: {$fields['mockup_prompt']}\n"
            . "* Negative Prompt: {$fields['negative_prompt']}";
    }

}
