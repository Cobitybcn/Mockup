<?php
declare(strict_types=1);

final class MockupVariationEligibility
{
    private const CLOSEUP_SLOT_IDS = [
        'detalle_textura_lienzo',
        'borde_canvas_closeup',
        'rasante_superficie_pintura',
        'corte_agresivo_esquina_obra',
        'esquina_obra_perspectiva_extrema',
    ];

    public static function isCloseupMockup(array $mockup): bool
    {
        $state = $mockup['selector_state'] ?? null;
        if (!is_array($state)) {
            $state = json_decode((string)($mockup['selector_state_json'] ?? ''), true);
        }
        $state = is_array($state) ? $state : [];
        $combo = is_array($state['combination'] ?? null) ? $state['combination'] : [];

        $tokens = [
            (string)($mockup['context_id'] ?? ''),
            (string)($combo['selected_camera_slot_id'] ?? ''),
            (string)($combo['camera_slot_name'] ?? ''),
            (string)($combo['context_title'] ?? ''),
        ];

        foreach ($tokens as $token) {
            $normalized = self::normalize($token);
            if ($normalized === '') {
                continue;
            }
            if (in_array($normalized, self::CLOSEUP_SLOT_IDS, true)) {
                return true;
            }
            if (str_contains($normalized, 'closeup') || str_contains($normalized, 'close_up')) {
                return true;
            }
            if (str_contains($normalized, 'detalle_textura') || str_contains($normalized, 'rasante_superficie')) {
                return true;
            }
            if (str_contains($normalized, 'corte_agresivo') || str_contains($normalized, 'perspectiva_extrema')) {
                return true;
            }
        }

        return false;
    }

    public static function canUseVariationLab(array $mockup): bool
    {
        return !self::isCloseupMockup($mockup);
    }

    private static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['-', ' '], '_', $value);
        $value = strtr($value, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ñ' => 'n',
        ]);
        return preg_replace('/_+/', '_', $value) ?? $value;
    }
}
