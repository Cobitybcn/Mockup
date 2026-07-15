<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$rootDir = dirname(__DIR__);
$sqlitePath = $rootDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app.sqlite';

if (!is_file($sqlitePath)) {
    fwrite(STDERR, "SQLite database not found: {$sqlitePath}\n");
    exit(1);
}

$mysql = mysql_connection();
$sqlite = new PDO('sqlite:' . $sqlitePath);
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

create_mysql_schema($mysql);

$tables = [
    'users',
    'artworks',
    'mockups',
    'artist_profiles',
    'credit_transactions',
    'app_settings',
    'artwork_analysis',
    'mockup_contexts',
    'mockup_generation_jobs',
];

$mysql->beginTransaction();

try {
    $mysql->exec('SET FOREIGN_KEY_CHECKS=0');

    foreach (array_reverse($tables) as $table) {
        $mysql->exec('DELETE FROM `' . $table . '`');
    }

    foreach ($tables as $table) {
        copy_table($sqlite, $mysql, $table);
    }

    $mysql->exec('SET FOREIGN_KEY_CHECKS=1');
    $mysql->commit();
} catch (Throwable $e) {
    if ($mysql->inTransaction()) {
        $mysql->rollBack();
    }
    try {
        $mysql->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ignored) {
    }
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Migration completed.\n\n";
echo str_pad('Table', 32) . str_pad('SQLite', 12) . "MySQL\n";
echo str_repeat('-', 56) . "\n";

foreach ($tables as $table) {
    $sqliteCount = (int)$sqlite->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    $mysqlCount = (int)$mysql->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    echo str_pad($table, 32) . str_pad((string)$sqliteCount, 12) . $mysqlCount . "\n";
}

echo "\nNext step: set DB_CONNECTION=mysql in .env after reviewing these counts.\n";

function mysql_connection(): PDO
{
    $host = app_env('DB_HOST', '127.0.0.1');
    $port = app_env('DB_PORT', '3306');
    $database = app_env('DB_DATABASE', 'mockups');
    $username = app_env('DB_USERNAME', 'root');
    $password = app_env('DB_PASSWORD', '');
    $charset = app_env('DB_CHARSET', 'utf8mb4');

    $server = new PDO("mysql:host={$host};port={$port};charset={$charset}", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $server->exec(
        'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $database) . '` ' .
        "CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci"
    );

    return new PDO("mysql:host={$host};port={$port};dbname={$database};charset={$charset}", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
    ]);
}

function create_mysql_schema(PDO $mysql): void
{
    require_once __DIR__ . '/../app/Support/Database.php';

    putenv('DB_CONNECTION=mysql');
    $_ENV['DB_CONNECTION'] = 'mysql';
    $_SERVER['DB_CONNECTION'] = 'mysql';

    Database::connection();
}

function copy_table(PDO $sqlite, PDO $mysql, string $table): void
{
    $rows = $sqlite->query('SELECT * FROM ' . $table)->fetchAll();
    if ($rows === []) {
        return;
    }

    $columns = array_keys($rows[0]);
    $columnSql = implode(', ', array_map(fn(string $col): string => '`' . str_replace('`', '``', $col) . '`', $columns));
    $placeholderSql = implode(', ', array_map(fn(string $col): string => ':' . $col, $columns));
    $stmt = $mysql->prepare("INSERT INTO `{$table}` ({$columnSql}) VALUES ({$placeholderSql})");

    foreach ($rows as $row) {
        foreach ($row as $key => $value) {
            if ($value === null) {
                continue;
            }
            $row[$key] = (string)$value;
        }
        $stmt->execute($row);
    }
}
