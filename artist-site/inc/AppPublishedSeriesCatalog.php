<?php
declare(strict_types=1);

final class AppPublishedSeriesCatalog
{
    public function __construct(private readonly PDO $pdo, private readonly string $artistEmail) {}

    public static function fromApp(string $appRoot, string $artistEmail): self
    {
        return new self(artist_site_database_connection($appRoot), $artistEmail);
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare('SELECT s.*,
                (SELECT COUNT(*) FROM artworks a WHERE a.user_id = s.user_id AND a.series_id = s.id) AS artwork_count
            FROM artwork_series s
            JOIN users u ON u.id = s.user_id
            WHERE LOWER(u.email) = ? AND s.published = 1
            ORDER BY s.year_start DESC, s.title ASC');
        $statement->execute([$this->artistEmail]);
        $rows = [];
        foreach ($statement as $row) {
            $rows[(string)$row['slug']] = $row;
        }
        return $rows;
    }

    public function one(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

}
