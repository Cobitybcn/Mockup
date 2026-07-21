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
        // Resolve only from trusted deployment configuration or a registered host.
        // A query-string tenant override would allow cross-tenant enumeration.
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        $host = preg_replace('/:\d+$/', '', $host) ?: '';
        if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1') {
            // Intentar buscar coincidencia directa por dominio personalizado
            $stmt = $this->pdo->prepare('
                SELECT u.email 
                FROM artist_profiles ap
                JOIN users u ON u.id = ap.user_id
                WHERE LOWER(ap.custom_domain) = ?
                LIMIT 1
            ');
            $stmt->execute([$host]);
            $email = $stmt->fetchColumn();
            if ($email) {
                return (string)$email;
            }

            // Intentar buscar coincidencia por subdominio (*.artworkmockups.com)
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

        // Single-tenant deployments must provide an explicit fallback identity.
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
        return strtolower(trim((string)$envEmail));
    }
}
