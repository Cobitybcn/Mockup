<?php
declare(strict_types=1);

final class ArtworkScalePolicy
{
    public static function promptBlock(float $widthCm, float $heightCm, float $depthCm, string $orientation): string
    {
        $longest = max($widthCm, $heightCm);
        $class = self::sizeClass($longest);
        $doorRule = $heightCm > 0 && $heightCm < 205.0
            ? 'The artwork height must read below a typical 205 cm interior door when a door or comparable architectural height is visible.'
            : 'Use the supplied physical height as the only size source; do not invent a larger display scale.';

        $readabilityRule = in_array($class, ['small', 'medium'], true)
            ? 'If an extreme camera angle makes the artwork less dominant at true scale, keep the true scale and allow the room/camera composition to carry more of the image. Do not enlarge the artwork just to keep it visually dominant.'
            : 'The artwork may have strong presence, but only according to its supplied physical dimensions.';

        return trim(<<<TEXT
ARTWORK SCALE POLICY

This block is the only authority for artwork size. Camera slots may control viewpoint, lens, tilt, rotation, crop, and composition, but they must not redefine artwork scale.

Physical artwork size: {$widthCm} cm wide x {$heightCm} cm high x {$depthCm} cm deep.
Resolved size class: {$class}.
Resolved orientation: {$orientation}.

Keep the artwork at its real physical size inside the room. Do not reinterpret it as XL, monumental, billboard-sized, wall-filling, architectural panel-sized, stage-prop-sized, or mural-scale unless these supplied dimensions truly support that reading.

{$doorRule}
{$readabilityRule}

Scale must be inferred from architecture, furniture, floor/wall junctions, windows, ceiling height, and installation contact. These references must adapt to the artwork's real size; the artwork must not be enlarged to satisfy camera drama.
TEXT);
    }

    public static function sizeClass(float $longestSideCm): string
    {
        if ($longestSideCm <= 0) {
            return 'unknown';
        }
        if ($longestSideCm < 90.0) {
            return 'small';
        }
        if ($longestSideCm <= 140.0) {
            return 'medium';
        }
        if ($longestSideCm <= 190.0) {
            return 'large';
        }

        return 'xl_or_oversize';
    }
}
