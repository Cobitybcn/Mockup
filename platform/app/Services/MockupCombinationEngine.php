<?php
declare(strict_types=1);

final class MockupCombinationEngine
{
    private PDO $pdo;
    private WorldMotherLibrary $worldMothers;

    public function __construct(?PDO $pdo = null, ?WorldMotherLibrary $worldMothers = null)
    {
        $this->pdo = $pdo ?: Database::connection();
        $this->worldMothers = $worldMothers ?: new WorldMotherLibrary();
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

        $sceneBoardIndex = max(1, min(3, (int)($options['scene_board_index'] ?? 1)));
        $cameraSlots = $this->activeCameraSlots($sceneBoardIndex);
        $worldImages = $this->worldMotherImagesByCategory();
        $flatWorldImages = [];
        foreach ($worldImages as $images) {
            foreach ($images as $image) {
                $flatWorldImages[] = $image;
            }
        }

        $notes = [];
        $directProfile = [
            'source' => 'direct_world_mother_flow',
            'text' => '',
            'keywords' => [],
            'orientation' => '',
            'size_class' => '',
        ];
        if (!$flatWorldImages) {
            $notes[] = 'No selected world mother images found in storage/world_mothers; add curated reference images to that folder before generating combinations.';
        }
        if (!$cameraSlots) {
            $notes[] = 'No enabled camera slots found in app/Config/mockup_camera_slots.php.';
        }

        $selectedWorldMotherCategory = $this->resolveWorldMotherCategory(
            (string)($options['selected_world_mother_category'] ?? ''),
            $worldImages
        );
        if ($selectedWorldMotherCategory === '' && isset($worldImages['selected'])) {
            $selectedWorldMotherCategory = 'selected';
        }

        $rankedWorldMotherCategories = $this->directWorldMotherCategories($worldImages, $selectedWorldMotherCategory);
        if ($selectedWorldMotherCategory === '' && $rankedWorldMotherCategories) {
            $selectedWorldMotherCategory = (string)($rankedWorldMotherCategories[0]['category_slug'] ?? '');
        }
        $contexts = $this->directContextRowsForCameraSlots($artworkId, $cameraSlots, $selectedWorldMotherCategory);
        $rootPath = $this->resolveRootArtworkPath($artwork);
        $usedSlots = [];
        $combinations = [];
        $slotIds = array_keys($cameraSlots);
        $worldMotherVariantOffsets = array_map('intval', (array)($options['world_mother_variant_offsets'] ?? []));

        $targetCount = max(1, count($slotIds));
        for ($i = 0; $i < $targetCount; $i++) {
            $context = $contexts[$i % max(1, count($contexts))] ?? [];
            $contextJson = $this->decodeJson((string)($context['context_json'] ?? ''));
            $categoryDecision = $this->categoryDecisionForSelectedWorldMother($selectedWorldMotherCategory, $rankedWorldMotherCategories, $directProfile);
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
            $variantOffset = max(0, (int)($worldMotherVariantOffsets[$i + 1] ?? 0));
            $worldMother = $this->selectWorldMotherImageFromCategory($category, $worldImages, $selectedSlotId, $i + 1, $artworkId, $variantOffset);
            if ($worldMother) {
                $worldMother = $this->resolveWorldMotherImagePath($worldMother);
            }
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
            'scene_board_index' => $sceneBoardIndex,
            'generated_at' => date(DATE_ATOM),
            'root_artwork_path' => $rootPath,
            'direct_world_mother_profile' => $directProfile,
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
    public function activeCameraSlots(int $sceneBoardIndex = 1): array
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

        $sceneBoardIndex = max(1, min(3, $sceneBoardIndex));
        $boardSlots = (array)($config['scene_boards'][$sceneBoardIndex]['slots'] ?? []);
        if (!$boardSlots && $sceneBoardIndex === 1) {
            $boardSlots = (array)($config['scene_board']['slots'] ?? []);
        }
        $boardSlotIds = array_values(array_filter(array_map('strval', $boardSlots), static fn (string $slotId): bool => $slotId !== ''));
        if ($boardSlotIds) {
            $sceneBoardSlots = [];
            foreach ($boardSlotIds as $boardOrder => $slotId) {
                if (!isset($active[$slotId])) {
                    continue;
                }
                $slot = $active[$slotId];
                $slot['board_order'] = $boardOrder + 1;
                $slot['scene_board_index'] = $sceneBoardIndex;
                $sceneBoardSlots[$slotId] = $slot;
            }

            return $sceneBoardSlots;
        }

        if ($sceneBoardIndex > 1) {
            return [];
        }

        $sceneBoardSlots = array_filter($active, static function (array $slot): bool {
            return (int)($slot['board_order'] ?? 0) > 0
                || !empty($slot['primary_scene_set'])
                || (trim((string)($slot['group_id'] ?? '')) !== '' && (int)($slot['variant_order'] ?? 0) > 0);
        });
        if ($sceneBoardSlots) {
            uasort($sceneBoardSlots, static function (array $a, array $b): int {
                $aBoardOrder = (int)($a['board_order'] ?? 0);
                $bBoardOrder = (int)($b['board_order'] ?? 0);
                if ($aBoardOrder > 0 || $bBoardOrder > 0) {
                    return (($aBoardOrder > 0 ? $aBoardOrder : 9999) <=> ($bBoardOrder > 0 ? $bBoardOrder : 9999))
                        ?: strcmp((string)($a['slot_name'] ?? ''), (string)($b['slot_name'] ?? ''));
                }

                return ((int)($a['group_order'] ?? 999) <=> (int)($b['group_order'] ?? 999))
                    ?: ((int)($a['variant_order'] ?? 999) <=> (int)($b['variant_order'] ?? 999))
                    ?: strcmp((string)($a['slot_name'] ?? ''), (string)($b['slot_name'] ?? ''));
            });

            return $sceneBoardSlots;
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
     * Preserve real folder names while still accepting legacy normalized URLs.
     *
     * @param array<string,array<int,array<string,mixed>>> $worldImages
     */
    private function resolveWorldMotherCategory(string $value, array $worldImages): string
    {
        $value = trim(str_replace(['\\', '/'], '', $value));
        if ($value === '') {
            return '';
        }
        if (isset($worldImages[$value])) {
            return $value;
        }

        $normalized = WorldMotherGenerator::safeSlug($value);
        foreach (array_keys($worldImages) as $category) {
            if (WorldMotherGenerator::safeSlug((string)$category) === $normalized) {
                return (string)$category;
            }
        }

        // Stale links used to pass the virtual category "selected". That
        // folder no longer exists after the scene-library reorganization.
        // Treat every unknown category as no selection so the caller can
        // choose a real, indexed scene instead of building a broken batch.
        return '';
    }

    /**
     * @param array<string,array<string,mixed>> $cameraSlots
     * @return array<int,array<string,mixed>>
     */
    private function directContextRowsForCameraSlots(int $artworkId, array $cameraSlots, string $worldMotherCategory): array
    {
        $rows = [];
        $slotList = array_values($cameraSlots);
        $count = max(1, count($slotList));
        for ($i = 0; $i < $count; $i++) {
            $slot = $slotList[$i] ?? [];
            $slotName = trim((string)($slot['slot_name'] ?? $slot['slot_id'] ?? 'selected camera slot'));
            $json = [
                'direct_world_mother_mode' => true,
                'context_name' => 'Selected world mother',
                'context_role' => 'direct world mother plus selected camera slot',
                'selected_world_id' => $worldMotherCategory,
                'space_type' => 'Use only the selected world mother reference image as the environment source.',
                'atmosphere' => 'Inherit atmosphere from the selected world mother image.',
                'materials' => 'Inherit materials from the selected world mother image.',
                'lighting' => 'Inherit lighting from the selected world mother image.',
                'placement' => '',
                'human_presence' => 'none unless required by the selected camera slot.',
                'mockup_prompt' => '',
                'negative_prompt' => '',
                'camera_slot_name' => $slotName,
            ];
            $rows[] = [
                'id' => 0,
                'artwork_id' => $artworkId,
                'context_name' => 'Selected world mother',
                'context_json' => json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'prompt' => '',
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $worldImages
     * @return array<int,array<string,mixed>>
     */
    private function directWorldMotherCategories(array $worldImages, string $selectedWorldMotherCategory): array
    {
        $ranked = [];
        foreach ($this->worldMothers->categories() as $category) {
            $slug = (string)($category['category_slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $isSelected = $selectedWorldMotherCategory !== '' && $slug === $selectedWorldMotherCategory;
            $ranked[] = [
                'category_slug' => $slug,
                'category_name' => (string)($category['category_name'] ?? ucwords(str_replace('_', ' ', $slug))),
                'relative_path' => (string)($category['relative_path'] ?? 'storage/world_mothers/' . $slug),
                'absolute_path' => (string)($category['absolute_path'] ?? ''),
                'image_count' => count($worldImages[$slug] ?? []),
                'score' => $isSelected ? 1 : 0,
                'matched_terms' => [],
                'reason' => $isSelected
                    ? 'Directly selected scene mother folder. No artwork analysis or curatorial ranking was used.'
                    : 'Available scene mother folder. No artwork analysis or curatorial ranking was used.',
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => ((int)$b['score'] <=> (int)$a['score'])
            ?: ((int)$b['image_count'] <=> (int)$a['image_count'])
            ?: strcmp((string)$a['category_slug'], (string)$b['category_slug']));

        return $ranked;
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
        if (is_file($candidate)) {
            return $candidate;
        }

        // Self-healing: if GCS is active, download it to the container's ephemeral disk
        if (StorageService::isGcsActive()) {
            $gcsPath = 'results/' . basename($rootFile);
            $dir = dirname($candidate);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            if (StorageService::downloadFile($gcsPath, $candidate)) {
                return $candidate;
            }
        }

        return $candidate;
    }

    /**
     * @param array<string,mixed> $worldMother
     * @return array<string,mixed>
     */
    private function resolveWorldMotherImagePath(array $worldMother): array
    {
        $absolutePath = (string)($worldMother['absolute_path'] ?? '');
        if ($absolutePath !== '' && is_file($absolutePath)) {
            return $worldMother;
        }

        $relativePath = trim(str_replace('\\', '/', (string)($worldMother['relative_path'] ?? '')));
        if ($relativePath === '' || !str_starts_with($relativePath, 'storage/world_mothers/')) {
            return $worldMother;
        }

        if ($absolutePath === '') {
            $absolutePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $worldMother['absolute_path'] = $absolutePath;
        }

        if (!StorageService::isGcsActive()) {
            return $worldMother;
        }

        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (StorageService::downloadFile($relativePath, $absolutePath)) {
            $worldMother['absolute_path'] = $absolutePath;
        }

        return $worldMother;
    }

    /**
     * @param array<int,array<string,mixed>> $rankedCategories
     * @param array<string,mixed> $directProfile
     * @return array<string,mixed>
     */
    private function categoryDecisionForSelectedWorldMother(string $category, array $rankedCategories, array $directProfile): array
    {
        foreach ($rankedCategories as $rank => $candidate) {
            if ((string)($candidate['category_slug'] ?? '') !== $category) {
                continue;
            }

            return [
                'category_slug' => $category,
                'score' => (int)($candidate['score'] ?? 0),
                'rank' => $rank + 1,
                'reason' => 'User-selected scene mother category from the ranked list. Camera slots reconstruct this visual world from their own viewpoint.',
                'matched_terms' => (array)($candidate['matched_terms'] ?? []),
                'source' => (string)($directProfile['source'] ?? 'direct_world_mother_flow'),
                'image_count' => (int)($candidate['image_count'] ?? 0),
                'fixed_scene_mother' => true,
            ];
        }

        return [
            'category_slug' => $category,
            'score' => 0,
            'rank' => null,
                'reason' => 'User-selected scene mother category. Camera slots reconstruct this visual world from their own viewpoint.',
            'matched_terms' => [],
            'source' => (string)($directProfile['source'] ?? 'direct_world_mother_flow'),
            'image_count' => 0,
            'fixed_scene_mother' => true,
        ];
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $worldImages
     * @return array<string,mixed>
     */
    private function selectWorldMotherImageFromCategory(string $category, array $worldImages, string $cameraSlotId = '', int $combinationIndex = 0, int $artworkId = 0, int $variantOffset = 0): array
    {
        $pool = $worldImages[$category] ?? [];
        if (!$pool) {
            return [];
        }

        $pool = $this->annotateWorldMotherVariants($pool);
        if (count($pool) === 1) {
            return $pool[0];
        }

        if ($this->usesStableRandomWorldMotherRotation($category, count($pool), $cameraSlotId)) {
            $rotatedPool = $this->stableRandomWorldMotherPool($pool, $category, $artworkId);
            $selected = $rotatedPool[max(0, $combinationIndex - 1 + $variantOffset) % count($rotatedPool)];
            $selected['world_mother_selection_strategy'] = 'stable_full_pool_rotation';
            $selected['world_mother_variant_offset'] = $variantOffset;
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

    private function usesStableRandomWorldMotherRotation(string $category, int $poolCount, string $cameraSlotId): bool
    {
        return $poolCount > 1;
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
            $contextDescription = trim('Selected visual world: ' . (string)($worldMother['category_slug'] ?? '') . ' | Camera slot: ' . (string)($cameraSlot['slot_name'] ?? $selectedSlotId) . ($contextDescription !== '' ? ' | ' . $contextDescription : ''));
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
            $compatibilityReason = 'Selected directly from the world mother folder and camera slot.';
        }
        $generationReady = $rootPath !== ''
            && is_file($rootPath)
            && !empty($worldMother['absolute_path'])
            && is_file((string)$worldMother['absolute_path'])
            && $selectedSlotId !== '';

        $proposal = $this->contextProposalForComposer(
            (int)$artwork['id'],
            $index,
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
            'world_mother_variant_offset' => (int)($worldMother['world_mother_variant_offset'] ?? 0),
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
            'camera_slot_group_id' => (string)($cameraSlot['group_id'] ?? ''),
            'camera_slot_group_name' => (string)($cameraSlot['group_name'] ?? ''),
            'camera_slot_group_order' => (int)($cameraSlot['group_order'] ?? 0),
            'camera_slot_variant_label' => (string)($cameraSlot['variant_label'] ?? ''),
            'camera_slot_variant_order' => (int)($cameraSlot['variant_order'] ?? 0),
            'camera_slot_scene_board_index' => (int)($cameraSlot['scene_board_index'] ?? 1),
            'camera_slot_board_order' => (int)($cameraSlot['board_order'] ?? 0),
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
        int $combinationIndex,
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
        $json['direct_world_mother_mode'] = !empty($json['direct_world_mother_mode']) || !empty($categoryDecision['fixed_scene_mother']);
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
            'left' => 'Use this as the left-biased style variant: it suggests material, light, and spatial rhythm that may support a left-side camera attack in a newly built environment.',
            'right' => 'Use this as the right-biased style variant: it suggests material, light, and spatial rhythm that may support a right-side camera attack in a newly built environment.',
            'opposite' => 'Use this as the opposite/complementary style variant: it supports alternate orbital camera readings while preserving the same environmental family, not the same room layout.',
            default => 'Use this as the primary scene mother variant.',
        };
        $cameraRole = $this->worldMotherCameraRole(
            $variantRole,
            $variantIndex,
            $variantDirective,
            $slotName,
            $cameraReferenceMode
        );

        $json['context_role'] = 'selected world mother visual DNA transformed by the camera slot into a new environment';
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
            ? 'Use the distance required by the selected camera slot. The world mother image provides material, light, atmosphere, and environmental-family evidence, but not the camera position, layout, crop, room geometry, wall choice, furniture placement, window placement, or perspective.'
            : 'Use the distance required by the selected camera slot. The world mother image provides material, light, atmosphere, and environmental-family evidence, but not the final camera position, wall choice, object placement, layout, crop, room geometry, window placement, or perspective.';
        $json['camera_angle_notes'] = $cameraReferenceMode === 'reconstructed_view'
            ? 'The camera slot is authoritative for viewpoint, lens, crop, height, perspective, and composition. The world mother image supplies material language, palette, lighting, atmospheric density, and architectural mood only. Build a new environment from that visual family through the selected camera viewpoint rather than preserving the source photo room.'
            : 'The camera slot geometry is authoritative for viewpoint and composition. Build a new photograph inside the same environmental family: keep material language, palette, light quality, and architectural mood, but do not preserve the source photo layout, wall choice, window placement, object positions, crop, or camera angle.';
        $json['curatorial_reason'] = '';
        $json['commercial_reason'] = '';
        $json['mockup_prompt'] = trim($scene['mockup_prompt'] . "\n\n" . $cameraRole);

        $negative = trim((string)($json['negative_prompt'] ?? ''));
        $sceneNegative = 'no unrelated gallery, no white cube gallery unless required by the environmental family, no generic showroom, no replacement environment family, no different material palette, no different light temperature family, no invented minimalist museum, no frozen copy of the source photo composition, no copied world mother layout, no same sofa-window-wall composition, no same window placement as the reference image, no same furniture placement as the reference image, no treating the world mother as a room template, no subordinating the camera slot to the world mother photo';
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
        // A world mother is environmental inspiration for every camera slot.
        // The selected camera must always rebuild the scene composition instead
        // of inheriting the reference photo literally.
        return 'reconstructed_view';
    }

    private function usesAggressivePerspectiveOverride(string $slotId): bool
    {
        return in_array($slotId, [
            'camara_15_contrapicado_inpainting',
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
        if ($slotId === 'camara_15_contrapicado_inpainting') {
            return 'CAMERA 15 INPAINTING CONTRAPICADO OVERRIDE: use a protected precomposed artwork plate with a user mask, then generate the room around it. The camera must be a forceful floor-level low-angle view, 2-8 cm from the floor, slightly right of the artwork, using a low 7/8 right oblique attack. Strong optical perspective distortion of the room is intentional and desired: stretched near-floor foreground, steep upward vanishing lines, rising verticals, high ceiling structure, and dramatic architectural depth. The artwork itself must remain the same protected physical object with real scale, original orientation, aspect ratio, visible layout, color fields, mark placement, empty areas, sparse composition, and proportions. Do not solve drama by enlarging, repainting, bending, stretching, rotating, or replacing the artwork.';
        }

        if ($slotId === 'nadir_extremo_arquitectonico') {
            return 'EXTREME NADIR CAMERA OVERRIDE: use an off-axis floor-corner viewpoint, with the lens almost touching the floor beside one wall, not centered in front of the artwork. Strong optical perspective distortion of the room is intentional and desired: allow extreme wide-lens perspective, stretched near-floor foreground, steep upward/diagonal vanishing lines, and almost deformed architectural depth in the architecture only. Avoid symmetrical frontal monument composition. This override supersedes generic instructions that ask for a soft, controlled, undistorted, or normal gallery viewpoint. The artwork itself must remain the same rigid physical canvas with its original orientation, aspect ratio, visible layout, color fields, mark placement, empty areas, sparse composition, and proportions. Do not melt, tear, liquify, reformat, repaint, rotate, stretch, become a different painting, or lose its core composition.';
        }

        $label = $slotId === 'contrapicado_raton_puro'
            ? 'AGGRESSIVE NADIR CAMERA OVERRIDE'
            : 'AGGRESSIVE CONTRAPICADO CAMERA OVERRIDE';

        return $label . ': for this selected camera slot, strong optical perspective distortion of the room is intentional and desired. Allow dramatic wide-lens perspective, stretched near-floor foreground, steep upward vanishing lines, and almost deformed architectural depth in the architecture only. This override supersedes generic instructions that ask for a soft, controlled, undistorted, or normal gallery viewpoint. The artwork itself must remain the same rigid physical canvas with its original orientation, aspect ratio, visible layout, color fields, mark placement, empty areas, sparse composition, and proportions. Do not melt, tear, liquify, reformat, repaint, rotate, stretch, become a different painting, or lose its core composition.';
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
                'Scene mother variant: %s #%d. %s Camera role: build "%s" as a new environment photographed through the selected camera slot. The mother reference supplies visual evidence for materiality, palette, light quality, atmospheric density, architectural mood, and environmental family; it does not supply the final camera angle, layout, crop, height, perspective, room geometry, wall choice, window placement, furniture placement, or object positions. Reconstruct, relocate, or invent compatible architecture and objects as needed so the camera slot can fully govern the image.',
                $variantRole,
                $variantIndex,
                $variantDirective,
                $slotName
            ));
        }

        return trim(sprintf(
            'Scene mother variant: %s #%d. %s Camera role: build "%s" as a new photograph inside this environmental family. The mother reference supplies visual evidence for materiality, palette, light quality, atmospheric density, architectural mood, and compatible object/material choices; it must not supply the final wall choice, window placement, furniture placement, object positions, camera angle, crop, or room layout. Rebuild the space around the root artwork and selected camera slot.',
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
                ? 'Use the supplied world mother reference image as visual evidence for a dark collector-study environmental family: ' . $referencePath . '. Build a new environment with dark wood materiality, warm spot lighting, deep amber shadows, bookish/private-study atmosphere, and cultivated collector mood. Do not reproduce the source room layout, camera angle, bookcase placement, seating placement, wall choice, crop, or object positions. Reconstruct architecture and objects as needed for the selected camera slot.'
                : 'Use the supplied world mother reference image as visual evidence for a dark collector-study environmental family: ' . $referencePath . '. Build a new compatible environment with dark wood materiality, warm lighting, private-library atmosphere, and cultivated collector mood. The selected camera slot must choose the final wall, crop, object placement, camera angle, and perspective; do not preserve the source photo layout, seating placement, bookcase placement, or room geometry.';
            return [
                'space_type' => 'New dark private collector-study environment built from the supplied world mother visual family.',
                'atmosphere' => 'Moody, intimate, old-world collector-study atmosphere with warm spot lighting, dark wood materiality, books/private-library cues, and cultivated collector feeling. Keep the family warm, dark, enclosed, and materially rich rather than becoming a white gallery or bright loft.',
                'materials' => 'Dark carved or paneled wood materiality, warm brown surfaces, bookish collector cues, leather or textile warmth when useful, patterned rug or heavy table cues only if compatible with the selected camera, small warm lamps, focused ceiling spotlights, deep amber shadows. Layout and object positions must be newly composed.',
                'lighting' => 'Warm low collector-room lighting from small lamps and focused spotlights, with controlled shadows and a subdued evening-study atmosphere.',
                'mockup_prompt' => $mockupPrompt,
            ];
        }

        if ($cameraReferenceMode === 'reconstructed_view') {
            return [
                'space_type' => 'New ' . $categoryTitle . ' environment built from the supplied world mother visual family.',
                'atmosphere' => 'Reference-led premium environment. Use the supplied world mother image as visual evidence for materiality, palette, light quality, atmospheric density, architectural mood, and environmental family while building a new scene for the selected camera.',
                'materials' => 'Material palette, surface texture, light behavior, spatial density, and architectural mood should be inferred from the supplied world mother reference image. Windows, furniture, objects, wall choice, room geometry, and layout must be newly composed to obey the selected camera slot.',
                'lighting' => 'Use the lighting character visible in the supplied world mother image; do not invent a conflicting time of day or unrelated gallery lighting.',
                'mockup_prompt' => 'Use the supplied world mother reference image as visual evidence for the environmental family: ' . $referencePath . '. Build a new environment with compatible materiality, color palette, surface texture, atmospheric density, architectural mood, and lighting character. The selected camera slot must determine the final camera angle, wall choice, window placement, furniture placement, object positions, layout, crop, and perspective; the reference photo camera angle, room geometry, layout, crop, and perspective are not binding.',
            ];
        }

        return [
            'space_type' => 'New ' . $categoryTitle . ' environment built from the supplied world mother visual family.',
            'atmosphere' => 'Reference-led premium environment. Use the supplied world mother image as source evidence for materiality, palette, light quality, atmospheric density, architectural mood, and environmental family while building a new camera composition.',
            'materials' => 'Material palette, surface texture, light behavior, spatial density, and architectural mood must be inferred from the supplied world mother reference image, but windows, furniture, objects, wall choice, room geometry, object placement, and layout must be newly composed.',
            'lighting' => 'Use the lighting character visible in the supplied world mother image; do not invent a conflicting time of day or unrelated gallery lighting.',
            'mockup_prompt' => 'Use the supplied world mother reference image as visual evidence for the environmental family: ' . $referencePath . '. Build a new compatible environment with similar color palette, materiality, surface texture, atmospheric density, architectural mood, and lighting character. The selected camera slot must choose the final wall, window placement, furniture placement, object placement, crop, camera angle, and perspective; do not preserve the source photo layout or room geometry.',
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
            'depth_of_field_block',
        ] as $key) {
            $value = trim((string)($slot[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        $integrity = $this->cameraIntegrityBlock($slot);
        if ($integrity !== '') {
            $parts[] = $integrity;
        }
        $negativeDirectives = $slot['negative_directives'] ?? [];
        if (is_array($negativeDirectives) && $negativeDirectives) {
            $parts[] = 'Camera negatives: ' . implode(', ', array_filter(array_map('strval', $negativeDirectives))) . '.';
        }

        return implode("\n", $parts);
    }

    private function cameraIntegrityBlock(array $slot): string
    {
        $scaleBlock = trim((string)($slot['scale_block'] ?? ''));
        if ($scaleBlock === '') {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $scaleBlock) ?: [];
        $keep = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }
            $safeScaleSentence = preg_match('/\b(supplied physical|supplied dimensions|supplied artwork|technical data|physical artwork dimensions|real scale|true physical scale|do not enlarge|not a|never as|must remain consistent|according to the artwork|correctly scaled|plausible real scale)\b/i', $sentence) === 1;
            $dangerousScaleSentence = preg_match('/\b(XL|substantial|monumental|billboard|mural|door|person|adult|height|portion of the wall|global dominance)\b/i', $sentence) === 1;

            if ($dangerousScaleSentence && !$safeScaleSentence) {
                continue;
            }
            if (preg_match('/\b(canvas|artwork|identity|fidelity|preserve|rigid|rectangular|poster|print|screen|warp|bend|curve|wedge|substitution|replace|repaint|recolor|deform|melt|tear|orientation|aspect ratio|thickness|texture|scale|dimensions|enlarge|billboard|mural|physical size)\b/i', $sentence)) {
                $keep[] = $sentence;
            }
        }

        if (!$keep) {
            return '';
        }

        return 'Camera object integrity: ' . implode(' ', array_values(array_unique($keep)));
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
