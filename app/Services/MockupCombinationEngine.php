<?php
declare(strict_types=1);

final class MockupCombinationEngine
{
    private PDO $pdo;
    private WorldMotherLibrary $worldMothers;
    private WorldMotherGenerator $worldMotherGenerator;

    public function __construct(?PDO $pdo = null, ?WorldMotherLibrary $worldMothers = null, ?WorldMotherGenerator $worldMotherGenerator = null)
    {
        $this->pdo = $pdo ?: Database::connection();
        $this->worldMothers = $worldMothers ?: new WorldMotherLibrary();
        $this->worldMotherGenerator = $worldMotherGenerator ?: new WorldMotherGenerator($this->worldMothers);
    }

    /**
     * @param array<int,string> $selectedCameraSlotsByIndex 1-based combination index => camera_slot_id
     * @return array<string,mixed>
     */
    public function buildForArtwork(int $artworkId, array $selectedCameraSlotsByIndex = [], array $options = []): array
    {
        if ($artworkId <= 0) {
            throw new InvalidArgumentException('artwork_id is required.');
        }

        $artwork = $this->loadArtwork($artworkId);
        if (!$artwork) {
            throw new RuntimeException('Artwork not found.');
        }

        $cameraSlots = $this->activeCameraSlots();
        $contexts = $this->loadContextRows($artworkId);
        $artworkAnalysis = $this->loadArtworkAnalysisProfile($artworkId);
        $worldImages = $this->worldMotherImagesByCategory();
        $flatWorldImages = [];
        foreach ($worldImages as $images) {
            foreach ($images as $image) {
                $flatWorldImages[] = $image;
            }
        }

        $notes = [];
        if (!$contexts) {
            $notes[] = 'No mockup_contexts rows found; using safe fallback context shells from available world mother categories.';
            $contexts = $this->fallbackContextRowsFromWorldMothers($flatWorldImages);
        }
        if (!$flatWorldImages) {
            $notes[] = 'No selected world mother images found in storage/world_mothers; add curated reference images to that folder before generating combinations.';
        }
        if (!$cameraSlots) {
            $notes[] = 'No enabled camera slots found in app/Config/mockup_camera_slots.php.';
        }

        $rankedWorldMotherCategories = $this->rankWorldMotherCategories($contexts, $artworkAnalysis, $worldImages);
        $selectedWorldMotherCategory = WorldMotherGenerator::safeSlug((string)($options['selected_world_mother_category'] ?? ''));
        if ($selectedWorldMotherCategory === '' && $rankedWorldMotherCategories) {
            $selectedWorldMotherCategory = (string)($rankedWorldMotherCategories[0]['category_slug'] ?? '');
        }
        $rootPath = $this->resolveRootArtworkPath($artwork);
        $usedSlots = [];
        $combinations = [];
        $slotIds = array_keys($cameraSlots);

        $targetCount = max(1, count($slotIds));
        for ($i = 0; $i < $targetCount; $i++) {
            $context = $contexts[$i % max(1, count($contexts))] ?? [];
            $contextJson = $this->decodeJson((string)($context['context_json'] ?? ''));
            $categoryDecision = $selectedWorldMotherCategory !== ''
                ? $this->categoryDecisionForSelectedWorldMother($selectedWorldMotherCategory, $rankedWorldMotherCategories, $artworkAnalysis)
                : $this->selectWorldMotherCategory($context, $contextJson, $artworkAnalysis, $worldImages, []);
            $category = (string)($categoryDecision['category_slug'] ?? '');

            $suggestedSlotId = (string)($slotIds[$i % max(1, count($slotIds))] ?? '');
            $selectedSlotId = trim((string)($selectedCameraSlotsByIndex[$i + 1] ?? $suggestedSlotId));
            if (!isset($cameraSlots[$selectedSlotId])) {
                $selectedSlotId = $suggestedSlotId;
            }
            if ($selectedSlotId !== '') {
                $usedSlots[$selectedSlotId] = true;
            }

            $cameraSlot = $cameraSlots[$selectedSlotId] ?? [];
            $worldMother = $this->selectWorldMotherImageFromCategory($category, $worldImages, $selectedSlotId, $i + 1, $artworkId);
            if ($category !== '' && empty($worldMother)) {
                $worldMother = [
                    'category_slug' => $category,
                    'category_name' => ucwords(str_replace('_', ' ', $category)),
                    'relative_path' => '',
                    'absolute_path' => '',
                ];
                $categoryDecision['missing_world_mother'] = [
                    'reason' => 'Selected scene mother category has no image yet. Add one image manually to this folder, then refresh.',
                    'category_slug' => $category,
                    'folder' => 'storage/world_mothers/' . $category,
                ];
            }
            $validationNotes = [];
            if ($rootPath === '' || !is_file($rootPath)) {
                $validationNotes[] = 'Root artwork image not found on disk.';
            }
            if (empty($worldMother['absolute_path']) || !is_file((string)$worldMother['absolute_path'])) {
                $validationNotes[] = 'Selected scene mother has no image yet. Add one image manually to storage/world_mothers/' . $category . ' and refresh.';
            }
            if ($selectedSlotId === '') {
                $validationNotes[] = 'Selected camera slot missing.';
            }
            if (($i >= count($contexts)) && count($contexts) < $targetCount) {
                $validationNotes[] = 'Context row reused because fewer context rows than camera slots are available.';
            }

            $combination = $this->buildCombination(
                $i + 1,
                $artwork,
                $rootPath,
                $context,
                $contextJson,
                $worldMother,
                $suggestedSlotId,
                $selectedSlotId,
                $cameraSlot,
                $validationNotes,
                $categoryDecision
            );
            $combinations[] = $combination;
        }

        return [
            'schema' => 'mockup_combinations_review.v1',
            'artwork_id' => $artworkId,
            'generated_at' => date(DATE_ATOM),
            'root_artwork_path' => $rootPath,
            'artwork_analysis_profile' => $artworkAnalysis,
            'available_camera_slots' => array_values($cameraSlots),
            'suggested_world_mother_categories' => $rankedWorldMotherCategories,
            'selected_world_mother_category' => $selectedWorldMotherCategory,
            'world_mother_base_path' => $this->worldMothers->basePath(),
            'world_mother_categories_available' => count($worldImages),
            'combination_count' => count($combinations),
            'generation_mode' => 'review_only_no_image_generation',
            'validation_notes' => $notes,
            'combinations' => $combinations,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function activeCameraSlots(): array
    {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'mockup_camera_slots.php';
        $config = is_file($path) ? require $path : [];
        $slots = is_array($config['slots'] ?? null) ? $config['slots'] : [];
        $slotOrder = array_keys($slots);
        $active = [];
        foreach ($slotOrder as $slotId) {
            $slot = $slots[$slotId] ?? null;
            if (!is_array($slot) || empty($slot['enabled'])) {
                continue;
            }
            $slot['slot_id'] = (string)($slot['slot_id'] ?? $slotId);
            $slot['slot_name'] = (string)($slot['slot_name'] ?? $slot['slot_id']);
            $slot['camera_slot_geometry'] = $this->cameraGeometry($slot);
            $active[$slot['slot_id']] = $slot;
        }

        return $active;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadArtwork(int $artworkId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artworks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $artworkId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadContextRows(int $artworkId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mockup_contexts WHERE artwork_id = :artwork_id ORDER BY id ASC LIMIT 24');
        $stmt->execute(['artwork_id' => $artworkId]);
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadArtworkAnalysisProfile(int $artworkId): array
    {
        $profile = [
            'source' => 'none',
            'text' => '',
            'keywords' => [],
            'orientation' => '',
            'size_class' => '',
        ];

        $corePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . $artworkId . '.core.json';
        if (is_file($corePath)) {
            $core = $this->decodeJson((string)file_get_contents($corePath));
            $profile['source'] = 'analysis/core/' . $artworkId . '.core.json';
            $profile['text'] = $this->analysisTextFromCore($core);
            $profile['keywords'] = $this->extractProfileKeywords($profile['text']);
            $profile['orientation'] = (string)($core['artwork']['dimensions']['orientation'] ?? '');
            $longest = max(
                (float)($core['artwork']['dimensions']['width_cm'] ?? 0),
                (float)($core['artwork']['dimensions']['height_cm'] ?? 0)
            );
            $profile['size_class'] = $longest >= 100 ? 'xl' : ($longest > 0 ? 'standard' : '');
            return $profile;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT analysis_json FROM artwork_analysis WHERE artwork_id = :artwork_id ORDER BY id DESC LIMIT 1');
            $stmt->execute(['artwork_id' => $artworkId]);
            $analysis = $stmt->fetchColumn();
            if (is_string($analysis) && trim($analysis) !== '') {
                $json = $this->decodeJson($analysis);
                $profile['source'] = 'artwork_analysis.analysis_json';
                $profile['text'] = $this->flattenText($json);
                $profile['keywords'] = $this->extractProfileKeywords($profile['text']);
            }
        } catch (Throwable $e) {
            $profile['source'] = 'unavailable';
        }

        return $profile;
    }

    /**
     * @param array<string,mixed> $core
     */
    private function analysisTextFromCore(array $core): string
    {
        $parts = [];
        foreach ([
            $core['artwork_identity']['short_identity'] ?? '',
            $core['artwork_identity']['expanded_identity'] ?? '',
            $core['visual_analysis']['visual_language'] ?? '',
            $core['visual_analysis']['composition'] ?? '',
            $core['visual_analysis']['materials_or_surface'] ?? '',
            $core['visual_analysis']['texture'] ?? '',
            $core['visual_analysis']['spatial_depth'] ?? '',
            $core['visual_analysis']['light_behavior'] ?? '',
            $core['visual_analysis']['gesture_or_mark_making'] ?? '',
            $core['visual_analysis']['emotional_energy'] ?? '',
            $core['visual_analysis']['style_family'] ?? '',
        ] as $value) {
            if (trim((string)$value) !== '') {
                $parts[] = (string)$value;
            }
        }

        foreach ([
            $core['visual_analysis']['dominant_colors'] ?? [],
            $core['visual_analysis']['secondary_colors'] ?? [],
            $core['visual_analysis']['symbolic_elements'] ?? [],
            $core['artwork_identity']['keywords'] ?? [],
        ] as $values) {
            if (is_array($values)) {
                $parts[] = implode(' ', array_map('strval', $values));
            }
        }

        foreach ((array)($core['publishing_texts']['suggested_titles'] ?? []) as $suggestion) {
            if (is_array($suggestion)) {
                $parts[] = implode(' ', array_map('strval', [
                    $suggestion['title'] ?? '',
                    $suggestion['subtitle'] ?? '',
                    $suggestion['description'] ?? '',
                ]));
            }
        }

        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * @param mixed $value
     */
    private function flattenText($value): string
    {
        if (is_scalar($value) || $value === null) {
            return trim((string)$value);
        }
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach ($value as $child) {
            $text = $this->flattenText($child);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<int,string>
     */
    private function extractProfileKeywords(string $text): array
    {
        $tokens = preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: [];
        $stop = array_flip([
            'the', 'and', 'with', 'from', 'that', 'this', 'under', 'beneath', 'through', 'into', 'all', 'una', 'las', 'los', 'con', 'para', 'por', 'del', 'que',
            'artwork', 'composition', 'featuring', 'prominent', 'suggested', 'description', 'subtitle',
        ]);
        $keywords = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if (strlen($token) < 4 || isset($stop[$token])) {
                continue;
            }
            $keywords[$token] = true;
        }

        return array_slice(array_keys($keywords), 0, 80);
    }

    /**
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function worldMotherImagesByCategory(): array
    {
        $byCategory = [];
        foreach ($this->worldMothers->categories() as $category) {
            $slug = (string)($category['category_slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $byCategory[$slug] = $this->worldMothers->imagesForCategory($slug);
        }

        return $byCategory;
    }

    /**
     * @param array<int,array<string,mixed>> $images
     * @return array<int,array<string,mixed>>
     */
    private function fallbackContextRowsFromWorldMothers(array $images): array
    {
        $rows = [];
        foreach (array_slice($images, 0, 6) as $image) {
            $category = (string)($image['category_slug'] ?? '');
            $title = (string)($image['category_name'] ?? $category);
            $json = [
                'context_name' => $title,
                'selected_world_id' => $category,
                'space_type' => $title,
                'atmosphere' => 'Reference-led mockup world selected from the real world mother image library.',
                'materials' => [],
                'lighting' => '',
                'placement' => 'artwork-first placement compatible with the reference image',
                'curatorial_reason' => 'Fallback combination built from an available world mother category because no context proposal row was available.',
                'commercial_reason' => 'Keeps the review flow available without generating images.',
                'mockup_prompt' => 'Use the selected world mother reference image as the visual anchor for the environment.',
                'negative_prompt' => '',
            ];
            $rows[] = [
                'id' => 0,
                'artwork_id' => 0,
                'context_name' => $title,
                'context_json' => json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'prompt' => '',
            ];
        }

        if (!$rows) {
            foreach (array_slice($this->worldMothers->categories(), 0, 6) as $categoryRow) {
                $category = (string)($categoryRow['category_slug'] ?? '');
                if ($category === '') {
                    continue;
                }
                $title = (string)($categoryRow['category_name'] ?? $category);
                $json = [
                    'context_name' => $title,
                    'selected_world_id' => $category,
                    'space_type' => $title,
                    'atmosphere' => 'Category-led mockup world generated because no context proposal row or world mother image was available.',
                    'materials' => [],
                    'lighting' => '',
                    'placement' => 'artwork-first placement compatible with a generated world mother reference image',
                    'curatorial_reason' => 'Fallback combination built from an existing world mother category.',
                    'commercial_reason' => 'Keeps the review flow available by generating the missing scene mother from category metadata.',
                    'mockup_prompt' => 'Generate and use a category-compatible world mother reference image as the visual anchor for the environment.',
                    'negative_prompt' => '',
                ];
                $rows[] = [
                    'id' => 0,
                    'artwork_id' => 0,
                    'context_name' => $title,
                    'context_json' => json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'prompt' => '',
                ];
            }
        }

        return $rows;
    }

    private function resolveRootArtworkPath(array $artwork): string
    {
        $rootFile = trim((string)($artwork['root_file'] ?? ''));
        if ($rootFile === '') {
            return '';
        }
        if (is_file($rootFile)) {
            return $rootFile;
        }

        $candidate = rtrim(RESULTS_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($rootFile);
        return $candidate;
    }

    /**
     * @param array<int,array<string,mixed>> $contexts
     * @param array<string,mixed> $artworkAnalysis
     * @param array<string,array<int,array<string,mixed>>> $worldImages
     * @return array<int,array<string,mixed>>
     */
    private function rankWorldMotherCategories(array $contexts, array $artworkAnalysis, array $worldImages): array
    {
        $contextTextParts = [];
        foreach ($contexts as $context) {
            $contextJson = $this->decodeJson((string)($context['context_json'] ?? ''));
            $contextTextParts[] = implode(' ', array_filter([
                $context['context_name'] ?? '',
                $contextJson['selected_world_id'] ?? '',
                $contextJson['assigned_world_id'] ?? '',
                $contextJson['context_world_id'] ?? '',
                $contextJson['selected_family_id'] ?? '',
                $contextJson['assigned_family_id'] ?? '',
                $contextJson['space_type'] ?? '',
                $contextJson['atmosphere'] ?? '',
                is_array($contextJson['materials'] ?? null) ? implode(' ', $contextJson['materials']) : ($contextJson['materials'] ?? ''),
                $contextJson['mockup_prompt'] ?? '',
            ]));
        }

        $contextText = strtolower(implode(' ', $contextTextParts));
        $analysisText = strtolower((string)($artworkAnalysis['text'] ?? ''));
        $analysisKeywords = (array)($artworkAnalysis['keywords'] ?? []);
        $ranked = [];

        foreach ($this->worldMothers->categories() as $category) {
            $slug = (string)($category['category_slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $score = 0;
            $matchedTerms = [];
            foreach (preg_split('/[_\-\s]+/', $slug) ?: [] as $token) {
                $token = strtolower(trim($token));
                if (strlen($token) < 4) {
                    continue;
                }
                if (str_contains($contextText, $token)) {
                    $score += 3;
                    $matchedTerms[] = "context:{$token}";
                }
                if (str_contains($analysisText, $token)) {
                    $score += 2;
                    $matchedTerms[] = "analysis:{$token}";
                }
            }
            foreach ($this->categoryAffinityTerms($slug) as $term) {
                $term = strtolower(trim($term));
                if (strlen($term) < 4) {
                    continue;
                }
                if (str_contains($analysisText, $term)) {
                    $score += 2;
                    $matchedTerms[] = "analysis:{$term}";
                }
                if (str_contains($contextText, $term)) {
                    $score += 1;
                    $matchedTerms[] = "context:{$term}";
                }
            }
            foreach ($analysisKeywords as $keyword) {
                $keyword = strtolower((string)$keyword);
                if (strlen($keyword) >= 4 && str_contains(str_replace('_', ' ', $slug), $keyword)) {
                    $score += 2;
                    $matchedTerms[] = "keyword:{$keyword}";
                }
            }

            $imageCount = count($worldImages[$slug] ?? []);
            $ranked[] = [
                'category_slug' => $slug,
                'category_name' => (string)($category['category_name'] ?? ucwords(str_replace('_', ' ', $slug))),
                'relative_path' => (string)($category['relative_path'] ?? 'storage/world_mothers/' . $slug),
                'absolute_path' => (string)($category['absolute_path'] ?? ''),
                'image_count' => $imageCount,
                'score' => $score,
                'matched_terms' => array_values(array_unique($matchedTerms)),
                'reason' => $matchedTerms
                    ? 'Suggested by artwork/context affinity.'
                    : 'Available scene mother category; no strong text match found.',
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => ((int)$b['score'] <=> (int)$a['score'])
            ?: ((int)$b['image_count'] <=> (int)$a['image_count'])
            ?: strcmp((string)$a['category_slug'], (string)$b['category_slug']));

        return $ranked;
    }

    /**
     * @param array<int,array<string,mixed>> $rankedCategories
     * @param array<string,mixed> $artworkAnalysis
     * @return array<string,mixed>
     */
    private function categoryDecisionForSelectedWorldMother(string $category, array $rankedCategories, array $artworkAnalysis): array
    {
        foreach ($rankedCategories as $rank => $candidate) {
            if ((string)($candidate['category_slug'] ?? '') !== $category) {
                continue;
            }

            return [
                'category_slug' => $category,
                'score' => (int)($candidate['score'] ?? 0),
                'rank' => $rank + 1,
                'reason' => 'User-selected scene mother category from the ranked list. All camera slots use this same scene mother.',
                'matched_terms' => (array)($candidate['matched_terms'] ?? []),
                'analysis_source' => (string)($artworkAnalysis['source'] ?? 'none'),
                'image_count' => (int)($candidate['image_count'] ?? 0),
                'fixed_scene_mother' => true,
            ];
        }

        return [
            'category_slug' => $category,
            'score' => 0,
            'rank' => null,
            'reason' => 'User-selected scene mother category. All camera slots use this same scene mother.',
            'matched_terms' => [],
            'analysis_source' => (string)($artworkAnalysis['source'] ?? 'none'),
            'image_count' => 0,
            'fixed_scene_mother' => true,
        ];
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $worldImages
     * @param array<string,bool> $usedCategories
     */
    /**
     * @return array<string,mixed>
     */
    private function selectWorldMotherCategory(array $context, array $contextJson, array $artworkAnalysis, array $worldImages, array $usedCategories): array
    {
        if (!$worldImages) {
            return [
                'category_slug' => '',
                'score' => 0,
                'reason' => 'No world mother categories are available.',
                'matched_terms' => [],
                'analysis_source' => (string)($artworkAnalysis['source'] ?? 'none'),
            ];
        }

        $contextText = strtolower(implode(' ', array_filter([
            $context['context_name'] ?? '',
            $contextJson['selected_world_id'] ?? '',
            $contextJson['assigned_world_id'] ?? '',
            $contextJson['context_world_id'] ?? '',
            $contextJson['selected_family_id'] ?? '',
            $contextJson['assigned_family_id'] ?? '',
            $contextJson['space_type'] ?? '',
            $contextJson['atmosphere'] ?? '',
            is_array($contextJson['materials'] ?? null) ? implode(' ', $contextJson['materials']) : ($contextJson['materials'] ?? ''),
            $contextJson['mockup_prompt'] ?? '',
        ])));
        $analysisText = strtolower((string)($artworkAnalysis['text'] ?? ''));
        $analysisKeywords = (array)($artworkAnalysis['keywords'] ?? []);

        $bestSlug = '';
        $bestScore = -1;
        $bestMatchedTerms = [];
        foreach (array_keys($worldImages) as $slug) {
            $score = 0;
            $matchedTerms = [];
            foreach (preg_split('/[_\-\s]+/', $slug) ?: [] as $token) {
                $token = strtolower(trim($token));
                if (strlen($token) < 4) {
                    continue;
                }
                if (str_contains($contextText, $token)) {
                    $score += 3;
                    $matchedTerms[] = "context:{$token}";
                }
                if (str_contains($analysisText, $token)) {
                    $score += 2;
                    $matchedTerms[] = "analysis:{$token}";
                }
            }
            foreach ($this->categoryAffinityTerms($slug) as $term) {
                if (str_contains($analysisText, $term)) {
                    $score += 2;
                    $matchedTerms[] = "analysis:{$term}";
                }
                if (str_contains($contextText, $term)) {
                    $score += 1;
                    $matchedTerms[] = "context:{$term}";
                }
            }
            foreach ($analysisKeywords as $keyword) {
                $keyword = strtolower((string)$keyword);
                if (strlen($keyword) >= 4 && str_contains(str_replace('_', ' ', $slug), $keyword)) {
                    $score += 2;
                    $matchedTerms[] = "keyword:{$keyword}";
                }
            }
            if (!isset($usedCategories[$slug])) {
                $score += 1;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSlug = $slug;
                $bestMatchedTerms = array_values(array_unique($matchedTerms));
            }
        }

        $bestSlug = $bestSlug !== '' ? $bestSlug : (string)array_key_first($worldImages);
        return [
            'category_slug' => $bestSlug,
            'score' => $bestScore,
            'reason' => $bestMatchedTerms
                ? 'Selected by matching artwork analysis/context terms against available world mother categories.'
                : 'Selected as the next available real world mother category; no strong analysis/category text match was found.',
            'matched_terms' => $bestMatchedTerms,
            'analysis_source' => (string)($artworkAnalysis['source'] ?? 'none'),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function categoryAffinityTerms(string $slug): array
    {
        $map = [
            'artist_atelier' => ['abstract', 'gesture', 'paint', 'surface', 'studio', 'canvas'],
            'attic_studio' => ['surreal', 'symbolic', 'quiet', 'studio', 'landscape'],
            'belle_epoque' => ['vivid', 'dramatic', 'red', 'blue', 'collector', 'historical'],
            'contemporary_art_museum' => ['bold', 'geometry', 'geometric', 'architectural', 'large', 'blue'],
            'industrial_loft' => ['architectural', 'structure', 'red', 'bold', 'geometry', 'ladder'],
        ];

        return $map[$slug] ?? preg_split('/[_\-\s]+/', strtolower($slug)) ?: [];
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $worldImages
     * @param array<int,array<string,mixed>> $flatWorldImages
     * @param array<string,bool> $usedImages
     * @return array<string,mixed>
     */
    private function selectWorldMotherImage(string $category, array $worldImages, array $flatWorldImages, array $usedImages): array
    {
        $pool = $worldImages[$category] ?? [];
        foreach ($pool as $image) {
            if (!isset($usedImages[(string)($image['relative_path'] ?? '')])) {
                return $image;
            }
        }
        foreach ($flatWorldImages as $image) {
            if (!isset($usedImages[(string)($image['relative_path'] ?? '')])) {
                return $image;
            }
        }

        return $pool[0] ?? $flatWorldImages[0] ?? [];
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $worldImages
     * @return array<string,mixed>
     */
    private function selectWorldMotherImageFromCategory(string $category, array $worldImages, string $cameraSlotId = '', int $combinationIndex = 0, int $artworkId = 0): array
    {
        $pool = $worldImages[$category] ?? [];
        if (!$pool) {
            return [];
        }

        $pool = $this->annotateWorldMotherVariants($pool);
        if (count($pool) === 1) {
            return $pool[0];
        }

        if ($this->usesStableRandomWorldMotherRotation($category, count($pool))) {
            $rotatedPool = $this->stableRandomWorldMotherPool($pool, $category, $artworkId);
            $selected = $rotatedPool[max(0, $combinationIndex - 1) % count($rotatedPool)];
            $selected['world_mother_selection_strategy'] = 'stable_full_pool_rotation';
            return $selected;
        }

        $preferredRole = $this->preferredWorldMotherVariantRole($cameraSlotId, $combinationIndex);
        foreach ($pool as $image) {
            if ((string)($image['world_mother_variant_role'] ?? '') === $preferredRole) {
                return $image;
            }
        }

        if ($preferredRole === 'left') {
            foreach ($pool as $image) {
                if ((string)($image['world_mother_variant_role'] ?? '') === 'opposite') {
                    return $image;
                }
            }
            return $pool[1] ?? $pool[0];
        }

        if ($preferredRole === 'right') {
            foreach ($pool as $image) {
                if ((string)($image['world_mother_variant_role'] ?? '') === 'primary') {
                    return $image;
                }
            }
            return $pool[0];
        }

        if ($preferredRole === 'opposite') {
            return $pool[1] ?? $pool[0];
        }

        return $pool[0];
    }

    private function usesStableRandomWorldMotherRotation(string $category, int $poolCount): bool
    {
        return $poolCount > 2 || in_array($category, ['artist_atelier'], true);
    }

    /**
     * @param array<int,array<string,mixed>> $pool
     * @return array<int,array<string,mixed>>
     */
    private function stableRandomWorldMotherPool(array $pool, string $category, int $artworkId): array
    {
        usort($pool, static function (array $a, array $b): int {
            return strcmp((string)($a['relative_path'] ?? ''), (string)($b['relative_path'] ?? ''));
        });

        usort($pool, static function (array $a, array $b) use ($category, $artworkId): int {
            $aKey = sprintf('%u', crc32($category . '|' . $artworkId . '|' . (string)($a['relative_path'] ?? '')));
            $bKey = sprintf('%u', crc32($category . '|' . $artworkId . '|' . (string)($b['relative_path'] ?? '')));
            return $aKey <=> $bKey;
        });

        foreach ($pool as $index => $image) {
            $image['world_mother_random_rotation_index'] = $index + 1;
            $image['world_mother_random_rotation_count'] = count($pool);
            $pool[$index] = $image;
        }

        return $pool;
    }

    /**
     * @param array<int,array<string,mixed>> $pool
     * @return array<int,array<string,mixed>>
     */
    private function annotateWorldMotherVariants(array $pool): array
    {
        foreach ($pool as $index => $image) {
            $stem = strtolower((string)($image['title'] ?? pathinfo((string)($image['file_name'] ?? ''), PATHINFO_FILENAME)));
            $stem = str_replace(['-', '_'], ' ', $stem);
            $role = 'primary';
            if (preg_match('/\b(left|izquierda)\b/', $stem) === 1) {
                $role = 'left';
            } elseif (preg_match('/\b(right|derecha)\b/', $stem) === 1) {
                $role = 'right';
            } elseif (preg_match('/\b(opposite|reverse|mirrored|mirror|left attack|left side|from left|inversa|opuesta)\b/', $stem) === 1) {
                $role = 'opposite';
            } elseif (preg_match('/\b(primary|main|base|original|right attack)\b/', $stem) === 1) {
                $role = 'primary';
            } elseif ($index > 0) {
                $role = 'opposite';
            }

            $image['world_mother_variant_role'] = $role;
            $image['world_mother_variant_index'] = $index + 1;
            $pool[$index] = $image;
        }

        return $pool;
    }

    private function preferredWorldMotherVariantRole(string $cameraSlotId, int $combinationIndex): string
    {
        $leftAttackSlots = [
            'diagonal_estudio_moderno',
            'obra_apoyada_suelo_7_8',
            'borde_canvas_closeup',
            'esquina_obra_perspectiva_extrema',
            'rasante_superficie_pintura',
            'pasillo_obra_descentrada_proxima',
        ];

        if (in_array($cameraSlotId, $leftAttackSlots, true)) {
            return 'left';
        }

        $alternatingSlots = [
            'contrapicado_7_8',
            'contrapicado_raton_puro',
            'nadir_extremo_arquitectonico',
            'vista_aerea_contexto_ventanas',
        ];

        if (in_array($cameraSlotId, $alternatingSlots, true) && $combinationIndex % 2 === 0) {
            return 'left';
        }

        return 'right';
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $contextJson
     * @param array<string,mixed> $artworkAnalysis
     * @return array<string,mixed>
     */
    private function generateMissingWorldMother(string $category, array $context, array $contextJson, array $artworkAnalysis): array
    {
        $contextTitle = trim((string)($context['context_name'] ?? $contextJson['context_name'] ?? ''));
        if ($contextTitle === '') {
            $contextTitle = ucwords(str_replace('_', ' ', $category));
        }
        $contextDescription = $this->compactDescription($contextJson);
        $materials = $contextJson['materials'] ?? [];
        if (!is_array($materials)) {
            $materials = array_filter(array_map('trim', explode(',', (string)$materials)));
        }

        $analysis = [
            'scene_type' => $contextTitle,
            'architecture_language' => trim((string)($contextJson['space_type'] ?? $contextTitle)),
            'wall_language' => trim((string)($contextJson['world_wall_language'] ?? 'clean generous wall planes suitable for artwork placement')),
            'floor_language' => trim((string)($contextJson['world_floor_language'] ?? 'credible floor plane with realistic perspective and scale grounding')),
            'ceiling_language' => trim((string)($contextJson['world_ceiling_language'] ?? 'architectural ceiling geometry compatible with the selected camera')),
            'lighting' => trim((string)($contextJson['lighting'] ?? $contextJson['world_lighting_bias'] ?? 'refined natural or architectural lighting')),
            'materials' => array_values(array_filter(array_map('strval', $materials))),
            'palette' => ['neutral', 'premium', 'artwork-first'],
            'mood' => array_values(array_filter([
                trim((string)($contextJson['atmosphere'] ?? '')),
                trim((string)($contextJson['curatorial_reason'] ?? '')),
            ])),
            'camera_potential' => array_values(array_filter([
                trim((string)($contextJson['camera_view_expected'] ?? $contextJson['camera_view'] ?? '')),
                trim((string)($contextJson['camera_angle_notes_expected'] ?? $contextJson['camera_angle_notes'] ?? '')),
            ])),
            'negative_risks' => array_values(array_filter([
                'no existing artwork',
                'no framed art',
                'no readable text',
                'no logos',
                'no people',
                trim((string)($contextJson['negative_prompt'] ?? '')),
            ])),
            'category_keywords' => array_values(array_filter(preg_split('/[_\-\s]+/', strtolower($category)) ?: [])),
        ];

        $generated = $this->worldMotherGenerator->generateOriginalWorldMotherForCategory($category, $analysis, [
            'reference_free' => true,
            'context_title' => $contextTitle,
            'context_description' => $contextDescription,
            'artwork_analysis_text' => (string)($artworkAnalysis['text'] ?? ''),
            'notes' => 'Automatic fallback from MockupCombinationEngine: create the missing scene mother using the selected category and context metadata.',
        ]);

        $fileName = (string)($generated['file_name'] ?? basename((string)($generated['absolute_path'] ?? '')));
        return array_replace($generated, [
            'world_mother_id' => $category . '/' . pathinfo($fileName, PATHINFO_FILENAME),
            'category_slug' => $category,
            'category_name' => ucwords(str_replace('_', ' ', $category)),
            'file_name' => $fileName,
            'title' => ucwords(str_replace(['_', '-'], ' ', pathinfo($fileName, PATHINFO_FILENAME))),
            'extension' => strtolower(pathinfo($fileName, PATHINFO_EXTENSION)),
            'modified_at' => date('c'),
            'file_size' => is_file((string)($generated['absolute_path'] ?? '')) ? filesize((string)$generated['absolute_path']) : 0,
        ]);
    }

    /**
     * @param array<string,array<string,mixed>> $cameraSlots
     * @param array<string,bool> $usedSlots
     */
    private function suggestCameraSlotId(array $contextJson, array $cameraSlots, array $usedSlots): string
    {
        foreach ([
            'camera_first_slot_id',
            'camera_slot_id',
            'camera_slot',
        ] as $key) {
            $slotId = trim((string)($contextJson[$key] ?? ''));
            if ($slotId !== '' && isset($cameraSlots[$slotId])) {
                return $slotId;
            }
        }

        foreach (array_keys($cameraSlots) as $slotId) {
            if (!isset($usedSlots[$slotId])) {
                return $slotId;
            }
        }

        return (string)(array_key_first($cameraSlots) ?? '');
    }

    /**
     * @param array<string,mixed> $cameraSlot
     * @param array<int,string> $validationNotes
     * @return array<string,mixed>
     */
    private function buildCombination(
        int $index,
        array $artwork,
        string $rootPath,
        array $context,
        array $contextJson,
        array $worldMother,
        string $suggestedSlotId,
        string $selectedSlotId,
        array $cameraSlot,
        array $validationNotes,
        array $categoryDecision = []
    ): array {
        $cameraGeometry = (string)($cameraSlot['camera_slot_geometry'] ?? '');
        $contextTitle = trim((string)($context['context_name'] ?? $contextJson['context_name'] ?? $worldMother['category_name'] ?? 'Untitled context'));
        if (!empty($categoryDecision['fixed_scene_mother'])) {
            $sceneTitle = trim((string)($worldMother['category_name'] ?? ''));
            if ($sceneTitle === '') {
                $sceneTitle = ucwords(str_replace('_', ' ', (string)($worldMother['category_slug'] ?? 'Scene Mother')));
            }
            $slotTitle = trim((string)($cameraSlot['slot_name'] ?? $selectedSlotId));
            $contextTitle = trim($sceneTitle . ($slotTitle !== '' ? ' / ' . $slotTitle : ''));
        }
        $contextDescription = $this->compactDescription($contextJson);
        if (!empty($categoryDecision['fixed_scene_mother'])) {
            $contextDescription = trim('Single selected scene mother: ' . (string)($worldMother['category_slug'] ?? '') . ' | Camera slot: ' . (string)($cameraSlot['slot_name'] ?? $selectedSlotId) . ($contextDescription !== '' ? ' | ' . $contextDescription : ''));
        }
        $baseCompatibilityReason = trim((string)($contextJson['curatorial_reason'] ?? $contextJson['commercial_reason'] ?? ''));
        $selectionReason = trim((string)($categoryDecision['reason'] ?? ''));
        $matchedTerms = (array)($categoryDecision['matched_terms'] ?? []);
        $compatibilityReason = trim(implode(' ', array_filter([
            $baseCompatibilityReason,
            $selectionReason,
            $matchedTerms ? 'Matched terms: ' . implode(', ', array_slice(array_map('strval', $matchedTerms), 0, 8)) . '.' : '',
        ])));
        if ($compatibilityReason === '') {
            $compatibilityReason = 'Selected by artwork analysis, context metadata, and world mother category affinity.';
        }
        $generationReady = $rootPath !== ''
            && is_file($rootPath)
            && !empty($worldMother['absolute_path'])
            && is_file((string)$worldMother['absolute_path'])
            && $selectedSlotId !== '';

        $proposal = $this->contextProposalForComposer(
            (int)$artwork['id'],
            $contextTitle,
            $contextJson,
            $worldMother,
            $selectedSlotId,
            $cameraSlot,
            $cameraGeometry,
            $categoryDecision
        );

        try {
            $finalPromptPreview = (new AdminPromptComposerPreview())->compose($proposal);
        } catch (Throwable $e) {
            $finalPromptPreview = 'PROMPT PREVIEW ERROR: ' . $e->getMessage();
            $generationReady = false;
            $validationNotes[] = 'Prompt preview could not be composed from ADMIN template: ' . $e->getMessage();
        }

        if (!$generationReady && !$validationNotes) {
            $validationNotes[] = 'Combination is not generation-ready.';
        }

        return [
            'combination_index' => $index,
            'artwork_id' => (int)$artwork['id'],
            'root_artwork_path' => $rootPath,
            'selected_world_id' => (string)($contextJson['selected_world_id'] ?? $contextJson['assigned_world_id'] ?? $contextJson['context_world_id'] ?? ''),
            'world_mother_category' => (string)($worldMother['category_slug'] ?? ''),
            'world_mother_variant_role' => (string)($worldMother['world_mother_variant_role'] ?? 'primary'),
            'world_mother_variant_index' => (int)($worldMother['world_mother_variant_index'] ?? 1),
            'world_mother_selection_strategy' => (string)($worldMother['world_mother_selection_strategy'] ?? 'role_preference'),
            'world_mother_random_rotation_index' => (int)($worldMother['world_mother_random_rotation_index'] ?? 0),
            'world_mother_random_rotation_count' => (int)($worldMother['world_mother_random_rotation_count'] ?? 0),
            'world_mother_reference_mode' => $this->cameraReferenceMode($selectedSlotId),
            'world_mother_image_path' => (string)($worldMother['relative_path'] ?? ''),
            'world_mother_image_absolute_path' => (string)($worldMother['absolute_path'] ?? ''),
            'context_title' => $contextTitle,
            'context_description' => $contextDescription,
            'compatibility_reason' => $compatibilityReason,
            'suggested_camera_slot_id' => $suggestedSlotId,
            'selected_camera_slot_id' => $selectedSlotId,
            'camera_slot_name' => (string)($cameraSlot['slot_name'] ?? ''),
            'camera_slot_description' => $cameraGeometry,
            'final_prompt_preview' => $finalPromptPreview,
            'generation_ready' => $generationReady,
            'validation_notes' => $validationNotes,
            'world_mother_selection' => $categoryDecision,
            'source_context_id' => (int)($context['id'] ?? 0),
            'camera_changed_by_user' => $suggestedSlotId !== '' && $selectedSlotId !== '' && $suggestedSlotId !== $selectedSlotId,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function contextProposalForComposer(
        int $artworkId,
        string $contextTitle,
        array $contextJson,
        array $worldMother,
        string $selectedSlotId,
        array $cameraSlot,
        string $cameraGeometry,
        array $categoryDecision = []
    ): array {
        $json = $contextJson;
        if (!empty($categoryDecision['fixed_scene_mother'])) {
            $json = $this->fixedSceneMotherContextJson($json, $worldMother, $cameraSlot);
        }
        $json['context_name'] = $contextTitle;
        $json['world_mother_category'] = (string)($worldMother['category_slug'] ?? '');
        $json['world_mother_reference_image'] = (string)($worldMother['relative_path'] ?? '');
        $json['world_mother_reference_mode'] = $this->cameraReferenceMode($selectedSlotId);
        $json['camera_slot_id'] = $selectedSlotId;
        $json['camera_slot_name'] = (string)($cameraSlot['slot_name'] ?? $selectedSlotId);
        $json['camera_slot_geometry'] = $cameraGeometry;
        $json['camera_view'] = $json['camera_view_expected'] ?? $json['camera_view'] ?? '';
        $json['camera_group'] = $json['camera_group_expected'] ?? $json['camera_group'] ?? '';
        $json['camera_distance'] = $json['camera_distance_expected'] ?? $json['camera_distance'] ?? '';
        $json['camera_angle_notes'] = $json['camera_angle_notes_expected'] ?? $json['camera_angle_notes'] ?? '';
        $json['mockup_prompt'] = trim((string)($json['mockup_prompt'] ?? ''));
        $json['mockup_combination_notes'] = 'Combination preview uses the root artwork image plus the selected real world mother reference image and the selected camera slot.';
        if ($this->usesAggressivePerspectiveOverride($selectedSlotId)) {
            $json['mockup_prompt'] = trim($json['mockup_prompt'] . "\n\n" . $this->aggressivePerspectiveOverrideText($selectedSlotId));
            $json['camera_angle_notes'] = trim((string)($json['camera_angle_notes'] ?? '') . ' ' . $this->aggressivePerspectiveOverrideText($selectedSlotId));
            $json['negative_prompt'] = $this->relaxPerspectiveNegativePrompt((string)($json['negative_prompt'] ?? ''));
        }
        if ($this->usesDetailCropOverride($selectedSlotId)) {
            $json['mockup_prompt'] = trim($json['mockup_prompt'] . "\n\n" . $this->detailCropOverrideText($selectedSlotId));
            $json['camera_angle_notes'] = trim((string)($json['camera_angle_notes'] ?? '') . ' ' . $this->detailCropOverrideText($selectedSlotId));
            $json['negative_prompt'] = $this->relaxDetailCropNegativePrompt((string)($json['negative_prompt'] ?? ''));
        }

        return [
            'artwork_id' => $artworkId,
            'context_name' => $contextTitle,
            'context_json' => json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Keep the selected scene mother sovereign. The original context row can come
     * from a different world; in fixed-scene mode it must not replace the room.
     *
     * @param array<string,mixed> $json
     * @param array<string,mixed> $worldMother
     * @param array<string,mixed> $cameraSlot
     * @return array<string,mixed>
     */
    private function fixedSceneMotherContextJson(array $json, array $worldMother, array $cameraSlot): array
    {
        $category = (string)($worldMother['category_slug'] ?? '');
        $categoryTitle = (string)($worldMother['category_name'] ?? ucwords(str_replace('_', ' ', $category)));
        $referencePath = (string)($worldMother['relative_path'] ?? '');
        $variantRole = (string)($worldMother['world_mother_variant_role'] ?? 'primary');
        $variantIndex = (int)($worldMother['world_mother_variant_index'] ?? 1);
        $slotName = (string)($cameraSlot['slot_name'] ?? $cameraSlot['slot_id'] ?? 'Selected camera');
        $slotId = (string)($cameraSlot['slot_id'] ?? '');
        $cameraReferenceMode = $this->cameraReferenceMode($slotId);
        $scene = $this->worldMotherSceneAnchor($category, $categoryTitle, $referencePath, $cameraReferenceMode);
        $variantDirective = match ($variantRole) {
            'left' => 'Use this as the left-side scene mother variant, chosen when the useful artwork wall or camera attack should be biased to the left side of the room while preserving the same world.',
            'right' => 'Use this as the right-side scene mother variant, chosen when the useful artwork wall or camera attack should be biased to the right side of the room while preserving the same world.',
            'opposite' => 'Use this as the opposite/complementary scene mother variant, chosen to support reversed room dominance and alternate orbital camera readings while preserving the same world.',
            default => 'Use this as the primary scene mother variant.',
        };
        $cameraRole = $this->worldMotherCameraRole(
            $variantRole,
            $variantIndex,
            $variantDirective,
            $slotName,
            $cameraReferenceMode
        );

        $json['context_role'] = 'single fixed scene mother with camera variation';
        $json['space_type'] = $scene['space_type'];
        $json['atmosphere'] = $scene['atmosphere'];
        $json['materials'] = $scene['materials'];
        $json['lighting'] = $scene['lighting'];
        $json['placement'] = $this->placementForCameraSlot($slotId, (string)($json['placement'] ?? ''));
        unset(
            $json['camera_view_expected'],
            $json['camera_group_expected'],
            $json['camera_distance_expected'],
            $json['camera_angle_notes_expected']
        );
        $json['camera_view'] = 'Selected camera slot viewpoint: ' . $slotName . '.';
        $json['camera_group'] = $slotId !== '' ? $slotId : $slotName;
        $json['camera_distance'] = $cameraReferenceMode === 'reconstructed_view'
            ? 'Use the distance required by the selected camera slot; the world mother supplies room identity, not camera position.'
            : 'Use the distance required by the selected camera slot while preserving the fixed scene mother.';
        $json['camera_angle_notes'] = $cameraReferenceMode === 'reconstructed_view'
            ? 'The camera slot is authoritative for viewpoint. The world mother reference supplies materials, architecture, palette, and lighting, but not its original camera angle.'
            : 'The camera slot geometry is authoritative for viewpoint only; the scene identity remains the supplied world mother image.';
        $json['curatorial_reason'] = $cameraReferenceMode === 'reconstructed_view'
            ? 'The selected world mother defines the architectural and material language. The selected camera slot defines the actual camera position, so extreme viewpoints are rebuilt from the same room DNA instead of copied from the reference photo.'
            : 'The selected world mother image is the binding visual source for the environment. The artwork is placed into that same room language so the camera study changes viewpoint without changing scene identity.';
        $json['commercial_reason'] = 'Maintains a coherent collector-room series: one recognizable premium interior, multiple camera readings, stable material identity, and consistent perceived value.';
        $json['mockup_prompt'] = trim($scene['mockup_prompt'] . "\n\n" . $cameraRole);

        $negative = trim((string)($json['negative_prompt'] ?? ''));
        $sceneNegative = 'do not change the scene mother, no unrelated gallery, no white cube gallery unless present in the world mother image, no generic showroom, no replacement room, no different furniture style, no different wall color family, no invented minimalist museum';
        $json['negative_prompt'] = trim($negative !== '' ? $negative . '; ' . $sceneNegative : $sceneNegative);

        return $json;
    }

    private function placementForCameraSlot(string $slotId, string $existingPlacement): string
    {
        if ($slotId === 'obra_apoyada_suelo_7_8') {
            return 'floor_or_low_support_leaning; slight_backward_lean_against_real_wall_or_stable_object; visible_load_bearing_contact; not_hanging';
        }

        return trim($existingPlacement) !== '' ? trim($existingPlacement) : 'wall_hanging';
    }

    private function cameraReferenceMode(string $slotId): string
    {
        return in_array($slotId, [
            'nadir_extremo_arquitectonico',
            'contrapicado_raton_puro',
            'contrapicado_7_8',
            'vista_aerea_contexto_ventanas',
            'borde_canvas_closeup',
            'esquina_obra_perspectiva_extrema',
            'rasante_superficie_pintura',
            'detalle_textura_lienzo',
        ], true) ? 'reconstructed_view' : 'literal_scene_view';
    }

    private function usesAggressivePerspectiveOverride(string $slotId): bool
    {
        return in_array($slotId, [
            'nadir_extremo_arquitectonico',
            'contrapicado_raton_puro',
            'contrapicado_7_8',
        ], true);
    }

    private function usesDetailCropOverride(string $slotId): bool
    {
        return in_array($slotId, [
            'detalle_textura_lienzo',
            'borde_canvas_closeup',
            'esquina_obra_perspectiva_extrema',
            'rasante_superficie_pintura',
        ], true);
    }

    private function detailCropOverrideText(string $slotId): string
    {
        if ($slotId === 'borde_canvas_closeup') {
            return 'DETAIL CAMERA CROP OVERRIDE: for this canvas-edge close-up, camera-frame cropping is allowed and expected. The final image may show only a physical slice of the artwork face plus the side edge, thickness, wall contact, and cast shadow. This is not permission to alter the artwork itself: preserve the real canvas orientation, real aspect ratio, real colors, and visible local composition. If the source artwork is portrait, the visible fragment must still feel like part of a taller-than-wide canvas, not a complete square painting.';
        }

        return 'DETAIL CAMERA CROP OVERRIDE: for this material-detail camera slot, camera-frame cropping is allowed and expected. The final image may show only a faithful fragment of the real artwork surface. This is not permission to alter the artwork itself: preserve the real canvas orientation, real aspect ratio, real colors, and visible local composition.';
    }

    private function aggressivePerspectiveOverrideText(string $slotId): string
    {
        if ($slotId === 'nadir_extremo_arquitectonico') {
            return 'EXTREME NADIR CAMERA OVERRIDE: this must be the most radical low camera in the set. Use an off-axis floor-corner viewpoint, with the lens almost touching the floor beside one wall, not centered in front of the artwork. Strong optical perspective distortion of the room is intentional and desired: allow extreme wide-lens perspective, stretched near-floor foreground, steep upward/diagonal vanishing lines, and almost deformed architectural depth. Avoid symmetrical frontal monument composition. This override supersedes generic instructions that ask for a soft, controlled, undistorted, or normal gallery viewpoint. Protect only the artwork identity: the artwork must remain the same rigid physical canvas and must not melt, tear, liquify, become a different painting, or lose its core composition.';
        }

        $label = $slotId === 'contrapicado_raton_puro'
            ? 'AGGRESSIVE NADIR CAMERA OVERRIDE'
            : 'AGGRESSIVE CONTRAPICADO CAMERA OVERRIDE';

        return $label . ': for this selected camera slot, strong optical perspective distortion of the room is intentional and desired. Allow dramatic wide-lens perspective, stretched near-floor foreground, steep upward vanishing lines, and almost deformed architectural depth. This override supersedes generic instructions that ask for a soft, controlled, undistorted, or normal gallery viewpoint. Protect only the artwork identity: the artwork must remain the same rigid physical canvas and must not melt, tear, liquify, become a different painting, or lose its core composition.';
    }

    private function relaxPerspectiveNegativePrompt(string $negativePrompt): string
    {
        $negativePrompt = trim($negativePrompt);
        if ($negativePrompt === '') {
            return 'no melted artwork; no torn canvas; no artwork substitution; no changed artwork identity; no eye-level view; no standing-height view; no normal 3/4 gallery photo';
        }

        $remove = [
            'no distortion',
            'no distorted perspective',
            'no excessive wide-angle view',
            'no uncontrolled floor-level distortion',
            'no fisheye',
        ];
        foreach ($remove as $term) {
            $negativePrompt = preg_replace('/(?:^|[;,]\s*)' . preg_quote($term, '/') . '(?=\s*(?:[;,]|$))/i', '', $negativePrompt) ?? $negativePrompt;
        }

        $negativePrompt = preg_replace('/\s*([;,])\s*([;,]\s*)+/', '$1 ', $negativePrompt) ?? $negativePrompt;
        $negativePrompt = trim($negativePrompt, " \t\n\r\0\x0B;,");
        $add = 'controlled aggressive perspective distortion is allowed for the room; no centered frontal composition; no symmetrical monument view; no straight-on product wall view; no melted artwork; no torn canvas; no artwork substitution; no changed artwork identity; no eye-level view; no standing-height view; no normal 3/4 gallery photo; no gentle low angle';

        return trim($negativePrompt !== '' ? $negativePrompt . '; ' . $add : $add);
    }

    private function relaxDetailCropNegativePrompt(string $negativePrompt): string
    {
        $negativePrompt = trim($negativePrompt);
        $remove = [
            'no cropped artwork',
            'no cropped artwork edge',
            'no cropped artwork edges',
        ];
        foreach ($remove as $term) {
            $negativePrompt = preg_replace('/(?:^|[;,]\s*)' . preg_quote($term, '/') . '(?=\s*(?:[;,]|$))/i', '', $negativePrompt) ?? $negativePrompt;
        }

        $negativePrompt = preg_replace('/\s*([;,])\s*([;,]\s*)+/', '$1 ', $negativePrompt) ?? $negativePrompt;
        $negativePrompt = trim($negativePrompt, " \t\n\r\0\x0B;,");
        $add = 'camera-frame crop allowed for selected detail slot; no changed artwork format; no squared portrait artwork; no stretched artwork; no compressed artwork; no artwork substitution; no invented composition';

        return trim($negativePrompt !== '' ? $negativePrompt . '; ' . $add : $add);
    }

    private function worldMotherCameraRole(
        string $variantRole,
        int $variantIndex,
        string $variantDirective,
        string $slotName,
        string $cameraReferenceMode
    ): string {
        if ($cameraReferenceMode === 'reconstructed_view') {
            return trim(sprintf(
                'Scene mother variant: %s #%d. %s Camera role: build "%s" as a new camera construction using the same room DNA. The mother reference supplies architecture, materials, palette, wall/floor language, lighting, and furnishing family; it does not supply the final camera angle, crop, height, or perspective.',
                $variantRole,
                $variantIndex,
                $variantDirective,
                $slotName
            ));
        }

        return trim(sprintf(
            'Scene mother variant: %s #%d. %s Camera role: apply "%s" only as a viewpoint over this same scene mother. Reframe, crop, elevate, lower, or rotate the camera, but do not replace the room, palette, furniture family, wall language, or lighting character.',
            $variantRole,
            $variantIndex,
            $variantDirective,
            $slotName
        ));
    }

    /**
     * @return array{space_type:string,atmosphere:string,materials:string,lighting:string,mockup_prompt:string}
     */
    private function worldMotherSceneAnchor(string $category, string $categoryTitle, string $referencePath, string $cameraReferenceMode): array
    {
        if ($category === 'dark_wood_study') {
            $mockupPrompt = $cameraReferenceMode === 'reconstructed_view'
                ? 'Use the supplied world mother reference image as a room DNA source: ' . $referencePath . '. Preserve the dark wood library/study identity, bookcases, dark paneling, brown leather seating, heavy table, patterned rug, warm spotlights, amber shadows, and moody private collector atmosphere. Reconstruct the room as needed for the selected camera slot; do not inherit the reference photo camera angle.'
                : 'Use the supplied world mother reference image as the binding environment source: ' . $referencePath . '. Preserve its recognizable room DNA: dark wood library/study, bookcases, dark paneling, brown leather seating, heavy table, patterned rug, warm spotlights, amber shadows, and a moody private collector atmosphere. Install the artwork on the available warm brown wall area as a real physical canvas. The camera may change according to the selected camera slot, but the generated room must still read as the same dark wood study, not a white cube gallery, not a modern minimalist loft, and not a generic showroom.';
            return [
                'space_type' => 'Dark private collector library study from the supplied world mother reference image.',
                'atmosphere' => 'Moody, intimate, old-world collector study with warm spot lighting, dark wood, leather, books, and a cultivated private-library feeling. The room must stay warm, dark, enclosed, and materially rich rather than becoming a white gallery or bright loft.',
                'materials' => 'Dark carved wood paneling and trim, floor-to-ceiling bookcases, brown leather club or chesterfield seating, heavy dark wood table, patterned rug, warm brown wall prepared for the artwork, small warm lamps, focused ceiling spotlights, deep amber shadows.',
                'lighting' => 'Warm low collector-room lighting from small lamps and focused spotlights, with controlled shadows and a subdued evening-study atmosphere.',
                'mockup_prompt' => $mockupPrompt,
            ];
        }

        if ($cameraReferenceMode === 'reconstructed_view') {
            return [
                'space_type' => $categoryTitle . ' scene mother reconstructed from the supplied visual reference.',
                'atmosphere' => 'Reference-led premium interior. Preserve the supplied world mother image as the source for room identity, palette, materials, and lighting, while allowing a new camera construction.',
                'materials' => 'Materials, furniture family, wall language, floor language, and lighting must be inferred from and remain consistent with the supplied world mother reference image.',
                'lighting' => 'Use the lighting character visible in the supplied world mother image; do not invent a conflicting time of day or unrelated gallery lighting.',
                'mockup_prompt' => 'Use the supplied world mother reference image as a room DNA source: ' . $referencePath . '. Preserve its recognizable color palette, materials, furniture family, wall language, floor language, and lighting character. Reconstruct the viewpoint required by the selected camera slot; the reference photo camera angle, crop, and perspective are not binding.',
            ];
        }

        return [
            'space_type' => $categoryTitle . ' scene mother from the supplied visual reference.',
            'atmosphere' => 'Reference-led premium interior. Preserve the supplied world mother image as the dominant source for room identity, palette, furnishings, materials, and lighting.',
            'materials' => 'Materials, furniture, wall language, floor language, and lighting must be inferred from and remain consistent with the supplied world mother reference image.',
            'lighting' => 'Use the lighting character visible in the supplied world mother image; do not invent a conflicting time of day or unrelated gallery lighting.',
            'mockup_prompt' => 'Use the supplied world mother reference image as the binding environment source: ' . $referencePath . '. Preserve its recognizable room DNA, color palette, materials, furniture family, wall language, floor language, and lighting character. The selected camera slot may only reframe or rotate this same environment; it must not replace the room with a different interior style.',
        ];
    }

    private function compactDescription(array $contextJson): string
    {
        $materials = $contextJson['materials'] ?? '';
        if (is_array($materials)) {
            $materials = implode(', ', array_filter(array_map('strval', $materials)));
        }
        $parts = array_filter([
            $contextJson['space_type'] ?? '',
            $contextJson['atmosphere'] ?? '',
            $materials,
            $contextJson['lighting'] ?? '',
            $contextJson['placement'] ?? '',
        ], static fn ($value): bool => trim((string)$value) !== '');

        return trim(implode(' | ', array_map('strval', $parts)));
    }

    private function cameraGeometry(array $slot): string
    {
        $parts = [];
        foreach ([
            'camera_height_block',
            'lens_block',
            'vertical_tilt_block',
            'lateral_rotation_block',
            'composition_block',
            'scale_block',
            'depth_of_field_block',
        ] as $key) {
            $value = trim((string)($slot[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        $negativeDirectives = $slot['negative_directives'] ?? [];
        if (is_array($negativeDirectives) && $negativeDirectives) {
            $parts[] = 'Camera negatives: ' . implode(', ', array_filter(array_map('strval', $negativeDirectives))) . '.';
        }

        return implode("\n", $parts);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
