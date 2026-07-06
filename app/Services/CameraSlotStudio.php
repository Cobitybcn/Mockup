<?php
declare(strict_types=1);

final class CameraSlotStudio
{
    private GeminiImageClient $client;

    public function __construct(?GeminiImageClient $client = null)
    {
        $this->client = $client ?: new GeminiImageClient();
    }

    /**
     * @return array<string,mixed>
     */
    public function draftSlot(string $brief, array $metadata = []): array
    {
        $brief = trim($brief);
        if ($brief === '') {
            throw new RuntimeException('Describe la camara que queres crear.');
        }

        $draft = ProviderSettings::isRealMode() && ProviderSettings::imageProvider() === 'gemini'
            ? $this->draftWithGemini($brief, $metadata)
            : $this->fallbackDraft($brief, $metadata);

        return $this->normalizeDraft($draft, $brief, $metadata);
    }

    /**
     * @return array<string,mixed>
     */
    public function cameraConfig(): array
    {
        $config = require dirname(__DIR__) . '/Config/mockup_camera_slots.php';
        return is_array($config) ? $config : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function baseCameraConfig(): array
    {
        $skipCustomCameraSlots = true;
        $config = require dirname(__DIR__) . '/Config/mockup_camera_slots.php';
        return is_array($config) ? $config : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function customCameraConfig(): array
    {
        $customPath = $this->customConfigPath();
        if (!is_file($customPath)) {
            return ['sets' => [], 'slots' => [], 'scene_board' => [], 'scene_boards' => []];
        }
        $loaded = require $customPath;
        if (!is_array($loaded)) {
            return ['sets' => [], 'slots' => [], 'scene_board' => [], 'scene_boards' => []];
        }
        return [
            'sets' => is_array($loaded['sets'] ?? null) ? $loaded['sets'] : [],
            'slots' => is_array($loaded['slots'] ?? null) ? $loaded['slots'] : [],
            'scene_board' => is_array($loaded['scene_board'] ?? null) ? $loaded['scene_board'] : [],
            'scene_boards' => is_array($loaded['scene_boards'] ?? null) ? $loaded['scene_boards'] : [],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function existingSlots(): array
    {
        $config = $this->cameraConfig();
        return is_array($config['slots'] ?? null) ? $config['slots'] : [];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function baseSlots(): array
    {
        $config = $this->baseCameraConfig();
        return is_array($config['slots'] ?? null) ? $config['slots'] : [];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function customSlots(): array
    {
        $config = $this->customCameraConfig();
        return is_array($config['slots'] ?? null) ? $config['slots'] : [];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function sceneBoardGroups(array $overrides = []): array
    {
        $groups = [
            'real_wall' => [
                'group_id' => 'real_wall',
                'group_name' => 'En una pared real',
                'group_order' => 1,
                'variants' => [
                    1 => 'Frontal',
                    2 => '3/4 derecha',
                    3 => '3/4 izquierda',
                ],
            ],
            'architectural_context' => [
                'group_id' => 'architectural_context',
                'group_name' => 'Contexto arquitectónico',
                'group_order' => 2,
                'variants' => [
                    1 => '3/4 perspectiva',
                    2 => '7/8 derecha',
                    3 => '7/8 izquierda',
                ],
            ],
            'artistic_cameras' => [
                'group_id' => 'artistic_cameras',
                'group_name' => 'Cámaras artísticas',
                'group_order' => 3,
                'variants' => [
                    1 => 'Nadir extremo / monumental',
                    2 => 'Aérea entrepiso',
                    3 => 'Aérea extrema cenital',
                ],
            ],
            'texture_canvas' => [
                'group_id' => 'texture_canvas',
                'group_name' => 'Textura y canvas',
                'group_order' => 4,
                'variants' => [
                    1 => 'Detalle de lienzo',
                    2 => 'Detalle lateral',
                    3 => 'Detalle de esquina',
                ],
            ],
        ];

        $custom = $this->customCameraConfig();
        $stored = is_array($custom['scene_board']['groups'] ?? null) ? $custom['scene_board']['groups'] : [];
        foreach ([$stored, $overrides] as $source) {
            foreach ((array)$source as $groupId => $patch) {
                if (!is_string($groupId) || !isset($groups[$groupId]) || !is_array($patch)) {
                    continue;
                }
                $groupName = trim((string)($patch['group_name'] ?? ''));
                if ($groupName !== '') {
                    $groups[$groupId]['group_name'] = $groupName;
                }
                if (isset($patch['group_order'])) {
                    $groups[$groupId]['group_order'] = max(1, (int)$patch['group_order']);
                }
                foreach ((array)($patch['variants'] ?? []) as $variantOrder => $label) {
                    $variantOrder = (int)$variantOrder;
                    $label = trim((string)$label);
                    if ($variantOrder >= 1 && $variantOrder <= 3 && $label !== '') {
                        $groups[$groupId]['variants'][$variantOrder] = $label;
                    }
                }
            }
        }

        uasort($groups, static function (array $a, array $b): int {
            return ((int)($a['group_order'] ?? 999)) <=> ((int)($b['group_order'] ?? 999));
        });

        return $groups;
    }

    /**
     * @param array<string,mixed> $boardSlots
     * @return array<string,mixed>
     */
    public function saveSceneBoard(array $boardSlots, array $boardRows = [], int $boardIndex = 1): array
    {
        $custom = $this->customCameraConfig();
        $boards = [];
        for ($index = 1; $index <= 3; $index++) {
            $boards[(string)$index] = (array)($custom['scene_boards'][(string)$index]['slots'] ?? ($index === 1 ? ($custom['scene_board']['slots'] ?? []) : []));
        }
        $boards[(string)max(1, min(3, $boardIndex))] = $boardSlots;
        return $this->saveSceneBoards($boards);
    }

    /**
     * @param array<string,mixed> $boards
     * @return array<string,mixed>
     */
    public function saveSceneBoards(array $boards): array
    {
        $slots = $this->existingSlots();
        $custom = $this->customCameraConfig();

        $custom['scene_boards'] = is_array($custom['scene_boards'] ?? null) ? $custom['scene_boards'] : [];
        $allAssigned = [];
        $boardOneSlots = [];
        $totalAssigned = 0;

        for ($boardIndex = 1; $boardIndex <= 3; $boardIndex++) {
            $boardSlots = (array)($boards[(string)$boardIndex] ?? $boards[$boardIndex] ?? []);
            $orderedSlotIds = [];
            array_walk_recursive($boardSlots, static function ($value) use (&$orderedSlotIds): void {
                $slotId = trim((string)$value);
                if ($slotId !== '') {
                    $orderedSlotIds[] = $slotId;
                }
            });
            $orderedSlotIds = array_values(array_unique(array_filter($orderedSlotIds, static function (string $slotId) use ($slots): bool {
                return isset($slots[$slotId]);
            })));

            $custom['scene_boards'][(string)$boardIndex] = [
                'label' => 'Tablero ' . $boardIndex,
                'slots' => $orderedSlotIds,
                'updated_at' => date(DATE_ATOM),
            ];

            if ($boardIndex === 1) {
                $boardOneSlots = $orderedSlotIds;
            }
            foreach ($orderedSlotIds as $slotId) {
                $allAssigned[$slotId] = true;
                $totalAssigned++;
            }
        }

        $custom['scene_board']['slots'] = $boardOneSlots;
        $custom['scene_board']['updated_at'] = date(DATE_ATOM);

        foreach ($slots as $slotId => $slot) {
            $slot = $this->publishedSlotPayload($slot);
            $slot['slot_id'] = (string)($slot['slot_id'] ?? $slotId);
            unset(
                $slot['group_id'],
                $slot['group_name'],
                $slot['group_order'],
                $slot['variant_label'],
                $slot['variant_order']
            );
            if (isset($allAssigned[$slotId])) {
                $slot['enabled'] = true;
                $slot['primary_scene_set'] = in_array($slotId, $boardOneSlots, true);
                if ($slot['primary_scene_set']) {
                    $slot['board_order'] = array_search($slotId, $boardOneSlots, true) + 1;
                } else {
                    unset($slot['board_order']);
                }
            } else {
                $slot['enabled'] = false;
                $slot['primary_scene_set'] = false;
                unset($slot['board_order']);
            }
            $custom['slots'][$slotId] = $this->publishedSlotPayload($slot);
        }

        $this->writeCustomConfig($custom);

        return [
            'assigned_count' => $totalAssigned,
            'path' => $this->customConfigPath(),
        ];
    }

    public function scenePromptForEdit(array $slot): string
    {
        $prompt = trim((string)($slot['full_prompt_template'] ?? ''));
        return $prompt !== '' ? $prompt : $this->buildPromptTemplate($slot);
    }

    /**
     * @return array<string,mixed>
     */
    public function slotForEdit(string $slotId): array
    {
        $slotId = trim($slotId);
        $slots = $this->existingSlots();
        if ($slotId === '' || !isset($slots[$slotId]) || !is_array($slots[$slotId])) {
            throw new RuntimeException('La cámara seleccionada no existe.');
        }
        $slot = $this->publishedSlotPayload($slots[$slotId]);
        $slot['slot_id'] = (string)($slot['slot_id'] ?? $slotId);
        return $slot;
    }

    /**
     * @return array<string,mixed>
     */
    public function saveSlotFromForm(array $input): array
    {
        $slotId = $this->safeSlug((string)($input['slot_id'] ?? ''));
        if ($slotId === '') {
            throw new RuntimeException('El ID de cámara es obligatorio.');
        }

        $slot = [
            'slot_id' => $slotId,
            'slot_name' => trim((string)($input['slot_name'] ?? '')),
            'enabled' => !empty($input['enabled']),
            'fidelity_mode' => trim((string)($input['fidelity_mode'] ?? 'world_mother_camera_adaptation')),
            'size_classes_supported' => $this->stringList($input['size_classes_supported'] ?? ''),
            'orientation_supported' => $this->stringList($input['orientation_supported'] ?? ''),
            'camera_height_block' => trim((string)($input['camera_height_block'] ?? '')),
            'lens_block' => trim((string)($input['lens_block'] ?? '')),
            'vertical_tilt_block' => trim((string)($input['vertical_tilt_block'] ?? '')),
            'lateral_rotation_block' => trim((string)($input['lateral_rotation_block'] ?? '')),
            'composition_block' => trim((string)($input['composition_block'] ?? '')),
            'human_subject_block' => trim((string)($input['human_subject_block'] ?? '')),
            'scale_block' => trim((string)($input['scale_block'] ?? '')),
            'depth_of_field_block' => trim((string)($input['depth_of_field_block'] ?? '')),
            'scene_affinity' => $this->stringList($input['scene_affinity'] ?? ''),
            'negative_directives' => $this->stringList($input['negative_directives'] ?? ''),
            'full_prompt_template' => trim((string)($input['full_prompt_template'] ?? '')),
        ];
        $existingSlot = $this->existingSlots()[$slotId] ?? [];
        foreach (['primary_scene_set', 'board_order', 'group_id', 'group_name', 'group_order', 'variant_label', 'variant_order'] as $boardKey) {
            if (array_key_exists($boardKey, $input)) {
                if ($boardKey === 'primary_scene_set') {
                    $slot[$boardKey] = !empty($input[$boardKey]);
                } elseif (in_array($boardKey, ['board_order', 'group_order', 'variant_order'], true)) {
                    $slot[$boardKey] = (int)$input[$boardKey];
                } else {
                    $slot[$boardKey] = trim((string)$input[$boardKey]);
                }
            } elseif (array_key_exists($boardKey, $existingSlot)) {
                $slot[$boardKey] = $existingSlot[$boardKey];
            }
        }
        if ($slot['slot_name'] === '') {
            $slot['slot_name'] = ucwords(str_replace('_', ' ', $slotId));
        }
        if ($slot['full_prompt_template'] === '') {
            $slot['full_prompt_template'] = $this->buildPromptTemplate($slot);
        }

        $setIds = $this->stringList($input['set_ids'] ?? []);
        $custom = $this->customCameraConfig();
        $custom['slots'][$slotId] = $this->publishedSlotPayload($slot);
        $allSets = (array)($this->cameraConfig()['sets'] ?? []);
        foreach ($allSets as $setId => $set) {
            if (!is_string($setId)) {
                continue;
            }
            $custom['sets'][$setId] = is_array($custom['sets'][$setId] ?? null)
                ? $custom['sets'][$setId]
                : [
                    'set_name' => (string)($set['set_name'] ?? ucwords(str_replace('_', ' ', $setId))),
                    'slots' => [],
                ];
            $slots = array_values(array_filter(array_map('strval', (array)($custom['sets'][$setId]['slots'] ?? [])), static fn (string $item): bool => $item !== $slotId));
            if (in_array($setId, $setIds, true)) {
                $slots[] = $slotId;
            }
            $custom['sets'][$setId]['slots'] = array_values(array_unique($slots));
        }

        $this->writeCustomConfig($custom);

        return [
            'slot_id' => $slotId,
            'path' => $this->customConfigPath(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function saveSceneQuick(array $input): array
    {
        $slotId = $this->safeSlug((string)($input['slot_id'] ?? ''));
        if ($slotId === '') {
            throw new RuntimeException('La Scene seleccionada no existe.');
        }

        $slot = $this->slotForEdit($slotId);
        $slotName = trim((string)($input['slot_name'] ?? ''));
        $slot['slot_name'] = $slotName !== '' ? $slotName : (string)($slot['slot_name'] ?? ucwords(str_replace('_', ' ', $slotId)));
        if (array_key_exists('enabled', $input)) {
            $slot['enabled'] = !empty($input['enabled']);
        }

        $prompt = trim((string)($input['full_prompt_template'] ?? ''));
        $slot['full_prompt_template'] = $prompt !== '' ? $prompt : $this->buildPromptTemplate($slot);

        $custom = $this->customCameraConfig();
        $custom['slots'][$slotId] = $this->publishedSlotPayload($slot);
        $this->writeCustomConfig($custom);

        return [
            'slot_id' => $slotId,
            'path' => $this->customConfigPath(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function disableSlot(string $slotId): array
    {
        $slot = $this->slotForEdit($slotId);
        $slot['enabled'] = false;
        $slot['primary_scene_set'] = false;
        unset($slot['board_order']);
        $custom = $this->customCameraConfig();
        $custom['slots'][(string)$slot['slot_id']] = $this->publishedSlotPayload($slot);
        $custom['scene_board']['slots'] = array_values(array_filter(
            array_map('strval', (array)($custom['scene_board']['slots'] ?? [])),
            static fn (string $item): bool => $item !== (string)$slot['slot_id']
        ));
        $this->writeCustomConfig($custom);
        return ['slot_id' => (string)$slot['slot_id'], 'mode' => 'disabled'];
    }

    /**
     * @return array<string,mixed>
     */
    public function setSlotEnabled(string $slotId, bool $enabled): array
    {
        $slot = $this->slotForEdit($slotId);
        $slot['enabled'] = $enabled;
        $custom = $this->customCameraConfig();
        $custom['slots'][(string)$slot['slot_id']] = $this->publishedSlotPayload($slot);
        $this->writeCustomConfig($custom);
        return ['slot_id' => (string)$slot['slot_id'], 'enabled' => $enabled];
    }

    /**
     * @return array<string,mixed>
     */
    public function deleteSlot(string $slotId): array
    {
        $slotId = trim($slotId);
        $custom = $this->customCameraConfig();
        $baseSlots = $this->baseSlots();
        if (isset($baseSlots[$slotId])) {
            return $this->disableSlot($slotId);
        }
        unset($custom['slots'][$slotId]);
        $custom['scene_board']['slots'] = array_values(array_filter(
            array_map('strval', (array)($custom['scene_board']['slots'] ?? [])),
            static fn (string $item): bool => $item !== $slotId
        ));
        foreach ((array)($custom['sets'] ?? []) as $setId => $set) {
            if (!is_string($setId) || !is_array($set)) {
                continue;
            }
            $custom['sets'][$setId]['slots'] = array_values(array_filter(
                array_map('strval', (array)($set['slots'] ?? [])),
                static fn (string $item): bool => $item !== $slotId
            ));
        }
        $this->writeCustomConfig($custom);
        return ['slot_id' => $slotId, 'mode' => 'deleted'];
    }

    /**
     * @return array<string,int>
     */
    public function purgeInactiveSlots(): array
    {
        $custom = $this->customCameraConfig();
        $baseSlots = $this->baseSlots();
        $slots = $this->existingSlots();
        $removedCustom = 0;
        $hiddenBase = 0;

        foreach ($slots as $slotId => $slot) {
            if (!is_array($slot) || !empty($slot['enabled'])) {
                continue;
            }

            $custom['scene_board']['slots'] = array_values(array_filter(
                array_map('strval', (array)($custom['scene_board']['slots'] ?? [])),
                static fn (string $item): bool => $item !== (string)$slotId
            ));

            if (isset($baseSlots[$slotId])) {
                $slot = $this->publishedSlotPayload($slot);
                $slot['enabled'] = false;
                $slot['deleted_from_studio'] = true;
                $custom['slots'][$slotId] = $slot;
                $hiddenBase++;
            } else {
                unset($custom['slots'][$slotId]);
                $removedCustom++;
            }

            foreach ((array)($custom['sets'] ?? []) as $setId => $set) {
                if (!is_string($setId) || !is_array($set)) {
                    continue;
                }
                $custom['sets'][$setId]['slots'] = array_values(array_filter(
                    array_map('strval', (array)($set['slots'] ?? [])),
                    static fn (string $item): bool => $item !== $slotId
                ));
            }
        }

        $this->writeCustomConfig($custom);

        return [
            'removed_custom' => $removedCustom,
            'hidden_base' => $hiddenBase,
            'total' => $removedCustom + $hiddenBase,
        ];
    }

    public function quickTestPrompt(string $slotId, int $artworkId = 0): string
    {
        $slot = $this->slotForEdit($slotId);
        $artworkId = $artworkId > 0 ? $artworkId : $this->fallbackArtworkId();
        $proposal = [
            'artwork_id' => $artworkId,
            'context_name' => 'Prueba rápida de cámara',
            'context_json' => json_encode([
                'direct_world_mother_mode' => true,
                'world_mother_category' => 'camera_admin_test',
                'world_mother_reference_image' => 'world-mothers/test.jpg',
                'camera_slot_id' => (string)$slot['slot_id'],
                'camera_slot_name' => (string)$slot['slot_name'],
                'camera_slot_geometry' => $this->cameraGeometry($slot),
                'space_type' => 'interior de prueba',
                'materials' => 'materiales neutros de prueba',
                'lighting' => 'luz natural suave de prueba',
                'placement' => 'ubicación definida por la cámara',
                'mockup_prompt' => 'Prueba rápida sin generación de imagen.',
                'negative_prompt' => implode(', ', (array)($slot['negative_directives'] ?? [])),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        return (new AdminPromptComposerPreview())->compose($proposal);
    }

    /**
     * @return array<string,mixed>
     */
    public function generateQuickTestImage(string $slotId, int $artworkId = 0): array
    {
        $slot = $this->slotForEdit($slotId);
        $artwork = $this->fallbackArtwork($artworkId);
        $worldMother = $this->fallbackWorldMother();
        $prompt = $this->quickTestPrompt($slotId, (int)$artwork['id']);

        $result = (new GeminiMockupGenerator())->generate(
            (string)$artwork['root_path'],
            'camera_admin_test_' . $slotId,
            $prompt,
            [
                'slot_full_prompt_mode' => true,
                'skip_world_visual_enhancer' => true,
                'force_disable_precomposition' => true,
                'world_mother_reference_path' => (string)($worldMother['absolute_path'] ?? ''),
                'mockup_combination' => [
                    'selected_camera_slot_id' => (string)$slot['slot_id'],
                ],
                'seo_params' => [
                    'artworkTitle' => 'camera-test',
                    'contextTitle' => (string)$slot['slot_name'],
                    'cameraAngle' => (string)$slot['slot_id'],
                ],
            ]
        );
        $result['artwork_id'] = (int)$artwork['id'];
        $result['world_mother_path'] = (string)($worldMother['absolute_path'] ?? '');
        $result['prompt'] = $prompt;
        return $result;
    }

    /**
     * @param array<string,mixed> $slot
     */
    public function exportPhpArray(array $slot): string
    {
        $slot = $this->publishedSlotPayload($slot);
        $slotId = (string)($slot['slot_id'] ?? 'new_camera_slot');
        return "'" . addslashes($slotId) . "' => " . var_export($slot, true) . ",";
    }

    /**
     * @param array<string,mixed> $slot
     * @return array<string,mixed>
     */
    public function publishSlot(array $slot, string $setId): array
    {
        $slot = $this->publishedSlotPayload($slot);
        $slotId = (string)($slot['slot_id'] ?? '');
        $setId = trim($setId);
        if ($slotId === '') {
            throw new RuntimeException('El borrador no tiene slot_id.');
        }
        if ($setId === '') {
            throw new RuntimeException('Elegí un set donde publicar la cámara.');
        }

        $config = $this->cameraConfig();
        if (!isset($config['sets'][$setId])) {
            throw new RuntimeException('El set seleccionado no existe.');
        }

        $customPath = $this->customConfigPath();
        $custom = $this->customCameraConfig();

        $custom['slots'][$slotId] = $slot;
        if (!isset($custom['sets'][$setId]) || !is_array($custom['sets'][$setId])) {
            $custom['sets'][$setId] = [
                'set_name' => (string)($config['sets'][$setId]['set_name'] ?? ucwords(str_replace('_', ' ', $setId))),
                'slots' => [],
            ];
        }
        $setSlots = array_values(array_unique(array_merge(
            array_map('strval', (array)($custom['sets'][$setId]['slots'] ?? [])),
            [$slotId]
        )));
        $custom['sets'][$setId]['slots'] = $setSlots;

        $this->writeCustomConfig($custom);

        return [
            'slot_id' => $slotId,
            'set_id' => $setId,
            'path' => $customPath,
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function draftWithGemini(string $brief, array $metadata): array
    {
        $knownSlots = implode(', ', array_keys($this->existingSlots()));
        $style = trim((string)($metadata['style'] ?? ''));
        $riskNotes = trim((string)($metadata['risk_notes'] ?? ''));
        $prompt = "Crear un borrador de slot de cámara reutilizable para un motor premium de mockups de obra.\n"
            . "Responder solamente JSON estricto. Claves: slot_id, slot_name, fidelity_mode, size_classes_supported, orientation_supported, camera_height_block, lens_block, vertical_tilt_block, lateral_rotation_block, composition_block, human_subject_block, scale_block, depth_of_field_block, scene_affinity, negative_directives, full_prompt_template, reviewer_notes.\n"
            . "Todos los textos administrativos y prompts deben estar en español. El slot debe proteger IMAGE 1 como obra raíz y puede usar IMAGE 2 solo como ADN ambiental/world mother cuando corresponda.\n"
            . "Usar estos placeholders: {{CAMERA_SLOT_ID}}, {{CAMERA_SLOT_NAME}}, {{ARTWORK_TITLE}}, {{ARTWORK_WIDTH_CM}}, {{ARTWORK_HEIGHT_CM}}, {{ARTWORK_ORIENTATION}}, {{ARTWORK_SIZE_CLASS}}.\n"
            . "No crear un prompt terminado para una escena única; crear una cámara reusable.\n"
            . "IDs de slots existentes a evitar: {$knownSlots}\n"
            . "Idea de cámara: {$brief}\n"
            . "Familia/estilo deseado: {$style}\n"
            . "Riesgos a bloquear: {$riskNotes}";

        $text = $this->client->generateText([
            $this->client->textPart($prompt),
        ], 'gemini-2.5-flash');

        $decoded = json_decode($this->extractJson($text), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Camera Studio did not return valid JSON.');
        }
        return $decoded;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function fallbackDraft(string $brief, array $metadata): array
    {
        $style = trim((string)($metadata['style'] ?? ''));
        $riskNotes = trim((string)($metadata['risk_notes'] ?? ''));
        $basis = $style !== '' ? $style : $brief;
        $slotId = $this->safeSlug($basis);
        if ($slotId === '') {
            $slotId = 'custom_camera_slot';
        }
        $slotId = substr($slotId, 0, 56);
        $slotName = ucwords(str_replace('_', ' ', $slotId));

        return [
            'slot_id' => $slotId,
            'slot_name' => $slotName,
            'fidelity_mode' => 'adaptacion_camara_world_mother',
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'La altura de cámara sigue la idea solicitada y se mantiene físicamente plausible para un mockup de obra.',
            'lens_block' => 'Usar una lente fotográfica natural con perspectiva controlada. Evitar distorsión extrema salvo que la idea de cámara lo requiera explícitamente.',
            'vertical_tilt_block' => 'Usar solo la inclinación vertical necesaria para expresar la cámara sin romper geometría de pared, canvas, piso o techo.',
            'lateral_rotation_block' => 'Usar un ángulo lateral deliberado que apoye la cámara sin copiar la cámara de la world mother.',
            'composition_block' => $brief,
            'human_subject_block' => '',
            'scale_block' => 'Respetar las dimensiones provistas de la obra y mantenerla físicamente plausible. IMAGE 1 manda: preservar identidad exacta, proporción, colores, marcas, composición, rostros si existen, relaciones de figura si existen y estructura visual interna. No repintar, simplificar, embellecer, estirar, comprimir, reemplazar ni reinterpretar la superficie.',
            'depth_of_field_block' => 'Mantener nítida la cara de la obra, sus bordes y el contacto inmediato con pared, piso o soporte. Usar profundidad de campo óptica solo si ayuda a la idea de cámara.',
            'scene_affinity' => array_values(array_filter([$slotId, 'custom_camera', 'artwork_mockup'])),
            'negative_directives' => array_values(array_filter(array_map('trim', explode(',', $riskNotes)))) ?: [
                'sin sustitución de obra',
                'sin pintura inventada',
                'sin canvas deformado',
                'sin escala de billboard',
                'sin texto visible',
                'sin logos',
                'sin marcas de agua',
            ],
            'reviewer_notes' => 'Borrador local. Revisar el lenguaje y agregar restricciones duras específicas de cámara antes de publicar.',
        ];
    }

    /**
     * @param array<string,mixed> $draft
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function normalizeDraft(array $draft, string $brief, array $metadata): array
    {
        $slotId = $this->safeSlug((string)($draft['slot_id'] ?? $metadata['style'] ?? $brief));
        if ($slotId === '') {
            $slotId = 'custom_camera_slot';
        }

        $slot = [
            'slot_id' => $slotId,
            'slot_name' => trim((string)($draft['slot_name'] ?? ucwords(str_replace('_', ' ', $slotId)))),
            'enabled' => true,
            'fidelity_mode' => trim((string)($draft['fidelity_mode'] ?? 'world_mother_camera_adaptation')),
            'size_classes_supported' => $this->stringList($draft['size_classes_supported'] ?? ['small', 'medium', 'large', 'xl_or_oversize', 'unknown']),
            'orientation_supported' => $this->stringList($draft['orientation_supported'] ?? ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown']),
            'camera_height_block' => trim((string)($draft['camera_height_block'] ?? '')),
            'lens_block' => trim((string)($draft['lens_block'] ?? '')),
            'vertical_tilt_block' => trim((string)($draft['vertical_tilt_block'] ?? '')),
            'lateral_rotation_block' => trim((string)($draft['lateral_rotation_block'] ?? '')),
            'composition_block' => trim((string)($draft['composition_block'] ?? $brief)),
            'human_subject_block' => trim((string)($draft['human_subject_block'] ?? '')),
            'scale_block' => trim((string)($draft['scale_block'] ?? '')),
            'depth_of_field_block' => trim((string)($draft['depth_of_field_block'] ?? '')),
            'scene_affinity' => $this->stringList($draft['scene_affinity'] ?? []),
            'negative_directives' => $this->stringList($draft['negative_directives'] ?? []),
            'full_prompt_template' => trim((string)($draft['full_prompt_template'] ?? '')),
        ];

        if ($slot['full_prompt_template'] === '') {
            $slot['full_prompt_template'] = $this->buildPromptTemplate($slot);
        }

        $slot['_studio'] = [
            'schema' => 'camera_slot_studio_draft.v1',
            'created_at' => date(DATE_ATOM),
            'source' => ProviderSettings::isRealMode() && ProviderSettings::imageProvider() === 'gemini' ? 'gemini' : 'local_fallback',
            'brief' => $brief,
            'reviewer_notes' => trim((string)($draft['reviewer_notes'] ?? '')),
        ];

        return $slot;
    }

    /**
     * @param array<string,mixed> $slot
     * @return array<string,mixed>
     */
    private function publishedSlotPayload(array $slot): array
    {
        foreach (array_keys($slot) as $key) {
            if (str_starts_with((string)$key, '_')) {
                unset($slot[$key]);
            }
        }
        $slot['size_classes_supported'] = $this->normalizeSupportedList(
            $slot['size_classes_supported'] ?? [],
            ['small', 'medium', 'large', 'xl_or_oversize', 'unknown']
        );
        $slot['orientation_supported'] = $this->normalizeSupportedList(
            $slot['orientation_supported'] ?? [],
            ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown']
        );
        if (is_array($slot['human_subject_block'] ?? null) || (string)($slot['human_subject_block'] ?? '') === 'Array') {
            $slot['human_subject_block'] = '';
        }
        return $slot;
    }

    /**
     * @param mixed $value
     * @param array<int,string> $fallback
     * @return array<int,string>
     */
    private function normalizeSupportedList($value, array $fallback): array
    {
        $items = $this->stringList($value);
        $lower = array_map('strtolower', $items);
        if (!$items || array_intersect($lower, ['all', 'any', '*'])) {
            return $fallback;
        }
        return $items;
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function stringList($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn ($item): string => trim((string)$item), $value)));
        }
        return array_values(array_filter(array_map('trim', explode(',', (string)$value))));
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function buildPromptTemplate(array $slot): string
    {
        $negative = implode(', ', (array)($slot['negative_directives'] ?? []));
        return "Generar un mockup fotográfico premium usando únicamente este slot de cámara:\n"
            . "ID de cámara: {{CAMERA_SLOT_ID}}\n"
            . "Nombre de cámara: {{CAMERA_SLOT_NAME}}\n\n"
            . "ROLES DE IMAGEN:\n"
            . "IMAGE 1 es la única fuente de verdad para la obra. Preservar identidad exacta, orientación, proporción, colores, marcas, zonas vacías y composición.\n"
            . "IMAGE 2 es la world mother ambiental cuando exista. Tomar materialidad, luz y clima espacial, pero no copiar su cámara, layout, ubicación de muebles ni contenido artístico.\n\n"
            . "DATOS FÍSICOS DE LA OBRA:\n"
            . "Título: {{ARTWORK_TITLE}}\n"
            . "Ancho: {{ARTWORK_WIDTH_CM}} cm\n"
            . "Alto: {{ARTWORK_HEIGHT_CM}} cm\n"
            . "Orientación: {{ARTWORK_ORIENTATION}}\n"
            . "Clase de tamaño: {{ARTWORK_SIZE_CLASS}}\n\n"
            . "CÁMARA:\n"
            . (string)$slot['camera_height_block'] . "\n"
            . (string)$slot['lens_block'] . "\n"
            . (string)$slot['vertical_tilt_block'] . "\n"
            . (string)$slot['lateral_rotation_block'] . "\n\n"
            . "COMPOSICIÓN:\n"
            . (string)$slot['composition_block'] . "\n\n"
            . "ESCALA E INTEGRIDAD DE OBRA:\n"
            . (string)$slot['scale_block'] . "\n\n"
            . "FOCO:\n"
            . (string)$slot['depth_of_field_block'] . "\n\n"
            . "PROMPT NEGATIVO:\n"
            . $negative;
    }

    private function customConfigPath(): string
    {
        return dirname(__DIR__) . '/Config/mockup_camera_slots_custom.php';
    }

    /**
     * @param array<string,mixed> $custom
     */
    private function writeCustomConfig(array $custom): void
    {
        $custom['sets'] = is_array($custom['sets'] ?? null) ? $custom['sets'] : [];
        $custom['slots'] = is_array($custom['slots'] ?? null) ? $custom['slots'] : [];
        $custom['scene_board'] = is_array($custom['scene_board'] ?? null) ? $custom['scene_board'] : [];
        $custom['scene_boards'] = is_array($custom['scene_boards'] ?? null) ? $custom['scene_boards'] : [];
        $contents = "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "return " . var_export($custom, true) . ";\n";
        $contents = preg_replace('/[ \t]+$/m', '', $contents) ?? $contents;

        if (file_put_contents($this->customConfigPath(), $contents) === false) {
            throw new RuntimeException('No se pudo guardar la configuración custom de cámaras.');
        }
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function cameraGeometry(array $slot): string
    {
        $parts = [];
        foreach ([
            'camera_height_block',
            'lens_block',
            'vertical_tilt_block',
            'lateral_rotation_block',
            'composition_block',
            'human_subject_block',
            'scale_block',
            'depth_of_field_block',
        ] as $key) {
            $value = trim((string)($slot[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $key . ': ' . $value;
            }
        }
        return implode("\n", $parts);
    }

    /**
     * @return array<string,mixed>
     */
    private function fallbackArtwork(int $artworkId = 0): array
    {
        $pdo = Database::connection();
        if ($artworkId > 0) {
            $stmt = $pdo->prepare('SELECT id, root_file, main_file FROM artworks WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $artworkId]);
            $row = $stmt->fetch();
            if ($row) {
                $path = $this->rootArtworkPath((string)($row['root_file'] ?? ''));
                if ($path === '') {
                    $path = $this->rootArtworkPath((string)($row['main_file'] ?? ''));
                }
                if ($path !== '') {
                    return ['id' => (int)$row['id'], 'root_path' => $path];
                }
            }
        }

        $stmt = $pdo->query('SELECT id, root_file, main_file FROM artworks ORDER BY id DESC LIMIT 100');
        foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
            $path = $this->rootArtworkPath((string)($row['root_file'] ?? ''));
            if ($path === '') {
                $path = $this->rootArtworkPath((string)($row['main_file'] ?? ''));
            }
            if ($path !== '') {
                return ['id' => (int)$row['id'], 'root_path' => $path];
            }
        }

        throw new RuntimeException('No encontré una obra raíz existente para hacer el test de imagen.');
    }

    private function fallbackArtworkId(): int
    {
        return (int)$this->fallbackArtwork(0)['id'];
    }

    private function rootArtworkPath(string $rootFile): string
    {
        $rootFile = trim($rootFile);
        if ($rootFile === '') {
            return '';
        }
        $candidates = [
            $rootFile,
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $rootFile,
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($rootFile),
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $rootFile,
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'roots' . DIRECTORY_SEPARATOR . basename($rootFile),
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'roots' . DIRECTORY_SEPARATOR . basename($rootFile),
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'root_images' . DIRECTORY_SEPARATOR . basename($rootFile),
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'artworks' . DIRECTORY_SEPARATOR . basename($rootFile),
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'results' . DIRECTORY_SEPARATOR . basename($rootFile),
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function fallbackWorldMother(): array
    {
        $library = new WorldMotherLibrary();
        foreach ($library->allImages() as $image) {
            if (is_array($image) && is_file((string)($image['absolute_path'] ?? ''))) {
                return $image;
            }
        }
        return ['absolute_path' => ''];
    }

    private function safeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: '';
        $value = trim($value, '_');
        return substr($value, 0, 80);
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
}
