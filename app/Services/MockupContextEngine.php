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
                $profile = json_decode($json, true);

                if (!is_array($profile) || empty($profile['contextual_proposals'])) {
                    throw new RuntimeException('Gemini no devolvió un JSON con propuestas válidas.');
                }

                $normalized = $this->normalizeAnalysisResponse($profile, $imageMeta);
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
                'placement' => $this->mapPlacement($prop['space_type'] ?? 'wall'),
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

        // Guardar el análisis y las propuestas dinámicas en la base de datos
        $this->saveToDatabase($artworkId, $artworkAnalysis, $finalProposals);

        return [
            'artwork_analysis' => $artworkAnalysis,
            'recommended_number_of_contexts' => count($finalProposals),
            'contextual_proposals' => $finalProposals,
        ];
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
        
        // Dynamically replace context count in prompt template
        $template = preg_replace(
            '/"recommended_number_of_contexts":\s*\d+/',
            '"recommended_number_of_contexts": ' . $contextCount,
            $template
        );
        $template = str_replace('{context_count}', (string)$contextCount, $template);

        return str_replace(
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
        $angle = strtolower(trim($angle));
        $distance = strtolower(trim($distance));
        $distanceText = match (true) {
            str_contains($distance, 'close-up') || str_contains($distance, 'close up') => 'close-up view, artwork dominant, room only suggested',
            str_contains($distance, 'medium') => 'medium-close view, enough space for context and scale while keeping artwork dominant',
            default => 'medium-close view, artwork dominant in frame',
        };

        if (str_contains($angle, 'three-quarter') || str_contains($angle, '3/4') || str_contains($angle, 'quarter')) {
            if (str_contains($angle, 'left')) return 'three-quarter view from the left, slight side angle, eye-level, natural perspective, ' . $distanceText;
            if (str_contains($angle, 'right')) return 'three-quarter view from the right, slight side angle, eye-level, natural perspective, ' . $distanceText;
            return 'three-quarter view from the left, slight side angle, eye-level, natural perspective, ' . $distanceText;
        }
        if (str_contains($angle, 'close') || str_contains($angle, 'detail') || str_contains($angle, 'texture')) {
            return 'close-up detail shot of canvas texture, painted surface, brushwork and artwork edge';
        }
        if (str_contains($angle, 'low') || str_contains($angle, 'hero')) {
            return 'subtle low-angle hero shot, slightly below eye level, artwork powerful but not distorted';
        }
        if (str_contains($angle, 'human') || str_contains($angle, 'figure') || str_contains($angle, 'scale')) {
            return 'medium interior shot with discreet human figure for scale, artwork remains the main subject';
        }
        return 'front-facing shot, eye-level, balanced interior context, artwork dominant in frame, ' . $distanceText;
    }

    private function mapCameraGroup(string $angle): string
    {
        $angle = strtolower(trim($angle));
        if (str_contains($angle, 'three-quarter') || str_contains($angle, '3/4') || str_contains($angle, 'quarter')) {
            if (str_contains($angle, 'left')) return 'three_quarter_left';
            if (str_contains($angle, 'right')) return 'three_quarter_right';
            return 'three_quarter_left';
        }
        return 'front_medium';
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
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', (string)$text);

        $start = strpos((string)$text, '{');
        $end = strrpos((string)$text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            return substr((string)$text, $start, $end - $start + 1);
        }

        return (string)$text;
    }

    private function normalizeAnalysisResponse(array $profile, array $imageMeta): array
    {
        $count = PromptSettings::mockupContextCount();
        $profile['recommended_number_of_contexts'] = $count;

        $proposals = is_array($profile['contextual_proposals'] ?? null) ? $profile['contextual_proposals'] : [];
        $profile['contextual_proposals'] = array_slice($proposals, 0, $count);

        return $profile;
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
                'mockup_metadata' => $prop['mockup_metadata'] ?? [],
                'pinterest_marketing' => $prop['mockup_metadata'] ?? $prop['pinterest_marketing'] ?? [],
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
