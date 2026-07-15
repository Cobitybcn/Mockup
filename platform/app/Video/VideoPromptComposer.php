<?php
declare(strict_types=1);

final class VideoPromptComposer
{
    private const INTEGRITY = <<<'TEXT'
Preserve the artwork exactly. Do not redraw, reinterpret, animate, crop, deform or modify its internal composition. Do not change its colors, marks, texture, aspect ratio or orientation. Only animate the camera, environment, light and explicitly permitted secondary elements.
TEXT;

    public static function compose(array $project, array $scene): string
    {
        $sections = [];
        $global = trim((string)($project['globalPrompt'] ?? ''));
        if ($global !== '') $sections[] = "PROJECT DIRECTION\n" . $global;

        $scenePrompt = trim((string)($scene['prompt'] ?? ''));
        if ($scenePrompt !== '') $sections[] = "SCENE PROMPT\n" . $scenePrompt;

        $camera = (string)($scene['cameraMovement'] ?? 'static');
        if ($camera === 'custom') $camera = trim((string)($scene['customCameraMovement'] ?? '')) ?: 'static';
        $sections[] = "CURATORIAL CONTROLS\nCamera movement: " . self::words($camera)
            . ". Motion intensity: " . self::words((string)($scene['motionIntensity'] ?? 'low')) . '.';

        $artworkMotion = (string)($scene['artworkMotion'] ?? 'locked');
        $motionDirection = match ($artworkMotion) {
            'minimal' => 'Allow only imperceptible physical light or surface variations; the composition itself remains unchanged.',
            'creative' => 'Creative freedom applies only to the surrounding environment and secondary elements; the artwork composition remains visually exact.',
            default => 'The artwork is locked. No movement may occur inside the artwork.',
        };
        $sections[] = "ARTWORK FIDELITY — " . strtoupper($artworkMotion) . "\n" . self::INTEGRITY . "\n" . $motionDirection;
        $sections[] = 'Maintain a restrained, contemplative, cinematic tone. Avoid advertising gestures, rapid motion and gratuitous spectacle.';

        return implode("\n\n", $sections);
    }

    private static function words(string $value): string
    {
        return trim(str_replace('_', ' ', $value));
    }
}
