<?php
// app/Support/DatabaseSessionHandler.php
declare(strict_types=1);

class DatabaseSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function open($savePath, $sessionName): bool
    {
        try {
            // Keep it SQLite and MySQL compatible by not using database-specific syntax
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS php_sessions (
                    id VARCHAR(255) NOT NULL PRIMARY KEY,
                    data TEXT NOT NULL,
                    updated_at INT NOT NULL
                )
            ");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string
    {
        try {
            $stmt = $this->pdo->prepare("SELECT data FROM php_sessions WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $data = $stmt->fetchColumn();
            return is_string($data) ? $data : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    public function write($id, $data): bool
    {
        try {
            // REPLACE INTO is standard and works in both MySQL and SQLite
            $stmt = $this->pdo->prepare("
                REPLACE INTO php_sessions (id, data, updated_at)
                VALUES (:id, :data, :updated_at)
            ");
            return $stmt->execute([
                'id' => $id,
                'data' => $data,
                'updated_at' => time()
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function destroy($id): bool
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM php_sessions WHERE id = :id");
            return $stmt->execute(['id' => $id]);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function gc($maxlifetime): int|false
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM php_sessions WHERE updated_at < :time");
            $stmt->execute(['time' => time() - $maxlifetime]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
