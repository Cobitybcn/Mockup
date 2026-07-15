<?php
declare(strict_types=1);

/**
 * Permanently remove a disabled user's active database rows and exclusive
 * files after a verified archive has been created.
 *
 * The archive itself and files listed as shared are never removed.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run from the CLI.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['email:', 'archive-manifest:', 'execute', 'confirm:', 'help']);
if (isset($options['help'])) {
    usage();
    exit(0);
}

$email = strtolower(trim((string)($options['email'] ?? '')));
$manifestPath = trim((string)($options['archive-manifest'] ?? ''));
$execute = array_key_exists('execute', $options);
$confirmation = trim((string)($options['confirm'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Provide a valid --email address.');
}
if ($manifestPath === '' || !is_file($manifestPath)) {
    fail('Provide an existing --archive-manifest path.');
}
if ($execute && $confirmation !== 'DELETE') {
    fail('--confirm=DELETE is required with --execute.');
}
if (StorageService::isGcsActive()) {
    fail('Cloud storage is active. This local archive cannot prove that remote objects are preserved.');
}

$platformRoot = realpath(dirname(__DIR__));
if ($platformRoot === false) {
    fail('The platform root could not be resolved.');
}

try {
    $archiveRoot = dirname(realpath($manifestPath) ?: $manifestPath);
    $manifest = json_decode((string)file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
    validateManifest($manifest, $email, $archiveRoot);
    verifyArchiveChecksums($archiveRoot);

    $archiveFiles = $manifest['files']['copied_and_verified'];
    $sharedFiles = $manifest['files']['kept_active_because_shared'];
    $sharedLookup = [];
    foreach ($sharedFiles as $file) {
        $sharedLookup[normalizeRelativePath((string)$file['path'])] = true;
    }

    $activeFiles = [];
    $alreadyMissing = 0;
    $activeBytes = 0;
    foreach ($archiveFiles as $file) {
        $relative = normalizeRelativePath((string)($file['path'] ?? ''));
        if (isset($sharedLookup[$relative])) {
            throw new RuntimeException("A file is marked both exclusive and shared: {$relative}");
        }
        $source = safeActivePath($platformRoot, $relative);
        if (!is_file($source)) {
            $alreadyMissing++;
            continue;
        }
        $expectedHash = strtolower((string)($file['sha256'] ?? ''));
        $actualHash = hash_file('sha256', $source);
        if ($actualHash === false || !hash_equals($expectedHash, strtolower($actualHash))) {
            throw new RuntimeException("The active file changed after archiving and will not be deleted: {$relative}");
        }
        $activeFiles[] = ['relative' => $relative, 'absolute' => $source, 'bytes' => filesize($source) ?: 0];
        $activeBytes += filesize($source) ?: 0;
    }

    $pdo = Database::connection();
    $user = findUser($pdo, $email);
    if ($user !== null) {
        if ((int)$user['id'] !== (int)$manifest['user']['id']) {
            throw new RuntimeException('The current account ID does not match the archive.');
        }
        if ((int)$user['is_admin'] === 1) {
            throw new RuntimeException('Administrator accounts cannot be purged.');
        }
        if (strtolower((string)($user['status'] ?? 'active')) !== 'disabled') {
            throw new RuntimeException('The account must be disabled before it can be purged.');
        }
    }

    fwrite(STDOUT, sprintf(
        "Purge plan for user #%d <%s>:\n  database account: %s\n  exclusive active files: %d (%s)\n  already absent: %d\n  shared files preserved: %d\n  archive preserved at: %s\n",
        (int)$manifest['user']['id'],
        $email,
        $user === null ? 'already absent' : 'disabled and ready',
        count($activeFiles),
        humanBytes($activeBytes),
        $alreadyMissing,
        count($sharedFiles),
        $archiveRoot
    ));

    if (!$execute) {
        fwrite(STDOUT, "Dry run only: nothing was deleted.\n");
        exit(0);
    }

    if ($user !== null) {
        purgeDatabase($pdo, (int)$user['id']);
        fwrite(STDOUT, "Database account and owned rows deleted.\n");
    }

    $deletedFiles = 0;
    $deletedBytes = 0;
    $worldMotherChanged = false;
    $candidateDirectories = [];
    foreach ($activeFiles as $file) {
        if (!unlink($file['absolute'])) {
            throw new RuntimeException("Could not delete active file: {$file['relative']}");
        }
        $deletedFiles++;
        $deletedBytes += $file['bytes'];
        $candidateDirectories[dirname($file['absolute'])] = true;
        if (str_starts_with(strtolower($file['relative']), 'storage/world_mothers/')) {
            $worldMotherChanged = true;
        }
    }

    removeEmptyDirectories(array_keys($candidateDirectories), $platformRoot);
    if ($worldMotherChanged) {
        (new WorldMotherLibrary())->rebuildIndex();
    }

    foreach ($sharedLookup as $relative => $_) {
        $sharedPath = safeActivePath($platformRoot, $relative);
        if (!is_file($sharedPath)) {
            throw new RuntimeException("A shared file is missing after the purge: {$relative}");
        }
    }

    $report = [
        'status' => 'complete',
        'completed_at' => date(DATE_ATOM),
        'user' => $manifest['user'],
        'database_deleted' => true,
        'active_files_deleted' => $deletedFiles,
        'active_bytes_deleted' => $deletedBytes,
        'already_missing_files' => $alreadyMissing,
        'shared_files_preserved' => count($sharedFiles),
        'archive_preserved' => $archiveRoot,
    ];
    file_put_contents(
        $archiveRoot . DIRECTORY_SEPARATOR . 'purge-report.json',
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
        LOCK_EX
    );

    fwrite(STDOUT, sprintf(
        "Purge complete: %d active files (%s) deleted; %d shared files and the archive were preserved.\n",
        $deletedFiles,
        humanBytes($deletedBytes),
        count($sharedFiles)
    ));
} catch (Throwable $e) {
    fail($e->getMessage());
}

function usage(): void
{
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php scripts/purge_archived_user.php --email=person@example.com --archive-manifest=C:\\archive\\manifest.json\n");
    fwrite(STDOUT, "  php scripts/purge_archived_user.php --email=person@example.com --archive-manifest=C:\\archive\\manifest.json --execute --confirm=DELETE\n");
}

function validateManifest(array $manifest, string $email, string $archiveRoot): void
{
    if (($manifest['status'] ?? '') !== 'complete') {
        throw new RuntimeException('The archive manifest is not complete.');
    }
    if (strtolower((string)($manifest['user']['email'] ?? '')) !== $email || (int)($manifest['user']['id'] ?? 0) <= 0) {
        throw new RuntimeException('The archive manifest belongs to a different user.');
    }
    if (($manifest['safety']['source_files_deleted'] ?? null) !== false
        || ($manifest['safety']['database_rows_changed'] ?? null) !== false) {
        throw new RuntimeException('The manifest does not describe a non-destructive archive.');
    }
    if (!is_array($manifest['files']['copied_and_verified'] ?? null)
        || !is_array($manifest['files']['kept_active_because_shared'] ?? null)) {
        throw new RuntimeException('The archive manifest has no valid file inventory.');
    }
    foreach (['database-export.sql', 'checksums.sha256'] as $file) {
        if (!is_file($archiveRoot . DIRECTORY_SEPARATOR . $file)) {
            throw new RuntimeException("The archive is missing {$file}.");
        }
    }
}

function verifyArchiveChecksums(string $archiveRoot): void
{
    $checksumPath = $archiveRoot . DIRECTORY_SEPARATOR . 'checksums.sha256';
    $lines = file($checksumPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || $lines === []) {
        throw new RuntimeException('The archive checksum file is empty.');
    }
    foreach ($lines as $line) {
        if (!preg_match('/^([a-f0-9]{64}) \*(.+)$/i', $line, $match)) {
            throw new RuntimeException('The archive checksum file is malformed.');
        }
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $match[2]);
        $path = $archiveRoot . DIRECTORY_SEPARATOR . $relative;
        if (!is_file($path)) {
            throw new RuntimeException("An archived file is missing: {$match[2]}");
        }
        $actual = hash_file('sha256', $path);
        if ($actual === false || !hash_equals(strtolower($match[1]), strtolower($actual))) {
            throw new RuntimeException("An archived checksum does not match: {$match[2]}");
        }
    }
}

/** @return array<string,mixed>|null */
function findUser(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(email)=? LIMIT 2');
    $stmt->execute([$email]);
    $users = $stmt->fetchAll();
    if (count($users) > 1) {
        throw new RuntimeException('More than one account matched the email address.');
    }
    return $users[0] ?? null;
}

function purgeDatabase(PDO $pdo, int $userId): void
{
    Database::beginWriteTransaction($pdo);
    try {
        if (Database::isMysql()) {
            $lock = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $lock->execute([$userId]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('The account disappeared before deletion.');
            }
        }

        $publicationIds = $pdo->prepare('SELECT id FROM publications WHERE user_id=?');
        $publicationIds->execute([$userId]);
        $ids = array_map('intval', $publicationIds->fetchAll(PDO::FETCH_COLUMN));
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            foreach (['publication_items', 'channel_variants', 'distribution_jobs'] as $table) {
                $pdo->prepare("DELETE FROM `{$table}` WHERE publication_id IN ({$placeholders})")->execute($ids);
            }
        }

        foreach (['publications', 'artwork_series', 'social_campaigns'] as $table) {
            $pdo->prepare("DELETE FROM `{$table}` WHERE user_id=?")->execute([$userId]);
        }
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);

        $remaining = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE id=' . $userId)->fetchColumn();
        if ($remaining !== 0) {
            throw new RuntimeException('The user row was not deleted.');
        }

        $database = $pdo->quote(DB_DATABASE);
        $columns = $pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA={$database} AND COLUMN_NAME IN ('user_id','created_by_user_id','actor_user_id')")->fetchAll();
        foreach ($columns as $column) {
            $table = (string)$column['TABLE_NAME'];
            $name = (string)$column['COLUMN_NAME'];
            $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE `{$name}`=" . $userId)->fetchColumn();
            if ($count > 0) {
                throw new RuntimeException("Owned rows remain in {$table}.{$name}: {$count}");
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function normalizeRelativePath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) || str_contains('/' . $path . '/', '/../')) {
        throw new RuntimeException("Unsafe relative path in the archive: {$path}");
    }
    $top = strtolower(explode('/', $path, 2)[0]);
    if (!in_array($top, ['results', 'jobs', 'uploads', 'analysis', 'mockup-prompts', 'storage'], true)) {
        throw new RuntimeException("Archive path is outside the managed runtime directories: {$path}");
    }
    return $path;
}

function safeActivePath(string $platformRoot, string $relative): string
{
    $path = $platformRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $parent = realpath(dirname($path));
    if ($parent !== false) {
        $rootKey = strtolower(str_replace('\\', '/', rtrim($platformRoot, '\\/'))) . '/';
        $parentKey = strtolower(str_replace('\\', '/', rtrim($parent, '\\/'))) . '/';
        if (!str_starts_with($parentKey, $rootKey)) {
            throw new RuntimeException("Active path escaped the platform root: {$relative}");
        }
    }
    return $path;
}

function removeEmptyDirectories(array $directories, string $platformRoot): void
{
    usort($directories, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    $managedRoots = array_map(
        static fn(string $name): string => strtolower($platformRoot . DIRECTORY_SEPARATOR . $name),
        ['results', 'jobs', 'uploads', 'analysis', 'mockup-prompts', 'storage']
    );
    foreach ($directories as $directory) {
        $current = $directory;
        while (is_dir($current) && !in_array(strtolower($current), $managedRoots, true)) {
            $contents = array_values(array_diff(scandir($current) ?: [], ['.', '..']));
            if ($contents !== [] || !rmdir($current)) {
                break;
            }
            $current = dirname($current);
        }
    }
}

function humanBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return sprintf($unit === 0 ? '%.0f %s' : '%.2f %s', $value, $units[$unit]);
}

function fail(string $message): never
{
    fwrite(STDERR, "Purge failed: {$message}\n");
    exit(1);
}
