<?php
declare(strict_types=1);

final class WorldMotherCameraAuthorityPolicy
{
    /**
     * @var array<string,bool>
     */
    private const DETAIL_CAMERA_SLOTS = [
        'detalle_textura_lienzo' => true,
        'borde_canvas_closeup' => true,
        'esquina_obra_perspectiva_extrema' => true,
        'rasante_superficie_pintura' => true,
    ];

    /**
     * @var array<string,bool>
     */
    private const ENVIRONMENT_CAMERA_SLOTS = [
        'vista_aerea_contexto_ventanas' => true,
        'nadir_extremo_arquitectonico' => true,
    ];

    public static function promptBlock(string $cameraSlotId): string
    {
        if (isset(self::DETAIL_CAMERA_SLOTS[$cameraSlotId])) {
            return self::detailCameraPromptBlock($cameraSlotId);
        }

        if (isset(self::ENVIRONMENT_CAMERA_SLOTS[$cameraSlotId])) {
            return self::environmentCameraPromptBlock();
        }

        return self::balancedCameraPromptBlock();
    }

    public static function applyToPrompt(string $prompt, string $cameraSlotId): string
    {
        $prompt = trim($prompt);
        if ($prompt === '' || str_contains($prompt, 'WORLD MOTHER AUTHORITY POLICY')) {
            return $prompt;
        }

        return self::promptBlock($cameraSlotId) . "\n\n" . $prompt;
    }

    private static function detailCameraPromptBlock(string $cameraSlotId): string
    {
        $rasanteRule = $cameraSlotId === 'rasante_superficie_pintura'
            ? "\nFor rasante_superficie_pintura specifically, the camera must stay extremely close and almost parallel to the painted surface, like a grazing macro shot across the skin of the artwork plane. Do not let the world mother reference convert this into a full-room atelier view, a normal side view, a 3/4 wall mockup, or an artwork-on-wall presentation."
            : '';

        return trim(<<<TEXT
WORLD MOTHER AUTHORITY POLICY - DETAIL CAMERA

WORLD MOTHER ROLE: ENVIRONMENTAL INSPIRATION ONLY. The output must be a newly composed scene, not a recreation or edit of the source photo.

The selected camera slot remains the highest authority for composition, crop, lens behavior, camera height, tilt, distance, and perspective.

This is a detail/material camera. The world mother image is not a room, layout, or camera reference. It is visual evidence for peripheral material and atmospheric cues only: light quality, color temperature, surface texture, wall/floor material feeling, studio palette, and distant contextual softness.

Do not let windows, furniture, easels, tables, shelves, props, architecture, or a full-room layout compete with the detail camera. The artwork surface, edge, pigment relief, canvas weave, painted side continuation, and shallow optical depth must remain dominant.

The world mother reference must not make the generator show the whole artwork, pull the camera backward, widen the composition into a room scene, or replace the selected detail camera with a general atelier mockup. If the detail camera needs context, create a new peripheral context from the material/light family of the world mother instead of reproducing the source room.{$rasanteRule}
TEXT);
    }

    private static function environmentCameraPromptBlock(): string
    {
        return trim(<<<TEXT
WORLD MOTHER AUTHORITY POLICY - ENVIRONMENT CAMERA

WORLD MOTHER ROLE: ENVIRONMENTAL INSPIRATION ONLY. The output must be a newly composed scene, not a recreation or edit of the source photo.

The selected camera slot remains the highest authority for viewpoint, crop, lens behavior, camera height, tilt, distance, and perspective.

This is an environment/architectural camera. The world mother image is not the environment to reproduce and not the camera reference. It is visual evidence for building a new environment in the same environmental family: materiality, surface texture, palette, light temperature, light direction, atmospheric density, architectural mood, and premium spatial character.

Build a new environment through the selected camera slot. You may relocate or reinvent windows, openings, walls, furniture, supports, objects, depth, and architectural structure whenever needed to obey the camera slot and serve the root artwork. Preserve the visual family, material character, light quality, and atmosphere; do not preserve the source photo layout, camera angle, crop, room geometry, wall choice, window placement, furniture placement, or object positions.
TEXT);
    }

    private static function balancedCameraPromptBlock(): string
    {
        return trim(<<<TEXT
WORLD MOTHER AUTHORITY POLICY - BALANCED CAMERA

WORLD MOTHER ROLE: ENVIRONMENTAL INSPIRATION ONLY. The output must be a newly composed scene, not a recreation or edit of the source photo.

The selected camera slot remains the highest authority for viewpoint, crop, lens behavior, camera height, tilt, distance, and perspective.

The world mother image is not the environment to reproduce and not the camera reference. It is visual evidence for building a new compatible environment in the same material, lighting, architectural, and atmospheric family. It is not the authority for final composition.

Build a new photograph from the selected camera slot. You may move, replace, or reinvent windows, walls, furniture, objects, depth, and spatial layout so the camera slot can fully govern the final image. Keep the material palette, light quality, surface language, atmospheric tone, and environmental family; do not copy the source photo layout, camera angle, crop, wall choice, window placement, furniture placement, object positions, or room geometry.
TEXT);
    }
}
