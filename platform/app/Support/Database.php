<?php
declare(strict_types=1);

class Database
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        self::$driver = self::configuredDriver();
        self::$pdo = self::$driver === 'mysql'
            ? self::createMysqlConnection()
            : self::createSqliteConnection();

        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        self::migrate(self::$pdo);

        return self::$pdo;
    }

    public static function driver(): string
    {
        if (self::$pdo instanceof PDO) {
            return self::$driver;
        }

        return self::configuredDriver();
    }

    public static function isMysql(): bool
    {
        return self::driver() === 'mysql';
    }

    public static function randomOrderSql(): string
    {
        return self::isMysql() ? 'RAND()' : 'RANDOM()';
    }

    public static function dateOrderSql(string $column, string $direction = 'DESC'): string
    {
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        return self::isMysql() ? "{$column} {$direction}" : "datetime({$column}) {$direction}";
    }

    public static function appSettingUpsertSql(): string
    {
        if (self::isMysql()) {
            return '
                INSERT INTO app_settings (`key`, value, updated_at)
                VALUES (:key, :value, :updated_at)
                ON DUPLICATE KEY UPDATE
                    value = VALUES(value),
                    updated_at = VALUES(updated_at)
            ';
        }

        return '
            INSERT INTO app_settings (key, value, updated_at)
            VALUES (:key, :value, :updated_at)
            ON CONFLICT(key) DO UPDATE SET
                value = excluded.value,
                updated_at = excluded.updated_at
        ';
    }

    public static function beginWriteTransaction(PDO $pdo): void
    {
        if (self::isMysql()) {
            $pdo->beginTransaction();
            return;
        }

        $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
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

    private static function configuredDriver(): string
    {
        $driver = strtolower(trim(app_env('DB_CONNECTION', 'sqlite')));
        return $driver === 'mysql' ? 'mysql' : 'sqlite';
    }

    private static function createSqliteConnection(): PDO
    {
        $storageDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';

        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $storageDir . DIRECTORY_SEPARATOR . 'app.sqlite');
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 30);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000'); // Reducido de 30000ms a 5000ms (fallos rápidos)
        $pdo->exec('PRAGMA wal_autocheckpoint = 5000'); // 5MB antes de checkpoint
        $pdo->exec('PRAGMA cache_size = -64000'); // 64MB cache

        return $pdo;
    }

    private static function createMysqlConnection(): PDO
    {
        $host = app_env('DB_HOST', '127.0.0.1');
        $port = app_env('DB_PORT', '3306');
        $socket = app_env('DB_SOCKET', '');
        $database = app_env('DB_DATABASE', 'mockups');
        $username = app_env('DB_USERNAME', 'root');
        $password = app_env('DB_PASSWORD', '');
        $charset = app_env('DB_CHARSET', 'utf8mb4');

        $serverDsn = self::mysqlDsn('', $charset, $host, $port, $socket);
        $server = new PDO($serverDsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $server->exec(
            'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $database) . '` ' .
            "CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci"
        );

        $dsn = self::mysqlDsn($database, $charset, $host, $port, $socket);
        return new PDO($dsn, $username, $password, [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
        ]);
    }

    private static function mysqlDsn(string $database, string $charset, string $host, string $port, string $socket): string
    {
        $params = [];
        if ($socket !== '') {
            $params[] = 'unix_socket=' . $socket;
        } else {
            $params[] = 'host=' . $host;
            $params[] = 'port=' . $port;
        }

        if ($database !== '') {
            $params[] = 'dbname=' . $database;
        }

        $params[] = 'charset=' . $charset;

        return 'mysql:' . implode(';', $params);
    }

    private static function isBusyError(Throwable $e): bool
    {
        if ($e instanceof PDOException) {
            $errorInfo = $e->errorInfo;
            $vendorCode = (int)($errorInfo[1] ?? 0);
            if (in_array($vendorCode, [5, 6, 1205, 1213], true)) {
                return true;
            }
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'database is locked') ||
            str_contains($message, 'database table is locked') ||
            str_contains($message, 'database is busy') ||
            str_contains($message, 'lock wait timeout') ||
            str_contains($message, 'deadlock');
    }

    private static function migrate(PDO $pdo): void
    {
        if (self::isMysql()) {
            self::migrateMysql($pdo);
        } else {
            self::migrateSqlite($pdo);
        }
        self::migratePinterest($pdo);
        self::migrateMeta($pdo);
        self::migrateInstagram($pdo);
        require_once __DIR__ . '/../Assistant/AssistantSchema.php';
        AssistantSchema::migrate($pdo);
    }

    private static function migrateSqlite(PDO $pdo): void
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
        self::addColumnIfMissing($pdo, 'artworks', 'artwork_group_id', "INTEGER");
        self::addColumnIfMissing($pdo, 'artworks', 'root_view_type', "TEXT NOT NULL DEFAULT 'unknown'");
        self::addColumnIfMissing($pdo, 'artworks', 'root_view_status', "TEXT NOT NULL DEFAULT 'variant'");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mockups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                artwork_file TEXT NOT NULL,
                mockup_file TEXT NOT NULL,
                context_id TEXT,
                prompt_file TEXT,
                selector_state_json TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        self::addColumnIfMissing($pdo, 'mockups', 'selector_state_json', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'mockups', 'artwork_group_id', "INTEGER");
        self::addColumnIfMissing($pdo, 'mockups', 'source_artwork_id', "INTEGER");

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
        self::addColumnIfMissing($pdo, 'artist_profiles', 'photo_file', "TEXT NOT NULL DEFAULT ''");

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

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mockup_generation_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                artwork_id INTEGER NOT NULL,
                artwork_file TEXT NOT NULL,
                context_id TEXT NOT NULL,
                prompt TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'queued',
                mockup_id INTEGER,
                mockup_file TEXT,
                prompt_file TEXT,
                error TEXT,
                attempts INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            )
        ");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mockup_generation_jobs_artwork ON mockup_generation_jobs (artwork_id, status)");
        self::addColumnIfMissing($pdo, 'mockup_generation_jobs', 'artwork_group_id', "INTEGER");
        self::addColumnIfMissing($pdo, 'mockup_generation_jobs', 'source_artwork_id', "INTEGER");
        self::addColumnIfMissing($pdo, 'mockup_generation_jobs', 'selector_state_json', "TEXT NULL");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mockup_generation_jobs_context ON mockup_generation_jobs (artwork_id, context_id)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS artwork_sheets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                canonical_artwork_id INTEGER NOT NULL,
                related_artwork_ids TEXT NOT NULL DEFAULT '',
                source_image_file TEXT NOT NULL DEFAULT '',
                user_notes TEXT NOT NULL DEFAULT '',
                title TEXT NOT NULL DEFAULT '',
                subtitle TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                short_description TEXT NOT NULL DEFAULT '',
                keywords TEXT NOT NULL DEFAULT '',
                tags TEXT NOT NULL DEFAULT '',
                alt_text TEXT NOT NULL DEFAULT '',
                caption TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'draft',
                generated_json TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (canonical_artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_artwork_sheets_canonical ON artwork_sheets (canonical_artwork_id)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS artwork_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                canonical_artwork_id INTEGER NOT NULL,
                official_root_artwork_ids TEXT NOT NULL DEFAULT '',
                title TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'active',
                merged_into_group_id INTEGER,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (canonical_artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            )
        ");
        self::addColumnIfMissing($pdo, 'artwork_groups', 'merged_into_group_id', "INTEGER");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_artwork_groups_user ON artwork_groups (user_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_artwork_groups_canonical ON artwork_groups (canonical_artwork_id)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mockup_sheets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                artwork_sheet_id INTEGER,
                artwork_id INTEGER NOT NULL,
                mockup_id INTEGER,
                mockup_file TEXT NOT NULL,
                user_notes TEXT NOT NULL DEFAULT '',
                title TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                keywords TEXT NOT NULL DEFAULT '',
                tags TEXT NOT NULL DEFAULT '',
                alt_text TEXT NOT NULL DEFAULT '',
                caption TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'draft',
                generated_json TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mockup_sheets_artwork ON mockup_sheets (artwork_id, mockup_file)");
        self::addColumnIfMissing($pdo, 'mockup_sheets', 'artwork_group_id', "INTEGER");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS artwork_embeddings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                artwork_id INTEGER NOT NULL UNIQUE,
                source_file TEXT NOT NULL,
                model TEXT NOT NULL DEFAULT 'multimodalembedding@001',
                embedding_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS root_artwork_candidates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                artwork_id INTEGER NOT NULL,
                file_name TEXT NOT NULL,
                view_type TEXT NOT NULL DEFAULT 'frontal',
                is_selected INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_root_artwork_candidates_artwork ON root_artwork_candidates (artwork_id, view_type)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                payload TEXT NOT NULL,
                last_activity INTEGER NOT NULL
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_resets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                token_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_token ON password_resets (token_hash)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets (user_id, used_at)");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS contact_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                subject TEXT NOT NULL,
                message TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'new',
                created_at TEXT NOT NULL
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_contact_status_created ON contact_messages (status, created_at)");
    }

    private static function migrateMysql(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL DEFAULT '',
                credits INT NOT NULL DEFAULT 10,
                is_admin TINYINT(1) NOT NULL DEFAULT 0,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY users_email_unique (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::addColumnIfMissing($pdo, 'users', 'is_admin', 'TINYINT(1) NOT NULL DEFAULT 0');

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS artworks (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                job_id VARCHAR(255) NOT NULL,
                root_file VARCHAR(255) NULL,
                main_file VARCHAR(255) NULL,
                final_title VARCHAR(255) NOT NULL DEFAULT '',
                subtitle VARCHAR(255) NOT NULL DEFAULT '',
                medium VARCHAR(255) NOT NULL DEFAULT '',
                artwork_year VARCHAR(80) NOT NULL DEFAULT '',
                series VARCHAR(255) NOT NULL DEFAULT '',
                status VARCHAR(80) NOT NULL DEFAULT 'queued',
                width VARCHAR(80) NULL,
                height VARCHAR(80) NULL,
                depth VARCHAR(80) NULL,
                unit VARCHAR(20) NOT NULL DEFAULT 'cm',
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY artworks_job_id_unique (job_id),
                KEY artworks_user_status_idx (user_id, status),
                CONSTRAINT artworks_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::addColumnIfMissing($pdo, 'artworks', 'final_title', "VARCHAR(255) NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artworks', 'subtitle', "VARCHAR(255) NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artworks', 'medium', "VARCHAR(255) NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artworks', 'artwork_year', "VARCHAR(80) NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artworks', 'series', "VARCHAR(255) NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artworks', 'artwork_group_id', "INT UNSIGNED NULL");
        self::addColumnIfMissing($pdo, 'artworks', 'root_view_type', "VARCHAR(80) NOT NULL DEFAULT 'unknown'");
        self::addColumnIfMissing($pdo, 'artworks', 'root_view_status', "VARCHAR(40) NOT NULL DEFAULT 'variant'");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mockups (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                artwork_file VARCHAR(255) NOT NULL,
                mockup_file VARCHAR(255) NOT NULL,
                context_id VARCHAR(80) NULL,
                prompt_file VARCHAR(255) NULL,
                selector_state_json MEDIUMTEXT NULL,
                created_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY mockups_user_artwork_idx (user_id, artwork_file),
                CONSTRAINT mockups_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::addColumnIfMissing($pdo, 'mockups', 'selector_state_json', "MEDIUMTEXT NOT NULL");
        self::makeMysqlColumnNullableIfNeeded($pdo, 'mockups', 'selector_state_json', 'MEDIUMTEXT');
        self::addColumnIfMissing($pdo, 'mockups', 'artwork_group_id', "INT UNSIGNED NULL");
        self::addColumnIfMissing($pdo, 'mockups', 'source_artwork_id', "INT UNSIGNED NULL");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS artist_profiles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                artist_name VARCHAR(255) NOT NULL DEFAULT '',
                short_bio TEXT NOT NULL,
                statement TEXT NOT NULL,
                visual_language TEXT NOT NULL,
                materials TEXT NOT NULL,
                recurring_themes TEXT NOT NULL,
                palette_notes TEXT NOT NULL,
                target_audience TEXT NOT NULL,
                preferred_regions TEXT NOT NULL,
                preferred_contexts TEXT NOT NULL,
                forbidden_contexts TEXT NOT NULL,
                commercial_positioning TEXT NOT NULL,
                conceptual_keywords TEXT NOT NULL,
                tone_of_voice TEXT NOT NULL,
                marketplace_strategy TEXT NOT NULL,
                social_strategy TEXT NOT NULL,
                pinterest_strategy TEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY artist_profiles_user_unique (user_id),
                CONSTRAINT artist_profiles_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::addColumnIfMissing($pdo, 'artist_profiles', 'conceptual_keywords', "TEXT NOT NULL");
        self::addColumnIfMissing($pdo, 'artist_profiles', 'tone_of_voice', "TEXT NOT NULL");
        self::addColumnIfMissing($pdo, 'artist_profiles', 'marketplace_strategy', "TEXT NOT NULL");
        self::addColumnIfMissing($pdo, 'artist_profiles', 'social_strategy', "TEXT NOT NULL");
        self::addColumnIfMissing($pdo, 'artist_profiles', 'pinterest_strategy', "TEXT NOT NULL");
        self::addColumnIfMissing($pdo, 'artist_profiles', 'photo_file', "VARCHAR(255) NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artist_profiles', 'subdomain', "VARCHAR(190) NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artist_profiles', 'custom_domain', "VARCHAR(190) NOT NULL DEFAULT ''");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS credit_transactions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                amount INT NOT NULL,
                reason VARCHAR(255) NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY credit_transactions_user_idx (user_id),
                CONSTRAINT credit_transactions_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_settings (
                `key` VARCHAR(190) NOT NULL,
                value MEDIUMTEXT NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS artwork_analysis (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                artwork_id INT UNSIGNED NOT NULL,
                provider VARCHAR(80) NOT NULL,
                analysis_json MEDIUMTEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY artwork_analysis_artwork_idx (artwork_id),
                CONSTRAINT artwork_analysis_artwork_fk FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mockup_contexts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                artwork_id INT UNSIGNED NOT NULL,
                analysis_id INT UNSIGNED NOT NULL,
                context_name VARCHAR(255) NOT NULL,
                context_json MEDIUMTEXT NOT NULL,
                prompt MEDIUMTEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY mockup_contexts_artwork_idx (artwork_id),
                KEY mockup_contexts_analysis_idx (analysis_id),
                CONSTRAINT mockup_contexts_artwork_fk FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE,
                CONSTRAINT mockup_contexts_analysis_fk FOREIGN KEY (analysis_id) REFERENCES artwork_analysis(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mockup_generation_jobs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                artwork_id INT UNSIGNED NOT NULL,
                artwork_file VARCHAR(255) NOT NULL,
                context_id VARCHAR(80) NOT NULL,
                prompt MEDIUMTEXT NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'queued',
                mockup_id INT UNSIGNED NULL,
                mockup_file VARCHAR(255) NULL,
                prompt_file VARCHAR(255) NULL,
                error TEXT NULL,
                attempts INT NOT NULL DEFAULT 0,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_mockup_generation_jobs_artwork (artwork_id, status),
                KEY idx_mockup_generation_jobs_context (artwork_id, context_id),
                KEY mockup_generation_jobs_user_idx (user_id),
                CONSTRAINT mockup_generation_jobs_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT mockup_generation_jobs_artwork_fk FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::addColumnIfMissing($pdo, 'mockup_generation_jobs', 'artwork_group_id', "INT UNSIGNED NULL");
        self::addColumnIfMissing($pdo, 'mockup_generation_jobs', 'source_artwork_id', "INT UNSIGNED NULL");
        self::addColumnIfMissing($pdo, 'mockup_generation_jobs', 'selector_state_json', "MEDIUMTEXT NULL");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS artwork_sheets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                canonical_artwork_id INT UNSIGNED NOT NULL,
                related_artwork_ids MEDIUMTEXT NOT NULL,
                source_image_file VARCHAR(255) NOT NULL DEFAULT '',
                user_notes MEDIUMTEXT NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                subtitle VARCHAR(255) NOT NULL DEFAULT '',
                description MEDIUMTEXT NOT NULL,
                short_description TEXT NOT NULL,
                keywords MEDIUMTEXT NOT NULL,
                tags MEDIUMTEXT NOT NULL,
                alt_text TEXT NOT NULL,
                caption TEXT NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'draft',
                generated_json MEDIUMTEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_artwork_sheets_canonical (canonical_artwork_id),
                KEY idx_artwork_sheets_user (user_id),
                CONSTRAINT artwork_sheets_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT artwork_sheets_artwork_fk FOREIGN KEY (canonical_artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS artwork_groups (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                canonical_artwork_id INT UNSIGNED NOT NULL,
                official_root_artwork_ids MEDIUMTEXT NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                status VARCHAR(40) NOT NULL DEFAULT 'active',
                merged_into_group_id INT UNSIGNED NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_artwork_groups_user (user_id),
                KEY idx_artwork_groups_canonical (canonical_artwork_id),
                CONSTRAINT artwork_groups_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT artwork_groups_artwork_fk FOREIGN KEY (canonical_artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::addColumnIfMissing($pdo, 'artwork_groups', 'merged_into_group_id', "INT UNSIGNED NULL");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mockup_sheets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                artwork_sheet_id INT UNSIGNED NULL,
                artwork_id INT UNSIGNED NOT NULL,
                mockup_id INT UNSIGNED NULL,
                mockup_file VARCHAR(255) NOT NULL,
                user_notes MEDIUMTEXT NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                description MEDIUMTEXT NOT NULL,
                keywords MEDIUMTEXT NOT NULL,
                tags MEDIUMTEXT NOT NULL,
                alt_text TEXT NOT NULL,
                caption TEXT NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'draft',
                generated_json MEDIUMTEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_mockup_sheets_artwork (artwork_id, mockup_file),
                KEY idx_mockup_sheets_user (user_id),
                CONSTRAINT mockup_sheets_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT mockup_sheets_artwork_fk FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS artwork_embeddings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                artwork_id INT UNSIGNED NOT NULL,
                source_file VARCHAR(255) NOT NULL,
                model VARCHAR(80) NOT NULL DEFAULT 'multimodalembedding@001',
                embedding_json MEDIUMTEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY artwork_embeddings_artwork_unique (artwork_id),
                CONSTRAINT artwork_embeddings_artwork_fk FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::addColumnIfMissing($pdo, 'mockup_sheets', 'artwork_group_id', "INT UNSIGNED NULL");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS root_artwork_candidates (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                artwork_id INT UNSIGNED NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                view_type VARCHAR(80) NOT NULL DEFAULT 'frontal',
                is_selected TINYINT(1) NOT NULL DEFAULT 0,
                created_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_root_artwork_candidates_artwork (artwork_id, view_type),
                CONSTRAINT root_artwork_candidates_artwork_fk FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sessions (
                id VARCHAR(190) NOT NULL,
                payload MEDIUMTEXT NOT NULL,
                last_activity INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                KEY idx_sessions_activity (last_activity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_resets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                email VARCHAR(255) NOT NULL,
                token_hash VARCHAR(255) NOT NULL,
                expires_at VARCHAR(40) NOT NULL,
                used_at VARCHAR(40) NULL,
                created_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_password_resets_token (token_hash),
                KEY idx_password_resets_user (user_id, used_at),
                CONSTRAINT password_resets_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS contact_messages (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(254) NOT NULL,
                subject VARCHAR(80) NOT NULL,
                message MEDIUMTEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'new',
                created_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_contact_status_created (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private static function migratePinterest(PDO $pdo): void
    {
        if (self::isMysql()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pinterest_connections (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                purpose VARCHAR(20) NOT NULL DEFAULT 'artist',
                pinterest_account_id VARCHAR(190) NOT NULL,
                access_token_encrypted MEDIUMTEXT NULL,
                refresh_token_encrypted MEDIUMTEXT NULL,
                access_token_expires_at VARCHAR(40) NULL,
                refresh_token_expires_at VARCHAR(40) NULL,
                scopes TEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                connected_at VARCHAR(40) NULL,
                disconnected_at VARCHAR(40) NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                UNIQUE KEY uq_pinterest_connections_user_purpose (user_id,purpose),
                CONSTRAINT pinterest_connections_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS pinterest_pin_drafts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                mockup_id INT UNSIGNED NOT NULL,
                purpose VARCHAR(20) NOT NULL DEFAULT 'artist',
                board_suggestion VARCHAR(255) NOT NULL DEFAULT '',
                board_id VARCHAR(190) NOT NULL DEFAULT '',
                board_name VARCHAR(255) NOT NULL DEFAULT '',
                board_section_id VARCHAR(190) NOT NULL DEFAULT '',
                board_section_name VARCHAR(255) NOT NULL DEFAULT '',
                title VARCHAR(100) NOT NULL DEFAULT '',
                description VARCHAR(500) NOT NULL DEFAULT '',
                alt_text VARCHAR(500) NOT NULL DEFAULT '',
                keywords MEDIUMTEXT NOT NULL,
                hashtags MEDIUMTEXT NOT NULL,
                destination_url MEDIUMTEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                payload_json MEDIUMTEXT NOT NULL,
                media_token VARCHAR(64) NOT NULL DEFAULT '',
                external_id VARCHAR(255) NOT NULL DEFAULT '',
                external_url MEDIUMTEXT NOT NULL,
                error MEDIUMTEXT NOT NULL,
                crop_x DECIMAL(6,5) NOT NULL DEFAULT 0.5,
                crop_y DECIMAL(6,5) NOT NULL DEFAULT 0.5,
                crop_zoom DECIMAL(6,3) NOT NULL DEFAULT 1,
                variant_file VARCHAR(255) NOT NULL DEFAULT '',
                variant_width INT NOT NULL DEFAULT 0,
                variant_height INT NOT NULL DEFAULT 0,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                KEY idx_pin_drafts_user_status (user_id,status),
                CONSTRAINT pin_drafts_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT pin_drafts_mockup_fk FOREIGN KEY (mockup_id) REFERENCES mockups(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS pinterest_batches (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,purpose VARCHAR(20) NOT NULL,
                destination_url MEDIUMTEXT NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'draft',created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
                KEY idx_pinterest_batches_user_status (user_id,status),CONSTRAINT pinterest_batches_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS pinterest_batch_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,batch_id INT UNSIGNED NOT NULL,draft_id INT UNSIGNED NOT NULL,position INT NOT NULL DEFAULT 0,status VARCHAR(30) NOT NULL DEFAULT 'draft',
                UNIQUE KEY uq_pinterest_batch_draft (batch_id,draft_id),CONSTRAINT pinterest_batch_items_batch_fk FOREIGN KEY(batch_id) REFERENCES pinterest_batches(id) ON DELETE CASCADE,
                CONSTRAINT pinterest_batch_items_draft_fk FOREIGN KEY(draft_id) REFERENCES pinterest_pin_drafts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS pinterest_pin_destinations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,draft_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,mockup_id INT UNSIGNED NOT NULL,purpose VARCHAR(20) NOT NULL,
                board_id VARCHAR(190) NOT NULL,board_name VARCHAR(255) NOT NULL DEFAULT '',status VARCHAR(30) NOT NULL DEFAULT 'selected',external_id VARCHAR(255) NOT NULL DEFAULT '',
                external_url MEDIUMTEXT NOT NULL,error MEDIUMTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
                UNIQUE KEY uq_pinterest_destination_draft_board (draft_id,board_id),KEY idx_pinterest_destination_history (user_id,mockup_id,purpose,board_id,status),
                CONSTRAINT pinterest_destinations_draft_fk FOREIGN KEY(draft_id) REFERENCES pinterest_pin_drafts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS pinterest_connections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,purpose TEXT NOT NULL DEFAULT 'artist',
            pinterest_account_id TEXT NOT NULL,access_token_encrypted TEXT,refresh_token_encrypted TEXT,
            access_token_expires_at TEXT,refresh_token_expires_at TEXT,scopes TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'pending',
            connected_at TEXT,disconnected_at TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,UNIQUE(user_id,purpose)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS pinterest_pin_drafts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,mockup_id INTEGER NOT NULL,
            purpose TEXT NOT NULL DEFAULT 'artist',board_suggestion TEXT NOT NULL DEFAULT '',board_id TEXT NOT NULL DEFAULT '',
            board_name TEXT NOT NULL DEFAULT '',board_section_id TEXT NOT NULL DEFAULT '',board_section_name TEXT NOT NULL DEFAULT '',
            title TEXT NOT NULL DEFAULT '',description TEXT NOT NULL DEFAULT '',alt_text TEXT NOT NULL DEFAULT '',keywords TEXT NOT NULL DEFAULT '',
            hashtags TEXT NOT NULL DEFAULT '',destination_url TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'draft',payload_json TEXT NOT NULL DEFAULT '',
            media_token TEXT NOT NULL DEFAULT '',external_id TEXT NOT NULL DEFAULT '',external_url TEXT NOT NULL DEFAULT '',error TEXT NOT NULL DEFAULT '',
            crop_x REAL NOT NULL DEFAULT 0.5,crop_y REAL NOT NULL DEFAULT 0.5,crop_zoom REAL NOT NULL DEFAULT 1,
            variant_file TEXT NOT NULL DEFAULT '',variant_width INTEGER NOT NULL DEFAULT 0,variant_height INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(mockup_id) REFERENCES mockups(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pin_drafts_user_status ON pinterest_pin_drafts(user_id,status)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS pinterest_batches (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,purpose TEXT NOT NULL,destination_url TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'draft',created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS pinterest_batch_items (id INTEGER PRIMARY KEY AUTOINCREMENT,batch_id INTEGER NOT NULL,draft_id INTEGER NOT NULL,position INTEGER NOT NULL DEFAULT 0,status TEXT NOT NULL DEFAULT 'draft',UNIQUE(batch_id,draft_id),FOREIGN KEY(batch_id) REFERENCES pinterest_batches(id) ON DELETE CASCADE,FOREIGN KEY(draft_id) REFERENCES pinterest_pin_drafts(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS pinterest_pin_destinations (id INTEGER PRIMARY KEY AUTOINCREMENT,draft_id INTEGER NOT NULL,user_id INTEGER NOT NULL,mockup_id INTEGER NOT NULL,purpose TEXT NOT NULL,board_id TEXT NOT NULL,board_name TEXT NOT NULL DEFAULT '',status TEXT NOT NULL DEFAULT 'selected',external_id TEXT NOT NULL DEFAULT '',external_url TEXT NOT NULL DEFAULT '',error TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,updated_at TEXT NOT NULL,UNIQUE(draft_id,board_id),FOREIGN KEY(draft_id) REFERENCES pinterest_pin_drafts(id) ON DELETE CASCADE)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pinterest_destination_history ON pinterest_pin_destinations(user_id,mockup_id,purpose,board_id,status)');
    }

    private static function migrateMeta(PDO $pdo): void
    {
        if (self::isMysql()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS meta_connections (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                purpose VARCHAR(20) NOT NULL DEFAULT 'artist',
                meta_user_id VARCHAR(190) NOT NULL DEFAULT '',
                meta_user_name VARCHAR(255) NOT NULL DEFAULT '',
                user_access_token_encrypted MEDIUMTEXT NULL,
                token_expires_at VARCHAR(40) NULL,
                page_id VARCHAR(190) NOT NULL DEFAULT '',
                page_name VARCHAR(255) NOT NULL DEFAULT '',
                page_access_token_encrypted MEDIUMTEXT NULL,
                instagram_account_id VARCHAR(190) NOT NULL DEFAULT '',
                instagram_username VARCHAR(255) NOT NULL DEFAULT '',
                scopes TEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                connected_at VARCHAR(40) NULL,
                disconnected_at VARCHAR(40) NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                UNIQUE KEY uq_meta_connections_user_purpose (user_id,purpose),
                CONSTRAINT meta_connections_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS social_channel_drafts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                mockup_id INT UNSIGNED NOT NULL,
                channel VARCHAR(24) NOT NULL,
                purpose VARCHAR(20) NOT NULL DEFAULT 'artist',
                title MEDIUMTEXT NOT NULL,
                description MEDIUMTEXT NOT NULL,
                hashtags MEDIUMTEXT NOT NULL,
                alt_text MEDIUMTEXT NOT NULL,
                destination_url MEDIUMTEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                payload_json MEDIUMTEXT NOT NULL,
                media_token VARCHAR(64) NOT NULL DEFAULT '',
                media_expires_at VARCHAR(40) NULL,
                variant_file VARCHAR(255) NOT NULL DEFAULT '',
                variant_width INT NOT NULL DEFAULT 0,
                variant_height INT NOT NULL DEFAULT 0,
                crop_x DECIMAL(6,5) NOT NULL DEFAULT 0.5,
                crop_y DECIMAL(6,5) NOT NULL DEFAULT 0.5,
                crop_zoom DECIMAL(6,3) NOT NULL DEFAULT 1,
                publish_attempt_id VARCHAR(64) NOT NULL DEFAULT '',
                external_id VARCHAR(255) NOT NULL DEFAULT '',
                external_url MEDIUMTEXT NOT NULL,
                error MEDIUMTEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                KEY idx_social_channel_drafts_user_status (user_id,channel,status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS meta_batches (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                purpose VARCHAR(20) NOT NULL DEFAULT 'artist',
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                KEY idx_meta_batches_user_status (user_id,status),
                CONSTRAINT meta_batches_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS meta_batch_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                batch_id INT UNSIGNED NOT NULL,
                draft_id INT UNSIGNED NOT NULL,
                position INT NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                UNIQUE KEY uq_meta_batch_draft (batch_id,draft_id),
                CONSTRAINT meta_batch_items_batch_fk FOREIGN KEY(batch_id) REFERENCES meta_batches(id) ON DELETE CASCADE,
                CONSTRAINT meta_batch_items_draft_fk FOREIGN KEY(draft_id) REFERENCES social_channel_drafts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS meta_connections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,purpose TEXT NOT NULL DEFAULT 'artist',
                meta_user_id TEXT NOT NULL DEFAULT '',meta_user_name TEXT NOT NULL DEFAULT '',user_access_token_encrypted TEXT,
                token_expires_at TEXT,page_id TEXT NOT NULL DEFAULT '',page_name TEXT NOT NULL DEFAULT '',page_access_token_encrypted TEXT,
                instagram_account_id TEXT NOT NULL DEFAULT '',instagram_username TEXT NOT NULL DEFAULT '',scopes TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'pending',connected_at TEXT,disconnected_at TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,UNIQUE(user_id,purpose)
            )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS social_channel_drafts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,mockup_id INTEGER NOT NULL,
                channel TEXT NOT NULL,purpose TEXT NOT NULL DEFAULT 'artist',title TEXT NOT NULL,description TEXT NOT NULL,
                hashtags TEXT NOT NULL,alt_text TEXT NOT NULL DEFAULT '',destination_url TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'draft',
                payload_json TEXT NOT NULL,media_token TEXT NOT NULL DEFAULT '',media_expires_at TEXT,variant_file TEXT NOT NULL DEFAULT '',
                variant_width INTEGER NOT NULL DEFAULT 0,variant_height INTEGER NOT NULL DEFAULT 0,crop_x REAL NOT NULL DEFAULT 0.5,
                crop_y REAL NOT NULL DEFAULT 0.5,crop_zoom REAL NOT NULL DEFAULT 1,publish_attempt_id TEXT NOT NULL DEFAULT '',
                external_id TEXT NOT NULL DEFAULT '',external_url TEXT NOT NULL DEFAULT '',error TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,updated_at TEXT NOT NULL
            )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_social_channel_drafts_user_status ON social_channel_drafts(user_id,channel,status)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS meta_batches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,purpose TEXT NOT NULL DEFAULT 'artist',
                status TEXT NOT NULL DEFAULT 'draft',created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS meta_batch_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,batch_id INTEGER NOT NULL,draft_id INTEGER NOT NULL,
                position INTEGER NOT NULL DEFAULT 0,status TEXT NOT NULL DEFAULT 'draft',UNIQUE(batch_id,draft_id),
                FOREIGN KEY(batch_id) REFERENCES meta_batches(id) ON DELETE CASCADE,
                FOREIGN KEY(draft_id) REFERENCES social_channel_drafts(id) ON DELETE CASCADE
            )");
        }

        $text = self::isMysql() ? 'MEDIUMTEXT NOT NULL' : "TEXT NOT NULL DEFAULT ''";
        $varchar20 = self::isMysql() ? "VARCHAR(20) NOT NULL DEFAULT 'artist'" : "TEXT NOT NULL DEFAULT 'artist'";
        $varchar40Nullable = self::isMysql() ? 'VARCHAR(40) NULL' : 'TEXT';
        $varchar64 = self::isMysql() ? "VARCHAR(64) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
        $varchar255 = self::isMysql() ? "VARCHAR(255) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
        $integer = self::isMysql() ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
        $decimal = self::isMysql() ? 'DECIMAL(6,5) NOT NULL DEFAULT 0.5' : 'REAL NOT NULL DEFAULT 0.5';
        $zoom = self::isMysql() ? 'DECIMAL(6,3) NOT NULL DEFAULT 1' : 'REAL NOT NULL DEFAULT 1';
        self::addColumnIfMissing($pdo, 'social_channel_drafts', 'purpose', $varchar20);
        self::addColumnIfMissing($pdo, 'social_channel_drafts', 'alt_text', $text);
        self::addColumnIfMissing($pdo, 'social_channel_drafts', 'media_token', $varchar64);
        self::addColumnIfMissing($pdo, 'social_channel_drafts', 'media_expires_at', $varchar40Nullable);
        self::addColumnIfMissing($pdo, 'social_channel_drafts', 'variant_file', $varchar255);
        self::addColumnIfMissing($pdo, 'social_channel_drafts', 'variant_width', $integer);
        self::addColumnIfMissing($pdo, 'social_channel_drafts', 'variant_height', $integer);
        self::addColumnIfMissing($pdo, 'social_channel_drafts', 'crop_x', $decimal);
        self::addColumnIfMissing($pdo, 'social_channel_drafts', 'crop_y', $decimal);
        self::addColumnIfMissing($pdo, 'social_channel_drafts', 'crop_zoom', $zoom);
        self::addColumnIfMissing($pdo, 'social_channel_drafts', 'publish_attempt_id', $varchar64);
    }

    private static function migrateInstagram(PDO $pdo): void
    {
        if (self::isMysql()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS instagram_connections (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                purpose VARCHAR(20) NOT NULL DEFAULT 'artist',
                instagram_user_id VARCHAR(190) NOT NULL DEFAULT '',
                username VARCHAR(255) NOT NULL DEFAULT '',
                account_type VARCHAR(80) NOT NULL DEFAULT '',
                access_token_encrypted MEDIUMTEXT NULL,
                token_expires_at VARCHAR(40) NULL,
                scopes TEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                connected_at VARCHAR(40) NULL,
                disconnected_at VARCHAR(40) NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                UNIQUE KEY uq_instagram_connections_user_purpose (user_id,purpose),
                CONSTRAINT instagram_connections_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS instagram_connections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            purpose TEXT NOT NULL DEFAULT 'artist',
            instagram_user_id TEXT NOT NULL DEFAULT '',
            username TEXT NOT NULL DEFAULT '',
            account_type TEXT NOT NULL DEFAULT '',
            access_token_encrypted TEXT,
            token_expires_at TEXT,
            scopes TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending',
            connected_at TEXT,
            disconnected_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(user_id,purpose)
        )");
    }

    private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (self::isMysql()) {
            $stmt = $pdo->prepare('
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table
                AND COLUMN_NAME = :column
            ');
            $stmt->execute(['table' => $table, 'column' => $column]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
            return;
        }

        $stmt = $pdo->query("PRAGMA table_info({$table})");
        $columns = array_map(fn(array $row): string => (string)$row['name'], $stmt->fetchAll());

        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private static function makeMysqlColumnNullableIfNeeded(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare('
            SELECT IS_NULLABLE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :table
            AND COLUMN_NAME = :column
            LIMIT 1
        ');
        $stmt->execute(['table' => $table, 'column' => $column]);

        if (strtoupper((string)$stmt->fetchColumn()) !== 'YES') {
            $pdo->exec("ALTER TABLE `{$table}` MODIFY `{$column}` {$definition} NULL");
        }
    }

    private static function expandMysqlVarcharIfNeeded(PDO $pdo, string $table, string $column, int $length): void
    {
        $stmt = $pdo->prepare('
            SELECT CHARACTER_MAXIMUM_LENGTH
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column
            LIMIT 1
        ');
        $stmt->execute(['table' => $table, 'column' => $column]);
        $current = (int)$stmt->fetchColumn();
        if ($current > 0 && $current < $length) {
            $pdo->exec("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR({$length}) NOT NULL");
        }
    }

    public static function deductCredit(int $userId, string $reason): bool
    {
        return (bool)self::withBusyRetry(function () use ($userId, $reason): bool {
            $pdo = self::connection();
            $inTransaction = false;

            try {
                self::beginWriteTransaction($pdo);
                $inTransaction = true;

                $sql = 'SELECT credits FROM users WHERE id = :id';
                if (self::isMysql()) {
                    $sql .= ' FOR UPDATE';
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $userId]);
                $credits = (int)$stmt->fetchColumn();

                if ($credits < 1) {
                    $pdo->exec('ROLLBACK');
                    $inTransaction = false;
                    return false;
                }

                $pdo->prepare('UPDATE users SET credits = credits - 1, updated_at = :now WHERE id = :id')
                    ->execute(['now' => date('c'), 'id' => $userId]);

                $pdo->prepare("
                    INSERT INTO credit_transactions (user_id, amount, reason, created_at)
                    VALUES (:user_id, -1, :reason, :created_at)
                ")->execute([
                    'user_id' => $userId,
                    'reason' => $reason,
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
                    }
                }
                throw $e;
            }
        }, 12);
    }

    public static function refundCredit(int $userId, string $reason): void
    {
        self::withBusyRetry(function () use ($userId, $reason): void {
            $pdo = self::connection();
            $inTransaction = false;

            try {
                self::beginWriteTransaction($pdo);
                $inTransaction = true;

                $pdo->prepare('UPDATE users SET credits = credits + 1, updated_at = :now WHERE id = :id')
                    ->execute(['now' => date('c'), 'id' => $userId]);

                $pdo->prepare("
                    INSERT INTO credit_transactions (user_id, amount, reason, created_at)
                    VALUES (:user_id, 1, :reason, :created_at)
                ")->execute([
                    'user_id' => $userId,
                    'reason' => $reason . ' [refund]',
                    'created_at' => date('c'),
                ]);

                $pdo->exec('COMMIT');
                $inTransaction = false;
            } catch (Throwable $e) {
                if ($inTransaction) {
                    try {
                        $pdo->exec('ROLLBACK');
                    } catch (Throwable $rollbackErr) {
                    }
                }
                throw $e;
            }
        });
    }

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
                'id' => $userId,
            ]);

        $pdo->prepare("
            INSERT INTO credit_transactions (user_id, amount, reason, created_at)
            VALUES (:user_id, :amount, :reason, :created_at)
        ")->execute([
            'user_id' => $userId,
            'amount' => $diff,
            'reason' => $reason,
            'created_at' => date('c'),
        ]);
    }

    public static function createGenerationJobWithTransaction(int $userId, int $artworkId, string $contextId, string $prompt, string $imagePath, ?string $selectorStateJson = null): int
    {
        return self::withBusyRetry(function () use ($userId, $artworkId, $contextId, $prompt, $imagePath, $selectorStateJson): int {
            $pdo = self::connection();
            $inTransaction = false;

            try {
                self::beginWriteTransaction($pdo);
                $inTransaction = true;

                // 1. Lock and check credits
                $sql = 'SELECT credits FROM users WHERE id = :id';
                if (self::isMysql()) {
                    $sql .= ' FOR UPDATE';
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $userId]);
                $credits = $stmt->fetchColumn();

                if ($credits === false || (int)$credits < 1) {
                    throw new RuntimeException('No tienes créditos suficientes para generar un mockup.');
                }

                // 2. Deduct credit
                $now = date('c');
                $pdo->prepare('UPDATE users SET credits = credits - 1, updated_at = :now WHERE id = :id')
                    ->execute(['now' => $now, 'id' => $userId]);

                // 3. Register transaction ledger
                $pdo->prepare("
                    INSERT INTO credit_transactions (user_id, amount, reason, created_at)
                    VALUES (:user_id, -1, :reason, :created_at)
                ")->execute([
                    'user_id' => $userId,
                    'reason' => 'mockup_generation:' . $contextId,
                    'created_at' => $now,
                ]);

                // 4. Create generation job in pending_enqueue status
                $groupId = null;
                $stmtGroup = $pdo->prepare('SELECT id FROM artwork_groups WHERE user_id = :user_id AND canonical_artwork_id = :artwork_id LIMIT 1');
                $stmtGroup->execute(['user_id' => $userId, 'artwork_id' => $artworkId]);
                $groupVal = $stmtGroup->fetchColumn();
                if ($groupVal !== false) {
                    $groupId = (int)$groupVal;
                }

                $insert = $pdo->prepare('
                    INSERT INTO mockup_generation_jobs
                        (user_id, artwork_id, artwork_group_id, source_artwork_id, artwork_file, context_id, prompt, selector_state_json, status, attempts, created_at, updated_at)
                    VALUES
                        (:user_id, :artwork_id, :artwork_group_id, :source_artwork_id, :artwork_file, :context_id, :prompt, :selector_state_json, "pending_enqueue", 0, :created_at, :updated_at)
                ');
                $insert->execute([
                    'user_id' => $userId,
                    'artwork_id' => $artworkId,
                    'artwork_group_id' => $groupId,
                    'source_artwork_id' => $artworkId,
                    'artwork_file' => basename($imagePath),
                    'context_id' => $contextId,
                    'prompt' => $prompt,
                    'selector_state_json' => $selectorStateJson,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $jobId = (int)$pdo->lastInsertId();

                $pdo->exec('COMMIT');
                $inTransaction = false;
                return $jobId;
            } catch (Throwable $e) {
                if ($inTransaction) {
                    try {
                        $pdo->exec('ROLLBACK');
                    } catch (Throwable $rollbackErr) {
                    }
                }
                throw $e;
            }
        }, 12);
    }

    public static function failEnqueueAndRefund(int $userId, int $jobId, string $reason): void
    {
        self::withBusyRetry(function () use ($userId, $jobId, $reason): void {
            $pdo = self::connection();
            $inTransaction = false;

            try {
                self::beginWriteTransaction($pdo);
                $inTransaction = true;

                $now = date('c');

                // 1. Re-credit user
                $pdo->prepare('UPDATE users SET credits = credits + 1, updated_at = :now WHERE id = :id')
                    ->execute(['now' => $now, 'id' => $userId]);

                // 2. Register refund ledger
                $pdo->prepare("
                    INSERT INTO credit_transactions (user_id, amount, reason, created_at)
                    VALUES (:user_id, 1, :reason, :created_at)
                ")->execute([
                    'user_id' => $userId,
                    'reason' => 'refund:enqueue_failed_job_' . $jobId,
                    'created_at' => $now,
                ]);

                // 3. Update job status to failed_enqueue
                $pdo->prepare('
                    UPDATE mockup_generation_jobs
                    SET status = "failed_enqueue", error = :error, updated_at = :now
                    WHERE id = :id
                ')->execute([
                    'error' => $reason,
                    'now' => $now,
                    'id' => $jobId
                ]);

                $pdo->exec('COMMIT');
                $inTransaction = false;
            } catch (Throwable $e) {
                if ($inTransaction) {
                    try {
                        $pdo->exec('ROLLBACK');
                    } catch (Throwable $rollbackErr) {
                    }
                }
                throw $e;
            }
        });
    }

    public static function updateJobStatus(int $jobId, string $status, ?string $error = null): void
    {
        self::withBusyRetry(function () use ($jobId, $status, $error): void {
            $pdo = self::connection();
            $stmt = $pdo->prepare('
                UPDATE mockup_generation_jobs
                SET status = :status, error = :error, updated_at = :now
                WHERE id = :id
            ');
            $stmt->execute([
                'status' => $status,
                'error' => $error,
                'now' => date('c'),
                'id' => $jobId
            ]);
        });
    }
}
