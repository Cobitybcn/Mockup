<?php
declare(strict_types=1);

final class VideoReferencePolicy
{
    public const MAX_IMAGES = 10;
    public const MAX_VIDEOS = 1;
    public const MAX_VIDEO_SECONDS = 10.0;

    public const IMAGE_ROLES = [
        'reference',
        'main',
        'artwork_fidelity',
        'character_identity',
        'wardrobe_identity',
        'environment',
        'cinematic_style',
        'start_frame',
        'end_frame',
    ];

    public const SINGLE_ROLES = [
        'main',
        'artwork_fidelity',
        'character_identity',
        'wardrobe_identity',
        'start_frame',
        'end_frame',
        'source_video',
    ];

    public static function roles(): array
    {
        return [...self::IMAGE_ROLES, 'source_video'];
    }

    public static function isSingle(string $role): bool
    {
        return in_array($role, self::SINGLE_ROLES, true);
    }

    public static function isImageRole(string $role): bool
    {
        return in_array($role, self::IMAGE_ROLES, true);
    }

    public static function defaultInstruction(string $role): string
    {
        return match ($role) {
            'artwork_fidelity' => 'Conservar exactamente la identidad, colores, textura, proporciones y detalles de la obra de arte.',
            'character_identity' => 'Conservar exactamente la identidad, rostro, cuerpo y rasgos del personaje.',
            'wardrobe_identity' => 'Conservar exactamente el vestuario, sus colores, prendas y accesorios.',
            'end_frame' => 'Usar como objetivo visual para la composición final, sin tratarla como un fotograma final garantizado.',
            'environment' => 'Usar como referencia para el ambiente y la relación espacial.',
            'cinematic_style' => 'Usar como referencia para iluminación, composición y lenguaje cinematográfico.',
            default => '',
        };
    }

    public static function sortWeight(string $role): int
    {
        return match ($role) {
            'start_frame' => 10,
            'end_frame' => 20,
            'artwork_fidelity' => 30,
            'character_identity' => 40,
            'wardrobe_identity' => 50,
            'main' => 60,
            'reference' => 70,
            'environment' => 80,
            'cinematic_style' => 90,
            default => 90,
        };
    }

    public static function promptNumber(string $role, int $position = 1): int
    {
        return match ($role) {
            'start_frame' => 1,
            'end_frame' => 2,
            'artwork_fidelity' => 3,
            'character_identity' => 4,
            'wardrobe_identity' => 5,
            default => min(self::MAX_IMAGES, 5 + max(1, $position)),
        };
    }
}
