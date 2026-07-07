<?php
// app/Support/DatabaseSessionHandler.php
declare(strict_types=1);

class DatabaseSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT payload FROM sessions WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $payload = $stmt->fetchColumn();
            return $payload !== false ? (string)$payload : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $now = time();
            $stmt = $this->pdo->prepare('
                INSERT INTO sessions (id, payload, last_activity)
                VALUES (:id, :payload, :last_activity)
                ON DUPLICATE KEY UPDATE
                    payload = VALUES(payload),
                    last_activity = VALUES(last_activity)
            ');
            return $stmt->execute([
                'id' => $id,
                'payload' => $data,
                'last_activity' => $now
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE id = :id');
            return $stmt->execute(['id' => $id]);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $cutoff = time() - $max_lifetime;
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE last_activity < :cutoff');
            $stmt->execute(['cutoff' => $cutoff]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            return false;
        }
    }
}
