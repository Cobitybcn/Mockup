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

        $finalProposals = [];

        $promptBuilder = new MockPromptBuilder();

        foreach ($proposals as $prop) {
            $cameraView = trim((string)($prop['camera_view'] ?? $prop['camera_angle'] ?? 'front view'));
            $cameraDistance = trim((string)($prop['camera_distance'] ?? 'medium-close view'));
            $cameraNotes = trim((string)($prop['camera_angle_notes'] ?? ''));
            $mockupPrompt = trim((string)($prop['mockup_prompt'] ?? ''));
            $negativePrompt = trim((string)($prop['negative_prompt'] ?? ''));

            // Mapear la propuesta dinámica de la IA al formato de MockPromptBuilder
            $mappedContext = [
                'name' => $prop['context_name'] ?? 'Custom Context',
                'purpose' => $prop['context_role'] ?? 'presentation',
                'scene' => "A " . ($prop['space_type'] ?? 'interior') . " with " . ($prop['atmosphere'] ?? 'neutral') . " atmosphere. Materials: " . implode(', ', (array)($prop['materials'] ?? [])),
                'lighting' => $prop['lighting'] ?? 'soft light',
                'camera' => $this->mapCameraAngle($cameraView, $cameraDistance),
                'camera_group' => $this->mapCameraGroup($cameraView),
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
        $size = @getimagesize($path);
        $width = $size ? (int)$size[0] : 0;
        $height = $size ? (int)$size[1] : 0;
        $orientation = 'unknown';

        if ($width > 0 && $height > 0) {
            $orientation = $width > $height ? 'horizontal' : ($height > $width ? 'vertical' : 'square');
        }

        return [
            'width_px' => $width,
            'height_px' => $height,
            'orientation' => $orientation,
            'aspect_ratio' => $height > 0 ? round($width / $height, 4) : null,
            'physical_size' => [
                'width_cm' => $metadata['width_cm'] ?? $metadata['width'] ?? null,
                'height_cm' => $metadata['height_cm'] ?? $metadata['height'] ?? null,
                'depth_cm' => $metadata['depth_cm'] ?? $metadata['depth'] ?? null,
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
