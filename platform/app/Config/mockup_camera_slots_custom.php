<?php
declare(strict_types=1);

return array (
  'sets' =>
  array (
    'phase_2_6_experimental_v1' =>
    array (
      'set_name' => 'FASE 2.6 experimental camera composition set',
      'slots' =>
      array (
        0 => 'obra_apoyada_suelo_7_8',
        1 => 'nadir_extremo_arquitectonico',
      ),
    ),
    'phase_2_6_artistic_detail_v1' =>
    array (
      'set_name' => 'FASE 2.6 artistic physical artwork detail set',
      'slots' =>
      array (
        0 => 'vista_aerea_obra_piso_contexto_cenital',
        1 => 'esquina_obra_perspectiva_extrema',
        2 => 'detalle_textura_lienzo',
        3 => 'borde_canvas_closeup',
      ),
    ),
    'phase_2_4_default_v1' =>
    array (
      'set_name' => 'FASE 2.4 default camera slot set',
      'slots' =>
      array (
        0 => 'contrapicado_7_8',
        1 => 'vista_aerea_contexto_ventanas',
      ),
    ),
  ),
  'slots' =>
  array (
    'vista_aerea_obra_piso_contexto_cenital' =>
    array (
      'slot_id' => 'vista_aerea_obra_piso_contexto_cenital',
      'slot_name' => 'Vista Aérea Cenital de Obra en Suelo con Contexto Ambiental',
      'enabled' => true,
      'fidelity_mode' => 'premium',
      'size_classes_supported' =>
      array (
        0 => 'pequeña',
        1 => 'mediana',
        2 => 'grande',
        3 => 'extragrande',
      ),
      'orientation_supported' =>
      array (
        0 => 'vertical',
        1 => 'horizontal',
      ),
      'camera_height_block' => 'Cámara elevada, mirando hacia abajo de forma cenital o casi cenital (entre 85 y 90 grados de inclinación vertical), posicionada directamente encima de la obra para una vista aérea amplia.',
      'lens_block' => 'Lente de tipo gran angular o normal para capturar la obra y su entorno cercano sin distorsión óptica extrema. Ángulo de visión amplio que abarca la obra y parte del contexto circundante.',
      'vertical_tilt_block' => 'Inclinación vertical entre 85 y 90 grados respecto al plano del suelo, buscando una perspectiva estrictamente cenital o muy cercana a ella.',
      'lateral_rotation_block' => 'Rotación lateral de 0 grados (sin rotación) o mínima para asegurar que la composición del contexto alrededor de la obra se perciba de manera equilibrada y natural.',
      'composition_block' => 'La obra de arte (IMAGE 1) está colocada horizontalmente sobre el suelo y sirve como punto focal principal de la composición. Se muestra en su totalidad o casi en su totalidad, centrada o ligeramente descentrada para permitir la inclusión de elementos de contexto ambiental. El suelo es el plano principal y se extiende visiblemente más allá de los bordes de la obra. El contexto circundante (como una alfombra, mesa baja, sofá, cortinas, ventanas o sutiles líneas del piso) debe ser visible y orgánico, integrando la obra en un espacio habitado y creíble. Evitar recortes abruptos de la obra. La obra debe leerse como el sujeto principal y no como un elemento decorativo secundario o un patrón del suelo.',
      'human_subject_block' => 'No incluir sujetos humanos ni partes del cuerpo (manos, pies, sombras de personas). Cualquier mueble como un sofá o silla debe estar vacío y servir únicamente como parte del entorno ambiental.',
      'scale_block' => 'La obra (IMAGE 1) se renderiza a escala real y proporcional respecto al entorno. Debe aparecer como un objeto físico genuino apoyado en el suelo, conservando sus dimensiones y perspectiva. Asegurar que el tamaño aparente de la obra sea coherente con el tipo de habitación o entorno representado, evitando escalas que la hagan parecer una valla publicitaria o un mural.',
      'depth_of_field_block' => 'Profundidad de campo moderada a amplia para asegurar que tanto la obra de arte como una parte significativa de su contexto circundante estén nítidos. Un ligero y sutil desenfoque en los elementos más distantes del fondo puede ser aceptable para dirigir la atención hacia la obra, pero la mayor parte de la escena debe estar enfocada.',
      'scene_affinity' =>
      array (
        0 => 'interiores',
        1 => 'minimalista',
        2 => 'moderno',
        3 => 'loft',
        4 => 'estudio',
        5 => 'hogar',
        6 => 'sala de estar',
        7 => 'galería no tradicional',
        8 => 'boutique',
        9 => 'diseño de interiores',
        10 => 'arte sobre el suelo',
        11 => 'vista de planta',
        12 => 'cenital de interiores.',
      ),
      'negative_directives' =>
      array (
        0 => 'Obra de arte montada en la pared.',
        1 => 'Obra de arte vertical como si estuviera colgada.',
        2 => 'Vista frontal de una pared.',
        3 => 'Obra de arte colgando de la pared.',
        4 => 'Vista a la altura de los ojos o de pie.',
        5 => 'Sofá reemplazando la obra de arte como sujeto principal.',
        6 => 'Mesa reemplazando la obra de arte como sujeto principal.',
        7 => 'Alfombra reemplazando la obra de arte como sujeto principal.',
        8 => 'Sustitución o alteración de la obra de arte (IMAGE 1).',
        9 => 'Pintura inventada o genérica.',
        10 => 'Lienzo deformado o curvado.',
        11 => 'Obra de arte estirada o comprimida.',
        12 => 'Escalado tipo valla publicitaria o mural gigante.',
        13 => 'Texto visible en la escena, excepto si es parte intrínseca de IMAGE 1.',
        14 => 'Logotipos o marcas de agua.',
        15 => 'Cualquier distorsión que no sea la perspectiva natural de una vista cenital.',
        16 => 'Obra de arte recortada de forma que parezca flotar o no estar anclada al suelo.',
        17 => 'Obra de arte suspendida en el aire.',
        18 => 'Ángulo contrapicado o en picado leve.',
        19 => 'Objetos bloqueando la visión total o casi total de la obra de arte.',
        20 => 'Iluminación que cree reflejos excesivos, deslumbramientos o anule la visibilidad y detalles de la obra.',
        21 => 'Sombras excesivas que oculten partes significativas de la obra de arte.',
        22 => 'Personas o animales en la escena.',
      ),
      'full_prompt_template' => 'Una vista aérea cenital, de alta calidad y resolución, de una obra de arte con un título \'{{ARTWORK_TITLE}}\' (`IMAGE 1`) que mide {{ARTWORK_WIDTH_CM}}cm de ancho por {{ARTWORK_HEIGHT_CM}}cm de alto con orientación {{ARTWORK_ORIENTATION}} y de la clase de tamaño {{ARTWORK_SIZE_CLASS}}. La obra de arte está colocada horizontalmente y plana sobre un [material_piso, ej. suelo de madera clara con grano sutil, alfombra de lana bouclé gris, hormigón pulido con juntas minimalistas] en un [tipo_habitación, ej. loft moderno con luz natural, sala de estar minimalista y acogedora, estudio de diseño con muebles de líneas limpias]. La cámara está directamente encima, mirando hacia abajo con una perspectiva cenital. La composición muestra la obra de arte completa y parte del entorno inmediato. Alrededor de la obra, hay elementos de contexto ambiental que la integran al espacio, como [descripción_contexto, ej. una alfombra de tonos neutros que enmarca la obra, una mesa baja de diseño escandinavo con una única planta en maceta, el borde de un sofá de lino natural, sutiles líneas de baldosas que convergen o el reflejo suave de una ventana grande en el suelo pulido]. El estilo y la atmósfera de la escena están fuertemente influenciados por el ADN ambiental de `IMAGE 2`, fusionando sus características atmosféricas y detalles contextuales con la composición principal. La iluminación es [tipo_luz, ej. natural y difusa proveniente de una ventana lateral, suave y uniforme desde el techo] creando sombras sutiles que anclan la obra al espacio. La obra debe verse como una pieza física real, cuidadosamente ubicada en el espacio, no como una imagen flotante o digitalmente pegada. Los detalles finos del suelo y del entorno son visibles. La profundidad de campo es moderada, manteniendo la obra y su contexto principal nítidos y realistas. Esta es una representación de alta fidelidad, de calidad premium, centrada en la obra \'{{ARTWORK_TITLE}}\' en un entorno doméstico o de galería no tradicional.',
      'primary_scene_set' => false,
    ),
    'detalle_textura_lienzo' =>
    array (
      'slot_id' => 'detalle_textura_lienzo',
      'slot_name' => 'Canvas Close-Up',
      'enabled' => true,
      'fidelity_mode' => 'artistic_optical_distortion',
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'Camera is very close to the artwork surface, using a close-up or semi-macro viewpoint focused on the physical painted skin of the canvas rather than the full room.',
      'lens_block' => 'Use a macro or close-focus lens equivalent with natural optical rendering, enough detail resolution to show the existing pigment, canvas weave, brushwork, veladuras, or surface marks visible in the root artwork without making the artwork look digital.',
      'vertical_tilt_block' => 'Use only the vertical tilt needed to reveal the existing material texture already present in the root artwork. Full artwork readability is not required; selected texture areas must remain physically believable.',
      'lateral_rotation_block' => 'Use a close material angle across the painting surface, allowing partial cropping and shallow spatial depth while preserving the visible artwork identity.',
      'composition_block' => 'Partial cropping is allowed and expected. Close-up material framing and mild optical distortion from the close lens are allowed. The canvas or painted surface may fill most of the frame, showing only a fragment of the artwork. The purpose is to document the real surface already visible in the root artwork: pigment, brush marks, canvas weave, and existing physical texture. Do not add artificial impasto, invented relief, cracks, incisions, extra ridges, or new tactile marks.',
      'human_subject_block' => '',
      'scale_block' => 'Material scale protection: the visible surface remains a real physical artwork, not a poster, flat print, digital screen, or decorative texture. Artwork identity protection: preserve the real colors, visible marks, existing surface texture, and abstract visual identity of the provided artwork. Anti-substitution protection: do not invent new marks, artificial impasto, extra relief, figures, symbols, faces, landscapes, decorative motifs, or another painting.',
      'depth_of_field_block' => 'Shallow depth of field is allowed, but the selected artwork texture area must remain sharp and legible. Any blur must fall only outside the chosen focus plane and must feel optical and photographic.',
      'scene_affinity' =>
      array (
        0 => 'macro_texture',
        1 => 'canvas_surface',
        2 => 'material_detail',
        3 => 'pigment',
        4 => 'brushwork',
      ),
      'negative_directives' =>
      array (
        0 => 'no poster',
        1 => 'no flat print',
        2 => 'no digital screen',
        3 => 'no invented imagery',
        4 => 'no artwork substitution',
        5 => 'no plastic surface',
        6 => 'no pets',
        7 => 'no crowds',
        8 => 'no children',
      ),
      'full_prompt_template' => 'Generate one premium photographic mockup using this exact camera slot only:
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
No poster, no flat print, no digital screen, no invented imagery, no artwork substitution, no artificial impasto, no invented relief, no cracks, no scratches, no incisions, no extra ridges, no wet paint, no glossy plastic shine, no embossed effects, no heavy paste, no new tactile marks, no room atmosphere, no full room, no furniture, no easel, no table setup, no shelves, no large windows, no window-dominant composition, no dramatic atelier mood, no cinematic haze, no golden-room storytelling, no people, no pets, no crowds, no children, no visible text, no logos, no watermarks.',
      'primary_scene_set' => true,
      'board_order' => 4,
    ),
    'borde_canvas_closeup' =>
    array (
      'slot_id' => 'borde_canvas_closeup',
      'slot_name' => 'Canvas Edge Close-Up',
      'enabled' => true,
      'fidelity_mode' => 'artistic_optical_distortion',
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'Camera is close to the wall-mounted canvas edge, positioned at artwork height or slightly oblique to read the front face, lateral side, thickness, and shadow against the nearby wall.',
      'lens_block' => 'Use a close-focus 50-70 mm lens equivalent or similar natural close-up lens that preserves plausible canvas thickness and avoids wide-angle exaggeration.',
      'vertical_tilt_block' => 'Keep vertical tilt controlled and material-oriented, enough to reveal edge depth and wall contact without making the canvas float or bend.',
      'lateral_rotation_block' => 'Use a close side-oblique angle across the canvas edge so the transition between painted front, lateral canvas depth, stretcher thickness, and wall shadow is visible.',
      'composition_block' => 'Partial artwork view is allowed and expected. This is a camera crop, not an artwork alteration. The full artwork does not need to be visible, and one or more artwork edges may fall outside the final image frame. Close-up optical distortion and mild foreshortening are allowed if they come from the physical lens and edge perspective. The canvas edge and thickness are the subject. The artwork may be cropped by the camera frame, with wall texture, lateral side, subtle cast shadow, and the physical transition from front surface to side edge carrying the composition. Preserve the original artwork format even when only a fragment is visible: a portrait artwork must still read as a fragment of a taller-than-wide canvas, a landscape artwork as a fragment of a wider-than-tall canvas, and a square artwork as square. Do not complete, widen, compress, or redesign the visible fragment into a different full-format painting.',
      'human_subject_block' => '',
      'scale_block' => 'Physical canvas protection: edge thickness must remain moderate and plausible for the real artwork depth; it must look like stretched canvas, not poster, paper, screen, floating panel, or exaggerated block. Artwork proportion protection: the physical canvas keeps the source artwork aspect ratio and orientation even if only a partial close-up is shown. Artwork identity protection: preserve exact artwork identity on the visible portion. Anti-substitution protection: do not replace the artwork with another image.',
      'depth_of_field_block' => 'Use close-up photographic depth of field. Canvas edge, visible artwork portion, side depth, and immediate wall contact should remain sharp; distant wall texture may soften gently.',
      'scene_affinity' =>
      array (
        0 => 'canvas_edge',
        1 => 'wall_shadow',
        2 => 'closeup',
        3 => 'stretched_canvas',
        4 => 'material_depth',
      ),
      'negative_directives' =>
      array (
        0 => 'no poster',
        1 => 'no flat print',
        2 => 'no digital screen',
        3 => 'no floating panel',
        4 => 'no exaggerated thickness',
        5 => 'no artwork substitution',
        6 => 'no pets',
        7 => 'no crowds',
        8 => 'no children',
      ),
      'full_prompt_template' => 'Generate one premium photographic mockup using this exact camera slot only:
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
No poster, no flat print, no paper sheet, no digital screen, no floating panel, no exaggerated thickness, no block canvas, no slab, no plinth, no box, no wedge, no invented frame, no ornate frame, no raw wood stretcher bars unless visible in IMAGE 1, no staples unless visible in IMAGE 1, no redesigned side edge, no changed artwork format, no squared portrait artwork, no stretched artwork, no compressed artwork, no artwork substitution, no invented imagery, no invented marks, no artificial impasto, no new tactile marks, no full room, no room atmosphere, no furniture, no easel, no table setup, no shelves, no large windows, no window-dominant composition, no dramatic atelier mood, no cinematic haze, no golden-room storytelling, no people, no pets, no crowds, no children, no visible text, no logos, no watermarks.',
      'primary_scene_set' => false,
    ),
    'rasante_superficie_pintura' =>
    array (
      'slot_id' => 'rasante_superficie_pintura',
      'slot_name' => 'Canvas Texture Close-Up',
      'enabled' => false,
      'fidelity_mode' => 'artistic_optical_distortion',
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'Camera is extremely close and almost parallel to the painting surface, like a grazing shot across the skin of the artwork plane.',
      'lens_block' => 'Use a close-focus macro or material-detail lens equivalent with natural shallow optical depth, emphasizing only the pigment, texture, brushwork, and physical surface presence already visible in the root artwork.',
      'vertical_tilt_block' => 'Use a very low grazing angle along the painting surface. The full artwork does not need to be visible; the focus must fall on a real visible area of the artwork surface.',
      'lateral_rotation_block' => 'Run the viewpoint laterally along the painting plane so the existing texture creates depth, with partial cropping expected and no need for full rectangular readability.',
      'composition_block' => 'Partial cropping is expected. The image is a rasante view over the artwork surface, emphasizing the real material skin, pigment depth, shine, and texture transitions already present in the root artwork rather than room context. Do not add artificial impasto, invented relief, scratches, cracks, incisions, extra ridges, or new tactile marks.',
      'human_subject_block' => '',
      'scale_block' => 'Material protection: the surface must remain a real painted canvas or artwork material, not plastic, glossy digital, poster-like, printed paper, or screen-like. Artwork identity protection: preserve the provided artwork color world, visible marks, and existing material identity. Anti-substitution protection: do not invent new imagery, artificial impasto, extra relief, or replace the painting.',
      'depth_of_field_block' => 'Shallow depth of field is allowed and expected. The selected focus ridge, mark, pigment area, or texture band must be sharp; foreground and background surface areas may soften naturally along the grazing plane.',
      'scene_affinity' =>
      array (
        0 => 'grazing_surface',
        1 => 'macro_material',
        2 => 'pigment_relief',
        3 => 'brushwork',
        4 => 'surface_skin',
      ),
      'negative_directives' =>
      array (
        0 => 'no plastic surface',
        1 => 'no glossy digital look',
        2 => 'no digital screen',
        3 => 'no poster-like print',
        4 => 'no printed-paper appearance',
        5 => 'no invented imagery',
        6 => 'no artwork substitution',
        7 => 'no pets',
        8 => 'no crowds',
        9 => 'no children',
      ),
      'full_prompt_template' => 'Generate one premium photographic mockup using this exact camera slot only:
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
No plastic surface, no glossy digital look, no digital screen, no poster-like print, no printed-paper appearance, no invented imagery, no artwork substitution, no artificial impasto, no invented relief, no cracks, no scratches, no incisions, no extra ridges, no wet paint, no embossed effects, no new tactile marks, no full room, no room atmosphere, no furniture, no easel, no table setup, no shelves, no large windows, no dramatic atelier mood, no cinematic haze, no people, no pets, no crowds, no children, no visible text, no logos, no watermarks.',
      'deleted_from_studio' => true,
      'primary_scene_set' => false,
    ),
    'luz_dorada_sombra_diagonal' =>
    array (
      'slot_id' => 'luz_dorada_sombra_diagonal',
      'slot_name' => 'Golden Hour',
      'enabled' => false,
      'fidelity_mode' => 'adaptacion_camara_world_mother',
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'Camera height is natural gallery or collector viewing height, approximately 120-170 cm, calm enough to let golden window light and diagonal shadow geometry structure the image.',
      'lens_block' => 'Use a refined 40-55 mm lens equivalent with natural compression, preserving artwork scale, wall plane, and light/shadow transitions without distortion.',
      'vertical_tilt_block' => 'Use minimal vertical tilt. Keep the artwork plane calm, legible, and physically credible while light and shadow provide the compositional energy.',
      'lateral_rotation_block' => 'Use a soft frontal-oblique or moderate 3/4 camera angle, enough to show wall depth and room atmosphere without competing with the diagonal light composition.',
      'composition_block' => 'Build the composition from golden late-afternoon window light and a strong diagonal shadow across wall or floor. Place the artwork near the transition between light and shadow. The room may feel like a palazzo, atelier, townhouse, warm gallery, or Mediterranean interior. Avoid overexposing the artwork; the artwork remains sharp, faithful, and visually readable.',
      'human_subject_block' => '',
      'scale_block' => 'The artwork remains a physically plausible wall-mounted canvas near the light/shadow transition. Canvas perspective protection: keep the canvas rectangular, rigid, and aligned with wall and floor perspective; do not warp, deform, bend, taper, or turn it into a wedge. Artwork fidelity protection: preserve the exact abstract artwork from the reference image; do not recolor it with golden light, repaint it, replace it, or reinterpret it.',
      'depth_of_field_block' => 'Use little to moderate natural depth of field. The artwork surface, canvas edges, texture, and light/shadow boundary near the artwork remain crisp; only distant room planes may soften gently.',
      'scene_affinity' =>
      array (
        0 => 'golden_light',
        1 => 'diagonal_shadow',
        2 => 'palazzo',
        3 => 'atelier',
        4 => 'townhouse',
        5 => 'mediterranean',
      ),
      'negative_directives' =>
      array (
        0 => 'no overexposed artwork',
        1 => 'no recolored artwork',
        2 => 'no artwork substitution',
        3 => 'no poster effect',
        4 => 'no pets',
        5 => 'no crowds',
        6 => 'no children',
      ),
      'full_prompt_template' => 'Generate one premium photographic mockup using this exact camera slot only:
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
No overexposed artwork, no recolored artwork, no golden repainting of the artwork, no washed-out artwork, no generic warm room, no invented palazzo, no invented atelier, no invented townhouse, no invented Mediterranean interior, no alternate room type, no replacing IMAGE 2 environment, no world-mother substitution, no invented windows, no invented diagonal light source, no artwork substitution, no invented painting, no added marks, no decorative brushwork, no room-inspired marks, no warped canvas, no poster effect, no digital screen, no billboard scaling, no mural scaling, no raw beige canvas edge, no unpainted canvas edge, no bare wood edge, no pets, no crowds, no children, no visible people, no visible text, no logos, no watermarks.',
      'deleted_from_studio' => true,
      'primary_scene_set' => false,
    ),
    'esquina_obra_perspectiva_extrema' =>
    array (
      'slot_id' => 'esquina_obra_perspectiva_extrema',
      'slot_name' => 'Canvas Corner Close-Up',
      'enabled' => true,
      'fidelity_mode' => 'artistic_optical_distortion',
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'Camera is placed extremely close to one physical canvas corner or edge, almost touching the artwork plane, so the image cuts aggressively across the artwork rather than showing a normal room mockup.',
      'lens_block' => 'Use a close optical lens equivalent with shallow depth and strong foreshortening. The lens may crop the artwork hard and skim across the surface, but must not melt, bend, tear, curve, or reshape the canvas geometry.',
      'vertical_tilt_block' => 'Use only the tilt needed to cut across the artwork corner and reveal thickness, surface texture, subtle sheen, humidity-like highlights, and edge depth. The canvas remains a rigid physical rectangle under optical perspective.',
      'lateral_rotation_block' => 'Use an aggressive side-oblique angle across one real artwork corner, around 10-25 degrees along the artwork plane, so the frame cuts the artwork and may exclude large portions of it. The camera should read as a close artistic slice over the painting surface, not a distant side view of a room.',
      'composition_block' => 'Partial cropping is required. The full artwork should usually not be visible. Let the image cut through one angle or corner of the artwork, with the canvas edge, front surface, thickness, texture, slight moisture-like sheen, and gentle gloss carrying the composition. Preserve the local composition exactly in the visible area: existing lines, color blocks, marks, boundaries, symbols, and proportions must remain from the reference artwork. Do not invent landscapes, figures, new symbols, new brush marks, or a different pictorial composition.',
      'human_subject_block' => '',
      'scale_block' => 'Physical canvas protection: the canvas may look dramatic through optical perspective, but it must not melt, bend, curve, tear, warp, taper into an impossible wedge, or lose rigid rectangular credibility. Artwork identity protection: preserve visible colors, marks, texture, abstract identity, and local composition from the provided artwork. Anti-substitution protection: do not replace it with figurative, classical, historical, portrait, landscape, decorative, or invented painting. Do not repaint the visible fragment, do not change the composition inside the cropped area, and do not turn surface sheen into new imagery.',
      'depth_of_field_block' => 'Shallow depth of field is allowed. The selected physical corner, nearby edge, surface texture, subtle gloss, humidity-like highlights, and visible artwork marks must remain sharp enough to read as the real artwork; only distant cropped surface areas may fall softly out of focus.',
      'scene_affinity' =>
      array (
        0 => 'canvas_corner',
        1 => 'aggressive_crop',
        2 => 'extreme_perspective',
        3 => 'foreshortening',
        4 => 'material_edge',
        5 => 'surface_sheen',
        6 => 'artistic_detail',
      ),
      'negative_directives' =>
      array (
        0 => 'no full-room side view',
        1 => 'no distant mockup view',
        2 => 'no melted canvas',
        3 => 'no bent canvas',
        4 => 'no torn canvas',
        5 => 'no impossible wedge',
        6 => 'no changed composition',
        7 => 'no invented marks',
        8 => 'no invented landscape',
        9 => 'no digital screen',
        10 => 'no artwork substitution',
        11 => 'no invented painting',
        12 => 'no pets',
        13 => 'no crowds',
        14 => 'no children',
      ),
      'full_prompt_template' => 'Generate one premium photographic mockup using this exact camera slot only:
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
No full-room side view, no distant mockup view, no normal room mockup, no melted canvas, no bent canvas, no curved canvas, no torn canvas, no impossible wedge, no warped canvas, no changed composition, no invented marks, no invented landscape, no figures, no portraits, no decorative motifs, no digital screen, no poster, no flat print, no artwork substitution, no invented painting, no artificial impasto, no cracks, no scratches, no incisions, no extra ridges, no wet paint, no raw beige canvas edge, no unpainted canvas edge, no bare wood edge, no exposed stretcher bars, no room atmosphere, no furniture, no easel, no large windows, no people, no pets, no crowds, no children, no visible text, no logos, no watermarks.',
      'primary_scene_set' => false,
    ),
    'obra_apoyada_suelo_7_8' =>
    array (
      'slot_id' => 'obra_apoyada_suelo_7_8',
      'slot_name' => 'Floor-Leaning Artwork 3/4 View',
      'enabled' => true,
      'fidelity_mode' => 'adaptacion_camara_world_mother',
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'Camera height is low or medium-low, approximately 35-90 cm from the floor, close enough to read the physical bottom edge, floor contact, floor plane, wall plane, and leaning relationship.',
      'lens_block' => 'Use a disciplined 45-60 mm lens equivalent that preserves believable scale, canvas depth, and wall/floor geometry without wide-angle stretching or heroic enlargement.',
      'vertical_tilt_block' => 'Use a modest vertical tilt only as needed to keep the leaning artwork readable; avoid forcing the front face into an impossible skew, billboard scale, or theatrical monumental angle.',
      'lateral_rotation_block' => 'Use a restrained 3/4 or 7/8 side-oblique view so the side edge, canvas depth, exact floor contact, and slight backward lean against the wall are visible through one coherent perspective.',
      'composition_block' => 'The artwork is not hanging. It must lean with physically believable gravity either against a real vertical wall or against a real stable object that could plausibly support a canvas in an atelier or collector preview, such as a low cabinet, sturdy table edge, studio rack, crate, storage block, easel-like support, or large furniture side. The support must be coherent with the room, not an invented monumental display plinth. The bottom edge should rest on the real floor or on a clearly stable low surface with visible contact shadows and believable load-bearing contact. Use a slight backward lean of about 5-12 degrees only. The scene may read as atelier, collector preview, clean storage wall, or refined studio. Off-center framing is allowed so floor, support object, wall, and side depth communicate the physical object, but the artwork must stay physically grounded and naturally placed.',
      'human_subject_block' => '',
      'scale_block' => 'The artwork keeps physically plausible real scale according to the artwork technical data supplied in the final prompt, leaning on the floor or against a stable support. It is a physical studio artwork at its supplied dimensions, not a monumental wall panel, billboard, room divider, stage prop, or oversized slab. The artwork height should read lower than a normal adult person unless the supplied artwork dimensions explicitly indicate otherwise, and it should remain well below a typical door height when the artwork dimensions support that reading. Canvas perspective protection: keep the front face rectangular and optically faithful, side thickness moderate, bottom edge grounded, and floor/support contact believable. Artwork fidelity protection: preserve the exact abstract artwork from the reference image; do not replace it with figurative, classical, historical, portrait, landscape, decorative, or old-master-style painting.',
      'depth_of_field_block' => 'Keep the artwork face, canvas edges, bottom edge, contact point, and texture sharp. Subtle depth falloff may soften distant studio objects or far wall planes, but never the artwork or its physical support contact.',
      'scene_affinity' =>
      array (
        0 => 'atelier',
        1 => 'collector_preview',
        2 => 'storage_wall',
        3 => 'studio_floor',
        4 => 'leaning_canvas',
      ),
      'negative_directives' =>
      array (
        0 => 'no hanging artwork',
        1 => 'no wall-mounted artwork',
        2 => 'no impossible support',
        3 => 'no invented monumental plinth',
        4 => 'no floating canvas',
        5 => 'no unsupported canvas',
        6 => 'no unstable balancing',
        7 => 'no arbitrary placement',
        8 => 'no oversized canvas',
        9 => 'no billboard scaling',
        10 => 'no poster',
        11 => 'no flat print',
        12 => 'no canvas wedge',
        13 => 'no artwork substitution',
        14 => 'no pets',
        15 => 'no crowds',
        16 => 'no children',
      ),
      'full_prompt_template' => 'Usa IMAGEN 1 como fuente absoluta de la obra y IMAGEN 2 solo como referencia de materiales, luz y atmósfera.

Genera un mockup fotográfico en contrapicado extremo a ras de suelo. La obra está de pie, apoyada contra una pared o soporte estable, con el borde inferior tocando el piso. La cámara está muy cerca del borde inferior del lienzo, usando un ultra gran angular rectilíneo, para crear una perspectiva dramática desde abajo donde la base del canvas domina el primer plano y la parte superior se aleja.

La obra debe conservarse exactamente fiel a IMAGEN 1.
El ambiente debe tomar de IMAGEN 2 solo su carácter material y lumínico, apareciendo de forma secundaria y periférica.

No vista frontal, no cámara a nivel de ojos, no vista desde arriba, no ojo de pez, no obra colgada, no obra acostada, no obra flotante, no deformación blanda, no rediseño de la pintura.',
      'primary_scene_set' => false,
    ),
    'diagonal_estudio_moderno' =>
    array (
      'slot_id' => 'diagonal_estudio_moderno',
      'slot_name' => 'FRONT View',
      'enabled' => true,
      'fidelity_mode' => 'adaptacion_camara_world_mother',
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'La altura de la cámara es media o moderadamente baja.',
      'lens_block' => 'Utilice un objetivo arquitectónico editorial de 35-50 mm evitando una anchura exagerada y conservando una buena sensación de profundida',
      'vertical_tilt_block' => 'Mantén la inclinación vertical controlada y lo más natural posible, de modo que la composición diagonal provenga de la arquitectura, la luz y el encuadre, en lugar de la distorsión.',
      'lateral_rotation_block' => 'Utilice una composición diagonal de tres cuartos donde las líneas del suelo, las líneas del techo, las barandillas, los bancos, las luces lineales, las juntas de las paredes, las sombras o las paredes laterales conduzcan hacia la obra de arte.',
      'composition_block' => 'Las líneas diagonales marcadas guían al espectador hacia la obra de arte como destino visual. Se permite y se recomienda la colocación descentrada cuando refuerza el flujo diagonal. La fotografía debe tener un aire contemporáneo, editorial y espacial, en lugar de estar centrada y parecer un producto.',
      'human_subject_block' => '',
      'scale_block' => '',
      'depth_of_field_block' => '',
      'scene_affinity' =>
      array (
        0 => 'modern_studio',
        1 => 'diagonal_lines',
        2 => 'editorial',
        3 => 'architectural_edges',
        4 => 'linear_lighting',
      ),
      'negative_directives' =>
      array (
      ),
      'full_prompt_template' => 'Generar un mockup fotográfico premium a partir de IMAGEN 1 e IMAGEN 2.

ROLES DE ENTRADA:
IMAGEN 1 La primera imagen de referencia adjunta es IMAGEN 1. Preservar su identidad.

IMAGEN 2 es la referencia para crear el estilo de entorno. Mantener su estilo, familia de materiales, lógica de luz, carácter de superficie e identidad espacial. No reemplazarla con un pasillo genérico, un vestíbulo, un pasaje de galería o un entorno inventado alternativo.

Si la IMAGEN 2 contiene pinturas, lienzos, caballetes, marcos, dibujos, carteles u otras obras de arte, tratarlas solo como objetos ambientales. IMAGEN 1 es la obra de arte más importante en la nueva imagen. La escala de IMAGEN 1 debe estar en relación con los otros objetos de la composición, sean ventanas, sillones, muebles, lámparas, mesas. Prestar especial atención en sitios de doble altura. Nunca sobredimensionar la obra de arte o IMAGEN 1 para darle protagonismo. 

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}

CAMARA
Usar una altura humana natural (nivel de los ojos respecto a la obra de arte). Colocar la cámara perpendicular a la pared donde se ubica la obra. Usar un objetivo de 50-85 mm para reducir la distorsión de perspectiva y asegurar que las líneas verticales de la pared se mantengan rectas y paralelas a los bordes del marco.

La obra de arte debe ocupar el centro de la imagen con una simetría natural. La pared de fondo debe ser paralela al plano de la imagen. La obra debe estar alineada al nivel de los ojos del espectador. La arquitectura y los objetos de la IMAGEN 2 (muebles, ventanas, etc.) deben servir como marco simétrico o equilibrado para la obra, sin invadir el protagonismo de la misma. Evitar ángulos de fuga que distraigan la atención hacia los lados.3.  


ENFOQUE:
Establecer el plano de enfoque en la obra de arte. Mantener la superficie de la obra, los bordes del lienzo, el grosor, la textura y el área inmediata de la pared nítidos. Los planos de pared lejanos, las columnas remotas, las ventanas de fondo, las puertas lejanas, la profundidad del techo, la profundidad del suelo pueden desenfocarse naturalmente. El desenfoque debe sentirse óptico, no artificial. 



DETERMINISMO DE LA OBRA DE ARTE:
La obra de arte final instalada debe ser la misma imagen que la IMAGEN 1. No redibujar, repintar, simplificar, embellecer, completar, estilizar, intercambiar, fusionar o reinterpretar ninguna parte de la superficie de la obra de arte o IMAGEN 1. Si la IMAGEN 1 contiene rostros, figuras, símbolos, marcas texturales, áreas en blanco dispersas, bloques de color o proporciones inusuales, mantener esas relaciones exactas de la IMAGEN 1. Nunca sobredimensionar la obra de arte o IMAGEN 1 para darle protagonismo. 

ESCALA:
Respetar las dimensiones suministradas ({{ARTWORK_WIDTH_CM}} x {{ARTWORK_HEIGHT_CM}} cm). Al usar una vista frontal, es crítico que la obra de arte se mantenga a escala real comparada con los objetos cercanos (muebles, interruptores, marcos de puertas o altura del techo). No llenar la pared con la obra de arte. Dejar espacio negativo alrededor (arriba, abajo y a los lados) para que la obra "respire" y mantenga su relación proporcional con el mobiliario de la IMAGEN 

Prohibido: Vista de tres cuartos, vista diagonal, composición descentrada, perspectiva de fuga profunda, ángulos de cámara exagerados, distorsión de lente gran angular, simetría forzada que deforme la obra, que la obra sea más grande que el mueble de referencia debajo de ella, efecto de mural, que la obra toque el techo o el suelo.',
      'primary_scene_set' => true,
      'board_order' => 1,
    ),
    'nadir_extremo_arquitectonico' =>
    array (
      'slot_id' => 'nadir_extremo_arquitectonico',
      'slot_name' => 'NADIR : Extreme Low-Angle View',
      'enabled' => true,
      'fidelity_mode' => 'adaptacion_camara_world_mother',
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'Camera is positioned at true floor level, approximately 1-3 cm from the floor, almost touching the ground, as if the lens is lying on the floor at the corner of the room. The camera is not centered in front of the artwork; it sits near one lateral wall or floor/wall junction and looks sharply upward across the artwork zone. The floor must dominate the lower foreground and feel physically close to the lens, while walls, columns, beams, slabs, doors, luminaires, wall joints, or vertical architectural planes tower upward through the composition.',
      'lens_block' => 'Use an extreme architectural wide lens equivalent, about 14-20 mm. Strong perspective exaggeration, stretched floor foreground, rising verticals, diagonal convergence, and dramatic optical depth are allowed and desired. Avoid cartoon circular fisheye rings, but do not sanitize the perspective into a normal 24-35 mm gallery view.',
      'vertical_tilt_block' => 'Use an unmistakable steep upward nadir or contrapicado tilt, almost vertical from the floor toward the wall, ceiling, beams, slabs, skylights, and vertical structure. The camera must look up from below and beside the artwork zone, not from a centered frontal axis. The artwork may be visibly foreshortened by perspective, but it must remain recognizable as the same rigid physical artwork.',
      'lateral_rotation_block' => 'Use an extreme low diagonal rotation from a floor corner or side-wall position, with a strong upward vanishing direction shared by the artwork, wall, floor, ceiling, furniture, and architectural verticals. The room should feel pulled into a steep diagonal vortex. Avoid any centered, symmetrical, straight-on product wall view.',
      'composition_block' => 'Build the drama through a radical bottom-up diagonal architectural view. Do not center the artwork as a frontal monument. The lower floor edge or near floor plane should occupy a large part of the lower frame, with one side wall or floor/wall seam rushing past the camera and architecture rising overhead. Extreme nadir views work best in spaces with mass, height, and clear architectural structure: brutalist architecture, exposed concrete galleries, tall vertical walls, architectural atriums, industrial minimalist lofts, high ceiling collector spaces, concrete beams, slabs, columns, skylights, or vertical openings. The drama comes from extreme off-axis camera position, stretched floor foreground, rising verticals, diagonal convergence, and exaggerated spatial depth in the room only. Floor lines, vertical wall planes, ceiling height, columns, door frames, beams, luminaires, furniture legs, wall joints, and overhead structural planes must visibly carry the nadir effect. The artwork remains intentionally framed at its supplied physical scale and exact visible layout; do not enlarge, reformat, rotate, repaint, or recompose it to compete with the architecture. The camera can make the surrounding room perspective feel almost deformed, but the artwork must preserve its original format, mark placement, color fields, empty areas, and composition.',
      'human_subject_block' => '',
      'scale_block' => 'The artwork remains a physically plausible canvas in the room. Canvas perspective protection: the canvas is a rigid rectangular physical object under aggressive lens perspective; controlled foreshortening is allowed, but do not melt, tear, curve, liquid-warp, rotate to a different format, or replace the artwork identity. Artwork fidelity protection: preserve the exact abstract artwork from the reference image, including original orientation, aspect ratio, color fields, mark placement, empty areas, sparse composition, and proportions. Do not replace it with figurative, classical, historical, portrait, landscape, decorative, old-master-style, or newly invented abstract painting.',
      'depth_of_field_block' => 'Keep the artwork, canvas edges, canvas thickness, and artwork texture sharp and legible. Only very close floor foreground or distant ceiling and architectural planes may soften slightly; the blur must remain subtle, optical, and photographic.',
      'scene_affinity' =>
      array (
        0 => 'floor_plane',
        1 => 'nadir',
        2 => 'architectural_verticals',
        3 => 'columns',
        4 => 'ceiling_height',
        5 => 'brutalist_architecture',
        6 => 'exposed_concrete_gallery',
        7 => 'architectural_atrium',
        8 => 'industrial_minimalist_loft',
        9 => 'high_ceiling_collector_space',
      ),
      'negative_directives' =>
      array (
        0 => 'no centered frontal composition',
        1 => 'no symmetrical monument view',
        2 => 'no straight-on product wall view',
        3 => 'no eye-level view',
        4 => 'no standing-height view',
        5 => 'no normal 3/4 gallery photo',
        6 => 'no gentle low angle',
        7 => 'no soft controlled viewpoint',
        8 => 'no flat wall view',
        9 => 'no melted artwork',
        10 => 'no torn canvas',
        11 => 'no artwork substitution',
        12 => 'no artwork reinterpretation',
        13 => 'no classical painting substitution',
        14 => 'no low domestic rooms',
        15 => 'no cozy living rooms',
        16 => 'no cramped rooms',
        17 => 'no pets',
        18 => 'no crowds',
        19 => 'no children',
      ),
      'full_prompt_template' => 'Usa IMAGEN 1 como fuente absoluta de la obra y IMAGEN 2 solo como referencia de materiales, luz y atmósfera.

Genera un mockup fotográfico en contrapicado extremo a ras de suelo. La obra está de pie, apoyada contra una pared o soporte estable, con el borde inferior tocando el piso. La cámara está muy cerca del borde inferior del lienzo, usando un ultra gran angular rectilíneo, para crear una perspectiva dramática desde abajo donde la base del canvas domina el primer plano y la parte superior se aleja.

La obra debe conservarse exactamente fiel a IMAGEN 1.
El ambiente debe tomar de IMAGEN 2 solo su carácter material y lumínico, apareciendo de forma secundaria y periférica.

No vista frontal, no cámara a nivel de ojos, no vista desde arriba, no ojo de pez, no obra colgada, no obra acostada, no obra flotante, no deformación blanda, no rediseño de la pintura.',
      'primary_scene_set' => false,
    ),
    'vista_aerea_contexto_ventanas' =>
    array (
      'slot_id' => 'vista_aerea_contexto_ventanas',
      'slot_name' => 'Aerial View',
      'enabled' => true,
      'fidelity_mode' => 'adaptacion_camara_world_mother',
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'Camera is positioned very high, near ceiling height, upper balcony height, skylight height, or the top edge of a tall architectural void. It is the aerial counterpart of an extreme nadir: instead of looking upward from the floor, the camera looks downward from above into the room.',
      'lens_block' => 'Use a controlled 28-40 mm architectural lens equivalent, wide enough to read the room from above but restrained enough to avoid fisheye, drone, surveillance, or real-estate plan-view behavior.',
      'vertical_tilt_block' => 'Use a steep downward aerial-nadir tilt. The floor is the receiving plane of the image, and wall planes, windows, furniture, and the artwork wall drop downward through one coherent high-angle perspective.',
      'lateral_rotation_block' => 'Use an oblique high-corner or upper-balcony diagonal, not a centered frontal wall view. The camera may look diagonally down along the room so window context, floor geometry, and artwork wall are visible together.',
      'composition_block' => 'Build the image from above: visible floor plane, furniture or bench tops, rug or floor layout, wall height, window/skylight context, and the artwork installed on a vertical wall. The artwork remains identifiable, but the room must read as seen from above, not as a normal wall presentation.',
      'human_subject_block' => '',
      'scale_block' => 'The artwork occupies a plausible portion of the wall in an aerial-nadir composition with strong visible floor plane and surrounding architecture. Canvas integrity: the canvas is a rigid physical rectangle; the front face must not bend, curve, stretch, or become a wedge, lateral thickness stays moderate and plausible, and canvas plane, wall plane, floor lines, windows, furniture, and architecture share one coherent downward high-angle vanishing logic.',
      'depth_of_field_block' => 'Use little to no depth of field because the elevated architectural view needs readable spatial geometry. Keep the artwork, canvas edges, floor lines, windows, and main room planes crisp.',
      'scene_affinity' =>
      array (
        0 => 'windows',
        1 => 'aerial_nadir',
        2 => 'high_angle',
        3 => 'visible_floor_plane',
        4 => 'upper_balcony',
        5 => 'skylight',
        6 => 'architectural_context',
      ),
      'negative_directives' =>
      array (
        0 => 'no eye-level wall view',
        1 => 'no standing-height view',
        2 => 'no centered frontal presentation',
        3 => 'no simple elevated 3/4',
        4 => 'no drone distance',
        5 => 'no surveillance view',
        6 => 'no decorative random people',
        7 => 'no pets',
        8 => 'no crowds',
        9 => 'no children',
      ),
      'full_prompt_template' => 'CAMARA — VISTA AÉREA DESDE ENTREPISO:
La cámara se ubica en un entrepiso o nivel superior (mezzanine), mirando hacia abajo hacia la obra de arte instalada en planta baja. El ángulo de cámara es picado (high angle / bird\'s eye parcial), no cenital puro — debe conservarse una lectura de profundidad vertical real entre el nivel de cámara y el nivel de la obra, no una vista de planta.

Lente 28-35mm equivalente, apertura f/4.0-f/5.6. Un lente angular corto es necesario aquí (no telefoto) para capturar en un mismo encuadre tanto el elemento arquitectónico que aloja la cámara (baranda, losa de entrepiso, escalera, estructura de doble altura) como la obra completa en planta baja, preservando la sensación de espacio vertical. Mantener distorsión de barril mínima — sin curvatura exagerada en líneas rectas de baranda, losa o marcos de ventana cercanos a cámara.

COMPOSICIÓN:
El primer plano puede incluir elementos del entrepiso (baranda, piso, estructura, pasamanos) parcialmente visibles en los bordes del encuadre, reforzando la sensación de mirar hacia abajo desde una altura real. Las líneas de fuga del techo, columnas, losas de entrepiso y muros deben converger hacia la obra en planta baja, guiando la mirada del espectador desde el punto de cámara hacia el objeto. Evitar composición centrada; la obra puede ubicarse en el tercio inferior o lateral del encuadre, nunca ocupando el centro exacto de manera simétrica.

ÁNGULO SOBRE LA OBRA:
La obra de arte, vista desde arriba y a distancia, debe mostrar escorzo vertical: el borde superior del marco (más cercano a cámara en altura) se ve levemente más grande que el borde inferior (más lejano), y la superficie pintada puede leerse con una leve inclinación trapezoidal vertical — no como un rectángulo perfecto visto de frente. Este escorzo es la prueba de que la altura de cámara es real y no una vista frontal reubicada.

ENFOQUE:
Plano de enfoque en la obra de arte en planta baja. Los elementos del entrepiso en primer plano (baranda, estructura) pueden desenfocarse naturalmente por su proximidad a cámara, generando profundidad de campo real. El desenfoque debe sentirse óptico, no artificial.

DETERMINISMO DE LA OBRA DE ARTE (contenido, no perspectiva):
La superficie pintada final debe ser la misma imagen que IMAGEN 1 en cuanto a contenido: no redibujar, repintar, simplificar, embellecer, completar, estilizar, intercambiar, fusionar ni reinterpretar rostros, figuras, símbolos, marcas texturales, bloques de color o proporciones internas. La perspectiva final la determina exclusivamente esta sección CAMARA.

ESCALA:
La obra de arte es de {{ARTWORK_WIDTH_CM}} x {{ARTWORK_HEIGHT_CM}} cm, clase de tamaño {{ARTWORK_SIZE_CLASS}}. Al ser una vista aérea con distancia considerable entre cámara y obra, prestar especial atención a que la obra no se perciba desproporcionadamente grande respecto a los elementos de planta baja (sillones, mesas, piso). Usar la escala humana o mobiliario visible como referencia. Nunca sobredimensionar la obra para darle protagonismo artificial a pesar de la distancia de cámara.',
      'primary_scene_set' => false,
    ),
    'contrapicado_7_8' =>
    array (
      'slot_id' => 'contrapicado_7_8',
      'slot_name' => 'Low-Angle Wall/Floor View',
      'enabled' => true,
      'fidelity_mode' => 'adaptacion_camara_world_mother',
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'Camera height is aggressively low, 5-20 cm from the floor, clearly far below knee height. The viewpoint must feel like the lens is near the floor and close to the wall, not standing height, not chest height, and not a normal eye-level gallery view.',
      'lens_block' => 'Use an aggressive wide architectural lens equivalent, about 20-28 mm. Strong side-depth exaggeration, stretched near foreground, and intense perspective convergence are allowed and desired. Avoid cartoon circular fisheye, but do not normalize the view into a polite 35-50 mm product photo.',
      'vertical_tilt_block' => 'Use a strong visible upward low-angle tilt from below the artwork centerline. The viewer should read the underside/lower edge relationship of the canvas, with wall verticals, ceiling lines, track lights, or architecture rising sharply upward. If the result could be mistaken for a normal eye-level 3/4 wall view, the camera is wrong.',
      'lateral_rotation_block' => 'Use a very strong 7/8 oblique rotation so the wall, artwork side depth, floor plane, and room volume rush toward one shared vanishing direction. The side of the canvas can feel dramatically deep through perspective, while the artwork remains a rigid object.',
      'composition_block' => 'The artwork is seen with aggressive side depth and believable thickness from a near-floor 7/8 viewpoint. Include a large visible floor wedge or floor plane in the lower foreground, with wall/floor junctions and architectural verticals proving the camera is below knee height. Use strong asymmetry, a close foreground edge, and negative space toward the vanishing side while keeping surrounding wall and floor aligned to the same camera. This must feel almost perspective-deformed and must not collapse into a standard standing-height 3/4 gallery photograph.',
      'human_subject_block' => '',
      'scale_block' => 'The artwork may be strongly framed by the camera, but its apparent size must remain consistent with the supplied physical artwork dimensions. Canvas integrity: the canvas is a rigid physical rectangle under aggressive perspective; strong foreshortening and exaggerated side-depth are allowed, but the front face must not melt, tear, curve, liquid-warp, or become a different artwork. Preserve the exact root artwork identity, colors, marks, sparse areas, composition, and proportions from IMAGE 1. Canvas plane, wall plane, floor lines, furniture, and architecture share one coherent vanishing logic.',
      'depth_of_field_block' => 'Use controlled photographic depth of field to separate foreground, artwork, and vanishing-side architecture: the artwork face, canvas edges, and visible texture remain completely sharp; only secondary foreground or distant background planes may soften subtly.',
      'scene_affinity' =>
      array (
        0 => 'loft',
        1 => 'gallery',
        2 => 'statement_wall',
        3 => 'architectural_depth',
      ),
      'negative_directives' =>
      array (
        0 => 'no eye-level view',
        1 => 'no standing-height view',
        2 => 'no normal 3/4 gallery photo',
        3 => 'no gentle low angle',
        4 => 'no soft controlled viewpoint',
        5 => 'no flat wall view',
        6 => 'no melted artwork',
        7 => 'no torn canvas',
        8 => 'no artwork substitution',
        9 => 'no random people',
        10 => 'no pets',
        11 => 'no crowds',
        12 => 'no children',
      ),
      'full_prompt_template' => 'Generar un mockup fotográfico premium a partir de IMAGEN 1 e IMAGEN 2.

ROLES DE ENTRADA:
IMAGEN 1: primera imagen de referencia adjunta. Es la obra de arte. Preservar su identidad visual — colores, formas, proporciones internas, texturas, marcas, superficie — Esta preservación se refiere únicamente al CONTENIDO de la obra, no a su perspectiva o ángulo de cámara, que se define exclusivamente en la sección CAMARA de este prompt.

IMAGEN 2: referencia de entorno. Mantener su estilo, familia de materiales, lógica de luz, carácter de superficie e identidad espacial. No reemplazarla con un pasillo genérico, un vestíbulo, un pasaje de galería o un entorno inventado alternativo.

Si la IMAGEN 2 contiene pinturas, lienzos, caballetes, marcos, dibujos, carteles u otras obras de arte, tratarlas solo como objetos ambientales. IMAGEN 1 es la obra de arte más importante en la nueva imagen.

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}


Genera un mockup fotográfico en contrapicado extremo casi nadir desde un lateral de la obra de arte, como si la cámara estuviera colocada en el suelo en el vértice de la obra de arte. Esta imagen busca la perspectiva artística de la fotografia de la obra de arte.

La obra debe estar muy cerca de la cámara, entrando desde el primer plano inferior. El borde inferior del lienzo debe sentirse cercano y dominante. La parte superior de la obra desenfoca levemente.

Usa un lente ultra gran angular rectilíneo, sin ojo de pez. La perspectiva debe sentirse dramática y arquitectónica, similar a una fotografía tomada desde el suelo mirando hacia una torre, una fachada alta o una persona vista desde abajo.

ENFOQUE:
Establecer el plano de enfoque en la obra de arte. Mantener la superficie de la obra, los bordes del lienzo, el grosor, la textura y el área inmediata de la pared nítidos. Los planos de pared lejanos, columnas remotas, ventanas de fondo, puertas lejanas, profundidad del techo y del suelo pueden desenfocarse naturalmente. El desenfoque debe sentirse óptico, no artificial.

DETERMINISMO DE LA OBRA DE ARTE (contenido, no perspectiva):
La superficie pintada final debe ser la misma imagen que IMAGEN 1 en cuanto a contenido: no redibujar, repintar, simplificar, embellecer, completar, estilizar, intercambiar, fusionar ni reinterpretar rostros, figuras, símbolos, marcas texturales, áreas en blanco dispersas, bloques de color o proporciones internas. Esto no implica preservar el ángulo o la perspectiva de IMAGEN 1 — la perspectiva final la determina exclusivamente la sección CAMARA.

ESCALA:
La obra de arte es de {{ARTWORK_WIDTH_CM}} x {{ARTWORK_HEIGHT_CM}} cm, clase de tamaño {{ARTWORK_SIZE_CLASS}}. No ampliarla para que domine la habitación o llene la pared disponible. Usar otros objetos de la escena (ventanas, sillones, muebles, lámparas, mesas) como referencia de escala real. Prestar especial atención en sitios de doble altura. Nunca sobredimensionar la obra de arte para darle protagonismo.

IMAGEN 1 debe conservarse fiel: composición, colores, marcas, proporciones internas y superficie pictórica. La pintura no debe rediseñarse. La deformación permitida es solo perspectiva óptica causada por la cercanía y el ángulo de cámara.

El ambiente de IMAGEN 2 debe aparecer solo parcialmente en los bordes, arriba o al fondo, como contexto secundario. No copiar la cámara ni la composición de IMAGEN 2.

No queremos: vista frontal, cámara a nivel normal, cámara mirando recto a la pared, obra plana contra el muro, obra centrada como postal, sala completa simétrica, obra colgada, obra acostada, ojo de pez, mural gigante, rediseño de la obra, deformación blanda, lienzo curvado, pintura inventada.',
      'primary_scene_set' => true,
      'board_order' => 2,
    ),
    'reflejo_dorado_tarde_palazzo' =>
    array (
      'slot_id' => 'reflejo_dorado_tarde_palazzo',
      'slot_name' => 'Reflejo Dorado de Tarde / Palazzo',
      'enabled' => false,
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'Camera height is elegant human standing height, approximately 150-170 cm, as if observed calmly from inside a palazzo room.',
      'lens_block' => 'Use a refined 40-50 mm lens equivalent with natural compression and no exaggerated room depth.',
      'vertical_tilt_block' => 'Keep vertical tilt minimal and steady, preserving dignified wall geometry and a calm institutional reading.',
      'lateral_rotation_block' => 'Use a soft three-quarter rotation that catches reflected late light while keeping the artwork plane legible.',
      'composition_block' => 'The composition is stable, warm, and architectural, with golden afternoon reflection supporting the room rather than repainting the artwork; keep elegant balance but avoid perfect symmetry unless the room itself naturally demands it.',
      'human_subject_block' => '',
      'scale_block' => 'The artwork is a plausible wall-mounted object at its supplied physical size in a refined interior, neither miniaturized nor monumentally enlarged. Canvas integrity: the canvas is a rigid physical rectangle; the front face must not bend, curve, stretch, or become a wedge, lateral thickness stays moderate and plausible, and canvas plane, wall plane, floor lines, furniture, and architecture share one coherent vanishing logic. Preserve the exact root artwork identity, colors, marks, sparse areas, composition, and proportions from IMAGE 1.',
      'depth_of_field_block' => 'Use minimal depth of field. The room, artwork, canvas edges, and artwork texture should remain mostly crisp; any softness is barely perceptible and limited to distant secondary planes.',
      'scene_affinity' =>
      array (
        0 => 'palazzo',
        1 => 'collector_room',
        2 => 'warm_reflection',
        3 => 'late_afternoon',
      ),
      'negative_directives' =>
      array (
        0 => 'no artwork color redescription',
        1 => 'no fashion-model posing',
        2 => 'no animals',
        3 => 'no crowds',
        4 => 'no children',
        5 => 'no pets',
      ),
      'full_prompt_template' => 'Generate one premium photographic mockup using this exact camera slot only:
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
No artwork color redescription, no recolored artwork, no golden repainting of the artwork, no overexposed artwork, no washed-out artwork, no artwork substitution, no invented painting, no added marks, no decorative brushwork, no warped canvas, no bent canvas, no billboard scaling, no mural scaling, no oversized panel, no raw beige canvas edge, no unpainted canvas edge, no bare wood edge, no fashion-model posing, no animals, no crowds, no children, no pets, no visible people, no visible text, no logos, no watermarks.',
      'deleted_from_studio' => true,
      'primary_scene_set' => false,
    ),
    'camara_15_contrapicado_inpainting' =>
    array (
      'slot_id' => 'camara_15_contrapicado_inpainting',
      'slot_name' => 'Cámara 15 / Contrapicado Fuerte con Inpainting',
      'enabled' => false,
      'generation_strategy' => 'inpainting_precomposition',
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'Camera is at true floor level, approximately 2-8 cm from the floor, placed close to the artwork wall and slightly to the right of the artwork, looking upward with a forceful low-angle attack.',
      'lens_block' => 'Use a dramatic but architectural 18-24 mm lens equivalent. Strong rising verticals, stretched near-floor foreground, and deep upward perspective are desired, but avoid cartoon fisheye rings.',
      'vertical_tilt_block' => 'Use a very strong upward contrapicado tilt from below the artwork centerline. The wall, ceiling, beams, windows, columns, or track lights must rise sharply above the artwork.',
      'lateral_rotation_block' => 'Use a low 7/8 right oblique rotation so the wall, floor plane, artwork edge, and overhead architecture share one coherent vanishing logic.',
      'composition_block' => 'Build a powerful floor-level architectural view: a large floor wedge in the foreground, a visible wall/floor junction near the artwork, and tall architecture rising behind and above it. The artwork must remain protected at its real precomposed scale and should feel physically installed, not enlarged into a mural.',
      'human_subject_block' => '',
      'scale_block' => 'Inpainting scale test: the artwork is precomposed at the supplied physical dimensions before environment generation. The mask protects the artwork face and physical footprint. The final image may dramatize the room, floor, and ceiling through camera perspective, but must not change artwork size, aspect ratio, orientation, mark placement, color fields, or identity.',
      'depth_of_field_block' => 'Keep the artwork and immediate wall contact sharp. Very close floor foreground or distant ceiling structure may soften slightly, but the artwork must never blur.',
      'scene_affinity' =>
      array (
        0 => 'inpainting',
        1 => 'floor_level',
        2 => 'contrapicado',
        3 => 'rising_architecture',
        4 => 'high_ceiling',
      ),
      'best_fit' =>
      array (
        0 => 'tall vertical wall',
        1 => 'high ceiling studio',
        2 => 'architectural atrium',
        3 => 'strong vertical structure',
        4 => 'tall wall with beams or windows',
      ),
      'negative_directives' =>
      array (
        0 => 'no eye-level view',
        1 => 'no standing-height view',
        2 => 'no normal 3/4 gallery photo',
        3 => 'no gentle low angle',
        4 => 'no centered product wall view',
        5 => 'no mural scaling',
        6 => 'no billboard scaling',
        7 => 'no oversized panel',
        8 => 'no artwork substitution',
        9 => 'no warped artwork',
        10 => 'no bent artwork',
        11 => 'no stretched artwork',
        12 => 'no compressed artwork',
        13 => 'no floating artwork',
        14 => 'no pets',
        15 => 'no crowds',
        16 => 'no children',
      ),
      'full_prompt_template' => 'Generate one premium photographic mockup using this exact experimental camera slot only:
Camera Slot ID: {{CAMERA_SLOT_ID}}
Camera Slot Name: {{CAMERA_SLOT_NAME}}

EXPERIMENTAL INPAINTING STRATEGY:
This is Camera 15. Use an inpainting-first reading: IMAGE 1 must be treated as the protected root artwork, precomposed at real physical scale before the room is generated. The environment may be rebuilt around it, but the artwork face, physical footprint, aspect ratio, orientation, and relative size must remain locked.

INPUT ROLES:
IMAGE 1 is the only source of truth for the artwork. Preserve exact identity, colors, mark placement, sparse areas, surface rhythm, proportions, and format.
IMAGE 2, if present, is the world mother and the authority for environmental DNA: wall material, floor material, architectural mass, light temperature, and mood. Reproduce IMAGE 2\'s material and light identity faithfully; do not default to concrete, industrial, or loft materials unless IMAGE 2 actually shows them. Do not copy its camera angle, layout, furniture placement, wall choice, window placement, crop, or perspective.

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
No eye-level view, no standing-height view, no normal 3/4 gallery photo, no gentle low angle, no centered product wall view, no symmetrical monument view, no mural scaling, no billboard scaling, no oversized canvas, no room-dominating artwork, no wall-filling panel, no architectural panel, no changed artwork scale, no changed artwork format, no rotated artwork, no stretched artwork, no compressed artwork, no warped artwork, no bent artwork, no melted artwork, no floating artwork, no artwork substitution, no invented painting, no copied painting from IMAGE 2, no room-inspired marks on artwork, no classical painting substitution, no portrait replacement, no landscape replacement, no visible measurement labels, no visible text, no logos, no watermarks, no pets, no crowds, no children.',
      'deleted_from_studio' => true,
      'primary_scene_set' => false,
    ),
    'pasillo_obra_descentrada_proxima' =>
    array (
      'slot_id' => 'pasillo_obra_descentrada_proxima',
      'slot_name' => '3/4 Left View',
      'enabled' => true,
      'fidelity_mode' => 'adaptacion_camara_world_mother',
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'Camera height is natural standing or slightly lowered human height, close enough to feel the corridor edge.',
      'lens_block' => 'Use a 35-45 mm lens equivalent suited to corridor depth, with controlled perspective and no stretched hallway exaggeration.',
      'vertical_tilt_block' => 'Keep vertical tilt nearly level, allowing corridor lines and the artwork plane to stay believable.',
      'lateral_rotation_block' => 'Use an offset three-quarter corridor angle, with the artwork deliberately off-center and close rather than symmetrically framed.',
      'composition_block' => 'Place the artwork on a long wall plane rather than a short frontal display wall. The wall should extend laterally into the scene like a gallery passage, corridor, or elongated architectural plane, creating a clear vanishing direction. Use floor lines, ceiling lines, wall joints, lighting tracks, windows, columns, doorways, or distant wall planes to reinforce depth. The artwork may sit off-center and relatively close to the viewer, while the architecture recedes naturally to one side. Avoid product-style centered symmetry.',
      'human_subject_block' => '',
      'scale_block' => 'The artwork reads at its supplied physical size in a corridor offset composition, never as an assumed XL piece and never as a wall-filling billboard. Artwork identity protection: IMAGE 1 is determinative and overrides every camera, corridor, wall, lighting, depth-of-field, and world-mother cue. Preserve the exact root artwork content, colors, marks, sparse areas, composition, proportions, subject matter, faces if present, figure relationships if present, and internal visual structure from IMAGE 1. The camera may create corridor depth around the canvas, but it must not solve perspective by repainting, simplifying, beautifying, swapping, extending, completing, restyling, or reinterpreting the artwork surface. Do not invent a new painting, botanical brushwork, decorative gestures, room-inspired marks, world-mother faces, studio-wall imagery, or alternate pictorial content. Canvas integrity: the canvas is a rigid physical rectangle; the front face must not bend, curve, stretch, or become a wedge, lateral thickness stays moderate and plausible, and canvas plane, wall plane, floor lines, furniture, and architecture share one coherent vanishing logic.',
      'depth_of_field_block' => 'Set the focus plane firmly on the artwork. Keep the artwork surface, canvas edges, canvas thickness, artwork texture, and the wall area immediately around the artwork sharp and fully legible. Use a visible natural depth-of-field falloff with a subtle diorama-like effect only away from the artwork plane. The distant corridor, far wall planes, remote columns, background windows, far doorways, ceiling depth, floor depth, and the corridor end should fall noticeably but naturally out of focus. Foreground architectural edges may be slightly soft if they are closer to the camera than the artwork. The blur must feel optical and photographic, not artificial. Never blur the artwork, its canvas edges, its texture, or the immediate wall around it.',
      'scene_affinity' =>
      array (
        0 => 'corridor',
        1 => 'offset_composition',
        2 => 'near_wall',
        3 => 'architectural_edge',
      ),
      'negative_directives' =>
      array (
        0 => 'no billboard scaling',
        1 => 'no fashion-model posing',
        2 => 'no animals',
        3 => 'no crowds',
        4 => 'no children',
        5 => 'no pets',
      ),
      'full_prompt_template' => 'Generar un mockup fotográfico premium a partir de IMAGEN 1 e IMAGEN 2.

ROLES DE ENTRADA:
IMAGEN 1 La primera imagen de referencia adjunta es IMAGEN 1. Preservar su identidad.


IMAGEN 2 es la referencia para crear el estilo de entorno. Mantener su estilo, familia de materiales, lógica de luz, carácter de superficie e identidad espacial. No reemplazarla con un pasillo genérico, un vestíbulo, un pasaje de galería o un entorno inventado alternativo.


Si la IMAGEN 2 contiene pinturas, lienzos, caballetes, marcos, dibujos, carteles u otras obras de arte, tratarlas solo como objetos ambientales. IMAGEN 1 es la obra de arte más importante en la nueva imagen. La escala de IMAGEN 1 debe estar en relación con los otros objetos de la composición, sean ventanas, sillones, muebles, lámparas, mesas. Prestar especial atención en sitios de doble altura. Nunca sobredimensionar la obra de arte o IMAGEN 1 para darle protagonismo. 

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}

CAMARA
Usar una altura humana natural de pie o ligeramente bajada. Usar un objetivo de 35-45 mm adecuado para la profundidad, con perspectiva controlada.

COMPOSICIÓN:
Usar un ángulo descentrado de tres cuartos derecho. Las líneas del suelo, las líneas del techo, las uniones de las paredes, la iluminación, las ventanas, las columnas, las puertas o los planos de pared distantes pueden reforzar la profundidad. Evitar la simetría centrada para presentar la obra de arte.

ENFOQUE:
Establecer el plano de enfoque en la obra de arte. Mantener la superficie de la obra, los bordes del lienzo, el grosor, la textura y el área inmediata de la pared nítidos. Los planos de pared lejanos, las columnas remotas, las ventanas de fondo, las puertas lejanas, la profundidad del techo, la profundidad del suelo pueden desenfocarse naturalmente. El desenfoque debe sentirse óptico, no artificial. 

DETERMINISMO DE LA OBRA DE ARTE:


La obra de arte final instalada debe ser la misma imagen que la IMAGEN 1. No redibujar, repintar, simplificar, embellecer, completar, estilizar, intercambiar, fusionar o reinterpretar ninguna parte de la superficie de la obra de arte o IMAGEN 1. Si la IMAGEN 1 contiene rostros, figuras, símbolos, marcas texturales, áreas en blanco dispersas, bloques de color o proporciones inusuales, mantener esas relaciones exactas de la IMAGEN 1. Nunca sobredimensionar la obra de arte o IMAGEN 1 para darle protagonismo. 

ESCALA:
La obra de arte es de {{ARTWORK_WIDTH_CM}} x {{ARTWORK_HEIGHT_CM}} cm, clase de tamaño {{ARTWORK_SIZE_CLASS}}
No ampliarla para que domine la habitación o llene la pared disponible. Para determinar el tamaño de la obra de arte utilice otros objetos de la escena. Mantener la obra de arte en su tamaño normal respecto a los otros objetos. Nunca sobredimensionar la obra de arte o IMAGEN 1 para darle protagonismo.',
      'primary_scene_set' => false,
    ),
    'contrapicado_raton_puro' =>
    array (
      'slot_id' => 'contrapicado_raton_puro',
      'slot_name' => 'Low Angle :: Nadir',
      'enabled' => true,
      'fidelity_mode' => 'adaptacion_camara_world_mother',
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'Camera is positioned extremely low, approximately 5-15 cm from the ground, with the lens nearly touching the floor. This is a mouse-level/nadir-inspired architectural viewpoint, not a gentle low 3/4. The floor plane must be large and close in the foreground while wall height, ceiling lines, columns, door frames or architectural verticals rise strongly.',
      'lens_block' => 'Use an aggressive wide architectural lens equivalent, about 20-26 mm. Strong perspective expansion, stretched near floor foreground, and dramatic vanishing lines are allowed and desired, while avoiding cartoon circular fisheye.',
      'vertical_tilt_block' => 'Use a strong upward nadir-inspired tilt toward the wall and surrounding architecture. The camera must visibly look up from near floor level. The artwork may be seen from below with clear foreshortening, yet it must remain recognizable as a rigid physical canvas.',
      'lateral_rotation_block' => 'Use an assertive oblique angle across the room, not a straight-on product view, with one forceful vanishing direction for architecture and artwork. The wall, floor, ceiling, and artwork should feel pulled into the same steep perspective.',
      'composition_block' => 'Use the nadir effect strongly through the surrounding room geometry: a large foreground floor wedge, floor lines, vertical wall planes, ceiling height, architectural edges, columns, furniture legs or door frames. The artwork remains legible and intentionally placed at its supplied physical scale, while the dramatic low viewpoint must be obvious at thumbnail size. Do not produce a polite standing-height gallery view.',
      'human_subject_block' => '',
      'scale_block' => 'The artwork is a rigid rectangular physical canvas under aggressive lens perspective. Controlled foreshortening and strong side-depth exaggeration are allowed; do not melt, tear, curve, liquid-warp, or replace the artwork identity. Preserve the exact abstract artwork from the reference image. Its apparent size must remain consistent with the supplied physical artwork dimensions; do not enlarge it to dominate the room. Canvas plane, wall plane, floor lines, furniture, and architecture share one coherent vanishing logic.',
      'depth_of_field_block' => 'Keep the artwork, canvas edges and artwork texture sharp. A slight softness may appear only in very close floor foreground or distant ceiling/architectural planes. Do not blur the artwork.',
      'scene_affinity' =>
      array (
        0 => 'floor_plane',
        1 => 'studio',
        2 => 'gallery',
        3 => 'architectural_wall',
      ),
      'negative_directives' =>
      array (
        0 => 'no eye-level view',
        1 => 'no standing-height view',
        2 => 'no normal 3/4 gallery photo',
        3 => 'no gentle low angle',
        4 => 'no soft controlled viewpoint',
        5 => 'no flat wall view',
        6 => 'no melted artwork',
        7 => 'no torn canvas',
        8 => 'no artwork substitution',
        9 => 'no fashion-model posing',
        10 => 'no mural scaling',
        11 => 'no billboard scaling',
        12 => 'no oversized canvas',
        13 => 'no pets',
        14 => 'no crowds',
        15 => 'no children',
      ),
      'full_prompt_template' => 'Generate one premium photographic mockup using this exact camera slot only:
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
No eye-level view, no standing-height view, no normal 3/4 gallery photo, no gentle low angle, no soft controlled viewpoint, no flat wall view, no centered product view, no melted artwork, no torn canvas, no curved canvas, no warped canvas, no artwork substitution, no invented painting, no mural scaling, no billboard scaling, no oversized canvas, no raw beige canvas edge, no unpainted canvas edge, no bare wood edge, no fashion-model posing, no pets, no crowds, no children, no visible people, no visible text, no logos, no watermarks.',
      'primary_scene_set' => false,
    ),
    'borgona_recovecos_3_4_loft_hormigon' =>
    array (
      'slot_id' => 'borgona_recovecos_3_4_loft_hormigon',
      'slot_name' => '3/4 Right View',
      'enabled' => true,
      'fidelity_mode' => 'adaptacion_camara_world_mother',
      'size_classes_supported' =>
      array (
        0 => 'small',
        1 => 'medium',
        2 => 'large',
        3 => 'xl_or_oversize',
        4 => 'unknown',
      ),
      'orientation_supported' =>
      array (
        0 => 'horizontal',
        1 => 'landscape',
        2 => 'vertical',
        3 => 'portrait',
        4 => 'square',
        5 => 'unknown',
      ),
      'camera_height_block' => 'Camera height is human but tucked into an architectural recess, approximately 120-160 cm depending on the loft edge.',
      'lens_block' => 'Use a 35-50 mm lens equivalent with tactile concrete depth and no aggressive wide-angle distortion.',
      'vertical_tilt_block' => 'Keep tilt controlled and mostly level so concrete planes, wall geometry, and artwork edges remain coherent.',
      'lateral_rotation_block' => 'Use a 3/4 view from a recess, corner, or architectural edge, with a burgundy-toned spatial mood and one clean vanishing direction.',
      'composition_block' => 'The artwork is partially contextualized by loft recesses and concrete architecture, creating depth and intimacy without hiding the piece; allow strong off-center framing through recesses, corners, side planes, or architectural edges.',
      'human_subject_block' => '',
      'scale_block' => 'The artwork remains a physically plausible canvas at its supplied physical size within the concrete loft, balanced by recess depth rather than global dominance. Canvas integrity: the canvas is a rigid physical rectangle; the front face must not bend, curve, stretch, or become a wedge, lateral thickness stays moderate and plausible, and canvas plane, wall plane, floor lines, furniture, and architecture share one coherent vanishing logic. Preserve the exact root artwork identity, colors, marks, sparse areas, composition, and proportions from IMAGE 1.',
      'depth_of_field_block' => 'Use controlled shallow spatial depth: recess edges, side planes, or distant loft surfaces may soften subtly, while the artwork face, canvas edges, and artwork texture remain fully sharp and legible.',
      'scene_affinity' =>
      array (
        0 => 'loft',
        1 => 'concrete',
        2 => 'recess',
        3 => 'three_quarter',
        4 => 'burgundy_mood',
      ),
      'negative_directives' =>
      array (
        0 => 'no artwork deformation',
        1 => 'no random decorative people',
        2 => 'no animals',
        3 => 'no crowds',
        4 => 'no children',
        5 => 'no pets',
      ),
      'full_prompt_template' => 'Generar un mockup fotográfico premium a partir de IMAGEN 1 e IMAGEN 2.

ROLES DE ENTRADA:
IMAGEN 1: primera imagen de referencia adjunta. Es la obra de arte. Preservar su identidad visual — colores, formas, proporciones internas, texturas, marcas, superficie — exactamente como aparece en IMAGEN 1. Esta preservación se refiere únicamente al CONTENIDO de la obra, no a su perspectiva o ángulo de cámara, que se define exclusivamente en la sección CAMARA de este prompt.

IMAGEN 2: referencia de entorno. Mantener su estilo, familia de materiales, lógica de luz, carácter de superficie e identidad espacial. No reemplazarla con un pasillo genérico, un vestíbulo, un pasaje de galería o un entorno inventado alternativo.

Si la IMAGEN 2 contiene pinturas, lienzos, caballetes, marcos, dibujos, carteles u otras obras de arte, tratarlas solo como objetos ambientales. IMAGEN 1 es la obra de arte más importante en la nueva imagen.

ARTWORK PHYSICAL DATA:
Artwork title: {{ARTWORK_TITLE}}
Artwork width: {{ARTWORK_WIDTH_CM}} cm
Artwork height: {{ARTWORK_HEIGHT_CM}} cm
Artwork orientation: {{ARTWORK_ORIENTATION}}
Artwork size class: {{ARTWORK_SIZE_CLASS}}

CAMARA — 3/4 DERECHO:
La cámara se posiciona hacia el lado derecho de la obra. El borde derecho del marco es el más próximo a cámara y debe verse más grande. El borde izquierdo se aleja de cámara y debe verse más chico, con sus líneas convirgiendo hacia un punto de fuga detrás y a la izquierda. La superficie pintada debe mostrar escorzo (foreshortening) real: el resultado esperado es un trapecio, no un rectángulo — más angosto en el borde izquierdo que en el derecho. Esta deformación geométrica es obligatoria y no debe evitarse ni suavizarse.

Lente 85mm equivalente, apertura f/2.0. Leve compresión de perspectiva entre la obra y el ambiente detrás de ella, sin introducir distorsión de gran angular (curvatura de líneas rectas del entorno). Esta compresión de lente no debe usarse para justificar un marco rectangular o simétrico — el escorzo 3/4 definido arriba tiene prioridad. Los elementos de fondo (muebles, arquitectura) se desenfocan suavemente, aislando la obra como sujeto claro. La compresión debe leerse como fotografía editorial premium, no como una instantánea.

COMPOSICIÓN:
Las líneas del suelo, las líneas del techo, las uniones de las paredes, la iluminación, las ventanas, las columnas, las puertas o los planos de pared distantes deben reforzar la profundidad y la dirección de fuga hacia la izquierda-atrás. Evitar la simetría centrada para presentar la obra de arte.

ENFOQUE:
Establecer el plano de enfoque en la obra de arte. Mantener la superficie de la obra, los bordes del lienzo, el grosor, la textura y el área inmediata de la pared nítidos. Los planos de pared lejanos, columnas remotas, ventanas de fondo, puertas lejanas, profundidad del techo y del suelo pueden desenfocarse naturalmente. El desenfoque debe sentirse óptico, no artificial.

DETERMINISMO DE LA OBRA DE ARTE (contenido, no perspectiva):
La superficie pintada final debe ser la misma imagen que IMAGEN 1 en cuanto a contenido: no redibujar, repintar, simplificar, embellecer, completar, estilizar, intercambiar, fusionar ni reinterpretar rostros, figuras, símbolos, marcas texturales, áreas en blanco dispersas, bloques de color o proporciones internas. Esto no implica preservar el ángulo o la perspectiva de IMAGEN 1 — la perspectiva final la determina exclusivamente la sección CAMARA.

ESCALA:
La obra de arte es de {{ARTWORK_WIDTH_CM}} x {{ARTWORK_HEIGHT_CM}} cm, clase de tamaño {{ARTWORK_SIZE_CLASS}}. No ampliarla para que domine la habitación o llene la pared disponible. Usar otros objetos de la escena (ventanas, sillones, muebles, lámparas, mesas) como referencia de escala real. Prestar especial atención en sitios de doble altura. Nunca sobredimensionar la obra de arte para darle protagonismo.',
      'primary_scene_set' => true,
      'board_order' => 3,
    ),
  ),
  'scene_board' =>
  array (
    'groups' =>
    array (
      'real_wall' =>
      array (
        'group_name' => 'En una pared real',
        'group_order' => 1,
        'variants' =>
        array (
          1 => 'Frontal',
          2 => '3/4 derecha',
          3 => '3/4 izquierda',
        ),
      ),
      'architectural_context' =>
      array (
        'group_name' => 'Contexto arquitectónico',
        'group_order' => 2,
        'variants' =>
        array (
          1 => '3/4 perspectiva',
          2 => 'Low-Angle Wall/Floor View',
          3 => '7/8 izquierda',
        ),
      ),
      'artistic_cameras' =>
      array (
        'group_name' => 'Cámaras artísticas',
        'group_order' => 3,
        'variants' =>
        array (
          1 => 'Nadir extremo / monumental',
          2 => 'Aérea entrepiso',
          3 => 'Aérea extrema cenital',
        ),
      ),
      'texture_canvas' =>
      array (
        'group_name' => 'Textura y canvas',
        'group_order' => 4,
        'variants' =>
        array (
          1 => 'Canvas Close-Up',
          2 => 'Canvas Edge Close-Up',
          3 => 'Canvas Corner Close-Up',
        ),
      ),
    ),
    'slots' =>
    array (
      0 => 'diagonal_estudio_moderno',
      1 => 'contrapicado_7_8',
      2 => 'borgona_recovecos_3_4_loft_hormigon',
      3 => 'detalle_textura_lienzo',
    ),
    'updated_at' => '2026-07-15T07:47:35+00:00',
  ),
  'scene_boards' =>
  array (
    1 =>
    array (
      'label' => 'Tablero 1',
      'slots' =>
      array (
        0 => 'diagonal_estudio_moderno',
        1 => 'contrapicado_7_8',
        2 => 'borgona_recovecos_3_4_loft_hormigon',
        3 => 'detalle_textura_lienzo',
      ),
      'updated_at' => '2026-07-15T07:47:35+00:00',
    ),
    2 =>
    array (
      'label' => 'Tablero 2',
      'slots' =>
      array (
        0 => 'nadir_extremo_arquitectonico',
        1 => 'vista_aerea_obra_piso_contexto_cenital',
        2 => 'pasillo_obra_descentrada_proxima',
        3 => 'borde_canvas_closeup',
      ),
      'updated_at' => '2026-07-15T07:47:35+00:00',
    ),
    3 =>
    array (
      'label' => 'Tablero 3',
      'slots' =>
      array (
        0 => 'contrapicado_raton_puro',
        1 => 'vista_aerea_contexto_ventanas',
        2 => 'obra_apoyada_suelo_7_8',
        3 => 'esquina_obra_perspectiva_extrema',
      ),
      'updated_at' => '2026-07-15T07:47:35+00:00',
    ),
  ),
);
