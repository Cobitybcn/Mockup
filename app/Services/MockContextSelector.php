<?php
declare(strict_types=1);

class MockContextSelector implements ContextSelectorInterface
{
    private MockPromptBuilder $promptBuilder;

    public function __construct(?MockPromptBuilder $promptBuilder = null)
    {
        $this->promptBuilder = $promptBuilder ?: new MockPromptBuilder();
    }

    public function select(array $profile, array $imageMeta, int $limit = 10): array
    {
        $library = require __DIR__ . '/../Data/context_library.php';

        foreach ($library as &$context) {
            $context['score'] = $this->score($context, $profile);
            $context['why'] = $this->reason($context, $profile);
        }

        unset($context);

        usort($library, fn($a, $b) => $b['score'] <=> $a['score']);

        $selected = $this->balancedSelection($library, $limit);

        foreach ($selected as &$context) {
            $context['prompt'] = $this->promptBuilder->build($context, $profile, $imageMeta);
        }

        unset($context);

        return array_slice(array_map(function (array $context): array {
            return [
                'id' => $context['id'],
                'name' => $context['name'],
                'purpose' => $context['purpose'],
                'score' => $context['score'],
                'camera' => $context['camera'],
                'camera_group' => $context['camera_group'] ?? null,
                'time_of_day' => $context['time_of_day'] ?? 'day',
                'placement' => $context['placement'],
                'with_human' => $context['with_human'],
                'human_profile' => $context['human_profile'] ?? null,
                'scene' => $context['scene'],
                'lighting' => $context['lighting'],
                'why' => $context['why'],
                'prompt' => $context['prompt'],
            ];
        }, $selected), 0, $limit);
    }

    private function score(array $context, array $profile): int
    {
        $score = 10;
        $score += count(array_intersect($context['styles'], $profile['style_tags'] ?? [])) * 5;
        $score += count(array_intersect($context['moods'], $profile['mood_tags'] ?? [])) * 4;
        $score += count(array_intersect($context['palette_family'], $profile['palette_family'] ?? [])) * 3;
        $score += count(array_intersect($context['commercial_fit'], $profile['commercial_fit'] ?? [])) * 4;

        $season = $profile['seasonal_strategy']['primary_season'] ?? '';
        if ($season && in_array($season, $context['commercial_fit'], true)) {
            $score += 6;
        }

        if (($profile['dreamlike_presence']['level'] ?? '') === 'high' && in_array('surreal', $context['styles'], true)) {
            $score += 5;
        }

        if (($profile['materiality_strategy']['importance'] ?? '') === 'high' && $context['purpose'] === 'materiality') {
            $score += 8;
        }

        $styleTags = $profile['style_tags'] ?? [];

        if (str_contains((string)$context['id'], 'brutalist') && !array_intersect(['architectural', 'geometric', 'structural'], $styleTags)) {
            $score -= 16;
        }

        if (in_array('minimal', $context['styles'] ?? [], true) && !in_array('minimal', $styleTags, true)) {
            $score -= 8;
        }

        if (in_array('gallery', $context['commercial_fit'] ?? [], true) || in_array('institutional', $context['commercial_fit'] ?? [], true)) {
            $score += 6;
        }

        $score += $this->artistProfileScore($context, $profile);

        return $score;
    }

    private function artistProfileScore(array $context, array $profile): int
    {
        $artistProfile = is_array($profile['_artist_profile'] ?? null) ? $profile['_artist_profile'] : [];

        if (!$artistProfile) {
            return 0;
        }

        $score = 0;
        $contextText = $this->contextText($context);
        $preferred = strtolower(trim(implode("\n", [
            (string)($artistProfile['preferred_contexts'] ?? ''),
            (string)($artistProfile['preferred_regions'] ?? ''),
            (string)($artistProfile['target_audience'] ?? ''),
            (string)($artistProfile['commercial_positioning'] ?? ''),
        ])));
        $forbidden = strtolower(trim((string)($artistProfile['forbidden_contexts'] ?? '')));

        foreach ($this->profileKeywords($preferred) as $keyword) {
            if (str_contains($contextText, $keyword)) {
                $score += 12;
            }
        }

        foreach ($this->profileKeywords($forbidden) as $keyword) {
            if (str_contains($contextText, $keyword)) {
                $score -= 30;
            }
        }

        return $score;
    }

    private function contextText(array $context): string
    {
        return strtolower(implode(' ', array_filter([
            $context['id'] ?? '',
            $context['name'] ?? '',
            $context['purpose'] ?? '',
            $context['scene'] ?? '',
            $context['lighting'] ?? '',
            implode(' ', $context['styles'] ?? []),
            implode(' ', $context['moods'] ?? []),
            implode(' ', $context['commercial_fit'] ?? []),
        ])));
    }

    private function profileKeywords(string $text): array
    {
        $keywords = [
            'gallery', 'galeria', 'private', 'privado', 'collector', 'coleccionista',
            'museum', 'museo', 'institutional', 'institucional', 'art fair', 'feria',
            'paris', 'london', 'londres', 'new york', 'milan', 'milan', 'madrid',
            'europe', 'europa', 'american', 'americano', 'loft', 'brutalist',
            'minimal', 'hotel', 'boutique', 'townhouse', 'apartment', 'apartamento',
            'studio', 'atelier', 'residence', 'residencia',
        ];

        return array_values(array_unique(array_filter($keywords, fn(string $keyword): bool => str_contains($text, $keyword))));
    }

    private function balancedSelection(array $library, int $limit): array
    {
        $slots = [
            ['camera' => 'front, eye-level, 50mm lens', 'camera_group' => 'front_wide', 'time' => 'day', 'human' => 'male_180', 'prefer' => ['gallery']],
            ['camera' => '3/4 left', 'camera_group' => 'three_quarter_left', 'time' => 'afternoon', 'human' => null, 'prefer' => ['collector']],
            ['camera' => '3/4 left evening', 'camera_group' => 'three_quarter_left', 'time' => 'night', 'human' => null, 'prefer' => ['collector', 'editorial']],
            ['camera' => 'front, eye-level, 50mm lens', 'camera_group' => 'front_wide', 'time' => 'day', 'human' => 'female_155', 'prefer' => ['gallery']],
            ['camera' => '3/4 right', 'camera_group' => 'three_quarter_right', 'time' => 'afternoon', 'human' => null, 'prefer' => ['designer_home', 'collector']],
            ['camera' => '3/4 right evening', 'camera_group' => 'three_quarter_right', 'time' => 'night', 'human' => 'male_180', 'prefer' => ['collector']],
            ['camera' => 'front close-up', 'camera_group' => 'front_close', 'time' => 'day', 'human' => null, 'prefer' => ['materiality', 'institutional']],
            ['camera' => 'front close-up', 'camera_group' => 'front_close', 'time' => 'afternoon', 'human' => 'female_155', 'prefer' => ['gallery', 'scale_proof']],
            ['camera' => 'front-wide', 'camera_group' => 'front_wide', 'time' => 'day', 'human' => null, 'prefer' => ['institutional', 'gallery'], 'must_include' => 'museum'],
            ['camera' => 'front-wide night', 'camera_group' => 'front_wide', 'time' => 'night', 'human' => null, 'prefer' => ['collector', 'editorial']],
        ];

        $selected = [];
        $used = [];

        foreach (array_slice($slots, 0, $limit) as $slot) {
            $context = $this->bestContextForSlot($library, $used, $slot);

            if (!$context) {
                continue;
            }

            $context['camera'] = $slot['camera'];
            $context['camera_group'] = $slot['camera_group'];
            $context['time_of_day'] = $slot['time'];
            $context['with_human'] = $slot['human'] !== null;
            $context['human_profile'] = $slot['human'];
            $context['lighting'] = $this->lightingForTime($context['lighting'], $slot['time']);
            $context['why'] .= ' Esta propuesta cubre la cuota visual: ' . $this->slotLabel($slot) . '.';

            $selected[] = $context;
            $used[$context['id']] = true;
        }

        foreach ($library as $context) {
            if (count($selected) >= $limit) {
                break;
            }

            if (!isset($used[$context['id']])) {
                $context['time_of_day'] = $context['time_of_day'] ?? 'day';
                $context['human_profile'] = null;
                $selected[] = $context;
                $used[$context['id']] = true;
            }
        }

        return $selected;
    }

    private function bestContextForSlot(array $library, array $used, array $slot): ?array
    {
        $best = null;
        $bestScore = PHP_INT_MIN;

        foreach ($library as $context) {
            if (isset($used[$context['id']])) {
                continue;
            }

            $score = (int)$context['score'];
            $fit = $context['commercial_fit'] ?? [];
            $id = (string)$context['id'];

            foreach ($slot['prefer'] ?? [] as $preferred) {
                if (in_array($preferred, $fit, true) || str_contains($id, (string)$preferred)) {
                    $score += 18;
                }
            }

            if (($slot['must_include'] ?? '') === 'museum') {
                $score += str_contains($id, 'museum') || in_array('institutional', $fit, true) ? 100 : -40;
            }

            if ($score > $bestScore) {
                $best = $context;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function lightingForTime(string $baseLighting, string $time): string
    {
        return match ($time) {
            'day' => $baseLighting . ' Time of day: clear daytime, natural visibility, no nocturnal mood.',
            'afternoon' => $baseLighting . ' Time of day: late afternoon, warmer angle of light, emotional but still readable.',
            'night' => $baseLighting . ' Time of day: night or evening, controlled artificial art lighting, rich atmosphere, no underexposed artwork.',
            default => $baseLighting,
        };
    }

    private function slotLabel(array $slot): string
    {
        $human = match ($slot['human']) {
            'male_180' => 'hombre discreto de 1,80 m',
            'female_155' => 'mujer discreta de 1,55 m',
            default => 'sin figura humana',
        };

        return "{$slot['camera_group']}, {$slot['time']}, {$human}";
    }

    private function reason(array $context, array $profile): string
    {
        $parts = [];
        $styles = array_slice(array_values(array_intersect($context['styles'], $profile['style_tags'] ?? [])), 0, 2);
        $moods = array_slice(array_values(array_intersect($context['moods'], $profile['mood_tags'] ?? [])), 0, 2);

        if ($styles) {
            $parts[] = 'encaja con su lenguaje ' . implode(', ', $styles);
        }

        if ($moods) {
            $parts[] = 'refuerza una lectura ' . implode(', ', $moods);
        }

        $season = $profile['seasonal_strategy']['primary_season'] ?? '';
        if ($season) {
            $parts[] = 'puede orientarse a temporada ' . $season;
        }

        return $parts ? ucfirst(implode('; ', $parts)) . '.' : 'Contexto simulado elegido para probar el flujo local.';
    }
}
