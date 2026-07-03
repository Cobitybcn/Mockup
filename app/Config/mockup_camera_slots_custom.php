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
        0 => 'vista_aerea_obra_piso_cenital',
        1 => 'camara_contraluz',
      ),
    ),
    'phase_2_6_artistic_detail_v1' => 
    array (
      'set_name' => 'FASE 2.6 artistic physical artwork detail set',
      'slots' => 
      array (
        0 => 'camara_contraluz',
        1 => 'aerea_cenital_obra_piso',
        2 => 'vista_aerea_obra_piso_contexto_cenital',
      ),
    ),
    'phase_2_4_default_v1' => 
    array (
      'set_name' => 'FASE 2.4 default camera slot set',
      'slots' => 
      array (
        0 => 'vista_aerea_obra_piso_cenital',
      ),
    ),
  ),
  'slots' => 
  array (
    'vista_aerea_obra_piso_cenital' => 
    array (
      'slot_id' => 'vista_aerea_obra_piso_cenital',
      'slot_name' => 'Vista Aérea Cenital de Obra sobre el Piso',
      'enabled' => true,
      'fidelity_mode' => 'high',
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
        1 => 'square',
      ),
      'camera_height_block' => 'High angle, directly above, looking straight down at the floor, zenithal view. The camera is positioned overhead, providing a bird\'s-eye perspective.',
      'lens_block' => 'Standard wide-angle lens, capturing a broad floor area and surrounding context without extreme distortion, maintaining clear lines.',
      'vertical_tilt_block' => 'Camera angle 85-90 degrees from the horizon, almost perfectly perpendicular to the floor, ensuring an aerial, top-down view.',
      'lateral_rotation_block' => '0 degrees lateral rotation, presenting a level and balanced perspective from above.',
      'composition_block' => 'The {{ARTWORK_ORIENTATION}} artwork, measuring {{ARTWORK_WIDTH_CM}}cm by {{ARTWORK_HEIGHT_CM}}cm, is the central and primary subject, positioned flat and horizontally on the floor. It occupies approximately 30-50% of the frame, showcasing its full extent or nearly full extent. The composition emphasizes the floor as the main plane, with subtle surrounding elements like a rug, low table, or sofa providing upscale context without obscuring the artwork.',
      'human_subject_block' => '',
      'scale_block' => 'The artwork is presented at a realistic and natural scale relative to the surrounding interior elements and furniture. It is neither disproportionately large nor miniature.',
      'depth_of_field_block' => 'Shallow to medium depth of field, with the artwork in crisp, sharp focus. The immediate floor area around the artwork is also clear, while more distant background elements (e.g., far walls, distant windows) may exhibit a subtle, pleasing blur to emphasize the artwork.',
      'scene_affinity' => 
      array (
        0 => 'An elegant',
        1 => 'modern interior space such as a minimalist loft',
        2 => 'a designer living room',
        3 => 'or a contemporary art studio. The primary surface is a well-maintained floor (e.g.',
        4 => 'polished concrete',
        5 => 'light wood',
        6 => 'large tile',
        7 => 'or a tasteful',
        8 => 'neutral-toned area rug). The environment suggests sophistication and highlights the artwork\'s presence on the floor.',
      ),
      'negative_directives' => 
      array (
        0 => 'no artwork hanging, no artwork on wall, no vertical artwork, no artwork standing, no easel, no frame around artwork, no canvas stretcher visible, no wall visible, no vertical surface.',
        1 => 'no eye-level, no low angle, no standing height, no street view, no perspective from a human\'s viewpoint.',
        2 => 'no invented art, no fake painting, no substitution, artwork is main subject, no sofa replacing artwork, no table replacing artwork, no rug replacing artwork.',
        3 => 'no warped, no bent, no folded, no stretched, no compressed, no crinkled artwork, artwork must be perfectly flat and planar.',
        4 => 'no text, no logo, no watermark, no writing, no branding, no visible artist signature unless part of artwork.',
        5 => 'no blur, no out of focus, no discolored, no poor quality, no low resolution, no grainy.',
        6 => 'no billboard, no mural, no giant artwork, no miniature artwork, no cartoon, no illustration.',
        7 => 'no people, no hands, no human presence, no animals.',
      ),
      'full_prompt_template' => 'A stunning professional fine art photograph, taken with a high-angle, zenithal view, looking straight down onto a clean, polished floor. The primary subject is a physical {{ARTWORK_ORIENTATION}} artwork, titled "{{ARTWORK_TITLE}}", measuring {{ARTWORK_WIDTH_CM}}cm by {{ARTWORK_HEIGHT_CM}}cm, which is lying perfectly flat and horizontally on a sleek, minimalist floor. The artwork occupies a significant portion of the frame (around 30-50%), with ample surrounding floor space visible. The scene is a modern, sunlit interior, possibly a minimalist loft or elegant gallery, featuring subtle ambient details like a textured area rug partially underneath the artwork, the elegant leg of a low-slung sofa, or the edge of a sleek coffee table. The lighting is soft and natural, emphasizing the texture and details of the artwork and the floor. Sharp focus on the artwork, with a shallow to medium depth of field that subtly blurs distant background elements, creating a premium, inviting atmosphere. Ultra-realistic, high resolution, professional studio lighting, fine art photography, gallery aesthetic.',
    ),
    'camara_contraluz' => 
    array (
      'slot_id' => 'camara_contraluz',
      'slot_name' => 'CONTRALUZ',
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
      'camera_height_block' => 'La cámara está a altura media-baja, aproximadamente entre 90 y 120 cm desde el suelo, mirando hacia la obra desde una posición ligeramente inferior al centro visual. La cámara no está completamente frontal: busca capturar la luz que entra desde detrás de la obra.',
      'lens_block' => 'Usar lente gran angular moderada, entre 28mm y 35mm, con perspectiva natural y ligera profundidad espacial. Evitar distorsión extrema. La lente debe permitir ver la obra, parte del ambiente y el halo de luz de fondo.',
      'vertical_tilt_block' => 'Inclinación vertical levemente ascendente, aproximadamente 5 a 12 grados hacia arriba. La cámara mira hacia la obra y hacia la fuente de luz posterior, permitiendo que el contraluz cree brillo, borde luminoso y atmósfera.',
      'lateral_rotation_block' => 'Rotación lateral suave de 10 a 20 grados respecto a la obra, en vista tres cuartos sutil. La cámara no está totalmente frontal; debe permitir que el contraluz marque profundidad, volumen y separación entre la obra y el fondo.',
      'composition_block' => 'La obra aparece completamente visible o casi completamente visible, ubicada en el centro o ligeramente desplazada del centro. La fuente de luz principal está detrás de la obra o detrás del plano donde se encuentra la obra, entrando hacia cámara. El resultado debe ser una escena en contraluz: bordes iluminados, halo suave, sombras profundas en primer plano, atmósfera cinematográfica y alto contraste. Mantener espacio visible alrededor de la obra para que se perciba el ambiente, el suelo o la profundidad. Evitar que la luz queme o tape la pintura.',
      'human_subject_block' => 'Sin personas como sujeto principal. Se permite presencia humana mínima solo si aparece recortada, en sombra, desenfocada o como silueta secundaria. No usar modelos posando, rostros visibles ni figuras que compitan con la obra. La presencia humana, si aparece, debe servir únicamente para escala y atmósfera.',
      'scale_block' => 'La obra debe conservar su escala física real en relación con el muro, el suelo, puertas, ventanas, muebles y arquitectura. No debe verse como mural, póster gigante, billboard ni miniatura. En contraluz, la luz puede dramatizar los bordes, pero no debe agrandar visualmente la obra ni deformar sus proporciones.',
      'depth_of_field_block' => 'La obra y sus bordes deben permanecer nítidos y legibles. El fondo luminoso puede suavizarse levemente por el contraluz, con halo atmosférico y profundidad. No desenfocar la pintura ni perder sus detalles. La fuente de luz posterior puede tener brillo suave, pero sin quemar la obra.',
      'scene_affinity' => 
      array (
        0 => 'interior arquitectónico',
        1 => 'ventana posterior',
        2 => 'luz de atardecer',
        3 => 'estudio artístico',
        4 => 'galería íntima',
        5 => 'concrete interior',
        6 => 'dark wood study',
        7 => 'modernismo',
        8 => 'brutalist interior',
        9 => 'cinematic backlight',
        10 => 'atmospheric room',
      ),
      'negative_directives' => 
      array (
        0 => 'obra deformada',
        1 => 'pintura inventada',
        2 => 'obra ilegible',
        3 => 'obra quemada por la luz',
        4 => 'silueta total',
        5 => 'escala mural',
        6 => 'billboard',
        7 => 'miniatura',
        8 => 'marco agregado',
        9 => 'texto visible',
        10 => 'reflejos que tapen la pintura',
        11 => 'figura humana dominante',
        12 => 'rostro visible',
        13 => 'pose de moda',
        14 => 'exceso de flare',
        15 => 'distorsión extrema',
        16 => 'lente ojo de pez',
        17 => 'ambiente plano',
        18 => 'cámara frontal sin profundidad',
      ),
      'full_prompt_template' => 'Generar un mockup fotográfico premium usando únicamente este slot de cámara:
ID de cámara: {{CAMERA_SLOT_ID}}
Nombre de cámara: {{CAMERA_SLOT_NAME}}

ROLES DE IMAGEN:
IMAGE 1 es la única fuente de verdad para la obra. Preservar identidad exacta, orientación, proporción, colores, marcas, zonas vacías y composición.
IMAGE 2 es la world mother ambiental cuando exista. Tomar materialidad, luz y clima espacial, pero no copiar su cámara, layout, ubicación de muebles ni contenido artístico.

DATOS FÍSICOS DE LA OBRA:
Título: {{ARTWORK_TITLE}}
Ancho: {{ARTWORK_WIDTH_CM}} cm
Alto: {{ARTWORK_HEIGHT_CM}} cm
Orientación: {{ARTWORK_ORIENTATION}}
Clase de tamaño: {{ARTWORK_SIZE_CLASS}}

CÁMARA:
La cámara está a altura media-baja, aproximadamente entre 90 y 120 cm desde el suelo, mirando hacia la obra desde una posición ligeramente inferior al centro visual. La cámara no está completamente frontal: busca capturar la luz que entra desde detrás de la obra.
Usar lente gran angular moderada, entre 28mm y 35mm, con perspectiva natural y ligera profundidad espacial. Evitar distorsión extrema. La lente debe permitir ver la obra, parte del ambiente y el halo de luz de fondo.
Inclinación vertical levemente ascendente, aproximadamente 5 a 12 grados hacia arriba. La cámara mira hacia la obra y hacia la fuente de luz posterior, permitiendo que el contraluz cree brillo, borde luminoso y atmósfera.
Rotación lateral suave de 10 a 20 grados respecto a la obra, en vista tres cuartos sutil. La cámara no está totalmente frontal; debe permitir que el contraluz marque profundidad, volumen y separación entre la obra y el fondo.

COMPOSICIÓN:
La obra aparece completamente visible o casi completamente visible, ubicada en el centro o ligeramente desplazada del centro. La fuente de luz principal está detrás de la obra o detrás del plano donde se encuentra la obra, entrando hacia cámara. El resultado debe ser una escena en contraluz: bordes iluminados, halo suave, sombras profundas en primer plano, atmósfera cinematográfica y alto contraste. Mantener espacio visible alrededor de la obra para que se perciba el ambiente, el suelo o la profundidad. Evitar que la luz queme o tape la pintura.

ESCALA E INTEGRIDAD DE OBRA:
La obra debe conservar su escala física real en relación con el muro, el suelo, puertas, ventanas, muebles y arquitectura. No debe verse como mural, póster gigante, billboard ni miniatura. En contraluz, la luz puede dramatizar los bordes, pero no debe agrandar visualmente la obra ni deformar sus proporciones.

FOCO:
La obra y sus bordes deben permanecer nítidos y legibles. El fondo luminoso puede suavizarse levemente por el contraluz, con halo atmosférico y profundidad. No desenfocar la pintura ni perder sus detalles. La fuente de luz posterior puede tener brillo suave, pero sin quemar la obra.

PROMPT NEGATIVO:
obra deformada, pintura inventada, obra ilegible, obra quemada por la luz, silueta total, escala mural, billboard, miniatura, marco agregado, texto visible, reflejos que tapen la pintura, figura humana dominante, rostro visible, pose de moda, exceso de flare, distorsión extrema, lente ojo de pez, ambiente plano, cámara frontal sin profundidad',
    ),
    'aerea_cenital_obra_piso' => 
    array (
      'slot_id' => 'aerea_cenital_obra_piso',
      'slot_name' => 'Vista aérea cenital de obra de arte en el piso',
      'enabled' => true,
      'fidelity_mode' => 'Premium realista',
      'size_classes_supported' => 
      array (
        0 => 'Pequeña',
        1 => 'Mediana',
        2 => 'Grande',
        3 => 'Extra Grande',
      ),
      'orientation_supported' => 
      array (
        0 => 'Vertical',
        1 => 'Horizontal',
      ),
      'camera_height_block' => 'Cámara posicionada a gran altura directamente sobre el centro de la obra. Vista cenital o casi cenital, apuntando hacia abajo con un ángulo de aproximadamente 90 grados respecto al plano horizontal del suelo.',
      'lens_block' => 'Lente estándar a ligeramente gran angular (equivalente a 28-50mm) para capturar la obra y un entorno moderado sin distorsión significativa.',
      'vertical_tilt_block' => 'Ángulo de inclinación vertical de aproximadamente 85-90 grados, buscando una perspectiva lo más perpendicular posible al plano del suelo y la obra.',
      'lateral_rotation_block' => 'Mínima o ninguna rotación lateral. La cámara debe estar alineada compositivamente para que la obra se vea de manera clara y central, aunque puede haber un sutil desplazamiento para una composición dinámica.',
      'composition_block' => 'La obra de arte (IMAGE 1) se coloca horizontalmente sobre el suelo y es el sujeto principal y el foco innegable de la composición. La cámara se sitúa directamente encima, mirando hacia abajo para capturar la obra completa o casi completa dentro del encuadre. El suelo actúa como el plano principal. Se permite incluir contexto ambiental sutil y complementario alrededor de la obra (como una alfombra, una mesa baja de centro, una sección de un sofá, cortinas discretas, ventanas en el fondo o patrones/líneas del piso) para añadir realismo, escala y un toque de habitabilidad. IMAGE 2 debe ser utilizado como un \'ADN ambiental\' para inspirar el estilo y la paleta de colores del entorno, sin reemplazar ni competir con IMAGE 1. El espacio debe ser amplio, bien iluminado y de estilo contemporáneo.',
      'human_subject_block' => 'No se permiten sujetos humanos, partes del cuerpo o elementos relacionados con la presencia humana directa en la escena.',
      'scale_block' => 'La escala de la obra (IMAGE 1) debe ser exacta, manteniendo sus proporciones ({{ARTWORK_WIDTH_CM}} cm x {{ARTWORK_HEIGHT_CM}} cm). Los objetos de entorno (muebles, alfombras, texturas del piso) deben ser de escala realista y coherente para transmitir las dimensiones de la obra. No se debe percibir la obra como un mural o un elemento de señalización.',
      'depth_of_field_block' => 'Profundidad de campo moderada a amplia. La obra de arte (IMAGE 1) debe estar perfectamente nítida. Los elementos de contexto cercanos pueden tener un ligero desenfoque, pero deben ser claramente reconocibles y contribuir a la sensación de profundidad espacial.',
      'scene_affinity' => 
      array (
        0 => 'Interior',
        1 => 'loft moderno',
        2 => 'estudio de artista',
        3 => 'galería de arte contemporánea',
        4 => 'apartamento de diseño',
        5 => 'espacio minimalista',
        6 => 'sala de estar luminosa',
        7 => 'piso de madera clara',
        8 => 'hormigón pulido',
        9 => 'baldosas grandes',
        10 => 'alfombra de diseño escandinavo',
        11 => 'muebles contemporáneos',
        12 => 'luz natural difusa.',
      ),
      'negative_directives' => 
      array (
        0 => 'No obra de arte montada en la pared.',
        1 => 'No obra de arte en posición vertical.',
        2 => 'No vista frontal de pared.',
        3 => 'No obra de arte colgada en la pared.',
        4 => 'No vista a la altura de los ojos o a altura de pie.',
        5 => 'No el sofá debe reemplazar o dominar a la obra de arte.',
        6 => 'No la mesa debe reemplazar o dominar a la obra de arte.',
        7 => 'No la alfombra debe reemplazar o dominar a la obra de arte.',
        8 => 'No sustitución de la obra de arte (IMAGE 1).',
        9 => 'No pintura inventada o ficticia.',
        10 => 'No lienzo deformado o doblado.',
        11 => 'No obra de arte estirada o comprimida.',
        12 => 'No escalado de valla publicitaria o mural.',
        13 => 'No texto visible.',
        14 => 'No logotipos.',
        15 => 'No marcas de agua.',
        16 => 'No otras obras de arte en la escena; IMAGE 1 es la única.',
        17 => 'No distorsión de la cámara que afecte a la obra de arte.',
        18 => 'No sombras duras o contrastes excesivos que oculten detalles de la obra.',
      ),
      'full_prompt_template' => 'Una vista aérea cenital impecable y realista de la obra de arte principal (IMAGE 1), titulada \'{{ARTWORK_TITLE}}\', con dimensiones de {{ARTWORK_WIDTH_CM}}cm x {{ARTWORK_HEIGHT_CM}}cm y orientación {{ARTWORK_ORIENTATION}} ({{ARTWORK_SIZE_CLASS}}). La obra está posicionada horizontalmente sobre el suelo de un espacio interior moderno y luminoso, como un loft, estudio o galería contemporánea. La cámara está ubicada directamente encima, apuntando hacia abajo con un ángulo casi perfectamente cenital, capturando la obra en su totalidad y parte del entorno inmediato. El suelo es el plano principal, con texturas realistas como madera pulida, hormigón suave o baldosas grandes y limpias. Elementos de contexto sutiles y bien seleccionados rodean la obra (ej. una alfombra de diseño, una mesa de centro baja de madera o metal, un fragmento de un sofá contemporáneo o cortinas ligeras de una ventana), utilizando IMAGE 2 como referencia para el estilo y el ambiente general del espacio, asegurando una estética cohesionada. La iluminación es suave, difusa y natural, acentuando los detalles y la atmósfera tranquila del espacio. Fotografía de alta resolución, fotorrealista, con enfoque nítido en toda la escena. Perspectiva perfectamente recta y aérea. Sin sujetos humanos. Sin distorsión óptica. No obras colgadas en pared. No obras verticales. No elementos que distraigan o compitan con la obra principal.',
    ),
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
    ),
  ),
);
