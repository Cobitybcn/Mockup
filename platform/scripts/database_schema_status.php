<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run from the CLI.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/app/Support/Database.php';
require_once dirname(__DIR__) . '/app/Support/SchemaMigrator.php';

$options = getopt('', ['json', 'assert-current', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php scripts/database_schema_status.php [--json] [--assert-current]\n");
    exit(0);
}

try {
    $pdo = Database::connection();
    $status = SchemaMigrator::status($pdo);
    if (isset($options['assert-current'])) {
        SchemaMigrator::assertCurrent($pdo);
    }
    $report = [
        'environment' => app_env('APP_ENV', 'unset'),
        'driver' => (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
        'database' => app_env('DB_DATABASE', 'sqlite'),
        ...$status,
        'current' => $status['pending'] === []
            && $status['latest_code_version'] === $status['latest_database_version'],
    ];
    if (isset($options['json'])) {
        fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } else {
        fwrite(STDOUT, "Environment: {$report['environment']}\n");
        fwrite(STDOUT, "Database: {$report['driver']}:{$report['database']}\n");
        fwrite(STDOUT, "Code schema: {$report['latest_code_version']}\n");
        fwrite(STDOUT, "Database schema: {$report['latest_database_version']}\n");
        fwrite(STDOUT, 'Pending: ' . ($report['pending'] === [] ? 'none' : implode(', ', $report['pending'])) . PHP_EOL);
        fwrite(STDOUT, 'Status: ' . ($report['current'] ? 'CURRENT' : 'OUT OF DATE') . PHP_EOL);
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Schema status failed: {$error->getMessage()}\n");
    exit(1);
}
