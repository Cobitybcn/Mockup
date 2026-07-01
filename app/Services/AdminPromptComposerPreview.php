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

        $composed = rtrim($composed) . "\n\n" . ArtworkScalePolicy::promptBlock($width, $height, $depth, $orientation);
        $dominancePolicy = ArtworkDominancePolicy::promptBlock($fields['camera_slot_id']);
        if ($dominancePolicy !== '') {
            $composed = rtrim($composed) . "\n\n" . $dominancePolicy;
        }
        $edgePolicy = ArtworkEdgePolicy::promptBlock($fields['camera_slot_id']);
        if ($edgePolicy !== '') {
            $composed = rtrim($composed) . "\n\n" . $edgePolicy;
        }
        $detailCropPolicy = ArtworkDetailCropPolicy::promptBlock($fields['camera_slot_id'], $orientation);
        if ($detailCropPolicy !== '') {
            $composed = rtrim($composed) . "\n\n" . $detailCropPolicy;
        }
        $worldMotherAuthorityPolicy = WorldMotherCameraAuthorityPolicy::promptBlock($fields['camera_slot_id']);
        if ($worldMotherAuthorityPolicy !== '') {
            $composed = rtrim($composed) . "\n\n" . $worldMotherAuthorityPolicy;
        }

        $detailOverride = $this->detailSlotCompositionOverride(
            $fields['camera_slot_id'],
            $orientation
        );
        if ($detailOverride !== '') {
            $composed = rtrim($composed) . "\n\n" . $detailOverride;
        }
        $floorLeaningOverride = $this->floorLeaningSlotOverride(
            $fields['camera_slot_id'],
            $orientation
        );
        if ($floorLeaningOverride !== '') {
            $composed = rtrim($composed) . "\n\n" . $floorLeaningOverride;
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

    private function detailSlotCompositionOverride(string $cameraSlotId, string $orientation): string
    {
        $detailSlots = [
            'detalle_textura_lienzo',
            'borde_canvas_closeup',
            'esquina_obra_perspectiva_extrema',
            'rasante_superficie_pintura',
        ];
        if (!in_array($cameraSlotId, $detailSlots, true)) {
            return '';
        }

        $formatRule = match ($orientation) {
            'portrait' => 'Because the artwork is portrait, any visible canvas fragment must still feel like part of a taller-than-wide physical artwork. Do not square it, widen it, compress its height, or complete the visible fragment into a square painting.',
            'landscape' => 'Because the artwork is landscape, any visible canvas fragment must still feel like part of a wider-than-tall physical artwork. Do not make it portrait, square it, compress its width, or complete the visible fragment into a different format.',
            default => 'Because the artwork is square, any visible canvas fragment must still feel like part of a square physical artwork. Do not stretch it into portrait or landscape.',
        };

        $slotRule = $cameraSlotId === 'borde_canvas_closeup'
            ? 'For Borde de Canvas Close-up, prioritize the physical side edge, canvas thickness, wall contact, cast shadow, and a faithful partial slice of the painted face. The whole artwork should usually not be visible.'
            : 'For this material-detail camera slot, prioritize a faithful physical fragment of the artwork surface over showing the whole artwork.';

        return trim(<<<TEXT
SELECTED DETAIL CAMERA OVERRIDE

This selected camera slot intentionally allows camera-frame cropping of the artwork. This overrides generic "do not crop", "no cropped artwork", and "show the whole artwork" instructions only for photographic framing.

Do not crop, resize, repaint, extend, redesign, or alter the artwork itself. The central ARTWORK SCALE POLICY owns the true physical size and orientation; this detail camera only controls photographic framing of a faithful fragment.

{$formatRule}

{$slotRule}
TEXT);
    }

    private function floorLeaningSlotOverride(string $cameraSlotId, string $orientation): string
    {
        if ($cameraSlotId !== 'obra_apoyada_suelo_7_8') {
            return '';
        }

        return trim(<<<TEXT
SELECTED FLOOR-LEANING ARTWORK OVERRIDE

This selected camera slot requires a real leaning artwork installation. The artwork is not hanging and not wall-mounted. It may lean against a real wall or against a real stable support object when that object could plausibly hold a canvas in an atelier, studio, storage room, or collector preview.

Place the real physical artwork with believable gravity: its bottom edge must rest on the real floor or on a clearly stable low support surface, and its back upper edge must lean gently against a wall or load-bearing object at about 5-12 degrees. The floor/support/wall relationship must be physically legible through contact shadows, grounded bottom contact, and coherent perspective.

The central ARTWORK SCALE POLICY owns physical size, orientation ({$orientation}), aspect ratio, and scale. This floor-leaning override only controls installation physics, contact, support, and gravity. It must not reinterpret the artwork as a monumental billboard, room divider, oversized slab, stage prop, or architectural panel.

Do not invent a giant plinth, oversized display block, impossible platform, or arbitrary support just to hold the artwork. If the artwork leans on an object, the object must be real, stable, correctly scaled, visually connected to the floor, and coherent with the room; the artwork contact point must be visible or strongly implied.
TEXT);
    }
}
