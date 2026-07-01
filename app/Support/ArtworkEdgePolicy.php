<?php
declare(strict_types=1);

final class ArtworkEdgePolicy
{
    /**
     * @var array<string,bool>
     */
    private const EDGE_VISIBLE_SLOTS = [
        'borde_canvas_closeup' => true,
        'esquina_obra_perspectiva_extrema' => true,
        'rasante_superficie_pintura' => true,
    ];

    public static function appliesToCameraSlot(string $cameraSlotId): bool
    {
        return isset(self::EDGE_VISIBLE_SLOTS[$cameraSlotId]);
    }

    public static function promptBlock(string $cameraSlotId): string
    {
        if (!self::appliesToCameraSlot($cameraSlotId)) {
            return '';
        }

        return trim(<<<TEXT
ARTWORK PAINTED EDGE POLICY

When a canvas side edge, corner, lateral depth, or stretcher thickness is visible, that visible edge must be painted as a physical continuation of the artwork.

The side edge must carry wrapped color, pigment, texture, brush rhythm, marks, and material energy from the nearest front-face area. It should feel like the painting continues around the canvas depth, not like a blank neutral canvas edge.

Do not show raw beige canvas, unpainted fabric, white primer, bare wood, exposed stretcher bars, a blank side band, or a generic neutral lateral strip unless the source artwork itself explicitly shows that kind of edge.

The edge continuation must remain local and coherent: extend nearby color fields and texture around the side, but do not invent a new composition, new symbols, new figures, new text, or unrelated imagery on the side edge.

The front face remains the identity authority. The edge is a wrapped physical continuation of the nearest visible artwork surface, not a separate painting.
TEXT);
    }
}
