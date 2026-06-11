<?php
declare(strict_types=1);

class MockArtworkAnalyzer implements ArtworkAnalyzerInterface
{
    private ContextSelectorInterface $contextSelector;

    public function __construct(?ContextSelectorInterface $contextSelector = null)
    {
        $this->contextSelector = $contextSelector ?: new MockContextSelector();
    }

    public function analyze(string $imagePath, array $metadata = []): array
    {
        if (!is_file($imagePath)) {
            throw new RuntimeException('No se encontro la imagen para analizar.');
        }

        Logger::log('Iniciando analisis MOCK para obra: ' . basename($imagePath), 'mock');
        $t0 = microtime(true);
        $imageMeta = $this->imageMeta($imagePath, $metadata);
        $profile = $this->mockProfile($metadata);
        $profile['_artist_profile'] = is_array($metadata['artist_profile'] ?? null) ? $metadata['artist_profile'] : [];
        $profile['_artist_profile_prompt'] = trim((string)($metadata['artist_profile_prompt'] ?? ''));
        $profile['_artist_profile_updated_at'] = (string)($profile['_artist_profile']['updated_at'] ?? '');
        $contextCount = PromptSettings::mockupContextCount();
        $contexts = $this->contextSelector->select($profile, $imageMeta, $contextCount);
        $elapsed = round(microtime(true) - $t0, 2);
        Logger::log("Analisis MOCK completado exitosamente en {$elapsed}s para: " . basename($imagePath), 'mock');

        return [
            'ok' => true,
            'mode' => 'mock',
            'context_count' => $contextCount,
            'mock_notice' => 'Analisis contextual simulado. No se uso API.',
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

    private function mockProfile(array $metadata): array
    {
        $artistProfile = is_array($metadata['artist_profile'] ?? null) ? $metadata['artist_profile'] : [];
        $artistProfilePrompt = trim((string)($metadata['artist_profile_prompt'] ?? ''));
        $notes = strtolower(trim((string)($metadata['artist_notes'] ?? '') . "\n" . $artistProfilePrompt));
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
        if (str_contains($notes, 'minimal')) {
            $styleTags[] = 'minimal';
        }

        $paletteFamily = ['balanced', 'muted', 'neutral'];
        if (str_contains($notes, 'calid') || str_contains($notes, 'rojo') || str_contains($notes, 'ocre')) {
            $paletteFamily = ['warm', 'earth', 'muted'];
        }
        if (str_contains($notes, 'azul') || str_contains($notes, 'frio')) {
            $paletteFamily[] = 'cool';
            $paletteFamily[] = 'blue';
        }

        $season = 'neutral';
        if (str_contains($notes, 'verano') || str_contains($notes, 'mediterr')) {
            $season = 'summer';
        } elseif (str_contains($notes, 'invierno') || str_contains($notes, 'oscuro')) {
            $season = 'winter';
        }

        $dreamLevel = (in_array('surreal', $styleTags, true) || str_contains($notes, 'sueño') || str_contains($notes, 'onir'))
            ? 'high'
            : 'medium';

        return [
            'style_summary' => $artistProfilePrompt !== ''
                ? 'Perfil simulado: obra contemporanea leida desde estilo, perfil del artista, materialidad, temperatura emocional y potencial comercial.'
                : 'Perfil simulado: obra contemporanea leida desde estilo, materialidad, temperatura emocional y potencial comercial.',
            'style_tags' => array_values(array_unique($styleTags)),
            'mood_tags' => ['calm', 'contemplative', 'premium', 'quiet'],
            'palette' => ['simulated dominant palette', 'artist-provided notes pending'],
            'palette_family' => array_values(array_unique($paletteFamily)),
            'luminosity' => 'medium',
            'saturation' => 'balanced',
            'detail_density' => 'medium',
            'texture_visibility' => 'high',
            'structure_tags' => ['surface', 'gesture', 'composition', 'materiality'],
            'commercial_fit' => ['collector', 'gallery', 'designer_home', 'premium', $season],
            'seasonality' => [$season],
            'recommended_shot_needs' => ['front', 'scale proof', 'material detail', 'collector context'],
            'avoid' => ['generic room', 'cheap decor', 'visual clutter', 'reinterpretation'],
            'one_line_curatorial_read' => 'Lectura mock: la obra debe presentarse como pieza fiel, material y comercialmente deseable sin alterar su identidad visual.',
            'style_interpretation' => [
                'dominant_language' => $styleTags,
                'reads_through' => ['color vibration', 'surface', 'trace', 'material presence'],
            ],
            'emotional_palette' => [
                'temperature' => in_array('warm', $paletteFamily, true) ? 'warm' : (in_array('cool', $paletteFamily, true) ? 'cool' : 'balanced'),
                'psychological_associations' => ['contemplation', 'premium calm', 'collector confidence'],
            ],
            'audience_profile' => [
                'primary' => $targetAudience !== '' ? $targetAudience : 'collector or premium interior buyer',
                'secondary' => 'gallery, designer home or boutique hospitality audience',
            ],
            'region_context' => [
                'target_region' => $preferredRegions !== '' ? $preferredRegions : ($metadata['region'] ?? 'not specified'),
                'strategy' => 'Use local region later to tune architecture, light and seasonal cues.',
            ],
            'seasonal_strategy' => [
                'primary_season' => $season,
                'reason' => 'Mock value inferred from notes only. Future API/manual input should refine this.',
            ],
            'dreamlike_presence' => [
                'level' => $dreamLevel,
                'reading' => 'Prototype reading of oniric or evocative presence.',
            ],
            'materiality_strategy' => [
                'importance' => 'high',
                'show' => ['texture', 'brushwork', 'palette knife', 'incisions', 'edge', 'canvas tension'],
            ],
        ];
    }
}
