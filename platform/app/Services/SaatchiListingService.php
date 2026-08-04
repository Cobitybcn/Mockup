<?php
declare(strict_types=1);

/**
 * Genera los campos propios del listing de Saatchi Art para una obra.
 *
 * Es una pasada DIRIGIDA: produce solo subtitulo, keywords y pies de imagen, y
 * escribe solo esos campos. No toca la lectura editorial ya aprobada ni los
 * textos del sitio, que estan publicados y no se tocan por esto.
 *
 * Los limites salen del formulario real de Saatchi, verificados el 2026-08-04:
 *   - titulo completo (obra + subtitulo)  <= 65 caracteres
 *   - keywords: 5 a 12, cada una de 2 a 20 caracteres, en ingles
 *   - pie por imagen adicional: el formulario no declara tope; <50 es decision
 *     editorial nuestra para que se lea como etiqueta y no como parrafo
 */
class SaatchiListingService
{
    public const TITLE_MAX = 65;
    public const KEYWORD_MIN = 2;
    public const KEYWORD_MAX = 20;
    public const KEYWORDS_TARGET = 12;
    public const CAPTION_MAX = 50;

    public function __construct(
        private PDO $pdo,
        private ?GeminiImageClient $client = null
    ) {
        $this->client = $client ?: new GeminiImageClient();
    }

    /**
     * @return array{title:string,subtitle:string,description:string,keywords:list<string>,captions:array<string,string>,budget:int,warnings:list<string>}
     */
    public function generate(int $userId, int $artworkId): array
    {
        $artwork = $this->artwork($userId, $artworkId);
        $title = trim((string)($artwork['final_title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('La obra no tiene titulo: sin identidad no hay listing.');
        }

        $budget = self::TITLE_MAX - mb_strlen($title) - 3;
        if ($budget < 12) {
            throw new RuntimeException("El titulo ocupa casi los 65 caracteres: quedan {$budget} para el subtitulo.");
        }

        $images = $this->images($userId, $artworkId);

        $parts = [$this->client->textPart($this->prompt($artwork, $images, $budget))];
        // Sin la imagen no hay analisis visual: el modelo estaria reescribiendo un
        // texto anterior en vez de mirar la obra, que es de donde salian las
        // contradicciones ("bloques suspendidos anclando").
        $rootPath = $this->rootImagePath($artwork);
        if ($rootPath !== '') {
            $parts[] = $this->client->imagePart($rootPath);
        }

        $raw = $this->client->generateText($parts);

        $decoded = $this->decode($raw);

        return $this->normalize($decoded, $title, $budget, $images);
    }

    /**
     * Escritura DIRIGIDA: toca solo los campos de Saatchi y deja intacto el resto
     * del contenido editorial, que esta publicado y aprobado.
     *
     * @param array{title:string,keywords:list<string>,captions:array<string,string>} $listing
     */
    public function save(int $userId, int $artworkId, array $listing): int
    {
        $escritos = 0;

        $stmt = $this->pdo->prepare('SELECT id, content_json, published_content_json FROM bilingual_editorial_content
            WHERE user_id = ? AND entity_type = ? AND entity_id = ?');
        $stmt->execute([$userId, 'artwork', $artworkId]);

        // Se escribe en el borrador Y en la copia publicada. El idioma de trabajo
        // lee la publicada (publicar = aprobar), y estos campos no van al sitio:
        // alimentan solo el paquete manual de Saatchi, que el artista revisa en
        // pantalla antes de copiarlo. Sin esto, lo generado no se ve.
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columnas = [];
            $valores = [];
            foreach (['content_json', 'published_content_json'] as $columna) {
                $crudo = (string)($row[$columna] ?? '');
                if (trim($crudo) === '') {
                    continue;
                }
                $content = json_decode($crudo, true);
                if (!is_array($content)) {
                    continue;
                }
                $content['saatchi_title'] = $listing['title'];
                $content['saatchi_keywords'] = implode(', ', $listing['keywords']);
                $content['saatchi_description'] = $listing['description'];
                $columnas[] = "{$columna} = ?";
                $valores[] = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($columnas === []) {
                continue;
            }
            $valores[] = date('c');
            $valores[] = (int)$row['id'];
            $update = $this->pdo->prepare('UPDATE bilingual_editorial_content SET ' . implode(', ', $columnas) . ', updated_at = ? WHERE id = ?');
            $update->execute($valores);
            $escritos++;
        }

        foreach ($listing['captions'] as $file => $caption) {
            $sheet = $this->pdo->prepare('UPDATE mockup_sheets SET saatchi_caption = ?, updated_at = ?
                WHERE user_id = ? AND mockup_file LIKE ?');
            $sheet->execute([$caption, date('c'), $userId, '%' . $file]);
            $escritos += $sheet->rowCount();
        }

        return $escritos;
    }

    /** @param array<string,mixed> $artwork @param list<array<string,string>> $images */
    private function prompt(array $artwork, array $images, int $budget): string
    {
        $title = trim((string)($artwork['final_title'] ?? ''));
        $lista = [];
        foreach ($images as $index => $image) {
            $lista[] = ($index + 1) . '. file=' . $image['file']
                . ' · camera=' . ($image['camera'] !== '' ? $image['camera'] : 'unknown')
                . ' · current_caption=' . ($image['caption'] !== '' ? mb_substr($image['caption'], 0, 160) : '(none)');
        }

        $referentes = trim((string)($artwork['reference_artists'] ?? ''));
        if ($referentes === '') {
            $referentes = '(none declared — do not name any artist)';
        }
        $reglas = (string)file_get_contents(__DIR__ . '/saatchi_listing_rules.txt');
        $captionList = $lista !== [] ? implode("
", $lista) : '(this artwork has no additional images)';

        return <<<PROMPT
{$reglas}

CONFIRMED DATA FOR THIS ARTWORK (immutable — never alter, never restate as prose)
- Official title: {$title}
- Series: {$artwork['series']}
- Technique and materials: {$artwork['medium']}
- Character budget for the subtitle: {$budget} (full title must stay within 65)
- Approved editorial reading (context only — do not copy its wording):
{$artwork['reading']}
- Catalogue tags already in use (do not repeat them as keywords): {$artwork['tags']}

ARTIST AFFINITIES DECLARED BY THE ARTIST (authorised for keywords, max two per artwork, only if
this artwork visibly sustains what the artist takes from them; use the plain normalised name, never
"Like Rothko" or "Rothko Style")
{$referentes}

IMAGES TO CAPTION
{$captionList}
PROMPT;
    }

    /** @param array<string,mixed> $artwork */
    private function rootImagePath(array $artwork): string
    {
        foreach (['root_file', 'main_file'] as $key) {
            $file = basename(trim((string)($artwork[$key] ?? '')));
            if ($file === '') {
                continue;
            }
            $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                return $path;
            }
        }
        return '';
    }

    /** @return array<string,mixed> */
    private function artwork(int $userId, int $artworkId): array
    {
        $stmt = $this->pdo->prepare('SELECT a.id, a.final_title, a.series, a.medium, a.root_file, a.main_file, a.user_id
            FROM artworks a WHERE a.id = ? AND a.user_id = ? LIMIT 1');
        $stmt->execute([$artworkId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('La obra no existe o no es de este artista.');
        }

        $sheet = $this->pdo->prepare("SELECT description, short_description, tags FROM artwork_sheets
            WHERE canonical_artwork_id = ? AND user_id = ? AND COALESCE(status,'') <> 'merged' ORDER BY id DESC LIMIT 1");
        $sheet->execute([$artworkId, $userId]);
        $sheetRow = $sheet->fetch(PDO::FETCH_ASSOC) ?: [];

        $profile = ArtistProfile::findForUser($userId);

        return [
            'final_title' => (string)$row['final_title'],
            'series' => (string)($row['series'] ?? ''),
            'medium' => (string)($row['medium'] ?? ''),
            'root_file' => (string)($row['root_file'] ?? ''),
            'main_file' => (string)($row['main_file'] ?? ''),
            'reading' => trim((string)($sheetRow['description'] ?? $sheetRow['short_description'] ?? '')),
            'tags' => (string)($sheetRow['tags'] ?? ''),
            'reference_artists' => (string)($profile['reference_artists'] ?? ''),
        ];
    }

    /** @return list<array<string,string>> */
    private function images(int $userId, int $artworkId): array
    {
        $stmt = $this->pdo->prepare('SELECT m.mockup_file, m.selector_state_json, s.caption
            FROM mockups m
            LEFT JOIN mockup_sheets s ON s.user_id = m.user_id AND (s.mockup_id = m.id OR s.mockup_file = m.mockup_file)
            WHERE m.user_id = ? AND (m.source_artwork_id = ?
                OR m.artwork_group_id = (SELECT artwork_group_id FROM artworks WHERE id = ?))
            ORDER BY m.id');
        $stmt->execute([$userId, $artworkId, $artworkId]);

        $images = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $state = json_decode((string)($row['selector_state_json'] ?? ''), true);
            $combination = is_array($state['combination'] ?? null) ? $state['combination'] : [];
            $images[] = [
                'file' => basename((string)$row['mockup_file']),
                'camera' => trim((string)($combination['camera_slot_name'] ?? '')),
                'caption' => trim((string)($row['caption'] ?? '')),
            ];
        }

        return $images;
    }

    /** @return array<string,mixed> */
    private function decode(string $raw): array
    {
        $clean = trim($raw);
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $clean, $m)) {
            $clean = trim($m[1]);
        }
        $decoded = json_decode($clean, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('El modelo no devolvio JSON valido: ' . mb_substr($clean, 0, 200));
        }
        return $decoded;
    }

    /**
     * @param array<string,mixed> $decoded
     * @param list<array<string,string>> $images
     * @return array{title:string,subtitle:string,description:string,keywords:list<string>,captions:array<string,string>,budget:int,warnings:list<string>}
     */
    private function normalize(array $decoded, string $title, int $budget, array $images): array
    {
        $warnings = [];

        $subtitle = trim((string)($decoded['title']['subtitle'] ?? $decoded['subtitle'] ?? ''));
        // Mayuscula inicial, sin tocar el resto: el subtitulo es una frase, no un
        // titulo en capitales, y el modelo la devuelve en minuscula a menudo.
        if ($subtitle !== '') {
            $subtitle = mb_strtoupper(mb_substr($subtitle, 0, 1)) . mb_substr($subtitle, 1);
        }
        if ($subtitle !== '' && mb_strlen($subtitle) > $budget) {
            $warnings[] = "El subtitulo mide " . mb_strlen($subtitle) . " y el presupuesto es {$budget}.";
        }

        $description = trim((string)($decoded['description_en'] ?? $decoded['description'] ?? ''));
        if ($description !== '' && mb_strlen($description) > 1000) {
            $warnings[] = 'La descripcion mide ' . mb_strlen($description) . ' y el tope de Saatchi es 1000.';
        }

        $keywords = [];
        foreach ((array)($decoded['keywords']['selected'] ?? $decoded['keywords'] ?? []) as $keyword) {
            $keyword = trim((string)$keyword);
            $largo = mb_strlen($keyword);
            if ($largo < self::KEYWORD_MIN || $largo > self::KEYWORD_MAX) {
                $warnings[] = "Keyword fuera del rango 2-20 de Saatchi ({$largo}): {$keyword}";
                continue;
            }
            if (isset($keywords[mb_strtolower($keyword)])) {
                continue;
            }
            $keywords[mb_strtolower($keyword)] = $keyword;
        }
        $keywords = array_slice(array_values($keywords), 0, self::KEYWORDS_TARGET);
        if (count($keywords) < 5) {
            $warnings[] = 'Saatchi exige al menos 5 keywords y quedaron ' . count($keywords) . '.';
        }

        $captions = [];
        $esperados = array_column($images, 'file');
        $entradas = [];
        foreach ((array)($decoded['image_captions'] ?? []) as $entrada) {
            if (is_array($entrada)) {
                $entradas[(string)($entrada['file'] ?? '')] = (string)($entrada['caption'] ?? '');
            }
        }
        if ($entradas === []) {
            $entradas = (array)($decoded['captions'] ?? []);
        }
        foreach ($entradas as $file => $caption) {
            $file = basename((string)$file);
            $caption = trim((string)$caption);
            if (!in_array($file, $esperados, true)) {
                $warnings[] = "Pie para una imagen que no es de esta obra: {$file}";
                continue;
            }
            if (mb_strlen($caption) > self::CAPTION_MAX) {
                $warnings[] = 'Pie de ' . mb_strlen($caption) . ' caracteres, tope ' . self::CAPTION_MAX . ": {$file}";
            }
            $captions[$file] = $caption;
        }
        foreach ($esperados as $file) {
            if (!isset($captions[$file])) {
                $warnings[] = "Falta el pie de {$file}";
            }
        }

        return [
            'title' => $subtitle !== '' ? $title . ' · ' . $subtitle : $title,
            'subtitle' => $subtitle,
            'description' => $description,
            'keywords' => $keywords,
            'captions' => $captions,
            'budget' => $budget,
            'analysis' => (array)($decoded['visual_analysis'] ?? []),
            'rejected' => (array)($decoded['keywords']['rejected'] ?? []),
            'status' => (string)($decoded['validation']['status'] ?? 'ok'),
            'warnings' => array_merge($warnings, array_map('strval', (array)($decoded['validation']['warnings'] ?? []))),
        ];
    }
}
