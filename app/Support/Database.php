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
        self::$pdo->exec('PRAGMA foreign_keys = ON');

        self::migrate(self::$pdo);

        return self::$pdo;
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
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

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
    }

    private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        $columns = array_map(fn(array $row): string => (string)$row['name'], $stmt->fetchAll());

        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }
}
