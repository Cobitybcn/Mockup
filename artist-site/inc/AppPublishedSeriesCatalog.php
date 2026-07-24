<?php
declare(strict_types=1);

require_once __DIR__ . '/AppPublishedLocalization.php';

final class AppPublishedSeriesCatalog
{
    private AppPublishedLocalization $localization;
    /** @var array<string,array<string,array<string,mixed>>> */
    private array $catalogCache = [];

    public function __construct(private readonly PDO $pdo, private readonly string $artistEmail)
    {
        $this->localization = new AppPublishedLocalization($pdo, $artistEmail);
    }

    public static function fromApp(string $appRoot, string $artistEmail): self
    {
        return new self(artist_site_database_connection($appRoot), $artistEmail);
    }

    public function all(): array
    {
        $language = function_exists('artist_site_language') ? artist_site_language() : 'es';
        if (isset($this->catalogCache[$language])) return $this->catalogCache[$language];

        $statement = $this->pdo->prepare('SELECT s.*,
                (SELECT COUNT(*) FROM artworks a WHERE a.user_id = s.user_id AND a.series_id = s.id) AS artwork_count
            FROM artwork_series s
            JOIN users u ON u.id = s.user_id
            WHERE LOWER(u.email) = ? AND s.published = 1
            ORDER BY
                CASE WHEN s.display_order > 0 THEN 0 ELSE 1 END ASC,
                s.display_order ASC,
                CASE WHEN s.year_start IS NULL AND s.year_end IS NULL THEN 1 ELSE 0 END ASC,
                COALESCE(s.year_start, s.year_end) DESC,
                COALESCE(s.year_end, s.year_start) DESC,
                s.created_at DESC,
                s.id DESC');
        $statement->execute([$this->artistEmail]);
        $rows = [];
        foreach ($statement as $row) {
            $spanish = $this->localization->content('series', (int)$row['id'], 'es');
            $english = $this->localization->content('series', (int)$row['id'], 'en');
            $localized = $language === 'es' ? $spanish : $english;
            $row['spanish_available'] = $spanish !== [];
            $row['english_available'] = $english !== [];
            if ($localized !== []) {
                $row['subtitle'] = (string)($localized['subtitle'] ?? $row['subtitle']);
                $row['description'] = (string)($localized['short_description'] ?? $row['description']);
                $row['long_description'] = (string)($localized['description'] ?? $row['long_description']);
                $row['tags'] = (string)($localized['tags'] ?? $row['tags']);
                $row['keywords'] = $this->searchTerms($localized, (string)$row['keywords']);
                $row['seo_title'] = (string)($localized['seo_title'] ?? '');
                $row['seo_description'] = (string)($localized['seo_description'] ?? $row['seo_description']);
            }
            $rows[(string)$row['slug']] = $row;
        }
        return $this->catalogCache[$language] = $rows;
    }

    public function one(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    private function searchTerms(array $content, string $fallback): string
    {
        $explicit = trim((string)($content['search_terms'] ?? ''));
        if ($explicit !== '') return $explicit;
        return $fallback;
    }

}
