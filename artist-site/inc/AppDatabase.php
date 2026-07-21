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
    $now = time();
    $identityHash = hash('sha256', strtolower(trim($identity)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $pdo->beginTransaction();
    try {
        $select = $pdo->prepare('SELECT window_started_at, attempts FROM auth_rate_limits WHERE action = :action AND identity_hash = :identity_hash FOR UPDATE');
        $select->execute(['action' => $action, 'identity_hash' => $identityHash]);
        $row = $select->fetch();
        $windowStartedAt = (int)($row['window_started_at'] ?? $now);
        $attempts = (int)($row['attempts'] ?? 0);

        if (!$row || ($now - $windowStartedAt) >= $windowSeconds) {
            $windowStartedAt = $now;
            $attempts = 1;
        } else {
            $attempts++;
        }

        $write = $pdo->prepare('INSERT INTO auth_rate_limits (action, identity_hash, window_started_at, attempts, updated_at)
            VALUES (:action, :identity_hash, :window_started_at, :attempts, :updated_at)
            ON DUPLICATE KEY UPDATE window_started_at = VALUES(window_started_at), attempts = VALUES(attempts), updated_at = VALUES(updated_at)');
        $write->execute([
            'action' => $action,
            'identity_hash' => $identityHash,
            'window_started_at' => $windowStartedAt,
            'attempts' => $attempts,
            'updated_at' => $now,
        ]);
        $pdo->commit();
        return $attempts <= $limit;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
