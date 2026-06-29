<?php
declare(strict_types=1);

require_once __DIR__ . '/MockupCameraArchetypeResolver.php';
require_once __DIR__ . '/MockupContextWorldRegistry.php';
require_once __DIR__ . '/MockupContextIdentity.php';
require_once __DIR__ . '/MockupVitalPresenceResolver.php';

class MockupContextEngine
{
    private GeminiImageClient $client;

    public function __construct(?GeminiImageClient $client = null)
    {
        $this->client = $client ?: new GeminiImageClient();
    }

    /**
     * Fase 1: Analizar la obra y proponer contextos dinámicos usando Gemini
     */
    public function analyzeArtworkContext(string $imagePath, array $metadata): array
    {
        if (!is_file($imagePath)) {
            throw new RuntimeException('No se encontró la imagen de la obra raíz para analizar.');
        }

        $imageMeta = $this->imageMeta($imagePath, $metadata);

        if (PromptSettings::mockupContextCount() === 6) {
            return $this->analyzeArtworkContextWorldIsolated($imagePath, $metadata, $imageMeta);
        }

        $prompt = $this->buildAnalysisPrompt($metadata, $imageMeta);
        $this->saveDebugPrompt($imagePath, $prompt);

        $parts = [
            $this->client->textPart($prompt),
            $this->client->imagePart($imagePath),
        ];

        $lastError = null;
        $rawText = '';

        // Intentar la llamada usando los modelos de Gemini con reintento implícito
        foreach (['gemini-2.5-flash'] as $model) {
            try {
                $rawText = $this->client->generateText($parts, $model);
                $this->saveDebugText($imagePath, $rawText, 'raw');
                $json = $this->extractJson($rawText);
                $this->logRawJsonDebug($json);
                $profile = json_decode($json, true);

                if (!is_array($profile) || empty($profile['contextual_proposals'])) {
                    throw new RuntimeException('Gemini no devolvió un JSON con propuestas válidas.');
                }

                $normalized = $this->normalizeAnalysisResponse($profile, $imageMeta);
                $normalized['prompt_version'] = PromptSettings::artworkAnalysisPromptVersion();
                // Preserve the image path so generateMockupPrompts() can call imageMeta() correctly.
                $normalized['image_path'] = $imagePath;
                $expectedCount = PromptSettings::mockupContextCount();
                $actualCount = count((array)($normalized['contextual_proposals'] ?? []));
                if ($actualCount < $expectedCount) {
                    throw new RuntimeException("Gemini devolvio {$actualCount} propuestas, pero ADMIN solicita {$expectedCount}.");
                }

                return $normalized;
            } catch (Throwable $e) {
                error_log("Model {$model} failed in analyzeArtworkContext: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
                $lastError = $e;
            }
        }

        throw $lastError ?: new RuntimeException('Fallo al analizar la obra con Gemini.');
    }

    /**
     * Fase 2: Generar prompts específicos para cada propuesta e insertar en BD
     */
    public function generateMockupPrompts(int $artworkId, array $analysisData, array $artworkMetadata): array
    {
        $imagePath = $analysisData['image_path'] ?? '';
        $imageMeta = $this->imageMeta($imagePath, $artworkMetadata);

        $suggestedTitles = $this->normalizeSuggestedTitles($analysisData['suggested_titles'] ?? []);
        $artworkAnalysis = $analysisData['artwork_analysis'] ?? [];
        $proposals = $analysisData['contextual_proposals'] ?? [];

        $limit = PromptSettings::mockupContextCount();
        $proposals = array_slice($proposals, 0, $limit);

        // Run non-blocking camera distribution validation on original proposals
        $this->validateCameraDistribution($proposals, $limit);

        $deterministicSpecs = [
            1 => [
                'camera_slot' => 1,
                'camera_group_expected' => 'soft_editorial_left_oblique_non_frontal',
                'camera_view_expected' => 'soft editorial left-oblique physical view, 15–20 degrees, clearly non-frontal',
                'camera_distance_expected' => 'close or medium-close premium commercial presentation',
                'camera_angle_notes_expected' => 'A soft editorial left-oblique room camera: the artwork plane, wall plane, floor lines, furniture perspective, and architectural vanishing direction must share one coherent perspective.',
            ],
            2 => [
                'camera_slot' => 2,
                'camera_group_expected' => 'soft_left_oblique',
                'camera_view_expected' => 'three-quarter left view, soft left-oblique, 10–15 degrees',
                'camera_distance_expected' => 'close or medium-close view',
                'camera_angle_notes_expected' => 'A gentle left-oblique room camera: the artwork plane, wall plane, floor lines, furniture perspective, and architectural vanishing direction must share one coherent perspective.',
            ],
            3 => [
                'camera_slot' => 3,
                'camera_group_expected' => 'soft_right_oblique',
                'camera_view_expected' => 'three-quarter right view, soft right-oblique, 10–15 degrees',
                'camera_distance_expected' => 'close or medium-close view',
                'camera_angle_notes_expected' => 'A gentle right-oblique room camera: the artwork plane, wall plane, floor lines, furniture perspective, and architectural vanishing direction must share one coherent perspective.',
            ],
            4 => [
                'camera_slot' => 4,
                'camera_group_expected' => 'elevated_high_angle_architectural',
                'camera_view_expected' => 'elevated high-angle architectural view, controlled view from above, not top-down, not surveillance-like',
                'camera_distance_expected' => 'controlled architectural view',
                'camera_angle_notes_expected' => 'An elevated high-angle room camera: the artwork plane, wall plane, floor lines, furniture perspective, and architectural vanishing direction must belong to the same controlled elevated perspective.',
            ],
            5 => [
                'camera_slot' => 5,
                'camera_group_expected' => 'controlled_low_angle_contrapicado',
                'camera_view_expected' => 'controlled oblique low-angle / contrapicado, low camera looking upward through the room, artistic but believable scale',
                'camera_distance_expected' => 'medium-close view',
                'camera_angle_notes_expected' => 'A controlled low-angle oblique room camera: the artwork plane, wall plane, floor lines, furniture perspective, and architectural vanishing direction must share one coherent upward contrapicado perspective.',
            ],
            6 => [
                'camera_slot' => 6,
                'camera_group_expected' => 'low_floor_wide_7_8_architectural',
                'camera_view_expected' => 'low floor wide 7/8 architectural view, controlled oblique depth, wide architectural view, no fisheye, no scale distortion',
                'camera_distance_expected' => 'controlled wide architectural view',
                'camera_angle_notes_expected' => 'A controlled low-floor wide 7/8 architectural room camera: the artwork plane, wall plane, floor lines, furniture perspective, and architectural vanishing direction must share one coherent wide perspective with clean vanishing lines.',
            ]
        ];

        $cameraArchetypeResolution = null;
        if ($limit === 6) {
            try {
                $cameraArchetypeResolution = (new MockupCameraArchetypeResolver())->resolveForArtwork($artworkId);
                $selectedSet = $cameraArchetypeResolution['selected_set'] ?? null;
                if (($cameraArchetypeResolution['dimensions']['xl_class'] ?? '') === 'xl' && is_array($selectedSet) && is_array($selectedSet['slots'] ?? null)) {
                    foreach ($selectedSet['slots'] as $slot => $archetype) {
                        $slot = (int)$slot;
                        if (!isset($deterministicSpecs[$slot]) || !is_array($archetype)) {
                            continue;
                        }
                        $deterministicSpecs[$slot]['camera_group_expected'] = (string)($archetype['camera_group'] ?? $deterministicSpecs[$slot]['camera_group_expected']);
                        $deterministicSpecs[$slot]['camera_view_expected'] = (string)($archetype['camera_view'] ?? $deterministicSpecs[$slot]['camera_view_expected']);
                        $deterministicSpecs[$slot]['camera_distance_expected'] = (string)($archetype['camera_distance'] ?? $deterministicSpecs[$slot]['camera_distance_expected']);
                        $deterministicSpecs[$slot]['camera_angle_notes_expected'] = (string)($archetype['camera_angle_notes'] ?? $deterministicSpecs[$slot]['camera_angle_notes_expected']);
                        $deterministicSpecs[$slot]['camera_archetype_set_id'] = (string)($cameraArchetypeResolution['selected_set_id'] ?? '');
                        $deterministicSpecs[$slot]['camera_archetype_id'] = (string)($archetype['camera_archetype_id'] ?? '');
                        $deterministicSpecs[$slot]['camera_archetype_name'] = (string)($archetype['camera_archetype_name'] ?? '');
                        $deterministicSpecs[$slot]['camera_archetype_source'] = 'core_json';
                        $deterministicSpecs[$slot]['camera_archetype_reason'] = (string)($archetype['camera_archetype_reason'] ?? '');
                    }
                }
            } catch (Throwable $e) {
                Logger::log("Camera archetype resolver fallback for artwork_id={$artworkId}: " . $e->getMessage(), 'warning');
                $cameraArchetypeResolution = null;
            }
        }

        $cameraSlotResolution = $this->resolveCameraSlotsForArtwork($artworkId, $limit, $cameraArchetypeResolution);
        if (!($cameraSlotResolution['fallback_used'] ?? true)) {
            foreach (($cameraSlotResolution['slots'] ?? []) as $slot => $cameraSlot) {
                $slot = (int)$slot;
                if (!isset($deterministicSpecs[$slot]) || !is_array($cameraSlot)) {
                    continue;
                }
                $deterministicSpecs[$slot]['camera_slot_set_id'] = (string)($cameraSlotResolution['selected_set_id'] ?? '');
                $deterministicSpecs[$slot]['camera_slot_data'] = $cameraSlot;
                $deterministicSpecs[$slot]['camera_slot_geometry'] = $this->composeCameraSlotGeometryBlock($cameraSlot);
                $deterministicSpecs[$slot]['camera_slot_fallback_used'] = false;

                $slotId = (string)($cameraSlot['slot_id'] ?? '');
                $slotName = (string)($cameraSlot['slot_name'] ?? $slotId);
                $deterministicSpecs[$slot]['camera_group_expected'] = 'camera_slot_' . $slotId;
                $deterministicSpecs[$slot]['camera_view_expected'] = $this->composeCameraSlotView($cameraSlot);
                $deterministicSpecs[$slot]['camera_distance_expected'] = 'defined by selected camera slot geometry, lens block, camera height, and composition block';
                $deterministicSpecs[$slot]['camera_angle_notes_expected'] = $deterministicSpecs[$slot]['camera_slot_geometry'];
            }
        }

        $finalProposals = [];

        $promptBuilder = new MockPromptBuilder();

        // Phase 2.9 — registry used only to read presence_policy for the Vital
        // Presence layer. Failure is non-fatal (resolver falls back to conservative
        // defaults), so it never blocks the main generation flow.
        $vitalPresenceRegistry = null;
        try {
            $vitalPresenceRegistry = new MockupContextWorldRegistry();
        } catch (Throwable $e) {
            Logger::log('Vital Presence registry unavailable; using conservative defaults: ' . $e->getMessage(), 'warning');
            $vitalPresenceRegistry = null;
        }

        foreach ($proposals as $index => $prop) {
            $position = $index + 1;
            
            // Populate deterministic specifications for the first 6 slots
            if (isset($deterministicSpecs[$position])) {
                $spec = $deterministicSpecs[$position];
                
                // Preserve Gemini's original camera view/angle
                $prop['camera_view_original'] = trim((string)($prop['camera_view'] ?? $prop['camera_angle'] ?? ''));
                
                // Add deterministic metadata
                $prop['camera_slot'] = $spec['camera_slot'];
                $prop['camera_group_expected'] = $spec['camera_group_expected'];
                $prop['camera_view_expected'] = $spec['camera_view_expected'];
                $prop['camera_distance_expected'] = $spec['camera_distance_expected'];
                $prop['camera_angle_notes_expected'] = $spec['camera_angle_notes_expected'];

                if (isset($spec['camera_archetype_set_id'])) {
                    $dims = is_array($cameraArchetypeResolution['dimensions'] ?? null) ? $cameraArchetypeResolution['dimensions'] : [];
                    $prop['camera_archetype_set_id'] = $spec['camera_archetype_set_id'];
                    $prop['camera_archetype_id'] = $spec['camera_archetype_id'] ?? '';
                    $prop['camera_archetype_name'] = $spec['camera_archetype_name'] ?? '';
                    $prop['camera_archetype_source'] = $spec['camera_archetype_source'] ?? 'core_json';
                    $prop['camera_archetype_reason'] = $spec['camera_archetype_reason'] ?? '';
                    $prop['artwork_dimensions_source'] = $cameraArchetypeResolution['artwork_dimensions_source'] ?? null;
                    $prop['artwork_orientation_resolved'] = $dims['orientation_resolved'] ?? null;
                    $prop['artwork_aspect_ratio_resolved'] = $dims['aspect_ratio_resolved'] ?? null;
                    $prop['artwork_longest_side_cm'] = $dims['longest_side_cm'] ?? null;
                    $prop['artwork_size_class'] = $dims['size_class'] ?? null;
                    $prop['artwork_xl_class'] = $dims['xl_class'] ?? null;
                }

                if (isset($spec['camera_slot_data']) && is_array($spec['camera_slot_data'])) {
                    $slotData = $spec['camera_slot_data'];
                    $prop['camera_slot_set_id'] = $spec['camera_slot_set_id'] ?? '';
                    $prop['camera_slot_id'] = (string)($slotData['slot_id'] ?? '');
                    $prop['camera_slot_name'] = (string)($slotData['slot_name'] ?? '');
                    $prop['camera_slot_enabled'] = (bool)($slotData['enabled'] ?? false);
                    $prop['camera_height_block'] = (string)($slotData['camera_height_block'] ?? '');
                    $prop['lens_block'] = (string)($slotData['lens_block'] ?? '');
                    $prop['vertical_tilt_block'] = (string)($slotData['vertical_tilt_block'] ?? '');
                    $prop['lateral_rotation_block'] = (string)($slotData['lateral_rotation_block'] ?? '');
                    $prop['composition_block'] = (string)($slotData['composition_block'] ?? '');
                    $prop['human_subject_block'] = (string)($slotData['human_subject_block'] ?? '');
                    $prop['scale_block'] = (string)($slotData['scale_block'] ?? '');
                    $prop['depth_of_field_block'] = (string)($slotData['depth_of_field_block'] ?? '');
                    $prop['scene_affinity'] = $slotData['scene_affinity'] ?? [];
                    $prop['negative_directives'] = $slotData['negative_directives'] ?? [];
                    $prop['camera_slot_geometry'] = (string)($spec['camera_slot_geometry'] ?? '');
                    $prop['camera_slot_fallback_used'] = (bool)($spec['camera_slot_fallback_used'] ?? false);

                    if (trim($prop['human_subject_block']) === '') {
                        $prop['human_presence'] = 'none';
                    }
                }
                
                // Overwrite camera fields for compatibility with legacy components and coherence
                $prop['camera_view'] = $spec['camera_view_expected'];
                $prop['camera_distance'] = $spec['camera_distance_expected'];
                $prop['camera_angle_notes'] = $spec['camera_angle_notes_expected'];
            }

            $cameraView = trim((string)($prop['camera_view'] ?? $prop['camera_angle'] ?? 'soft editorial left-oblique physical view'));
            $cameraDistance = trim((string)($prop['camera_distance'] ?? 'medium-close view'));
            $cameraNotes = trim((string)($prop['camera_angle_notes'] ?? ''));
            $mockupPrompt = trim((string)($prop['mockup_prompt'] ?? ''));
            $negativePrompt = trim((string)($prop['negative_prompt'] ?? ''));

            // Clean/harmonize camera description inside mockup_prompt to prevent contradiction
            if (isset($deterministicSpecs[$position])) {
                $spec = $deterministicSpecs[$position];
                
                // 1. Clean starting camera descriptions that might contradict (e.g. "A low floor wide low-angle view...")
                $startPatterns = [
                    '/^A\s+(?:low\s+floor\s+wide\s+)?low-angle\s+view,\s+looking\s+up\s+at\s+the\s+artwork\s+[\'"]?[^\'"]+[\'"]?\s+installed\s+on\s+a?/i' => 'The artwork is installed on',
                    '/^A\s+(?:low\s+floor\s+wide\s+)?low-angle\s+view\s+of\s+/i' => '',
                    '/^An?\s+(?:elevated|high-angle|frontal|subtle|left-oblique|right-oblique|artistic|controlled)\s+[^,]+,\s+/i' => '',
                ];
                foreach ($startPatterns as $pat => $rep) {
                    if (preg_match($pat, $mockupPrompt)) {
                        $mockupPrompt = preg_replace($pat, $rep, $mockupPrompt);
                        break; // clean at most one prefix
                    }
                }
                
                // 2. Remove camera narration from mockup_prompt; sovereign camera fields carry it.
                $cameraSentencePattern = '/\b(?:(?:The\s+)?camera\s*(?::|view\s+is|angle\s+is|direction\s+is|is\s+positioned|takes|captures|provides)|Viewed\s+from|Shot\s+from)\s+[^.]+\.?/i';
                $mockupPrompt = preg_replace($cameraSentencePattern, '', $mockupPrompt);
            }

            // 3. Clean/sanitize any artwork redescriptions to prevent redundant/conflicting details
            // Replace: The artwork '[Title]' ([Description of colors/geometric/style]) -> The artwork
            $mockupPrompt = preg_replace('/(?:The\s+)?(?:artwork|painting|canvas)\s+\'[^\']+\'\s*\([^)]*\)/i', 'The artwork', $mockupPrompt);
            $mockupPrompt = preg_replace('/(?:The\s+)?(?:artwork|painting|canvas)\s+"[^"]+"\s*\([^)]*\)/i', 'The artwork', $mockupPrompt);
            $mockupPrompt = preg_replace('/(?:The\s+)?(?:artwork|painting|canvas)\s*\([^)]*\)/i', 'The artwork', $mockupPrompt);
            
            // Replace: An abstract artwork with [colors/shapes...] or A vibrant artwork, rich in... -> The artwork (prior to hangs/is hung/is displayed/etc or comma + participle)
            $mockupPrompt = preg_replace('/(?:An?\s+)?(?:[a-z\-]+\s+){0,3}(?:artwork|painting|canvas)(?:\s*(?:with|featuring|showing|having|depicting)|,\s*rich\s+in)\s+.*?(?=\s+(?:hangs|is\s+hung|is\s+elegantly\s+displayed|is\s+displayed|is\s+mounted|is\s+placed)|\s*,\s*(?:[a-z]+\s+)?(?:installed|leaning|placed|mounted|hung|displayed))/i', 'The artwork', $mockupPrompt);
            $mockupPrompt = preg_replace('/,?\s*making\s+(?:its|the\s+artwork\'s|the\s+painting\'s|the\s+canvas\'s)\s+colou?rs?\s+glow\.?/i', '', $mockupPrompt);
            $mockupPrompt = preg_replace('/\b(?:its|the\s+artwork\'s|the\s+painting\'s|the\s+canvas\'s)\s+colou?rs?\b[^.]*\./i', '', $mockupPrompt);
            $mockupPrompt = preg_replace('/\b(?:abstract\s+landscape|vibrant\s+(?:orange|red|yellow|blue|green|purple|pink|black|white|colors?|colours?)|geometric\s+forms?|pictorial\s+style|painted\s+composition|visual\s+composition)\b[,;:\s]*/i', '', $mockupPrompt);
            
            // Remove sentences starting with "The painting features/has..." or "The artwork features/has..."
            $mockupPrompt = preg_replace('/(?:The\s+)?(?:artwork|painting|canvas)\s+(?:features|has|depicts|portrays|shows|contains|is\s+an?\s+abstract|is\s+an?\s+figurative|is\s+an?\s+geometric)\s+[^.]*\./i', '', $mockupPrompt);

            // Remove legacy centering language from scene narration; camera slot geometry owns composition.
            if (isset($prop['camera_slot_geometry']) && trim((string)$prop['camera_slot_geometry']) !== '' && empty($prop['camera_slot_fallback_used'])) {
                $mockupPrompt = preg_replace('/\b(?:The\s+)?artwork\s+is\s+centrally\s+(?:placed|displayed|installed)\s+on\b/i', 'The artwork is installed on', $mockupPrompt);
                $mockupPrompt = preg_replace('/\b(?:The\s+)?artwork\s+is\s+installed\s+centrally\s+on\b/i', 'The artwork is installed on', $mockupPrompt);
                $mockupPrompt = preg_replace('/\b(?:The\s+)?artwork\s+is\s+cent(?:er|re)ed\s+on\s+the\s+wall\b/i', 'The artwork is installed on the wall', $mockupPrompt);
                $mockupPrompt = preg_replace('/\b(?:The\s+)?artwork\s+is\s+cent(?:er|re)ed\s+in\s+the\s+frame\.?/i', 'The artwork is installed within the room.', $mockupPrompt);

                $slotCameraText = strtolower(
                    (string)($prop['camera_slot_id'] ?? '') . ' '
                    . (string)($prop['camera_slot_name'] ?? '') . ' '
                    . (string)($prop['camera_slot_geometry'] ?? '')
                );
                if (preg_match('/\b(?:nadir|contrapicado|low-angle|low\s+angle|near\s+the\s+floor|floor\s+position)\b/i', $slotCameraText)) {
                    $mockupPrompt = preg_replace('/\bArtwork\s+at\s+eye-level\.?/i', 'Artwork mounted at a natural gallery height on the wall.', $mockupPrompt);
                    $mockupPrompt = preg_replace('/\bartwork\s+at\s+eye\s+level\.?/i', 'artwork mounted at a natural gallery height on the wall.', $mockupPrompt);
                    $mockupPrompt = preg_replace('/\beye-level\s+artwork\b/i', 'artwork mounted at a natural gallery height on the wall', $mockupPrompt);
                    $mockupPrompt = preg_replace('/\beye-level\s+view\b/i', 'low architectural viewpoint', $mockupPrompt);
                }
            }

            if ($position === 6) {
                $mockupPrompt = preg_replace('/\b(?:The\s+)?artwork\s+(?:rests\s+casually\s+against|rests\s+against|leans\s+against)\s+the\s+wall,\s*leaning\s+slightly\.?/i', 'The artwork is installed on the wall.', $mockupPrompt);
                $mockupPrompt = preg_replace('/\b(?:leaning\s+slightly|rests\s+casually\s+against\s+the\s+wall|rests\s+against\s+the\s+wall|leans\s+against\s+the\s+wall)\b\.?/i', '', $mockupPrompt);
                $mockupPrompt = preg_replace('/\bcommanding\s+attention\b/i', 'presented clearly', $mockupPrompt);
                $mockupPrompt = preg_replace('/\bin\s+the\s+foreground\b/i', 'within the space', $mockupPrompt);
                $mockupPrompt = preg_replace('/\bshowcasing\s+the\s+artwork\'s\s+scale\s+within\s+the\s+grand\s+space\b/i', 'showing the artwork within the architectural space', $mockupPrompt);
            }

            $mockupPrompt = preg_replace('/\s{2,}/', ' ', trim((string)$mockupPrompt));

            // Mapear la propuesta dinámica de la IA al formato de MockPromptBuilder
            $mappedContext = [
                'name' => $prop['context_name'] ?? 'Custom Context',
                'purpose' => $prop['context_role'] ?? 'presentation',
                'scene' => "A " . ($prop['space_type'] ?? 'interior') . " with " . ($prop['atmosphere'] ?? 'neutral') . " atmosphere. Materials: " . implode(', ', (array)($prop['materials'] ?? [])),
                'lighting' => $prop['lighting'] ?? 'soft light',
                'camera' => $this->mapCameraAngle($cameraView, $cameraDistance),
                'camera_group' => isset($deterministicSpecs[$position]) ? $deterministicSpecs[$position]['camera_group_expected'] : $this->mapCameraGroup($cameraView, $position),
                'camera_view' => $cameraView,
                'camera_distance' => $cameraDistance,
                'camera_angle_notes' => $cameraNotes,
                'time_of_day' => $this->mapTimeOfDay($prop['lighting'] ?? 'day'),
                'placement' => $prop['placement'] ?? 'hanging',
                'with_human' => (isset($prop['human_presence']) && strtolower(trim($prop['human_presence'])) !== 'none'),
                'human_profile' => $this->mapHumanProfile($prop['human_presence'] ?? 'none'),
                'mockup_prompt' => $mockupPrompt,
                'negative_prompt' => $negativePrompt,
            ];

            if (isset($deterministicSpecs[$position])) {
                $mappedContext['camera_slot'] = $prop['camera_slot'];
                $mappedContext['camera_group_expected'] = $prop['camera_group_expected'];
                $mappedContext['camera_view_expected'] = $prop['camera_view_expected'];
                $mappedContext['camera_distance_expected'] = $prop['camera_distance_expected'];
                $mappedContext['camera_angle_notes_expected'] = $prop['camera_angle_notes_expected'];
                $mappedContext['camera_view_original'] = $prop['camera_view_original'];

                foreach ([
                    'camera_archetype_set_id',
                    'camera_archetype_id',
                    'camera_archetype_name',
                    'camera_archetype_source',
                    'camera_archetype_reason',
                    'artwork_dimensions_source',
                    'artwork_orientation_resolved',
                    'artwork_aspect_ratio_resolved',
                    'artwork_longest_side_cm',
                    'artwork_size_class',
                    'artwork_xl_class',
                ] as $archetypeField) {
                    if (array_key_exists($archetypeField, $prop)) {
                        $mappedContext[$archetypeField] = $prop[$archetypeField];
                    }
                }

                foreach ([
                    'camera_slot_set_id',
                    'camera_slot_id',
                    'camera_slot_name',
                    'camera_slot_enabled',
                    'camera_height_block',
                    'lens_block',
                    'vertical_tilt_block',
                    'lateral_rotation_block',
                    'composition_block',
                    'human_subject_block',
                    'scale_block',
                    'depth_of_field_block',
                    'scene_affinity',
                    'negative_directives',
                    'camera_slot_geometry',
                    'camera_slot_fallback_used',
                ] as $slotField) {
                    if (array_key_exists($slotField, $prop)) {
                        $mappedContext[$slotField] = $prop[$slotField];
                    }
                }
            }

            $mappedProfile = [
                'one_line_curatorial_read' => $artworkAnalysis['one_line_curatorial_read'] ?? $artworkAnalysis['style_summary'] ?? 'Contemporary artwork presentation.',
                'style_summary' => $artworkAnalysis['style_summary'] ?? 'Abstract artwork.',
                'seasonal_strategy' => [
                    'primary_season' => $artworkAnalysis['seasonal_strategy']['primary_season'] ?? 'neutral'
                ],
                'audience_profile' => [
                    'primary' => $artworkAnalysis['audience_profile']['primary'] ?? 'collectors'
                ],
                '_artist_profile_prompt' => $artworkMetadata['artist_profile_prompt'] ?? '',
                '_artist_profile' => $artworkMetadata['artist_profile'] ?? [],
            ];

            // Phase 2.9 — Vital Presence (additive, secondary layer). Camera-first
            // stays sovereign over human scale; this only adds optional, ambient
            // living/atmospheric presence governed by the dominance rule.
            $presencePolicy = [];
            if ($vitalPresenceRegistry instanceof MockupContextWorldRegistry) {
                $assignedWorld   = trim((string)($prop['assigned_world_id'] ?? ''));
                $assignedFamily  = trim((string)($prop['assigned_family_id'] ?? ''));
                $assignedVariant = trim((string)($prop['assigned_variant_id'] ?? ''));
                if ($assignedWorld !== '' && $assignedFamily !== '' && $assignedVariant !== '') {
                    try {
                        $presenceMeta = $vitalPresenceRegistry->metadataForCombination(
                            $assignedWorld,
                            $assignedFamily,
                            $assignedVariant
                        );
                        if (is_array($presenceMeta['presence_policy'] ?? null)) {
                            $presencePolicy = $presenceMeta['presence_policy'];
                        }
                    } catch (Throwable $e) {
                        // Conservative defaults inside the resolver.
                    }
                }
            }
            $cameraHumanBlock = (string)($mappedContext['human_subject_block'] ?? $prop['human_subject_block'] ?? '');
            $vitalPresence = MockupVitalPresenceResolver::resolve(
                $presencePolicy,
                $cameraHumanBlock,
                (string)($prop['placement_mode'] ?? '')
            );
            $mappedContext['vital_presence'] = $vitalPresence;
            $prop['vital_presence'] = $vitalPresence;

            // Generar el prompt con las reglas rígidas de preservación y escala física
            $finalPrompt = $promptBuilder->build($mappedContext, $mappedProfile, $imageMeta);

            $prop['prompt'] = $finalPrompt;
            $prop['mapped_context'] = $mappedContext;
            $finalProposals[] = $prop;
        }

        $minimalAnalysis = [
            'suggested_titles' => $suggestedTitles,
            'recommended_number_of_contexts' => count($finalProposals),
            'contextual_proposals' => array_map([$this, 'minimalContextualProposal'], $finalProposals),
            'prompt_version' => $analysisData['prompt_version'] ?? 'v1.0 (Auto)',
        ];

        $this->saveToDatabase($artworkId, $minimalAnalysis, $finalProposals);

        return $minimalAnalysis;
    }

    private function validateCameraDistribution(array $proposals, int $limit): void
    {
        if ($limit !== 6) {
            return;
        }

        $expectedGroups = [
            'soft_editorial_left_oblique_non_frontal',
            'soft_left_oblique',
            'soft_right_oblique',
            'elevated_high_angle_architectural',
            'controlled_low_angle_contrapicado',
            'low_floor_wide_7_8_architectural',
        ];
        $expectedLabels = [
            'soft editorial left-oblique physical view, 15-20 degrees, clearly non-frontal',
            'three-quarter left view, soft left-oblique, 10-15 degrees',
            'three-quarter right view, soft right-oblique, 10-15 degrees',
            'elevated high-angle architectural view, controlled view from above, not top-down, not surveillance-like',
            'controlled oblique low-angle / contrapicado, low camera looking upward through the room, artistic but believable scale',
            'low floor wide 7/8 architectural view, controlled oblique depth, wide architectural view, no fisheye, no scale distortion',
        ];

        foreach ($expectedGroups as $index => $expectedGroup) {
            $proposal = $proposals[$index] ?? [];
            $position = $index + 1;
            $actualView = trim((string)($proposal['camera_view'] ?? $proposal['camera_angle'] ?? ''));
            $actualGroup = $this->mapCameraGroup($actualView);

            if ($actualGroup !== $expectedGroup) {
                Logger::log(
                    "MOCKUP_AUDIT: Camera distribution mismatch at proposal {$position}. " .
                    "Expected: {$expectedGroup} ({$expectedLabels[$index]}). " .
                    "Gemini original: '{$actualView}'. " .
                    "Normalized: {$actualGroup}.",
                    'warning'
                );
            }
        }
    }

    private function analyzeArtworkContextWorldIsolated(string $imagePath, array $metadata, array $imageMeta): array
    {
        $registry = new MockupContextWorldRegistry();
        $regValidation = $registry->validation();
        if (empty($regValidation['ok'])) {
            throw new RuntimeException(
                'MockupContextWorldRegistry validation failed for world-isolated mode: '
                . implode('; ', (array)($regValidation['errors'] ?? []))
            );
        }

        // FALLBACK ONLY — generic placeholder camera views. These must NOT govern world
        // generation in the normal CAMERA-FIRST flow; they are used only if the real camera
        // slots cannot be resolved (e.g. missing dimensions). See resolveCameraFirstSlots().
        $defaultCameraViews = [
            0 => 'soft editorial left-oblique physical view, 15–20 degrees, clearly non-frontal',
            1 => 'three-quarter left view, soft left-oblique, 10–15 degrees',
            2 => 'three-quarter right view, soft right-oblique, 10–15 degrees',
            3 => 'elevated high-angle architectural view, controlled view from above, not top-down, not surveillance-like',
            4 => 'controlled oblique low-angle / contrapicado, low camera looking upward through the room, artistic but believable scale',
            5 => 'low floor wide 7/8 architectural view, controlled oblique depth, wide architectural view, no fisheye, no scale distortion',
        ];

        // CAMERA FIRST (phase 2.7-S): resolve the REAL camera slots before any world prompt,
        // reusing the same resolver that generateMockupPrompts() uses later, so the camera
        // used to design the world matches the camera saved in the final context.
        $cameraFirst        = $this->resolveCameraFirstSlots($imageMeta, $metadata);
        $cameraFirstSlots   = is_array($cameraFirst['slots'] ?? null) ? $cameraFirst['slots'] : [];
        $cameraFirstActive  = empty($cameraFirst['fallback_used']) && count($cameraFirstSlots) === 6;
        $cameraFirstSetId   = (string)($cameraFirst['selected_set_id'] ?? '');
        $cameraFirstFallbackReason = $cameraFirstActive ? '' : (string)($cameraFirst['fallback_reason'] ?? 'camera_first_unavailable');
        if (!$cameraFirstActive) {
            Logger::log(
                'CAMERA-FIRST unavailable; falling back to generic placeholder camera views. reason='
                . $cameraFirstFallbackReason,
                'warning'
            );
        }

        $proposals      = [];
        $suggestedTitles = [];
        $artworkAnalysis = [];

        for ($index = 0; $index < 6; $index++) {
            $worldMetadata = $registry->metadataForCuratorialProposalIndex($index, null);
            $isFirstCall   = ($index === 0);

            // Resolve the real camera slot for this index (camera-first). Placeholder only on fallback.
            $realCameraSlot = $cameraFirstActive ? ($cameraFirstSlots[$index + 1] ?? null) : null;
            $slotFallback   = !is_array($realCameraSlot);
            if (!$slotFallback) {
                $cameraView         = $this->composeCameraSlotView($realCameraSlot);
                $cameraSlotGeometry = $this->composeCameraSlotGeometryBlock($realCameraSlot);
            } else {
                $cameraView         = $defaultCameraViews[$index];
                $cameraSlotGeometry = '';
            }

            $callError = null;
            $proposal  = null;
            $parsed    = [];
            $retryFeedback = '';
            $retryApplied = false;
            $retryCount = 0;
            $validation = [
                'passed' => false,
                'terms' => [],
            ];

            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $prompt = $this->buildSingleWorldPrompt(
                    $index,
                    $metadata,
                    $imageMeta,
                    $worldMetadata,
                    $cameraView,
                    $isFirstCall,
                    $retryFeedback,
                    $slotFallback ? null : $realCameraSlot,
                    $cameraSlotGeometry
                );
                $attemptSuffix = "slot_" . ($index + 1) . "_attempt_{$attempt}" . ($attempt === 2 ? '_retry' : '');
                $this->saveDebugPrompt($imagePath, $prompt, $attemptSuffix);
                if ($attempt === 1) {
                    $this->saveDebugPrompt($imagePath, $prompt, "world_{$index}");
                }

                $parts = [
                    $this->client->textPart($prompt),
                    $this->client->imagePart($imagePath),
                ];

                foreach (['gemini-2.5-flash'] as $model) {
                    try {
                        $rawText = $this->client->generateText($parts, $model);
                        $this->saveDebugText($imagePath, $rawText, "raw_world_{$index}_attempt_{$attempt}");
                        if ($attempt === 1) {
                            $this->saveDebugText($imagePath, $rawText, "raw_world_{$index}");
                        }
                        $json    = $this->extractJson($rawText);
                        $parsed  = json_decode($json, true);

                        if (!is_array($parsed)) {
                            throw new RuntimeException("World-isolated call index={$index} attempt={$attempt} returned invalid JSON.");
                        }

                        // Accept contextual_proposals[0] or a flat proposal object
                        if (!empty($parsed['contextual_proposals']) && is_array($parsed['contextual_proposals'][0] ?? null)) {
                            $proposal = $parsed['contextual_proposals'][0];
                        } elseif (isset($parsed['context_name'])) {
                            $proposal = $parsed;
                        }

                        if (!is_array($proposal) || empty($proposal['context_name'])) {
                            throw new RuntimeException("World-isolated call index={$index} attempt={$attempt} returned no valid proposal.");
                        }

                        $validation = $this->validateWorldIsolatedProposalAgainstForbiddenDrift($proposal, $worldMetadata);
                        if (!empty($validation['passed'])) {
                            $callError = null;
                            break 2;
                        }

                        if ($attempt === 1) {
                            $retryApplied = true;
                            $retryCount = 1;
                            $terms = array_values(array_map('strval', (array)($validation['terms'] ?? [])));
                            $retryFeedback = $this->buildWorldForbiddenDriftRetryFeedback($terms);
                            Logger::log(sprintf(
                                'World-isolated proposal index=%d failed forbidden drift validation; retrying same slot once. terms=%s',
                                $index,
                                implode(', ', $terms)
                            ), 'world_isolated');
                            break;
                        }

                        $terms = array_values(array_map('strval', (array)($validation['terms'] ?? [])));
                        throw new RuntimeException(
                            "World-isolated proposal index={$index} failed forbidden drift validation after retry: "
                            . implode(', ', $terms)
                        );
                    } catch (Throwable $e) {
                        error_log("World-isolated index={$index} attempt={$attempt} model={$model} failed: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
                        $callError = $e;
                    }
                }

                if ($callError !== null && $attempt === 1 && $retryFeedback === '') {
                    break;
                }
            }

            if ($callError !== null) {
                throw $callError;
            }

            if (!is_array($proposal)) {
                throw new RuntimeException("World-isolated call index={$index} returned no accepted proposal.");
            }

            if ($isFirstCall) {
                $suggestedTitles = is_array($parsed['suggested_titles'] ?? null)
                    ? $parsed['suggested_titles'] : [];
                $artworkAnalysis = is_array($parsed['artwork_analysis'] ?? null)
                    ? $parsed['artwork_analysis'] : [];
            }

            // Trazability
            $proposal['generation_mode']           = 'world_isolated_single_call_per_slot';
            $proposal['world_generation_index']    = $index;
            $proposal['world_generation_version']  = 'phase_2_7_m_world_isolated_v1';
            $proposal['assigned_world_id']         = (string)($worldMetadata['selected_world_id']   ?? $worldMetadata['context_world_id']  ?? '');
            $proposal['assigned_family_id']        = (string)($worldMetadata['selected_family_id']  ?? $worldMetadata['context_family_id'] ?? '');
            $proposal['assigned_variant_id']       = (string)($worldMetadata['selected_variant_id'] ?? $worldMetadata['scene_variant_id']  ?? '');
            $proposal['world_prompt_isolated']     = true;
            $proposal['world_validation_passed']    = (bool)($validation['passed'] ?? false);
            $proposal['world_forbidden_terms_found'] = array_values(array_map('strval', (array)($validation['terms'] ?? [])));
            $proposal['world_retry_applied']        = $retryApplied;
            $proposal['world_retry_count']          = $retryCount;
            $proposal['world_validation_version']   = 'phase_2_7_n_retry_v1';
            // CAMERA-FIRST trazability (phase 2.7-S)
            $proposal['camera_first_enabled']        = !$slotFallback;
            $proposal['camera_first_version']        = 'phase_2_7_s_camera_first_v1';
            $proposal['camera_first_slot_id']        = $slotFallback ? '' : (string)($realCameraSlot['slot_id'] ?? '');
            $proposal['camera_first_slot_name']      = $slotFallback ? '' : (string)($realCameraSlot['slot_name'] ?? $realCameraSlot['slot_id'] ?? '');
            $proposal['camera_first_source']         = 'resolved_before_world_generation';
            $proposal['camera_first_fallback_used']  = $slotFallback;
            $proposal['camera_first_fallback_reason'] = $slotFallback
                ? ($cameraFirstFallbackReason !== '' ? $cameraFirstFallbackReason : 'slot_unavailable_for_index')
                : '';
            $proposal['camera_first_camera_view']    = $cameraView;
            // Enforce camera_view to the resolved camera (real slot, or placeholder on fallback)
            $proposal['camera_view']               = $cameraView;

            $proposals[] = $proposal;

            Logger::log(sprintf(
                'World-isolated proposal index=%d world=%s context_name="%s" generated OK.',
                $index,
                $proposal['assigned_world_id'],
                (string)($proposal['context_name'] ?? '')
            ), 'world_isolated');
        }

        return [
            'suggested_titles'             => $suggestedTitles,
            'artwork_analysis'             => $artworkAnalysis,
            'contextual_proposals'         => $proposals,
            'recommended_number_of_contexts' => 6,
            'generation_mode'              => 'world_isolated_single_call_per_slot',
            'generation_version'           => 'phase_2_7_m_world_isolated_v1',
            'prompt_version'               => PromptSettings::artworkAnalysisPromptVersion(),
            'image_path'                   => $imagePath,
        ];
    }

    /**
     * CAMERA FIRST: resolve the real camera slots for the artwork BEFORE world generation,
     * reusing resolveCameraSlotsForArtwork(). Builds a synthetic dimensions resolution from
     * imageMeta so we do not need the artworkId/core.json during the phase-1 analysis flow;
     * the slot set is deterministic per (size_class, orientation), matching what
     * generateMockupPrompts() resolves later from core.json.
     */
    private function resolveCameraFirstSlots(array $imageMeta, array $metadata): array
    {
        $width  = $this->cameraFirstPositiveFloat($imageMeta['physical_size']['width_cm']  ?? $metadata['width_cm']  ?? null);
        $height = $this->cameraFirstPositiveFloat($imageMeta['physical_size']['height_cm'] ?? $metadata['height_cm'] ?? null);
        $depth  = $this->cameraFirstPositiveFloat($imageMeta['physical_size']['depth_cm']  ?? $metadata['depth_cm']  ?? null);

        $orientation = strtolower(trim((string)($imageMeta['orientation'] ?? '')));
        if ($orientation === '' || $orientation === 'unknown') {
            if ($width !== null && $height !== null) {
                $orientation = $width > $height ? 'horizontal' : ($height > $width ? 'vertical' : 'square');
            } else {
                $orientation = 'unknown';
            }
        }

        $longest = null;
        if ($width !== null || $height !== null) {
            $longest = max($width ?? 0.0, $height ?? 0.0);
        }
        $sizeClass = 'unknown';
        if ($longest !== null && $longest > 0) {
            $sizeClass = $longest < 80.0 ? 'standard' : ($longest <= 140.0 ? 'xl' : 'oversize');
        }

        if ($sizeClass === 'unknown' || $orientation === 'unknown') {
            return [
                'slots'           => [],
                'fallback_used'   => true,
                'fallback_reason' => 'camera_first_dimensions_incomplete',
                'selected_set_id' => null,
                'dimensions'      => ['size_class' => $sizeClass, 'orientation_resolved' => $orientation],
            ];
        }

        $syntheticResolution = [
            'dimensions' => [
                'width_cm'             => $width,
                'height_cm'            => $height,
                'depth_cm'             => $depth,
                'orientation_resolved' => $orientation,
                'size_class'           => $sizeClass,
            ],
        ];

        // artworkId=0 is unused because we pass a non-null resolution.
        return $this->resolveCameraSlotsForArtwork(0, 6, $syntheticResolution);
    }

    private function cameraFirstPositiveFloat($value): ?float
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $float = (float)str_replace(',', '.', (string)$value);
        return $float > 0 ? $float : null;
    }

    /**
     * CAMERA FIRST: derive a world-design directive that tells Gemini how to build the scene
     * SO THAT it works with the assigned camera. The camera stays sovereign; the world adapts.
     */
    private function composeCameraFirstWorldDesignDirective(array $slot, string $cameraView): string
    {
        $probe = strtolower(
            (string)($slot['slot_id'] ?? '') . ' '
            . (string)($slot['slot_name'] ?? '') . ' '
            . (string)($slot['camera_height_block'] ?? '') . ' '
            . (string)($slot['vertical_tilt_block'] ?? '') . ' '
            . (string)($slot['lateral_rotation_block'] ?? '') . ' '
            . (string)($slot['composition_block'] ?? '') . ' '
            . $cameraView
        );

        if (preg_match('/\b(nadir|contrapicado|low[\s-]?angle|near the floor|low floor|looking up(ward)?)\b/', $probe)) {
            return 'This camera is LOW (low-angle / contrapicado). Design the world so a prominent visible floor plane, low vanishing lines, and architectural verticals (walls, columns, door frames, furniture legs) rise through the frame. Place lateral furniture partially cropped near the foreground; let the artwork sit elevated and dominant as seen from below, with believable architectural depth behind it.';
        }
        if (preg_match('/\b(aerial|elevated|high[\s-]?angle|from above|overhead|descending)\b/', $probe)) {
            return 'This camera is ELEVATED (high-angle, read from above). Design the world with a readable descending floor surface, visible spatial organization, and furniture arranged as compositional geometry on the floor plane. The room must be legible from above while the artwork on the wall remains the identifiable subject.';
        }
        if (preg_match('/\b(7\/?8|three[\s-]?quarter|3\/4|oblique|recess|corridor|pasillo)\b/', $probe)) {
            return 'This camera is THREE-QUARTER / oblique. Design the world with strong lateral depth: a wall seen in perspective, a clear architectural vanishing direction, side planes, recesses or corridor edges, and foreground-to-background recession. The artwork may sit off-center with the architecture receding to one side.';
        }
        if (preg_match('/\b(frontal|straight[\s-]?on|symmetr|centered)\b/', $probe)) {
            return 'This camera is FRONTAL / curatorial. Design the world around calm symmetry, a quiet dominant wall, balanced proportion, and restrained frontality. Depth is shallow and dignified; the wall and artwork carry the composition without aggressive perspective.';
        }

        return 'Design the world spatial composition (foreground, midground, background, vanishing lines, floor/wall/ceiling visibility) so that it reads correctly and richly through this specific camera, rather than as a flat frontal product shot.';
    }

    private function validateWorldIsolatedProposalAgainstForbiddenDrift(array $proposal, array $worldMetadata): array
    {
        $terms = $this->worldForbiddenDriftTerms($worldMetadata);
        if ($terms === []) {
            return [
                'passed' => true,
                'terms' => [],
            ];
        }

        $scanParts = [];
        foreach ([
            'context_name',
            'space_type',
            'atmosphere',
            'lighting',
            'mockup_prompt',
            'negative_prompt',
        ] as $field) {
            $scanParts[] = (string)($proposal[$field] ?? '');
        }
        $scanParts[] = implode(' ', array_map('strval', (array)($proposal['materials'] ?? [])));

        $text = trim(implode("\n", array_filter($scanParts, fn($value) => trim((string)$value) !== '')));
        $found = [];
        foreach ($terms as $term) {
            if ($this->proposalContainsAffirmativeForbiddenTerm($text, $term)) {
                $found[] = $term;
            }
        }

        $found = array_values(array_unique($found));

        return [
            'passed' => ($found === []),
            'terms' => $found,
        ];
    }

    private function worldForbiddenDriftTerms(array $worldMetadata): array
    {
        $contract = is_array($worldMetadata['world_visual_contract'] ?? null)
            ? $worldMetadata['world_visual_contract']
            : [];

        $terms = [];
        foreach ((array)($contract['forbidden_visual_drift'] ?? []) as $term) {
            $term = trim((string)$term);
            if ($term === '') {
                continue;
            }
            if (preg_match('/^(?:no|without|avoid|exclude|excluding|forbid|forbidden|never|not)\b/i', $term)) {
                continue;
            }
            $terms[] = $term;
        }

        return array_values(array_unique($terms));
    }

    private function proposalContainsAffirmativeForbiddenTerm(string $text, string $term): bool
    {
        $text = trim($text);
        $term = trim($term);
        if ($text === '' || $term === '') {
            return false;
        }

        $quoted = preg_quote($term, '/');
        $quoted = preg_replace('/\\\\\s+/', '\\s+', $quoted) ?? $quoted;
        $pattern = '/(?<![A-Za-z0-9])' . $quoted . '(?![A-Za-z0-9])/i';
        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        foreach ($matches[0] as $match) {
            $offset = (int)$match[1];
            if (!$this->forbiddenTermOccurrenceIsNegated($text, $offset)) {
                return true;
            }
        }

        return false;
    }

    private function forbiddenTermOccurrenceIsNegated(string $text, int $offset): bool
    {
        $before = substr($text, max(0, $offset - 90), min(90, $offset));
        $after = substr($text, $offset, 90);

        if (preg_match('/(?:\b(?:without|avoid|exclude|excluding|forbid|forbidden|never|not)\b|\bno\b|\bno_)[\w\s,;:\/-]{0,90}$/i', $before)) {
            return true;
        }

        if (preg_match('/^(?:[\w\s,;:\/-]{0,40})\b(?:is|are|must be|should be)\s+(?:avoided|excluded|forbidden|not present)\b/i', $after)) {
            return true;
        }

        return false;
    }

    private function buildWorldForbiddenDriftRetryFeedback(array $terms): string
    {
        $terms = array_values(array_filter(array_map('strval', $terms), fn($term) => trim($term) !== ''));
        $lines = [];
        $lines[] = 'The previous response used forbidden vocabulary for this assigned world:';
        foreach ($terms as $term) {
            $lines[] = '* ' . $term;
        }
        $lines[] = '';
        $lines[] = 'Regenerate the same single context for the same artwork and same assigned world, but remove these forbidden terms completely. Do not replace them with theatrical, cinematic, costume-drama, staged, nostalgic, or theme-park language. Keep the scene architecturally authentic, restrained, and collector-grade.';

        return implode("\n", $lines);
    }

    private function buildSingleWorldPrompt(
        int $index,
        array $metadata,
        array $imageMeta,
        array $worldMetadata,
        string $cameraView,
        bool $includeArtworkAnalysis = true,
        string $retryFeedback = '',
        ?array $cameraSlot = null,
        string $cameraSlotGeometry = ''
    ): string {
        $notes             = trim((string)($metadata['artist_notes'] ?? ''));
        $artistProfile     = is_array($metadata['artist_profile'] ?? null) ? $metadata['artist_profile'] : [];
        $artistProfilePrompt = trim((string)($metadata['artist_profile_prompt'] ?? ''));
        $targetMarket      = trim((string)($metadata['target_market'] ?? 'collectors'));
        $preferredStyle    = trim((string)($metadata['preferred_style'] ?? ''));
        $width             = $imageMeta['physical_size']['width_cm']  ?? '';
        $height            = $imageMeta['physical_size']['height_cm'] ?? '';
        $depth             = $imageMeta['physical_size']['depth_cm']  ?? '';

        // Base: ADMIN master template (single source of editorial truth)
        $template = PromptSettings::artworkAnalysisPrompt();
        $this->logPromptSourceDebug($template);

        // Override count to 1 for this isolated call
        $template = preg_replace('/"recommended_number_of_contexts":\s*\d+/', '"recommended_number_of_contexts": 1', $template);
        $template = str_replace('{context_count}', '1', $template);

        $prompt = str_replace(
            ['{artist_profile_prompt}', '{artist_statement}', '{visual_language}', '{recurring_symbols}',
             '{preferred_atmospheres}', '{title}', '{width_cm}', '{height_cm}', '{depth_cm}',
             '{notes}', '{preferred_style}', '{target_market}', '{orientation}', '{region}', '{scale_text}'],
            [$artistProfilePrompt, (string)($artistProfile['statement'] ?? ''),
             (string)($artistProfile['visual_language'] ?? ''), (string)($artistProfile['recurring_themes'] ?? ''),
             (string)($artistProfile['palette_notes'] ?? ''), $metadata['title'] ?? 'Untitled',
             (string)$width, (string)$height, (string)$depth, $notes, $preferredStyle, $targetMarket,
             $imageMeta['orientation'] ?? 'unknown', (string)($metadata['region'] ?? ''),
             (string)($metadata['scale_text'] ?? '')],
            $template
        );

        // Extract world contract data
        $worldId      = (string)($worldMetadata['selected_world_id']   ?? $worldMetadata['context_world_id']  ?? '');
        $familyId     = (string)($worldMetadata['selected_family_id']  ?? $worldMetadata['context_family_id'] ?? '');
        $variantId    = (string)($worldMetadata['selected_variant_id'] ?? $worldMetadata['scene_variant_id']  ?? '');
        $contract     = is_array($worldMetadata['world_visual_contract'] ?? null) ? $worldMetadata['world_visual_contract'] : [];
        $worldLabel       = (string)($contract['selected_world_label']              ?? $worldId);
        $familyLabel      = (string)($contract['selected_family_label']             ?? $familyId);
        $variantLabel     = (string)($contract['selected_variant_label']            ?? $variantId);
        $visualAnchor     = trim((string)($contract['visual_anchor']               ?? ''));
        $materialAnchor   = trim((string)($contract['material_anchor']             ?? ''));
        $colorAtmosphere  = trim((string)($contract['required_color_atmosphere']   ?? ''));
        $spatialBehavior  = trim((string)($contract['required_spatial_behavior']   ?? ''));
        $archLanguage     = trim((string)($contract['required_architectural_language'] ?? ''));
        $materialPalette  = trim((string)($contract['required_material_palette']   ?? ''));
        $sceneDirectives  = array_values(array_filter(array_map('strval', (array)($contract['scene_directives']       ?? []))));
        $forbiddenDrift   = array_values(array_filter(array_map('strval', (array)($contract['forbidden_visual_drift'] ?? []))));
        $worldDirective   = trim((string)($worldMetadata['context_world_directive']  ?? ''));
        $familyDirective  = trim((string)($worldMetadata['context_family_directive'] ?? ''));
        $variantDirective = trim((string)($worldMetadata['scene_variant_directive']  ?? ''));
        $slotNumber       = $index + 1;

        $worldBlock  = "\n\n=== WORLD-ISOLATED GENERATION MODE — SLOT {$slotNumber} OF 6 ===\n";
        $worldBlock .= "You are generating exactly ONE context proposal for the pre-assigned world below.\n";
        $worldBlock .= "Return contextual_proposals with exactly 1 item. Do not generate additional proposals.\n\n";

        $worldBlock .= "ASSIGNED WORLD (mandatory — cannot be changed by artwork aesthetics):\n";
        $worldBlock .= "  world:   {$worldId} ({$worldLabel})\n";
        $worldBlock .= "  family:  {$familyId} ({$familyLabel})\n";
        $worldBlock .= "  variant: {$variantId} ({$variantLabel})\n";
        if ($worldDirective !== '')  { $worldBlock .= "  world directive: {$worldDirective}\n"; }
        if ($familyDirective !== '') { $worldBlock .= "  family directive: {$familyDirective}\n"; }
        if ($variantDirective !== '') { $worldBlock .= "  variant directive: {$variantDirective}\n"; }
        if ($visualAnchor !== '')    { $worldBlock .= "  visual anchor: {$visualAnchor}\n"; }
        if ($materialAnchor !== '')  { $worldBlock .= "  material anchor: {$materialAnchor}\n"; }
        if ($materialPalette !== '') { $worldBlock .= "  required material palette: {$materialPalette}\n"; }
        if ($archLanguage !== '')    { $worldBlock .= "  architectural language: {$archLanguage}\n"; }
        if ($colorAtmosphere !== '') { $worldBlock .= "  required color atmosphere: {$colorAtmosphere}\n"; }
        if ($spatialBehavior !== '') { $worldBlock .= "  required spatial behavior: {$spatialBehavior}\n"; }
        if ($sceneDirectives !== []) {
            $worldBlock .= "  scene directives: " . implode('; ', $sceneDirectives) . "\n";
        }

        if ($forbiddenDrift !== []) {
            $worldBlock .= "\nFORBIDDEN — do not use any of these terms in any text field of this proposal:\n";
            foreach ($forbiddenDrift as $term) {
                $worldBlock .= "  - {$term}\n";
            }
        }

        // CAMERA FIRST (phase 2.7-S): the camera is resolved BEFORE the world and is sovereign.
        // The world/context/decoration must be designed to work WITH this camera.
        $cameraFirstActive = is_array($cameraSlot) && $cameraSlot !== [];
        $worldBlock .= "\n=== CAMERA (SOVEREIGN — RESOLVED BEFORE THE WORLD) ===\n";
        if ($cameraFirstActive) {
            $slotId   = trim((string)($cameraSlot['slot_id'] ?? ''));
            $slotName = trim((string)($cameraSlot['slot_name'] ?? $slotId));
            $worldBlock .= "The photographer/camera for this slot is already decided. You must design the world FOR this camera.\n";
            if ($slotName !== '') { $worldBlock .= "  camera slot: {$slotName}" . ($slotId !== '' ? " ({$slotId})" : '') . "\n"; }
            $worldBlock .= "  camera_view (must be returned exactly): \"{$cameraView}\"\n";
            $geometry = trim($cameraSlotGeometry !== '' ? $cameraSlotGeometry : $this->composeCameraSlotGeometryBlock($cameraSlot));
            if ($geometry !== '') {
                $worldBlock .= "  camera geometry (reference — do NOT copy verbatim into mockup_prompt):\n";
                foreach (explode("\n", $geometry) as $geoLine) {
                    $geoLine = trim($geoLine);
                    if ($geoLine !== '') { $worldBlock .= "    {$geoLine}\n"; }
                }
            }
            $worldBlock .= "\nHOW TO DESIGN THE WORLD FOR THIS CAMERA:\n";
            $worldBlock .= "  " . $this->composeCameraFirstWorldDesignDirective($cameraSlot, $cameraView) . "\n";
            $worldBlock .= "  Build explicit foreground, midground and background, coherent vanishing lines, and the floor/wall/ceiling visibility this camera requires.\n";
        } else {
            $worldBlock .= "  camera_view (must be returned exactly): \"{$cameraView}\"\n";
            $worldBlock .= "  Design the world spatial composition (foreground, midground, background, depth) so it reads richly through this camera, not as a flat frontal product shot.\n";
        }
        $worldBlock .= "  Camera rule: you MAY design the space in response to this camera, but you MUST NOT override, rename, replace, soften, or contradict the assigned camera. Do not invent a different camera.\n";
        $worldBlock .= "  Do not narrate camera mechanics inside mockup_prompt; the camera fields are sovereign. But DO let the camera shape the architecture, depth and composition you describe.\n";

        $worldBlock .= "\nWORLD-ISOLATION RULES (critical):\n";
        $worldBlock .= "1. This proposal must be entirely confined to world: {$worldId}.\n";
        $worldBlock .= "2. Do not borrow vocabulary, materials, or spatial types from other worlds.\n";
        $worldBlock .= "3. context_name must evoke {$worldLabel} identity — not any other world.\n";
        $worldBlock .= "4. space_type must belong to the typology of {$worldId}.\n";
        $worldBlock .= "5. atmosphere and materials must match the language of {$familyLabel}.\n";
        $worldBlock .= "6. The artwork informs the atmosphere within this world but cannot change the world type.\n";
        $worldBlock .= "7. A dark or intense artwork placed in {$worldLabel} stays in {$worldLabel}. It does not become a Mediterranean villa, rustic room, or any other world.\n";
        $worldBlock .= "8. mockup_prompt describes the spatial and material installation of the artwork within {$worldId}, composed for the assigned camera (foreground/midground/background, depth, floor/wall visibility). Do not narrate camera mechanics there.\n";

        if (!$includeArtworkAnalysis) {
            $worldBlock .= "\nNOTE: Omit suggested_titles and artwork_analysis in your response. Return only contextual_proposals with 1 item.\n";
        }

        if (trim($retryFeedback) !== '') {
            $worldBlock .= "\nRETRY VALIDATION FEEDBACK (mandatory correction):\n";
            $worldBlock .= trim($retryFeedback) . "\n";
        }

        $prompt .= $worldBlock;

        $this->logCompiledPromptDebug($prompt, "mockup_context_world_isolated_index_{$index}");

        return $prompt;
    }

    private function buildAnalysisPrompt(array $metadata, array $imageMeta): string
    {
        $notes = trim((string)($metadata['artist_notes'] ?? ''));
        $artistProfile = is_array($metadata['artist_profile'] ?? null) ? $metadata['artist_profile'] : [];
        $artistProfilePrompt = trim((string)($metadata['artist_profile_prompt'] ?? ''));
        $targetMarket = trim((string)($metadata['target_market'] ?? 'collectors'));
        $preferredStyle = trim((string)($metadata['preferred_style'] ?? ''));

        $width = $imageMeta['physical_size']['width_cm'] ?? '';
        $height = $imageMeta['physical_size']['height_cm'] ?? '';
        $depth = $imageMeta['physical_size']['depth_cm'] ?? '';

        $contextCount = PromptSettings::mockupContextCount();
        $template = PromptSettings::artworkAnalysisPrompt();
        $this->logPromptSourceDebug($template);

        if ($contextCount === 6) {
            $template .= "\n\nCRITICAL CAMERA VIEW REQUIREMENT:\n"
                . "You MUST propose exactly 6 mockup contexts in this exact order. Each of the 6 proposals MUST use one of the following camera views, in this exact order:\n"
                . "1. Proposal 1: camera_view must be 'soft editorial left-oblique physical view, 15-20 degrees, clearly non-frontal'\n"
                . "2. Proposal 2: camera_view must be 'three-quarter left view, soft left-oblique, 10-15 degrees'\n"
                . "3. Proposal 3: camera_view must be 'three-quarter right view, soft right-oblique, 10-15 degrees'\n"
                . "4. Proposal 4: camera_view must be 'elevated high-angle architectural view, controlled view from above, not top-down, not surveillance-like'\n"
                . "5. Proposal 5: camera_view must be 'controlled oblique low-angle / contrapicado, low camera looking upward through the room, artistic but believable scale'\n"
                . "6. Proposal 6: camera_view must be 'low floor wide 7/8 architectural view, controlled oblique depth, wide architectural view, no fisheye, no scale distortion'\n"
                . "The mockup_prompt field must describe only the space, installation, light, materials, atmosphere, spatial composition, and the artwork's physical relationship with the environment. Do not repeat the camera there; use the sovereign camera fields only. Do not redescribe artwork colors, symbols, pictorial composition, or painting style.";

            $worldFirstBlock = $this->buildWorldFirstBlock($contextCount);
            if ($worldFirstBlock !== '') {
                $template .= "\n\n" . $worldFirstBlock;
            }
        }

        // Dynamically replace context count in prompt template
        $template = preg_replace(
            '/"recommended_number_of_contexts":\s*\d+/',
            '"recommended_number_of_contexts": ' . $contextCount,
            $template
        );
        $template = str_replace('{context_count}', (string)$contextCount, $template);

        $prompt = str_replace(
            [
                '{artist_profile_prompt}',
                '{artist_statement}',
                '{visual_language}',
                '{recurring_symbols}',
                '{preferred_atmospheres}',
                '{title}',
                '{width_cm}',
                '{height_cm}',
                '{depth_cm}',
                '{notes}',
                '{preferred_style}',
                '{target_market}',
                '{orientation}',
                '{region}',
                '{scale_text}'
            ],
            [
                $artistProfilePrompt,
                (string)($artistProfile['statement'] ?? ''),
                (string)($artistProfile['visual_language'] ?? ''),
                (string)($artistProfile['recurring_themes'] ?? ''),
                (string)($artistProfile['palette_notes'] ?? ''),
                $metadata['title'] ?? 'Untitled',
                (string)$width,
                (string)$height,
                (string)$depth,
                $notes,
                $preferredStyle,
                $targetMarket,
                $imageMeta['orientation'] ?? 'unknown',
                (string)($metadata['region'] ?? ''),
                (string)($metadata['scale_text'] ?? '')
            ],
            $template
        );

        $this->logCompiledPromptDebug($prompt, 'mockup_context_analysis');

        return $prompt;
    }

    private function buildWorldFirstBlock(int $contextCount): string
    {
        if ($contextCount !== 6) {
            return '';
        }

        try {
            $registry = new MockupContextWorldRegistry();
            $validation = $registry->validation();
            if (empty($validation['ok'])) {
                Logger::log('WORLD-FIRST block skipped: registry validation failed. ' . implode('; ', (array)($validation['errors'] ?? [])), 'warning');
                return '';
            }
            $distribution = $registry->curatorialDefaultDistribution($contextCount);
        } catch (Throwable $e) {
            Logger::log('WORLD-FIRST block skipped due to registry error: ' . $e->getMessage(), 'warning');
            return '';
        }

        $lines = [];
        $lines[] = 'WORLD-FIRST CONTEXT DISTRIBUTION — MANDATORY:';
        $lines[] = 'Each proposal below is PRE-ASSIGNED to a specific world, family, and variant. You MUST generate context_name, space_type, atmosphere, materials, and mockup_prompt that are coherent with and confined to the assigned world. Using vocabulary, aesthetics, or spatial types from a different world is a critical error.';
        $lines[] = '';

        foreach ($distribution as $index => $meta) {
            $proposal = $index + 1;
            $worldId   = (string)($meta['selected_world_id']   ?? $meta['context_world_id']  ?? '');
            $familyId  = (string)($meta['selected_family_id']  ?? $meta['context_family_id'] ?? '');
            $variantId = (string)($meta['selected_variant_id'] ?? $meta['scene_variant_id']  ?? '');
            $contract = is_array($meta['world_visual_contract'] ?? null) ? $meta['world_visual_contract'] : [];
            $worldLabel       = (string)($contract['selected_world_label']   ?? $worldId);
            $familyLabel      = (string)($contract['selected_family_label']  ?? $familyId);
            $variantLabel     = (string)($contract['selected_variant_label'] ?? $variantId);
            $visualAnchor     = trim((string)($contract['visual_anchor']            ?? ''));
            $materialAnchor   = trim((string)($contract['material_anchor']          ?? ''));
            $colorAtmosphere  = trim((string)($contract['required_color_atmosphere'] ?? ''));
            $spatialBehavior  = trim((string)($contract['required_spatial_behavior'] ?? ''));
            $archLanguage     = trim((string)($contract['required_architectural_language'] ?? ''));
            $sceneDirectives  = array_values(array_filter(array_map('strval', (array)($contract['scene_directives'] ?? []))));
            $forbiddenDrift   = array_values(array_filter(array_map('strval', (array)($contract['forbidden_visual_drift'] ?? []))));
            $worldDirective   = trim((string)($meta['context_world_directive'] ?? ''));
            $compatStatus     = (string)($meta['compatibility_status'] ?? 'not_evaluated');

            $lines[] = "Proposal {$proposal}:";
            $lines[] = "  assigned world:   {$worldId} ({$worldLabel})";
            $lines[] = "  assigned family:  {$familyId} ({$familyLabel})";
            $lines[] = "  assigned variant: {$variantId} ({$variantLabel})";
            if ($worldDirective !== '') {
                $lines[] = "  world directive: {$worldDirective}";
            }
            if ($visualAnchor !== '') {
                $lines[] = "  visual anchor: {$visualAnchor}";
            }
            if ($materialAnchor !== '') {
                $lines[] = "  material anchor: {$materialAnchor}";
            }
            if ($archLanguage !== '') {
                $lines[] = "  architectural language: {$archLanguage}";
            }
            if ($colorAtmosphere !== '') {
                $lines[] = "  required color atmosphere: {$colorAtmosphere}";
            }
            if ($spatialBehavior !== '') {
                $lines[] = "  required spatial behavior: {$spatialBehavior}";
            }
            if ($sceneDirectives !== []) {
                $lines[] = "  scene directives: " . implode('; ', $sceneDirectives);
            }
            if ($forbiddenDrift !== []) {
                $lines[] = "  FORBIDDEN in this proposal (do not use): " . implode('; ', $forbiddenDrift);
            }
            if ($compatStatus !== 'not_evaluated') {
                $lines[] = "  camera compatibility status: {$compatStatus}";
            }
            $lines[] = '';
        }

        $lines[] = 'WORLD-FIRST ENFORCEMENT RULES:';
        $lines[] = '- context_name MUST evoke the identity of the assigned world. Do not name a minimal contemporary world proposal with rustic, Mediterranean, Tuscan, warm plaster, villa, or cottage vocabulary.';
        $lines[] = '- space_type MUST belong to the assigned world typology (e.g. a midcentury world requires midcentury domestic or studio spaces, not Provençal or farmhouse rooms).';
        $lines[] = '- atmosphere MUST reflect the material temperature and lighting bias of the assigned world.';
        $lines[] = '- mockup_prompt MUST describe spatial and material elements confined to the assigned world. Do not import elements from other worlds.';
        $lines[] = '- Any term listed under FORBIDDEN must not appear in any text field of that proposal.';
        $lines[] = '- Each proposal is an independent world-confined scene. Do not carry vocabulary, materials, or spatial cues across proposals from different worlds.';
        $lines[] = '- The structural world assignment made here is final. Do not override it or produce context that contradicts it.';

        return implode("\n", $lines);
    }

    private function saveDebugPrompt(string $imagePath, string $prompt, string $suffix = ''): void
    {
        if (!defined('ANALYSIS_DIR')) {
            return;
        }

        if (!is_dir(ANALYSIS_DIR)) {
            @mkdir(ANALYSIS_DIR, 0775, true);
        }

        $base = pathinfo(basename($imagePath), PATHINFO_FILENAME);
        $tag  = $suffix !== '' ? ".analysis-prompt-{$suffix}.txt" : '.analysis-prompt.txt';
        $name = $base . $tag;
        @file_put_contents(ANALYSIS_DIR . DIRECTORY_SEPARATOR . $name, $prompt);
    }

    private function saveDebugText(string $imagePath, string $text, string $suffix): void
    {
        if (!defined('ANALYSIS_DIR')) {
            return;
        }

        if (!is_dir(ANALYSIS_DIR)) {
            @mkdir(ANALYSIS_DIR, 0775, true);
        }

        $name = pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.analysis-' . $suffix . '.txt';
        @file_put_contents(ANALYSIS_DIR . DIRECTORY_SEPARATOR . $name, $text);
    }

    private function mapCameraAngle(string $angle, string $distance = ''): string
    {
        return trim($angle);
    }

    private function mapCameraGroup(string $angle): string
    {
        return trim($angle);
    }

    private function mapTimeOfDay(string $lighting): string
    {
        $lighting = strtolower(trim($lighting));
        if (str_contains($lighting, 'night') || str_contains($lighting, 'evening') || str_contains($lighting, 'nocturnal')) {
            return 'night';
        }
        if (str_contains($lighting, 'afternoon') || str_contains($lighting, 'sunset') || str_contains($lighting, 'warm')) {
            return 'afternoon';
        }
        return 'day';
    }

    private function mapPlacement(string $spaceType): string
    {
        $spaceType = strtolower(trim($spaceType));
        if (str_contains($spaceType, 'detail') || str_contains($spaceType, 'texture')) {
            return 'detail';
        }
        if (str_contains($spaceType, 'lean')) {
            return 'leaning';
        }
        return 'hanging';
    }

    private function mapHumanProfile(string $presence): ?string
    {
        $presence = strtolower(trim($presence));
        if (str_contains($presence, 'none') || $presence === '') {
            return null;
        }
        if (str_contains($presence, 'female') || str_contains($presence, 'woman') || str_contains($presence, '1.55')) {
            return 'female_155';
        }
        return 'male_180';
    }

    private function extractJson(string $text): string
    {
        $text = trim($text);
        
        // First, try to extract from markdown code block
        if (preg_match('/```(?:json)?\s*\n(.*?)\n```/s', $text, $matches)) {
            $json = trim($matches[1]);
        } else {
            // Fallback: look for { and } directly
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/i', '', (string)$text);
            
            $start = strpos((string)$text, '{');
            $end = strpos((string)$text, "\n```");  // Look for the markdown close, not end of file
            
            if ($end === false) {
                $end = strrpos((string)$text, '}');
            } else {
                // Find the last } before the markdown close
                $beforeClose = substr((string)$text, 0, $end);
                $lastBrace = strrpos($beforeClose, '}');
                $end = $lastBrace;
            }
            
            if ($start !== false && $end !== false && $end > $start) {
                $json = substr((string)$text, $start, $end - $start + 1);
            } else {
                $json = (string)$text;
            }
        }
        
        // Clean non-ASCII that could break JSON parsing
        $replacements = [
            // Smart quotes (UTF-8 encoded)
            "\xE2\x80\x98" => "'",  // left single quotation mark
            "\xE2\x80\x99" => "'",  // right single quotation mark / apostrophe
            "\xE2\x80\x9C" => '"',  // left double quotation mark
            "\xE2\x80\x9D" => '"',  // right double quotation mark
            // Dashes
            "\xE2\x80\x93" => "-",  // en dash
            "\xE2\x80\x94" => "-",  // em dash
            // Ellipsis
            "\xE2\x80\xA6" => "...", // horizontal ellipsis
        ];
        
        $json = strtr($json, $replacements);
        
        // Remove any control characters and other problematic bytes
        $json = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $json);
        
        return $json;
    }

    private function normalizeAnalysisResponse(array $profile, array $imageMeta): array
    {
        $count = PromptSettings::mockupContextCount();
        $rootTitles = $this->normalizeSuggestedTitles($profile['suggested_titles'] ?? []);
        $legacyTitles = is_array($profile['artwork_analysis']['publishing_metadata']['suggested_titles'] ?? null)
            ? $profile['artwork_analysis']['publishing_metadata']['suggested_titles']
            : [];
        $schema = !empty($rootTitles) || array_key_exists('contextual_proposals', $profile) ? 'new_schema' : 'legacy_schema';

        $profile['suggested_titles'] = $rootTitles;
        $profile['recommended_number_of_contexts'] = $count;

        $proposals = is_array($profile['contextual_proposals'] ?? null) ? $profile['contextual_proposals'] : [];
        $profile['contextual_proposals'] = array_slice($proposals, 0, $count);

        Logger::log(sprintf(
            'Analysis schema detected=%s root_suggested_titles=%d legacy_suggested_titles=%d contextual_proposals=%d',
            $schema,
            count($rootTitles),
            count($legacyTitles),
            count($profile['contextual_proposals'])
        ), 'analysis_debug');

        foreach ($profile['suggested_titles'] as $index => $titleOption) {
            Logger::log(sprintf(
                'Title option %d title="%s" subtitle_length=%d description_length=%d description_source=%s fallback_description_used=no',
                $index + 1,
                (string)($titleOption['title'] ?? ''),
                strlen((string)($titleOption['subtitle'] ?? '')),
                strlen((string)($titleOption['description'] ?? '')),
                'suggested_titles.description'
            ), 'analysis_debug');
        }

        return $profile;
    }

    private function normalizeSuggestedTitles($titles): array
    {
        if (!is_array($titles)) {
            return [];
        }

        if (isset($titles['title'])) {
            $titles = [$titles];
        }

        $normalized = [];
        foreach ($titles as $titleOption) {
            if (!is_array($titleOption)) {
                continue;
            }

            $normalized[] = [
                'title' => trim((string)($titleOption['title'] ?? '')),
                'subtitle' => trim((string)($titleOption['subtitle'] ?? '')),
                'description' => trim((string)($titleOption['description'] ?? '')),
            ];
        }

        return $normalized;
    }

    private function resolveCameraSlotsForArtwork(int $artworkId, int $limit, ?array $cameraArchetypeResolution): array
    {
        $fallback = [
            'selected_set_id' => null,
            'slots' => [],
            'fallback_used' => true,
            'fallback_reason' => 'camera_slot_system_not_applicable',
        ];

        if ($limit !== 6) {
            $fallback['fallback_reason'] = 'proposal_count_not_6';
            return $fallback;
        }

        $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'mockup_camera_slots.php';
        if (!is_file($configPath)) {
            $fallback['fallback_reason'] = 'config_missing';
            return $fallback;
        }

        $config = require $configPath;
        if (!is_array($config)) {
            $fallback['fallback_reason'] = 'config_invalid';
            return $fallback;
        }

        $setId = (string)($config['default_slot_set_id'] ?? '');
        $set = $setId !== '' ? ($config['sets'][$setId] ?? null) : null;
        if (!is_array($set) || !is_array($set['slots'] ?? null) || count($set['slots']) < 6) {
            $fallback['fallback_reason'] = 'slot_set_missing_or_incomplete';
            return $fallback;
        }

        $resolution = $cameraArchetypeResolution;
        if (!is_array($resolution)) {
            try {
                $resolution = (new MockupCameraArchetypeResolver())->resolveForArtwork($artworkId);
            } catch (Throwable $e) {
                $fallback['fallback_reason'] = 'dimension_resolution_failed';
                return $fallback;
            }
        }

        $dims = is_array($resolution['dimensions'] ?? null) ? $resolution['dimensions'] : [];
        $sizeClass = strtolower(trim((string)($dims['size_class'] ?? '')));
        $orientation = strtolower(trim((string)($dims['orientation_resolved'] ?? 'unknown')));
        if ($sizeClass === '') {
            $fallback['fallback_reason'] = 'size_class_missing';
            return $fallback;
        }

        $slots = [];
        foreach (array_slice($set['slots'], 0, 6) as $position => $slotId) {
            $slotId = (string)$slotId;
            $slot = $config['slots'][$slotId] ?? null;
            if (!is_array($slot) || empty($slot['enabled'])) {
                $fallback['fallback_reason'] = 'slot_missing_or_disabled';
                return $fallback;
            }

            $supportedSizes = array_map('strtolower', array_map('strval', (array)($slot['size_classes_supported'] ?? [])));
            $supportedOrientations = array_map('strtolower', array_map('strval', (array)($slot['orientation_supported'] ?? [])));
            if ($supportedSizes && !in_array($sizeClass, $supportedSizes, true)) {
                $fallback['fallback_reason'] = 'size_class_not_supported';
                return $fallback;
            }
            if ($supportedOrientations && !in_array($orientation, $supportedOrientations, true)) {
                $fallback['fallback_reason'] = 'orientation_not_supported';
                return $fallback;
            }

            $slots[$position + 1] = $slot;
        }

        if (count($slots) !== 6) {
            $fallback['fallback_reason'] = 'slots_missing';
            return $fallback;
        }

        return [
            'selected_set_id' => $setId,
            'slots' => $slots,
            'fallback_used' => false,
            'fallback_reason' => '',
            'dimensions' => $dims,
        ];
    }

    private function composeCameraSlotGeometryBlock(array $slot): string
    {
        $humanBlock = trim((string)($slot['human_subject_block'] ?? ''));
        $lines = [
            'Camera Slot: ' . trim((string)($slot['slot_name'] ?? $slot['slot_id'] ?? '')),
            'Camera height: ' . trim((string)($slot['camera_height_block'] ?? '')),
            'Lens: ' . trim((string)($slot['lens_block'] ?? '')),
            'Vertical tilt: ' . trim((string)($slot['vertical_tilt_block'] ?? '')),
            'Lateral rotation: ' . trim((string)($slot['lateral_rotation_block'] ?? '')),
            'Composition: ' . trim((string)($slot['composition_block'] ?? '')),
            'Human subject: ' . ($humanBlock !== '' ? $humanBlock : 'No human figure requested by this camera slot.'),
            'Scale: ' . trim((string)($slot['scale_block'] ?? '')),
            'Depth of field: ' . trim((string)($slot['depth_of_field_block'] ?? '')),
        ];

        $negative = $slot['negative_directives'] ?? [];
        if (is_array($negative) && $negative !== []) {
            $lines[] = 'Slot negative directives: ' . implode('; ', array_map('strval', $negative));
        } elseif (is_string($negative) && trim($negative) !== '') {
            $lines[] = 'Slot negative directives: ' . trim($negative);
        }

        return implode("\n", array_values(array_filter($lines, static function (string $line): bool {
            return trim($line) !== '' && !preg_match('/:\s*$/', $line);
        })));
    }

    private function composeCameraSlotView(array $slot): string
    {
        $slotName = trim((string)($slot['slot_name'] ?? $slot['slot_id'] ?? 'Camera Slot'));
        $height = trim((string)($slot['camera_height_block'] ?? ''));
        $rotation = trim((string)($slot['lateral_rotation_block'] ?? ''));
        $composition = trim((string)($slot['composition_block'] ?? ''));

        return trim($slotName . ': ' . implode(' ', array_filter([$height, $rotation, $composition])));
    }

    private function minimalContextualProposal(array $proposal): array
    {
        $allowedKeys = [
            'context_name',
            'context_role',
            'space_type',
            'atmosphere',
            'materials',
            'lighting',
            'camera_view',
            'camera_distance',
            'camera_angle_notes',
            'human_presence',
            'curatorial_reason',
            'commercial_reason',
            'mockup_prompt',
            'negative_prompt',
            'camera_slot',
            'camera_group_expected',
            'camera_view_expected',
            'camera_distance_expected',
            'camera_angle_notes_expected',
            'camera_view_original',
            'camera_archetype_set_id',
            'camera_archetype_id',
            'camera_archetype_name',
            'camera_archetype_source',
            'camera_archetype_reason',
            'artwork_dimensions_source',
            'artwork_orientation_resolved',
            'artwork_aspect_ratio_resolved',
            'artwork_longest_side_cm',
            'artwork_size_class',
            'artwork_xl_class',
            'camera_slot_set_id',
            'camera_slot_id',
            'camera_slot_name',
            'camera_slot_enabled',
            'camera_height_block',
            'lens_block',
            'vertical_tilt_block',
            'lateral_rotation_block',
            'composition_block',
            'human_subject_block',
            'scale_block',
            'depth_of_field_block',
            'scene_affinity',
            'negative_directives',
            'camera_slot_geometry',
            'camera_slot_fallback_used',
            'world_validation_passed',
            'world_forbidden_terms_found',
            'world_retry_applied',
            'world_retry_count',
            'world_validation_version',
        ];

        $minimal = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $proposal)) {
                $minimal[$key] = $proposal[$key];
            }
        }

        return $minimal;
    }

    private function logCompiledPromptDebug(string $prompt, string $context): void
    {
        $remaining = $this->remainingPromptPlaceholders($prompt);
        $oldTerms = $this->oldPromptTermsFound($prompt);
        $containsMinimalSchema = str_contains($prompt, 'suggested_titles')
            && str_contains($prompt, 'recommended_number_of_contexts')
            && str_contains($prompt, 'contextual_proposals');
        Logger::log($context . ' compiled prompt after variable replacement:' . "\n" . $prompt, 'prompt_debug');
        Logger::log(sprintf(
            '%s first_500="%s" contains_minimal_schema=%s unreplaced_placeholders=%s old_terms=%s',
            $context,
            substr($prompt, 0, 500),
            $containsMinimalSchema ? 'yes' : 'no',
            $remaining ? implode(', ', $remaining) : 'none',
            $oldTerms ? implode(', ', $oldTerms) : 'none'
        ), $remaining ? 'warning' : 'prompt_debug');
    }

    private function logPromptSourceDebug(string $template): void
    {
        $source = 'built_in_default';
        $updatedAt = '';

        try {
            $stmt = Database::connection()->prepare("SELECT updated_at, value FROM app_settings WHERE `key` = :key LIMIT 1");
            $stmt->execute(['key' => 'artwork_analysis_prompt']);
            $row = $stmt->fetch();
            if (is_array($row) && trim((string)($row['value'] ?? '')) !== '') {
                $source = 'app_settings.artwork_analysis_prompt';
                $updatedAt = (string)($row['updated_at'] ?? '');
            }
        } catch (Throwable $e) {
            Logger::log('Could not read prompt source metadata: ' . $e->getMessage(), 'warning');
        }

        Logger::log(sprintf(
            'Prompt source=%s prompt_name=artwork_analysis_prompt prompt_id=%s updated_at=%s template_first_500="%s"',
            $source,
            $source,
            $updatedAt !== '' ? $updatedAt : 'n/a',
            substr($template, 0, 500)
        ), 'prompt_debug');
    }

    private function logRawJsonDebug(string $json): void
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            Logger::log('Raw Gemini JSON could not be decoded for root-key debug.', 'warning');
            return;
        }

        $keys = array_keys($decoded);
        $expected = ['suggested_titles', 'recommended_number_of_contexts', 'contextual_proposals'];
        $onlyExpected = empty(array_diff($keys, $expected));
        $oldKeys = array_values(array_intersect($keys, [
            'artwork_analysis',
            'publishing_metadata',
            'technical_metadata',
            'catawiki_listing',
            'pinterest_marketing',
            'seo_keywords',
            'seo_tags',
            'marketplace_title',
            'short_description',
            'curatorial_description',
            'commercial_description',
        ]));

        Logger::log(sprintf(
            'Raw Gemini JSON root_keys=%s only_minimal_root_keys=%s old_root_keys=%s suggested_titles=%d contextual_proposals=%d',
            implode(', ', $keys),
            $onlyExpected ? 'yes' : 'no',
            $oldKeys ? implode(', ', $oldKeys) : 'none',
            count((array)($decoded['suggested_titles'] ?? [])),
            count((array)($decoded['contextual_proposals'] ?? []))
        ), $onlyExpected ? 'analysis_debug' : 'warning');
    }

    private function oldPromptTermsFound(string $text): array
    {
        $terms = [
            'publishing_metadata',
            'artwork_analysis',
            'Catawiki',
            'Pinterest',
            'SEO',
            'marketplace',
            'short_description',
            'curatorial_description',
            'commercial_description',
        ];

        return array_values(array_filter($terms, static fn(string $term): bool => stripos($text, $term) !== false));
    }

    private function remainingPromptPlaceholders(string $prompt): array
    {
        preg_match_all('/\{(?:artist_profile_prompt|artist_statement|visual_language|recurring_symbols|preferred_atmospheres)\}/', $prompt, $matches);
        return array_values(array_unique($matches[0] ?? []));
    }

    private function imageMeta(string $path, array $metadata): array
    {
        // Guard: getimagesize('') throws a ValueError in PHP 8 even with @ suppression.
        // If path is empty or file does not exist, return safe zero defaults.
        $width = 0;
        $height = 0;
        if ($path !== '' && is_file($path)) {
            $size = @getimagesize($path);
            $width  = $size ? (int)$size[0] : 0;
            $height = $size ? (int)$size[1] : 0;
        } elseif ($path !== '') {
            Logger::log("imageMeta: path is set but file does not exist: {$path}", 'warning');
        }

        $orientation = 'unknown';
        if ($width > 0 && $height > 0) {
            $orientation = $width > $height ? 'horizontal' : ($height > $width ? 'vertical' : 'square');
        }

        return [
            'width_px'    => $width,
            'height_px'   => $height,
            'orientation' => $orientation,
            'aspect_ratio' => $height > 0 ? round($width / $height, 4) : null,
            'physical_size' => [
                'width_cm'  => $metadata['width_cm']  ?? $metadata['width']  ?? null,
                'height_cm' => $metadata['height_cm'] ?? $metadata['height'] ?? null,
                'depth_cm'  => $metadata['depth_cm']  ?? $metadata['depth']  ?? null,
            ],
        ];
    }

    private function appendContextWorldText(string $base, string $addition): string
    {
        $base = trim($base);
        $addition = trim($addition);
        if ($addition === '') {
            return $base;
        }
        if ($base === '') {
            return $addition;
        }
        if (stripos($base, $addition) !== false) {
            return $base;
        }

        return rtrim($base, " \t\n\r\0\x0B.;") . '; ' . $addition;
    }

    private function appendContextWorldMaterials($materials, array $additions): array
    {
        $merged = [];
        foreach ((array)$materials as $material) {
            $material = trim((string)$material);
            if ($material !== '') {
                $merged[] = $material;
            }
        }
        foreach ($additions as $addition) {
            $addition = trim((string)$addition);
            if ($addition !== '') {
                $merged[] = $addition;
            }
        }

        return array_values(array_unique($merged));
    }

    private function formatWorldNegativeContextControls(array $controls): string
    {
        $formatted = [];
        foreach ($controls as $control) {
            $control = trim((string)$control);
            if ($control === '') {
                continue;
            }

            $lower = strtolower($control);
            $isExplicitNegative = (bool)preg_match('/^(?:no|without|avoid|exclude|excluding|forbid|forbidden|never|not)\b/', $lower)
                || str_starts_with($lower, 'no_');
            $isAffirmativePreservation = str_contains($lower, 'artwork remains')
                || str_contains($lower, 'artwork_must_remain')
                || str_contains($lower, 'artwork_remains')
                || str_contains($lower, 'context supports')
                || str_contains($lower, 'commercially_usable')
                || str_contains($lower, 'lifestyle_secondary');

            if (!$isExplicitNegative && !$isAffirmativePreservation) {
                $control = 'no ' . $control;
            }

            $formatted[] = $control;
        }

        return implode('; ', array_values(array_unique($formatted)));
    }

    private function saveToDatabase(int $artworkId, array $analysisData, array $proposals): void
    {
        $pdo = Database::connection();
        $now = date('c');

        // 1. Insertar en artwork_analysis
        $stmtAnalysis = $pdo->prepare("
            INSERT INTO artwork_analysis (artwork_id, provider, analysis_json, created_at)
            VALUES (:artwork_id, :provider, :analysis_json, :created_at)
        ");
        $stmtAnalysis->execute([
            'artwork_id' => $artworkId,
            'provider' => 'gemini',
            'analysis_json' => json_encode($analysisData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
        ]);
        $analysisId = (int)$pdo->lastInsertId();

        // 2. Insertar en mockup_contexts
        $stmtContext = $pdo->prepare("
            INSERT INTO mockup_contexts (artwork_id, analysis_id, context_name, context_json, prompt, created_at)
            VALUES (:artwork_id, :analysis_id, :context_name, :context_json, :prompt, :created_at)
        ");

        $contextWorldRegistry = null;
        try {
            $contextWorldRegistry = new MockupContextWorldRegistry();
            $registryValidation = $contextWorldRegistry->validation();
            if (empty($registryValidation['ok'])) {
                Logger::log('Context Worlds Registry validation failed; passive metadata will use safe null values: ' . implode('; ', (array)($registryValidation['errors'] ?? [])), 'warning');
                $contextWorldRegistry = null;
            }
        } catch (Throwable $e) {
            Logger::log('Context Worlds Registry could not be loaded; passive metadata will use safe null values: ' . $e->getMessage(), 'warning');
            $contextWorldRegistry = null;
        }

        foreach ($proposals as $index => $prop) {
            $mapped = $prop['mapped_context'] ?? [];
            $contextJson = [
                'context_role' => $prop['context_role'] ?? '',
                'space_type' => $prop['space_type'] ?? '',
                'atmosphere' => $prop['atmosphere'] ?? '',
                'materials' => $prop['materials'] ?? [],
                'lighting' => $prop['lighting'] ?? '',
                'camera_angle' => $mapped['camera'] ?? ($prop['camera_angle'] ?? ''),
                'camera_group' => $mapped['camera_group'] ?? '',
                'camera_view' => $mapped['camera_view'] ?? ($prop['camera_view'] ?? ''),
                'camera_distance' => $mapped['camera_distance'] ?? ($prop['camera_distance'] ?? ''),
                'camera_angle_notes' => $mapped['camera_angle_notes'] ?? ($prop['camera_angle_notes'] ?? ''),
                'time_of_day' => $mapped['time_of_day'] ?? '',
                'placement' => $mapped['placement'] ?? 'hanging',
                'human_presence' => $prop['human_presence'] ?? 'none',
                'human_profile' => $mapped['human_profile'] ?? null,
                'curatorial_reason' => $prop['curatorial_reason'] ?? '',
                'commercial_reason' => $prop['commercial_reason'] ?? '',
                'mockup_prompt' => $mapped['mockup_prompt'] ?? ($prop['mockup_prompt'] ?? ''),
                'negative_prompt' => $mapped['negative_prompt'] ?? ($prop['negative_prompt'] ?? ''),
            ];

            // Save deterministic fields inside context_json if present in $prop
            if (isset($prop['camera_slot'])) {
                $contextJson['camera_slot'] = $prop['camera_slot'];
                $contextJson['camera_group_expected'] = $prop['camera_group_expected'];
                $contextJson['camera_view_expected'] = $prop['camera_view_expected'];
                $contextJson['camera_distance_expected'] = $prop['camera_distance_expected'];
                $contextJson['camera_angle_notes_expected'] = $prop['camera_angle_notes_expected'];
                $contextJson['camera_view_original'] = $prop['camera_view_original'];
            }

            foreach ([
                'camera_archetype_set_id',
                'camera_archetype_id',
                'camera_archetype_name',
                'camera_archetype_source',
                'camera_archetype_reason',
                'artwork_dimensions_source',
                'artwork_orientation_resolved',
                'artwork_aspect_ratio_resolved',
                'artwork_longest_side_cm',
                'artwork_size_class',
                'artwork_xl_class',
                'camera_slot_set_id',
                'camera_slot_id',
                'camera_slot_name',
                'camera_slot_enabled',
                'camera_height_block',
                'lens_block',
                'vertical_tilt_block',
                'lateral_rotation_block',
                'composition_block',
                'human_subject_block',
                'scale_block',
                'depth_of_field_block',
                'scene_affinity',
                'negative_directives',
                'camera_slot_geometry',
                'camera_slot_fallback_used',
            ] as $archetypeField) {
                if (array_key_exists($archetypeField, $mapped)) {
                    $contextJson[$archetypeField] = $mapped[$archetypeField];
                }
            }

            $cameraSlotId = trim((string)($mapped['camera_slot_id'] ?? ''));
            $passiveWorldMetadata = [
                'context_world_id' => null,
                'context_family_id' => null,
                'scene_variant_id' => null,
                'world_status' => null,
                'family_status' => null,
                'variant_status' => null,
                'default_rotation' => false,
                'context_tags' => [],
                'presence_policy' => ['world' => [], 'family' => []],
                'risk_controls' => [],
                'placement_mode' => null,
                'compatibility_decision' => [
                    'status' => 'not_evaluated',
                    'world_id' => null,
                    'camera_slot_id' => $cameraSlotId !== '' ? $cameraSlotId : null,
                    'reason' => 'context_world_registry_unavailable',
                ],
                'context_world_directive' => '',
                'context_family_directive' => '',
                'scene_variant_directive' => '',
                'world_architecture_language' => '',
                'world_wall_language' => '',
                'world_floor_language' => '',
                'world_ceiling_language' => '',
                'world_lighting_bias' => '',
                'world_material_temperature' => '',
                'world_scale_behavior' => '',
                'world_negative_context_controls' => [
                    'artwork remains the primary visual subject',
                    'context supports the artwork, never replaces it',
                    'no artwork substitution',
                    'no scale distortion',
                    'no decorative dominance',
                ],
                'world_risk_controls' => [
                    'artwork remains the primary visual subject',
                    'context supports the artwork, never replaces it',
                    'no artwork substitution',
                    'no scale distortion',
                    'no decorative dominance',
                ],
                'world_presence_policy' => ['world' => [], 'family' => []],
            ];

            if ($contextWorldRegistry instanceof MockupContextWorldRegistry) {
                try {
                    $passiveWorldMetadata = $contextWorldRegistry->metadataForDefaultIndex(
                        (int)$index,
                        $cameraSlotId !== '' ? $cameraSlotId : null
                    );
                } catch (Throwable $e) {
                    Logger::log('Context Worlds passive metadata fallback for artwork_id=' . $artworkId . ': ' . $e->getMessage(), 'warning');
                }
            }

            foreach ([
                'context_world_id',
                'context_family_id',
                'scene_variant_id',
                'world_status',
                'family_status',
                'variant_status',
                'default_rotation',
                'context_tags',
                'presence_policy',
                'risk_controls',
                'placement_mode',
                'compatibility_decision',
                'context_world_directive',
                'context_family_directive',
                'scene_variant_directive',
                'world_architecture_language',
                'world_wall_language',
                'world_floor_language',
                'world_ceiling_language',
                'world_lighting_bias',
                'world_material_temperature',
                'world_scale_behavior',
                'world_negative_context_controls',
                'world_risk_controls',
                'world_presence_policy',
            ] as $worldMetadataField) {
                $contextJson[$worldMetadataField] = $passiveWorldMetadata[$worldMetadataField] ?? null;
            }

            $contextJson['atmosphere'] = $this->appendContextWorldText(
                (string)($contextJson['atmosphere'] ?? ''),
                (string)($passiveWorldMetadata['scene_variant_directive'] ?? '')
            );
            $contextJson['lighting'] = $this->appendContextWorldText(
                (string)($contextJson['lighting'] ?? ''),
                (string)($passiveWorldMetadata['world_lighting_bias'] ?? '')
            );
            $contextJson['materials'] = $this->appendContextWorldMaterials(
                $contextJson['materials'] ?? [],
                [
                    $passiveWorldMetadata['world_architecture_language'] ?? '',
                    $passiveWorldMetadata['world_wall_language'] ?? '',
                    $passiveWorldMetadata['world_floor_language'] ?? '',
                    $passiveWorldMetadata['world_ceiling_language'] ?? '',
                    $passiveWorldMetadata['world_material_temperature'] ?? '',
                    $passiveWorldMetadata['world_scale_behavior'] ?? '',
                ]
            );
            $contextJson['placement'] = $this->appendContextWorldText(
                (string)($contextJson['placement'] ?? ''),
                (string)($passiveWorldMetadata['placement_mode'] ?? '')
            );
            $contextJson['negative_prompt'] = $this->appendContextWorldText(
                (string)($contextJson['negative_prompt'] ?? ''),
                $this->formatWorldNegativeContextControls((array)($passiveWorldMetadata['world_negative_context_controls'] ?? []))
            );

            // World-isolated trazability — persisted in context_json if present in $prop
            foreach ([
                'generation_mode',
                'world_generation_index',
                'world_generation_version',
                'assigned_world_id',
                'assigned_family_id',
                'assigned_variant_id',
                'world_prompt_isolated',
                'world_validation_passed',
                'world_forbidden_terms_found',
                'world_retry_applied',
                'world_retry_count',
                'world_validation_version',
                'camera_first_enabled',
                'camera_first_version',
                'camera_first_slot_id',
                'camera_first_slot_name',
                'camera_first_source',
                'camera_first_fallback_used',
                'camera_first_fallback_reason',
                'camera_first_camera_view',
            ] as $traceField) {
                if (array_key_exists($traceField, $prop)) {
                    $contextJson[$traceField] = $prop[$traceField];
                }
            }

            // CAMERA-FIRST: compare the camera resolved before world generation with the final
            // camera slot resolved by generateMockupPrompts(). They should match when dimensions agree.
            $cameraFirstSlotId = (string)($prop['camera_first_slot_id'] ?? '');
            $finalCameraSlotId = (string)($mapped['camera_slot_id'] ?? $contextJson['camera_slot_id'] ?? '');
            $contextJson['camera_first_matches_final_camera'] =
                ($cameraFirstSlotId !== '' && $finalCameraSlotId !== '' && $cameraFirstSlotId === $finalCameraSlotId);

            // Phase 2.9 — canonical world identity (ADDITIVE; new contexts only).
            // Old context_json keys are preserved untouched for backward compatibility.
            $identity = MockupContextIdentity::resolve($contextJson);
            $contextJson['selected_world_id']   = $identity['selected_world_id'];
            $contextJson['selected_family_id']  = $identity['selected_family_id'];
            $contextJson['selected_variant_id'] = $identity['selected_variant_id'];
            $contextJson['identity_source']     = $identity['identity_source'];

            // Phase 2.9 — Vital Presence (ADDITIVE). Prefer the object resolved during
            // prompt composition; otherwise derive from the persisted presence policy.
            if (isset($prop['vital_presence']) && is_array($prop['vital_presence'])) {
                $contextJson['vital_presence'] = $prop['vital_presence'];
            } else {
                $contextJson['vital_presence'] = MockupVitalPresenceResolver::resolveFromContext($contextJson);
            }

            $stmtContext->execute([
                'artwork_id' => $artworkId,
                'analysis_id' => $analysisId,
                'context_name' => $prop['context_name'] ?? 'Custom Context',
                'context_json' => json_encode($contextJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'prompt' => $prop['prompt'] ?? '',
                'created_at' => $now,
            ]);
        }
    }
}
