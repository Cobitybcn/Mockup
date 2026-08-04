<?php
declare(strict_types=1);

/**
 * LABORATORIO: deriva los campos propios del listing de Saatchi a partir de la
 * lectura editorial YA APROBADA de la obra.
 *
 * No vuelve a mirar la obra ni la reinterpreta: toma el texto aprobado —que es
 * donde vive la voz del artista— y lo lleva a los recipientes de Saatchi, en
 * ingles. Una sola voz entre el sitio y el listing.
 *
 * Por que existe como pasada aparte: el prompt del sistema
 * (ArtworkAnalysisV2::prompt) ya emite saatchi_title, saatchi_description y
 * saatchi_caption, y BilingualEditorialAdapterService ya los pasa al ingles.
 * Falta saatchi_keywords —que no lo genera nadie— y un piso para la descripcion.
 * Esta clase prueba esas salidas sin tocar el prompt del sistema. Si funcionan,
 * se graduan a su contrato y esta pasada desaparece; mientras tanto sirve para
 * rellenar el catalogo existente sin regenerar ninguna lectura aprobada.
 *
 * Los pies por imagen van en una SEGUNDA pasada, con las imagenes adjuntas: un
 * pie describe una imagen concreta y no se puede derivar de un texto. Se
 * mantienen separados a proposito — si las imagenes viajaran junto a la
 * derivacion, el modelo agregaria a la descripcion hechos visuales que la
 * lectura aprobada no dice, que es justo lo que derivar evita. A la larga esos
 * pies pertenecen al circuito de fichas de mockup, que ya adjunta cada imagen.
 *
 * Limites verificados en el formulario real de Saatchi (2026-08-04):
 *   titulo completo <= 65 · keywords 5 a 12, cada una de 2 a 20 caracteres
 * Politica propia de EDITORIAL_CORE, no reglas de Saatchi:
 *   descripcion de 850 a 1000 · pies de 4 a 7 palabras y menos de 50
 */
class SaatchiListingService
{
    public const TITLE_MAX = 65;
    public const KEYWORD_MIN = 2;
    public const KEYWORD_MAX = 20;
    public const KEYWORDS_MIN = 5;
    public const KEYWORDS_TARGET = 12;
    public const CAPTION_MAX = 50;
    public const CAPTION_WORDS_MIN = 4;
    public const CAPTION_WORDS_MAX = 7;
    // 1000 es el techo de EDITORIAL_CORE, no un maximo publicado por Saatchi.
    // 850 es el objetivo y avisa, no bloquea: un piso duro empuja a rellenar.
    // Debajo de 600 no hay decision de estilo, hay generacion incompleta.
    public const DESCRIPTION_TARGET_MIN = 850;
    public const DESCRIPTION_HARD_MIN = 600;
    public const DESCRIPTION_MAX = 1000;

    /**
     * Vocabulario de encuadre. Un pie hecho solo de estas palabras describe la
     * posicion de la camara y no que deja inspeccionar la imagen.
     */
    private const VIEWPOINT_WORDS = [
        'view', 'views', 'viewed', 'viewpoint', 'angle', 'angled', 'perspective', 'shot', 'image', 'photo',
        'low', 'high', 'overhead', 'aerial', 'top', 'bottom', 'side', 'front', 'back', 'rear',
        'close', 'closeup', 'three', 'quarter', 'left', 'right', 'below', 'above', 'full', 'wide',
        'detail', 'details', 'showing', 'shows', 'show', 'seen', 'from', 'of', 'the', 'a', 'an',
        'and', 'on', 'in', 'at', 'to', 'with', 'artwork', 'art', 'work', 'piece', 'painting',
        'canvas', 'composition', 'picture', 'this',
    ];

    private const STOPWORDS = [
        'the', 'a', 'an', 'of', 'and', 'or', 'in', 'on', 'with', 'for', 'to', 'at', 'from',
        'within', 'across', 'along', 'above', 'below', 'under', 'over', 'between',
        'de', 'la', 'el', 'los', 'las', 'un', 'una', 'del', 'al', 'y', 'o', 'en', 'con',
    ];

    public function __construct(
        private PDO $pdo,
        private ?GeminiImageClient $client = null
    ) {
        $this->client = $client ?: new GeminiImageClient();
    }

    /* ————————————————————————— logica pura, testeable sin red ————————————————————————— */

    /**
     * Palabra normalizada para comparar: red/reds, line/lines, layer/layered
     * cuentan como la misma. Stemming minimo y determinista, nada semantico.
     */
    public static function normalizeWord(string $word): string
    {
        $word = mb_strtolower(trim($word));
        if (mb_strlen($word) >= 6 && str_ends_with($word, 'ing')) {
            return mb_substr($word, 0, -3);
        }
        if (mb_strlen($word) >= 5 && str_ends_with($word, 'ed')) {
            return mb_substr($word, 0, -2);
        }
        if (mb_strlen($word) >= 4 && str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
            return mb_substr($word, 0, -1);
        }
        return $word;
    }

    /**
     * Lee del JSON del modelo SOLO el contrato minimo. Conteos, puntajes y
     * estados que el modelo pudiera devolver se ignoran: un numero que se
     * inventa a si mismo no prueba nada.
     *
     * @param array<string,mixed> $decoded
     * @return array{title:string,description:string,keywords:list<string>}
     */
    public static function parseModelOutput(array $decoded): array
    {
        $keywords = [];
        foreach ((array)($decoded['saatchi_keywords'] ?? []) as $keyword) {
            $keyword = trim((string)$keyword);
            if ($keyword === '' || isset($keywords[mb_strtolower($keyword)])) {
                continue;
            }
            $keywords[mb_strtolower($keyword)] = $keyword;
        }

        return [
            'title' => trim((string)($decoded['saatchi_title'] ?? '')),
            'description' => trim((string)($decoded['saatchi_description'] ?? '')),
            'keywords' => array_values($keywords),
        ];
    }

    /**
     * @param array<string,mixed> $decoded
     * @param list<string> $expectedFiles
     * @return array{captions:array<string,string>,unknown:list<string>}
     */
    public static function parseCaptionOutput(array $decoded, array $expectedFiles): array
    {
        $captions = [];
        $unknown = [];
        foreach ((array)($decoded['image_captions'] ?? []) as $entrada) {
            if (!is_array($entrada)) {
                continue;
            }
            $file = basename(trim((string)($entrada['file'] ?? '')));
            if ($file === '') {
                continue;
            }
            if (!in_array($file, $expectedFiles, true)) {
                $unknown[] = $file;
                continue;
            }
            $captions[$file] = trim((string)($entrada['caption'] ?? ''));
        }
        return ['captions' => $captions, 'unknown' => $unknown];
    }

    /**
     * La validacion determinista: cada regla que importa, contada por codigo.
     * Devuelve errores exactos, aptos para la llamada unica de reparacion.
     *
     * @param array{title:string,description:string,keywords:list<string>} $listing
     * @param list<string> $affinities nombres autorizados por el artista
     * @return list<string>
     */
    public static function validateListing(array $listing, string $officialTitle, string $artistName, array $affinities = []): array
    {
        $errors = [];

        $title = (string)$listing['title'];
        $largoTitulo = mb_strlen($title);
        if ($largoTitulo > self::TITLE_MAX) {
            $errors[] = "saatchi_title mide {$largoTitulo} caracteres y el formulario corta en " . self::TITLE_MAX . '.';
        }
        if ($officialTitle !== '' && !str_starts_with($title, $officialTitle)) {
            $errors[] = "saatchi_title debe empezar con el titulo exacto de la obra (\"{$officialTitle}\"): prohibido abreviarlo para hacerle lugar a la cola.";
        }

        $description = (string)$listing['description'];
        $largo = mb_strlen($description);
        if ($largo > self::DESCRIPTION_MAX) {
            $errors[] = "saatchi_description mide {$largo} caracteres y el techo es " . self::DESCRIPTION_MAX . '.';
        } elseif ($largo < self::DESCRIPTION_HARD_MIN) {
            $errors[] = "saatchi_description mide {$largo} caracteres: por debajo de " . self::DESCRIPTION_HARD_MIN . ' no es una decision de estilo, es una generacion incompleta.';
        }
        if ($description !== '' && preg_match('/^\s*(this|in this|the|a|an|discover|explore|acquire)\b/i', $description)) {
            $errors[] = 'saatchi_description abre con una formula generica: la primera palabra no puede ser This, In this, The, A, An, Discover, Explore ni Acquire.';
        }

        $keywords = $listing['keywords'];
        $cantidad = count($keywords);
        if ($cantidad < self::KEYWORDS_MIN || $cantidad > self::KEYWORDS_TARGET) {
            $errors[] = "saatchi_keywords trae {$cantidad} entradas y el formulario admite entre " . self::KEYWORDS_MIN . ' y ' . self::KEYWORDS_TARGET . '.';
        }

        // Reservado = lo que Saatchi ya indexa: el titulo completo del listing y
        // el nombre del artista. Las afinidades autorizadas quedan exentas:
        // nombrarlas es exactamente el proposito del campo.
        $reserved = [];
        foreach ([$title, $artistName] as $texto) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', (string)$texto) ?: [] as $palabra) {
                $palabra = self::normalizeWord($palabra);
                if (mb_strlen($palabra) >= 3 && !in_array($palabra, self::STOPWORDS, true)) {
                    $reserved[$palabra] = true;
                }
            }
        }
        $nombresAfinidad = [];
        foreach ($affinities as $nombre) {
            $nombre = trim((string)$nombre);
            if ($nombre === '') {
                continue;
            }
            $nombresAfinidad[] = $nombre;
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $nombre) ?: [] as $palabra) {
                unset($reserved[self::normalizeWord($palabra)]);
            }
        }

        foreach ($keywords as $keyword) {
            $len = mb_strlen($keyword);
            if ($len < self::KEYWORD_MIN || $len > self::KEYWORD_MAX) {
                $errors[] = "la keyword \"{$keyword}\" mide {$len} caracteres, fuera del rango " . self::KEYWORD_MIN . '-' . self::KEYWORD_MAX . ' del formulario de Saatchi.';
            }
            foreach (preg_split('/\s+/u', trim($keyword)) ?: [] as $palabra) {
                if (isset($reserved[self::normalizeWord($palabra)])) {
                    $errors[] = "la keyword \"{$keyword}\" repite \"{$palabra}\", que ya esta en el titulo del listing o en el nombre del artista.";
                    break;
                }
            }
        }

        // La afinidad va SOLO como keyword y como maximo dos.
        if ($nombresAfinidad !== []) {
            $conNombre = 0;
            foreach ($keywords as $keyword) {
                foreach ($nombresAfinidad as $nombre) {
                    $partes = preg_split('/\s+/u', $nombre) ?: [];
                    $apellido = $partes !== [] ? (string)$partes[count($partes) - 1] : '';
                    if (mb_strtolower($keyword) === mb_strtolower($nombre)
                        || ($apellido !== '' && mb_strtolower($keyword) === mb_strtolower($apellido))) {
                        $conNombre++;
                        break;
                    }
                }
            }
            if ($conNombre > 2) {
                $errors[] = "{$conNombre} keywords son nombres de artista y el maximo por obra es 2.";
            }
            foreach ($nombresAfinidad as $nombre) {
                foreach (['saatchi_title' => $title, 'saatchi_description' => $description] as $campo => $texto) {
                    if ($texto !== '' && preg_match('/(^|[^\p{L}])' . preg_quote($nombre, '/') . '($|[^\p{L}])/iu', $texto)) {
                        $errors[] = "{$campo} nombra a {$nombre}: la afinidad va solo como keyword, nunca en titulo ni descripcion.";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Ley determinista de los pies de Saatchi.
     *
     * @param array<string,string> $captions
     * @param list<string> $inspectableFiles
     * @param list<string> $uninspectableFiles imagenes sin archivo: pie vacio obligatorio
     * @return list<string>
     */
    public static function validateCaptions(array $captions, array $inspectableFiles, array $uninspectableFiles = []): array
    {
        $errors = [];
        foreach ($inspectableFiles as $file) {
            $caption = trim((string)($captions[$file] ?? ''));
            if ($caption === '') {
                $errors[] = "falta el pie de {$file}.";
                continue;
            }
            $len = mb_strlen($caption);
            if ($len > self::CAPTION_MAX) {
                $errors[] = "el pie de {$file} mide {$len} caracteres y el tope es " . self::CAPTION_MAX . ": \"{$caption}\".";
            }
            $palabras = count(preg_split('/\s+/u', $caption) ?: []);
            if ($palabras < self::CAPTION_WORDS_MIN || $palabras > self::CAPTION_WORDS_MAX) {
                $errors[] = "el pie de {$file} tiene {$palabras} palabras y debe tener entre " . self::CAPTION_WORDS_MIN . ' y ' . self::CAPTION_WORDS_MAX . ": \"{$caption}\".";
            }
            $informativas = [];
            foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($caption)) ?: [] as $palabra) {
                if ($palabra !== '' && !in_array($palabra, self::VIEWPOINT_WORDS, true)) {
                    $informativas[] = $palabra;
                }
            }
            if ($informativas === []) {
                $errors[] = "el pie de {$file} solo describe la posicion de la camara y no que deja inspeccionar la imagen: \"{$caption}\".";
            }
        }
        foreach ($uninspectableFiles as $file) {
            if (trim((string)($captions[$file] ?? '')) !== '') {
                $errors[] = "el pie de {$file} debe quedar vacio: esa imagen no se pudo inspeccionar y un pie sin mirar es texto inventado.";
            }
        }
        return $errors;
    }

    /* ————————————————————————————— orquestacion ————————————————————————————— */

    /**
     * @return array{title:string,description:string,keywords:list<string>,locale:string,source:array<string,string>,validation:array<string,mixed>}
     */
    public function generate(int $userId, int $artworkId): array
    {
        $artwork = $this->artwork($userId, $artworkId);
        $officialTitle = trim((string)$artwork['final_title']);
        if ($officialTitle === '') {
            throw new RuntimeException('La obra no tiene titulo: sin identidad no hay listing.');
        }

        $reading = $this->approvedReading($userId, $artworkId);
        if (trim($reading['description']) === '') {
            return $this->emptyResult($officialTitle, $reading, [
                'status' => 'requires_input',
                'errors' => [],
                'warnings' => ['La obra todavia no tiene lectura editorial aprobada: sin ella no hay de donde derivar el listing.'],
                'metrics' => ['repair_attempted' => false],
            ]);
        }

        $budget = self::TITLE_MAX - mb_strlen($officialTitle) - 3;
        if ($budget < 12) {
            throw new RuntimeException("El titulo ocupa casi los 65 caracteres: quedan {$budget} para el subtitulo.");
        }

        $affinities = $this->affinities($userId);
        $prompt = $this->prompt($artwork, $reading, $this->affinitiesForPrompt($userId), $budget);

        $listing = self::parseModelOutput($this->decode($this->client->generateText([$this->client->textPart($prompt)])));
        $errors = self::validateListing($listing, $officialTitle, (string)$artwork['artist_name'], $affinities);

        $repaired = false;
        if ($errors !== []) {
            // UNA llamada de reparacion con los errores exactos y el JSON previo.
            // Si la segunda pasada sigue fallando: requires_review, no se insiste.
            $repaired = true;
            try {
                $listing = self::parseModelOutput($this->decode(
                    $this->client->generateText([$this->client->textPart($this->repairPrompt($prompt, $listing, $errors))])
                ));
                $errors = self::validateListing($listing, $officialTitle, (string)$artwork['artist_name'], $affinities);
            } catch (RuntimeException $e) {
                $errors[] = 'La reparacion no devolvio JSON valido: ' . $e->getMessage();
            }
        }

        $warnings = [];
        $largo = mb_strlen($listing['description']);
        if ($largo > 0 && $largo < self::DESCRIPTION_TARGET_MIN) {
            $warnings[] = "La descripcion mide {$largo} y el objetivo editorial son " . self::DESCRIPTION_TARGET_MIN . ': revisala, pero no la rellenes para llegar.';
        }
        if (count($listing['keywords']) !== self::KEYWORDS_TARGET) {
            $warnings[] = 'Quedaron ' . count($listing['keywords']) . ' keywords y el objetivo son ' . self::KEYWORDS_TARGET . '.';
        }

        // Segunda pasada: los pies, con las imagenes adjuntas.
        [$captions, $captionErrors, $captionWarnings, $captionMetrics] = $this->captions($userId, $artworkId);
        $errors = array_merge($errors, $captionErrors);
        $warnings = array_merge($warnings, $captionWarnings);

        return [
            'title' => $listing['title'],
            'description' => $listing['description'],
            'keywords' => $listing['keywords'],
            'captions' => $captions,
            // El origen puede ser el castellano aprobado; el destino es siempre
            // el idioma del listing, porque Saatchi se carga en ingles.
            'locale' => $this->listingLocale($userId),
            'source_locale' => $reading['locale'],
            'source_approved' => (bool)($reading['approved'] ?? false),
            'source' => $reading,
            'validation' => [
                'status' => $errors === [] ? 'ok' : 'requires_review',
                'errors' => $errors,
                'warnings' => $warnings,
                'metrics' => array_merge([
                    'title_characters' => mb_strlen($listing['title']),
                    'subtitle_budget' => $budget,
                    'description_characters' => $largo,
                    'keyword_count' => count($listing['keywords']),
                    'source_reading_characters' => mb_strlen($reading['description']),
                    'repair_attempted' => $repaired,
                ], $captionMetrics),
            ],
        ];
    }

    /**
     * Los pies de las imagenes de la composicion, mirando cada una. Pasada
     * aparte de la derivacion a proposito: acá las imagenes SI viajan.
     *
     * @return array{0:array<string,string>,1:list<string>,2:list<string>,3:array<string,mixed>}
     */
    private function captions(int $userId, int $artworkId): array
    {
        // Las primeras 5 de la composicion, en el orden que fijo el artista.
        $images = array_slice($this->compositionImages($userId, $artworkId), 0, 5);
        if ($images === []) {
            return [[], [], ['La obra no tiene imagenes en su composicion: no hay pies que escribir.'], ['captions_expected' => 0]];
        }

        $inspectable = [];
        $uninspectable = [];
        foreach ($images as $image) {
            $path = $this->resolveResultFile($image['file']);
            if ($path !== '') {
                $image['path'] = $path;
                $inspectable[] = $image;
            } else {
                $uninspectable[] = $image;
            }
        }

        $expected = array_column($images, 'file');
        $inspectableFiles = array_column($inspectable, 'file');
        $uninspectableFiles = array_column($uninspectable, 'file');

        $prompt = $this->captionPrompt($images, $inspectableFiles);
        $parts = [$this->client->textPart($prompt)];
        foreach ($inspectable as $image) {
            $parts[] = $this->client->imagePart((string)$image['path']);
        }

        $parsed = self::parseCaptionOutput($this->decode($this->client->generateText($parts)), $expected);
        $errors = self::validateCaptions($parsed['captions'], $inspectableFiles, $uninspectableFiles);

        $repaired = false;
        if ($errors !== []) {
            $repaired = true;
            $repairParts = array_merge(
                [$this->client->textPart($this->captionRepairPrompt($prompt, $parsed['captions'], $errors))],
                array_slice($parts, 1)
            );
            try {
                $parsed = self::parseCaptionOutput($this->decode($this->client->generateText($repairParts)), $expected);
                $errors = self::validateCaptions($parsed['captions'], $inspectableFiles, $uninspectableFiles);
            } catch (RuntimeException $e) {
                $errors[] = 'La reparacion de los pies no devolvio JSON valido: ' . $e->getMessage();
            }
        }

        $warnings = [];
        foreach ($uninspectableFiles as $file) {
            $parsed['captions'][$file] = '';
            $warnings[] = "La imagen {$file} no se pudo inspeccionar: su pie queda vacio.";
        }
        foreach ($parsed['unknown'] as $file) {
            $warnings[] = "El modelo devolvio un pie para una imagen que no es de esta obra y se descarto: {$file}.";
        }

        return [$parsed['captions'], $errors, $warnings, [
            'captions_expected' => count($inspectableFiles),
            'captions_received' => count(array_filter($parsed['captions'], static fn (string $c): bool => trim($c) !== '')),
            'caption_repair_attempted' => $repaired,
        ]];
    }

    /**
     * Escritura DIRIGIDA: toca los tres campos de Saatchi y nada mas. La lectura
     * aprobada, que esta publicada, no se altera.
     *
     * Escribe en la fila del idioma del listing —Saatchi se carga en ingles— y no
     * en todas: meter texto en ingles en la fila en espanol corrompe el contenido
     * del sitio, que lee por idioma.
     *
     * @param array{title:string,description:string,keywords:list<string>,locale:string} $listing
     */
    public function save(int $userId, int $artworkId, array $listing): int
    {
        $status = (string)($listing['validation']['status'] ?? 'ok');
        if ($status !== 'ok') {
            throw new RuntimeException("El paquete quedo en estado {$status} y no se guarda solo: corregilo o completa lo que falta.");
        }

        $locale = (string)($listing['locale'] ?? '');
        if ($locale === '') {
            throw new RuntimeException('El listing no declara idioma: sin eso no se sabe en que fila escribir.');
        }

        // Queda en el BORRADOR de ese idioma. La copia publicada la llena la
        // accion de publicar, que es del artista: publicar es aprobar.
        (new BilingualEditorialService($this->pdo))->mergeDerivedFields(
            $userId,
            'artwork',
            $artworkId,
            $locale,
            [
                'saatchi_title' => $listing['title'],
                'saatchi_description' => $listing['description'],
                'saatchi_keywords' => implode(', ', $listing['keywords']),
            ]
        );
        $escritos = 1;

        // Los pies viven por imagen, en su propia columna: el pie del sitio mide
        // ~250 caracteres y sirve para otra cosa, no se toca.
        foreach ((array)($listing['captions'] ?? []) as $file => $caption) {
            if (trim((string)$caption) === '') {
                continue;
            }
            $sheet = $this->pdo->prepare('UPDATE mockup_sheets SET saatchi_caption = ?, updated_at = ?
                WHERE user_id = ? AND mockup_file LIKE ?');
            $sheet->execute([$caption, date('c'), $userId, '%' . basename((string)$file)]);
            $escritos += $sheet->rowCount();
        }

        return $escritos;
    }

    /* ————————————————————————————— internos ————————————————————————————— */

    /**
     * @param array<string,mixed> $artwork
     * @param array{locale:string,description:string,short_description:string,subtitle:string} $reading
     */
    private function prompt(array $artwork, array $reading, string $affinities, int $budget): string
    {
        $reglas = strtr((string)file_get_contents(__DIR__ . '/saatchi_listing_rules.txt'), [
            '{editorial_integrity_rules}' => EditorialIntegrityPolicy::promptRules('artwork'),
        ]);

        $referentes = $affinities !== '' ? $affinities : '(none declared — do not name any artist)';
        $dimensiones = trim(implode(' x ', array_filter([
            trim((string)$artwork['width']), trim((string)$artwork['height']), trim((string)$artwork['depth']),
        ], 'strlen')));

        return <<<PROMPT
{$reglas}

CONFIRMED ARTWORK DATA (immutable)
- Official title (fixed by the artist, never vary it): {$artwork['final_title']}
- Artist: {$artwork['artist_name']}
- Series: {$artwork['series']}
- Medium and materials: {$artwork['medium']}
- Dimensions (cm, never restated in prose): {$dimensiones}
- Character budget for the subtitle: {$budget} (the full saatchi_title must stay within 65)

APPROVED EDITORIAL READING ({$reading['locale']}) — the source of the voice and of every visual and
conceptual claim. Carry this into the Saatchi containers in English; do not add facts it does not
state, and do not translate it literally.
{$reading['description']}

APPROVED SHORT READING ({$reading['locale']})
{$reading['short_description']}

APPROVED SUBTITLE ({$reading['locale']})
{$reading['subtitle']}

AUTHORISED ARTIST AFFINITIES
{$referentes}
PROMPT;
    }

    /**
     * @param array{title:string,description:string,keywords:list<string>} $listing
     * @param list<string> $errors
     */
    private function repairPrompt(string $prompt, array $listing, array $errors): string
    {
        $previo = json_encode([
            'saatchi_title' => $listing['title'],
            'saatchi_description' => $listing['description'],
            'saatchi_keywords' => $listing['keywords'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $listaErrores = '- ' . implode("\n- ", $errors);

        return <<<PROMPT
{$prompt}

REPAIR PASS — your previous answer failed the application's deterministic validation.
Correct ONLY the fields named below. Preserve every valid field unchanged. Do not explain the
correction. Return the complete strict JSON object.

VALIDATION ERRORS
{$listaErrores}

PREVIOUS JSON
{$previo}
PROMPT;
    }

    /**
     * @param list<array<string,string>> $images
     * @param list<string> $inspectableFiles
     */
    private function captionPrompt(array $images, array $inspectableFiles): string
    {
        $reglas = (string)file_get_contents(__DIR__ . '/saatchi_caption_rules.txt');

        $lista = [];
        $adjunta = 0;
        foreach ($images as $image) {
            $linea = 'file=' . $image['file'] . ' · camera=' . ($image['camera'] !== '' ? $image['camera'] : 'unknown');
            if (in_array($image['file'], $inspectableFiles, true)) {
                $adjunta++;
                $linea = "attached image {$adjunta} · " . $linea;
            } else {
                $linea .= ' · NOT AVAILABLE FOR INSPECTION — leave its caption empty';
            }
            $lista[] = (count($lista) + 1) . '. ' . $linea;
        }

        return $reglas . "\n\nIMAGES TO CAPTION\n" . implode("\n", $lista) . "\n";
    }

    /**
     * @param array<string,string> $captions
     * @param list<string> $errors
     */
    private function captionRepairPrompt(string $prompt, array $captions, array $errors): string
    {
        $previo = json_encode(['image_captions' => array_map(
            static fn (string $file, string $caption): array => ['file' => $file, 'caption' => $caption],
            array_keys($captions),
            array_values($captions)
        )], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $listaErrores = '- ' . implode("\n- ", $errors);

        return <<<PROMPT
{$prompt}

REPAIR PASS — your previous captions failed the application's deterministic validation.
Correct ONLY the captions named below. Preserve every valid caption unchanged. Do not explain the
correction. Return the complete strict JSON object with one entry per image.

VALIDATION ERRORS
{$listaErrores}

PREVIOUS JSON
{$previo}
PROMPT;
    }

    /**
     * Las imagenes de la COMPOSICION de la publicacion, en el orden que el
     * artista fijo en el panel Sitio web: ese es el orden en que suben a Saatchi.
     * Los mockups sueltos son respaldo para una obra sin composicion armada.
     *
     * @return list<array<string,string>>
     */
    private function compositionImages(int $userId, int $artworkId): array
    {
        $composicion = $this->pdo->prepare("SELECT s.mockup_file, m.selector_state_json
            FROM artwork_sheets a
            JOIN publications p ON p.artwork_sheet_id = a.id AND p.user_id = a.user_id
            JOIN publication_items i ON i.publication_id = p.id
            JOIN mockup_sheets s ON s.id = i.mockup_sheet_id
            LEFT JOIN mockups m ON m.user_id = s.user_id AND (m.id = s.mockup_id OR m.mockup_file = s.mockup_file)
            WHERE a.user_id = ? AND a.canonical_artwork_id = ? AND COALESCE(a.status,'') <> 'merged'
                AND p.id = (SELECT MAX(p2.id) FROM publications p2 WHERE p2.artwork_sheet_id = a.id AND p2.user_id = a.user_id)
            ORDER BY i.position, i.id");
        $composicion->execute([$userId, $artworkId]);
        $rows = $composicion->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            $stmt = $this->pdo->prepare('SELECT m.mockup_file, m.selector_state_json FROM mockups m
                WHERE m.user_id = ? AND (m.source_artwork_id = ?
                    OR m.artwork_group_id = (SELECT artwork_group_id FROM artworks WHERE id = ?))
                ORDER BY m.id');
            $stmt->execute([$userId, $artworkId, $artworkId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $images = [];
        foreach ($rows as $row) {
            $state = json_decode((string)($row['selector_state_json'] ?? ''), true);
            $combination = is_array($state['combination'] ?? null) ? $state['combination'] : [];
            $images[] = [
                'file' => basename((string)$row['mockup_file']),
                'camera' => trim((string)($combination['camera_slot_name'] ?? '')),
            ];
        }
        return $images;
    }

    private function resolveResultFile(string $file): string
    {
        $file = basename(trim($file));
        if ($file === '') {
            return '';
        }
        $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path) && class_exists('StorageService') && StorageService::isGcsActive()) {
            try {
                StorageService::downloadFile('results/' . $file, $path);
            } catch (Throwable) {
                // Sin archivo no hay inspeccion: el pie queda vacio y registrado.
            }
        }
        return is_file($path) ? $path : '';
    }

    /**
     * La lectura aprobada, preferida en el idioma de adaptacion (el listing va en
     * ingles) y con el idioma de trabajo como respaldo.
     *
     * @return array{locale:string,description:string,short_description:string,subtitle:string}
     */
    private function approvedReading(int $userId, int $artworkId): array
    {
        $stmt = $this->pdo->prepare('SELECT locale, content_json, published_content_json
            FROM bilingual_editorial_content WHERE user_id = ? AND entity_type = ? AND entity_id = ?');
        $stmt->execute([$userId, 'artwork', $artworkId]);

        // Se elige por ESTADO antes que por idioma. Publicar es aprobar, asi que
        // una copia publicada gana siempre sobre cualquier borrador, aunque el
        // borrador este en el idioma del listing. Antes esto prefería el ingles
        // y terminaba derivando de un borrador sin aprobar, rotulandolo ademas
        // como "lectura aprobada", que era falso.
        $publicadas = [];
        $borradores = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $locale = (string)$row['locale'];
            foreach ([['published_content_json', &$publicadas], ['content_json', &$borradores]] as [$columna, &$destino]) {
                $content = json_decode((string)($row[$columna] ?? ''), true);
                if (!is_array($content) || trim((string)($content['description'] ?? '')) === '') {
                    continue;
                }
                $destino[$locale] = [
                    'locale' => $locale,
                    'approved' => $columna === 'published_content_json',
                    'description' => trim((string)$content['description']),
                    'short_description' => trim((string)($content['short_description'] ?? '')),
                    'subtitle' => trim((string)($content['subtitle'] ?? '')),
                ];
            }
            unset($destino);
        }

        foreach ([$publicadas, $borradores] as $grupo) {
            if ($grupo === []) {
                continue;
            }
            // Dentro de lo aprobado manda el idioma de trabajo: es la fuente.
            $trabajo = (new BilingualEditorialService($this->pdo))->sourceLocale($userId);
            foreach ([$trabajo, 'es', 'en'] as $preferido) {
                if (isset($grupo[$preferido])) {
                    return $grupo[$preferido];
                }
            }
            return (array)reset($grupo);
        }

        return ['locale' => 'en', 'approved' => false, 'description' => '', 'short_description' => '', 'subtitle' => ''];
    }

    /**
     * Los nombres declarados por el artista. Antes esto partia por comas, que
     * rompia en cuanto el fundamento de una filiacion tenia una: el formato es
     * una linea por artista, "Nombre: fundamento".
     *
     * @return list<string>
     */
    private function affinities(int $userId): array
    {
        $profile = ArtistProfile::findForUser($userId);
        return ArtistReferences::names((string)($profile['reference_artists'] ?? ''));
    }

    /**
     * El idioma del listing: Saatchi se carga en ingles, asi que es el idioma de
     * adaptacion del artista, no el de la lectura que le sirvio de fuente.
     */
    private function listingLocale(int $userId): string
    {
        $target = trim((new BilingualEditorialService($this->pdo))->primaryAdaptationTarget($userId));
        return $target !== '' ? $target : 'en';
    }

    /** El bloque con nombre y fundamento, que es el criterio para elegir cual aplica. */
    private function affinitiesForPrompt(int $userId): string
    {
        $profile = ArtistProfile::findForUser($userId);
        return ArtistReferences::forPrompt((string)($profile['reference_artists'] ?? ''));
    }

    /** @return array<string,mixed> */
    private function artwork(int $userId, int $artworkId): array
    {
        $stmt = $this->pdo->prepare('SELECT final_title, series, medium, width, height, depth
            FROM artworks WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$artworkId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('La obra no existe o no es de este artista.');
        }

        $profile = ArtistProfile::findForUser($userId);

        return [
            'final_title' => (string)$row['final_title'],
            'series' => (string)($row['series'] ?? ''),
            'medium' => (string)($row['medium'] ?? ''),
            'width' => (string)($row['width'] ?? ''),
            'height' => (string)($row['height'] ?? ''),
            'depth' => (string)($row['depth'] ?? ''),
            'artist_name' => (string)($profile['artist_name'] ?? ''),
        ];
    }

    /**
     * @param array{locale:string,description:string,short_description:string,subtitle:string} $reading
     * @param array<string,mixed> $validation
     * @return array<string,mixed>
     */
    private function emptyResult(string $title, array $reading, array $validation): array
    {
        return [
            'title' => $title,
            'description' => '',
            'keywords' => [],
            'locale' => $reading['locale'],
            'source' => $reading,
            'validation' => $validation,
        ];
    }

    /** @return array<string,mixed> */
    private function decode(string $raw): array
    {
        $clean = trim($raw);
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $clean, $m)) {
            $clean = trim($m[1]);
        }
        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            $clean = substr($clean, $start, $end - $start + 1);
        }
        $decoded = json_decode($clean, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('El modelo no devolvio JSON valido: ' . mb_substr($clean, 0, 200));
        }
        return $decoded;
    }
}
