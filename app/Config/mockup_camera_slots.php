<?php
declare(strict_types=1);

$cameraSlotsConfig = [
    'default_slot_set_id' => 'phase_2_4_default_v1',
    'sets' => [
        'phase_2_4_default_v1' => [
            'set_name' => 'FASE 2.4 default camera slot set',
            'slots' => [
                'contrapicado_raton_puro',
                'contrapicado_7_8',
                'reflejo_dorado_tarde_palazzo',
                'vista_aerea_contexto_ventanas',
                'pasillo_obra_descentrada_proxima',
                'borgona_recovecos_3_4_loft_hormigon',
            ],
        ],
        'phase_2_6_experimental_v1' => [
            'set_name' => 'FASE 2.6 experimental camera composition set',
            'slots' => [
                'nadir_extremo_arquitectonico',
                'obra_apoyada_suelo_7_8',
                'diagonal_estudio_moderno',
                'luz_dorada_sombra_diagonal',
            ],
        ],
        'phase_2_6_artistic_detail_v1' => [
            'set_name' => 'FASE 2.6 artistic physical artwork detail set',
            'slots' => [
                'detalle_textura_lienzo',
                'borde_canvas_closeup',
                'esquina_obra_perspectiva_extrema',
                'rasante_superficie_pintura',
            ],
        ],
    ],
    'slots' => [
        'detalle_textura_lienzo' => [
            'slot_id' => 'detalle_textura_lienzo',
            'slot_name' => 'Detalle de Textura del Lienzo',
            'enabled' => true,
            'fidelity_mode' => 'artistic_optical_distortion',
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera is very close to the artwork surface, using a close-up or semi-macro viewpoint focused on the physical painted skin of the canvas rather than the full room.',
            'lens_block' => 'Use a macro or close-focus lens equivalent with natural optical rendering, enough detail resolution to show the existing pigment, canvas weave, brushwork, veladuras, or surface marks visible in the root artwork without making the artwork look digital.',
            'vertical_tilt_block' => 'Use only the vertical tilt needed to reveal the existing material texture already present in the root artwork. Full artwork readability is not required; selected texture areas must remain physically believable.',
            'lateral_rotation_block' => 'Use a close material angle across the painting surface, allowing partial cropping and shallow spatial depth while preserving the visible artwork identity.',
            'composition_block' => 'Partial cropping is allowed and expected. Close-up material framing and mild optical distortion from the close lens are allowed. The canvas or painted surface may fill most of the frame, showing only a fragment of the artwork. The purpose is to document the real surface already visible in the root artwork: pigment, brush marks, canvas weave, and existing physical texture. Do not add artificial impasto, invented relief, cracks, incisions, extra ridges, or new tactile marks.',
            'human_subject_block' => '',
            'scale_block' => 'Material scale protection: the visible surface remains a real physical artwork, not a poster, flat print, digital screen, or decorative texture. Artwork identity protection: preserve the real colors, visible marks, existing surface texture, and abstract visual identity of the provided artwork. Anti-substitution protection: do not invent new marks, artificial impasto, extra relief, figures, symbols, faces, landscapes, decorative motifs, or another painting.',
            'depth_of_field_block' => 'Shallow depth of field is allowed, but the selected artwork texture area must remain sharp and legible. Any blur must fall only outside the chosen focus plane and must feel optical and photographic.',
            'scene_affinity' => ['macro_texture', 'canvas_surface', 'material_detail', 'pigment', 'brushwork'],
            'negative_directives' => ['no poster', 'no flat print', 'no digital screen', 'no invented imagery', 'no artwork substitution', 'no plastic surface', 'no pets', 'no crowds', 'no children'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium photographic mockup using this exact camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

INPUT ROLES:
- IMAGE 1 is the root artwork. Treat it as visual evidence that must be preserved.
- IMAGE 2, if provided, may only inform a very neutral immediate studio surface or wall tone. Do not import its atmosphere, room layout, furniture, windows, dramatic light, props, architectural mood, or storytelling.

ARTWORK PHYSICAL DATA:
- Width: {{ARTWORK_WIDTH_CM}} cm
- Height: {{ARTWORK_HEIGHT_CM}} cm
- Depth: supplied physical canvas/object depth metadata only; do not render as visible text
- Orientation: {{ARTWORK_ORIENTATION}}

TASK:
Create a close-up or semi-macro photographic view of the physical painted surface of IMAGE 1. The canvas or painted surface may fill most of the frame. A partial crop is expected because this slot is about material detail, not a full-room mockup.

CAMERA:
Use a macro or close-focus lens with natural optical rendering. The viewpoint is very close to the artwork surface, with only the tilt needed to reveal existing pigment, canvas weave, brushwork, veladuras, or visible surface marks already present in IMAGE 1. Shallow depth of field is allowed, but the selected artwork texture area must remain sharp and legible.

STRICT ARTWORK FIDELITY:
Preserve the visible colors, mark placement, sparse areas, abstract identity, local composition, and existing surface character from IMAGE 1. Do not redraw, reinterpret, beautify, simplify, complete, recompose, recolor, or replace the artwork. Do not invent symbols, figures, faces, landscapes, decorative motifs, new lines, new color blocks, or another painting.

MATERIAL LIMITS:
Show only texture that is already visually supported by IMAGE 1. Do not add artificial impasto, invented relief, cracks, scratches, incisions, extra ridges, wet paint, glossy plastic shine, embossed effects, heavy paste, or new tactile marks. The result must still feel like the same real artwork photographed closely, not a newly textured version.

ATMOSPHERE LIMITS:
No atmospheric scene-building. No dramatic atelier mood. No cinematic haze. No golden-room storytelling. No full-room context. No visible furniture, easels, shelves, table setups, large windows, decorative props, people, pets, or architectural narrative. Background, if any is visible, must stay minimal, peripheral, soft, and secondary.

SCALE AND CROP:
The artwork remains a real physical canvas/object at the supplied dimensions. Cropping is camera cropping only, not artwork alteration. The visible fragment must still read as part of the original artwork surface, with plausible canvas scale and photographic optics.

NEGATIVE PROMPT:
No poster, no flat print, no digital screen, no invented imagery, no artwork substitution, no artificial impasto, no invented relief, no cracks, no scratches, no incisions, no extra ridges, no wet paint, no glossy plastic shine, no embossed effects, no heavy paste, no new tactile marks, no room atmosphere, no full room, no furniture, no easel, no table setup, no shelves, no large windows, no window-dominant composition, no dramatic atelier mood, no cinematic haze, no golden-room storytelling, no people, no pets, no crowds, no children, no visible text, no logos, no watermarks.
PROMPT,
        ],
        'borde_canvas_closeup' => [
            'slot_id' => 'borde_canvas_closeup',
            'slot_name' => 'Borde de Canvas Close-up',
            'enabled' => true,
            'fidelity_mode' => 'artistic_optical_distortion',
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera is close to the wall-mounted canvas edge, positioned at artwork height or slightly oblique to read the front face, lateral side, thickness, and shadow against the nearby wall.',
            'lens_block' => 'Use a close-focus 50-70 mm lens equivalent or similar natural close-up lens that preserves plausible canvas thickness and avoids wide-angle exaggeration.',
            'vertical_tilt_block' => 'Keep vertical tilt controlled and material-oriented, enough to reveal edge depth and wall contact without making the canvas float or bend.',
            'lateral_rotation_block' => 'Use a close side-oblique angle across the canvas edge so the transition between painted front, lateral canvas depth, stretcher thickness, and wall shadow is visible.',
            'composition_block' => 'Partial artwork view is allowed and expected. This is a camera crop, not an artwork alteration. The full artwork does not need to be visible, and one or more artwork edges may fall outside the final image frame. Close-up optical distortion and mild foreshortening are allowed if they come from the physical lens and edge perspective. The canvas edge and thickness are the subject. The artwork may be cropped by the camera frame, with wall texture, lateral side, subtle cast shadow, and the physical transition from front surface to side edge carrying the composition. Preserve the original artwork format even when only a fragment is visible: a portrait artwork must still read as a fragment of a taller-than-wide canvas, a landscape artwork as a fragment of a wider-than-tall canvas, and a square artwork as square. Do not complete, widen, compress, or redesign the visible fragment into a different full-format painting.',
            'human_subject_block' => '',
            'scale_block' => 'Physical canvas protection: edge thickness must remain moderate and plausible for the real artwork depth; it must look like stretched canvas, not poster, paper, screen, floating panel, or exaggerated block. Artwork proportion protection: the physical canvas keeps the source artwork aspect ratio and orientation even if only a partial close-up is shown. Artwork identity protection: preserve exact artwork identity on the visible portion. Anti-substitution protection: do not replace the artwork with another image.',
            'depth_of_field_block' => 'Use close-up photographic depth of field. Canvas edge, visible artwork portion, side depth, and immediate wall contact should remain sharp; distant wall texture may soften gently.',
            'scene_affinity' => ['canvas_edge', 'wall_shadow', 'closeup', 'stretched_canvas', 'material_depth'],
            'negative_directives' => ['no poster', 'no flat print', 'no digital screen', 'no floating panel', 'no exaggerated thickness', 'no artwork substitution', 'no pets', 'no crowds', 'no children'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium photographic mockup using this exact camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

INPUT ROLES:
- IMAGE 1 is the root artwork. Treat it as visual evidence that must be preserved.
- IMAGE 2, if provided, may only inform a very neutral immediate wall tone or peripheral surface color. Do not import its atmosphere, room layout, furniture, windows, dramatic light, props, architectural mood, or storytelling.

ARTWORK PHYSICAL DATA:
- Width: {{ARTWORK_WIDTH_CM}} cm
- Height: {{ARTWORK_HEIGHT_CM}} cm
- Depth: supplied physical canvas/object depth metadata only; use it only to keep canvas thickness plausible and never render it as visible text
- Orientation: {{ARTWORK_ORIENTATION}}

TASK:
Create a close-up photographic view of the physical canvas edge of IMAGE 1. The subject is the transition between the painted front face, the lateral side depth, the canvas thickness, the nearby wall contact, and the subtle cast shadow. A partial crop is expected. This is not a full-room mockup.

CAMERA:
Use a close-focus 50-70 mm lens equivalent, or a natural close-up lens with controlled perspective. The camera is near artwork height and slightly side-oblique, close enough to read the physical edge and wall contact. Keep the canvas edge, front-face fragment, lateral side, thickness, and immediate wall contact sharp. Distant background may soften gently, but it must not become the subject.

STRICT ARTWORK FIDELITY:
Preserve the visible portion of IMAGE 1 exactly: colors, marks, sparse areas, local composition, edge relationships, and format logic. Do not redraw, reinterpret, beautify, simplify, complete, recompose, recolor, mirror, rotate, stretch, compress, or replace the artwork. Do not invent symbols, figures, faces, landscapes, decorative motifs, new lines, new color blocks, or another painting.

CANVAS EDGE AND SCALE LIMITS:
The visible canvas remains a rigid physical object at the supplied dimensions. The edge thickness must be moderate and plausible for a stretched canvas or physical artwork object; do not turn it into a thick block, slab, plinth, box, wedge, floating panel, poster, paper sheet, digital screen, or framed object. Preserve the original artwork orientation and aspect ratio even when only a fragment is visible: a portrait artwork must still read as part of a taller-than-wide canvas, a landscape artwork as part of a wider-than-tall canvas, and a square artwork as part of a square canvas.

CROP LIMITS:
Cropping is camera framing only, not artwork alteration. The full artwork does not need to be visible. One or more artwork edges may fall outside the image frame. Do not complete, widen, shorten, square, compress, extend, redesign, or reformat the visible fragment into a different full-format painting.

ATMOSPHERE LIMITS:
No atmospheric scene-building. No dramatic atelier mood. No cinematic haze. No golden-room storytelling. No full-room context. No visible furniture, easels, shelves, table setups, large windows, decorative props, people, pets, or architectural narrative. The background, if visible, must stay minimal, peripheral, soft, and secondary to the canvas edge.

NEGATIVE PROMPT:
No poster, no flat print, no paper sheet, no digital screen, no floating panel, no exaggerated thickness, no block canvas, no slab, no plinth, no box, no wedge, no invented frame, no ornate frame, no raw wood stretcher bars unless visible in IMAGE 1, no staples unless visible in IMAGE 1, no redesigned side edge, no changed artwork format, no squared portrait artwork, no stretched artwork, no compressed artwork, no artwork substitution, no invented imagery, no invented marks, no artificial impasto, no new tactile marks, no full room, no room atmosphere, no furniture, no easel, no table setup, no shelves, no large windows, no window-dominant composition, no dramatic atelier mood, no cinematic haze, no golden-room storytelling, no people, no pets, no crowds, no children, no visible text, no logos, no watermarks.
PROMPT,
        ],
        'esquina_obra_perspectiva_extrema' => [
            'slot_id' => 'esquina_obra_perspectiva_extrema',
            'slot_name' => 'Corte Agresivo de Esquina de Obra',
            'enabled' => true,
            'fidelity_mode' => 'artistic_optical_distortion',
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera is placed extremely close to one physical canvas corner or edge, almost touching the artwork plane, so the image cuts aggressively across the artwork rather than showing a normal room mockup.',
            'lens_block' => 'Use a close optical lens equivalent with shallow depth and strong foreshortening. The lens may crop the artwork hard and skim across the surface, but must not melt, bend, tear, curve, or reshape the canvas geometry.',
            'vertical_tilt_block' => 'Use only the tilt needed to cut across the artwork corner and reveal thickness, surface texture, subtle sheen, humidity-like highlights, and edge depth. The canvas remains a rigid physical rectangle under optical perspective.',
            'lateral_rotation_block' => 'Use an aggressive side-oblique angle across one real artwork corner, around 10-25 degrees along the artwork plane, so the frame cuts the artwork and may exclude large portions of it. The camera should read as a close artistic slice over the painting surface, not a distant side view of a room.',
            'composition_block' => 'Partial cropping is required. The full artwork should usually not be visible. Let the image cut through one angle or corner of the artwork, with the canvas edge, front surface, thickness, texture, slight moisture-like sheen, and gentle gloss carrying the composition. Preserve the local composition exactly in the visible area: existing lines, color blocks, marks, boundaries, symbols, and proportions must remain from the reference artwork. Do not invent landscapes, figures, new symbols, new brush marks, or a different pictorial composition.',
            'human_subject_block' => '',
            'scale_block' => 'Physical canvas protection: the canvas may look dramatic through optical perspective, but it must not melt, bend, curve, tear, warp, taper into an impossible wedge, or lose rigid rectangular credibility. Artwork identity protection: preserve visible colors, marks, texture, abstract identity, and local composition from the provided artwork. Anti-substitution protection: do not replace it with figurative, classical, historical, portrait, landscape, decorative, or invented painting. Do not repaint the visible fragment, do not change the composition inside the cropped area, and do not turn surface sheen into new imagery.',
            'depth_of_field_block' => 'Shallow depth of field is allowed. The selected physical corner, nearby edge, surface texture, subtle gloss, humidity-like highlights, and visible artwork marks must remain sharp enough to read as the real artwork; only distant cropped surface areas may fall softly out of focus.',
            'scene_affinity' => ['canvas_corner', 'aggressive_crop', 'extreme_perspective', 'foreshortening', 'material_edge', 'surface_sheen', 'artistic_detail'],
            'negative_directives' => ['no full-room side view', 'no distant mockup view', 'no melted canvas', 'no bent canvas', 'no torn canvas', 'no impossible wedge', 'no changed composition', 'no invented marks', 'no invented landscape', 'no digital screen', 'no artwork substitution', 'no invented painting', 'no pets', 'no crowds', 'no children'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium photographic mockup using this exact camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

INPUT ROLES:
IMAGE 1 is the only source of truth for the artwork. Preserve the visible artwork identity, colors, marks, sparse areas, local composition, surface character, and physical canvas/object behavior.
IMAGE 2 may only inform a minimal peripheral surface tone if needed. Do not import its atmosphere, room layout, camera angle, furniture, windows, props, dramatic light, or architectural storytelling.

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}

TASK:
Create an aggressive close photographic slice across one real physical corner or edge of IMAGE 1. Partial cropping is required. The full artwork should usually not be visible. The composition is carried by one canvas corner, front painted surface, side thickness, edge depth, surface texture, subtle sheen, and optical foreshortening.

CAMERA:
Place the camera extremely close to one artwork corner or edge, almost touching the artwork plane. Use a close optical lens with shallow depth and strong foreshortening. The viewpoint should skim across the surface at an aggressive side-oblique angle, around 10-25 degrees along the artwork plane.

STRICT ARTWORK FIDELITY:
Preserve the local visible composition exactly from IMAGE 1. Existing lines, color blocks, marks, boundaries, symbols, sparse zones, and proportions must remain from the reference artwork. Do not invent landscapes, figures, new symbols, new brush marks, decorative gestures, or a different pictorial composition.

CANVAS GEOMETRY:
The canvas may look dramatic through optical perspective, but it must remain a rigid physical rectangle. Do not melt, bend, curve, tear, warp, taper into an impossible wedge, or lose rectangular credibility. Strong camera crop is allowed; artwork alteration is not.

PAINTED EDGE:
If a side edge, corner, or lateral thickness is visible, it must appear painted as a natural continuation of IMAGE 1 or as a coherent painted wrap from the same artwork. Do not show raw beige canvas, unpainted primer, bare wood, exposed stretcher bars, or a blank neutral side band unless IMAGE 1 explicitly shows that exact edge condition.

TEXTURE:
Show only texture, gloss, pigment, weave, and sheen already supported by IMAGE 1 and by a normal painted canvas. Do not invent heavy artificial impasto, cracks, incisions, scratches, extra ridges, wet paint, embossed effects, or new tactile marks.

ATMOSPHERE LIMITS:
No full-room view. No distant mockup view. No atmospheric scene-building. No dramatic atelier mood. No large windows, furniture, easels, shelves, props, people, pets, or architectural narrative. Any background must stay minimal, peripheral, soft, and secondary to the canvas corner.

NEGATIVE PROMPT:
No full-room side view, no distant mockup view, no normal room mockup, no melted canvas, no bent canvas, no curved canvas, no torn canvas, no impossible wedge, no warped canvas, no changed composition, no invented marks, no invented landscape, no figures, no portraits, no decorative motifs, no digital screen, no poster, no flat print, no artwork substitution, no invented painting, no artificial impasto, no cracks, no scratches, no incisions, no extra ridges, no wet paint, no raw beige canvas edge, no unpainted canvas edge, no bare wood edge, no exposed stretcher bars, no room atmosphere, no furniture, no easel, no large windows, no people, no pets, no crowds, no children, no visible text, no logos, no watermarks.
PROMPT,
        ],
        'rasante_superficie_pintura' => [
            'slot_id' => 'rasante_superficie_pintura',
            'slot_name' => 'Rasante de Superficie Pictórica',
            'enabled' => true,
            'fidelity_mode' => 'artistic_optical_distortion',
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera is extremely close and almost parallel to the painting surface, like a grazing shot across the skin of the artwork plane.',
            'lens_block' => 'Use a close-focus macro or material-detail lens equivalent with natural shallow optical depth, emphasizing only the pigment, texture, brushwork, and physical surface presence already visible in the root artwork.',
            'vertical_tilt_block' => 'Use a very low grazing angle along the painting surface. The full artwork does not need to be visible; the focus must fall on a real visible area of the artwork surface.',
            'lateral_rotation_block' => 'Run the viewpoint laterally along the painting plane so the existing texture creates depth, with partial cropping expected and no need for full rectangular readability.',
            'composition_block' => 'Partial cropping is expected. The image is a rasante view over the artwork surface, emphasizing the real material skin, pigment depth, shine, and texture transitions already present in the root artwork rather than room context. Do not add artificial impasto, invented relief, scratches, cracks, incisions, extra ridges, or new tactile marks.',
            'human_subject_block' => '',
            'scale_block' => 'Material protection: the surface must remain a real painted canvas or artwork material, not plastic, glossy digital, poster-like, printed paper, or screen-like. Artwork identity protection: preserve the provided artwork color world, visible marks, and existing material identity. Anti-substitution protection: do not invent new imagery, artificial impasto, extra relief, or replace the painting.',
            'depth_of_field_block' => 'Shallow depth of field is allowed and expected. The selected focus ridge, mark, pigment area, or texture band must be sharp; foreground and background surface areas may soften naturally along the grazing plane.',
            'scene_affinity' => ['grazing_surface', 'macro_material', 'pigment_relief', 'brushwork', 'surface_skin'],
            'negative_directives' => ['no plastic surface', 'no glossy digital look', 'no digital screen', 'no poster-like print', 'no printed-paper appearance', 'no invented imagery', 'no artwork substitution', 'no pets', 'no crowds', 'no children'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium photographic mockup using this exact camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

INPUT ROLES:
IMAGE 1 is the only source of truth for the artwork surface. Preserve the visible colors, marks, surface rhythm, existing pigment, weave, and local abstract identity.
IMAGE 2 may only inform a neutral peripheral surface tone if absolutely needed. Do not import atmosphere, room layout, windows, furniture, props, dramatic light, or architectural narrative.

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}

TASK:
Create a rasante close-up view across the physical painting surface of IMAGE 1. The camera is almost parallel to the artwork plane, grazing across the skin of the painting. Partial cropping is expected; this is a material surface photograph, not a full-room mockup.

CAMERA:
Use a close-focus macro or material-detail lens with natural shallow optical depth. Run the viewpoint laterally along the painting plane so existing pigment, texture, brushwork, shine, and surface transitions create depth. The full artwork does not need to be visible.

STRICT ARTWORK FIDELITY:
The selected visible area must remain the same artwork from IMAGE 1. Preserve local color relationships, marks, sparse areas, abstract identity, and surface character. Do not redraw, reinterpret, recolor, complete, recompose, replace, or invent any new imagery.

TEXTURE LIMITS:
Show only physical surface presence already supported by IMAGE 1 and by a normal painted canvas. Do not add artificial impasto, invented relief, scratches, cracks, incisions, extra ridges, wet paint, plastic shine, embossed effects, or new tactile marks.

ATMOSPHERE LIMITS:
No atmospheric scene-building. No dramatic atelier mood. No cinematic haze. No golden-room storytelling. No full room. No furniture, easels, shelves, table setups, large windows, decorative props, people, pets, or architectural narrative. Background, if any, must be minimal and secondary.

FOCUS:
The selected focus ridge, mark, pigment area, or texture band must be sharp. Foreground and background surface areas may soften naturally along the grazing plane. Never blur the chosen focus area or turn the surface into an abstract background texture unrelated to IMAGE 1.

NEGATIVE PROMPT:
No plastic surface, no glossy digital look, no digital screen, no poster-like print, no printed-paper appearance, no invented imagery, no artwork substitution, no artificial impasto, no invented relief, no cracks, no scratches, no incisions, no extra ridges, no wet paint, no embossed effects, no new tactile marks, no full room, no room atmosphere, no furniture, no easel, no table setup, no shelves, no large windows, no dramatic atelier mood, no cinematic haze, no people, no pets, no crowds, no children, no visible text, no logos, no watermarks.
PROMPT,
        ],
        'nadir_extremo_arquitectonico' => [
            'slot_id' => 'nadir_extremo_arquitectonico',
            'slot_name' => 'Nadir Extremo Arquitectónico',
            'enabled' => true,
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera is positioned at true floor level, approximately 1-3 cm from the floor, almost touching the ground, as if the lens is lying on the floor at the corner of the room. The camera is not centered in front of the artwork; it sits near one lateral wall or floor/wall junction and looks sharply upward across the artwork zone. The floor must dominate the lower foreground and feel physically close to the lens, while walls, columns, beams, slabs, doors, luminaires, wall joints, or vertical architectural planes tower upward through the composition.',
            'lens_block' => 'Use an extreme architectural wide lens equivalent, about 14-20 mm. Strong perspective exaggeration, stretched floor foreground, rising verticals, diagonal convergence, and dramatic optical depth are allowed and desired. Avoid cartoon circular fisheye rings, but do not sanitize the perspective into a normal 24-35 mm gallery view.',
            'vertical_tilt_block' => 'Use an unmistakable steep upward nadir or contrapicado tilt, almost vertical from the floor toward the wall, ceiling, beams, slabs, skylights, and vertical structure. The camera must look up from below and beside the artwork zone, not from a centered frontal axis. The artwork may be visibly foreshortened by perspective, but it must remain recognizable as the same rigid physical artwork.',
            'lateral_rotation_block' => 'Use an extreme low diagonal rotation from a floor corner or side-wall position, with a strong upward vanishing direction shared by the artwork, wall, floor, ceiling, furniture, and architectural verticals. The room should feel pulled into a steep diagonal vortex. Avoid any centered, symmetrical, straight-on product wall view.',
            'composition_block' => 'Build the drama through a radical bottom-up diagonal architectural view. Do not center the artwork as a frontal monument. The lower floor edge or near floor plane should occupy a large part of the lower frame, with one side wall or floor/wall seam rushing past the camera and architecture rising overhead. Extreme nadir views work best in spaces with mass, height, and clear architectural structure: brutalist architecture, exposed concrete galleries, tall vertical walls, architectural atriums, industrial minimalist lofts, high ceiling collector spaces, concrete beams, slabs, columns, skylights, or vertical openings. The drama comes from extreme off-axis camera position, stretched floor foreground, rising verticals, diagonal convergence, and exaggerated spatial depth in the room only. Floor lines, vertical wall planes, ceiling height, columns, door frames, beams, luminaires, furniture legs, wall joints, and overhead structural planes must visibly carry the nadir effect. The artwork remains intentionally framed at its supplied physical scale and exact visible layout; do not enlarge, reformat, rotate, repaint, or recompose it to compete with the architecture. The camera can make the surrounding room perspective feel almost deformed, but the artwork must preserve its original format, mark placement, color fields, empty areas, and composition.',
            'human_subject_block' => '',
            'scale_block' => 'The artwork remains a physically plausible canvas in the room. Canvas perspective protection: the canvas is a rigid rectangular physical object under aggressive lens perspective; controlled foreshortening is allowed, but do not melt, tear, curve, liquid-warp, rotate to a different format, or replace the artwork identity. Artwork fidelity protection: preserve the exact abstract artwork from the reference image, including original orientation, aspect ratio, color fields, mark placement, empty areas, sparse composition, and proportions. Do not replace it with figurative, classical, historical, portrait, landscape, decorative, old-master-style, or newly invented abstract painting.',
            'depth_of_field_block' => 'Keep the artwork, canvas edges, canvas thickness, and artwork texture sharp and legible. Only very close floor foreground or distant ceiling and architectural planes may soften slightly; the blur must remain subtle, optical, and photographic.',
            'scene_affinity' => ['floor_plane', 'nadir', 'architectural_verticals', 'columns', 'ceiling_height', 'brutalist_architecture', 'exposed_concrete_gallery', 'architectural_atrium', 'industrial_minimalist_loft', 'high_ceiling_collector_space'],
            'best_fit' => ['brutalist architecture', 'exposed concrete gallery', 'tall vertical wall', 'architectural atrium', 'industrial minimalist loft', 'high ceiling collector space', 'strong vertical structure', 'large but restrained architecture', 'concrete beams', 'slabs', 'columns', 'skylights', 'vertical openings'],
            'curatorial_rule' => 'Extreme nadir views work best in spaces with mass, height, and clear architectural structure. Brutalist or monumental architecture is especially suitable because the drama comes from the rising architecture, not from enlarging or deforming the artwork.',
            'avoid_scene_affinity' => ['low domestic rooms', 'cozy living rooms', 'soft decorative interiors', 'cramped rooms', 'flat walls with no architectural height', 'scenes where drama would require warping the canvas or oversizing the artwork'],
            'negative_directives' => ['no centered frontal composition', 'no symmetrical monument view', 'no straight-on product wall view', 'no eye-level view', 'no standing-height view', 'no normal 3/4 gallery photo', 'no gentle low angle', 'no soft controlled viewpoint', 'no flat wall view', 'no melted artwork', 'no torn canvas', 'no artwork substitution', 'no artwork reinterpretation', 'no classical painting substitution', 'no low domestic rooms', 'no cozy living rooms', 'no cramped rooms', 'no pets', 'no crowds', 'no children'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium photographic mockup using this exact camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

INPUT ROLES:
- IMAGE 1 is the root artwork. Treat it as visual evidence that must be preserved exactly.
- IMAGE 2, if provided, may only inform environmental DNA: materiality, surface texture, palette, light temperature, architectural mass, and premium spatial character. Do not copy IMAGE 2's camera angle, layout, crop, wall choice, window placement, furniture placement, object positions, or room geometry.

ARTWORK PHYSICAL DATA:
- Width: {{ARTWORK_WIDTH_CM}} cm
- Height: {{ARTWORK_HEIGHT_CM}} cm
- Depth: supplied physical canvas/object depth metadata only; use it only to keep canvas thickness plausible and never render it as visible text
- Orientation: {{ARTWORK_ORIENTATION}}

TASK:
Create an extreme architectural nadir mockup of IMAGE 1 installed as a real physical artwork in a newly built premium architectural environment. The camera slot is the authority. The image must feel photographed from almost floor level, with dramatic rising architecture and a strong off-axis diagonal perspective.

CAMERA:
Place the lens at true floor level, approximately 1-3 cm from the floor, almost touching the ground. Use an off-axis floor-corner or side-wall viewpoint, not a centered frontal product view. The camera looks sharply upward and diagonally across the artwork zone toward walls, ceiling, beams, skylights, columns, tall windows, slabs, door frames, luminaires, or vertical architectural planes.

LENS AND PERSPECTIVE:
Use an extreme architectural wide lens equivalent, about 14-20 mm. Strong perspective exaggeration, stretched near-floor foreground, rising verticals, diagonal convergence, and dramatic optical depth are desired. Architecture, floor, ceiling, beams, columns, windows, furniture legs, wall joints, and room planes may feel almost perspective-deformed. Avoid cartoon circular fisheye rings.

CRITICAL DISTINCTION:
The architecture may distort through the lens. The artwork may not distort as an object. IMAGE 1 remains the same rigid physical canvas/object with its original orientation, aspect ratio, color fields, mark placement, empty areas, sparse composition, proportions, and visible identity. Controlled optical foreshortening is allowed only as natural perspective on a rigid rectangular object. Do not melt, bend, curve, tear, liquify, reformat, rotate to a different format, stretch, compress, repaint, recompose, or replace the artwork.

PAINTED EDGE:
If any canvas side edge, bottom edge, top edge, corner, or lateral thickness is visible, that edge must be painted as a physical continuation of IMAGE 1. The visible edge should carry wrapped color, pigment, texture, brush rhythm, marks, and material energy from the nearest front-face area. Do not show raw beige canvas, unpainted fabric, white primer, bare wood, exposed stretcher bars, blank side bands, or generic neutral lateral strips unless IMAGE 1 explicitly shows that kind of edge.

ARTWORK FIDELITY:
Preserve the exact root artwork from IMAGE 1: colors, composition, marks, sparse areas, surface rhythm, local texture, proportions, and format. Do not reconstruct the artwork from style, room mood, or memory. Do not invent a new abstract painting, figurative painting, landscape, portrait, decorative panel, classical artwork, old-master-style painting, symbols, figures, extra strokes, or room-inspired marks.

SCALE AND PRESENCE:
Keep the artwork at its supplied physical size. Do not enlarge it into a mural, billboard, wall-sized architectural panel, room divider, stage prop, or monumental slab unless the supplied dimensions truly support that. If the artwork risks becoming too small in the large room, solve it photographically: move the low camera closer to the artwork wall, tighten the crop, choose a nearer floor/wall junction, or use focal-length compression while preserving the extreme nadir logic. Do not solve presence by changing object scale.

COMPOSITION:
Build the drama through room geometry: near floor plane, floor seams, wall/floor junction, baseboard, concrete panel joints, tall wall planes, columns, beams, slabs, skylights, vertical openings, door frames, luminaires, furniture legs, or overhead structure. The lower foreground should feel physically close to the lens. The artwork must remain intentionally framed and legible at its true scale, not tiny, accidental, or lost in the background.

SCALE ANCHORS:
Use nearby architectural or object scale anchors when possible: floor/wall junction, baseboard, outlet, door edge, floor seam, concrete panel joint, radiator, chair leg, low table edge, furniture edge, or clear contact shadow. These anchors must confirm the artwork's real dimensions and must not make it read as a mural or architectural surface.

WORLD MOTHER LIMITS:
Use IMAGE 2 only to infer compatible environmental DNA: material palette, wall/floor surface feeling, architectural mass, light quality, and premium spatial character. Reconstruct or relocate windows, openings, walls, furniture, supports, objects, depth, and architectural structure as needed to obey this nadir camera. Preserve visual family, not source layout. Do not let IMAGE 2 override the camera, artwork scale, artwork identity, or composition.

INSTALLATION:
The artwork should be physically plausible as a wall-mounted or architecturally installed canvas/object unless the selected scene clearly supports another stable installation. It must have believable wall contact, canvas/object thickness when visible, coherent perspective, and subtle physical shadow. No floating artwork.

NEGATIVE PROMPT:
No centered frontal composition, no symmetrical monument view, no straight-on product wall view, no eye-level view, no standing-height view, no normal 3/4 gallery photo, no gentle low angle, no soft controlled viewpoint, no flat wall view, no polite gallery view, no fisheye circle, no cartoon fisheye, no melted artwork, no bent artwork, no curved artwork, no torn canvas, no liquid-warp artwork, no raw beige canvas edge, no unpainted canvas edge, no white primer edge, no bare wood edge, no exposed stretcher bars, no blank side band, no generic neutral lateral strip, no rotated artwork format, no stretched artwork, no compressed artwork, no changed artwork identity, no artwork substitution, no artwork reinterpretation, no invented painting, no classical painting substitution, no old-master-style painting, no figurative replacement, no landscape replacement, no portrait replacement, no room-inspired marks on the artwork, no mural scaling, no billboard scaling, no wall-sized architectural panel, no oversized slab, no floating canvas, no copied world mother layout, no copied source photo camera angle, no same window placement as IMAGE 2, no same furniture placement as IMAGE 2, no treating IMAGE 2 as a room template, no low domestic room, no cozy living room, no cramped room, no pets, no crowds, no children, no visible people, no visible text, no logos, no watermarks, no measurement labels.
PROMPT,
        ],
        'camara_15_contrapicado_inpainting' => [
            'slot_id' => 'camara_15_contrapicado_inpainting',
            'slot_name' => 'Cámara 15 / Contrapicado Fuerte con Inpainting',
            'enabled' => false,
            'generation_strategy' => 'inpainting_precomposition',
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera is at true floor level, approximately 2-8 cm from the floor, placed close to the artwork wall and slightly to the right of the artwork, looking upward with a forceful low-angle attack.',
            'lens_block' => 'Use a dramatic but architectural 18-24 mm lens equivalent. Strong rising verticals, stretched near-floor foreground, and deep upward perspective are desired, but avoid cartoon fisheye rings.',
            'vertical_tilt_block' => 'Use a very strong upward contrapicado tilt from below the artwork centerline. The wall, ceiling, beams, windows, columns, or track lights must rise sharply above the artwork.',
            'lateral_rotation_block' => 'Use a low 7/8 right oblique rotation so the wall, floor plane, artwork edge, and overhead architecture share one coherent vanishing logic.',
            'composition_block' => 'Build a powerful floor-level architectural view: a large floor wedge in the foreground, a visible wall/floor junction near the artwork, and tall architecture rising behind and above it. The artwork must remain protected at its real precomposed scale and should feel physically installed, not enlarged into a mural.',
            'human_subject_block' => '',
            'scale_block' => 'Inpainting scale test: the artwork is precomposed at the supplied physical dimensions before environment generation. The mask protects the artwork face and physical footprint. The final image may dramatize the room, floor, and ceiling through camera perspective, but must not change artwork size, aspect ratio, orientation, mark placement, color fields, or identity.',
            'depth_of_field_block' => 'Keep the artwork and immediate wall contact sharp. Very close floor foreground or distant ceiling structure may soften slightly, but the artwork must never blur.',
            'scene_affinity' => ['inpainting', 'floor_level', 'contrapicado', 'rising_architecture', 'high_ceiling'],
            'best_fit' => ['tall vertical wall', 'high ceiling studio', 'architectural atrium', 'strong vertical structure', 'tall wall with beams or windows'],
            'negative_directives' => ['no eye-level view', 'no standing-height view', 'no normal 3/4 gallery photo', 'no gentle low angle', 'no centered product wall view', 'no mural scaling', 'no billboard scaling', 'no oversized panel', 'no artwork substitution', 'no warped artwork', 'no bent artwork', 'no stretched artwork', 'no compressed artwork', 'no floating artwork', 'no pets', 'no crowds', 'no children'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium photographic mockup using this exact experimental camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

EXPERIMENTAL INPAINTING STRATEGY:
This is Camera 15. Use an inpainting-first reading: IMAGE 1 must be treated as the protected root artwork, precomposed at real physical scale before the room is generated. The environment may be rebuilt around it, but the artwork face, physical footprint, aspect ratio, orientation, and relative size must remain locked.

INPUT ROLES:
IMAGE 1 is the only source of truth for the artwork. Preserve exact identity, colors, mark placement, sparse areas, surface rhythm, proportions, and format.
IMAGE 2, if present, is the world mother and the authority for environmental DNA: wall material, floor material, architectural mass, light temperature, and mood. Reproduce IMAGE 2's material and light identity faithfully; do not default to concrete, industrial, or loft materials unless IMAGE 2 actually shows them. Do not copy its camera angle, layout, furniture placement, wall choice, window placement, crop, or perspective.

ARTWORK PHYSICAL DATA:
Artwork physical size: {{ARTWORK_WIDTH_CM}} cm wide x {{ARTWORK_HEIGHT_CM}} cm high.
Artwork depth: supplied physical canvas/object depth metadata only; use it only to keep canvas thickness plausible and never render it as visible text.
Artwork orientation: {{ARTWORK_ORIENTATION}}.
Artwork size class: {{ARTWORK_SIZE_CLASS}}.

CAMERA:
Create a very strong floor-level contrapicado. Place the lens approximately 2-8 cm from the floor, close to the artwork wall, slightly to the right of the artwork, looking sharply upward from below the artwork centerline. Use a low 7/8 right oblique view.

LENS AND PERSPECTIVE:
Use an 18-24 mm architectural lens equivalent. Strong rising verticals, stretched floor foreground, steep wall/floor convergence, and dramatic upward room depth are desired. Avoid circular fisheye. The room can feel optically intense; the protected artwork must remain a rigid physical object.

COMPOSITION:
The lower foreground should contain a large floor wedge, floor seam, floor reflection, or wall/floor junction very close to camera, in whatever floor and wall material IMAGE 2 establishes. Behind it, the artwork is installed on the wall at its true physical size. Ceiling beams, tall windows, columns, track lights, wall joints, slabs, or vertical openings should rise above it and make the contrapicado powerful.

SCALE LOCK:
The artwork is {{ARTWORK_WIDTH_CM}} cm wide x {{ARTWORK_HEIGHT_CM}} cm high. Keep that real scale. Do not enlarge it to dominate the room, do not make it mural-sized, billboard-sized, wall-filling, room-divider sized, or architectural-panel sized. If the camera needs more presence, move the floor-level camera closer or crop tighter; never change object scale.

MASK PROTECTION:
Keep the artwork surface unchanged. Do not repaint, reinterpret, alter, crop, mirror, rotate, recolor, simplify, extend, replace, blur, stretch, compress, bend, curve, melt, or recompose the protected artwork. Natural rigid-object perspective is allowed only if it is already present in the protected precomposition.

INTEGRATION:
Generate the surrounding wall, floor, ceiling, light, contact shadows, and architecture around the protected artwork. Add subtle physical shadow and contact depth at the artwork edges. The artwork must feel genuinely installed in the room, not pasted, floating, or scaled after the fact.

NEGATIVE PROMPT:
No eye-level view, no standing-height view, no normal 3/4 gallery photo, no gentle low angle, no centered product wall view, no symmetrical monument view, no mural scaling, no billboard scaling, no oversized canvas, no room-dominating artwork, no wall-filling panel, no architectural panel, no changed artwork scale, no changed artwork format, no rotated artwork, no stretched artwork, no compressed artwork, no warped artwork, no bent artwork, no melted artwork, no floating artwork, no artwork substitution, no invented painting, no copied painting from IMAGE 2, no room-inspired marks on artwork, no classical painting substitution, no portrait replacement, no landscape replacement, no visible measurement labels, no visible text, no logos, no watermarks, no pets, no crowds, no children.
PROMPT,
        ],
        'obra_apoyada_suelo_7_8' => [
            'slot_id' => 'obra_apoyada_suelo_7_8',
            'slot_name' => 'Obra Apoyada en Suelo 7/8',
            'enabled' => true,
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera height is low or medium-low, approximately 35-90 cm from the floor, close enough to read the physical bottom edge, floor contact, floor plane, wall plane, and leaning relationship.',
            'lens_block' => 'Use a disciplined 45-60 mm lens equivalent that preserves believable scale, canvas depth, and wall/floor geometry without wide-angle stretching or heroic enlargement.',
            'vertical_tilt_block' => 'Use a modest vertical tilt only as needed to keep the leaning artwork readable; avoid forcing the front face into an impossible skew, billboard scale, or theatrical monumental angle.',
            'lateral_rotation_block' => 'Use a restrained 3/4 or 7/8 side-oblique view so the side edge, canvas depth, exact floor contact, and slight backward lean against the wall are visible through one coherent perspective.',
            'composition_block' => 'The artwork is not hanging. It must lean with physically believable gravity either against a real vertical wall or against a real stable object that could plausibly support a canvas in an atelier or collector preview, such as a low cabinet, sturdy table edge, studio rack, crate, storage block, easel-like support, or large furniture side. The support must be coherent with the room, not an invented monumental display plinth. The bottom edge should rest on the real floor or on a clearly stable low surface with visible contact shadows and believable load-bearing contact. Use a slight backward lean of about 5-12 degrees only. The scene may read as atelier, collector preview, clean storage wall, or refined studio. Off-center framing is allowed so floor, support object, wall, and side depth communicate the physical object, but the artwork must stay physically grounded and naturally placed.',
            'human_subject_block' => '',
            'scale_block' => 'The artwork keeps physically plausible real scale according to the artwork technical data supplied in the final prompt, leaning on the floor or against a stable support. It is a physical studio artwork at its supplied dimensions, not a monumental wall panel, billboard, room divider, stage prop, or oversized slab. The artwork height should read lower than a normal adult person unless the supplied artwork dimensions explicitly indicate otherwise, and it should remain well below a typical door height when the artwork dimensions support that reading. Canvas perspective protection: keep the front face rectangular and optically faithful, side thickness moderate, bottom edge grounded, and floor/support contact believable. Artwork fidelity protection: preserve the exact abstract artwork from the reference image; do not replace it with figurative, classical, historical, portrait, landscape, decorative, or old-master-style painting.',
            'depth_of_field_block' => 'Keep the artwork face, canvas edges, bottom edge, contact point, and texture sharp. Subtle depth falloff may soften distant studio objects or far wall planes, but never the artwork or its physical support contact.',
            'scene_affinity' => ['atelier', 'collector_preview', 'storage_wall', 'studio_floor', 'leaning_canvas'],
            'negative_directives' => ['no hanging artwork', 'no wall-mounted artwork', 'no impossible support', 'no invented monumental plinth', 'no floating canvas', 'no unsupported canvas', 'no unstable balancing', 'no arbitrary placement', 'no oversized canvas', 'no billboard scaling', 'no poster', 'no flat print', 'no canvas wedge', 'no artwork substitution', 'no pets', 'no crowds', 'no children'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium editorial mockup image for the selected camera slot.

Camera Slot ID: obra_apoyada_suelo_7_8
Camera Slot Name: Obra Apoyada en Suelo 7/8

IMAGE ROLES:
IMAGE 1 is the only source of truth for the artwork. Preserve its exact artwork identity, aspect ratio, composition, color relationships, sparse areas, marks, and visible painted surface. Do not redesign, repaint, reinterpret, simplify, decorate, complete, crop into a different composition, or replace the artwork.
IMAGE 2 is only environmental reference. Use it for compatible material DNA, light quality, palette, atelier or collector-room feeling, wall/floor surface character, and spatial mood. Do not copy its camera, layout, furniture placement, window placement, artwork placement, scale, or composition.

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}

FINAL TASK:
Create a realistic physical mockup of IMAGE 1 as a real stretched canvas leaning on the floor or on a clearly stable low support. The artwork is not hanging. The scene must show a floor-leaning artwork with believable gravity, wall/support contact, bottom-edge contact, canvas depth, and a restrained 7/8 side-oblique relationship.

FLOOR-LEANING INSTALLATION:
The bottom edge of the artwork must rest on a real floor or on a clearly stable low surface. The upper/back side must lean gently against a real vertical wall or against a stable object that could plausibly support a canvas in an atelier or collector preview: a wall, low cabinet, sturdy table edge, studio rack, crate, storage block, easel-like support, or large furniture side.
Use a slight backward lean of about 5-12 degrees only. Include believable load-bearing contact, contact shadows, and coherent support geometry. The support must be part of the room, not an invented monumental display plinth. No floating, no unsupported balancing, no arbitrary placement.

CAMERA:
Use a low or medium-low camera height, approximately 35-90 cm from the floor. The camera should be close enough to read the bottom edge, floor contact, side edge, canvas depth, floor plane, wall/support plane, and slight backward lean.
Use a restrained 3/4 or 7/8 side-oblique view. The side edge and thickness may be visible, but the artwork must remain a rigid rectangular canvas with coherent perspective.
Use a disciplined 45-60 mm lens equivalent. Preserve believable scale, canvas depth, and wall/floor geometry. Do not use heroic wide-angle enlargement, billboard scale, or theatrical monumental distortion.

SCALE:
Respect the supplied artwork dimensions. The artwork must read as a physical studio artwork at its real size, not as a monumental wall panel, billboard, room divider, stage prop, oversized slab, or architectural surface.
If the image needs more presence, solve it with camera proximity, framing, and natural perspective, not by enlarging the artwork beyond its physical data. The artwork height should remain below a typical door height unless the supplied dimensions explicitly justify otherwise.
Use nearby scale anchors when helpful: floor seams, baseboard, wall/floor junction, table leg, stool, studio rack, cabinet edge, crate, easel leg, or contact shadow. These anchors must confirm physical scale, not shrink or enlarge the artwork unnaturally.

PAINTED EDGE:
If any side edge, bottom edge, top edge, corner, or canvas thickness is visible, it must appear painted as a natural continuation of IMAGE 1 or as a coherent painted wrap from the same artwork. Do not show raw beige canvas, unpainted primer, bare wood, exposed stretcher bars, or a blank neutral side band unless IMAGE 1 explicitly shows that exact edge condition.

ARTWORK FIDELITY:
Keep the front artwork optically faithful to IMAGE 1. Preserve the original abstract structure, proportions, negative space, color fields, marks, texture level, and edge relationships. Do not add figurative content, portraits, landscapes, decorative brushwork, classical painting language, room-inspired marks, labels, signatures, or extra graphic elements.

TEXTURE:
Render only the physical texture already implied by IMAGE 1 and by a normal painted canvas. Do not invent heavy artificial impasto, extra relief, glossy sculptural ridges, thick palette-knife buildup, or exaggerated canvas weave. Surface detail may be visible, but it must not alter the artwork.

CONTEXT:
The setting may read as atelier, collector preview, clean storage wall, refined studio, or premium informal viewing space. Surroundings should support the floor-leaning placement: floor, wall/support, contact shadows, and a few coherent studio or furniture elements. Do not let the room, props, windows, furniture, or atmosphere dominate the image or override the slot.

DEPTH OF FIELD:
Keep the artwork face, side edge, bottom edge, contact point, support contact, and visible texture sharp. Subtle depth falloff may soften distant studio objects or far wall planes only. Never blur the artwork or the contact relationship.

NEGATIVE PROMPT:
No hanging artwork, no wall-mounted artwork, no floating canvas, no unsupported canvas, no unstable balancing, no arbitrary placement, no impossible support, no invented monumental plinth, no museum pedestal, no billboard scaling, no mural scaling, no oversized canvas, no room divider, no stage prop, no wall-sized architectural panel, no poster, no flat print, no digital screen, no canvas wedge, no warped canvas, no bent canvas, no curved canvas, no melted artwork, no stretched artwork, no compressed artwork, no changed artwork format, no changed artwork identity, no artwork substitution, no invented painting, no figurative replacement, no landscape replacement, no portrait replacement, no classical painting substitution, no decorative brushwork, no extra marks, no room-inspired marks on the artwork, no artificial impasto, no excessive relief, no exaggerated palette-knife texture, no exaggerated canvas weave, no raw beige canvas edge, no unpainted canvas edge, no white primer edge, no bare wood edge, no exposed stretcher bars, no blank side band, no generic neutral lateral strip, no copied IMAGE 2 layout, no copied IMAGE 2 camera, no copied IMAGE 2 window placement, no copied IMAGE 2 furniture placement, no full-room architectural dominance, no over-atmospheric haze, no pets, no crowds, no children, no visible people, no visible text, no logos, no watermarks, no measurement labels.
PROMPT,
        ],
        'diagonal_estudio_moderno' => [
            'slot_id' => 'diagonal_estudio_moderno',
            'slot_name' => 'Diagonal Moderna de Estudio',
            'enabled' => true,
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera height is medium or moderately low, approximately 70-140 cm, with an editorial studio viewpoint that lets architectural diagonals guide the eye.',
            'lens_block' => 'Use a clean 35-50 mm editorial architectural lens equivalent, avoiding exaggerated width while preserving clear room depth.',
            'vertical_tilt_block' => 'Keep vertical tilt controlled and mostly natural so diagonal composition comes from architecture, light, and framing rather than distortion.',
            'lateral_rotation_block' => 'Use a diagonal three-quarter composition where floor lines, ceiling lines, railings, benches, linear lights, wall seams, shadows, or side walls lead toward the artwork.',
            'composition_block' => 'Strong diagonal leading lines direct the viewer toward the artwork as the visual destination. Off-center placement is allowed and encouraged when it strengthens the diagonal flow. The photograph should feel contemporary, editorial, and spatial rather than centered and product-like.',
            'human_subject_block' => '',
            'scale_block' => 'The artwork remains physically plausible and optically faithful while the surrounding studio geometry creates movement. Canvas perspective protection: keep the canvas rigid, rectangular, crisp, and aligned to the same vanishing logic as floor, wall, ceiling, furniture, and architectural lines. Artwork fidelity protection: preserve the exact abstract artwork from the reference image; do not repaint, replace, or reinterpret it.',
            'depth_of_field_block' => 'Keep artwork, canvas edges, and artwork texture sharp. Secondary foreground diagonals or distant studio planes may soften slightly if it supports photographic depth, but the artwork remains the focus plane.',
            'scene_affinity' => ['modern_studio', 'diagonal_lines', 'editorial', 'architectural_edges', 'linear_lighting'],
            'negative_directives' => ['no centered product symmetry', 'no artwork deformation', 'no artwork substitution', 'no poster effect', 'no pets', 'no crowds', 'no children'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium photographic mockup using this exact camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

INPUT ROLES:
IMAGE 1 is the only source of truth for the artwork. Preserve exact artwork identity, orientation, aspect ratio, colors, marks, sparse areas, and composition.
IMAGE 2 is the world mother for the environment. Keep its room type, material family, light logic, surface character, and spatial identity. Do not replace it with a generic studio, generic gallery, different room type, or invented architecture. Adapt only the camera and artwork placement needed for this slot.

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}

TASK:
Create a diagonal editorial composition inside the environment provided by IMAGE 2. Strong diagonal movement should come from architectural lines, light/shadow, floor/wall geometry, furniture edges, or spatial features already present or naturally inferable from IMAGE 2. The artwork is a real rigid physical canvas/object at its supplied dimensions.

CAMERA:
Use medium or moderately low camera height, approximately 70-140 cm. Use a clean 35-50 mm editorial architectural lens. Keep vertical tilt controlled and mostly natural. Build diagonal movement only from existing or naturally compatible features of IMAGE 2, not by inventing a new studio, new gallery furniture, new windows, new benches, new lighting systems, or by deforming the artwork.

DIAGONAL COMPOSITION:
Use an off-center three-quarter or diagonal composition. The world mother remains recognizable in material, room type, and spatial character even after camera adaptation. Architecture may recede strongly in one direction if IMAGE 2 supports it, but the artwork must remain optically faithful, sharp, and aligned to the same wall/floor/ceiling perspective logic. Avoid centered product symmetry.

SCALE:
Respect the supplied artwork dimensions. The artwork must not become a billboard, mural, wall-sized panel, oversized slab, or stage prop. If stronger presence is needed, use camera proximity, crop, and diagonal leading lines rather than changing scale.

PAINTED EDGE:
If side depth, corner, or thickness is visible, the edge must be painted as a coherent continuation of IMAGE 1. No raw beige canvas edge, unpainted primer, bare wood, exposed stretcher bars, or generic blank side band unless IMAGE 1 explicitly shows it.

ARTWORK FIDELITY:
Do not repaint, replace, reinterpret, recolor, add marks, add figures, add decorative gestures, or import room-inspired shapes into the artwork.

NEGATIVE PROMPT:
No centered product symmetry, no flat frontal product wall, no invented studio, no invented gallery, no alternate room type, no replacing IMAGE 2 environment, no world-mother substitution, no invented benches, no invented track lights, no invented windows, no copied IMAGE 2 camera, no artwork deformation, no warped canvas, no melted artwork, no stretched artwork, no compressed artwork, no billboard scaling, no mural scaling, no oversized canvas, no artwork substitution, no invented painting, no decorative brushwork, no room-inspired marks, no raw beige canvas edge, no unpainted canvas edge, no bare wood edge, no poster effect, no digital screen, no pets, no crowds, no children, no visible people, no visible text, no logos, no watermarks.
PROMPT,
        ],
        'luz_dorada_sombra_diagonal' => [
            'slot_id' => 'luz_dorada_sombra_diagonal',
            'slot_name' => 'Luz Dorada y Sombra Diagonal',
            'enabled' => true,
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera height is natural gallery or collector viewing height, approximately 120-170 cm, calm enough to let golden window light and diagonal shadow geometry structure the image.',
            'lens_block' => 'Use a refined 40-55 mm lens equivalent with natural compression, preserving artwork scale, wall plane, and light/shadow transitions without distortion.',
            'vertical_tilt_block' => 'Use minimal vertical tilt. Keep the artwork plane calm, legible, and physically credible while light and shadow provide the compositional energy.',
            'lateral_rotation_block' => 'Use a soft frontal-oblique or moderate 3/4 camera angle, enough to show wall depth and room atmosphere without competing with the diagonal light composition.',
            'composition_block' => 'Build the composition from golden late-afternoon window light and a strong diagonal shadow across wall or floor. Place the artwork near the transition between light and shadow. The room may feel like a palazzo, atelier, townhouse, warm gallery, or Mediterranean interior. Avoid overexposing the artwork; the artwork remains sharp, faithful, and visually readable.',
            'human_subject_block' => '',
            'scale_block' => 'The artwork remains a physically plausible wall-mounted canvas near the light/shadow transition. Canvas perspective protection: keep the canvas rectangular, rigid, and aligned with wall and floor perspective; do not warp, deform, bend, taper, or turn it into a wedge. Artwork fidelity protection: preserve the exact abstract artwork from the reference image; do not recolor it with golden light, repaint it, replace it, or reinterpret it.',
            'depth_of_field_block' => 'Use little to moderate natural depth of field. The artwork surface, canvas edges, texture, and light/shadow boundary near the artwork remain crisp; only distant room planes may soften gently.',
            'scene_affinity' => ['golden_light', 'diagonal_shadow', 'palazzo', 'atelier', 'townhouse', 'mediterranean'],
            'negative_directives' => ['no overexposed artwork', 'no recolored artwork', 'no artwork substitution', 'no poster effect', 'no pets', 'no crowds', 'no children'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium photographic mockup using this exact camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

INPUT ROLES:
IMAGE 1 is the only source of truth for the artwork. Preserve its exact identity, colors, marks, sparse areas, composition, orientation, and aspect ratio.
IMAGE 2 is the world mother for the environment. Keep its room type, material family, light logic, wall/floor character, and spatial identity. Do not replace it with a generic warm room, palazzo, atelier, townhouse, Mediterranean interior, or any alternate invented environment. Adapt only camera, artwork placement, and light/shadow emphasis needed for this slot.

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}

TASK:
Create a refined wall-mounted artwork mockup inside the environment provided by IMAGE 2, using diagonal light/shadow only if it is present in IMAGE 2 or naturally compatible with its light source. The light is part of the world mother, not a replacement world and not a repainting of the artwork.

CAMERA:
Use natural gallery or collector viewing height, approximately 120-170 cm. Use a refined 40-55 mm lens with natural compression. Use minimal vertical tilt and a soft frontal-oblique or moderate 3/4 angle.

LIGHT AND SHADOW:
Place the artwork near a light/shadow transition only when the world mother supports that move. Avoid overexposing the artwork. Warm light may affect the room, wall, floor, and atmosphere only within the logic of IMAGE 2; it must not recolor, wash out, or alter IMAGE 1.

SCALE AND INSTALLATION:
The artwork is a plausible wall-mounted physical canvas at its supplied dimensions. Keep canvas rectangular, rigid, sharp, and aligned with wall/floor perspective. Do not turn it into a mural, billboard, oversized panel, or decorative wall finish.

PAINTED EDGE:
If any side edge or thickness is visible, it must be painted as a coherent continuation of IMAGE 1. No raw beige canvas edge, unpainted primer, bare wood, exposed stretcher bars, or blank neutral side band unless IMAGE 1 explicitly shows it.

NEGATIVE PROMPT:
No overexposed artwork, no recolored artwork, no golden repainting of the artwork, no washed-out artwork, no generic warm room, no invented palazzo, no invented atelier, no invented townhouse, no invented Mediterranean interior, no alternate room type, no replacing IMAGE 2 environment, no world-mother substitution, no invented windows, no invented diagonal light source, no artwork substitution, no invented painting, no added marks, no decorative brushwork, no room-inspired marks, no warped canvas, no poster effect, no digital screen, no billboard scaling, no mural scaling, no raw beige canvas edge, no unpainted canvas edge, no bare wood edge, no pets, no crowds, no children, no visible people, no visible text, no logos, no watermarks.
PROMPT,
        ],
        'contrapicado_raton_puro' => [
            'slot_id' => 'contrapicado_raton_puro',
            'slot_name' => 'Nadir Controlado',
            'enabled' => true,
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera is positioned extremely low, approximately 5-15 cm from the ground, with the lens nearly touching the floor. This is a mouse-level/nadir-inspired architectural viewpoint, not a gentle low 3/4. The floor plane must be large and close in the foreground while wall height, ceiling lines, columns, door frames or architectural verticals rise strongly.',
            'lens_block' => 'Use an aggressive wide architectural lens equivalent, about 20-26 mm. Strong perspective expansion, stretched near floor foreground, and dramatic vanishing lines are allowed and desired, while avoiding cartoon circular fisheye.',
            'vertical_tilt_block' => 'Use a strong upward nadir-inspired tilt toward the wall and surrounding architecture. The camera must visibly look up from near floor level. The artwork may be seen from below with clear foreshortening, yet it must remain recognizable as a rigid physical canvas.',
            'lateral_rotation_block' => 'Use an assertive oblique angle across the room, not a straight-on product view, with one forceful vanishing direction for architecture and artwork. The wall, floor, ceiling, and artwork should feel pulled into the same steep perspective.',
            'composition_block' => 'Use the nadir effect strongly through the surrounding room geometry: a large foreground floor wedge, floor lines, vertical wall planes, ceiling height, architectural edges, columns, furniture legs or door frames. The artwork remains legible and intentionally placed at its supplied physical scale, while the dramatic low viewpoint must be obvious at thumbnail size. Do not produce a polite standing-height gallery view.',
            'human_subject_block' => '',
            'scale_block' => 'The artwork is a rigid rectangular physical canvas under aggressive lens perspective. Controlled foreshortening and strong side-depth exaggeration are allowed; do not melt, tear, curve, liquid-warp, or replace the artwork identity. Preserve the exact abstract artwork from the reference image. Its apparent size must remain consistent with the supplied physical artwork dimensions; do not enlarge it to dominate the room. Canvas plane, wall plane, floor lines, furniture, and architecture share one coherent vanishing logic.',
            'depth_of_field_block' => 'Keep the artwork, canvas edges and artwork texture sharp. A slight softness may appear only in very close floor foreground or distant ceiling/architectural planes. Do not blur the artwork.',
            'scene_affinity' => ['floor_plane', 'studio', 'gallery', 'architectural_wall'],
            'negative_directives' => ['no eye-level view', 'no standing-height view', 'no normal 3/4 gallery photo', 'no gentle low angle', 'no soft controlled viewpoint', 'no flat wall view', 'no melted artwork', 'no torn canvas', 'no artwork substitution', 'no fashion-model posing', 'no mural scaling', 'no billboard scaling', 'no oversized canvas', 'no pets', 'no crowds', 'no children'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium photographic mockup using this exact camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

INPUT ROLES:
IMAGE 1 is the only source of truth for the artwork. Preserve exact identity, orientation, aspect ratio, colors, marks, sparse areas, and composition.
IMAGE 2 may only inform environmental DNA: materials, light quality, wall/floor character, and premium spatial mood. Do not copy camera, layout, furniture, windows, artwork placement, or composition.

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}

TASK:
Create a controlled nadir-inspired wall artwork mockup from near floor level. The camera must visibly look upward from approximately 5-15 cm above the floor, with a large near floor plane and rising room geometry.

CAMERA:
Use an aggressive wide architectural lens equivalent, about 20-26 mm. Use a strong upward tilt and assertive oblique angle across the room. Floor lines, wall planes, ceiling height, columns, furniture legs, door frames, and architectural verticals must carry the low viewpoint.

ARTWORK INTEGRITY:
The artwork is a rigid rectangular physical canvas under aggressive perspective. Controlled foreshortening is allowed, but do not melt, tear, curve, liquid-warp, rotate, stretch, compress, repaint, or replace the artwork. Keep canvas plane, wall plane, floor lines, furniture, and architecture in one coherent vanishing logic.

SCALE:
Respect the supplied dimensions. Do not solve drama by enlarging the artwork. No billboard, mural, wall-sized panel, oversized slab, or stage prop. Presence must come from low camera geometry and proximity.

PAINTED EDGE:
If a visible edge, corner, bottom, or side thickness appears, it must be painted as a coherent continuation of IMAGE 1. No raw beige canvas edge, unpainted primer, bare wood, exposed stretcher bars, or blank side band unless IMAGE 1 explicitly shows it.

NEGATIVE PROMPT:
No eye-level view, no standing-height view, no normal 3/4 gallery photo, no gentle low angle, no soft controlled viewpoint, no flat wall view, no centered product view, no melted artwork, no torn canvas, no curved canvas, no warped canvas, no artwork substitution, no invented painting, no mural scaling, no billboard scaling, no oversized canvas, no raw beige canvas edge, no unpainted canvas edge, no bare wood edge, no fashion-model posing, no pets, no crowds, no children, no visible people, no visible text, no logos, no watermarks.
PROMPT,
        ],
        'contrapicado_7_8' => [
            'slot_id' => 'contrapicado_7_8',
            'slot_name' => 'Contrapicado 7/8',
            'enabled' => true,
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera height is aggressively low, 5-20 cm from the floor, clearly far below knee height. The viewpoint must feel like the lens is near the floor and close to the wall, not standing height, not chest height, and not a normal eye-level gallery view.',
            'lens_block' => 'Use an aggressive wide architectural lens equivalent, about 20-28 mm. Strong side-depth exaggeration, stretched near foreground, and intense perspective convergence are allowed and desired. Avoid cartoon circular fisheye, but do not normalize the view into a polite 35-50 mm product photo.',
            'vertical_tilt_block' => 'Use a strong visible upward low-angle tilt from below the artwork centerline. The viewer should read the underside/lower edge relationship of the canvas, with wall verticals, ceiling lines, track lights, or architecture rising sharply upward. If the result could be mistaken for a normal eye-level 3/4 wall view, the camera is wrong.',
            'lateral_rotation_block' => 'Use a very strong 7/8 oblique rotation so the wall, artwork side depth, floor plane, and room volume rush toward one shared vanishing direction. The side of the canvas can feel dramatically deep through perspective, while the artwork remains a rigid object.',
            'composition_block' => 'The artwork is seen with aggressive side depth and believable thickness from a near-floor 7/8 viewpoint. Include a large visible floor wedge or floor plane in the lower foreground, with wall/floor junctions and architectural verticals proving the camera is below knee height. Use strong asymmetry, a close foreground edge, and negative space toward the vanishing side while keeping surrounding wall and floor aligned to the same camera. This must feel almost perspective-deformed and must not collapse into a standard standing-height 3/4 gallery photograph.',
            'human_subject_block' => '',
            'scale_block' => 'The artwork may be strongly framed by the camera, but its apparent size must remain consistent with the supplied physical artwork dimensions. Canvas integrity: the canvas is a rigid physical rectangle under aggressive perspective; strong foreshortening and exaggerated side-depth are allowed, but the front face must not melt, tear, curve, liquid-warp, or become a different artwork. Preserve the exact root artwork identity, colors, marks, sparse areas, composition, and proportions from IMAGE 1. Canvas plane, wall plane, floor lines, furniture, and architecture share one coherent vanishing logic.',
            'depth_of_field_block' => 'Use controlled photographic depth of field to separate foreground, artwork, and vanishing-side architecture: the artwork face, canvas edges, and visible texture remain completely sharp; only secondary foreground or distant background planes may soften subtly.',
            'scene_affinity' => ['loft', 'gallery', 'statement_wall', 'architectural_depth'],
            'negative_directives' => ['no eye-level view', 'no standing-height view', 'no normal 3/4 gallery photo', 'no gentle low angle', 'no soft controlled viewpoint', 'no flat wall view', 'no melted artwork', 'no torn canvas', 'no artwork substitution', 'no random people', 'no pets', 'no crowds', 'no children'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium photographic mockup using this exact camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

INPUT ROLES:
IMAGE 1 is the only source of truth for the artwork. Preserve exact artwork identity, orientation, aspect ratio, colors, marks, sparse areas, and composition.
IMAGE 2 may only inform compatible environmental DNA. Do not copy its camera, layout, furniture, window placement, artwork placement, or composition.

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}

TASK:
Create an aggressive low 7/8 contrapicado mockup. The viewpoint is near the floor and strongly side-oblique, showing believable artwork side depth, floor plane, wall geometry, and rising architecture.

CAMERA:
Camera height is 5-20 cm from the floor, far below knee height. Use an aggressive wide architectural lens equivalent, about 20-28 mm. Use a strong upward low-angle tilt from below the artwork centerline and a very strong 7/8 oblique rotation.

COMPOSITION:
Include a large visible floor wedge or floor plane in the lower foreground. Wall/floor junctions and architectural verticals must prove the camera is below knee height. Use strong asymmetry, close foreground edge, and one shared vanishing direction.

ARTWORK INTEGRITY:
The canvas is a rigid physical rectangle under aggressive perspective. Strong foreshortening and side-depth exaggeration are allowed, but the front face must not melt, tear, curve, liquid-warp, stretch, compress, or become a different artwork.

SCALE:
Respect the supplied dimensions. Do not enlarge into a billboard, mural, oversized slab, wall-sized panel, or stage prop. Presence must come from camera position and 7/8 geometry.

CAMERA SCALE RULE:
The camera may make the artwork feel prominent, close, intense, and dramatic, but it must never change the artwork's real physical size. Before placing the artwork, infer scale from IMAGE 2 using visible environmental evidence: windows, brick courses, sofa, table, radiators, doors, wall/floor junctions, fixtures, objects, and any repeated architectural or material unit. Use two or three references whenever available. The artwork must keep its real supplied dimensions against those anchors. If the final artwork reads as window-sized, sofa-wide, mural-sized, or like an architectural panel, the scale is wrong. Do not enlarge the artwork to create impact. Create impact through camera proximity, angle, crop, light, perspective, and composition while preserving correct physical scale.

PAINTED EDGE:
Visible side depth, bottom edge, corner, or thickness must be painted as a coherent continuation of IMAGE 1. No raw beige canvas edge, unpainted primer, bare wood, exposed stretcher bars, or blank side band unless IMAGE 1 explicitly shows it.

NEGATIVE PROMPT:
No eye-level view, no standing-height view, no normal 3/4 gallery photo, no gentle low angle, no soft controlled viewpoint, no flat wall view, no centered product view, no melted artwork, no torn canvas, no curved canvas, no warped canvas, no artwork substitution, no invented painting, no mural scaling, no billboard scaling, no oversized canvas, no raw beige canvas edge, no unpainted canvas edge, no bare wood edge, no random people, no pets, no crowds, no children, no visible text, no logos, no watermarks.
PROMPT,
        ],
        'reflejo_dorado_tarde_palazzo' => [
            'slot_id' => 'reflejo_dorado_tarde_palazzo',
            'slot_name' => 'Reflejo Dorado de Tarde / Palazzo',
            'enabled' => true,
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera height is elegant human standing height, approximately 150-170 cm, as if observed calmly from inside a palazzo room.',
            'lens_block' => 'Use a refined 40-50 mm lens equivalent with natural compression and no exaggerated room depth.',
            'vertical_tilt_block' => 'Keep vertical tilt minimal and steady, preserving dignified wall geometry and a calm institutional reading.',
            'lateral_rotation_block' => 'Use a soft three-quarter rotation that catches reflected late light while keeping the artwork plane legible.',
            'composition_block' => 'The composition is stable, warm, and architectural, with golden afternoon reflection supporting the room rather than repainting the artwork; keep elegant balance but avoid perfect symmetry unless the room itself naturally demands it.',
            'human_subject_block' => '',
            'scale_block' => 'The artwork is a plausible wall-mounted object at its supplied physical size in a refined interior, neither miniaturized nor monumentally enlarged. Canvas integrity: the canvas is a rigid physical rectangle; the front face must not bend, curve, stretch, or become a wedge, lateral thickness stays moderate and plausible, and canvas plane, wall plane, floor lines, furniture, and architecture share one coherent vanishing logic. Preserve the exact root artwork identity, colors, marks, sparse areas, composition, and proportions from IMAGE 1.',
            'depth_of_field_block' => 'Use minimal depth of field. The room, artwork, canvas edges, and artwork texture should remain mostly crisp; any softness is barely perceptible and limited to distant secondary planes.',
            'scene_affinity' => ['palazzo', 'collector_room', 'warm_reflection', 'late_afternoon'],
            'negative_directives' => ['no artwork color redescription', 'no fashion-model posing', 'no animals', 'no crowds', 'no children', 'no pets'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium photographic mockup using this exact camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

INPUT ROLES:
IMAGE 1 is the only source of truth for the artwork. Preserve exact identity, orientation, aspect ratio, colors, marks, sparse areas, and composition.
IMAGE 2 may only inform refined interior DNA: material quality, warm reflection, late-afternoon light, collector-room feeling, and premium surface character. Do not copy its camera, layout, furniture placement, window placement, artwork placement, or composition.

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}

TASK:
Create a refined wall-mounted artwork mockup in a calm palazzo or collector-room atmosphere with warm late-afternoon reflection. The room supports the artwork; it must not repaint or overpower it.

CAMERA:
Use elegant human standing height, approximately 150-170 cm. Use a refined 40-50 mm lens with natural compression and no exaggerated room depth. Keep vertical tilt minimal and stable. Use a soft three-quarter rotation that catches reflected late light while keeping the artwork plane legible.

LIGHT:
Warm reflection and late-afternoon atmosphere may shape the room, wall, floor, and surrounding surfaces. Do not recolor, overexpose, wash out, or tint IMAGE 1. The artwork remains visually faithful and crisp.

SCALE AND INSTALLATION:
The artwork is a plausible wall-mounted object at its supplied physical dimensions. It must be neither miniaturized nor monumentally enlarged. Keep the canvas rigid, rectangular, aligned with wall/floor perspective, and physically attached to the room.

PAINTED EDGE:
If canvas thickness, side edge, bottom edge, top edge, or corner is visible, it must be painted as a coherent continuation of IMAGE 1. No raw beige canvas edge, unpainted primer, bare wood, exposed stretcher bars, or blank neutral side band unless IMAGE 1 explicitly shows it.

NEGATIVE PROMPT:
No artwork color redescription, no recolored artwork, no golden repainting of the artwork, no overexposed artwork, no washed-out artwork, no artwork substitution, no invented painting, no added marks, no decorative brushwork, no warped canvas, no bent canvas, no billboard scaling, no mural scaling, no oversized panel, no raw beige canvas edge, no unpainted canvas edge, no bare wood edge, no fashion-model posing, no animals, no crowds, no children, no pets, no visible people, no visible text, no logos, no watermarks.
PROMPT,
        ],
        'vista_aerea_contexto_ventanas' => [
            'slot_id' => 'vista_aerea_contexto_ventanas',
            'slot_name' => 'Nadir Aéreo con Contexto de Ventanas',
            'enabled' => true,
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera is positioned very high, near ceiling height, upper balcony height, skylight height, or the top edge of a tall architectural void. It is the aerial counterpart of an extreme nadir: instead of looking upward from the floor, the camera looks downward from above into the room.',
            'lens_block' => 'Use a controlled 28-40 mm architectural lens equivalent, wide enough to read the room from above but restrained enough to avoid fisheye, drone, surveillance, or real-estate plan-view behavior.',
            'vertical_tilt_block' => 'Use a steep downward aerial-nadir tilt. The floor is the receiving plane of the image, and wall planes, windows, furniture, and the artwork wall drop downward through one coherent high-angle perspective.',
            'lateral_rotation_block' => 'Use an oblique high-corner or upper-balcony diagonal, not a centered frontal wall view. The camera may look diagonally down along the room so window context, floor geometry, and artwork wall are visible together.',
            'composition_block' => 'Build the image from above: visible floor plane, furniture or bench tops, rug or floor layout, wall height, window/skylight context, and the artwork installed on a vertical wall. The artwork remains identifiable, but the room must read as seen from above, not as a normal wall presentation.',
            'human_subject_block' => '',
            'scale_block' => 'The artwork occupies a plausible portion of the wall in an aerial-nadir composition with strong visible floor plane and surrounding architecture. Canvas integrity: the canvas is a rigid physical rectangle; the front face must not bend, curve, stretch, or become a wedge, lateral thickness stays moderate and plausible, and canvas plane, wall plane, floor lines, windows, furniture, and architecture share one coherent downward high-angle vanishing logic.',
            'depth_of_field_block' => 'Use little to no depth of field because the elevated architectural view needs readable spatial geometry. Keep the artwork, canvas edges, floor lines, windows, and main room planes crisp.',
            'scene_affinity' => ['windows', 'aerial_nadir', 'high_angle', 'visible_floor_plane', 'upper_balcony', 'skylight', 'architectural_context'],
            'negative_directives' => ['no eye-level wall view', 'no standing-height view', 'no centered frontal presentation', 'no simple elevated 3/4', 'no drone distance', 'no surveillance view', 'no decorative random people', 'no pets', 'no crowds', 'no children'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium photographic mockup using this exact camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

INPUT ROLES:
IMAGE 1 is the only source of truth for the artwork. Preserve exact identity, orientation, aspect ratio, colors, marks, sparse areas, and composition.
IMAGE 2 may only inform architectural DNA, material palette, window/skylight feeling, light quality, and premium spatial character. Do not copy its camera, layout, furniture placement, window placement, artwork placement, or composition.

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}

TASK:
Create a high aerial-nadir artwork mockup. The camera looks downward from near ceiling height, upper balcony height, skylight height, or the top edge of a tall architectural void into the room.

CAMERA:
Use a controlled 28-40 mm architectural lens. Use a steep downward aerial-nadir tilt. The floor is the receiving plane, and wall planes, windows, furniture, and the artwork wall drop downward through one coherent high-angle perspective.

COMPOSITION:
Build the image from above: visible floor plane, furniture or bench tops, rug or floor layout, wall height, window/skylight context, and artwork installed on a vertical wall. Use an oblique high-corner or upper-balcony diagonal, not a centered frontal wall view.

ARTWORK INTEGRITY:
The artwork remains identifiable and optically faithful to IMAGE 1. The canvas is rigid and rectangular, with coherent high-angle perspective. Do not bend, curve, stretch, compress, warp, reformat, repaint, or replace it.

SCALE:
Respect supplied dimensions. The artwork occupies a plausible portion of the wall; do not enlarge it into a mural, billboard, wall-sized architectural panel, oversized slab, or decorative wall finish. Do not shrink it into an accidental tiny object.

PAINTED EDGE:
If any edge, corner, or thickness is visible from above, it must be painted as a coherent continuation of IMAGE 1. No raw beige canvas edge, unpainted primer, bare wood, exposed stretcher bars, or blank neutral side band unless IMAGE 1 explicitly shows it.

NEGATIVE PROMPT:
No eye-level wall view, no standing-height view, no centered frontal presentation, no simple elevated 3/4, no drone distance, no surveillance view, no real-estate plan view, no artwork substitution, no invented painting, no warped canvas, no bent canvas, no stretched artwork, no compressed artwork, no billboard scaling, no mural scaling, no oversized panel, no raw beige canvas edge, no unpainted canvas edge, no bare wood edge, no decorative random people, no pets, no crowds, no children, no visible text, no logos, no watermarks.
PROMPT,
        ],
        'pasillo_obra_descentrada_proxima' => [
            'slot_id' => 'pasillo_obra_descentrada_proxima',
            'slot_name' => 'Pasillo, Obra Descentrada y Próxima',
            'enabled' => true,
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera height is natural standing or slightly lowered human height, close enough to feel the corridor edge.',
            'lens_block' => 'Use a 35-45 mm lens equivalent suited to corridor depth, with controlled perspective and no stretched hallway exaggeration.',
            'vertical_tilt_block' => 'Keep vertical tilt nearly level, allowing corridor lines and the artwork plane to stay believable.',
            'lateral_rotation_block' => 'Use an offset three-quarter corridor angle, with the artwork deliberately off-center and close rather than symmetrically framed.',
            'composition_block' => 'Place the artwork on a long wall plane rather than a short frontal display wall. The wall should extend laterally into the scene like a gallery passage, corridor, or elongated architectural plane, creating a clear vanishing direction. Use floor lines, ceiling lines, wall joints, lighting tracks, windows, columns, doorways, or distant wall planes to reinforce depth. The artwork may sit off-center and relatively close to the viewer, while the architecture recedes naturally to one side. Avoid product-style centered symmetry.',
            'human_subject_block' => '',
            'scale_block' => 'The artwork reads at its supplied physical size in a corridor offset composition, never as an assumed XL piece and never as a wall-filling billboard. Artwork identity protection: IMAGE 1 is determinative and overrides every camera, corridor, wall, lighting, depth-of-field, and world-mother cue. Preserve the exact root artwork content, colors, marks, sparse areas, composition, proportions, subject matter, faces if present, figure relationships if present, and internal visual structure from IMAGE 1. The camera may create corridor depth around the canvas, but it must not solve perspective by repainting, simplifying, beautifying, swapping, extending, completing, restyling, or reinterpreting the artwork surface. Do not invent a new painting, botanical brushwork, decorative gestures, room-inspired marks, world-mother faces, studio-wall imagery, or alternate pictorial content. Canvas integrity: the canvas is a rigid physical rectangle; the front face must not bend, curve, stretch, or become a wedge, lateral thickness stays moderate and plausible, and canvas plane, wall plane, floor lines, furniture, and architecture share one coherent vanishing logic.',
            'depth_of_field_block' => 'Set the focus plane firmly on the artwork. Keep the artwork surface, canvas edges, canvas thickness, artwork texture, and the wall area immediately around the artwork sharp and fully legible. Use a visible natural depth-of-field falloff with a subtle diorama-like effect only away from the artwork plane. The distant corridor, far wall planes, remote columns, background windows, far doorways, ceiling depth, floor depth, and the corridor end should fall noticeably but naturally out of focus. Foreground architectural edges may be slightly soft if they are closer to the camera than the artwork. The blur must feel optical and photographic, not artificial. Never blur the artwork, its canvas edges, its texture, or the immediate wall around it.',
            'scene_affinity' => ['corridor', 'offset_composition', 'near_wall', 'architectural_edge'],
            'negative_directives' => ['no billboard scaling', 'no fashion-model posing', 'no animals', 'no crowds', 'no children', 'no pets'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium photographic mockup using this exact camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

INPUT ROLES:
IMAGE 1 is the only source of truth for the artwork. The first attached reference image is IMAGE 1. IMAGE 1 is determinative: it overrides the corridor camera, the world mother, lighting, style, wall texture, depth of field, and any aesthetic improvement impulse. Preserve its exact identity, orientation, aspect ratio, colors, marks, sparse areas, composition, subject matter, faces if present, figure relationships if present, edges, and internal visual structure.
IMAGE 2 is the world mother for the environment. The second attached reference image is IMAGE 2. Keep its room type, material family, light logic, surface character, and spatial identity. Do not replace it with a generic corridor, hallway, gallery passage, or alternate invented environment. Adapt only the camera and artwork placement needed for this slot.
If IMAGE 2 contains paintings, canvases, easels, frames, drawings, posters, or other artworks, treat them only as environmental objects. Never copy their content, style, figures, faces, marks, colors, format, easel placement, or canvas layout into the final artwork. IMAGE 1 remains the only artwork content.

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}

TASK:
Create an offset close-wall composition inside the environment provided by IMAGE 2. If IMAGE 2 contains or naturally supports a corridor, passage, elongated wall, or receding architectural plane, use it. If not, keep a simpler near-wall composition from the world mother instead of inventing a corridor.

CAMERA:
Use natural standing or slightly lowered human height, close enough to feel the corridor edge. Use a 35-45 mm lens suited to corridor depth, with controlled perspective and no stretched hallway exaggeration. Keep vertical tilt nearly level.

COMPOSITION:
Use an offset three-quarter angle. Floor lines, ceiling lines, wall joints, lighting, windows, columns, doorways, or distant wall planes may reinforce depth only when they exist in IMAGE 2 or are naturally inferable from it. Avoid product-style centered symmetry and do not invent a new hallway structure.

FOCUS:
Set the focus plane firmly on the artwork. Keep artwork surface, canvas edges, thickness, texture, and immediate wall area sharp. Distant corridor, far wall planes, remote columns, background windows, far doorways, ceiling depth, floor depth, and corridor end may fall naturally out of focus. Blur must feel optical, not artificial.

ROOT ARTWORK DETERMINISM:
The final installed artwork must still be recognizably the same image as IMAGE 1 before it is a successful corridor photograph. The corridor viewpoint may add natural geometric perspective, side thickness, wall contact, shadows, and optical depth around the canvas, but it must not alter the painted image on the front face. Do not redraw, repaint, simplify, beautify, complete, stylize, swap, merge, or reinterpret any part of the artwork surface. If IMAGE 1 contains faces, figures, symbols, textural marks, sparse blank areas, color blocks, or unusual proportions, keep those exact relationships from IMAGE 1. If preserving the exact artwork conflicts with the corridor composition, simplify the surrounding room and keep IMAGE 1 unchanged.

SCALE:
Respect supplied dimensions. The artwork is {{ARTWORK_WIDTH_CM}} x {{ARTWORK_HEIGHT_CM}} cm, size class {{ARTWORK_SIZE_CLASS}}, and must read at that physical scale. Do not enlarge it to dominate the room or fill the available wall. For this slot, if the world mother has a large atelier wall, high ceiling, large window, tall easel, cabinet, door, stool, table, radiator, or chair, use those as scale anchors so the artwork remains plausibly at the supplied dimensions. Keep the artwork below door-height reading and below mural presence unless the supplied dimensions explicitly require otherwise.

PAINTED EDGE:
Visible edge, corner, or thickness must be painted as a coherent continuation of IMAGE 1. No raw beige canvas edge, unpainted primer, bare wood, exposed stretcher bars, or blank side band unless IMAGE 1 explicitly shows it.

NEGATIVE PROMPT:
No billboard scaling, no mural scaling, no oversized canvas, no room-dominating artwork, no wall-filling panel, no canvas enlarged to match window height, no centered product symmetry, no invented corridor, no generic hallway, no invented gallery passage, no alternate room type, no replacing IMAGE 2 environment, no world-mother substitution, no invented windows, no invented doorways, no invented long receding wall if IMAGE 2 does not support it, no artwork substitution, no invented painting, no repainted artwork surface, no redrawn artwork, no beautified artwork, no simplified artwork, no changed faces, no changed figures, no changed marks, no changed color blocks, no changed internal composition, no copying paintings from IMAGE 2, no copying easel artwork from IMAGE 2, no face or portrait from IMAGE 2, no figurative content from IMAGE 2, no botanical brushwork, no decorative gestures, no room-inspired marks, no warped canvas, no bent canvas, no stretched artwork, no compressed artwork, no raw beige canvas edge, no unpainted canvas edge, no bare wood edge, no fashion-model posing, no animals, no crowds, no children, no pets, no visible people, no visible text, no logos, no watermarks.
PROMPT,
        ],
        'borgona_recovecos_3_4_loft_hormigon' => [
            'slot_id' => 'borgona_recovecos_3_4_loft_hormigon',
            'slot_name' => 'Borgoña Recovecos / Cámara 3/4 en Loft de Hormigón',
            'enabled' => true,
            'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
            'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
            'camera_height_block' => 'Camera height is human but tucked into an architectural recess, approximately 120-160 cm depending on the loft edge.',
            'lens_block' => 'Use a 35-50 mm lens equivalent with tactile concrete depth and no aggressive wide-angle distortion.',
            'vertical_tilt_block' => 'Keep tilt controlled and mostly level so concrete planes, wall geometry, and artwork edges remain coherent.',
            'lateral_rotation_block' => 'Use a 3/4 view from a recess, corner, or architectural edge, with a burgundy-toned spatial mood and one clean vanishing direction.',
            'composition_block' => 'The artwork is partially contextualized by loft recesses and concrete architecture, creating depth and intimacy without hiding the piece; allow strong off-center framing through recesses, corners, side planes, or architectural edges.',
            'human_subject_block' => '',
            'scale_block' => 'The artwork remains a physically plausible canvas at its supplied physical size within the concrete loft, balanced by recess depth rather than global dominance. Canvas integrity: the canvas is a rigid physical rectangle; the front face must not bend, curve, stretch, or become a wedge, lateral thickness stays moderate and plausible, and canvas plane, wall plane, floor lines, furniture, and architecture share one coherent vanishing logic. Preserve the exact root artwork identity, colors, marks, sparse areas, composition, and proportions from IMAGE 1.',
            'depth_of_field_block' => 'Use controlled shallow spatial depth: recess edges, side planes, or distant loft surfaces may soften subtly, while the artwork face, canvas edges, and artwork texture remain fully sharp and legible.',
            'scene_affinity' => ['loft', 'concrete', 'recess', 'three_quarter', 'burgundy_mood'],
            'negative_directives' => ['no artwork deformation', 'no random decorative people', 'no animals', 'no crowds', 'no children', 'no pets'],
            'full_prompt_template' => <<<'PROMPT'
Generate one premium photographic mockup using this exact camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

INPUT ROLES:
IMAGE 1 is the only source of truth for the artwork. The first attached reference image is IMAGE 1. Preserve exact identity, orientation, aspect ratio, colors, marks, sparse areas, and composition.
IMAGE 2 is the world mother for the environment. The second attached reference image is IMAGE 2. Keep its room type, material family, light logic, surface character, and spatial identity. Do not replace it with a generic concrete loft, brutalist niche, burgundy room, or alternate invented environment. Adapt only the camera and artwork placement needed for this slot.
If IMAGE 2 contains paintings, canvases, easels, frames, drawings, posters, portraits, faces, figures, or other artworks, treat them only as environmental objects. Never copy their content, style, figures, faces, marks, colors, format, easel placement, or canvas layout into the final artwork. IMAGE 1 remains the only artwork content.

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}

TASK:
Create a three-quarter composition inside the environment provided by IMAGE 2. Use a recess, corner, side plane, or architectural edge only if IMAGE 2 contains it or naturally supports it. If the world mother does not support a recess, keep a simpler 3/4 wall composition rather than inventing a concrete niche.

CAMERA:
Use human camera height tucked into an architectural recess, approximately 120-160 cm depending on the loft edge. Use a 35-50 mm lens with tactile concrete depth and no aggressive wide-angle distortion. Keep tilt controlled and mostly level.

COMPOSITION:
Use a 3/4 view with one clean vanishing direction. Allow strong off-center framing through side planes, recesses, corners, or architectural edges only when they are present or naturally inferable from IMAGE 2. Any burgundy-toned mood must come from the world mother; do not impose it as a new room identity.

ARTWORK INTEGRITY:
The artwork face, canvas edges, and texture remain sharp and faithful to IMAGE 1. Do not deform, repaint, recolor, replace, or import loft marks, studio marks, easel paintings, portraits, faces, figures, or any artwork visible in IMAGE 2 into the artwork.

SCALE:
Respect supplied dimensions. The artwork is {{ARTWORK_WIDTH_CM}} x {{ARTWORK_HEIGHT_CM}} cm, size class {{ARTWORK_SIZE_CLASS}}, and remains physically plausible within the world mother space. It must be balanced by camera angle and wall/recess depth rather than global dominance. Use nearby room objects such as easels, windows, radiator, stool, table, cabinet, door, wall joints, floor boards, or existing canvases in IMAGE 2 only as scale anchors. No billboard, mural, oversized wall panel, room divider, stage prop, or canvas scaled to match the largest easel in IMAGE 2.

PAINTED EDGE:
Visible edge, corner, or thickness must be painted as a coherent continuation of IMAGE 1. No raw beige canvas edge, unpainted primer, bare wood, exposed stretcher bars, or blank side band unless IMAGE 1 explicitly shows it.

DEPTH:
Recess edges, side planes, or distant loft surfaces may soften subtly. The artwork and immediate wall/contact area stay crisp and legible.

NEGATIVE PROMPT:
No artwork deformation, no artwork substitution, no invented painting, no copied painting from IMAGE 2, no copied easel artwork from IMAGE 2, no portrait from IMAGE 2, no face from IMAGE 2, no figure from IMAGE 2, no recolored artwork, no invented concrete loft, no invented brutalist niche, no invented burgundy room, no invented recess, no alternate room type, no replacing IMAGE 2 environment, no world-mother substitution, no room-inspired marks, no decorative gestures, no warped canvas, no bent canvas, no stretched artwork, no compressed artwork, no billboard scaling, no mural scaling, no oversized panel, no canvas scaled to match easel paintings in IMAGE 2, no raw beige canvas edge, no unpainted canvas edge, no bare wood edge, no random decorative people, no animals, no crowds, no children, no pets, no visible text, no logos, no watermarks.
PROMPT,
        ],
    ],
];
$skipCustomCameraSlots = !empty($skipCustomCameraSlots);
$customCameraSlotsPath = __DIR__ . '/mockup_camera_slots_custom.php';
if (!$skipCustomCameraSlots && is_file($customCameraSlotsPath)) {
    $customCameraSlots = require $customCameraSlotsPath;
    if (is_array($customCameraSlots)) {
        foreach ((array)($customCameraSlots['slots'] ?? []) as $customCameraSlotId => $customCameraSlot) {
            if (is_string($customCameraSlotId) && is_array($customCameraSlot)) {
                $cameraSlotsConfig['slots'][$customCameraSlotId] = $customCameraSlot;
            }
        }
        foreach ((array)($customCameraSlots['sets'] ?? []) as $customCameraSetId => $customCameraSetPatch) {
            if (!is_string($customCameraSetId) || !is_array($customCameraSetPatch)) {
                continue;
            }
            if (!isset($cameraSlotsConfig['sets'][$customCameraSetId])) {
                $cameraSlotsConfig['sets'][$customCameraSetId] = [
                    'set_name' => (string)($customCameraSetPatch['set_name'] ?? ucwords(str_replace('_', ' ', $customCameraSetId))),
                    'slots' => [],
                ];
            }
            $currentCameraSetSlots = (array)($cameraSlotsConfig['sets'][$customCameraSetId]['slots'] ?? []);
            $extraCameraSetSlots = (array)($customCameraSetPatch['slots'] ?? []);
            $cameraSlotsConfig['sets'][$customCameraSetId]['slots'] = array_values(array_unique(array_merge($currentCameraSetSlots, array_map('strval', $extraCameraSetSlots))));
        }
        if (isset($customCameraSlots['scene_board']) && is_array($customCameraSlots['scene_board'])) {
            $cameraSlotsConfig['scene_board'] = $customCameraSlots['scene_board'];
        }
        if (isset($customCameraSlots['scene_boards']) && is_array($customCameraSlots['scene_boards'])) {
            $cameraSlotsConfig['scene_boards'] = $customCameraSlots['scene_boards'];
        }
    }
}

return $cameraSlotsConfig;
