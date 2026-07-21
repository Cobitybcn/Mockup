<?php
declare(strict_types=1);

final class AuthRateLimiter
{
    public static function consume(string $action, string $identity, int $limit, int $windowSeconds): bool
    {
        $action = substr(preg_replace('/[^a-z0-9_.-]/i', '', strtolower($action)) ?: 'auth', 0, 64);
        $identityHash = hash('sha256', strtolower(trim($identity)) . '|' . self::clientAddress());
        $limit = max(1, $limit);
        $windowSeconds = max(30, $windowSeconds);
        $now = time();
        $cutoff = $now - $windowSeconds;
        $pdo = Database::connection();

        if (Database::isMysql()) {
            $sql = "INSERT INTO auth_rate_limits (action,identity_hash,window_started_at,attempts,updated_at)
                VALUES (:action,:identity_hash,:window_started_at,1,:updated_at)
                ON DUPLICATE KEY UPDATE
                    attempts=IF(window_started_at < :cutoff_attempts,1,attempts+1),
                    window_started_at=IF(window_started_at < :cutoff_window,:replacement_window,window_started_at),
                    updated_at=:replacement_updated_at";
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
        } else {
            $sql = "INSERT INTO auth_rate_limits (action,identity_hash,window_started_at,attempts,updated_at)
                VALUES (:action,:identity_hash,:window_started_at,1,:updated_at)
                ON CONFLICT(action,identity_hash) DO UPDATE SET
                    attempts=CASE WHEN window_started_at < :cutoff_attempts THEN 1 ELSE attempts+1 END,
                    window_started_at=CASE WHEN window_started_at < :cutoff_window THEN :replacement_window ELSE window_started_at END,
                    updated_at=:replacement_updated_at";
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
        }

        $stmt = $pdo->prepare('SELECT attempts,window_started_at FROM auth_rate_limits WHERE action=? AND identity_hash=?');
        $stmt->execute([$action, $identityHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (random_int(1, 100) === 1) {
            $pdo->prepare('DELETE FROM auth_rate_limits WHERE updated_at < ?')->execute([$now - 86400]);
        }

        return is_array($row)
            && (int)$row['window_started_at'] >= $cutoff
            && (int)$row['attempts'] <= $limit;
    }

    public static function clear(string $action, string $identity): void
    {
        $action = substr(preg_replace('/[^a-z0-9_.-]/i', '', strtolower($action)) ?: 'auth', 0, 64);
        $identityHash = hash('sha256', strtolower(trim($identity)) . '|' . self::clientAddress());
        Database::connection()->prepare('DELETE FROM auth_rate_limits WHERE action = ? AND identity_hash = ?')
            ->execute([$action, $identityHash]);
    }

    private static function clientAddress(): string
    {
        $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
    }
}
