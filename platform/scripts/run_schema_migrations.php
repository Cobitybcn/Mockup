<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run from the CLI.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/app/Support/Database.php';
require_once dirname(__DIR__) . '/app/Support/SchemaMigrator.php';

try {
    $pdo = Database::connection();
    SchemaMigrator::assertCurrent($pdo);
    $status = SchemaMigrator::status($pdo);
    fwrite(STDOUT, sprintf(
        "Schema ready: environment=%s driver=%s database=%s version=%s\n",
        app_env('APP_ENV', 'unset'),
        (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
        app_env('DB_DATABASE', 'sqlite'),
        (string)$status['latest_database_version']
    ));
} catch (Throwable $error) {
    fwrite(STDERR, "Schema migration failed: {$error->getMessage()}\n");
    exit(1);
}
