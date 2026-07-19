<?php
declare(strict_types=1);

/**
 * Applies the immutable, ordered database migrations shipped with the app.
 *
 * Database::migrate() still builds the historical baseline for old installs.
 * Every schema change from this point forward must live in migrations/schema.
 */
final class SchemaMigrator
{
    private const TABLE = 'schema_migrations';

    public static function migrate(PDO $pdo, ?string $directory = null): array
    {
        $directory ??= self::defaultDirectory();
        self::ensureLedger($pdo);
        $lockAcquired = self::acquireLock($pdo);

        try {
            $migrations = self::loadMigrations($directory);
            $applied = self::applied($pdo);
            self::assertHistoryIsImmutable($migrations, $applied);
            $executed = [];

            foreach ($migrations as $version => $migration) {
                if (isset($applied[$version])) {
                    continue;
                }

                $startedAt = microtime(true);
                $transactional = self::driver($pdo) === 'sqlite';
                $transactionStarted = false;
                if ($transactional && !$pdo->inTransaction()) {
                    $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
                    $transactionStarted = true;
                }

                try {
                    ($migration['up'])($pdo);
                    $stmt = $pdo->prepare(
                        'INSERT INTO ' . self::TABLE .
                        ' (version, description, checksum, applied_at, execution_ms)' .
                        ' VALUES (:version, :description, :checksum, :applied_at, :execution_ms)'
                    );
                    $stmt->execute([
                        'version' => $version,
                        'description' => $migration['description'],
                        'checksum' => $migration['checksum'],
                        'applied_at' => date(DATE_ATOM),
                        'execution_ms' => max(0, (int)round((microtime(true) - $startedAt) * 1000)),
                    ]);
                    if ($transactionStarted) {
                        $pdo->exec('COMMIT');
                    }
                } catch (Throwable $error) {
                    if ($transactionStarted) {
                        try {
                            $pdo->exec('ROLLBACK');
                        } catch (Throwable) {
                        }
                    }
                    throw new RuntimeException("Migration {$version} failed: {$error->getMessage()}", 0, $error);
                }

                $executed[] = $version;
            }

            return [
                'latest' => array_key_last($migrations) ?? '',
                'executed' => $executed,
                'applied_count' => count($applied) + count($executed),
            ];
        } finally {
            if ($lockAcquired) {
                self::releaseLock($pdo);
            }
        }
    }

    public static function status(PDO $pdo, ?string $directory = null): array
    {
        $directory ??= self::defaultDirectory();
        self::ensureLedger($pdo);
        $migrations = self::loadMigrations($directory);
        $applied = self::applied($pdo);
        self::assertHistoryIsImmutable($migrations, $applied);

        return [
            'latest_code_version' => array_key_last($migrations) ?? '',
            'latest_database_version' => array_key_last($applied) ?? '',
            'pending' => array_values(array_diff(array_keys($migrations), array_keys($applied))),
            'applied' => array_keys($applied),
        ];
    }

    public static function assertCurrent(PDO $pdo, ?string $directory = null): void
    {
        $status = self::status($pdo, $directory);
        if ($status['pending'] !== []) {
            throw new RuntimeException(
                'Database schema is behind application code. Pending migrations: ' . implode(', ', $status['pending'])
            );
        }
        if ($status['latest_code_version'] !== $status['latest_database_version']) {
            throw new RuntimeException(
                'Database and application do not share the same schema version.'
            );
        }
    }

    private static function defaultDirectory(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . 'schema';
    }

    private static function ensureLedger(PDO $pdo): void
    {
        if (self::driver($pdo) === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(120) NOT NULL,
                description VARCHAR(255) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at VARCHAR(40) NOT NULL,
                execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            version TEXT NOT NULL PRIMARY KEY,
            description TEXT NOT NULL,
            checksum TEXT NOT NULL,
            applied_at TEXT NOT NULL,
            execution_ms INTEGER NOT NULL DEFAULT 0
        )");
    }

    /** @return array<string,array{description:string,checksum:string,up:callable}> */
    private static function loadMigrations(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException("Schema migration directory not found: {$directory}");
        }

        $files = glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);
        $migrations = [];

        foreach ($files as $file) {
            $version = pathinfo($file, PATHINFO_FILENAME);
            if (preg_match('/^\d{8}_\d{6}_[a-z0-9_]+$/', $version) !== 1) {
                throw new RuntimeException("Invalid schema migration filename: {$file}");
            }
            $definition = require $file;
            if (!is_array($definition) || !isset($definition['description'], $definition['up']) || !is_callable($definition['up'])) {
                throw new RuntimeException("Invalid schema migration definition: {$file}");
            }
            if (isset($migrations[$version])) {
                throw new RuntimeException("Duplicate schema migration version: {$version}");
            }
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new RuntimeException("Could not read schema migration: {$file}");
            }
            $normalizedContents = str_replace(["\r\n", "\r"], "\n", $contents);
            $migrations[$version] = [
                'description' => substr(trim((string)$definition['description']), 0, 255),
                'checksum' => hash('sha256', $normalizedContents),
                'up' => $definition['up'],
            ];
        }

        return $migrations;
    }

    /** @return array<string,array{checksum:string,description:string}> */
    private static function applied(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT version, description, checksum FROM ' . self::TABLE . ' ORDER BY version'
        )->fetchAll(PDO::FETCH_ASSOC);
        $applied = [];
        foreach ($rows as $row) {
            $applied[(string)$row['version']] = [
                'checksum' => (string)$row['checksum'],
                'description' => (string)$row['description'],
            ];
        }
        return $applied;
    }

    private static function assertHistoryIsImmutable(array $migrations, array $applied): void
    {
        foreach ($applied as $version => $row) {
            if (!isset($migrations[$version])) {
                throw new RuntimeException("Applied migration {$version} is missing from this application build.");
            }
            if (!hash_equals((string)$row['checksum'], (string)$migrations[$version]['checksum'])) {
                throw new RuntimeException("Applied migration {$version} was modified after execution.");
            }
        }
    }

    private static function acquireLock(PDO $pdo): bool
    {
        if (self::driver($pdo) !== 'mysql') {
            return false;
        }
        $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $lockName = substr('artworkmockups_schema_' . $database, 0, 64);
        $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 60)');
        $stmt->execute(['lock_name' => $lockName]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire the database migration lock.');
        }
        return true;
    }

    private static function releaseLock(PDO $pdo): void
    {
        $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $lockName = substr('artworkmockups_schema_' . $database, 0, 64);
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $stmt->execute(['lock_name' => $lockName]);
    }

    private static function driver(PDO $pdo): string
    {
        return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }
}
