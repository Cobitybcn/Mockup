<?php
declare(strict_types=1);

class MockupContextWorldRegistry
{
    private const CURATORIAL_DEFAULT_WORLD_ROTATION = [
        'minimal_contemporary_world',
        'domestic_collector_world',
        'premium_domestic_lifestyle_world',
        'historical_european_world',
        'artist_studio_world',
        'period_design_world',
    ];

    private string $rootPath;
    private array $worlds = [];
    private array $families = [];
    private array $variants = [];
    private array $compatibilityRules = [];
    private array $validation = ['ok' => true, 'errors' => [], 'warnings' => []];

    public function __construct(?string $rootPath = null)
    {
        $this->rootPath = $rootPath ?: dirname(__DIR__, 2);
        $this->load();
        $this->validation = $this->validate();
    }

    public function allWorlds(): array
    {
        return $this->worlds;
    }

    public function allFamilies(): array
    {
        return $this->families;
    }

    public function allVariants(): array
    {
        return $this->variants;
    }

    public function compatibilityRules(): array
    {
        return $this->compatibilityRules;
    }

    public function validation(): array
    {
        return $this->validation;
    }

    public function stableDefaultWorlds(): array
    {
        return array_filter($this->worlds, static function (array $world): bool {
            return ($world['status'] ?? '') === 'stable' && !empty($world['default_rotation']);
        });
    }

    public function stableDefaultFamilies(?string $worldId = null): array
    {
        $stableWorlds = array_keys($this->stableDefaultWorlds());

        return array_filter($this->families, static function (array $family) use ($worldId, $stableWorlds): bool {
            $familyWorldId = (string)($family['world_id'] ?? '');
            if ($worldId !== null && $familyWorldId !== $worldId) {
                return false;
            }

            return in_array($familyWorldId, $stableWorlds, true)
                && ($family['status'] ?? '') === 'stable'
                && !empty($family['default_rotation']);
        });
    }

    public function stableDefaultVariants(?string $familyId = null): array
    {
        $stableFamilies = array_keys($this->stableDefaultFamilies());

        return array_filter($this->variants, static function (array $variant) use ($familyId, $stableFamilies): bool {
            $variantFamilyId = (string)($variant['family_id'] ?? '');
            if ($familyId !== null && $variantFamilyId !== $familyId) {
                return false;
            }

            return in_array($variantFamilyId, $stableFamilies, true)
                && ($variant['status'] ?? '') === 'stable'
                && !empty($variant['default_rotation']);
        });
    }

    public function stableDefaultCombinations(): array
    {
        $combinations = [];
        foreach ($this->stableDefaultVariants() as $variantId => $variant) {
            $familyId = (string)($variant['family_id'] ?? '');
            $family = $this->families[$familyId] ?? null;
            if (!is_array($family)) {
                continue;
            }

            $worldId = (string)($family['world_id'] ?? '');
            $world = $this->worlds[$worldId] ?? null;
            if (!is_array($world)) {
                continue;
            }

            $combinations[] = [
                'world_id' => $worldId,
                'family_id' => $familyId,
                'variant_id' => (string)$variantId,
                'world' => $world,
                'family' => $family,
                'variant' => $variant,
            ];
        }

        return $combinations;
    }

    public function metadataForDefaultIndex(int $index, ?string $cameraSlotId = null): array
    {
        return $this->metadataForCuratorialProposalIndex($index, $cameraSlotId);
    }

    public function metadataForCuratorialProposalIndex(int $index, ?string $cameraSlotId = null): array
    {
        return $this->metadataForCuratorialProposalIndexInternal($index, $cameraSlotId, true);
    }

    public function metadataForCuratorialProposalIndexExplicit(int $index, ?string $cameraSlotId = null): array
    {
        return $this->metadataForCuratorialProposalIndexInternal($index, $cameraSlotId, false);
    }

    public function compatibleStableDefaultAlternativeExists(?string $worldId, ?string $cameraSlotId): bool
    {
        $worldId = trim((string)$worldId);
        if ($worldId === '') {
            return false;
        }

        return is_array($this->compatibleStableDefaultCombinationForWorld($worldId, $cameraSlotId));
    }

    private function metadataForCuratorialProposalIndexInternal(int $index, ?string $cameraSlotId = null, bool $enforceCompatibility = true): array
    {
        $worldId = self::CURATORIAL_DEFAULT_WORLD_ROTATION[$index % count(self::CURATORIAL_DEFAULT_WORLD_ROTATION)] ?? null;
        if (is_string($worldId)) {
            $combination = $this->firstStableDefaultCombinationForWorld($worldId);
            if (is_array($combination)) {
                $metadata = $this->metadataForCombination(
                    $combination['world_id'],
                    $combination['family_id'],
                    $combination['variant_id'],
                    $cameraSlotId
                );
                if (!$enforceCompatibility || !$this->shouldReplaceForNormalSelection($metadata, $cameraSlotId)) {
                    return $metadata;
                }

                // Contract: avoid/forbidden/unspecified cannot enter normal active layer.
                // Preserve the original world first by selecting another stable/default family+variant.
                $sameWorldFallback = $this->compatibleStableDefaultCombinationForWorld($worldId, $cameraSlotId, $combination);
                if (is_array($sameWorldFallback)) {
                    $sameWorldMetadata = $this->metadataForCombination(
                        $sameWorldFallback['world_id'],
                        $sameWorldFallback['family_id'],
                        $sameWorldFallback['variant_id'],
                        $cameraSlotId
                    );
                    return $this->withFamilyReplacementTrace($sameWorldMetadata, $metadata, 'same_world_compatible_family_variant_selected');
                }

                // Contract: world replacement is the last resort after same-world options fail.
                $fallback = $this->fallbackCompatibleStableDefaultCombination($worldId, $cameraSlotId);
                if (is_array($fallback)) {
                    $fallbackMetadata = $this->metadataForCombination(
                        $fallback['world_id'],
                        $fallback['family_id'],
                        $fallback['variant_id'],
                        $cameraSlotId
                    );
                    return $this->withReplacementTrace($fallbackMetadata, $metadata, 'original_world_not_selectable_for_camera');
                }

                return $metadata;
            }
        }

        $fallback = $this->fallbackCompatibleStableDefaultCombination($worldId, $cameraSlotId)
            ?: $this->fallbackStableDefaultCombination($worldId, $cameraSlotId);
        if (is_array($fallback)) {
            return $this->metadataForCombination(
                $fallback['world_id'],
                $fallback['family_id'],
                $fallback['variant_id'],
                $cameraSlotId
            );
        }

        return $this->safeMetadata('no_stable_default_combination_for_curatorial_index');
    }

    public function curatorialDefaultWorldRotation(): array
    {
        return self::CURATORIAL_DEFAULT_WORLD_ROTATION;
    }

    public function curatorialDefaultDistribution(int $count = 6): array
    {
        $distribution = [];
        for ($index = 0; $index < $count; $index++) {
            $distribution[$index] = $this->metadataForCuratorialProposalIndex($index, null);
        }

        return $distribution;
    }

    /**
     * @deprecated Use metadataForCuratorialProposalIndex().
     */
    public function metadataForLegacySequentialDefaultIndex(int $index, ?string $cameraSlotId = null): array
    {
        $combinations = $this->stableDefaultCombinations();
        if ($combinations === []) {
            return $this->safeMetadata('no_stable_default_combination');
        }

        $selected = $combinations[$index % count($combinations)];
        return $this->metadataForCombination(
            $selected['world_id'],
            $selected['family_id'],
            $selected['variant_id'],
            $cameraSlotId
        );
    }

    public function metadataForCombination(?string $worldId, ?string $familyId, ?string $variantId, ?string $cameraSlotId = null): array
    {
        $worldId = trim((string)$worldId);
        $familyId = trim((string)$familyId);
        $variantId = trim((string)$variantId);

        if ($worldId === '' || $familyId === '' || $variantId === '') {
            return $this->safeMetadata('missing_world_family_or_variant');
        }

        $world = $this->worlds[$worldId] ?? null;
        $family = $this->families[$familyId] ?? null;
        $variant = $this->variants[$variantId] ?? null;

        if (!is_array($world) || !is_array($family) || !is_array($variant)) {
            return $this->safeMetadata('unknown_world_family_or_variant');
        }

        if (($family['world_id'] ?? '') !== $worldId || ($variant['family_id'] ?? '') !== $familyId) {
            return $this->safeMetadata('invalid_world_family_variant_relationship');
        }

        $defaultRotation = !empty($world['default_rotation'])
            && !empty($family['default_rotation'])
            && !empty($variant['default_rotation']);
        $riskControls = array_values(array_unique(array_merge(
            array_map('strval', (array)($world['default_risk_controls'] ?? [])),
            array_map('strval', (array)($family['risk_controls'] ?? [])),
            array_map('strval', (array)($variant['negative_directives'] ?? [])),
            $this->minimumArtworkControls()
        )));
        $negativeControls = array_values(array_unique(array_merge(
            array_map('strval', (array)($family['negative_context_terms'] ?? [])),
            array_map('strval', (array)($variant['negative_directives'] ?? [])),
            array_map('strval', (array)($world['default_risk_controls'] ?? [])),
            $this->minimumArtworkControls()
        )));
        $presencePolicy = [
            'world' => $world['allowed_presence'] ?? [],
            'family' => $family['presence_policy'] ?? [],
        ];
        $compatibilityDecision = $this->compatibilityDecision($worldId, $cameraSlotId, $familyId, $variantId);
        $compatibilityReason = trim((string)($compatibilityDecision['reason'] ?? ''));

        return [
            'context_world_id' => $worldId,
            'context_family_id' => $familyId,
            'scene_variant_id' => $variantId,
            'world_status' => (string)($world['status'] ?? ''),
            'family_status' => (string)($family['status'] ?? ''),
            'variant_status' => (string)($variant['status'] ?? ''),
            'default_rotation' => $defaultRotation,
            'context_tags' => array_values(array_unique(array_merge(
                array_map('strval', (array)($world['tags'] ?? [])),
                array_map('strval', (array)($family['tags'] ?? []))
            ))),
            'presence_policy' => $presencePolicy,
            'risk_controls' => $riskControls,
            'placement_mode' => (string)($variant['placement_mode'] ?? ''),
            'compatibility_status' => (string)($compatibilityDecision['status'] ?? ''),
            'compatibility_decision' => $compatibilityDecision,
            'decision_reasons' => $compatibilityReason !== '' ? [$compatibilityReason] : [],
            'replacement_applied' => false,
            'world_preserved' => true,
            'family_replacement_applied' => false,
            'distribution_broken' => false,
            'replacement_reason' => '',
            'original_world_id' => $worldId,
            'selected_world_id' => $worldId,
            'original_family_id' => $familyId,
            'selected_family_id' => $familyId,
            'original_variant_id' => $variantId,
            'selected_variant_id' => $variantId,
            'context_world_directive' => $this->composeWorldDirective($world),
            'context_family_directive' => $this->composeFamilyDirective($family),
            'scene_variant_directive' => $this->composeVariantDirective($variant),
            'world_visual_contract' => $this->worldVisualContractForSelection($worldId, $familyId, $variantId),
            'world_architecture_language' => (string)($family['architecture_language'] ?? ''),
            'world_wall_language' => (string)($family['wall_language'] ?? ''),
            'world_floor_language' => (string)($family['floor_language'] ?? ''),
            'world_ceiling_language' => (string)($family['ceiling_language'] ?? ''),
            'world_lighting_bias' => (string)($family['lighting_bias'] ?? ''),
            'world_material_temperature' => (string)($family['material_temperature'] ?? ''),
            'world_scale_behavior' => (string)($family['scale_behavior'] ?? ''),
            'world_negative_context_controls' => $negativeControls,
            'world_risk_controls' => $riskControls,
            'world_presence_policy' => $presencePolicy,
        ];
    }

    public function worldVisualContractForContextJson(array $contextJson): array
    {
        $worldId = (string)($contextJson['selected_world_id'] ?? $contextJson['context_world_id'] ?? '');
        $familyId = (string)($contextJson['selected_family_id'] ?? $contextJson['context_family_id'] ?? '');
        $variantId = (string)($contextJson['selected_variant_id'] ?? $contextJson['scene_variant_id'] ?? '');

        return $this->worldVisualContractForSelection($worldId, $familyId, $variantId);
    }

    public function worldVisualContractForSelection(?string $worldId, ?string $familyId, ?string $variantId): array
    {
        $worldId = trim((string)$worldId);
        $familyId = trim((string)$familyId);
        $variantId = trim((string)$variantId);

        $world = $this->worlds[$worldId] ?? null;
        $family = $this->families[$familyId] ?? null;
        $variant = $this->variants[$variantId] ?? null;

        if (!is_array($world) || !is_array($family) || !is_array($variant)) {
            return [
                'available' => false,
                'reason' => 'unknown_world_family_or_variant',
                'selected_world' => $worldId,
                'selected_family' => $familyId,
                'selected_variant' => $variantId,
            ];
        }

        $forbiddenDrift = array_values(array_unique(array_filter(array_merge(
            array_map('strval', (array)($variant['forbidden_drift'] ?? [])),
            array_map('strval', (array)($variant['negative_directives'] ?? [])),
            array_map('strval', (array)($family['negative_context_terms'] ?? [])),
            [
                'generic neutral interior',
                'Mediterranean villa',
                'rustic Tuscan room',
                'generic lounge',
                'showroom',
                'unrelated gallery',
            ]
        ))));

        return [
            'available' => true,
            'selected_world' => $worldId,
            'selected_world_label' => (string)($world['label'] ?? $worldId),
            'selected_family' => $familyId,
            'selected_family_label' => (string)($family['label'] ?? $familyId),
            'selected_variant' => $variantId,
            'selected_variant_label' => (string)($variant['label'] ?? $variantId),
            'visual_anchor' => (string)($variant['visual_anchor'] ?? $variant['atmosphere'] ?? $world['description'] ?? ''),
            'material_anchor' => (string)($variant['material_anchor'] ?? $this->joinText([
                $family['architecture_language'] ?? '',
                $family['wall_language'] ?? '',
                $family['floor_language'] ?? '',
            ])),
            'color_anchor' => (string)($variant['color_anchor'] ?? $family['material_temperature'] ?? ''),
            'spatial_anchor' => (string)($variant['spatial_anchor'] ?? $family['scale_behavior'] ?? ''),
            'required_architectural_language' => (string)($family['architecture_language'] ?? ''),
            'required_material_palette' => $this->joinText([
                $family['wall_language'] ?? '',
                $family['floor_language'] ?? '',
                $family['ceiling_language'] ?? '',
                $family['material_temperature'] ?? '',
            ]),
            'required_color_atmosphere' => $this->joinText([
                $variant['atmosphere'] ?? '',
                $family['lighting_bias'] ?? '',
                $variant['color_anchor'] ?? '',
            ]),
            'required_spatial_behavior' => $this->joinText([
                $variant['placement_mode'] ?? '',
                $family['scale_behavior'] ?? '',
                $variant['spatial_anchor'] ?? '',
            ]),
            'scene_directives' => array_values(array_map('strval', (array)($variant['scene_directives'] ?? []))),
            'forbidden_visual_drift' => $forbiddenDrift,
        ];
    }

    public function formatWorldVisualContractBlock(array $contract): string
    {
        if (empty($contract['available'])) {
            return '';
        }

        return "WORLD VISUAL CONTRACT:\n"
            . "- selected world: {$contract['selected_world']} ({$contract['selected_world_label']})\n"
            . "- selected family: {$contract['selected_family']} ({$contract['selected_family_label']})\n"
            . "- selected variant: {$contract['selected_variant']} ({$contract['selected_variant_label']})\n"
            . "- visual anchor: {$contract['visual_anchor']}\n"
            . "- material anchor: {$contract['material_anchor']}\n"
            . "- color atmosphere anchor: {$contract['color_anchor']}\n"
            . "- spatial anchor: {$contract['spatial_anchor']}\n"
            . "- required architectural language: {$contract['required_architectural_language']}\n"
            . "- required material palette: {$contract['required_material_palette']}\n"
            . "- required color atmosphere: {$contract['required_color_atmosphere']}\n"
            . "- required spatial behavior: {$contract['required_spatial_behavior']}\n"
            . "- scene directives: " . $this->joinText((array)($contract['scene_directives'] ?? [])) . "\n"
            . "- forbidden visual drift: " . $this->joinText((array)($contract['forbidden_visual_drift'] ?? [])) . "\n"
            . "- this scene must not become a generic neutral interior, Mediterranean villa, rustic Tuscan room, generic lounge, showroom, or unrelated gallery unless that is the selected world.";
    }

    // Compatibility contract:
    // excellent = priority selectable; allowed = valid selectable; avoid = testable but
    // not normal-selectable when excellent/allowed exists; forbidden = never selectable.
    public function compatibilityDecision(string $worldId, ?string $cameraSlotId, ?string $familyId = null, ?string $variantId = null): array
    {
        $cameraSlotId = trim((string)$cameraSlotId);
        $familyId = trim((string)$familyId);
        $variantId = trim((string)$variantId);
        if ($cameraSlotId === '') {
            return [
                'status' => 'not_evaluated',
                'world_id' => $worldId,
                'family_id' => $familyId !== '' ? $familyId : null,
                'variant_id' => $variantId !== '' ? $variantId : null,
                'camera_slot_id' => null,
                'reason' => 'camera_slot_id_missing',
            ];
        }

        $rule = $this->compatibilityRules[$worldId] ?? null;
        if (!is_array($rule)) {
            return [
                'status' => 'not_evaluated',
                'world_id' => $worldId,
                'family_id' => $familyId !== '' ? $familyId : null,
                'variant_id' => $variantId !== '' ? $variantId : null,
                'camera_slot_id' => $cameraSlotId,
                'reason' => 'world_has_no_compatibility_rule',
            ];
        }

        $decisionReasons = is_array($rule['decision_reasons'] ?? null) ? $rule['decision_reasons'] : [];

        if (in_array($cameraSlotId, (array)($rule['deny_camera_slots'] ?? []), true)) {
            return [
                'status' => 'forbidden',
                'world_id' => $worldId,
                'family_id' => $familyId !== '' ? $familyId : null,
                'variant_id' => $variantId !== '' ? $variantId : null,
                'camera_slot_id' => $cameraSlotId,
                'reason' => implode('; ', array_map('strval', (array)($rule['deny_reasons'] ?? []))),
                'source' => 'world_rule',
            ];
        }

        $override = null;
        if ($familyId !== '' && $variantId !== '') {
            $candidate = $rule['family_variant_overrides'][$familyId][$variantId] ?? null;
            if (is_array($candidate)) {
                $override = $candidate;
            }
        }

        if (is_array($override)) {
            $overrideReasons = is_array($override['decision_reasons'] ?? null) ? $override['decision_reasons'] : [];
            foreach (['excellent', 'allowed', 'avoid'] as $status) {
                if (in_array($cameraSlotId, (array)($override[$status] ?? []), true)) {
                    return [
                        'status' => $status,
                        'world_id' => $worldId,
                        'family_id' => $familyId,
                        'variant_id' => $variantId,
                        'camera_slot_id' => $cameraSlotId,
                        'reason' => (string)($overrideReasons[$cameraSlotId] ?? ''),
                        'source' => 'family_variant_override',
                    ];
                }
            }
        }

        foreach (['excellent', 'allowed', 'avoid'] as $status) {
            if (in_array($cameraSlotId, (array)($rule[$status] ?? []), true)) {
                return [
                    'status' => $status,
                    'world_id' => $worldId,
                    'family_id' => $familyId !== '' ? $familyId : null,
                    'variant_id' => $variantId !== '' ? $variantId : null,
                    'camera_slot_id' => $cameraSlotId,
                    'reason' => (string)($decisionReasons[$cameraSlotId] ?? ''),
                    'source' => 'world_rule',
                ];
            }
        }

        if (in_array($cameraSlotId, (array)($rule['allow_camera_slots'] ?? []), true)) {
            return [
                'status' => 'allowed',
                'world_id' => $worldId,
                'family_id' => $familyId !== '' ? $familyId : null,
                'variant_id' => $variantId !== '' ? $variantId : null,
                'camera_slot_id' => $cameraSlotId,
                'reason' => (string)($decisionReasons[$cameraSlotId] ?? ''),
                'source' => 'world_rule',
            ];
        }

        return [
            'status' => 'unspecified',
            'world_id' => $worldId,
            'family_id' => $familyId !== '' ? $familyId : null,
            'variant_id' => $variantId !== '' ? $variantId : null,
            'camera_slot_id' => $cameraSlotId,
            'reason' => 'camera_slot_not_listed_for_world',
            'source' => 'world_rule',
        ];
    }

    private function load(): void
    {
        $worldConfig = $this->loadConfig('app/Config/mockup_context_worlds.php');
        $familyConfig = $this->loadConfig('app/Config/mockup_context_families.php');
        $variantConfig = $this->loadConfig('app/Config/mockup_scene_variants.php');
        $compatConfig = $this->loadConfig('app/Config/mockup_camera_context_compatibility.php');

        $this->worlds = is_array($worldConfig['worlds'] ?? null) ? $worldConfig['worlds'] : [];
        $this->families = is_array($familyConfig['families'] ?? null) ? $familyConfig['families'] : [];
        $this->variants = is_array($variantConfig['variants'] ?? null) ? $variantConfig['variants'] : [];
        $this->compatibilityRules = is_array($compatConfig['rules'] ?? null) ? $compatConfig['rules'] : [];
    }

    private function firstStableDefaultCombinationForWorld(string $worldId): ?array
    {
        $world = $this->worlds[$worldId] ?? null;
        if (!is_array($world) || ($world['status'] ?? '') !== 'stable' || empty($world['default_rotation'])) {
            return null;
        }

        foreach ($this->stableDefaultFamilies($worldId) as $familyId => $family) {
            foreach ($this->stableDefaultVariants((string)$familyId) as $variantId => $variant) {
                return [
                    'world_id' => $worldId,
                    'family_id' => (string)$familyId,
                    'variant_id' => (string)$variantId,
                    'world' => $world,
                    'family' => $family,
                    'variant' => $variant,
                ];
            }
        }

        return null;
    }

    private function fallbackStableDefaultCombination(?string $missingWorldId, ?string $cameraSlotId = null): ?array
    {
        foreach (self::CURATORIAL_DEFAULT_WORLD_ROTATION as $worldId) {
            if ($missingWorldId !== null && $worldId === $missingWorldId) {
                continue;
            }

            $combination = $this->firstStableDefaultCombinationForWorld($worldId);
            if (is_array($combination)) {
                if ($cameraSlotId !== null && ($this->compatibilityDecision(
                    $worldId,
                    $cameraSlotId,
                    (string)$combination['family_id'],
                    (string)$combination['variant_id']
                )['status'] ?? '') === 'forbidden') {
                    continue;
                }
                return $combination;
            }
        }

        foreach ($this->stableDefaultCombinations() as $combination) {
            if ($cameraSlotId !== null && ($this->compatibilityDecision(
                (string)$combination['world_id'],
                $cameraSlotId,
                (string)$combination['family_id'],
                (string)$combination['variant_id']
            )['status'] ?? '') === 'forbidden') {
                continue;
            }
            return $combination;
        }

        return null;
    }

    private function fallbackCompatibleStableDefaultCombination(?string $missingWorldId, ?string $cameraSlotId = null): ?array
    {
        $cameraSlotId = trim((string)$cameraSlotId);
        if ($cameraSlotId === '') {
            return null;
        }

        foreach (['excellent', 'allowed'] as $targetStatus) {
            foreach (self::CURATORIAL_DEFAULT_WORLD_ROTATION as $worldId) {
                if ($missingWorldId !== null && $worldId === $missingWorldId) {
                    continue;
                }

                $combination = $this->compatibleStableDefaultCombinationForWorld($worldId, $cameraSlotId, null, $targetStatus);
                if (!is_array($combination)) {
                    continue;
                }

                return $combination;
            }

            foreach ($this->stableDefaultCombinations() as $combination) {
                $worldId = (string)($combination['world_id'] ?? '');
                if ($missingWorldId !== null && $worldId === $missingWorldId) {
                    continue;
                }

                if (($this->compatibilityDecision(
                    $worldId,
                    $cameraSlotId,
                    (string)$combination['family_id'],
                    (string)$combination['variant_id']
                )['status'] ?? '') === $targetStatus) {
                    return $combination;
                }
            }
        }

        return null;
    }

    private function compatibleStableDefaultCombinationForWorld(string $worldId, ?string $cameraSlotId = null, ?array $excludeCombination = null, ?string $targetStatus = null): ?array
    {
        $cameraSlotId = trim((string)$cameraSlotId);
        if ($cameraSlotId === '') {
            return null;
        }

        $targetStatuses = $targetStatus !== null ? [$targetStatus] : ['excellent', 'allowed'];
        foreach ($targetStatuses as $status) {
            foreach ($this->stableDefaultFamilies($worldId) as $familyId => $family) {
                foreach ($this->stableDefaultVariants((string)$familyId) as $variantId => $variant) {
                    if ($excludeCombination !== null
                        && (string)($excludeCombination['world_id'] ?? '') === $worldId
                        && (string)($excludeCombination['family_id'] ?? '') === (string)$familyId
                        && (string)($excludeCombination['variant_id'] ?? '') === (string)$variantId
                    ) {
                        continue;
                    }

                    if (($this->compatibilityDecision($worldId, $cameraSlotId, (string)$familyId, (string)$variantId)['status'] ?? '') !== $status) {
                        continue;
                    }

                    $world = $this->worlds[$worldId] ?? null;
                    if (!is_array($world)) {
                        continue;
                    }

                    return [
                        'world_id' => $worldId,
                        'family_id' => (string)$familyId,
                        'variant_id' => (string)$variantId,
                        'world' => $world,
                        'family' => $family,
                        'variant' => $variant,
                    ];
                }
            }
        }

        return null;
    }

    private function shouldReplaceForNormalSelection(array $metadata, ?string $cameraSlotId): bool
    {
        if (trim((string)$cameraSlotId) === '') {
            return false;
        }

        return in_array((string)($metadata['compatibility_status'] ?? $metadata['compatibility_decision']['status'] ?? ''), ['avoid', 'forbidden', 'unspecified'], true);
    }

    private function withReplacementTrace(array $selectedMetadata, array $originalMetadata, string $reason): array
    {
        $selectedDecision = is_array($selectedMetadata['compatibility_decision'] ?? null) ? $selectedMetadata['compatibility_decision'] : [];
        $originalDecision = is_array($originalMetadata['compatibility_decision'] ?? null) ? $originalMetadata['compatibility_decision'] : [];
        $originalStatus = (string)($originalDecision['status'] ?? 'unknown');
        $originalReason = trim((string)($originalDecision['reason'] ?? ''));
        $traceReasons = array_values(array_filter(array_merge(
            array_map('strval', (array)($selectedMetadata['decision_reasons'] ?? [])),
            [
                $reason,
                'original compatibility: ' . $originalStatus . ($originalReason !== '' ? ' - ' . $originalReason : ''),
            ]
        )));

        $selectedMetadata['compatibility_status'] = (string)($selectedDecision['status'] ?? '');
        $selectedMetadata['decision_reasons'] = $traceReasons;
        $selectedMetadata['replacement_applied'] = true;
        $selectedMetadata['world_preserved'] = false;
        $selectedMetadata['family_replacement_applied'] = false;
        // Contract: distribution_broken is true only when selected_world_id changes.
        $selectedMetadata['distribution_broken'] = true;
        $selectedMetadata['replacement_reason'] = $reason;
        $selectedMetadata['original_world_id'] = $originalMetadata['context_world_id'] ?? null;
        $selectedMetadata['selected_world_id'] = $selectedMetadata['context_world_id'] ?? null;
        $selectedMetadata['original_family_id'] = $originalMetadata['context_family_id'] ?? null;
        $selectedMetadata['selected_family_id'] = $selectedMetadata['context_family_id'] ?? null;
        $selectedMetadata['original_variant_id'] = $originalMetadata['scene_variant_id'] ?? null;
        $selectedMetadata['selected_variant_id'] = $selectedMetadata['scene_variant_id'] ?? null;
        $selectedMetadata['original_compatibility_decision'] = $originalDecision;

        return $selectedMetadata;
    }

    private function withFamilyReplacementTrace(array $selectedMetadata, array $originalMetadata, string $reason): array
    {
        $selectedDecision = is_array($selectedMetadata['compatibility_decision'] ?? null) ? $selectedMetadata['compatibility_decision'] : [];
        $originalDecision = is_array($originalMetadata['compatibility_decision'] ?? null) ? $originalMetadata['compatibility_decision'] : [];
        $originalStatus = (string)($originalDecision['status'] ?? 'unknown');
        $originalReason = trim((string)($originalDecision['reason'] ?? ''));
        $traceReasons = array_values(array_filter(array_merge(
            array_map('strval', (array)($selectedMetadata['decision_reasons'] ?? [])),
            [
                $reason,
                'original compatibility: ' . $originalStatus . ($originalReason !== '' ? ' - ' . $originalReason : ''),
            ]
        )));

        $selectedMetadata['compatibility_status'] = (string)($selectedDecision['status'] ?? '');
        $selectedMetadata['decision_reasons'] = $traceReasons;
        $selectedMetadata['replacement_applied'] = true;
        $selectedMetadata['world_preserved'] = true;
        // Contract: family_replacement_applied means world preserved, family/variant changed.
        $selectedMetadata['family_replacement_applied'] = true;
        $selectedMetadata['distribution_broken'] = false;
        $selectedMetadata['replacement_reason'] = $reason;
        $selectedMetadata['original_world_id'] = $originalMetadata['context_world_id'] ?? null;
        $selectedMetadata['selected_world_id'] = $selectedMetadata['context_world_id'] ?? null;
        $selectedMetadata['original_family_id'] = $originalMetadata['context_family_id'] ?? null;
        $selectedMetadata['selected_family_id'] = $selectedMetadata['context_family_id'] ?? null;
        $selectedMetadata['original_variant_id'] = $originalMetadata['scene_variant_id'] ?? null;
        $selectedMetadata['selected_variant_id'] = $selectedMetadata['scene_variant_id'] ?? null;
        $selectedMetadata['original_compatibility_decision'] = $originalDecision;

        return $selectedMetadata;
    }

    private function loadConfig(string $relativePath): array
    {
        $path = $this->rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($path)) {
            return [];
        }

        $data = require $path;
        return is_array($data) ? $data : [];
    }

    private function validate(): array
    {
        $errors = [];
        $warnings = [];

        foreach ($this->worlds as $worldId => $world) {
            if (!is_array($world) || ($world['world_id'] ?? '') !== $worldId) {
                $errors[] = "Invalid world row: {$worldId}";
                continue;
            }
            if (($world['status'] ?? '') === 'experimental' && !empty($world['default_rotation'])) {
                $errors[] = "Experimental world {$worldId} has default_rotation=true.";
            }
        }

        foreach ($this->families as $familyId => $family) {
            if (!is_array($family) || ($family['family_id'] ?? '') !== $familyId) {
                $errors[] = "Invalid family row: {$familyId}";
                continue;
            }
            $worldId = (string)($family['world_id'] ?? '');
            if ($worldId === '' || !isset($this->worlds[$worldId])) {
                $errors[] = "Family {$familyId} points to missing world {$worldId}.";
            }
            if (($family['status'] ?? '') === 'experimental' && !empty($family['default_rotation'])) {
                $errors[] = "Experimental family {$familyId} has default_rotation=true.";
            }
        }

        foreach ($this->variants as $variantId => $variant) {
            if (!is_array($variant) || ($variant['variant_id'] ?? '') !== $variantId) {
                $errors[] = "Invalid scene variant row: {$variantId}";
                continue;
            }
            $familyId = (string)($variant['family_id'] ?? '');
            if ($familyId === '' || !isset($this->families[$familyId])) {
                $errors[] = "Scene variant {$variantId} points to missing family {$familyId}.";
            }
            if (($variant['status'] ?? '') === 'experimental' && !empty($variant['default_rotation'])) {
                $errors[] = "Experimental scene variant {$variantId} has default_rotation=true.";
            }
        }

        foreach ($this->compatibilityRules as $ruleId => $rule) {
            $worldId = (string)($rule['world_id'] ?? $ruleId);
            if ($worldId === '' || !isset($this->worlds[$worldId])) {
                $errors[] = "Compatibility rule {$ruleId} points to missing world {$worldId}.";
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private function safeMetadata(string $reason): array
    {
        return [
            'context_world_id' => null,
            'context_family_id' => null,
            'scene_variant_id' => null,
            'world_status' => null,
            'family_status' => null,
            'variant_status' => null,
            'default_rotation' => false,
            'context_tags' => [],
            'presence_policy' => ['world' => [], 'family' => []],
            'risk_controls' => [],
            'placement_mode' => null,
            'compatibility_status' => 'not_evaluated',
            'compatibility_decision' => [
                'status' => 'not_evaluated',
                'world_id' => null,
                'camera_slot_id' => null,
                'reason' => $reason,
            ],
            'decision_reasons' => [$reason],
            'replacement_applied' => false,
            'world_preserved' => false,
            'family_replacement_applied' => false,
            'distribution_broken' => false,
            'replacement_reason' => '',
            'original_world_id' => null,
            'selected_world_id' => null,
            'original_family_id' => null,
            'selected_family_id' => null,
            'original_variant_id' => null,
            'selected_variant_id' => null,
            'context_world_directive' => '',
            'context_family_directive' => '',
            'scene_variant_directive' => '',
            'world_visual_contract' => [
                'available' => false,
                'reason' => $reason,
            ],
            'world_architecture_language' => '',
            'world_wall_language' => '',
            'world_floor_language' => '',
            'world_ceiling_language' => '',
            'world_lighting_bias' => '',
            'world_material_temperature' => '',
            'world_scale_behavior' => '',
            'world_negative_context_controls' => $this->minimumArtworkControls(),
            'world_risk_controls' => $this->minimumArtworkControls(),
            'world_presence_policy' => ['world' => [], 'family' => []],
        ];
    }

    private function composeWorldDirective(array $world): string
    {
        return trim(sprintf(
            '%s: %s Usage: %s. Context supports the artwork, never replaces it.',
            (string)($world['label'] ?? 'Context world'),
            (string)($world['description'] ?? ''),
            (string)($world['usage_type'] ?? '')
        ));
    }

    private function composeFamilyDirective(array $family): string
    {
        return trim(sprintf(
            'Architecture: %s Wall: %s Floor: %s Ceiling: %s Lighting: %s Material temperature: %s Scale behavior: %s.',
            (string)($family['architecture_language'] ?? ''),
            (string)($family['wall_language'] ?? ''),
            (string)($family['floor_language'] ?? ''),
            (string)($family['ceiling_language'] ?? ''),
            (string)($family['lighting_bias'] ?? ''),
            (string)($family['material_temperature'] ?? ''),
            (string)($family['scale_behavior'] ?? '')
        ));
    }

    private function composeVariantDirective(array $variant): string
    {
        $directives = implode(' ', array_map('strval', (array)($variant['scene_directives'] ?? [])));
        return trim(sprintf(
            '%s: %s Placement mode: %s. %s',
            (string)($variant['label'] ?? 'Scene variant'),
            (string)($variant['atmosphere'] ?? ''),
            (string)($variant['placement_mode'] ?? ''),
            $directives
        ));
    }

    private function joinText(array $items): string
    {
        $items = array_values(array_filter(array_map(static function ($item): string {
            if (is_array($item)) {
                return implode(', ', array_map('strval', $item));
            }

            return trim((string)$item);
        }, $items), static fn(string $item): bool => $item !== ''));

        return implode('; ', array_unique($items));
    }

    private function minimumArtworkControls(): array
    {
        return [
            'artwork remains the primary visual subject',
            'context supports the artwork, never replaces it',
            'no artwork substitution',
            'no scale distortion',
            'no decorative dominance',
        ];
    }
}
