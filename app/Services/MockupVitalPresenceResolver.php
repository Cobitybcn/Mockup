<?php
declare(strict_types=1);

/**
 * MockupVitalPresenceResolver — derives the operative "Vital Presence" layer.
 *
 * Phase 2.9. Pure derivation (no DB, no master prompts, no Gemini). It turns the
 * presence signals already produced by the camera-first / world-isolated flow
 * (world & family presence_policy, the camera human_subject_block, placement)
 * into a single additive vital_presence object for NEW contexts.
 *
 * Vital Presence is a SECONDARY, ambient layer. It MAY introduce subtle living or
 * atmospheric presence, but it must never:
 *   - dominate the scene,
 *   - replace or obscure the artwork,
 *   - define the artwork's physical scale by itself,
 *   - turn the mockup into a fashion / lifestyle editorial,
 *   - force a human figure to always appear.
 *
 * Camera-first remains the authority over camera, scale, framing and composition;
 * a human only appears when a camera scale-reference explicitly requires it.
 */
final class MockupVitalPresenceResolver
{
    /** Full vocabulary of allowed presence types (constraint #5). */
    private const ORGANIC_TYPES = ['plant', 'moss', 'partial_vegetation'];
    private const ATMOSPHERIC_TYPES = [
        'water', 'humidity', 'vapor', 'illuminated_dust',
        'reflection', 'organic_shadow', 'light_ray',
    ];

    private const DOMINANCE_RULE =
        'Vital presence is secondary and ambient: it must not dominate the composition, '
        . 'must not replace or obscure the artwork, must not define the artwork scale by itself, '
        . 'and must not turn the image into a fashion or lifestyle editorial. '
        . 'Do not force a human figure; a human appears only as an explicit camera scale reference.';

    /**
     * Resolve from a world/family presence policy plus camera + placement signals.
     *
     * @param array<string,mixed> $presencePolicy e.g. ['world'=>['human'=>..,'animal'=>..],'family'=>[...]]
     * @param string $cameraHumanSubjectBlock The camera slot human_subject_block (sovereign over human scale).
     * @param string $placementMode e.g. 'wall_hanging'
     * @return array<string,mixed>
     */
    public static function resolve(
        array $presencePolicy,
        string $cameraHumanSubjectBlock = '',
        string $placementMode = ''
    ): array {
        $worldHuman  = self::policyValue($presencePolicy, 'world', 'human');
        $familyHuman = self::policyValue($presencePolicy, 'family', 'human');
        $worldAnimal = self::policyValue($presencePolicy, 'world', 'animal');
        $familyAnimal = self::policyValue($presencePolicy, 'family', 'animal');

        $hasPolicy = $worldHuman !== '' || $familyHuman !== '' || $worldAnimal !== '' || $familyAnimal !== '';
        $sourcePolicy = $hasPolicy ? 'world_family_presence_policy' : 'default_conservative';

        // Camera is sovereign over human scale references.
        $cameraRequestsHuman = trim($cameraHumanSubjectBlock) !== ''
            && !preg_match('/no\s+human\s+figure/i', $cameraHumanSubjectBlock);

        $humanAllowed = $cameraRequestsHuman
            || self::policyAllows($worldHuman)
            || self::policyAllows($familyHuman);
        $animalAllowed = self::policyAllows($worldAnimal) || self::policyAllows($familyAnimal);

        // Organic & atmospheric presence are universally subtle and enriching; allowed
        // by default and governed by the dominance rule rather than by a hard policy.
        $organicAllowed = true;
        $atmosphericAllowed = true;

        // Mode reflects what the PROMPT will actually invite (conflict-free with the
        // negative layer): a human only enters as a camera scale reference; otherwise
        // the layer stays ambient (organic/atmospheric) without added figures.
        if ($cameraRequestsHuman) {
            $mode = 'human_scale_reference';
        } elseif ($organicAllowed || $atmosphericAllowed) {
            $mode = 'ambient_optional';
        } else {
            $mode = 'absent';
        }

        $allowedTypes = [];
        if ($humanAllowed) {
            $allowedTypes[] = 'human';
        }
        if ($animalAllowed) {
            $allowedTypes[] = 'animal';
        }
        if ($organicAllowed) {
            $allowedTypes = array_merge($allowedTypes, self::ORGANIC_TYPES);
        }
        if ($atmosphericAllowed) {
            $allowedTypes = array_merge($allowedTypes, self::ATMOSPHERIC_TYPES);
        }
        $allowedTypes = array_values(array_unique($allowedTypes));

        return [
            'version'                       => 'phase_2_9_vital_presence_v1',
            'mode'                          => $mode,
            'human_allowed'                 => $humanAllowed,
            'animal_allowed'                => $animalAllowed,
            'organic_presence_allowed'      => $organicAllowed,
            'atmospheric_presence_allowed'  => $atmosphericAllowed,
            'allowed_presence_types'        => $allowedTypes,
            'dominance_rule'                => self::DOMINANCE_RULE,
            'source_policy'                 => $sourcePolicy,
            'policy_snapshot'               => [
                'world_human'  => $worldHuman,
                'family_human' => $familyHuman,
                'world_animal' => $worldAnimal,
                'family_animal' => $familyAnimal,
            ],
            'camera_requests_human'         => $cameraRequestsHuman,
            'placement_mode'                => trim($placementMode),
            'directive'                     => self::composeDirective($mode, $organicAllowed, $atmosphericAllowed),
        ];
    }

    /**
     * Convenience: resolve directly from a proposal/context array that already
     * carries the resolved policy + camera block (used by the engine flow).
     *
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    public static function resolveFromContext(array $ctx): array
    {
        $policy = [];
        foreach (['presence_policy', 'world_presence_policy'] as $key) {
            if (is_array($ctx[$key] ?? null) && $ctx[$key] !== []) {
                $policy = $ctx[$key];
                break;
            }
        }

        $cameraBlock = '';
        if (isset($ctx['human_subject_block']) && !is_array($ctx['human_subject_block'])) {
            $cameraBlock = (string)$ctx['human_subject_block'];
        }

        $placement = '';
        if (isset($ctx['placement_mode']) && !is_array($ctx['placement_mode'])) {
            $placement = (string)$ctx['placement_mode'];
        }

        return self::resolve($policy, $cameraBlock, $placement);
    }

    /**
     * Compose the prompt scaffold directive (NOT a master prompt; assembled from
     * the resolved flags). Conflict-free with the negative layer: a human is
     * invited ONLY as a camera scale reference; otherwise the directive offers
     * ambient organic/atmospheric presence and explicitly excludes added figures.
     */
    private static function composeDirective(string $mode, bool $organic, bool $atmospheric): string
    {
        $types = [];
        if ($organic) {
            $types = array_merge($types, self::ORGANIC_TYPES);
        }
        if ($atmospheric) {
            $types = array_merge($types, self::ATMOSPHERIC_TYPES);
        }
        $readable = str_replace('_', ' ', implode(', ', $types));

        if ($mode === 'human_scale_reference') {
            $base = 'A single human figure appears strictly as a camera scale reference, owned by the camera layer.';
            if ($types !== []) {
                $base .= ' The scene may also include optional, subtle ambient presence (' . $readable . ').';
            }
            return $base . ' ' . self::DOMINANCE_RULE;
        }

        if ($types === []) {
            return 'Vital presence: none beyond a quiet architectural setting around the artwork. '
                . self::DOMINANCE_RULE;
        }

        return 'The scene may include optional, subtle living or atmospheric presence (' . $readable . '), '
            . 'without any added human or animal figures. ' . self::DOMINANCE_RULE;
    }

    /** @param array<string,mixed> $policy */
    private static function policyValue(array $policy, string $scope, string $kind): string
    {
        $scoped = $policy[$scope] ?? null;
        if (!is_array($scoped)) {
            return '';
        }
        $value = $scoped[$kind] ?? '';
        if (is_array($value)) {
            return '';
        }
        return strtolower(trim((string)$value));
    }

    private static function policyAllows(string $policy): bool
    {
        $policy = strtolower(trim($policy));
        if ($policy === '' || $policy === 'none' || $policy === 'no' || $policy === 'forbidden') {
            return false;
        }
        // "optional_single_secondary", "none_or_single_secondary_scale_reference", etc.
        return (bool)preg_match('/\b(single|optional|secondary|scale_reference|allowed|permitted)\b/', $policy)
            || strpos($policy, 'single') !== false
            || strpos($policy, 'optional') !== false
            || strpos($policy, 'secondary') !== false;
    }
}
