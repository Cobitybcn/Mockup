<?php
declare(strict_types=1);

class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $storageDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';

        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0775, true);
        }

        self::$pdo = new PDO('sqlite:' . $storageDir . DIRECTORY_SEPARATOR . 'app.sqlite');
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$pdo->setAttribute(PDO::ATTR_TIMEOUT, 30);
        self::$pdo->exec('PRAGMA foreign_keys = ON');
        // WAL permite lecturas concurrentes mientras un job escribe (punto #8)
        self::$pdo->exec('PRAGMA journal_mode = WAL');
        self::$pdo->exec('PRAGMA synchronous = NORMAL');
        // Esperar hasta 30 s antes de fallar por database lock (punto #8)
        self::$pdo->exec('PRAGMA busy_timeout = 30000');

        // Run migration to check and add columns as needed.
        self::migrate(self::$pdo);

        return self::$pdo;
    }

    public static function withBusyRetry(callable $callback, int $attempts = 8)
    {
        $lastError = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                return $callback();
            } catch (Throwable $e) {
                if (!self::isBusyError($e) || $attempt === $attempts - 1) {
                    throw $e;
                }

                $lastError = $e;
                $delayMs = min(2500, 150 * (2 ** $attempt)) + random_int(0, 120);
                usleep($delayMs * 1000);
            }
        }

        if ($lastError instanceof Throwable) {
            throw $lastError;
        }

        return null;
    }

    private static function isBusyError(Throwable $e): bool
    {
        if ($e instanceof PDOException) {
            $errorInfo = $e->errorInfo;
            if (($errorInfo[1] ?? null) === 5 || ($errorInfo[1] ?? null) === 6) {
                return true;
            }
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'database is locked') ||
            str_contains($message, 'database table is locked') ||
            str_contains($message, 'database is busy');
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                name TEXT NOT NULL DEFAULT '',
                credits INTEGER NOT NULL DEFAULT 10,
                is_admin INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ");

        self::addColumnIfMissing($pdo, 'users', 'is_admin', 'INTEGER NOT NULL DEFAULT 0');

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS artworks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                job_id TEXT NOT NULL UNIQUE,
                root_file TEXT,
                main_file TEXT,
                final_title TEXT NOT NULL DEFAULT '',
                subtitle TEXT NOT NULL DEFAULT '',
                medium TEXT NOT NULL DEFAULT '',
                artwork_year TEXT NOT NULL DEFAULT '',
                series TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'queued',
                width TEXT,
                height TEXT,
                depth TEXT,
                unit TEXT NOT NULL DEFAULT 'cm',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        self::addColumnIfMissing($pdo, 'artworks', 'final_title', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artworks', 'subtitle', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artworks', 'medium', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artworks', 'artwork_year', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artworks', 'series', "TEXT NOT NULL DEFAULT ''");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mockups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                artwork_file TEXT NOT NULL,
                mockup_file TEXT NOT NULL,
                context_id TEXT,
                prompt_file TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS artist_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL UNIQUE,
                artist_name TEXT NOT NULL DEFAULT '',
                short_bio TEXT NOT NULL DEFAULT '',
                statement TEXT NOT NULL DEFAULT '',
                visual_language TEXT NOT NULL DEFAULT '',
                materials TEXT NOT NULL DEFAULT '',
                recurring_themes TEXT NOT NULL DEFAULT '',
                palette_notes TEXT NOT NULL DEFAULT '',
                target_audience TEXT NOT NULL DEFAULT '',
                preferred_regions TEXT NOT NULL DEFAULT '',
                preferred_contexts TEXT NOT NULL DEFAULT '',
                forbidden_contexts TEXT NOT NULL DEFAULT '',
                commercial_positioning TEXT NOT NULL DEFAULT '',
                conceptual_keywords TEXT NOT NULL DEFAULT '',
                tone_of_voice TEXT NOT NULL DEFAULT '',
                marketplace_strategy TEXT NOT NULL DEFAULT '',
                social_strategy TEXT NOT NULL DEFAULT '',
                pinterest_strategy TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        self::addColumnIfMissing($pdo, 'artist_profiles', 'conceptual_keywords', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artist_profiles', 'tone_of_voice', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artist_profiles', 'marketplace_strategy', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artist_profiles', 'social_strategy', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artist_profiles', 'pinterest_strategy', "TEXT NOT NULL DEFAULT ''");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS credit_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                amount INTEGER NOT NULL,
                reason TEXT NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT '',
                updated_at TEXT NOT NULL
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS artwork_analysis (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                artwork_id INTEGER NOT NULL,
                provider TEXT NOT NULL,
                analysis_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mockup_contexts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                artwork_id INTEGER NOT NULL,
                analysis_id INTEGER NOT NULL,
                context_name TEXT NOT NULL,
                context_json TEXT NOT NULL,
                prompt TEXT NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE,
                FOREIGN KEY (analysis_id) REFERENCES artwork_analysis(id) ON DELETE CASCADE
            )
        ");
    }

    private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        $columns = array_map(fn(array $row): string => (string)$row['name'], $stmt->fetchAll());

        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    /**
     * Descuenta 1 crédito del usuario de forma atómica.
     * Registra la transacción en credit_transactions.
     * Retorna false si no hay créditos suficientes (sin lanzar excepción).
     * Punto #10: sistema de créditos real.
     */
    public static function deductCredit(int $userId, string $reason): bool
    {
        return (bool)self::withBusyRetry(function () use ($userId, $reason): bool {
            $pdo = self::connection();
            $inTransaction = false;

            try {
                // Use IMMEDIATE transaction for SQLite to avoid write upgrade locks/deadlocks (SQLITE_BUSY)
                $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
                $inTransaction = true;

                // Leer créditos con bloqueo implícito de la fila
                $stmt = $pdo->prepare('SELECT credits FROM users WHERE id = :id');
                $stmt->execute(['id' => $userId]);
                $credits = (int)$stmt->fetchColumn();

                if ($credits < 1) {
                    $pdo->exec('ROLLBACK');
                    $inTransaction = false;
                    return false;
                }

                // Descontar crédito
                $pdo->prepare('UPDATE users SET credits = credits - 1, updated_at = :now WHERE id = :id')
                    ->execute(['now' => date('c'), 'id' => $userId]);

                // Registrar transacción
                $pdo->prepare("
                    INSERT INTO credit_transactions (user_id, amount, reason, created_at)
                    VALUES (:user_id, -1, :reason, :created_at)
                ")->execute([
                    'user_id'    => $userId,
                    'reason'     => $reason,
                    'created_at' => date('c'),
                ]);

                $pdo->exec('COMMIT');
                $inTransaction = false;
                return true;
            } catch (Throwable $e) {
                if ($inTransaction) {
                    try {
                        $pdo->exec('ROLLBACK');
                    } catch (Throwable $rollbackErr) {
                        // Ignore rollback exception to propagate original error
                    }
                }
                throw $e;
            }
        }, 12);
    }

    /**
     * Reembolsa 1 crédito al usuario (usado cuando una generación falla tras deducir).
     * Punto #10: reembolso automático en caso de error.
     */
    public static function refundCredit(int $userId, string $reason): void
    {
        self::withBusyRetry(function () use ($userId, $reason): void {
            $pdo = self::connection();
            $inTransaction = false;

            try {
                $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
                $inTransaction = true;

                $pdo->prepare('UPDATE users SET credits = credits + 1, updated_at = :now WHERE id = :id')
                    ->execute(['now' => date('c'), 'id' => $userId]);

                $pdo->prepare("
                    INSERT INTO credit_transactions (user_id, amount, reason, created_at)
                    VALUES (:user_id, 1, :reason, :created_at)
                ")->execute([
                    'user_id'    => $userId,
                    'reason'     => $reason . ' [refund]',
                    'created_at' => date('c'),
                ]);

                $pdo->exec('COMMIT');
                $inTransaction = false;
            } catch (Throwable $e) {
                if ($inTransaction) {
                    try {
                        $pdo->exec('ROLLBACK');
                    } catch (Throwable $rollbackErr) {
                        // Ignore rollback exception to propagate original error
                    }
                }
                throw $e;
            }
        });
    }

    /**
     * Establece los créditos de un usuario a un valor exacto.
     * Registra la diferencia en credit_transactions.
     */
    public static function setCredits(int $userId, int $targetCredits, string $reason): void
    {
        $pdo = self::connection();
        
        $stmt = $pdo->prepare('SELECT credits FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $currentCredits = (int)$stmt->fetchColumn();
        
        $diff = $targetCredits - $currentCredits;
        if ($diff === 0) {
            return;
        }

        $pdo->prepare('UPDATE users SET credits = :credits, updated_at = :now WHERE id = :id')
            ->execute([
                'credits' => $targetCredits,
                'now' => date('c'),
                'id' => $userId
            ]);

        $pdo->prepare("
            INSERT INTO credit_transactions (user_id, amount, reason, created_at)
            VALUES (:user_id, :amount, :reason, :created_at)
        ")->execute([
            'user_id'    => $userId,
            'amount'     => $diff,
            'reason'     => $reason,
            'created_at' => date('c'),
        ]);
    }
}
