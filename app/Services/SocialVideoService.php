<?php
declare(strict_types=1);

class SocialVideoService
{
    public function propose(array $artwork, array $analysis, array $mockups, array $profile): array
    {
        $candidates = $this->candidates($mockups, $analysis);
        $selected = $this->compatibleSelection($candidates);
        $titles = is_array($analysis['suggested_titles'] ?? null) ? $analysis['suggested_titles'] : [];
        $title = $titles[0] ?? [];
        $titleText = is_array($title) ? (string)($title['title'] ?? $title['name'] ?? '') : (string)$title;
        $description = is_array($title) ? (string)($title['description'] ?? $title['curatorial_reason'] ?? '') : '';

        $fallback = [
            'primary_artwork' => [
                'id' => (string)($artwork['id'] ?? ''),
                'file' => basename((string)($artwork['root_file'] ?? '')),
                'role' => 'Primary visual source',
                'usage_notes' => 'Keep the artwork fully visible, faithful, and correctly proportioned in every scene.',
            ],
            'scene_references' => $selected,
            'narrative_thread' => [
                'tone' => (string)($profile['tone_of_voice'] ?? 'Curatorial and cinematic'),
                'selected_title_index' => 0,
                'selected_title' => $titleText,
                'selected_description' => $description,
                'concept' => 'A focused sequence built from the selected contextual reference.',
            ],
            'selection_justification' => $this->selectionReason($selected),
            'new_image_references' => [],
        ];

        Logger::log(sprintf('Social Video selector candidates: artwork=%s mockups=%d candidates=%d selected=%d', (string)($artwork['id'] ?? ''), count($mockups), count($candidates), count($selected)), 'social_video');

        return $this->ask('social_video_selector_prompt', $fallback, 'setup proposal', [
            'artwork' => $artwork,
            'analysis' => $analysis,
            'artist_profile' => $profile,
            'candidates' => $candidates,
            'selection_rules' => 'Select only 1-3 mockups. Prefer 1-2. Sequence only compatible camera view, human figure, and lighting states. If none are compatible, choose one strong reference. Return a visible justification and select one title/description.',
        ]);
    }

    public function concept(array $setup, array $artwork, array $analysis, array $profile): array
    {
        $duration = 12;
        $fallback = [
            'video_title' => trim((string)($setup['narrative_thread']['selected_title'] ?? $artwork['final_title'] ?? 'Artwork Social Video')),
            'primary_artwork' => $setup['primary_artwork'] ?? [],
            'scene_references' => $setup['scene_references'] ?? [],
            'narrative_thread' => $setup['narrative_thread'] ?? [],
            'new_image_references' => $setup['new_image_references'] ?? [],
            'emotional_arc' => 'contemplation',
            'style_bible' => [
                'palette' => 'Derived from the artwork and selected room materials.',
                'lighting_character' => 'Consistent soft editorial lighting.',
                'lens_camera_character' => 'Vertical editorial framing, restrained lens movement.',
                'motion_feel' => 'Slow and deliberate.',
            ],
            'segments' => [
                ['segment_number' => 1, 'duration_seconds' => 6, 'segment_prompt' => 'Open on the selected scene reference. Preserve the artwork exactly, fully visible and correctly proportioned. Establish the fixed style bible.', 'final_frame_anchor' => 'Hold on the artwork centered in the established framing and exposure.'],
                ['segment_number' => 2, 'duration_seconds' => 6, 'segment_prompt' => 'Continue from the exact previous final frame anchor. Keep identical style bible, framing, exposure, and any human figure continuity. Preserve the artwork exactly.', 'final_frame_anchor' => 'End on a calm, stable view of the artwork with the same lighting and camera character.'],
            ],
            'audio_mode' => 'ambient room tone',
            'social_video_specs' => ['format' => 'vertical', 'aspect_ratio' => '9:16', 'total_duration_seconds' => $duration, 'platform_intent' => ['Instagram Reels', 'TikTok', 'YouTube Shorts']],
            'tiktok_metadata' => ['hook' => 'Enter the world around this artwork.', 'caption' => 'A curated encounter with an original artwork.', 'hashtags' => ['#contemporaryart', '#artcollector', '#interiordesign'], 'suggested_audio_direction' => 'Subtle ambient room tone.'],
            'negative_prompt' => 'Never repaint, redesign, crop, mirror, distort, or reinterpret the artwork.',
            'metadata' => ['workflow' => 'Social Video (beta)', 'generated_from' => 'The Artwork Curator'],
        ];

        return $this->ask('social_video_director_prompt', $fallback, 'final multi-segment video concept', [
            'edited_setup' => $setup,
            'artwork' => $artwork,
            'analysis' => $analysis,
            'artist_profile' => $profile,
            'structural_requirements' => 'Choose one emotional arc. Define one style bible and repeat it verbatim in every segment. Use 1-5 segments of 4-8 seconds. Every later segment must restate the prior final frame anchor, preserve artwork exactly, and preserve human figure identity when present. Set audio once. Include TikTok metadata.',
        ]);
    }

    private function candidates(array $mockups, array $analysis): array
    {
        $contexts = is_array($analysis['contextual_proposals'] ?? null) ? $analysis['contextual_proposals'] : [];
        $candidates = [];
        foreach ($mockups as $mockup) {
            $state = json_decode((string)($mockup['selector_state_json'] ?? ''), true);
            $state = is_array($state) ? $state : [];
            $contextId = (string)($mockup['context_id'] ?? '');
            $context = is_array($contexts[$contextId] ?? null) ? $contexts[$contextId] : [];
            if ($context === []) {
                foreach ($contexts as $key => $proposal) {
                    if (!is_array($proposal)) { continue; }
                    $proposalId = (string)($proposal['id'] ?? $proposal['context_id'] ?? $proposal['slug'] ?? $key);
                    if ($proposalId === $contextId) { $context = $proposal; break; }
                }
            }
            $file = basename((string)($mockup['mockup_file'] ?? $mockup['file'] ?? $mockup['filename'] ?? ''));
            $candidates[] = [
                'mockup_id' => (string)($mockup['id'] ?? ''),
                'file' => $file,
                'camera_view' => (string)($state['camera_override'] ?? $context['camera_view'] ?? ''),
                'human_presence' => (string)($state['human_override'] ?? $context['human_presence'] ?? 'none'),
                'lighting' => (string)($state['time_override'] ?? $context['lighting'] ?? $context['atmosphere'] ?? ''),
                'curatorial_reason' => (string)($context['curatorial_reason'] ?? $context['justification'] ?? ''),
            ];
        }
        return array_values(array_filter($candidates, fn(array $item): bool => $item['file'] !== ''));
    }

    private function compatibleSelection(array $candidates): array
    {
        if ($candidates === []) { return []; }
        $selected = [$this->reference($candidates[0])];
        foreach (array_slice($candidates, 1) as $candidate) {
            if (count($selected) >= 3) { break; }
            $previous = $selected[count($selected) - 1];
            if ($this->compatible($previous, $candidate)) {
                $selected[] = $this->reference($candidate);
            }
        }
        return $selected;
    }

    private function reference(array $candidate): array
    {
        return ['mockup_id' => $candidate['mockup_id'], 'file' => $candidate['file'], 'role' => 'Sequenced scene reference', 'visual_notes' => trim($candidate['camera_view'] . '; ' . $candidate['lighting']), 'curatorial_reason' => $candidate['curatorial_reason'], 'camera_view' => $candidate['camera_view'], 'human_presence' => $candidate['human_presence'], 'lighting' => $candidate['lighting']];
    }

    private function compatible(array $previous, array $candidate): bool
    {
        $cameraMatch = $previous['camera_view'] === '' || $candidate['camera_view'] === '' || $previous['camera_view'] === $candidate['camera_view'];
        $humanMatch = $previous['human_presence'] === '' || $candidate['human_presence'] === '' || $previous['human_presence'] === $candidate['human_presence'] || $previous['human_presence'] === 'none' || $candidate['human_presence'] === 'none';
        return $cameraMatch && $humanMatch;
    }

    private function selectionReason(array $selected): string
    {
        if (count($selected) <= 1) { return 'One strong scene reference was selected to protect visual continuity rather than force an incoherent sequence.'; }
        return 'These scene references share compatible framing and figure conditions, allowing a coherent visual progression.';
    }

    private function ask(string $promptKey, array $fallback, string $purpose, array $payload): array
    {
        $prompt = trim((string)(PromptSettings::all()[$promptKey] ?? ''));
        if (!ProviderSettings::isRealMode() || !ProviderSettings::allowRealApi() || ProviderSettings::imageProvider() !== 'gemini' || $prompt === '') { return $fallback; }
        try {
            $client = new GeminiImageClient();
            $response = trim($client->generateText([$client->textPart($prompt . "\n\nCreate a " . $purpose . ". Return only valid JSON.\nContext:\n" . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]));
            $start = strpos($response, '{'); $end = strrpos($response, '}');
            if ($start === false || $end === false || $end < $start) { return $fallback; }
            $generated = json_decode(substr($response, $start, $end - $start + 1), true);
            if (!is_array($generated)) { return $fallback; }
            if ($promptKey === 'social_video_selector_prompt') {
                return $this->mergeSelectorProposal($fallback, $generated);
            }
            return array_replace_recursive($fallback, $generated);
        } catch (Throwable $e) { Logger::log('Social Video fallback: ' . $e->getMessage(), 'warning'); return $fallback; }
    }

    private function mergeSelectorProposal(array $fallback, array $generated): array
    {
        $merged = array_replace_recursive($fallback, $generated);

        if (empty($generated['scene_references']) || !is_array($generated['scene_references'])) {
            $merged['scene_references'] = $fallback['scene_references'];
        }

        foreach (['tone', 'selected_title', 'selected_description', 'concept'] as $key) {
            if (trim((string)($generated['narrative_thread'][$key] ?? '')) === '') {
                $merged['narrative_thread'][$key] = $fallback['narrative_thread'][$key] ?? '';
            }
        }

        if (trim((string)($generated['selection_justification'] ?? '')) === '') {
            $merged['selection_justification'] = $fallback['selection_justification'];
        }

        return $merged;
    }
}
