<?php
declare(strict_types=1);

final class VideoProviderRegistry
{
    public const OMNI = 'vertex_gemini_omni';
    public const VEO = 'vertex_veo';

    public static function configuredName(): string
    {
        $provider = strtolower(trim(app_env('VIDEO_GENERATION_PROVIDER', self::OMNI)));
        return in_array($provider, [self::OMNI, self::VEO], true) ? $provider : self::OMNI;
    }

    public static function configuredModel(): string
    {
        return self::configuredName() === self::OMNI
            ? trim(app_env('VIDEO_OMNI_MODEL', 'gemini-omni-flash-preview'))
            : trim(app_env('VIDEO_VEO_MODEL', 'veo-3.1-fast-generate-001'));
    }

    /** @return int[] */
    public static function durations(?string $provider = null): array
    {
        return ($provider ?? self::configuredName()) === self::OMNI
            ? [3,4,5,6,7,8,9,10]
            : [4,6,8];
    }

    /** @return string[] */
    public static function generationModes(?string $provider = null): array
    {
        return ($provider ?? self::configuredName()) === self::OMNI
            ? ['image_to_video']
            : ['image_to_video','first_last_frame'];
    }

    public static function defaultMode(?string $provider = null): string
    {
        return 'image_to_video';
    }

    public static function defaultDuration(?string $provider = null): int
    {
        return 4;
    }

    public static function make(?string $provider = null, ?string $model = null): VideoGenerationProvider
    {
        $provider ??= self::configuredName();
        return match ($provider) {
            self::OMNI => new VertexGeminiOmniProvider($model),
            self::VEO => new VertexVeoProvider($model),
            default => throw new InvalidArgumentException('Unsupported video generation provider.'),
        };
    }
}
