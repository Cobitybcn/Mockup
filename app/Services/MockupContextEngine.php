<?php
declare(strict_types=1);

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
                'camera_group_expected' => 'near_frontal_subtle_3_4',
                'camera_view_expected' => 'near-frontal physical editorial view, subtle 5–10 degrees, not poster-flat',
                'camera_distance_expected' => 'close or medium-close premium commercial presentation',
                'camera_angle_notes_expected' => 'A near-frontal physical editorial view (5-10 degrees oblique) to show canvas depth and avoid a flat poster-like presentation.',
            ],
            2 => [
                'camera_slot' => 2,
                'camera_group_expected' => 'soft_left_oblique',
                'camera_view_expected' => 'three-quarter left view, soft left-oblique, 10–15 degrees',
                'camera_distance_expected' => 'close or medium-close view',
                'camera_angle_notes_expected' => 'A gentle left-oblique angle that shows canvas depth and gives the artwork stronger physical presence without distorting scale.',
            ],
            3 => [
                'camera_slot' => 3,
                'camera_group_expected' => 'soft_right_oblique',
                'camera_view_expected' => 'three-quarter right view, soft right-oblique, 10–15 degrees',
                'camera_distance_expected' => 'close or medium-close view',
                'camera_angle_notes_expected' => 'A gentle right-oblique angle that shows canvas depth and gives the artwork stronger physical presence without distorting scale.',
            ],
            4 => [
                'camera_slot' => 4,
                'camera_group_expected' => 'elevated_high_angle_architectural',
                'camera_view_expected' => 'elevated high-angle architectural view, controlled view from above, not top-down, not surveillance-like',
                'camera_distance_expected' => 'controlled architectural view',
                'camera_angle_notes_expected' => 'An elevated high-angle view looking down at the artwork in relation to the space, showing the floor and surrounding architectural details without flattening the geometry.',
            ],
            5 => [
                'camera_slot' => 5,
                'camera_group_expected' => 'controlled_low_angle_contrapicado',
                'camera_view_expected' => 'controlled low-angle / contrapicado, camera slightly below artwork centerline, looking upward, believable scale',
                'camera_distance_expected' => 'medium-close view',
                'camera_angle_notes_expected' => 'A controlled low-angle camera positioned slightly below the artwork centerline, looking upward to add monumentality while keeping furniture and floor scale realistic.',
            ],
            6 => [
                'camera_slot' => 6,
                'camera_group_expected' => 'low_floor_wide_7_8_architectural',
                'camera_view_expected' => 'low floor wide 7/8 architectural view, camera near floor level, stronger oblique depth, controlled wide view, no fisheye, no scale distortion',
                'camera_distance_expected' => 'controlled wide architectural view',
                'camera_angle_notes_expected' => 'A wide-angle camera situated close to floor level looking at a 7/8 oblique depth to show three-dimensional space, wall/floor intersection, and clean perspective lines.',
            ]
        ];

        $finalProposals = [];

        $promptBuilder = new MockPromptBuilder();
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
                
                // Overwrite camera fields for compatibility with legacy components and coherence
                $prop['camera_view'] = $spec['camera_view_expected'];
                $prop['camera_distance'] = $spec['camera_distance_expected'];
                $prop['camera_angle_notes'] = $spec['camera_angle_notes_expected'];
            }

            $cameraView = trim((string)($prop['camera_view'] ?? $prop['camera_angle'] ?? 'near-frontal subtle 3/4 commercial view'));
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
                
                // 2. Replace any internal "Camera view is ..." or "Camera: ..." sentence
                $cameraSentencePattern = '/Camera\s*(?::|view\s+is|angle\s+is|direction\s+is)\s+[^.]+./i';
                $replacementSentence = "Camera view is " . $spec['camera_view_expected'] . ".";
                if (preg_match($cameraSentencePattern, $mockupPrompt)) {
                    $mockupPrompt = preg_replace($cameraSentencePattern, $replacementSentence, $mockupPrompt);
                } else {
                    $mockupPrompt .= " " . $replacementSentence;
                }
            }

            // 3. Clean/sanitize any artwork redescriptions to prevent redundant/conflicting details
            // Replace: The artwork '[Title]' ([Description of colors/geometric/style]) -> The artwork
            $mockupPrompt = preg_replace('/(?:The\s+)?(?:artwork|painting|canvas)\s+\'[^\']+\'\s*\([^)]*\)/i', 'The artwork', $mockupPrompt);
            $mockupPrompt = preg_replace('/(?:The\s+)?(?:artwork|painting|canvas)\s*\([^)]*\)/i', 'The artwork', $mockupPrompt);
            
            // Replace: An abstract artwork with [colors/shapes...] or A vibrant artwork, rich in... -> The artwork (prior to hangs/is hung/is displayed/etc or comma + participle)
            $mockupPrompt = preg_replace('/(?:An?\s+)?(?:[a-z\-]+\s+){0,3}(?:artwork|painting|canvas)(?:\s*(?:with|featuring|showing|having|depicting)|,\s*rich\s+in)\s+.*?(?=\s+(?:hangs|is\s+hung|is\s+elegantly\s+displayed|is\s+displayed|is\s+mounted|is\s+placed)|\s*,\s*(?:[a-z]+\s+)?(?:installed|leaning|placed|mounted|hung|displayed))/i', 'The artwork', $mockupPrompt);
            
            // Remove sentences starting with "The painting features/has..." or "The artwork features/has..."
            $mockupPrompt = preg_replace('/(?:The\s+)?(?:artwork|painting|canvas)\s+(?:features|has|depicts|portrays)\s+[^.]*\./i', '', $mockupPrompt);

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
            'near_frontal_subtle_3_4',
            'soft_left_oblique',
            'soft_right_oblique',
            'controlled_low_3_4',
            'stronger_artistic_3_4_7_8',
            'elevated_3_4_architectural',
        ];
        $expectedLabels = [
            'near-frontal subtle 3/4 commercial view',
            'soft left-oblique view',
            'soft right-oblique view',
            'controlled low 3/4 view',
            'stronger artistic 3/4 or 7/8 view',
            'elevated 3/4 architectural view',
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
                . "1. Proposal 1: camera_view must be 'front view'\n"
                . "2. Proposal 2: camera_view must be 'three-quarter left view'\n"
                . "3. Proposal 3: camera_view must be 'three-quarter right view'\n"
                . "4. Proposal 4: camera_view must be 'high-angle view'\n"
                . "5. Proposal 5: camera_view must be 'low-angle view'\n"
                . "6. Proposal 6: camera_view must be 'low floor wide low-angle view'\n"
                . "Ensure the space description, materials, lighting, and placement naturally match the perspective of each camera view.";
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

    private function saveDebugPrompt(string $imagePath, string $prompt): void
    {
        if (!defined('ANALYSIS_DIR')) {
            return;
        }

        if (!is_dir(ANALYSIS_DIR)) {
            @mkdir(ANALYSIS_DIR, 0775, true);
        }

        $name = pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.analysis-prompt.txt';
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

        foreach ($proposals as $prop) {
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
