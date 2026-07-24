<?php
declare(strict_types=1);

final class SeriesKeywordResearchService
{
    private const LOCALES = ['es', 'en'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Imports a CSV/TSV export copied from Google Keyword Planner.
     *
     * @return array{imported:int,updated:int,skipped:int,total:int}
     */
    public function importPlannerExport(
        int $userId,
        int $seriesId,
        string $locale,
        string $market,
        string $raw
    ): array {
        $this->assertSeries($userId, $seriesId);
        $locale = $this->normalizeLocale($locale);
        $market = trim(preg_replace('/\s+/u', ' ', $market) ?? '');
        if ($market === '') throw new RuntimeException('Indicá el mercado de esta investigación.');
        if (mb_strlen($market) > 80) throw new RuntimeException('El nombre del mercado es demasiado largo.');
        if (strlen($raw) > 2000000) throw new RuntimeException('El archivo de Keyword Planner es demasiado grande.');

        $rows = $this->parseRows($raw);
        if ($rows === []) {
            throw new RuntimeException('No se encontraron filas de Keyword Planner reconocibles.');
        }

        $now = date(DATE_ATOM);
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $keyword = trim(preg_replace('/\s+/u', ' ', (string)($row['keyword_text'] ?? '')) ?? '');
            if ($keyword === '' || mb_strlen($keyword) > 255) {
                $skipped++;
                continue;
            }
            $existing = $this->pdo->prepare(
                'SELECT id FROM series_keyword_research
                 WHERE user_id=? AND series_id=? AND locale=? AND market=? AND keyword_text=? LIMIT 1'
            );
            $existing->execute([$userId, $seriesId, $locale, $market, $keyword]);
            $existingId = (int)$existing->fetchColumn();

            $values = [
                $this->nullableInteger($row['avg_monthly_searches'] ?? null),
                trim((string)($row['volume_label'] ?? '')),
                trim((string)($row['competition'] ?? '')),
                $this->nullableInteger($row['competition_index'] ?? null),
                $this->nullableDecimal($row['low_top_of_page_bid'] ?? null),
                $this->nullableDecimal($row['high_top_of_page_bid'] ?? null),
                strtoupper(substr(trim((string)($row['currency_code'] ?? '')), 0, 8)),
                $now,
                $now,
            ];

            if ($existingId > 0) {
                $update = $this->pdo->prepare(
                    'UPDATE series_keyword_research
                     SET avg_monthly_searches=?,volume_label=?,competition=?,competition_index=?,
                         low_top_of_page_bid=?,high_top_of_page_bid=?,currency_code=?,imported_at=?,updated_at=?
                     WHERE id=? AND user_id=?'
                );
                $update->execute([...$values, $existingId, $userId]);
                $updated++;
                continue;
            }

            $insert = $this->pdo->prepare(
                "INSERT INTO series_keyword_research
                 (user_id,series_id,locale,market,keyword_text,avg_monthly_searches,volume_label,
                  competition,competition_index,low_top_of_page_bid,high_top_of_page_bid,
                  currency_code,selected,source,imported_at,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,'google_keyword_planner',?,?,?)"
            );
            $insert->execute([
                $userId, $seriesId, $locale, $market, $keyword,
                ...array_slice($values, 0, 7),
                $now, $now, $now,
            ]);
            $imported++;
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => $imported + $updated,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function all(int $userId, int $seriesId, ?string $locale = null): array
    {
        $this->assertSeries($userId, $seriesId);
        $parameters = [$userId, $seriesId];
        $where = 'user_id=? AND series_id=?';
        if ($locale !== null) {
            $where .= ' AND locale=?';
            $parameters[] = $this->normalizeLocale($locale);
        }
        $statement = $this->pdo->prepare(
            "SELECT * FROM series_keyword_research
             WHERE {$where}
             ORDER BY selected DESC,
                      CASE WHEN avg_monthly_searches IS NULL THEN 1 ELSE 0 END,
                      avg_monthly_searches DESC,
                      locale ASC,market ASC,keyword_text ASC"
        );
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<int> $selectedIds */
    public function replaceSelection(int $userId, int $seriesId, array $selectedIds): void
    {
        $this->assertSeries($userId, $seriesId);
        $selectedIds = array_values(array_unique(array_filter(
            array_map('intval', $selectedIds),
            static fn(int $id): bool => $id > 0
        )));
        $this->pdo->beginTransaction();
        try {
            $now = date(DATE_ATOM);
            $this->pdo->prepare(
                'UPDATE series_keyword_research SET selected=0,updated_at=? WHERE user_id=? AND series_id=?'
            )->execute([$now, $userId, $seriesId]);
            if ($selectedIds !== []) {
                $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                $statement = $this->pdo->prepare(
                    "UPDATE series_keyword_research SET selected=1,updated_at=?
                     WHERE user_id=? AND series_id=? AND id IN ({$placeholders})"
                );
                $statement->execute([$now, $userId, $seriesId, ...$selectedIds]);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Supplies factual research evidence to the editorial prompt. Advertising
     * competition is named explicitly so it is never presented as organic SEO
     * difficulty.
     */
    public function promptContext(int $userId, int $seriesId): array
    {
        try {
            $rows = $this->all($userId, $seriesId);
        } catch (Throwable) {
            return [
                'status' => 'not_validated',
                'instruction' => 'No Google Keyword Planner data has been imported. Treat every generated phrase as an unvalidated candidate and never invent metrics.',
                'selected' => [],
                'candidates' => [],
            ];
        }
        $compact = static fn(array $row): array => array_filter([
            'locale' => (string)$row['locale'],
            'market' => (string)$row['market'],
            'keyword' => (string)$row['keyword_text'],
            'avg_monthly_searches' => $row['avg_monthly_searches'] !== null ? (int)$row['avg_monthly_searches'] : null,
            'volume_label' => (string)$row['volume_label'],
            'advertising_competition' => (string)$row['competition'],
            'advertising_competition_index' => $row['competition_index'] !== null ? (int)$row['competition_index'] : null,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        $selected = array_values(array_map($compact, array_filter(
            $rows,
            static fn(array $row): bool => (int)$row['selected'] === 1
        )));
        $candidates = array_slice(array_values(array_map($compact, $rows)), 0, 80);
        return [
            'status' => $selected !== [] ? 'validated_selection_available' : ($rows !== [] ? 'research_imported_selection_pending' : 'not_validated'),
            'instruction' => $selected !== []
                ? 'Use selected phrases as validated evidence. Metrics are historical estimates and advertising competition is not organic SEO difficulty.'
                : 'Research may contain candidates, but none is selected yet. Generate provisional recommendations and never invent metrics.',
            'selected' => $selected,
            'candidates' => $candidates,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function parseRows(string $raw): array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', trim($raw)) ?? trim($raw);
        if ($raw === '') return [];
        $lines = preg_split('/\R/u', $raw) ?: [];
        $delimiter = $this->detectDelimiter($lines);
        $parsed = array_map(static fn(string $line): array => str_getcsv($line, $delimiter), $lines);
        $headerIndex = null;
        $headerMap = [];
        foreach (array_slice($parsed, 0, 30, true) as $index => $columns) {
            $map = $this->headerMap($columns);
            if (isset($map['keyword_text'])) {
                $headerIndex = $index;
                $headerMap = $map;
                break;
            }
        }
        if ($headerIndex === null) return [];

        $rows = [];
        foreach (array_slice($parsed, $headerIndex + 1) as $columns) {
            $row = [];
            foreach ($headerMap as $field => $columnIndex) {
                $row[$field] = trim((string)($columns[$columnIndex] ?? ''));
            }
            if (trim((string)($row['keyword_text'] ?? '')) === '') continue;
            $volume = trim((string)($row['avg_monthly_searches'] ?? ''));
            $row['volume_label'] = $volume !== '' && $this->nullableInteger($volume) === null ? $volume : '';
            $rows[] = $row;
        }
        return $rows;
    }

    /** @param list<string> $lines */
    private function detectDelimiter(array $lines): string
    {
        $sample = implode("\n", array_slice($lines, 0, 20));
        $counts = [
            "\t" => substr_count($sample, "\t"),
            ';' => substr_count($sample, ';'),
            ',' => substr_count($sample, ','),
        ];
        arsort($counts);
        return (string)array_key_first($counts);
    }

    /** @param list<string> $headers @return array<string,int> */
    private function headerMap(array $headers): array
    {
        $aliases = [
            'keyword_text' => ['keyword', 'keyword text', 'palabra clave', 'texto de la palabra clave'],
            'avg_monthly_searches' => ['avg monthly searches', 'average monthly searches', 'promedio de busquedas mensuales', 'busquedas mensuales medias'],
            'competition' => ['competition', 'competencia'],
            'competition_index' => ['competition indexed value', 'competition index', 'competencia valor indexado', 'indice de competencia'],
            'low_top_of_page_bid' => ['top of page bid low range', 'puja por la parte superior de la pagina intervalo bajo', 'puja pagina superior intervalo bajo'],
            'high_top_of_page_bid' => ['top of page bid high range', 'puja por la parte superior de la pagina intervalo alto', 'puja pagina superior intervalo alto'],
            'currency_code' => ['currency', 'currency code', 'moneda', 'codigo de moneda'],
        ];
        $map = [];
        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader($header);
            foreach ($aliases as $field => $options) {
                if (in_array($normalized, $options, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }
        return $map;
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));
        $header = strtr($header, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header);
        if ($transliterated !== false) $header = $transliterated;
        $header = preg_replace('/[^a-z0-9]+/', ' ', $header) ?? '';
        return trim(preg_replace('/\s+/', ' ', $header) ?? '');
    }

    private function nullableInteger(mixed $value): ?int
    {
        $value = trim((string)$value);
        if ($value === '' || preg_match('/[-–—]/u', $value)) return null;
        $normalized = preg_replace('/[^\d]/', '', $value) ?? '';
        return $normalized !== '' ? (int)$normalized : null;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        $normalized = preg_replace('/[^\d,.\-]/', '', $value) ?? '';
        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = strrpos($normalized, ',') > strrpos($normalized, '.')
                ? str_replace(['.', ','], ['', '.'], $normalized)
                : str_replace(',', '', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }
        return is_numeric($normalized) ? (float)$normalized : null;
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));
        if (!in_array($locale, self::LOCALES, true)) {
            throw new RuntimeException('El idioma de investigación debe ser español o inglés.');
        }
        return $locale;
    }

    private function assertSeries(int $userId, int $seriesId): void
    {
        if ($userId <= 0 || $seriesId <= 0) throw new RuntimeException('Serie no válida.');
        $statement = $this->pdo->prepare('SELECT 1 FROM artwork_series WHERE id=? AND user_id=? LIMIT 1');
        $statement->execute([$seriesId, $userId]);
        if (!$statement->fetchColumn()) throw new RuntimeException('Serie no encontrada.');
    }
}
