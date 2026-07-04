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
        $database = app_env('DB_DATABASE', 'mockups');
        $username = app_env('DB_USERNAME', 'root');
        $password = app_env('DB_PASSWORD', '');
        $charset = app_env('DB_CHARSET', 'utf8mb4');

        $serverDsn = "mysql:host={$host};port={$port};charset={$charset}";
        $server = new PDO($serverDsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $server->exec(
            'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $database) . '` ' .
            "CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci"
        );

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
        return new PDO($dsn, $username, $password, [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
        ]);
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
            return;
        }

        self::migrateSqlite($pdo);
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

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS social_video_workflows (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                artwork_id INTEGER NOT NULL UNIQUE,
                setup_suggestion_json TEXT NOT NULL DEFAULT '',
                setup_edited_json TEXT NOT NULL DEFAULT '',
                final_concept_json TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'not_started',
                video_status TEXT NOT NULL DEFAULT 'not_started',
                video_url TEXT NOT NULL DEFAULT '',
                error TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS social_video_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                artwork_id INTEGER NOT NULL,
                workflow_id INTEGER NOT NULL,
                provider TEXT NOT NULL DEFAULT 'vertex_veo',
                model TEXT NOT NULL DEFAULT '',
                concept_json TEXT NOT NULL,
                external_job_id TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'created',
                video_url TEXT NOT NULL DEFAULT '',
                error TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE,
                FOREIGN KEY (workflow_id) REFERENCES social_video_workflows(id) ON DELETE CASCADE
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_social_video_jobs_workflow ON social_video_jobs (workflow_id, status)");
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

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS social_video_workflows (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                artwork_id INT UNSIGNED NOT NULL,
                setup_suggestion_json MEDIUMTEXT NOT NULL,
                setup_edited_json MEDIUMTEXT NOT NULL,
                final_concept_json MEDIUMTEXT NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'not_started',
                video_status VARCHAR(40) NOT NULL DEFAULT 'not_started',
                video_url TEXT NOT NULL,
                error TEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY social_video_workflows_artwork_unique (artwork_id),
                CONSTRAINT social_video_workflows_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT social_video_workflows_artwork_fk FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS social_video_jobs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                artwork_id INT UNSIGNED NOT NULL,
                workflow_id INT UNSIGNED NOT NULL,
                provider VARCHAR(80) NOT NULL DEFAULT 'vertex_veo',
                model VARCHAR(120) NOT NULL DEFAULT '',
                concept_json MEDIUMTEXT NOT NULL,
                external_job_id VARCHAR(255) NOT NULL DEFAULT '',
                status VARCHAR(40) NOT NULL DEFAULT 'created',
                video_url TEXT NOT NULL,
                error TEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_social_video_jobs_workflow (workflow_id, status),
                CONSTRAINT social_video_jobs_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT social_video_jobs_artwork_fk FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE,
                CONSTRAINT social_video_jobs_workflow_fk FOREIGN KEY (workflow_id) REFERENCES social_video_workflows(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::expandMysqlVarcharIfNeeded($pdo, 'social_video_workflows', 'video_status', 255);
        self::expandMysqlVarcharIfNeeded($pdo, 'social_video_workflows', 'status', 100);
        self::expandMysqlVarcharIfNeeded($pdo, 'social_video_jobs', 'status', 100);

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
}
