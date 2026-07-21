<?php
declare(strict_types=1);

final class TenantResolver
{
    private readonly PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function resolveEmail(): string
    {
        $local = self::isLocalEnvironment();
        $rawHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        $host = strtolower(trim((string)(parse_url('http://' . $rawHost, PHP_URL_HOST) ?: ''), '.'));
        if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1') {
            $stmt = $this->pdo->prepare('
                SELECT u.email 
                FROM artist_domains ad
                JOIN users u ON u.id = ad.user_id
                WHERE LOWER(ad.hostname) = ?
                  AND ad.status = \'verified\'
                LIMIT 1
            ');
            $stmt->execute([$host]);
            $email = $stmt->fetchColumn();
            if ($email) {
                return (string)$email;
            }

            if (preg_match('/^([a-z0-9\-]+)\.artworkmockups\.com$/i', $host, $matches)) {
                $subdomain = strtolower($matches[1]);
                $stmt = $this->pdo->prepare('
                    SELECT u.email 
                    FROM artist_profiles ap
                    JOIN users u ON u.id = ap.user_id
                    WHERE LOWER(ap.subdomain) = ?
                    LIMIT 1
                ');
                $stmt->execute([$subdomain]);
                $email = $stmt->fetchColumn();
                if ($email) {
                    return (string)$email;
                }
            }
        }

        $envEmail = getenv('ACTIVE_ARTIST_EMAIL');
        if (!$envEmail) {
            // Cargar archivo .env local si existe
            $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
            if (is_file($envPath)) {
                foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                    [$key, $value] = array_map('trim', explode('=', $line, 2));
                    if ($key === 'ACTIVE_ARTIST_EMAIL') {
                        $envEmail = trim($value, "\"'");
                        break;
                    }
                }
            }
        }

        if (!$envEmail || !filter_var($envEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('No active artist identity is configured for this host.');
        }

        $configuredUrl = trim((string)(getenv('ARTIST_SITE_PUBLIC_URL') ?: ''));
        $configuredHost = strtolower(trim((string)(parse_url($configuredUrl, PHP_URL_HOST) ?: ''), '.'));
        $cloudRunHost = str_ends_with($host, '.run.app');
        if (!$local && $host !== $configuredHost && !$cloudRunHost) {
            throw new RuntimeException('No verified artist website matches this host.');
        }
        return strtolower(trim((string)$envEmail));
    }

    private static function isLocalEnvironment(): bool
    {
        $environment = strtolower(trim((string)(getenv('APP_ENV') ?: '')));
        if (in_array($environment, ['local', 'development', 'testing'], true)) return true;
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        return $host === '' || str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1') || str_starts_with($host, '[::1]');
    }
}
