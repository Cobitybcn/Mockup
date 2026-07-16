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
            'artwork_fidelity' => 10,
            'character_identity' => 20,
            'wardrobe_identity' => 30,
            'end_frame' => 40,
            'main' => 50,
            'reference' => 60,
            'environment' => 70,
            'cinematic_style' => 80,
            default => 90,
        };
    }
}
