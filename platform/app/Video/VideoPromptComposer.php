<?php
declare(strict_types=1);

final class VideoPromptComposer
{
    private const INTEGRITY = <<<'TEXT'
Preserve the artwork exactly. Do not redraw, reinterpret, animate, crop, deform or modify its internal composition. Do not change its colors, marks, texture, aspect ratio or orientation. Only animate the camera, environment, light and explicitly permitted secondary elements.
TEXT;

    public static function compose(array $project, array $scene, bool $continuesPreviousScene = false): string
    {
        $sections = [];
        $global = trim((string)($project['globalPrompt'] ?? ''));
        if ($global !== '') $sections[] = "PROJECT DIRECTION\n" . $global;

        $scenePrompt = trim((string)($scene['prompt'] ?? ''));
        if ($scenePrompt !== '') $sections[] = "SCENE PROMPT\n" . $scenePrompt;

        if ($continuesPreviousScene) {
            $sections[] = <<<'TEXT'
CONTINUITY WITH THE PREVIOUS SCENE
Continue directly from the supplied final frame. Unless the scene prompt explicitly requests a deliberate change, preserve the exact artwork and character identities, environment, lighting, rhythm, direction of motion, spatial relationships and narrative tone. The next scene must not feel like a new or unrelated story.
TEXT;
        }

        $sections[] = <<<'TEXT'
REFERENCE PRIORITY
Apply visual references in this strict priority order: 1) artwork identity and fidelity, 2) character identity, 3) wardrobe identity, 4) all other visual references. Lower-priority references must never alter a higher-priority identity unless the scene prompt explicitly requests that change.
TEXT;

        $sections[] = "ARTWORK FIDELITY\n" . self::INTEGRITY;

        return implode("\n\n", $sections);
    }
}
