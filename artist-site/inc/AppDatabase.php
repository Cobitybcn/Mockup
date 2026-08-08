<?php
declare(strict_types=1);

function artist_site_database_connection(string $appRoot): PDO
{
    $config = [];
    $envPath = $appRoot . DIRECTORY_SEPARATOR . '.env';
    foreach (@file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $config[$key] = trim($value, "\"'");
    }

    foreach (['DB_HOST', 'DB_PORT', 'DB_SOCKET', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET'] as $key) {
        $runtimeValue = getenv($key);
        if ($runtimeValue !== false && $runtimeValue !== '') {
            $config[$key] = $runtimeValue;
        }
    }

    $socket = trim((string)($config['DB_SOCKET'] ?? ''));
    $database = (string)($config['DB_DATABASE'] ?? 'mockups');
    $charset = (string)($config['DB_CHARSET'] ?? 'utf8mb4');
    if ($socket !== '') {
        $dsn = "mysql:unix_socket={$socket};dbname={$database};charset={$charset}";
    } else {
        $host = (string)($config['DB_HOST'] ?? '127.0.0.1');
        $port = (string)($config['DB_PORT'] ?? '3306');
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
    }

    return new PDO($dsn, (string)($config['DB_USERNAME'] ?? 'root'), (string)($config['DB_PASSWORD'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function artist_site_rate_limit(PDO $pdo, string $action, string $identity, int $limit, int $windowSeconds): bool
{
    return artist_site_rate_limit_key(
        $pdo,
        $action,
        strtolower(trim($identity)) . '|' . artist_site_client_address(),
        $limit,
        $windowSeconds
    );
}

function artist_site_rate_limit_key(PDO $pdo, string $action, string $identityKey, int $limit, int $windowSeconds): bool
{
    $action = substr(preg_replace('/[^a-z0-9_.-]/i', '', strtolower($action)) ?: 'artist_site', 0, 64);
    $identityHash = hash('sha256', $identityKey);
    $limit = max(1, $limit);
    $windowSeconds = max(30, $windowSeconds);
    $now = time();
    $cutoff = $now - $windowSeconds;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql) {
        $sql = 'INSERT INTO auth_rate_limits (action, identity_hash, window_started_at, attempts, updated_at)
            VALUES (:action, :identity_hash, :window_started_at, 1, :updated_at)
            ON DUPLICATE KEY UPDATE
                attempts = IF(window_started_at < :cutoff_attempts, 1, attempts + 1),
                window_started_at = IF(window_started_at < :cutoff_window, :replacement_window, window_started_at),
                updated_at = :replacement_updated_at';
    } else {
        $sql = 'INSERT INTO auth_rate_limits (action, identity_hash, window_started_at, attempts, updated_at)
            VALUES (:action, :identity_hash, :window_started_at, 1, :updated_at)
            ON CONFLICT(action, identity_hash) DO UPDATE SET
                attempts = CASE WHEN window_started_at < :cutoff_attempts THEN 1 ELSE attempts + 1 END,
                window_started_at = CASE WHEN window_started_at < :cutoff_window THEN :replacement_window ELSE window_started_at END,
                updated_at = :replacement_updated_at';
    }
    $pdo->prepare($sql)->execute([
            'action' => $action,
            'identity_hash' => $identityHash,
            'window_started_at' => $now,
            'updated_at' => $now,
            'cutoff_attempts' => $cutoff,
            'cutoff_window' => $cutoff,
            'replacement_window' => $now,
            'replacement_updated_at' => $now,
        ]);

    $select = $pdo->prepare('SELECT window_started_at, attempts FROM auth_rate_limits WHERE action = :action AND identity_hash = :identity_hash');
    $select->execute(['action' => $action, 'identity_hash' => $identityHash]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    return is_array($row)
        && (int)$row['window_started_at'] >= $cutoff
        && (int)$row['attempts'] <= $limit;
}

function artist_site_client_address(): string
{
    if (getenv('K_SERVICE') !== false) {
        $forwarded = array_values(array_filter(array_map(
            static fn(string $address): string => trim($address),
            explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))
        ), static fn(string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false));
        if ($forwarded) {
            // Google Front End appends its address after the client address. Reading
            // from the trusted end avoids accepting a client-prepended spoofed value.
            return $forwarded[max(0, count($forwarded) - 2)];
        }
    }
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}
