<?php
declare(strict_types=1);

final class SocialBoardDestinationSettings
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{website:string,saatchi:string} */
    public function forUser(int $userId): array
    {
        $destinations = $this->environmentDefaults();
        if ($userId <= 0) return $destinations;

        $stmt = $this->pdo->prepare('SELECT value FROM app_settings WHERE `key`=?');
        $stmt->execute([$this->key($userId)]);
        $saved = json_decode((string)$stmt->fetchColumn(), true);
        if (!is_array($saved)) return $destinations;

        foreach (array_keys($destinations) as $destination) {
            $value = trim((string)($saved[$destination] ?? ''));
            if ($value !== '') $destinations[$destination] = $value;
        }
        return $destinations;
    }

    /** @return array{website:string,saatchi:string} */
    public function save(int $userId, string $website, string $saatchi): array
    {
        if ($userId <= 0) throw new RuntimeException('The destination settings session is invalid.');
        $destinations = [
            'website' => $this->publicHttpsUrl($website, 'Website'),
            'saatchi' => $this->publicHttpsUrl($saatchi, 'Saatchi Art'),
        ];
        $key = $this->key($userId);
        $value = json_encode($destinations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now = date('c');

        $update = $this->pdo->prepare('UPDATE app_settings SET value=?,updated_at=? WHERE `key`=?');
        $update->execute([$value, $now, $key]);
        if ($update->rowCount() === 0) {
            try {
                $insert = $this->pdo->prepare('INSERT INTO app_settings (`key`,value,updated_at) VALUES (?,?,?)');
                $insert->execute([$key, $value, $now]);
            } catch (PDOException) {
                $update->execute([$value, $now, $key]);
            }
        }
        return $destinations;
    }

    /** @return array{website:string,saatchi:string} */
    private function environmentDefaults(): array
    {
        return [
            'website' => rtrim(app_env('ARTIST_WEBSITE_CATALOG_URL', 'https://mauriziovalch.com/artworks'), '/'),
            'saatchi' => rtrim(app_env('SAATCHI_ARTIST_URL', 'https://www.saatchiart.com/mauriziovalch'), '/'),
        ];
    }

    private function publicHttpsUrl(string $value, string $label): string
    {
        $value = rtrim(trim($value), '/');
        if (!filter_var($value, FILTER_VALIDATE_URL)
            || strtolower((string)parse_url($value, PHP_URL_SCHEME)) !== 'https'
            || trim((string)parse_url($value, PHP_URL_HOST)) === '') {
            throw new InvalidArgumentException($label . ' must use a public HTTPS URL.');
        }
        return $value;
    }

    private function key(int $userId): string
    {
        return 'social_board_destinations_user_' . $userId;
    }
}
