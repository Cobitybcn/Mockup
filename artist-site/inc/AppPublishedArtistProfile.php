<?php
declare(strict_types=1);

final class AppPublishedArtistProfile
{
    public function __construct(private readonly PDO $pdo, private readonly string $artistEmail) {}

    public static function fromApp(string $appRoot, string $artistEmail): self
    {
        return new self(artist_site_database_connection($appRoot), $artistEmail);
    }

    public function get(): ?array
    {
        $statement = $this->pdo->prepare('
            SELECT ap.*
            FROM artist_profiles ap
            JOIN users u ON u.id = ap.user_id
            WHERE LOWER(u.email) = ?
            LIMIT 1
        ');
        $statement->execute([$this->artistEmail]);
        return $statement->fetch() ?: null;
    }

}
