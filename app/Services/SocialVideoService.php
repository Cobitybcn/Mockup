<?php
declare(strict_types=1);

class SocialVideoService
{
    public function conceptFromMockupSequence(array $mockups, array $artwork, array $analysis, array $profile, int $toneValue = 50): array
    {
        $mockups = array_values(array_filter($mockups, fn($file) => trim((string)$file) !== ''));
        if (count($mockups) < 2) { throw new InvalidArgumentException('Se necesitan al menos dos mockups para conectar un video.'); }
        $toneValue = max(0, min(100, $toneValue));
        $segments = [];
        foreach (array_values($mockups) as $i => $file) {
            if ($i === 0) { continue; }
            $segments[] = ['segment_number'=>$i,'duration_seconds'=>5,'segment_prompt'=>'Bridge from mockup ' . $i . ' to mockup ' . ($i + 1) . '. Use both supplied mockup anchors as the visual destination and origin. Preserve the artwork exactly, camera logic, light and human identity.', 'final_frame_anchor'=>'Resolve on the composition, exposure and artwork placement of mockup ' . ($i + 1) . '.'];
        }
        $fallback=['video_title'=>(string)($artwork['final_title']??'Mockup sequence'),'mockup_sequence'=>$mockups,'tone_value'=>$toneValue,'style_bible'=>['palette'=>'Derived from the six supplied mockups and the artwork.','lighting_character'=>'Transition only between the supplied lighting states.','lens_camera_character'=>'Vertical editorial camera continuity.','motion_feel'=>'Measured bridges between existing mockups.'],'segments'=>$segments,'audio_mode'=>'ambient room tone','social_video_specs'=>['format'=>'vertical','aspect_ratio'=>'9:16','total_duration_seconds'=>count($segments)*5,'platform_intent'=>['Instagram Reels','TikTok','YouTube Shorts']],'tiktok_metadata'=>['hook'=>'Six views, one artwork.','caption'=>'A continuous passage through the artwork’s existing mockup worlds.','hashtags'=>['#contemporaryart','#artvideo'],'suggested_audio_direction'=>'Subtle ambient room tone.'],'negative_prompt'=>'Never repaint, redesign, crop, mirror, distort, or reinterpret the artwork.'];
        return $this->ask('social_video_director_prompt',$fallback,'six-mockup transition video concept',['mockups'=>$mockups,'artwork'=>$artwork,'analysis'=>$analysis,'artist_profile'=>$profile,'tone_value'=>$toneValue,'structural_requirements'=>'Create one bridge for each consecutive supplied mockup pair. Do not introduce a new narrative structure, new artwork, or new scenes. Preserve exact artwork fidelity, continuity anchors, human identity and audio mode. Tone affects only wording.']);
    }
    public function conceptFromTimeline(array $timeline, array $artwork, array $analysis, array $profile): array
    {
        $milestones = array_values($timeline['milestones'] ?? []);
        if (count($milestones) !== 5) { throw new InvalidArgumentException('La línea de tiempo debe tener exactamente cinco hitos.'); }
        $images = array_filter($milestones, fn($item) => trim((string)($item['image'] ?? '')) !== '');
        $edgeImages = array_filter([$milestones[0]['image'] ?? '', $milestones[4]['image'] ?? '']);
        if (count($images) < 2 || $edgeImages === []) { throw new InvalidArgumentException('Añade al menos dos imágenes ancla, incluyendo Principio o Fin.'); }
        $duration = max(16, min(60, (int)($timeline['duration_seconds'] ?? 25)));
        $tone = max(0, min(100, (int)($timeline['tone_value'] ?? 50)));
        $segments = [];
        for ($i = 0; $i < 4; $i++) {
            $from = (string)($milestones[$i]['narrative_text'] ?? '');
            $to = (string)($milestones[$i + 1]['narrative_text'] ?? '');
            $segments[] = ['segment_number' => $i + 1, 'duration_seconds' => max(4, min(8, (int)round($duration / 4))), 'segment_prompt' => "Bridge milestone " . ($i + 1) . " to " . ($i + 2) . ". Outgoing moment: {$from}. Incoming moment: {$to}. Preserve the root artwork exactly and continue from the prior final frame anchor.", 'final_frame_anchor' => 'End in the visible state, framing and lighting required for milestone ' . ($i + 2) . '.'];
        }
        $fallback = ['video_title' => (string)($artwork['final_title'] ?? 'Timeline Social Video'), 'timeline' => $timeline, 'tone_value' => $tone, 'style_bible' => ['palette'=>'Derived from the root artwork and milestone anchors.','lighting_character'=>'Consistent cinematic continuity.','lens_camera_character'=>'Vertical editorial framing.','motion_feel'=>'Deliberate transitions between authored moments.'], 'segments'=>$segments, 'audio_mode'=>'ambient room tone', 'social_video_specs'=>['format'=>'vertical','aspect_ratio'=>'9:16','total_duration_seconds'=>$duration,'platform_intent'=>['Instagram Reels','TikTok','YouTube Shorts']], 'tiktok_metadata'=>['hook'=>'Five authored moments, one unfolding work.','caption'=>'A timeline built around an original artwork.','hashtags'=>['#contemporaryart','#artvideo'],'suggested_audio_direction'=>'Subtle ambient room tone.'], 'negative_prompt'=>'Never repaint, redesign, crop, mirror, distort, or reinterpret the artwork.'];
        return $this->ask('social_video_director_prompt', $fallback, 'timeline-based final video concept', ['timeline'=>$timeline,'artwork'=>$artwork,'analysis'=>$analysis,'artist_profile'=>$profile,'structural_requirements'=>'Create bridges across the four adjacent milestone gaps. Use each outgoing and incoming narrative text. Keep one style bible, root artwork fidelity, continuity anchors, human identity and audio mode. tone_value controls only wording in segment_prompt, hook and caption.']);
    }
    public function propose(array $artwork, array $analysis, array $mockups, array $profile): array
    {
        $candidates = $this->candidates($mockups, $analysis);
        $selected = $this->compatibleSelection($candidates);
        $archetypes = SocialVideoArchetypes::all();
        $suggestedArchetypes = $this->suggestedArchetypes($archetypes, $analysis);
        $defaultArchetype = $this->defaultArchetype($archetypes, 'contexto_vivido');
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
            'available_archetypes' => $archetypes,
            'suggested_archetypes' => $suggestedArchetypes,
            'narrative_archetype' => (string)$defaultArchetype['id'],
            'suggested_tone_value' => $this->suggestedTone($analysis, $defaultArchetype),
            'tone_value' => $this->suggestedTone($analysis, $defaultArchetype),
            'narrative_thread' => [
                'tone' => (string)($profile['tone_of_voice'] ?? 'Curatorial and cinematic'),
                'selected_title_index' => 0,
                'selected_title' => $titleText,
                'selected_description' => $description,
                'concept' => 'A focused sequence built from the selected contextual reference.',
            ],
            'selection_justification' => $this->selectionReason($selected),
            'new_image_references' => [],
            'new_image_generation_brief' => '',
        ];

        Logger::log(sprintf('Social Video selector candidates: artwork=%s mockups=%d candidates=%d selected=%d', (string)($artwork['id'] ?? ''), count($mockups), count($candidates), count($selected)), 'social_video');

        return $this->ask('social_video_selector_prompt', $fallback, 'setup proposal', [
            'artwork' => $artwork,
            'analysis' => $analysis,
            'artist_profile' => $profile,
            'candidates' => $candidates,
            'archetypes' => $archetypes,
            'selection_rules' => 'First suggest 2-3 archetypes from the supplied list with a short reason anchored in visible artwork qualities; do not invent IDs. Default narrative_archetype to contexto_vivido unless the evidence strongly favors another. Pass narrative_archetype through and return suggested_tone_value from 0 to 100. Select only 1-3 mockups for Contexto Vivido. For an archetype requiring new image generation, do not force room mockups: return a concrete new_image_generation_brief. If the archetype needs a second artwork, identify that requirement. Return a visible justification and select one title/description.',
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
            'narrative_archetype' => $setup['narrative_archetype'] ?? 'contexto_vivido',
            'tone_value' => (int)($setup['tone_value'] ?? $setup['suggested_tone_value'] ?? 50),
            'archetype' => SocialVideoArchetypes::find((string)($setup['narrative_archetype'] ?? '')),
            'new_image_generation_brief' => $setup['new_image_generation_brief'] ?? '',
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
            'metadata' => ['workflow' => 'Social Video (beta)', 'generated_from' => 'The Artwork Curator', 'narrative_archetype' => $setup['narrative_archetype'] ?? 'contexto_vivido', 'tone_value' => (int)($setup['tone_value'] ?? $setup['suggested_tone_value'] ?? 50)],
        ];

        return $this->ask('social_video_director_prompt', $fallback, 'final multi-segment video concept', [
            'edited_setup' => $setup,
            'artwork' => $artwork,
            'analysis' => $analysis,
            'artist_profile' => $profile,
            'structural_requirements' => 'Read narrative_archetype and tone_value (0 documentary, 100 artistic). The archetype changes the emotional arc and segment subject: Contexto Vivido retains the validated arrival/contemplation/departure/light vocabulary; Génesis moves raw/unformed toward resolved form; Dimensión Metafísica lets a visible artwork element become filmable at a new scale; Diálogo Simbólico stages a concrete relation between both artworks. Tone changes only the writing register of segment_prompt, hook and caption, never continuity, style bible, artwork fidelity, segment count or duration. At low values use concrete observational language; at high values use relational, poetic but still filmable language. Choose one emotional arc. Define one style bible and repeat it verbatim in every segment. Use 1-5 segments of 4-8 seconds. Every later segment must restate the prior final frame anchor, preserve artwork exactly, and preserve human figure identity when present. Set audio once. Include TikTok metadata.',
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

    private function defaultArchetype(array $archetypes, string $id): array
    {
        foreach ($archetypes as $archetype) {
            if (($archetype['id'] ?? '') === $id) { return $archetype; }
        }
        return $archetypes[0] ?? ['id' => 'contexto_vivido'];
    }

    private function suggestedArchetypes(array $archetypes, array $analysis): array
    {
        $visible = $this->visibleAnchor($analysis);
        $ids = ['contexto_vivido', 'genesis', 'dimension_metafisica'];
        $suggestions = [];
        foreach ($ids as $id) {
            foreach ($archetypes as $archetype) {
                if (($archetype['id'] ?? '') !== $id) { continue; }
                $suggestions[] = ['id' => $id, 'name' => $archetype['name'], 'reason' => $visible . ' makes ' . $archetype['name'] . ' a plausible direction.'];
            }
        }
        return $suggestions;
    }

    private function suggestedTone(array $analysis, array $archetype): int
    {
        $id = (string)($archetype['id'] ?? 'contexto_vivido');
        $base = in_array($id, ['dimension_metafisica', 'dialogo_simbolico'], true) ? 68 : ($id === 'genesis' ? 58 : 38);
        $text = strtolower(json_encode($analysis, JSON_UNESCAPED_UNICODE) ?: '');
        if (preg_match('/texture|textura|gestural|gestual|intens|vivid|ambigu|ambig/', $text)) { $base += 8; }
        return max(0, min(100, $base));
    }

    private function visibleAnchor(array $analysis): string
    {
        foreach (['visual_language', 'surface', 'dominant_colors', 'visible_elements', 'one_line_curatorial_read'] as $key) {
            $value = $analysis[$key] ?? null;
            if (is_array($value) && $value !== []) { return 'The visible ' . implode(', ', array_slice(array_map('strval', $value), 0, 2)); }
            if (is_string($value) && trim($value) !== '') { return 'The visible ' . trim($value); }
        }
        return 'The artwork’s visible color, surface, and composition';
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

        $knownIds = array_column($fallback['available_archetypes'] ?? [], 'id');
        if (!in_array((string)($merged['narrative_archetype'] ?? ''), $knownIds, true)) {
            $merged['narrative_archetype'] = $fallback['narrative_archetype'];
        }
        $merged['suggested_tone_value'] = max(0, min(100, (int)($merged['suggested_tone_value'] ?? $fallback['suggested_tone_value'])));
        $merged['tone_value'] = $merged['suggested_tone_value'];

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
