<?php
declare(strict_types=1);

/**
 * "Análisis Según Influencias del Artista": prosa breve que dice cuáles de las
 * afinidades DECLARADAS por el artista sostiene una obra concreta, y cómo.
 *
 * Por qué existe: las referencias del perfil publican su fundamento una sola
 * vez, en la página del artista. Este campo es la versión por obra de esa misma
 * decisión —la que ya toman las keywords de descubrimiento— hecha texto: qué
 * toma ESTA obra de cada afinidad que sostiene.
 *
 * Reglas de sangre, heredadas del canal:
 *   - Se deriva de la lectura de ese idioma, nunca vuelve a mirar la obra.
 *   - La lista declarada es cerrada: un texto que nombra a alguien fuera de
 *     ella, o que no nombra a nadie de ella, no está anclado y se descarta.
 *   - Vacío es un resultado VÁLIDO: la selección es la regla. Una obra puede
 *     sostener una afinidad, varias o ninguna.
 *   - Es un campo DERIVADO y OPCIONAL: no bloquea paquetes, no marca lecturas
 *     como cambiadas, y la escritura pasa por el dueño de la tabla al borrador.
 */
class InfluencesAnalysisService
{
    public const TEXT_MAX = 1500;

    public function __construct(
        private PDO $pdo,
        private ?GeminiImageClient $client = null
    ) {
        $this->client = $client ?: new GeminiImageClient();
    }

    /* ————————————————————————— logica pura ————————————————————————— */

    public static function parseModelOutput(array $decoded): string
    {
        return trim((string)($decoded['influences_analysis'] ?? ''));
    }

    /**
     * Ley determinista del campo. Un texto vacío pasa siempre: vacío es válido.
     *
     * @param list<string> $declaredNames
     * @return list<string>
     */
    public static function validate(string $text, array $declaredNames): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $errors = [];
        if ($declaredNames === []) {
            $errors[] = 'hay analisis pero el artista no declaro ninguna afinidad: sin lista no hay ancla.';
            return $errors;
        }
        if (self::namedArtists($text, $declaredNames) === []) {
            $errors[] = 'el analisis no nombra a ninguno de los artistas declarados: la seleccion es la regla y un texto sin ancla no se guarda.';
        }
        $largo = mb_strlen($text);
        if ($largo > self::TEXT_MAX) {
            $errors[] = "el analisis mide {$largo} caracteres y el tope es " . self::TEXT_MAX . '.';
        }
        return $errors;
    }

    /**
     * Qué artistas declarados aparecen nombrados en el texto.
     *
     * @param list<string> $declaredNames
     * @return list<string>
     */
    public static function namedArtists(string $text, array $declaredNames): array
    {
        $named = [];
        foreach ($declaredNames as $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            if (preg_match('/(^|[^\p{L}])' . preg_quote($name, '/') . '($|[^\p{L}])/iu', $text)) {
                $named[] = $name;
            }
        }
        return $named;
    }

    /* ————————————————————————— orquestacion ————————————————————————— */

    /**
     * @return array{text:string,locale:string,validation:array<string,mixed>}
     */
    public function generate(int $userId, int $artworkId, string $locale): array
    {
        $artwork = $this->artwork($userId, $artworkId);
        $profile = ArtistProfile::findForUser($userId);
        $declaradas = (string)($profile['reference_artists'] ?? '');
        $nombres = ArtistReferences::names($declaradas);
        if ($nombres === []) {
            // Sin afinidades declaradas no hay campo: el generador no nombra a
            // nadie que el artista no haya nombrado primero.
            return ['text' => '', 'locale' => $locale, 'validation' => ['status' => 'ok', 'errors' => [], 'warnings' => []]];
        }

        $reading = $this->reading($userId, $artworkId, $locale);
        if (trim($reading) === '') {
            return ['text' => '', 'locale' => $locale, 'validation' => [
                'status' => 'requires_input', 'errors' => [],
                'warnings' => ["La obra no tiene lectura en {$locale}: sin ella no hay de donde derivar."],
            ]];
        }

        $prompt = $this->prompt($artwork, $reading, $locale, $declaradas);
        $text = self::parseModelOutput($this->decode($this->client->generateText([$this->client->textPart($prompt)])));
        $errors = self::validate($text, $nombres);

        $repaired = false;
        if ($errors !== []) {
            $repaired = true;
            try {
                $reparacion = $prompt . "\n\nREPAIR PASS — your previous answer failed the application's deterministic"
                    . " validation. Correct ONLY what the errors name — returning an empty string is a valid"
                    . " correction — and return the same strict JSON object.\n\nVALIDATION ERRORS\n- "
                    . implode("\n- ", $errors)
                    . "\n\nPREVIOUS JSON\n" . json_encode(['influences_analysis' => $text], JSON_UNESCAPED_UNICODE);
                $text = self::parseModelOutput($this->decode($this->client->generateText([$this->client->textPart($reparacion)])));
                $errors = self::validate($text, $nombres);
            } catch (RuntimeException $e) {
                $errors[] = 'La reparacion no devolvio JSON valido: ' . $e->getMessage();
            }
        }

        if ($errors !== []) {
            // Descartar no es fallar: el campo es opcional y regenerable. El
            // motivo viaja como aviso para que el paquete lo muestre.
            return ['text' => '', 'locale' => $locale, 'validation' => [
                'status' => 'discarded', 'errors' => [],
                'warnings' => array_map(static fn (string $e): string => 'Influencias descartadas — ' . $e, $errors),
                'metrics' => ['repair_attempted' => $repaired],
            ]];
        }

        return ['text' => $text, 'locale' => $locale, 'validation' => [
            'status' => 'ok', 'errors' => [], 'warnings' => [],
            'metrics' => ['named_artists' => count(self::namedArtists($text, $nombres)), 'repair_attempted' => $repaired],
        ]];
    }

    /**
     * Deriva y guarda SOLO si el borrador de ese idioma tiene el campo vacío:
     * lo que el artista escribió o editó a mano no se pisa jamás. La escritura
     * pasa por el dueño de la tabla y toca solo el borrador.
     */
    public function deriveIfEmpty(int $userId, int $artworkId, string $locale): string
    {
        $actual = (new BilingualEditorialService($this->pdo))->get($userId, 'artwork', $artworkId, $locale);
        if (trim((string)(((array)$actual['content'])['influences_analysis'] ?? '')) !== '') {
            return "influencias {$locale}: conservadas (el campo ya tiene texto)";
        }
        $resultado = $this->generate($userId, $artworkId, $locale);
        $estado = (string)($resultado['validation']['status'] ?? '');
        if ($resultado['text'] === '') {
            $avisos = (array)($resultado['validation']['warnings'] ?? []);
            return "influencias {$locale}: " . ($avisos !== [] ? implode(' · ', $avisos)
                : ($estado === 'ok' ? 'ninguna afinidad sostenida (vacio valido)' : $estado));
        }
        (new BilingualEditorialService($this->pdo))->mergeDerivedFields(
            $userId,
            'artwork',
            $artworkId,
            $locale,
            ['influences_analysis' => $resultado['text']]
        );
        return "influencias {$locale}: escritas";
    }

    /* ————————————————————————— internos ————————————————————————— */

    private function prompt(array $artwork, string $reading, string $locale, string $declaradas): string
    {
        $language = match ($locale) {
            'es' => 'Spanish',
            'en' => 'English',
            default => $locale,
        };
        $reglas = str_replace(
            '{LANGUAGE}',
            $language,
            (string)file_get_contents(__DIR__ . '/influences_analysis_rules.txt')
        );
        $afinidades = ArtistReferences::forPrompt($declaradas, $locale);

        return <<<PROMPT
{$reglas}

CONFIRMED ARTWORK DATA (immutable)
- Official title (never vary it): {$artwork['final_title']}
- Artist: {$artwork['artist_name']}
- Series: {$artwork['series']}

DECLARED ARTIST AFFINITIES (closed list — the ONLY names available; each line is name: rationale)
{$afinidades}

APPROVED EDITORIAL READING ({$locale}) — the only source of visual and conceptual claims
{$reading}
PROMPT;
    }

    private function reading(int $userId, int $artworkId, string $locale): string
    {
        $stmt = $this->pdo->prepare('SELECT content_json, published_content_json FROM bilingual_editorial_content
            WHERE user_id = ? AND entity_type = ? AND entity_id = ? AND locale = ? LIMIT 1');
        $stmt->execute([$userId, 'artwork', $artworkId, $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['published_content_json', 'content_json'] as $columna) {
            $content = json_decode((string)($row[$columna] ?? ''), true);
            if (is_array($content) && trim((string)($content['description'] ?? '')) !== '') {
                return trim((string)$content['description']);
            }
        }
        return '';
    }

    /** @return array<string,mixed> */
    private function artwork(int $userId, int $artworkId): array
    {
        $stmt = $this->pdo->prepare('SELECT final_title, series FROM artworks WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$artworkId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('La obra no existe o no es de este artista.');
        }
        $profile = ArtistProfile::findForUser($userId);
        return [
            'final_title' => (string)$row['final_title'],
            'series' => (string)($row['series'] ?? ''),
            'artist_name' => (string)($profile['artist_name'] ?? ''),
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
            throw new RuntimeException('El modelo no devolvio JSON valido.');
        }
        return $decoded;
    }
}
