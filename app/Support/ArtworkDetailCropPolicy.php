<?php
declare(strict_types=1);

final class ArtworkDetailCropPolicy
{
    /**
     * @var array<string,bool>
     */
    private const DETAIL_CROP_SLOTS = [
        'detalle_textura_lienzo' => true,
        'borde_canvas_closeup' => true,
        'esquina_obra_perspectiva_extrema' => true,
        'rasante_superficie_pintura' => true,
    ];

    public static function promptBlock(string $cameraSlotId, string $orientation): string
    {
        if (!isset(self::DETAIL_CROP_SLOTS[$cameraSlotId])) {
            return '';
        }

        $orientationRule = match ($orientation) {
            'portrait' => 'For portrait artwork, the visible area must read as a cropped window onto a taller canvas that continues beyond the camera frame. Do not shorten the artwork, compress its height, widen it, square it, or show it as a complete low panel just to fit the detail view.',
            'landscape' => 'For landscape artwork, the visible area must read as a cropped window onto a wider canvas that continues beyond the camera frame. Do not narrow the artwork, compress its width, make it portrait, square it, or show it as a complete compact panel just to fit the detail view.',
            default => 'For square artwork, the visible area must read as a cropped window onto the original square canvas. Do not stretch, compress, or reformat it just to fit the detail view.',
        };

        return trim(<<<TEXT
ARTWORK DETAIL CROP POLICY

Detail camera slots are allowed to crop the camera frame, not the physical artwork. The artwork may continue outside the final image boundary. Do not scale down, shorten, squash, reformat, or redesign the artwork so the whole object fits inside a close-up detail image.

{$orientationRule}

If the camera is close to an edge, corner, or surface, show only the needed physical slice. It is better for the artwork to leave the frame than for the artwork to become a smaller or differently proportioned object.

Top, bottom, left, or right artwork boundaries may be outside the generated image. Missing boundaries must be understood as camera crop, not as a shorter artwork.
TEXT);
    }
}
