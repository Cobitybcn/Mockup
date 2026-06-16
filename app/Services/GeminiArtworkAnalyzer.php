<?php
declare(strict_types=1);

class GeminiArtworkAnalyzer implements ArtworkAnalyzerInterface
{
    private ContextSelectorInterface $contextSelector;
    private GeminiImageClient $client;

    public function __construct(?ContextSelectorInterface $contextSelector = null, ?GeminiImageClient $client = null)
    {
        $this->contextSelector = $contextSelector ?: new MockContextSelector(new MockPromptBuilder());
        $this->client = $client ?: new GeminiImageClient();
    }

    public function analyze(string $imagePath, array $metadata = []): array
    {
        if (!is_file($imagePath)) {
            throw new RuntimeException('No se encontro la imagen para analizar.');
        }

        $imageMeta = $this->imageMeta($imagePath, $metadata);
        $analysisWarning = null;
        $t0 = microtime(true);
        Logger::log('Iniciando analisis Gemini para obra: ' . basename($imagePath), 'gemini');

        try {
            $profile = $this->callGeminiAnalysis($imagePath, $metadata, $imageMeta);
            $elapsed = round(microtime(true) - $t0, 2);
            Logger::log('Analisis Gemini exitoso en ' . $elapsed . 's para: ' . basename($imagePath), 'gemini');
        } catch (Throwable $e) {
            $elapsed = round(microtime(true) - $t0, 2);
            $analysisWarning = 'Gemini analysis fallback used: ' . $e->getMessage();
            Logger::log('Analisis Gemini fallo despues de ' . $elapsed . 's, usando fallback. Error: ' . $e->getMessage(), 'warning');
            $profile = $this->fallbackProfile($metadata);
        }

        $profile['_artist_profile'] = is_array($metadata['artist_profile'] ?? null) ? $metadata['artist_profile'] : [];
        $profile['_artist_profile_prompt'] = trim((string)($metadata['artist_profile_prompt'] ?? ''));
        $profile['_artist_profile_updated_at'] = (string)($profile['_artist_profile']['updated_at'] ?? '');

        $contextCount = PromptSettings::mockupContextCount();
        $contexts = $this->contextSelector->select($profile, $imageMeta, $contextCount);

        return [
            'ok' => true,
            'mode' => 'gemini',
            'context_count' => $contextCount,
            'analysis_warning' => $analysisWarning,
            'image' => [
                'file' => basename($imagePath),
                'path' => $imagePath,
                'width_px' => $imageMeta['width_px'],
                'height_px' => $imageMeta['height_px'],
                'orientation' => $imageMeta['orientation'],
                'aspect_ratio' => $imageMeta['aspect_ratio'],
                'physical_size' => $imageMeta['physical_size'],
            ],
            'artwork_profile' => $profile,
            'recommended_contexts' => $contexts,
        ];
    }

    private function callGeminiAnalysis(string $imagePath, array $metadata, array $imageMeta): array
    {
        $notes = trim((string)($metadata['artist_notes'] ?? ''));
        $region = trim((string)($metadata['region'] ?? ''));
        $scaleText = trim((string)($metadata['scale_text'] ?? ''));
        $artistProfile = is_array($metadata['artist_profile'] ?? null) ? $metadata['artist_profile'] : [];
        $artistProfilePrompt = trim((string)($metadata['artist_profile_prompt'] ?? ''));
        $targetMarket = trim((string)($metadata['target_market'] ?? 'collectors'));
        $preferredStyle = trim((string)($metadata['preferred_style'] ?? ''));

        $contextCount = PromptSettings::mockupContextCount();
        $template = PromptSettings::artworkAnalysisPrompt();
        
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
                '{region}',
                '{scale_text}',
                '{orientation}',
                '{width_px}',
                '{height_px}',
                '{notes}',
                '{preferred_style}',
                '{target_market}'
            ],
            [
                $artistProfilePrompt,
                (string)($artistProfile['statement'] ?? ''),
                (string)($artistProfile['visual_language'] ?? ''),
                (string)($artistProfile['recurring_themes'] ?? ''),
                (string)($artistProfile['palette_notes'] ?? ''),
                $metadata['title'] ?? 'Untitled',
                (string)($imageMeta['physical_size']['width_cm'] ?? ''),
                (string)($imageMeta['physical_size']['height_cm'] ?? ''),
                (string)($imageMeta['physical_size']['depth_cm'] ?? ''),
                $region,
                $scaleText,
                $imageMeta['orientation'],
                (string)$imageMeta['width_px'],
                (string)$imageMeta['height_px'],
                $notes,
                $preferredStyle,
                $targetMarket
            ],
            $template
        );


        $parts = [
            $this->client->textPart($prompt),
            $this->client->imagePart($imagePath),
        ];

        $lastError = null;

        foreach (['gemini-2.5-flash'] as $model) {
            try {
                $text = $this->client->generateText($parts, $model);
                $json = $this->extractJson($text);
                $profile = json_decode($json, true);

                if (!is_array($profile)) {
                    throw new RuntimeException('Gemini devolvio analisis no JSON: ' . $text);
                }

                return $this->normalizeProfile($profile);
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        throw $lastError ?: new RuntimeException('No se pudo analizar la obra con Gemini.');
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

    private function normalizeProfile(array $profile): array
    {
        if (is_array($profile['artwork_analysis'] ?? null)) {
            $analysis = $profile['artwork_analysis'];
            $profile = array_merge($profile, $analysis);
            $profile['style_tags'] = $profile['style_tags'] ?? ($analysis['visual_language'] ?? []);
            $profile['mood_tags'] = $profile['mood_tags'] ?? ($analysis['emotional_energy'] ?? []);
            $profile['palette'] = $profile['palette'] ?? ($analysis['dominant_colors'] ?? []);
            $profile['structure_tags'] = $profile['structure_tags'] ?? array_filter([
                $analysis['composition_type'] ?? '',
                $analysis['rhythm'] ?? '',
                $analysis['surface'] ?? '',
            ]);
            $profile['commercial_fit'] = $profile['commercial_fit'] ?? ($analysis['suggested_audience'] ?? []);
        }

        foreach (['style_tags', 'mood_tags', 'palette', 'palette_family', 'structure_tags', 'commercial_fit', 'seasonality', 'recommended_shot_needs', 'avoid'] as $key) {
            $profile[$key] = isset($profile[$key]) && is_array($profile[$key])
                ? array_values(array_unique(array_map(fn($v) => strtolower(trim((string)$v)), $profile[$key])))
                : [];
        }

        return $profile;
    }

    private function fallbackProfile(array $metadata): array
    {
        $artistProfile = is_array($metadata['artist_profile'] ?? null) ? $metadata['artist_profile'] : [];
        $artistProfilePrompt = trim((string)($metadata['artist_profile_prompt'] ?? ''));
        $notes = strtolower(trim((string)($metadata['artist_notes'] ?? '') . "\n" . $artistProfilePrompt));
        $scaleText = trim((string)($metadata['scale_text'] ?? ''));
        $targetAudience = trim((string)($artistProfile['target_audience'] ?? ''));
        $preferredRegions = trim((string)($artistProfile['preferred_regions'] ?? ''));

        $styleTags = ['abstract', 'contemporary', 'material'];
        if (str_contains($notes, 'arquitect')) {
            $styleTags[] = 'architectural';
            $styleTags[] = 'geometric';
        }
        if (str_contains($notes, 'surreal') || str_contains($notes, 'onir')) {
            $styleTags[] = 'surreal';
        }
        if (str_contains($notes, 'figur')) {
            $styleTags[] = 'figurative';
        }

        return [
            'style_summary' => $artistProfilePrompt !== ''
                ? 'Emergency local analysis: contemporary visual reading based on metadata, artist context, and artwork-led rules.'
                : 'Emergency local analysis: contemporary visual reading based on metadata, artist notes, and artwork-led rules.',
            'style_tags' => array_values(array_unique($styleTags)),
            'mood_tags' => ['contemplative', 'sober', 'quiet intensity'],
            'palette' => ['inferred from root artwork by downstream prompt', 'manual review recommended'],
            'palette_family' => ['balanced', 'material', 'artwork-led'],
            'luminosity' => 'medium',
            'saturation' => 'balanced',
            'detail_density' => 'medium',
            'texture_visibility' => 'high',
            'structure_tags' => ['composition', 'surface', 'gesture', 'materiality'],
            'commercial_fit' => ['collector', 'gallery', 'designer_home', 'premium_context'],
            'seasonality' => ['neutral'],
            'recommended_shot_needs' => ['frontality', 'scale realism', 'material detail', 'sophisticated environment'],
            'avoid' => ['generic decor', 'cheap room', 'kitchen', 'common bedroom', 'reinterpreting the artwork'],
            'one_line_curatorial_read' => 'The work should be described through its visible color, surface, composition, and emotional atmosphere.',
            'style_interpretation' => [
                'dominant_language' => array_values(array_unique($styleTags)),
                'reads_through' => ['color vibration', 'surface', 'trace', 'material presence'],
            ],
            'emotional_palette' => [
                'temperature' => 'balanced',
                'psychological_associations' => ['contemplation', 'desire', 'collector confidence'],
            ],
            'audience_profile' => [
                'primary' => $targetAudience !== '' ? $targetAudience : 'collector or premium interior buyer',
                'secondary' => 'gallery, architect, interior designer or boutique hospitality audience',
            ],
            'region_context' => [
                'target_region' => $preferredRegions !== '' ? $preferredRegions : ($metadata['region'] ?? 'Europe or United States'),
                'strategy' => 'Use sophisticated European or American collector interiors, avoiding generic stock-room language.',
            ],
            'seasonal_strategy' => [
                'primary_season' => 'neutral',
                'reason' => 'Fallback analysis keeps season neutral until manual or AI reading is available.',
            ],
            'dreamlike_presence' => [
                'level' => in_array('surreal', $styleTags, true) ? 'high' : 'medium',
                'reading' => 'Fallback reading: preserve possible poetic or evocative presence without over-interpreting.',
            ],
            'materiality_strategy' => [
                'importance' => 'high',
                'show' => ['texture', 'brushwork', 'palette knife', 'incisions', 'edge', 'canvas tension'],
            ],
            'scale_strategy' => $scaleText,
        ];
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
                'width_cm' => $metadata['width_cm'] ?? null,
                'height_cm' => $metadata['height_cm'] ?? null,
                'depth_cm' => $metadata['depth_cm'] ?? null,
            ],
        ];
    }
}
