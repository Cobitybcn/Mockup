<?php
declare(strict_types=1);

/**
 * EDITORIAL_CORE Libro I Cap. 6 — herramienta curatorial de titulos.
 *
 * Sugerir si, decidir jamas: este servicio propone titulos bajo el criterio
 * del artista (latin preferente, una palabra, ADN por serie, evidencia visual
 * primero) y mantiene el registro semantico que impide repeticiones exactas y
 * conceptuales. Nada aqui escribe final_title: solo confirm(), que es la
 * accion explicita del artista. Un titulo locked es inmutable.
 */
final class TitleSuggestionService
{
    private const STATUSES = ['suggested', 'shortlisted', 'approved', 'locked', 'rejected'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?GeminiImageClient $client = null
    ) {}

    public static function normalize(string $title): string
    {
        $title = mb_strtoupper(trim($title));
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        if (is_string($transliterated) && $transliterated !== '') $title = strtoupper($transliterated);
        return trim((string)preg_replace('/[^A-Z0-9 ]+/', '', $title));
    }

    public static function formatCanonical(string $title): string
    {
        $title = trim($title);
        if ($title === '') return '';

        // Reemplazar guiones simples o en-dash entre espacios con em-dash canónico
        $normalized = preg_replace('/\s+[\-\x{2013}\x{2014}]\s+/u', ' — ', $title);

        if (str_contains($normalized, ' — ')) {
            $parts = explode(' — ', $normalized, 2);
            $prefix = mb_strtoupper(trim($parts[0]));
            $suffix = trim($parts[1]);
            return $prefix . ' — ' . $suffix;
        }

        return $normalized;
    }

    /**
     * Aviso NO bloqueante para titulos manuales (regla cero: titular es del
     * artista; el sistema solo muestra lo que el mismo pidio vigilar).
     *
     * @return list<array{title:string,artwork_id:int,series_id:int,kind:string}>
     */
    public function collisionsForTitle(int $userId, string $candidate, int $excludeArtworkId = 0): array
    {
        $normalized = self::normalize($candidate);
        if ($normalized === '') return [];
        $candidateWords = array_filter(explode(' ', $normalized), static fn(string $w): bool => mb_strlen($w) >= 3);
        $stmt = $this->pdo->prepare("SELECT title, normalized, semantic_root, artwork_id, series_id FROM artwork_title_registry
            WHERE user_id=? AND status IN ('approved','locked') AND artwork_id<>?");
        $stmt->execute([$userId, $excludeArtworkId]);
        $hits = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $kind = '';
            $existing = (string)$row['normalized'];
            if ($existing === $normalized) {
                $kind = 'exacta';
            } elseif ($this->sharesLongStem($normalized, $existing)) {
                $kind = 'raíz léxica compartida';
            } else {
                foreach ($candidateWords as $word) {
                    if (preg_match('/(?:^| )' . preg_quote($word, '/') . '(?: |$)/', $existing) === 1) {
                        $kind = 'palabra compartida';
                        break;
                    }
                }
            }
            if ($kind !== '') {
                $hits[] = [
                    'title' => (string)$row['title'],
                    'artwork_id' => (int)$row['artwork_id'],
                    'series_id' => (int)$row['series_id'],
                    'kind' => $kind,
                ];
            }
        }
        return $hits;
    }

    /**
     * Mapa de colisiones del catalogo entero: raices semanticas con mas de un
     * titulo aprobado. Solo informa — renombrar es decision del artista.
     *
     * @return array<string,list<string>>
     */
    public function collisionMap(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT semantic_root, title FROM artwork_title_registry
            WHERE user_id=? AND status IN ('approved','locked') AND semantic_root<>'' ORDER BY semantic_root, title");
        $stmt->execute([$userId]);
        $byRoot = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byRoot[(string)$row['semantic_root']][] = (string)$row['title'];
        }
        return array_filter($byRoot, static fn(array $titles): bool => count($titles) > 1);
    }

    /** @return list<array<string,mixed>> */
    public function suggestionsForArtwork(int $userId, int $artworkId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM artwork_title_registry
            WHERE user_id=? AND artwork_id=? AND status IN ('suggested','shortlisted') ORDER BY id DESC LIMIT 16");
        $stmt->execute([$userId, $artworkId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Genera 5-8 propuestas tipadas. Principio central (Libro I Cap. 6):
     * primero mirar la obra, despues reconocer la serie, al final la palabra.
     *
     * @return list<array<string,mixed>>
     */
    public function suggest(int $userId, int $artworkId, string $direction = ''): array
    {
        $artwork = $this->artwork($userId, $artworkId);
        $seriesId = (int)($artwork['series_id'] ?? 0);
        if ($seriesId <= 0) {
            throw new DomainException('Asigná la obra a una serie antes de pedir sugerencias de título.');
        }
        $series = $this->series($userId, $seriesId);
        $imagePath = $this->rootImagePath($artwork);

        $registry = $this->registrySnapshot($userId);
        $dna = trim((string)($series['title_dna'] ?? ''));
        $dnaBlock = $dna !== ''
            ? $dna
            : "(La serie aún no tiene ADN de títulos escrito por el artista; derivá con máxima prudencia del núcleo conceptual siguiente y quedate en su vocabulario.)\n" . trim((string)($series['conceptual_core'] ?? ''));
        $directionBlock = $direction !== '' ? "\nDIRECCIÓN PEDIDA POR EL ARTISTA PARA ESTA TANDA: {$direction}\n" : '';

        // El prompt se DERIVA de los articulos de EDITORIAL_CORE.md (Libro I
        // Cap. 6, Libro II Caps. 1-3) — nunca es autoridad propia.
        $prompt = "Actúa como asistente curatorial especializado en la obra de este artista.\n"
            . "Analiza primero únicamente los elementos visibles de la obra adjunta (dirección dominante, ascenso o descenso, peso, división, estratos, incisiones, frecuencias, huellas, contraste, concentración o dispersión, aparición, umbral, materia, luz, sombra, quietud, fractura, expansión, compresión) y después relaciónalos con el ADN de la serie.\n"
            . "SERIE: " . (string)$series['title'] . "\nADN DE TÍTULOS DE LA SERIE (autoridad del artista):\n{$dnaBlock}\n"
            . "LÍMITES INTERPRETATIVOS (prohibiciones):\n" . trim((string)($series['interpretive_limits'] ?? '')) . "\n"
            . $directionBlock
            . "TÍTULOS YA USADOS EN EL CATÁLOGO (prohibido repetirlos o proponer variantes semánticamente cercanas):\n" . $registry['used'] . "\n"
            . "RAÍCES SEMÁNTICAS YA SATURADAS (no proponer más equivalentes de estos conceptos):\n" . $registry['roots'] . "\n"
            . "TÍTULOS RECHAZADOS POR EL ARTISTA (no volver a proponerlos):\n" . $registry['rejected'] . "\n"
            . "REGLAS DE FORMA: preferentemente UNA palabra (excepcionalmente dos); breve, pronunciable, memorable, visualmente limpio, compatible con un catálogo internacional. Latín preferente; arameo solo cuando el término sea sólido y verificable; otras lenguas antiguas solo excepcional y justificadamente. Sin oraciones, subtítulos explicativos, adjetivos ornamentales ni construcciones literarias. El título es universal: idéntico en todos los idiomas.\n"
            . "PROHIBIDO como título o argumento: masterpiece, pivotal, museum-quality, important work, highly collectible, investment artwork, significant painting, spiritual awakening, inner journey, soul, healing, energy, cosmic, sacred, wall art, home decor. Desaconsejados los genéricos: Untitled, Composition, Abstract Landscape, Inner World, Silent Journey, Eternal Light, Infinite Horizon, Fragmented Memory.\n"
            . "No inventes narraciones, personajes, estados psicológicos ni simbolismos no respaldados por la imagen o la serie. El título debe abrir una lectura sin explicar ni clausurar la obra: una palabra que active una lectura antes que una palabra que resuma toda la pintura.\n"
            . "Devolvé SOLO un array JSON de 5 a 8 propuestas, divididas por intención (formal, material, conceptual, esencial, singular — sin variantes casi idénticas), la primera como recomendación principal. Cada una: {\"title\":\"\",\"language\":\"\",\"meaning\":\"\",\"reason\":\"motivo visual o conceptual breve\",\"semantic_root\":\"un concepto en una palabra española (ej: luz, umbral, intervalo)\",\"tone\":\"formal|material|conceptual|esencial|singular\",\"confidence\":0.0,\"recommended\":true|false}";

        $client = $this->client ?? new GeminiImageClient();
        $raw = $client->generateText([
            $client->textPart($prompt),
            $client->imagePart($imagePath),
        ]);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($raw)) ?? trim($raw);
        $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
        $start = strpos($clean, '['); $end = strrpos($clean, ']');
        if ($start !== false && $end !== false && $end > $start) $clean = substr($clean, $start, $end - $start + 1);
        $decoded = json_decode($clean, true);
        if (!is_array($decoded) || $decoded === []) {
            throw new RuntimeException('El asistente de títulos no devolvió propuestas válidas.');
        }

        $now = date(DATE_ATOM);
        $insert = $this->pdo->prepare('INSERT INTO artwork_title_registry
            (user_id,series_id,artwork_id,title,normalized,language,meaning,semantic_root,tone,confidence,reason,status,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,\'suggested\',?,?)');
        $stored = [];
        foreach (array_slice($decoded, 0, 8) as $item) {
            if (!is_array($item)) continue;
            $title = trim((string)($item['title'] ?? ''));
            $normalized = self::normalize($title);
            if ($title === '' || $normalized === '') continue;
            // Control de repeticion (Libro I Cap. 6): exacta y cercana.
            if ($this->collisionsForTitle($userId, $title) !== []) continue;
            $insert->execute([
                $userId, $seriesId, $artworkId, $title, $normalized,
                trim((string)($item['language'] ?? '')),
                trim((string)($item['meaning'] ?? '')),
                mb_strtolower(trim((string)($item['semantic_root'] ?? ''))),
                trim((string)($item['tone'] ?? '')),
                (string)round((float)($item['confidence'] ?? 0), 2),
                trim((string)($item['reason'] ?? '')),
                $now, $now,
            ]);
            $item['id'] = (int)$this->pdo->lastInsertId();
            $stored[] = $item;
        }
        if ($stored === []) {
            throw new RuntimeException('Todas las propuestas colisionaban con el registro — pedí otra dirección.');
        }
        return $stored;
    }

    public function updateStatus(int $userId, int $suggestionId, string $status): void
    {
        if (!in_array($status, ['shortlisted', 'rejected', 'suggested'], true)) {
            throw new InvalidArgumentException('Estado de sugerencia no válido.');
        }
        $this->pdo->prepare('UPDATE artwork_title_registry SET status=?, updated_at=? WHERE id=? AND user_id=? AND status IN (\'suggested\',\'shortlisted\')')
            ->execute([$status, date(DATE_ATOM), $suggestionId, $userId]);
    }

    /**
     * La UNICA via por la que una sugerencia toca el catalogo: la confirmacion
     * explicita del artista. Un titulo locked es inmutable.
     */
    public function confirm(int $userId, int $artworkId, int $suggestionId, bool $lock = false): string
    {
        if ($this->isLocked($userId, $artworkId)) {
            throw new DomainException('El título de esta obra está bloqueado por el artista y no puede reemplazarse.');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM artwork_title_registry WHERE id=? AND user_id=? AND artwork_id=? LIMIT 1');
        $stmt->execute([$suggestionId, $userId, $artworkId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new DomainException('La sugerencia no existe.');
        $title = (string)$row['title'];
        (new BilingualEditorialService($this->pdo))->saveUniversalTitle($userId, 'artwork', $artworkId, $title);
        $now = date(DATE_ATOM);
        $this->pdo->prepare("UPDATE artwork_title_registry SET status='rejected', updated_at=? WHERE user_id=? AND artwork_id=? AND status IN ('approved','locked')")
            ->execute([$now, $userId, $artworkId]);
        $this->pdo->prepare('UPDATE artwork_title_registry SET status=?, updated_at=? WHERE id=? AND user_id=?')
            ->execute([$lock ? 'locked' : 'approved', $now, $suggestionId, $userId]);
        return $title;
    }

    public function setLock(int $userId, int $artworkId, bool $locked): void
    {
        $this->pdo->prepare("UPDATE artwork_title_registry SET status=?, updated_at=? WHERE user_id=? AND artwork_id=? AND status IN ('approved','locked')")
            ->execute([$locked ? 'locked' : 'approved', date(DATE_ATOM), $userId, $artworkId]);
    }

    public function isLocked(int $userId, int $artworkId): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM artwork_title_registry WHERE user_id=? AND artwork_id=? AND status='locked'");
        $stmt->execute([$userId, $artworkId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Registra (o actualiza) el titulo vigente de una obra en el registro —
     * usado por la siembra del catalogo y por titulos escritos a mano.
     */
    public function registerCatalogTitle(int $userId, int $artworkId, int $seriesId, string $title, string $language = '', string $meaning = '', string $semanticRoot = ''): void
    {
        $normalized = self::normalize($title);
        if ($normalized === '') return;
        $now = date(DATE_ATOM);
        $stmt = $this->pdo->prepare("SELECT id, status FROM artwork_title_registry WHERE user_id=? AND artwork_id=? AND status IN ('approved','locked') LIMIT 1");
        $stmt->execute([$userId, $artworkId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            $this->pdo->prepare('UPDATE artwork_title_registry SET series_id=?, title=?, normalized=?, language=COALESCE(NULLIF(?,\'\'),language), meaning=COALESCE(NULLIF(?,\'\'),meaning), semantic_root=COALESCE(NULLIF(?,\'\'),semantic_root), updated_at=? WHERE id=?')
                ->execute([$seriesId, $title, $normalized, $language, $meaning, mb_strtolower($semanticRoot), $now, (int)$existing['id']]);
            return;
        }
        $this->pdo->prepare('INSERT INTO artwork_title_registry
            (user_id,series_id,artwork_id,title,normalized,language,meaning,semantic_root,tone,confidence,reason,status,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,\'\',\'\',\'\',\'approved\',?,?)')
            ->execute([$userId, $seriesId, $artworkId, $title, $normalized, $language, $meaning, mb_strtolower($semanticRoot), $now, $now]);
    }

    private function sharesLongStem(string $a, string $b): bool
    {
        $a = str_replace(' ', '', $a);
        $b = str_replace(' ', '', $b);
        $max = min(strlen($a), strlen($b));
        $shared = 0;
        for ($i = 0; $i < $max; $i++) {
            if ($a[$i] !== $b[$i]) break;
            $shared++;
        }
        return $shared >= 6;
    }

    /** @return array{used:string,roots:string,rejected:string} */
    private function registrySnapshot(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT title, semantic_root, status FROM artwork_title_registry WHERE user_id=? AND status IN ('approved','locked','rejected')");
        $stmt->execute([$userId]);
        $used = []; $roots = []; $rejected = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['status'] === 'rejected') { $rejected[] = (string)$row['title']; continue; }
            $used[] = (string)$row['title'];
            $root = trim((string)$row['semantic_root']);
            if ($root !== '') $roots[$root] = ($roots[$root] ?? 0) + 1;
        }
        $saturated = array_keys(array_filter($roots, static fn(int $n): bool => $n >= 2));
        return [
            'used' => $used !== [] ? '- ' . implode("\n- ", array_unique($used)) : '- (ninguno)',
            'roots' => $saturated !== [] ? '- ' . implode("\n- ", $saturated) : '- (ninguna)',
            'rejected' => $rejected !== [] ? '- ' . implode("\n- ", array_unique($rejected)) : '- (ninguno)',
        ];
    }

    /** @return array<string,mixed> */
    private function artwork(int $userId, int $artworkId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artworks WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$artworkId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new DomainException('La obra no existe.');
        return $row;
    }

    /** @return array<string,mixed> */
    private function series(int $userId, int $seriesId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artwork_series WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$seriesId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new DomainException('La serie no existe.');
        return $row;
    }

    private function rootImagePath(array $artwork): string
    {
        $file = basename(trim((string)($artwork['root_file'] ?? ''))) ?: basename(trim((string)($artwork['main_file'] ?? '')));
        if ($file === '' || !defined('RESULTS_DIR')) {
            throw new DomainException('Seleccioná la imagen raíz de la obra antes de pedir títulos.');
        }
        $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path) && class_exists('StorageService') && StorageService::isGcsActive()) {
            StorageService::downloadFile('results/' . $file, $path);
        }
        if (!is_file($path)) throw new DomainException('No se encontró la imagen raíz de la obra.');
        return $path;
    }
}
