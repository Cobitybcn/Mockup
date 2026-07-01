<?php
declare(strict_types=1);

final class ArtworkDominancePolicy
{
    /**
     * @var array<string,bool>
     */
    private const HIGH_DOMINANCE_SLOTS = [
        'diagonal_estudio_moderno' => true,
        'luz_dorada_sombra_diagonal' => true,
        'reflejo_dorado_tarde_palazzo' => true,
        'obra_apoyada_suelo_7_8' => true,
    ];

    /**
     * @var array<string,bool>
     */
    private const MEDIUM_DOMINANCE_SLOTS = [
        'contrapicado_7_8' => true,
        'contrapicado_raton_puro' => true,
        'pasillo_obra_descentrada_proxima' => true,
        'borgona_recovecos_3_4_loft_hormigon' => true,
    ];

    /**
     * @var array<string,bool>
     */
    private const ENVIRONMENT_DOMINANCE_SLOTS = [
        'nadir_extremo_arquitectonico' => true,
        'vista_aerea_contexto_ventanas' => true,
    ];

    public static function promptBlock(string $cameraSlotId): string
    {
        if ($cameraSlotId === 'obra_apoyada_suelo_7_8') {
            return trim(<<<TEXT
ARTWORK VISUAL DOMINANCE POLICY - FLOOR LEANING CLOSE

Treat this floor-leaning artwork view as a close product photograph of the artwork leaning against a wall or stable support. The artwork, its painted face, side depth, bottom edge, contact shadow, texture, and slight backward angle are the subject.

Use a close-up or medium-close camera distance from the selected camera viewpoint. Show only the minimum surrounding floor, wall, or support needed to prove that the artwork is physically leaning and grounded.

Do not compose this as a full-room interior view. Avoid wide empty architecture, distant furniture groups, broad ceiling, broad floor spread, symmetrical room framing, or deep room context. If the world mother environment appears, it should appear as cropped background, peripheral material cues, or subtle atmosphere around the artwork.

Do not enlarge the artwork physically, do not make it monumental, and do not violate the ARTWORK SCALE POLICY. The artwork can occupy more of the final frame only because the camera is closer, the crop is tighter, or the lens compresses the view.
TEXT);
        }

        if ($cameraSlotId === 'nadir_extremo_arquitectonico') {
            return trim(<<<TEXT
ARTWORK VISUAL DOMINANCE POLICY - EXTREME NADIR CLOSER

This is still an extreme low architectural camera, but the lens must be closer to the artwork wall so the artwork remains a major visual anchor rather than a small distant object.

Resolve artwork presence through photographic zoom from the selected nadir logic, not by changing object scale. The room may keep strong perspective, foreground floor, vertical architecture, and world mother identity, but the camera must not retreat so far that floor, props, or windows dominate the image.

Use a compositional zoom from the same low viewpoint: keep the lens near the floor, keep the dramatic upward/diagonal perspective, but reduce excessive empty floor distance, ceiling spread, and distant room breadth. The artwork should feel close enough to inspect, not merely installed in the background.

Do not enlarge the artwork physically or break scale. The artwork can become more visually important only through camera position, crop, framing, or focal-length compression.
TEXT);
        }

        if (isset(self::HIGH_DOMINANCE_SLOTS[$cameraSlotId])) {
            return trim(<<<TEXT
ARTWORK VISUAL DOMINANCE POLICY - HIGH

The artwork must be the clear primary subject of the image. Compose the camera so the real physical artwork occupies a strong share of the visible frame through camera proximity, framing, and subject placement, without changing its true physical scale.

Resolve dominance as a photographer would: move closer, crop tighter, choose a nearer wall section, reduce empty room spread, or use a slightly longer focal length while preserving the selected camera slot. It may be partially cropped only if the selected camera slot naturally requires it.

The world mother environment remains important, but studio props, windows, floor area, furniture, easels, tables, and empty walls must support the artwork rather than become the main subject.

Do not enlarge the artwork physically, do not make it monumental, and do not violate the ARTWORK SCALE POLICY. The artwork can occupy more of the final frame only because the camera is closer, the crop is tighter, or the lens compresses the view.
TEXT);
        }

        if (isset(self::MEDIUM_DOMINANCE_SLOTS[$cameraSlotId])) {
            return trim(<<<TEXT
ARTWORK VISUAL DOMINANCE POLICY - MEDIUM

The artwork must remain one of the main visual anchors of the image, not a small object lost inside the atelier. Keep the selected camera slot geometry, but compose close enough that the artwork is immediately readable and visually important.

Resolve this through photographic proximity, crop, framing, and focal length, not through physical enlargement. Strong perspective, low viewpoint, and room depth are allowed, but they must not reduce the artwork to a minor background detail.

The world mother environment may carry atmosphere and depth, but props, windows, floor, furniture, and architectural drama must not overpower the artwork.

Do not enlarge the artwork physically or break scale. The artwork can become more visually important only because the camera is closer, the crop is tighter, or the view is composed around the real object.
TEXT);
        }

        if (isset(self::ENVIRONMENT_DOMINANCE_SLOTS[$cameraSlotId])) {
            return trim(<<<TEXT
ARTWORK VISUAL DOMINANCE POLICY - ENVIRONMENTAL

This selected camera slot is allowed to show more room architecture and world mother environment than artwork-dominant slots. However, the artwork must still remain clearly readable and intentionally placed, not tiny, accidental, or visually lost.

When a large room makes the artwork read too small, solve it through a photographer's zoom strategy: choose a closer camera position, tighter crop, better wall section, or focal-length compression from the selected camera viewpoint. Preserve the architecture and world mother identity, but keep the artwork legible as the reason for the image.

Do not enlarge the artwork physically or break scale. Use camera composition, wall choice, crop, and framing to keep the artwork present while preserving the environmental camera.
TEXT);
        }

        return '';
    }
}
