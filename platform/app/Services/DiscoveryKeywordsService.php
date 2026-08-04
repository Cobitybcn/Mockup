<?php
declare(strict_types=1);

/**
 * El vocabulario de descubrimiento de una obra, en un idioma, derivado de su
 * lectura editorial YA APROBADA en ese mismo idioma.
 *
 * Por que existe: el sitio no tenia una sola entrada emocional en ninguna obra
 * —sus search_terms son transaccionales ("Buy original...", "Art for modern
 * collectors")— y los nombres de los pintores afines no viajaban a ningun lado.
 * Desde que el JSON-LD publica keywords, ese vocabulario tiene por fin donde ir.
 *
 * Y no es una traduccion. Traducir una keyword la mata: nadie tipea la version
 * literal de una frase pensada en otro idioma. Cada idioma se deriva de SU
 * lectura aprobada, que es texto escrito en ese idioma.
 *
 * En ingles no hace falta llamar a nadie: la pasada de Saatchi ya produce este
 * mismo vocabulario y el sitio lo lee. Esto cubre los demas idiomas.
 */
class DiscoveryKeywordsService
{
    public const MIN = 5;
    public const TARGET = 12;
    public const KEYWORD_MAX = 40;

    private const STOPWORDS = [
        'the', 'a', 'an', 'of', 'and', 'or', 'in', 'on', 'with', 'for', 'to', 'at', 'from',
        'de', 'la', 'el', 'los', 'las', 'un', 'una', 'del', 'al', 'y', 'o', 'en', 'con', 'sobre',
    ];

    /** Lenguaje de venta: describe a quien vendersela, no que es. */
    private const SALES_LANGUAGE = [
        'buy', 'purchase', 'acquire', 'for sale', 'on sale', 'price', 'collector', 'investment',
        'interior designer', 'for architects', 'home decor', 'wall decor',
        'comprar', 'compra', 'venta', 'precio', 'coleccionista', 'inversion', 'inversión',
        'decoracion', 'decoración', 'interiorista',
    ];

    public function __construct(
        private PDO $pdo,
        private ?GeminiImageClient $client = null
    ) {
        $this->client = $client ?: new GeminiImageClient();
    }

    /* ————————————————————————— logica pura ————————————————————————— */

    /**
     * @param array<string,mixed> $decoded
     * @return list<string>
     */
    public static function parseModelOutput(array $decoded): array
    {
        $keywords = [];
        foreach ((array)($decoded['keywords'] ?? []) as $keyword) {
            $keyword = trim((string)$keyword);
            if ($keyword === '' || isset($keywords[mb_strtolower($keyword)])) {
                continue;
            }
            $keywords[mb_strtolower($keyword)] = $keyword;
        }
        return array_values($keywords);
    }

    public static function isSalesLanguage(string $termino): bool
    {
        $t = mb_strtolower(' ' . trim($termino) . ' ');
        foreach (self::SALES_LANGUAGE as $venta) {
            if (str_contains($t, $venta)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $keywords
     * @param list<string> $affinities nombres autorizados por el artista
     * @return list<string>
     */
    public static function validate(array $keywords, string $title, string $artistName, array $affinities = []): array
    {
        $errors = [];
        $cantidad = count($keywords);
        if ($cantidad < self::MIN || $cantidad > self::TARGET) {
            $errors[] = "keywords trae {$cantidad} entradas y deben ser entre " . self::MIN . ' y ' . self::TARGET . '.';
        }

        $reserved = [];
        foreach ([$title, $artistName] as $texto) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', (string)$texto) ?: [] as $palabra) {
                $palabra = mb_strtolower(trim($palabra));
                if (mb_strlen($palabra) >= 4 && !in_array($palabra, self::STOPWORDS, true)) {
                    $reserved[$palabra] = true;
                }
            }
        }
        // Un nombre autorizado nunca es palabra reservada: nombrarlo es el punto.
        $nombres = [];
        foreach ($affinities as $nombre) {
            $nombre = trim((string)$nombre);
            if ($nombre === '') {
                continue;
            }
            $nombres[] = mb_strtolower($nombre);
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $nombre) ?: [] as $palabra) {
                unset($reserved[mb_strtolower($palabra)]);
            }
        }

        $conNombre = 0;
        foreach ($keywords as $keyword) {
            $largo = mb_strlen($keyword);
            if ($largo < 2 || $largo > self::KEYWORD_MAX) {
                $errors[] = "la keyword \"{$keyword}\" mide {$largo} caracteres, fuera del rango 2-" . self::KEYWORD_MAX . '.';
            }
            if (self::isSalesLanguage($keyword)) {
                $errors[] = "la keyword \"{$keyword}\" es lenguaje de venta: describe a quien vendersela, no que es la obra.";
            }
            if (in_array(mb_strtolower($keyword), $nombres, true)) {
                $conNombre++;
                continue;
            }
            foreach (preg_split('/\s+/u', trim($keyword)) ?: [] as $palabra) {
                if (isset($reserved[mb_strtolower($palabra)])) {
                    $errors[] = "la keyword \"{$keyword}\" repite \"{$palabra}\", que ya viaja en el titulo o el nombre del artista.";
                    break;
                }
            }
        }
        if ($conNombre > 2) {
            $errors[] = "{$conNombre} keywords son nombres de artista y el maximo por obra es 2.";
        }

        return $errors;
    }

    /* ————————————————————————— orquestacion ————————————————————————— */

    /**
     * @return array{keywords:list<string>,locale:string,source_characters:int,validation:array<string,mixed>}
     */
    public function generate(int $userId, int $artworkId, string $locale): array
    {
        $artwork = $this->artwork($userId, $artworkId);
        $reading = $this->approvedReading($userId, $artworkId, $locale);

        if (trim($reading) === '') {
            return [
                'keywords' => [], 'locale' => $locale, 'source_characters' => 0,
                'validation' => [
                    'status' => 'requires_input',
                    'errors' => [],
                    'warnings' => ["La obra no tiene lectura aprobada en {$locale}: sin ella no hay de donde derivar."],
                ],
            ];
        }

        $profile = ArtistProfile::findForUser($userId);
        $afinidades = ArtistReferences::names((string)($profile['reference_artists'] ?? ''));
        $prompt = $this->prompt($artwork, $reading, $locale, (string)($profile['reference_artists'] ?? ''));

        $keywords = self::parseModelOutput($this->decode($this->client->generateText([$this->client->textPart($prompt)])));
        $errors = self::validate($keywords, (string)$artwork['final_title'], (string)$artwork['artist_name'], $afinidades);

        $repaired = false;
        if ($errors !== []) {
            $repaired = true;
            try {
                $reparacion = $prompt . "\n\nREPAIR PASS — your previous answer failed the application's deterministic"
                    . " validation. Correct ONLY what the errors name, keep everything else, and return the same strict"
                    . " JSON object.\n\nVALIDATION ERRORS\n- " . implode("\n- ", $errors)
                    . "\n\nPREVIOUS JSON\n" . json_encode(['keywords' => $keywords], JSON_UNESCAPED_UNICODE);
                $keywords = self::parseModelOutput($this->decode($this->client->generateText([$this->client->textPart($reparacion)])));
                $errors = self::validate($keywords, (string)$artwork['final_title'], (string)$artwork['artist_name'], $afinidades);
            } catch (RuntimeException $e) {
                $errors[] = 'La reparacion no devolvio JSON valido: ' . $e->getMessage();
            }
        }

        return [
            'keywords' => $keywords,
            'locale' => $locale,
            'source_characters' => mb_strlen($reading),
            'validation' => [
                'status' => $errors === [] ? 'ok' : 'requires_review',
                'errors' => $errors,
                'warnings' => count($keywords) === self::TARGET ? [] : ['Quedaron ' . count($keywords) . ' keywords y el objetivo son ' . self::TARGET . '.'],
                'metrics' => ['keyword_count' => count($keywords), 'repair_attempted' => $repaired],
            ],
        ];
    }

    /**
     * Deja el vocabulario en el BORRADOR de ese idioma y nada mas. Publicar —o
     * sea aprobar— sigue siendo la accion del artista, con su revision: nada
     * llega al sitio sin que alguien lo haya leido.
     *
     * @param array{keywords:list<string>,locale:string,validation:array<string,mixed>} $result
     */
    public function save(int $userId, int $artworkId, array $result): void
    {
        if ((string)($result['validation']['status'] ?? '') !== 'ok') {
            throw new RuntimeException('El vocabulario no quedo en ok y no se guarda solo.');
        }
        $locale = (string)$result['locale'];
        if ($locale === '') {
            throw new RuntimeException('Sin idioma no se sabe en que fila escribir.');
        }

        (new BilingualEditorialService($this->pdo))->mergeDerivedFields(
            $userId,
            'artwork',
            $artworkId,
            $locale,
            ['discovery_keywords' => implode(', ', $result['keywords'])]
        );
    }

    /* ————————————————————————— internos ————————————————————————— */

    /** @param array<string,mixed> $artwork */
    private function prompt(array $artwork, string $reading, string $locale, string $referencias): string
    {
        $reglas = (string)file_get_contents(__DIR__ . '/discovery_keywords_rules.txt');
        $idioma = $locale === 'es' ? 'Spanish' : ($locale === 'en' ? 'English' : $locale);
        $afinidades = ArtistReferences::forPrompt($referencias);
        if (trim($afinidades) === '') {
            $afinidades = '(none declared — do not name any artist)';
        }

        return <<<PROMPT
{$reglas}

TARGET LANGUAGE: {$idioma}

CONFIRMED ARTWORK DATA
- Title (never used as a keyword): {$artwork['final_title']}
- Artist (never used as a keyword): {$artwork['artist_name']}
- Series: {$artwork['series']}
- Medium: {$artwork['medium']}

AUTHORISED ARTIST AFFINITIES — each with the artist's own account of what they take from that
painter. That account is the criterion: select a name only when the reading below shows THIS work
performing it.
{$afinidades}

APPROVED EDITORIAL READING ({$locale}) — the only source of every claim
{$reading}
PROMPT;
    }

    private function approvedReading(int $userId, int $artworkId, string $locale): string
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
        $stmt = $this->pdo->prepare('SELECT final_title, series, medium FROM artworks WHERE id = ? AND user_id = ? LIMIT 1');
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
            throw new RuntimeException('El modelo no devolvio JSON valido: ' . mb_substr($clean, 0, 200));
        }
        return $decoded;
    }
}
