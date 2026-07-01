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

    private static function detailCameraPromptBlock(string $cameraSlotId): string
    {
        $rasanteRule = $cameraSlotId === 'rasante_superficie_pintura'
            ? "\nFor rasante_superficie_pintura specifically, the camera must stay extremely close and almost parallel to the painted surface, like a grazing macro shot across the skin of the artwork plane. Do not let the world mother reference convert this into a full-room atelier view, a normal side view, a 3/4 wall mockup, or an artwork-on-wall presentation."
            : '';

        return trim(<<<TEXT
WORLD MOTHER AUTHORITY POLICY - DETAIL CAMERA

The selected camera slot remains the highest authority for composition, crop, lens behavior, camera height, tilt, distance, and perspective.

This is a detail/material camera. The world mother image is still a visual reference, but only for peripheral environment identity: light quality, wall or floor color, material atmosphere, studio palette, distant blurred hints, and believable surrounding context.

Do not let windows, furniture, easels, tables, shelves, props, architecture, or a full-room layout compete with the detail camera. The artwork surface, edge, pigment relief, canvas weave, painted side continuation, and shallow optical depth must remain dominant.

The world mother reference must not make the generator show the whole artwork, pull the camera backward, widen the composition into a room scene, or replace the selected detail camera with a general atelier mockup.{$rasanteRule}
TEXT);
    }

    private static function environmentCameraPromptBlock(): string
    {
        return trim(<<<TEXT
WORLD MOTHER AUTHORITY POLICY - ENVIRONMENT CAMERA

The selected camera slot remains the highest authority for viewpoint, crop, lens behavior, camera height, tilt, distance, and perspective.

This is an environment/architectural camera. The world mother image is the visual authority for environment identity: room type, object density, architecture, materials, wall and floor language, palette, light direction, windows, furniture family, studio tools, and overall atelier atmosphere.

Reconstruct the world mother environment from the selected camera slot viewpoint. Do not copy the mother image camera angle if it conflicts with the selected slot, but do preserve the mother image as the source of the room's visual language and environmental richness.
TEXT);
    }

    private static function balancedCameraPromptBlock(): string
    {
        return trim(<<<TEXT
WORLD MOTHER AUTHORITY POLICY - BALANCED CAMERA

The selected camera slot remains the highest authority for viewpoint, crop, lens behavior, camera height, tilt, distance, and perspective.

The world mother image remains the visual authority for environment identity, materials, palette, light quality, and believable surrounding context, but it must not override the selected camera slot composition.
TEXT);
    }
}
