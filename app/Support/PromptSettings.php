<?php
declare(strict_types=1);

class PromptSettings
{
    public static function all(): array
    {
        $settings = self::defaults();
        $stmt = Database::connection()->query('SELECT `key`, value FROM app_settings');

        foreach ($stmt->fetchAll() as $row) {
            $key = (string)($row['key'] ?? '');

            if (array_key_exists($key, $settings)) {
                $settings[$key] = (string)($row['value'] ?? '');
            }
        }

        return $settings;
    }

    public static function save(array $input): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(Database::appSettingUpsertSql());
        $now = date('c');

        foreach (array_keys(self::labels()) as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = trim((string)($input[$key] ?? self::defaults()[$key] ?? ''));

            if ($key === 'mockup_context_count') {
                $value = (string)self::normalizeContextCount($value);
            }

            if ($key === 'root_artwork_count') {
                $value = (string)self::normalizeRootArtworkCount($value);
            }

            $stmt->execute([
                'key' => $key,
                'value' => $value,
                'updated_at' => $now,
            ]);
        }

        $defaultDirectives = is_array($input['default_directives'] ?? null) ? $input['default_directives'] : [];

        foreach (array_keys(self::labels()) as $key) {
            if (!array_key_exists($key, $defaultDirectives)) {
                continue;
            }

            $value = trim((string)($defaultDirectives[$key] ?? self::builtInDefaultDirectives()[$key] ?? ''));

            $stmt->execute([
                'key' => 'prompt_default_' . $key,
                'value' => $value,
                'updated_at' => $now,
            ]);
        }
    }

    public static function rootArtworkRules(): string
    {
        return trim(self::all()['root_artwork_rules'] ?? '');
    }

    public static function artworkAnalysisPrompt(): string
    {
        $settings = self::all();
        $prompt = trim($settings['artwork_analysis_prompt'] ?? '');

        if ($prompt === '') {
            $prompt = self::artworkAnalysisContract();
        }

        $layers = [
            'CAPA A - ANALISIS DE LA OBRA' => trim($settings['artwork_analysis_layer_a'] ?? ''),
            'CAPA B - DATOS RELEVANTES DEL ARTISTA' => trim($settings['artwork_analysis_layer_b'] ?? ''),
            'CAPA C - INFORMES PARA PROMPTS Y PUBLICACIONES' => trim($settings['artwork_analysis_layer_c'] ?? ''),
            'CAPA D - PROMPTS PARA MOCKUPS' => trim($settings['artwork_analysis_layer_d'] ?? ''),
        ];

        $customLayers = [];
        foreach ($layers as $title => $text) {
            if ($text !== '') {
                $customLayers[] = $title . ":\n" . $text;
            }
        }

        $instructionBlocks = [];

        if ($customLayers !== []) {
            $instructionBlocks[] = "ADMIN CUSTOM ANALYSIS LAYERS:\n\n" . implode("\n\n", $customLayers);
        }

        $instructionBlocks[] = <<<'TEXT'
ADMIN EFFECTIVE SETTINGS:
- Generate exactly {context_count} contextual proposals.
- If any custom layer mentions another number of contexts or mockups, ignore that number and obey {context_count}.
- Keep the final response as one valid JSON object matching the schema below.
TEXT;

        $injectedInstructions = implode("\n\n", $instructionBlocks);
        $schemaMarker = 'Return exactly this JSON schema:';

        if (str_contains($prompt, $schemaMarker)) {
            return trim(str_replace(
                $schemaMarker,
                $injectedInstructions . "\n\n" . $schemaMarker,
                $prompt
            ));
        }

        return trim($prompt . "\n\n" . $injectedInstructions);
    }

    public static function mockupScaleRules(): string
    {
        return trim(self::all()['mockup_scale_rules'] ?? '');
    }

    public static function mockupNegativeRules(): string
    {
        return trim(self::all()['mockup_negative_rules'] ?? '');
    }

    public static function mockupQualityRules(): string
    {
        return trim(self::all()['mockup_quality_rules'] ?? '');
    }

    public static function mockupCameraRules(): string
    {
        return trim(self::all()['mockup_camera_rules'] ?? '');
    }

    public static function mockupRenderingRules(): string
    {
        return trim(self::all()['mockup_rendering_rules'] ?? '');
    }

    public static function mockupFinalRequest(): string
    {
        return trim(self::all()['mockup_final_request'] ?? '');
    }

    public static function mockupContextCount(): int
    {
        return self::normalizeContextCount(self::all()['mockup_context_count'] ?? '8');
    }

    public static function rootArtworkCount(): int
    {
        return self::normalizeRootArtworkCount(self::all()['root_artwork_count'] ?? '3');
    }

    public static function defaults(): array
    {
        return [
            'root_artwork_rules' => '',
            'artwork_analysis_prompt' => '',
            'artwork_analysis_layer_a' => '',
            'artwork_analysis_layer_b' => '',
            'artwork_analysis_layer_c' => '',
            'artwork_analysis_layer_d' => '',
            'mockup_scale_rules' => '',
            'mockup_negative_rules' => '',
            'mockup_quality_rules' => '',
            'mockup_camera_rules' => '',
            'mockup_rendering_rules' => '',
            'mockup_final_request' => '',
            'mockup_context_count' => '6',
            'root_artwork_count' => '3',
        ];
    }

    public static function labels(): array
    {
        return [
            'root_artwork_rules' => [
                'title' => 'Imagen raiz',
                'help' => 'Reglas adicionales para Formulario 1. Usar para ajustar fidelidad, luz, bastidor y preservacion.',
            ],
            'artwork_analysis_prompt' => [
                'title' => 'Análisis de Obra (Sugerencias y Fichas)',
                'help' => 'Instrucciones para que la IA analice la obra y sugiera contextos. Mantener los marcadores {placeholder} y la estructura de salida JSON intacta.',
            ],
            'artwork_analysis_layer_a' => [
                'title' => 'Capa A - Analisis de la obra',
                'help' => 'Bloque editable para lenguaje visual, composicion, color, textura, formato, escala, atmosfera y publico objetivo.',
            ],
            'artwork_analysis_layer_b' => [
                'title' => 'Capa B - Perfil del artista',
                'help' => 'Bloque editable para relacionar la obra con statement, lenguaje visual, simbolos, motivos y atmosferas del artista.',
            ],
            'artwork_analysis_layer_c' => [
                'title' => 'Capa C - Titulos y publicaciones',
                'help' => 'Bloque editable para titulos, subtitulos, descripciones curatoriales, comerciales, SEO, redes y marketplaces.',
            ],
            'artwork_analysis_layer_d' => [
                'title' => 'Capa D - Contextos para mockups',
                'help' => 'Bloque editable para reglas de contextos narrados, tipos de espacios permitidos, prohibiciones y variedad obligatoria.',
            ],
            'mockup_scale_rules' => [
                'title' => 'Escala y proporciones',
                'help' => 'Reglas adicionales para controlar tamaño real de obra, figura humana, muebles y arquitectura.',
            ],
            'mockup_negative_rules' => [
                'title' => 'Prohibiciones visuales',
                'help' => 'Elementos que nunca deben aparecer: textos, medidas, logos, objetos indeseados, etc.',
            ],
            'mockup_quality_rules' => [
                'title' => 'Calidad y atmosfera',
                'help' => 'Reglas finales para integracion, sombras, contexto, sofisticacion y estilo comercial.',
            ],
            'mockup_camera_rules' => [
                'title' => 'Cámara y ángulos de toma',
                'help' => 'Reglas sobre qué ángulos de cámara utilizar y cuáles evitar para la generación de mockups.',
            ],
            'mockup_rendering_rules' => [
                'title' => 'Render tecnico del mockup',
                'help' => 'Controles verificables por ADMIN para tamano visual, primer plano, limites de escala y figura humana. Mantener formato key=value.',
            ],
            'mockup_final_request' => [
                'title' => 'Peticion final de mockups',
                'help' => 'Instrucciones finales editables por ADMIN que se agregan al prompt que recibe el generador de imagenes.',
            ],
            'mockup_context_count' => [
                'title' => 'Cantidad de propuestas',
                'help' => 'Numero de direcciones curatoriales sugeridas en Formulario 2. Recomendado: 5, 7 o 10.',
                'type' => 'number',
            ],
            'root_artwork_count' => [
                'title' => 'Cantidad de obras raiz',
                'help' => 'Numero de versiones de obra raiz generadas en Formulario 1 antes de elegir la definitiva. Recomendado: 3.',
                'type' => 'number',
            ],
        ];
    }

    public static function builtInDirectives(): array
    {
        return self::builtInDefaultDirectives();
    }

    public static function defaultDirectives(): array
    {
        $directives = self::builtInDefaultDirectives();

        try {
            $stmt = Database::connection()->query("SELECT `key`, value FROM app_settings WHERE `key` LIKE 'prompt_default_%'");

            foreach ($stmt->fetchAll() as $row) {
                $settingKey = (string)($row['key'] ?? '');
                $directiveKey = substr($settingKey, strlen('prompt_default_'));

                if (array_key_exists($directiveKey, $directives)) {
                    $directives[$directiveKey] = (string)($row['value'] ?? '');
                }
            }
        } catch (Throwable $e) {
            return $directives;
        }

        return $directives;
    }

    private static function builtInDefaultDirectives(): array
    {
        return [
            'root_artwork_rules' => <<<'TEXT'
Generate a clean, high-resolution front-facing close-up of ONLY the painting itself, filling the entire frame.
Remove all background elements, including walls, floors, frames, borders, easels, stands, and shadows.
The output image must contain only the canvas and the painting, flat, centered, and occupying 100% of the image space from edge to edge.
DO NOT paint over, distort, redraw, smooth out brushstrokes, or omit signatures. Maintain 100% color, texture, and line fidelity of the painting surface. Keep signatures and artist marks exactly as they appear in the original upload.
TEXT,
            'artwork_analysis_prompt' => <<<'TEXT'
Analyze this artwork visually first and propose dynamic contextual mockup recommendations following the principle "The artwork determines the space, not the space the artwork".
Return only a valid JSON object. Do not include markdown formatting or comments.

ARTIST PROFILE CONTEXT:
{artist_profile_prompt}

ARTWORK DETAILS:
- Title: {title}
- Physical dimensions: {width_cm} cm wide x {height_cm} cm high
- Artist notes: {notes}
- User preferred style: {preferred_style}
- Target market: {target_market}
- Image orientation: {orientation}

LANGUAGE RULES:
- The artwork determines the language. Treat the artist profile as background context only.
- Do not copy the artist profile literally into public-facing descriptions.
- The preferred description tone in the artist profile guides all public text.
- Default tone if none is provided: clear, poetic, sober, elegant, human, public-facing, not academic, not overly curatorial, not decorative, not generic.
- Avoid these phrases and attitudes: "This artwork is presented as...", "This version positions the piece...", "collector-grade silence", "curatorial narrative", "commercial presentation", "publication-ready", "for galleries, curators and interior designers", overly academic language, and generic marketplace filler text.

INSTRUCTIONS FOR THE ANALYSIS AND SELECTION OF CONTEXTS:

1. Analyze the artwork across these dimensions:
   - dominant colors
   - composition and structure
   - visible symbols, motifs or recognizable elements
   - contrast
   - rhythm
   - surface and material presence
   - emotional atmosphere
   - possible public context and intended audience
   Then use the artist profile only to enrich the interpretation.

2. Enforce allowed and forbidden contexts:
   - ONLY propose premium, realistic, high-end residential interiors (e.g. living rooms, collector study, elegant hallways, dining areas, high-end design studios), professional art galleries, or clean museum spaces.
   - DO NOT propose subterranean spaces, industrial basements, underground vaults, warehouses, garages, dark caverns, or non-premium environments.
   - All spaces must have sophisticated, clean, and well-lit atmospheres. Do not use very dim, gloomy, or cold industrial settings.

3. Propose different contextual spaces for mockups. 
   **CRITICAL RULE: Each proposal must be completely unique and not a slight variation of the same room.**
   Each proposal should differ significantly in at least one key element: space type (e.g. minimalist studio, London townhouse, Mediterranean countryside house), atmosphere, lighting (e.g. morning daylight, warm evening lights), materials (stone, plaster, warm wood), camera angle, human presence.
   
4. The names of the contexts must be dynamically generated by you to suit the artwork's identity. 
   Avoid rigid placeholders like "Main Sales Mockup" or "Human Scale Mockup".
   Use evocative, curatorial names like: "Silent Mineral Interior", "Collector's Evening Room", "Warm Mediterranean Threshold", "Linen and Morning Light Interior".

5. Define roles for each context (e.g. "primary presentation", "collector positioning", "scale reference", "architectural study", "commercial alternative").

6. For human presence, suggest either "none", "optional standing male figure 1.80m", or "optional standing female figure 1.55m". At least 1 proposal in the list should include a human figure for scale reference.

7. Write visual and practical justifications explaining why the proposed space enhances the specific energy of this artwork.

8. Generate SEO-optimized titles, alt texts, board suggestions, and descriptions with different tones (poetic/emotional focus, formal/structure focus, and commercial/collectible focus) specifically tailored to Pinterest and marketplaces.

Return exactly this JSON schema:
{
  "artwork_analysis": {
    "visual_language": [],
    "emotional_energy": [],
    "dominant_colors": [],
    "secondary_colors": [],
    "visible_elements": [],
    "visible_symbols": [],
    "color_temperature": "",
    "contrast_level": "",
    "composition_type": "",
    "rhythm": "",
    "surface": "",
    "spatial_presence": "",
    "one_line_curatorial_read": "",
    "style_summary": "",
    "publishing_metadata": {
      "suggested_titles": [
        {
          "title": "A premium, rich, non-generic title 1 matching the artist statement",
          "subtitle": "An artistic and meaningful subtitle 1",
          "description": "A rich, premium, artistic curatorial description (100-150 words) linked to title 1."
        },
        {
          "title": "A premium, rich, non-generic title 2 matching the artist statement",
          "subtitle": "An artistic and meaningful subtitle 2",
          "description": "A rich, premium, artistic curatorial description (100-150 words) linked to title 2."
        },
        {
          "title": "A premium, rich, non-generic title 3 matching the artist statement",
          "subtitle": "An artistic and meaningful subtitle 3",
          "description": "A rich, premium, artistic curatorial description (100-150 words) linked to title 3."
        }
      ],
      "keywords": ["exactly 15 style-relevant keywords"],
      "long_tail_keywords": ["exactly 15 long tail keywords containing multiple words"],
      "root_image_metadata": {
        "alt_text": "Detailed alt text describing the clean root painting image, flat, centered, detailing surface, colors, and composition.",
        "caption": "Sober caption for the root image containing title, dimensions, medium and artist name."
      }
    }
  },
  "recommended_number_of_contexts": {context_count},
  "contextual_proposals": [
    {
      "context_name": "Silent Mineral Interior",
      "context_role": "primary presentation",
      "space_type": "minimal architectural interior",
      "atmosphere": "silent, contemplative, mineral, spacious",
      "materials": ["stone", "lime plaster", "neutral textile"],
      "lighting": "soft lateral afternoon light",
      "camera_angle": "three-quarter view",
      "human_presence": "none",
      "curatorial_reason": "The artwork has a silent architectural presence and needs a clean mineral space that reinforces its contemplative tension.",
      "commercial_reason": "This context positions the artwork for collectors, architects and interior designers looking for a strong but sober contemporary piece.",
      "mockup_metadata": {
        "alt_text": "Detailed alt text describing the painting hung on the wall in this mockup room layout, showing surrounding scale and room details.",
        "caption": "A clean caption for this mockup showcasing the artwork title in this specific setting."
      }
    }
  ]
}
TEXT,
            'artwork_analysis_layer_a' => '',
            'artwork_analysis_layer_b' => '',
            'artwork_analysis_layer_c' => '',
            'artwork_analysis_layer_d' => '',
            'mockup_scale_rules' => <<<'TEXT'
Respect the physical dimensions of the artwork relative to furniture, doors, windows, ceilings, pedestals, and human figures.
Do not enlarge the artwork for dramatic effect.
Use baseboards, doors, consoles, chairs, and human height as realistic, proportional scale references in the same plane.
Do not convert a medium-sized artwork into a mural, monumental panel, or installation if its dimensions do not warrant it.
TEXT,
            'mockup_negative_rules' => <<<'TEXT'
No kitchens.
No common bedrooms.
No cheap decor.
No generic stock interiors.
No additional artworks, paintings, framed prints, posters, murals, canvases, or competing wall art anywhere in the scene.
No logos.
No visible text.
No letters, numbers, plates, labels, posters, dimensions, dimension lines, ruler marks, or annotations.
No shoes or footwear as scale reference.
No distorted perspective.
Do not redraw, repaint, reinterpret, crop, mirror, rotate, recolor, simplify, extend, or alter the artwork surface.
Do not change the artwork's internal composition, visible symbols, brushstrokes, marks, texture, colors, proportions, orientation, or edge shape.
TEXT,
            'mockup_quality_rules' => <<<'TEXT'
Create an integrated mockup, not a photo pasted on a generic background.
Add realistic contact shadows, wall contact, depth, the canvas physical edge, and subtle ambient light.
The environment must feel sophisticated, European or American collector style, gallery, museum, art fair, or high-end interior.
The emotional impact should come from the scene, lighting, and context, not from false scaling.
The artwork must feel placed, collected, and desired.
The provided artwork image must remain visually identical inside the mockup. Preserve its exact composition, colors, symbols, lines, surface texture, brushwork, proportions, orientation, and edges.
Create a close, artwork-dominant mockup, not a distant interior view. The artwork must be the clear main subject and should occupy roughly 50-70% of the final image area when visually plausible.
TEXT,
            'mockup_camera_rules' => <<<'TEXT'
CAMERA SELECTION RULES FOR MOCKUP GENERATION

Only use camera angles that present the artwork as the dominant, high-end centerpiece of the room. The room exists to accompany, scale, and add value to the artwork, never to overshadow it. Avoid wide-angle or distant rooms.

ALLOWED CAMERAS:
1. Front Gallery View: straight-on front view, eye-level, artwork dominant, filling at least 50-70% of the visual space. Excellent for Saatchi Art, Pinterest, and listings.
2. Three-Quarter Left View: subtle three-quarter angle from the left, revealing slight canvas depth/thickness on the wall.
3. Three-Quarter Right View: subtle three-quarter angle from the right, showing texture, depth, and realistic lighting.

CAMERAS TO AVOID (DO NOT USE):
- Do not use wide-angle, distant room shots, or full-room views. The room must feel close, cropped, and intimate around the artwork.
- Do not add other paintings, artworks, framed prints, posters, murals, canvases, or visual competitors on surrounding walls.
- Do not use low-angle hero shots, fisheye, birds-eye, or extreme diagonal perspective views.
TEXT,
            'mockup_rendering_rules' => <<<'TEXT'
TECHNICAL RENDERER CONTROLS
These values control the pre-composed reference image sent to the image model. Edit only after visual testing.

mockup_fill_default=0.50
mockup_fill_long_side_le_45=0.32
mockup_fill_long_side_le_80=0.42
mockup_fill_long_side_le_120=0.52
mockup_fill_long_side_le_160=0.58
mockup_fill_long_side_le_220=0.64
mockup_fill_long_side_gt_220=0.70
mockup_human_scale_multiplier=0.78
mockup_fill_min=0.05
mockup_fill_max=0.95
TEXT,
            'mockup_final_request' => '',
            'mockup_context_count' => '6',
            'root_artwork_count' => '3',
        ];
    }

    private static function normalizeContextCount(string $value): int
    {
        $count = (int)$value;

        if ($count < 1) {
            return 1;
        }

        if ($count > 10) {
            return 10;
        }

        return $count;
    }

    private static function artworkAnalysisContract(): string
    {
        $default = self::defaultDirectives()['artwork_analysis_prompt'] ?? '';
        $schemaMarker = 'Return exactly this JSON schema:';
        $schemaPos = strpos($default, $schemaMarker);
        $schema = $schemaPos !== false ? substr($default, $schemaPos) : '';

        return trim(<<<TEXT
Return only a valid JSON object. Do not include markdown formatting or comments.
Use the ADMIN custom analysis layers as the creative, curatorial, commercial and mockup-context instructions.
Use the artist profile and artwork details as source context only.

ARTIST PROFILE CONTEXT:
{artist_profile_prompt}

ARTWORK DETAILS:
- Title: {title}
- Physical dimensions: {width_cm} cm wide x {height_cm} cm high
- Artist notes: {notes}
- User preferred style: {preferred_style}
- Target market: {target_market}
- Image orientation: {orientation}

{$schema}
TEXT);
    }

    private static function normalizeRootArtworkCount(string $value): int
    {
        $count = (int)$value;

        if ($count < 1) {
            return 1;
        }

        if ($count > 10) {
            return 10;
        }

        return $count;
    }
}
