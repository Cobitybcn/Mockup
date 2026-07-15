<?php
declare(strict_types=1);

final class ArtworkPhysicalIntegrityPolicy
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
        'camara_15_contrapicado_inpainting' => true,
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

    /**
     * @var array<string,bool>
     */
    private const DETAIL_CROP_SLOTS = [
        'detalle_textura_lienzo' => true,
        'borde_canvas_closeup' => true,
        'esquina_obra_perspectiva_extrema' => true,
        'rasante_superficie_pintura' => true,
    ];

    /**
     * @var array<string,bool>
     */
    private const EDGE_VISIBLE_SLOTS = [
        'borde_canvas_closeup' => true,
        'esquina_obra_perspectiva_extrema' => true,
        'rasante_superficie_pintura' => true,
    ];

    public static function promptBlock(
        float $widthCm,
        float $heightCm,
        float $depthCm,
        string $orientation,
        string $cameraSlotId
    ): string {
        $blocks = [
            self::basePhysicalContract($widthCm, $heightCm, $depthCm, $orientation),
            self::environmentalScaleReasoningBlock(),
            self::visualFidelityContract(),
        ];

        $dominance = self::dominanceBlock($cameraSlotId);
        if ($dominance !== '') {
            $blocks[] = $dominance;
        }

        $edge = self::paintedEdgeBlock($cameraSlotId);
        if ($edge !== '') {
            $blocks[] = $edge;
        }

        $detailCrop = self::detailCropBlock($cameraSlotId, $orientation);
        if ($detailCrop !== '') {
            $blocks[] = $detailCrop;
        }

        $slotOverride = self::slotOverrideBlock($cameraSlotId, $orientation);
        if ($slotOverride !== '') {
            $blocks[] = $slotOverride;
        }

        return implode("\n\n", array_map('trim', $blocks));
    }

    private static function visualFidelityContract(): string
    {
        return trim(<<<TEXT
ROOT ARTWORK VISUAL FIDELITY POLICY

The supplied root artwork image is visual evidence, not an idea to reinterpret. Preserve the visible artwork arrangement exactly: color-field placement, empty areas, mark count, mark location, mark scale, edge relationships, texture density, surface rhythm, and local imperfections.

Do not reconstruct the artwork from text, memory, style, genre, or environmental mood. The mockup must contain the same artwork object from the supplied image, not a newly painted approximation.

For sparse, minimal, abstract, geometric, or gestural artworks, empty fields must stay empty, simple marks must stay simple, and the original composition must not be filled with additional strokes, relief, cracks, drips, symbols, patterns, figures, or decorative noise.

The world mother or room context must never donate brushwork, colors, surface texture, symbols, objects, or compositional changes to the artwork face. If a close-up or edge view shows texture, it must be the root artwork's own material texture, not an invented heavier impasto or generic canvas grain.
TEXT);
    }

    public static function environmentalScaleReasoningBlock(): string
    {
        return trim(<<<TEXT
ENVIRONMENTAL SCALE REASONING POLICY

Before placing the artwork, infer the physical scale of IMAGE 2 from the visible environment. Use whatever scale evidence is naturally available in the scene: architectural modules, openings, repeated surface units, furniture, fixtures, objects, floor/wall junctions, shadows, spatial depth, human-scale traces, and material patterns.

Do not rely on a single clue. Cross-check the artwork size against at least two or three independent visual anchors whenever the scene provides them. The installed artwork must harmonize with both the supplied artwork dimensions and the inferred scale of the world mother environment.

Treat IMAGE 2 as scale evidence, not only as style evidence. Its objects, room proportions, openings, material units, and spatial distances must constrain the artwork's physical footprint. Empty wall area is not permission to enlarge the artwork.

The artwork may become visually prominent through camera position, crop, lens choice, perspective, lighting, and composition, but its physical size must remain consistent with the surrounding environmental anchors.
TEXT);
    }

    private static function basePhysicalContract(float $widthCm, float $heightCm, float $depthCm, string $orientation): string
    {
        $longest = max($widthCm, $heightCm);
        $class = self::sizeClass($longest);
        $doorRule = $heightCm > 0 && $heightCm < 205.0
            ? 'The artwork height must read below a typical 205 cm interior door when a door or comparable architectural height is visible.'
            : 'Use the supplied physical height as the only size source; do not invent a larger display scale.';

        $readabilityRule = in_array($class, ['M', 'L'], true)
            ? 'If an extreme camera angle or a very large room makes the artwork less dominant at true scale, keep the true scale and allow the room/camera composition to carry more of the image. Do not enlarge the artwork just to keep it visually dominant.'
            : 'The artwork may have strong presence, but only according to its supplied physical dimensions.';

        $relationalScaleRule = self::relationalScaleRule($class, $widthCm, $heightCm);
        $aspectRatioRule = self::aspectRatioRule($orientation);

        return trim(<<<TEXT
ARTWORK PHYSICAL INTEGRITY POLICY

This is the single authority for the artwork's physical truth: scale, size class, orientation, aspect ratio, canvas/object depth, crop behavior, visible edge behavior, and how the artwork may become visually present. Camera slots may control viewpoint, lens, tilt, rotation, crop, and composition, but they must not redefine artwork scale, proportions, orientation, or identity.

Physical artwork size metadata: {$widthCm} cm wide x {$heightCm} cm high x {$depthCm} cm deep.
Resolved size class: {$class}.
Resolved orientation: {$orientation}.

Root artwork geometry is absolute. Never stretch, squeeze, square, rotate to a different format, extend, repaint, recompose, or change the root artwork aspect ratio to make it fit the camera frame. If the selected camera is close, oblique, or cropped, crop the camera frame around the physical artwork; do not alter the physical artwork.

{$aspectRatioRule}

Keep the artwork at its real physical size inside the room. Do not reinterpret it as XL, monumental, billboard-sized, wall-filling, architectural panel-sized, stage-prop-sized, or mural-scale unless these supplied dimensions truly support that reading.

All measurements in this block are hidden generation metadata only. Never render measurement text, number labels, dimension callouts, captions, arrows, rulers, scale bars, or visible unit labels in the image. Do not write labels such as units, width, height, depth, or numeric size annotations on or near the artwork.

{$doorRule}
{$readabilityRule}
{$relationalScaleRule}

Scale must be inferred from nearby architecture, furniture, floor/wall junctions, baseboards, outlets, door edges, floor planks or seams, windows, ceiling height, and installation contact. These references must adapt to the artwork's real size; the artwork must not be enlarged to satisfy camera drama.
TEXT);
    }

    private static function aspectRatioRule(string $orientation): string
    {
        return match ($orientation) {
            'portrait' => 'For portrait artwork, every visible fragment must still read as part of a taller-than-wide physical artwork. Do not shorten it, widen it, square it, or complete a close-up fragment into a different format.',
            'landscape' => 'For landscape artwork, every visible fragment must still read as part of a wider-than-tall physical artwork. Do not narrow it, make it portrait, square it, or complete a close-up fragment into a different format.',
            default => 'For square artwork, every visible fragment must still read as part of the original square physical artwork. Do not stretch it into portrait or landscape.',
        };
    }

    private static function relationalScaleRule(string $class, float $widthCm, float $heightCm): string
    {
        $sizeLine = "Use these exact supplied dimensions for all visual comparisons: {$widthCm} cm wide x {$heightCm} cm high.";

        if ($class === 'unknown') {
            return trim(<<<TEXT
{$sizeLine}
Because the size class is unknown, avoid monumental assumptions. Include nearby scale anchors and keep the artwork believable as a physical art object rather than an architectural surface.
TEXT);
        }

        if ($class === 'M') {
            return trim(<<<TEXT
{$sizeLine}
This is an M format artwork. It must read clearly smaller than major furniture, doors, large windows, concrete panels, stairs, mezzanines, and high ceiling structures. Keep at least one nearby scale anchor close to the artwork, such as a baseboard, outlet, chair, side table, shelf edge, door trim, floor seam, or visible hand-scale detail.
TEXT);
        }

        if ($class === 'L') {
            return trim(<<<TEXT
{$sizeLine}
This is an L format movable domestic/gallery artwork, not a mural, billboard, room divider, or architectural panel. It may be clearly framed through camera proximity and crop, but its physical size must remain believable against large loft architecture. It should read smaller than major seating groups, tall windows, concrete wall bays, stairs, mezzanines, and double-height wall sections unless the supplied dimensions truly support that comparison. Keep at least one nearby scale anchor close to the artwork, such as a baseboard, outlet, chair, low table, plant pot, door edge, floor seam, concrete panel joint, or furniture edge.
TEXT);
        }

        if ($class === 'XL') {
            return trim(<<<TEXT
{$sizeLine}
This is an XL format artwork, but still a physical art object with finite supplied dimensions. It may dominate a local wall section, yet it must remain correctly scaled against doors, furniture, windows, wall panels, floor/wall junctions, and ceiling height. Do not turn it into a wall-sized architectural surface unless the supplied dimensions truly support that. Include nearby scale anchors whenever the room is large or industrial.
TEXT);
        }

        return trim(<<<TEXT
{$sizeLine}
This is a Monumental or XXL artwork according to its supplied dimensions. It may read monumental only to the degree those dimensions justify. Even at this scale, preserve credible comparison against doors, people, furniture, windows, wall panels, floor/wall junctions, and ceiling height. Do not inflate beyond the supplied measurements.
TEXT);
    }

    private static function dominanceBlock(string $cameraSlotId): string
    {
        if ($cameraSlotId === 'obra_apoyada_suelo_7_8') {
            return trim(<<<TEXT
ARTWORK PRESENCE POLICY - FLOOR LEANING CLOSE

Treat this floor-leaning artwork view as a close product photograph of the artwork leaning against a wall or stable support. The artwork, its painted face, side depth, bottom edge, contact shadow, texture, and slight backward angle are the subject.

Use a close-up or medium-close camera distance from the selected camera viewpoint. Show only the minimum surrounding floor, wall, or support needed to prove that the artwork is physically leaning and grounded.

Include at least one nearby real-scale anchor close to the artwork: baseboard, floor/wall junction, outlet, door trim, floor seam, low table edge, chair leg, plant pot, concrete panel joint, or comparable detail. The anchor must confirm the size defined by ARTWORK PHYSICAL INTEGRITY POLICY and must not make the artwork read as a wall panel or room divider.

Do not compose this as a full-room interior view. Avoid wide empty architecture, distant furniture groups, broad ceiling, broad floor spread, symmetrical room framing, or deep room context. If the world mother environment appears, it should appear as cropped background, peripheral material cues, or subtle atmosphere around the artwork.

Do not enlarge the artwork physically, do not make it monumental, and do not violate the ARTWORK PHYSICAL INTEGRITY POLICY. The artwork can occupy more of the final frame only because the camera is closer, the crop is tighter, or the lens compresses the view.
TEXT);
        }

        if ($cameraSlotId === 'nadir_extremo_arquitectonico') {
            return trim(<<<TEXT
ARTWORK PRESENCE POLICY - EXTREME NADIR CLOSER

This is still an extreme low architectural camera, but the lens must be closer to the artwork wall so the artwork remains legible and intentionally framed at its supplied physical scale rather than becoming a small distant object.

Resolve artwork presence through photographic zoom from the selected nadir logic, not by changing object scale. The room may keep strong perspective, foreground floor, vertical architecture, and world mother identity, but the camera must not retreat so far that floor, props, or windows dominate the image.

Use a compositional zoom from the same low viewpoint: keep the lens near the floor, keep the dramatic upward/diagonal perspective, but reduce excessive empty floor distance, ceiling spread, and distant room breadth. The artwork should feel close enough to inspect, not merely installed in the background.

Extreme perspective may make the artwork visually dramatic, but nearby scale anchors must still confirm its real physical dimensions: floor/wall junction, baseboard, outlet, door edge, floor seam, concrete panel joint, chair or low furniture edge, radiator, or similar human-scale detail. Large loft elements such as windows, columns, mezzanines, and ceiling structure must remain visibly larger than any artwork whose supplied dimensions are smaller than those elements.

Do not enlarge the artwork physically or break scale. The artwork can become more present and readable only through camera position, crop, framing, or focal-length compression.
TEXT);
        }

        if (isset(self::HIGH_DOMINANCE_SLOTS[$cameraSlotId])) {
            return trim(<<<TEXT
ARTWORK PRESENCE POLICY - HIGH

The artwork must be the clear intended subject of the image, but never by becoming physically oversized. Compose the camera so the real physical artwork is placed, lit, and framed with intention while keeping its true physical scale.

Resolve dominance as a photographer would: move closer, crop tighter, choose a nearer wall section, reduce empty room spread, or use a slightly longer focal length while preserving the selected camera slot. It may be partially cropped only if the selected camera slot naturally requires it. If true scale makes the artwork occupy a modest share of a large loft wall, accept that physical truth and make the image strong through placement, light, wall choice, and camera rhythm.

Use nearby relational scale anchors when the setting contains oversized architecture, especially baseboards, outlets, door edges, furniture edges, floor seams, wall panel joints, or installation contact shadows close to the artwork.

The world mother environment remains important, but studio props, windows, floor area, furniture, easels, tables, and empty walls must support the artwork rather than become the main subject.

Do not enlarge the artwork physically, do not make it monumental, and do not violate the ARTWORK PHYSICAL INTEGRITY POLICY. The artwork can occupy more of the final frame only because the camera is closer, the crop is tighter, or the lens compresses the view.
TEXT);
        }

        if (isset(self::MEDIUM_DOMINANCE_SLOTS[$cameraSlotId])) {
            return trim(<<<TEXT
ARTWORK PRESENCE POLICY - MEDIUM

The artwork must remain intentionally placed and readable, but it does not need to be one of the largest objects in the room. Keep the selected camera slot geometry and preserve true physical scale even when the artwork occupies a modest portion of a large loft, studio, or collector interior.

Resolve presence through photographic proximity, crop, framing, light, wall choice, and focal length, not through physical enlargement. Strong perspective, low viewpoint, and room depth are allowed. If true scale makes the artwork smaller than the sofa, windows, concrete panels, stairs, or ceiling structure, that is correct.

Use nearby relational scale anchors when the setting contains oversized architecture, especially baseboards, outlets, door edges, furniture edges, floor seams, wall panel joints, or installation contact shadows close to the artwork.

The world mother environment may carry atmosphere and depth. Props, windows, floor, furniture, and architectural drama may be visible and expressive as long as the artwork remains clearly installed and intentionally photographed at its true physical scale.

Do not enlarge the artwork physically or break scale. The artwork can become more present and readable only because the camera is closer, the crop is tighter, or the view is composed around the real object.
TEXT);
        }

        if (isset(self::ENVIRONMENT_DOMINANCE_SLOTS[$cameraSlotId])) {
            return trim(<<<TEXT
ARTWORK PRESENCE POLICY - ENVIRONMENTAL

This selected camera slot is allowed to show more room architecture and world mother environment than artwork-dominant slots. However, the artwork must still remain clearly readable and intentionally placed, not tiny, accidental, or visually lost.

When a large room makes the artwork read too small, solve it through a photographer's zoom strategy: choose a closer camera position, tighter crop, better wall section, or focal-length compression from the selected camera viewpoint. Preserve the architecture and world mother identity, but keep the artwork legible as the reason for the image.

Use at least one nearby relational scale anchor close to the artwork when possible: baseboard, outlet, door edge, furniture edge, floor seam, wall panel joint, radiator, chair, low table, plant pot, or clear contact shadow. These anchors must support the ARTWORK PHYSICAL INTEGRITY POLICY rather than making the artwork read as a mural or architectural panel.

Do not enlarge the artwork physically or break scale. Use camera composition, wall choice, crop, and framing to keep the artwork present while preserving the environmental camera.
TEXT);
        }

        return '';
    }

    private static function paintedEdgeBlock(string $cameraSlotId): string
    {
        if (!isset(self::EDGE_VISIBLE_SLOTS[$cameraSlotId])) {
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

    private static function detailCropBlock(string $cameraSlotId, string $orientation): string
    {
        if (!isset(self::DETAIL_CROP_SLOTS[$cameraSlotId])) {
            return '';
        }

        $orientationRule = self::aspectRatioRule($orientation);

        return trim(<<<TEXT
ARTWORK DETAIL CROP POLICY

Detail camera slots are allowed to crop the camera frame, not the physical artwork. The artwork may continue outside the final image boundary. Do not scale down, shorten, squash, reformat, or redesign the artwork so the whole object fits inside a close-up detail image.

{$orientationRule}

If the camera is close to an edge, corner, or surface, show only the needed physical slice. It is better for the artwork to leave the frame than for the artwork to become a smaller or differently proportioned object.

Top, bottom, left, or right artwork boundaries may be outside the generated image. Missing boundaries must be understood as camera crop, not as a shorter artwork.
TEXT);
    }

    private static function slotOverrideBlock(string $cameraSlotId, string $orientation): string
    {
        if (isset(self::DETAIL_CROP_SLOTS[$cameraSlotId])) {
            $slotRule = $cameraSlotId === 'borde_canvas_closeup'
                ? 'For Borde de Canvas Close-up, prioritize the physical side edge, canvas thickness, wall contact, cast shadow, and a faithful partial slice of the painted face. The whole artwork should usually not be visible.'
                : 'For this material-detail camera slot, prioritize a faithful physical fragment of the artwork surface over showing the whole artwork.';

            return trim(<<<TEXT
SELECTED DETAIL CAMERA OVERRIDE

This selected camera slot intentionally allows camera-frame cropping of the artwork. This overrides generic "do not crop", "no cropped artwork", and "show the whole artwork" instructions only for photographic framing.

Do not crop, resize, repaint, extend, redesign, or alter the artwork itself. The central ARTWORK PHYSICAL INTEGRITY POLICY owns the true physical size, orientation ({$orientation}), aspect ratio, and scale; this detail camera only controls photographic framing of a faithful fragment.

{$slotRule}
TEXT);
        }

        if ($cameraSlotId === 'obra_apoyada_suelo_7_8') {
            return trim(<<<TEXT
SELECTED FLOOR-LEANING ARTWORK OVERRIDE

This selected camera slot requires a real leaning artwork installation. The artwork is not hanging and not wall-mounted. It may lean against a real wall or against a real stable support object when that object could plausibly hold a canvas in an atelier, studio, storage room, or collector preview.

Place the real physical artwork with believable gravity: its bottom edge must rest on the real floor or on a clearly stable low support surface, and its back upper edge must lean gently against a wall or load-bearing object at about 5-12 degrees. The floor/support/wall relationship must be physically legible through contact shadows, grounded bottom contact, and coherent perspective.

The central ARTWORK PHYSICAL INTEGRITY POLICY owns physical size, orientation ({$orientation}), aspect ratio, and scale. This floor-leaning override only controls installation physics, contact, support, and gravity. It must not reinterpret the artwork as a monumental billboard, room divider, oversized slab, stage prop, or architectural panel.

Do not invent a giant plinth, oversized display block, impossible platform, or arbitrary support just to hold the artwork. If the artwork leans on an object, the object must be real, stable, correctly scaled, visually connected to the floor, and coherent with the room; the artwork contact point must be visible or strongly implied.
TEXT);
        }

        return '';
    }

    public static function sizeClass(float $longestSideCm): string
    {
        if ($longestSideCm <= 0) {
            return 'unknown';
        }
        if ($longestSideCm <= 40.0) {
            return 'M';
        }
        if ($longestSideCm <= 80.0) {
            return 'L';
        }
        if ($longestSideCm <= 150.0) {
            return 'XL';
        }
        if ($longestSideCm <= 250.0) {
            return 'Monumental';
        }

        return 'Monumental_or_XXL';
    }
}
