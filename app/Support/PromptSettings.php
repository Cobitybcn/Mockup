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
        $value = trim(self::all()['root_artwork_rules'] ?? '');

        if ($value === '') {
            $value = trim(self::builtInDefaultDirectives()['root_artwork_rules'] ?? '');
        }

        return $value;
    }

    public static function rootArtworkRulesFrontal(): string
    {
        $value = trim(self::all()['root_artwork_rules_frontal'] ?? '');

        if ($value === '') {
            $value = trim(self::builtInDefaultDirectives()['root_artwork_rules_frontal'] ?? '');
        }

        return $value;
    }

    public static function rootArtworkRulesLeft(): string
    {
        $value = trim(self::all()['root_artwork_rules_left'] ?? '');

        if ($value === '') {
            $value = trim(self::builtInDefaultDirectives()['root_artwork_rules_left'] ?? '');
        }

        return $value;
    }

    public static function rootArtworkRulesRight(): string
    {
        $value = trim(self::all()['root_artwork_rules_right'] ?? '');

        if ($value === '') {
            $value = trim(self::builtInDefaultDirectives()['root_artwork_rules_right'] ?? '');
        }

        return $value;
    }

    public static function artworkAnalysisPrompt(): string
    {
        $settings = self::all();
        $prompt = trim($settings['artwork_analysis_prompt'] ?? '');

        if ($prompt === '') {
            $prompt = trim(self::builtInDefaultDirectives()['artwork_analysis_prompt'] ?? '');
        }

        return $prompt;
    }

    public static function artworkAnalysisPromptVersion(): string
    {
        return self::extractPromptVersion(self::artworkAnalysisPrompt());
    }

    public static function extractPromptVersion(string $prompt): string
    {
        $lines = explode("\n", $prompt);
        foreach (array_slice($lines, 0, 5) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Remove common comment indicators: //, #, /*, *, etc.
            $clean = preg_replace('/^(\/\/|\#|\/\*|\*)\s*/', '', $line);
            $clean = trim($clean);

            // Match typical version tags: v6.4, version 6.4, v6.4 beta, etc.
            if (preg_match('/(v(?:er(?:sion)?)?\.?\s*[0-9]+(?:\.[0-9]+)*(?:\s*(?:beta|alpha|rc|dev|prod))?)/i', $clean, $matches)) {
                return trim($matches[1]);
            }

            // Match "version: 6.4 beta" or similar format
            if (preg_match('/version\s*:\s*([a-z0-9\.\-\s]+)/i', $clean, $matches)) {
                return 'v' . trim($matches[1]);
            }
        }
        return 'v1.0 (Auto)';
    }

    public static function mockupScaleRules(): string
    {
        $value = trim(self::all()['mockup_scale_rules'] ?? '');

        if ($value === '') {
            $value = trim(self::builtInDefaultDirectives()['mockup_scale_rules'] ?? '');
        }

        return $value;
    }

    public static function mockupNegativeRules(): string
    {
        $value = trim(self::all()['mockup_negative_rules'] ?? '');

        if ($value === '') {
            $value = trim(self::builtInDefaultDirectives()['mockup_negative_rules'] ?? '');
        }

        return $value;
    }

    public static function mockupQualityRules(): string
    {
        $value = trim(self::all()['mockup_quality_rules'] ?? '');

        if ($value === '') {
            $value = trim(self::builtInDefaultDirectives()['mockup_quality_rules'] ?? '');
        }

        return $value;
    }

    public static function mockupCameraRules(): string
    {
        $value = trim(self::all()['mockup_camera_rules'] ?? '');

        if ($value === '') {
            $value = trim(self::builtInDefaultDirectives()['mockup_camera_rules'] ?? '');
        }

        return $value;
    }

    public static function mockupRenderingRules(): string
    {
        $value = trim(self::all()['mockup_rendering_rules'] ?? '');

        if ($value === '') {
            $value = trim(self::builtInDefaultDirectives()['mockup_rendering_rules'] ?? '');
        }

        return $value;
    }

    public static function mockupFinalRequest(): string
    {
        $value = trim(self::all()['mockup_final_request'] ?? '');

        if ($value === '') {
            $value = trim(self::builtInDefaultDirectives()['mockup_final_request'] ?? '');
        }

        return $value;
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
            'root_artwork_rules_frontal' => '',
            'root_artwork_rules_left' => '',
            'root_artwork_rules_right' => '',
        ];
    }

    public static function labels(): array
    {
        return [
            'artwork_analysis_prompt' => [
                'title' => 'Artwork Analysis (Suggestions and Sheets)',
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
                'help' => 'Additional rules to control real artwork size, human figures, furniture, and architecture.',
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
                'title' => 'Camera and Shot Angles',
                'help' => 'Rules covering which camera angles to use and avoid for mockup generation.',
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
            'root_artwork_rules_frontal' => [
                'title' => 'Obra Raíz — Vista Frontal',
                'help' => 'Prompt completo y exclusivo para la vista frontal (v1). Este texto se envía a Vertex AI tal cual, sin combinarse con ningún otro campo.',
            ],
            'root_artwork_rules_left' => [
                'title' => 'Obra Raíz — 3/4 Izquierda',
                'help' => 'Prompt completo y exclusivo para la vista 3/4 izquierda (v2). Este texto se envía a Vertex AI tal cual, sin combinarse con ningún otro campo.',
            ],
            'root_artwork_rules_right' => [
                'title' => 'Obra Raíz — 3/4 Derecha',
                'help' => 'Prompt completo y exclusivo para la vista 3/4 derecha (v3). Este texto se envía a Vertex AI tal cual, sin combinarse con ningún otro campo.',
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
The artwork must be understood as only the final visible surface of the finished piece — that is, the real painted area that constitutes the artwork itself.

Do not use the edge of the photograph, the edge of any temporary support, or the edge of the visible background as the boundary of the artwork.

Completely remove anything that is not part of the final artwork, including but not limited to: temporary supports, MDF panels, cardboard, boards, walls, tables, support surfaces, backgrounds, shadows, accidental framing elements, external studio stains, nearby objects, or any structure used only to hold or photograph the piece.

Preserve only the real surface of the artwork, respecting its true physical boundaries, original proportions, orientation, and composition.

If the artwork is temporarily placed on a larger support, do not keep that support. Do not interpret it as a frame or as part of the artwork.

Expected result: a luxurious, realistic, premium product photograph, with the artwork resting on the floor and slightly leaning against a wall, in a minimal and elegant environment.

Lighting: composite studio lighting, soft and balanced, with lateral fill light, subtle directional highlights, clean tonal separation, sharp edges, and realistic texture.

Do not redraw, reinterpret, stylize, beautify, artistically correct, change colors, alter the composition, or add or remove any pictorial elements from the artwork.
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
            'mockup_scale_rules' => '',
            'mockup_negative_rules' => '',
            'mockup_quality_rules' => '',
            'mockup_camera_rules' => '',
            'mockup_rendering_rules' => '',
            'mockup_final_request' => '',
            'mockup_context_count' => '6',
            'root_artwork_count' => '3',
            'root_artwork_rules_frontal' => 'Create a single clean, high-resolution product photograph of ONLY the painting itself, isolated against a plain neutral studio background (light gray or white, no texture, no shadows from walls or floors other than the artwork\'s own contact shadow).

CAMERA: Position the camera perpendicular to the painting\'s surface, centered on the artwork, at the painting\'s mid-height. The lens axis must be exactly perpendicular to the painted surface — no perspective distortion, no convergence of the frame edges.

PHYSICAL POSE: The painting is leaning against a wall, resting on the floor, tilted back slightly (5-8 degrees) as physical objects naturally rest — NOT hanging, NOT floating, NOT perfectly vertical like a flat scan. The base of the painting touches the studio floor.

FRAMING: The artwork should occupy the majority of the frame, fully visible with all four edges inside the image, with even margin of neutral background around it. Do not crop any edge of the painting.

PROHIBITED: No mockup decor, no interior walls, no furniture, no gallery setting, no lifestyle context, no people, no reflections of a room. Only the painting and the empty studio surface it rests on.

There is only ONE physical object in this scene: the painting.',
            'root_artwork_rules_left' => 'Create a single clean, high-resolution product photograph of ONLY the painting itself, isolated against a plain neutral studio background (light gray or white, no texture, no shadows from walls or floors other than the artwork\'s own contact shadow).

CAMERA: Position the camera at a 3/4 angle to the LEFT of the painting\'s frontal axis (camera rotated approximately 25-35 degrees from perpendicular, viewing the artwork\'s right edge rotating away from camera). This is a genuine three-quarter rotation — the painting must appear visibly turned in space, not flat-on.

PHYSICAL POSE: The painting is leaning against a wall, resting on the floor, tilted back slightly (5-8 degrees) as physical objects naturally rest — same physical pose as the frontal view, NOT hanging, NOT floating.

CANVAS EDGE: This is a gallery-wrap canvas. The painted surface and colors wrap around onto the visible side edge of the canvas — there is no raw, unpainted canvas or visible wooden stretcher bars. The side edge shows continuous painted color/texture, not staples or bare wood.

FRAMING: The artwork should occupy the majority of the frame, fully visible with all edges inside the image, with even margin of neutral background around it. Do not crop any edge of the painting.

PROHIBITED: No mockup decor, no interior walls, no furniture, no gallery setting, no lifestyle context, no people, no reflections of a room. Only the painting and the empty studio surface it rests on.

There is only ONE physical object in this scene: the painting.',
            'root_artwork_rules_right' => 'Create a single clean, high-resolution product photograph of ONLY the painting itself, isolated against a plain neutral studio background (light gray or white, no texture, no shadows from walls or floors other than the artwork\'s own contact shadow).

CAMERA: Position the camera at a 3/4 angle to the RIGHT of the painting\'s frontal axis (camera rotated approximately 25-35 degrees from perpendicular, viewing the artwork\'s left edge rotating away from camera). This is a genuine three-quarter rotation — the painting must appear visibly turned in space, not flat-on.

PHYSICAL POSE: The painting is leaning against a wall, resting on the floor, tilted back slightly (5-8 degrees) as physical objects naturally rest — same physical pose as the frontal view, NOT hanging, NOT floating.

CANVAS EDGE: This is a gallery-wrap canvas. The painted surface and colors wrap around onto the visible side edge of the canvas — there is no raw, unpainted canvas or visible wooden stretcher bars. The side edge shows continuous painted color/texture, not staples or bare wood.

FRAMING: The artwork should occupy the majority of the frame, fully visible with all edges inside the image, with even margin of neutral background around it. Do not crop any edge of the painting.

PROHIBITED: No mockup decor, no interior walls, no furniture, no gallery setting, no lifestyle context, no people, no reflections of a room. Only the painting and the empty studio surface it rests on.

There is only ONE physical object in this scene: the painting.',
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
