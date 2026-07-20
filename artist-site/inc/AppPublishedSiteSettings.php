<?php
declare(strict_types=1);

final class AppPublishedSiteSettings
{
    public function __construct(private readonly PDO $pdo, private readonly string $artistEmail) {}

    public static function fromApp(string $appRoot, string $artistEmail): self
    {
        return new self(artist_site_database_connection($appRoot), $artistEmail);
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        try {
            $statement = $this->pdo->prepare('SELECT settings.*
                FROM artist_site_settings settings
                INNER JOIN users u ON u.id=settings.user_id
                WHERE LOWER(u.email)=?
                LIMIT 1');
            $statement->execute([strtolower(trim($this->artistEmail))]);
            return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException) {
            // The public site must remain available before the Site Manager schema exists.
            return [];
        }
    }
}
