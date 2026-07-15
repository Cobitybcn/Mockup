<?php
declare(strict_types=1);

/**
 * Build a verified, non-destructive archive for a retired user account.
 *
 * Dry run:
 *   php scripts/archive_user.php --email=person@example.com
 *
 * Copy and verify:
 *   php scripts/archive_user.php --email=person@example.com \
 *     --archive-dir=C:\laragon\archives\artworkmockups\retired-users\user-1-YYYYMMDD \
 *     --copy
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run from the CLI.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';

$options = getopt('', ['email:', 'archive-dir:', 'copy', 'help']);
if (isset($options['help'])) {
    printUsage();
    exit(0);
}

$email = strtolower(trim((string)($options['email'] ?? '')));
$copy = array_key_exists('copy', $options);
$archiveDir = trim((string)($options['archive-dir'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Provide a valid --email address.\n\n");
    printUsage();
    exit(1);
}
if ($copy && $archiveDir === '') {
    fwrite(STDERR, "--archive-dir is required with --copy.\n");
    exit(1);
}

$platformRoot = realpath(dirname(__DIR__));
if ($platformRoot === false) {
    throw new RuntimeException('The platform root could not be resolved.');
}
$repositoryRoot = dirname($platformRoot);

try {
    $pdo = archiveConnection($platformRoot);
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $schema = inspectSchema($pdo, $driver);
    $user = findUser($pdo, $driver, $email);
    $userId = (int)$user['id'];

    fwrite(STDOUT, sprintf("Planning archive for user #%d <%s>...\n", $userId, $email));

    $selected = selectOwnedRows($pdo, $driver, $schema, $userId);
    $plan = buildFilePlan($pdo, $driver, $schema, $selected, $platformRoot);
    $tableCounts = [];
    foreach ($selected as $table => $rows) {
        if ($rows !== []) {
            $tableCounts[$table] = count($rows);
        }
    }
    ksort($tableCounts);

    $summary = [
        'user_id' => $userId,
        'email' => $email,
        'database_rows' => array_sum($tableCounts),
        'database_tables' => count($tableCounts),
        'archive_files' => count($plan['archive']),
        'archive_bytes' => array_sum(array_column($plan['archive'], 'bytes')),
        'shared_files_kept_active' => count($plan['shared']),
        'shared_bytes_kept_active' => array_sum(array_column($plan['shared'], 'bytes')),
    ];

    printSummary($summary, $tableCounts, $plan['by_directory']);

    if (!$copy) {
        fwrite(STDOUT, "\nDry run only: nothing was copied, moved, changed, or deleted.\n");
        fwrite(STDOUT, "Run again with --archive-dir=<absolute-path> --copy to create the verified archive.\n");
        exit(0);
    }

    validateArchiveDirectory($archiveDir, $repositoryRoot, $platformRoot);
    createArchive($pdo, $driver, $selected, $schema, $plan, $summary, $tableCounts, $archiveDir, $platformRoot);

    fwrite(STDOUT, "\nArchive completed and verified at: {$archiveDir}\n");
    fwrite(STDOUT, "No source file or database row was modified or deleted.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Archive failed: {$e->getMessage()}\n");
    exit(1);
}

function printUsage(): void
{
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php scripts/archive_user.php --email=person@example.com\n");
    fwrite(STDOUT, "  php scripts/archive_user.php --email=person@example.com --archive-dir=C:\\absolute\\path --copy\n");
}

function archiveConnection(string $platformRoot): PDO
{
    if (strtolower(DB_CONNECTION) === 'mysql') {
        $host = DB_HOST;
        $port = DB_PORT;
        $socket = DB_SOCKET;
        $charset = DB_CHARSET;
        $dsn = $socket !== ''
            ? "mysql:unix_socket={$socket};dbname=" . DB_DATABASE . ";charset={$charset}"
            : "mysql:host={$host};port={$port};dbname=" . DB_DATABASE . ";charset={$charset}";
        return new PDO($dsn, DB_USERNAME, DB_PASSWORD, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    $path = $platformRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app.sqlite';
    if (!is_file($path)) {
        throw new RuntimeException("SQLite database not found at {$path}.");
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA query_only = ON');
    return $pdo;
}

/** @return array{tables:array<string,array{columns:array<int,string>,primary:array<int,string>}>,relations:array<int,array{child:string,child_column:string,parent:string,parent_column:string}>} */
function inspectSchema(PDO $pdo, string $driver): array
{
    $tables = [];
    $relations = [];

    if ($driver === 'mysql') {
        $tableStmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
        $tableStmt->execute([DB_DATABASE]);
        foreach ($tableStmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $columnStmt = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
            $columnStmt->execute([DB_DATABASE, $table]);
            $columns = [];
            $primary = [];
            foreach ($columnStmt as $column) {
                $columns[] = (string)$column['COLUMN_NAME'];
                if ((string)$column['COLUMN_KEY'] === 'PRI') {
                    $primary[] = (string)$column['COLUMN_NAME'];
                }
            }
            $tables[(string)$table] = ['columns' => $columns, 'primary' => $primary];
        }

        $relationStmt = $pdo->prepare("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME IS NOT NULL");
        $relationStmt->execute([DB_DATABASE]);
        foreach ($relationStmt as $relation) {
            $relations[] = [
                'child' => (string)$relation['TABLE_NAME'],
                'child_column' => (string)$relation['COLUMN_NAME'],
                'parent' => (string)$relation['REFERENCED_TABLE_NAME'],
                'parent_column' => (string)$relation['REFERENCED_COLUMN_NAME'],
            ];
        }
    } else {
        $tableRows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tableRows as $table) {
            $columns = [];
            $primaryByPosition = [];
            foreach ($pdo->query('PRAGMA table_info(' . quoteIdentifier((string)$table, $driver) . ')') as $column) {
                $columns[] = (string)$column['name'];
                if ((int)$column['pk'] > 0) {
                    $primaryByPosition[(int)$column['pk']] = (string)$column['name'];
                }
            }
            ksort($primaryByPosition);
            $tables[(string)$table] = ['columns' => $columns, 'primary' => array_values($primaryByPosition)];
            foreach ($pdo->query('PRAGMA foreign_key_list(' . quoteIdentifier((string)$table, $driver) . ')') as $relation) {
                $relations[] = [
                    'child' => (string)$table,
                    'child_column' => (string)$relation['from'],
                    'parent' => (string)$relation['table'],
                    'parent_column' => (string)$relation['to'],
                ];
            }
        }
    }

    // These application tables intentionally have no database-level foreign keys.
    foreach ([
        ['publication_items', 'publication_id', 'publications', 'id'],
        ['channel_variants', 'publication_id', 'publications', 'id'],
        ['distribution_jobs', 'publication_id', 'publications', 'id'],
    ] as [$child, $childColumn, $parent, $parentColumn]) {
        if (isset($tables[$child], $tables[$parent])
            && in_array($childColumn, $tables[$child]['columns'], true)
            && in_array($parentColumn, $tables[$parent]['columns'], true)) {
            $relations[] = [
                'child' => $child,
                'child_column' => $childColumn,
                'parent' => $parent,
                'parent_column' => $parentColumn,
            ];
        }
    }

    return compact('tables', 'relations');
}

/** @return array<string,mixed> */
function findUser(PDO $pdo, string $driver, string $email): array
{
    $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdentifier('users', $driver) . ' WHERE LOWER(email)=? LIMIT 2');
    $stmt->execute([$email]);
    $users = $stmt->fetchAll();
    if (count($users) !== 1) {
        throw new RuntimeException(count($users) === 0 ? "No user found for {$email}." : "More than one user matched {$email}.");
    }
    return $users[0];
}

/** @return array<string,array<string,array<string,mixed>>> */
function selectOwnedRows(PDO $pdo, string $driver, array $schema, int $userId): array
{
    $selected = array_fill_keys(array_keys($schema['tables']), []);
    $selected['users'] = keyedRows(
        queryRows($pdo, $driver, 'users', ['id' => [$userId]]),
        $schema['tables']['users']['primary']
    );

    $ownerColumns = ['user_id', 'created_by_user_id', 'actor_user_id'];
    foreach ($schema['tables'] as $table => $metadata) {
        if ($table === 'users') {
            continue;
        }
        $matchingColumns = array_values(array_intersect($ownerColumns, $metadata['columns']));
        if ($matchingColumns === []) {
            continue;
        }
        $rows = queryRowsAnyColumn($pdo, $driver, $table, $matchingColumns, $userId);
        $selected[$table] = keyedRows($rows, $metadata['primary']);
    }

    do {
        $changed = false;
        foreach ($schema['relations'] as $relation) {
            $parentRows = $selected[$relation['parent']] ?? [];
            if ($parentRows === []) {
                continue;
            }
            $values = [];
            foreach ($parentRows as $row) {
                $value = $row[$relation['parent_column']] ?? null;
                if ($value !== null && $value !== '') {
                    $values[(string)$value] = $value;
                }
            }
            if ($values === []) {
                continue;
            }
            foreach (array_chunk(array_values($values), 500) as $chunk) {
                $rows = queryRows($pdo, $driver, $relation['child'], [$relation['child_column'] => $chunk]);
                foreach (keyedRows($rows, $schema['tables'][$relation['child']]['primary']) as $key => $row) {
                    if (!isset($selected[$relation['child']][$key])) {
                        $selected[$relation['child']][$key] = $row;
                        $changed = true;
                    }
                }
            }
        }
    } while ($changed);

    return $selected;
}

/** @return array<int,array<string,mixed>> */
function queryRows(PDO $pdo, string $driver, string $table, array $filters): array
{
    $clauses = [];
    $parameters = [];
    foreach ($filters as $column => $values) {
        if ($values === []) {
            return [];
        }
        $clauses[] = quoteIdentifier((string)$column, $driver) . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        array_push($parameters, ...$values);
    }
    $sql = 'SELECT * FROM ' . quoteIdentifier($table, $driver) . ' WHERE ' . implode(' AND ', $clauses);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parameters);
    return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function queryRowsAnyColumn(PDO $pdo, string $driver, string $table, array $columns, int $userId): array
{
    $clauses = array_map(static fn(string $column): string => quoteIdentifier($column, $driver) . '=?', $columns);
    $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdentifier($table, $driver) . ' WHERE ' . implode(' OR ', $clauses));
    $stmt->execute(array_fill(0, count($columns), $userId));
    return $stmt->fetchAll();
}

/** @return array<string,array<string,mixed>> */
function keyedRows(array $rows, array $primaryColumns): array
{
    $keyed = [];
    foreach ($rows as $row) {
        $parts = [];
        foreach ($primaryColumns as $column) {
            $parts[] = (string)($row[$column] ?? '');
        }
        $key = $parts !== [] ? implode("\x1f", $parts) : hash('sha256', serialize($row));
        $keyed[$key] = $row;
    }
    return $keyed;
}

/** @return array{archive:array<int,array{path:string,bytes:int}>,shared:array<int,array{path:string,bytes:int}>,by_directory:array<string,array{archive_files:int,archive_bytes:int,shared_files:int,shared_bytes:int}>} */
function buildFilePlan(PDO $pdo, string $driver, array $schema, array $selected, string $platformRoot): array
{
    $managedDirectories = ['results', 'jobs', 'uploads', 'analysis', 'mockup-prompts', 'storage'];
    $files = [];
    $basenameIndex = [];
    $jobBasenameIndex = [];
    $jobs = [];
    $jobOwners = [];
    $targetUser = reset($selected['users']);
    $targetUserId = (int)($targetUser['id'] ?? 0);
    $selectedArtworkIds = [];
    foreach ($selected['artworks'] ?? [] as $row) {
        $selectedArtworkIds[(int)($row['id'] ?? 0)] = true;
    }

    foreach ($managedDirectories as $directory) {
        $root = $platformRoot . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $absolute = $file->getPathname();
            $relative = relativePath($absolute, $platformRoot);
            $files[$relative] = ['path' => $relative, 'bytes' => $file->getSize()];
            if ($directory === 'jobs') {
                $parts = explode('/', $relative);
                if (isset($parts[1])) {
                    $jobs[strtolower($parts[1])][] = $relative;
                }
                $jobBasenameIndex[strtolower($file->getBasename())][] = $relative;
                if (isset($parts[1]) && strtolower($file->getBasename()) === 'status.json') {
                    $status = json_decode((string)file_get_contents($absolute), true);
                    if (is_array($status)) {
                        if (isset($status['user_id'])) {
                            $jobOwners[strtolower($parts[1])] = (int)$status['user_id'];
                        } elseif (isset($status['artwork_id'], $selectedArtworkIds[(int)$status['artwork_id']])) {
                            $jobOwners[strtolower($parts[1])] = $targetUserId;
                        }
                    }
                }
                continue;
            }
            $basenameIndex[strtolower($file->getBasename())][] = $relative;
        }
    }

    $referenceIndex = $basenameIndex;
    foreach ($jobBasenameIndex as $basename => $paths) {
        $referenceIndex[$basename] = array_merge($referenceIndex[$basename] ?? [], $paths);
    }
    $selectedBasenames = extractReferencesFromRowSets($selected, $referenceIndex);
    $selectedJobIds = [];
    foreach ($selected['artworks'] ?? [] as $row) {
        $jobId = trim((string)($row['job_id'] ?? ''));
        if ($jobId !== '') {
            $selectedJobIds[strtolower($jobId)] = true;
        }
    }
    foreach ($jobOwners as $jobId => $ownerId) {
        if ($ownerId === $targetUserId) {
            $selectedJobIds[$jobId] = true;
        }
    }

    // Include deterministic sidecars derived from selected images.
    foreach (array_keys($selectedBasenames) as $basename) {
        $stem = strtolower(pathinfo($basename, PATHINFO_FILENAME));
        foreach (["{$stem}.analysis.json", "{$stem}.meta.json"] as $sidecar) {
            if (isset($basenameIndex[$sidecar])) {
                $selectedBasenames[$sidecar] = true;
            }
        }
    }

    $candidatePaths = [];
    foreach (array_keys($selectedBasenames) as $basename) {
        foreach ($basenameIndex[$basename] ?? [] as $relative) {
            $candidatePaths[$relative] = true;
        }
        $jobPaths = $jobBasenameIndex[$basename] ?? [];
        foreach ($jobPaths as $relative) {
            $parts = explode('/', $relative);
            $belongsToSelectedJob = isset($parts[1], $selectedJobIds[strtolower($parts[1])]);
            if ($belongsToSelectedJob || count($jobPaths) === 1) {
                $candidatePaths[$relative] = true;
            }
        }
    }
    foreach (array_keys($selectedJobIds) as $jobId) {
        foreach ($jobs[$jobId] ?? [] as $relative) {
            $candidatePaths[$relative] = true;
        }
    }

    $otherBasenames = [];
    $sharedJobIds = [];
    foreach ($schema['tables'] as $table => $metadata) {
        $stmt = $pdo->query('SELECT * FROM ' . quoteIdentifier($table, $driver));
        while ($row = $stmt->fetch()) {
            $keyed = keyedRows([$row], $metadata['primary']);
            $rowKey = array_key_first($keyed);
            if ($rowKey !== null && isset($selected[$table][$rowKey])) {
                continue;
            }
            foreach (extractReferencesFromValues($row, $referenceIndex) as $basename => $_) {
                if (isset($selectedBasenames[$basename])) {
                    $otherBasenames[$basename] = true;
                }
            }
            foreach ($selectedJobIds as $jobId => $_) {
                foreach ($row as $value) {
                    if (is_string($value) && stripos($value, $jobId) !== false) {
                        $sharedJobIds[$jobId] = true;
                    }
                }
            }
        }
    }

    $archive = [];
    $shared = [];
    $byDirectory = [];
    foreach (array_keys($candidatePaths) as $relative) {
        if (!isset($files[$relative])) {
            continue;
        }
        $parts = explode('/', $relative);
        $directory = $parts[0];
        $basename = strtolower(basename($relative));
        if ($directory === 'jobs') {
            $jobId = strtolower((string)($parts[1] ?? ''));
            $belongsToSelectedJob = isset($selectedJobIds[$jobId]);
            $isShared = $belongsToSelectedJob
                ? isset($sharedJobIds[$jobId])
                : isset($otherBasenames[$basename]);
        } else {
            $isShared = isset($otherBasenames[$basename]);
        }
        $target = $isShared ? 'shared' : 'archive';
        ${$target}[] = $files[$relative];
        $byDirectory[$directory] ??= ['archive_files' => 0, 'archive_bytes' => 0, 'shared_files' => 0, 'shared_bytes' => 0];
        $byDirectory[$directory][$target . '_files']++;
        $byDirectory[$directory][$target . '_bytes'] += $files[$relative]['bytes'];
    }
    usort($archive, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
    usort($shared, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
    ksort($byDirectory);

    return ['archive' => $archive, 'shared' => $shared, 'by_directory' => $byDirectory];
}

/** @return array<string,bool> */
function extractReferencesFromRowSets(array $rowSets, array $basenameIndex): array
{
    $references = [];
    foreach ($rowSets as $rows) {
        foreach ($rows as $row) {
            $references += extractReferencesFromValues($row, $basenameIndex);
        }
    }
    return $references;
}

/** @return array<string,bool> */
function extractReferencesFromValues(mixed $value, array $basenameIndex): array
{
    $references = [];
    $visit = function (mixed $item) use (&$visit, &$references, $basenameIndex): void {
        if (is_array($item)) {
            foreach ($item as $nested) {
                $visit($nested);
            }
            return;
        }
        if (!is_string($item) || trim($item) === '') {
            return;
        }

        $trimmed = trim($item);
        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            try {
                $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
                $visit($decoded);
            } catch (JsonException) {
                // The value is ordinary text, not JSON.
            }
        }

        $candidates = [$trimmed];
        preg_match_all('/[\pL\pN][\pL\pN._@+%()\- ]{0,240}\.(?:jpe?g|png|webp|gif|svg|pdf|mp4|mov|json|txt)/iu', $trimmed, $matches);
        array_push($candidates, ...($matches[0] ?? []));
        foreach ($candidates as $candidate) {
            $candidate = rawurldecode(str_replace('\\', '/', trim((string)$candidate, " \t\n\r\0\x0B\"'[](){}")));
            $path = parse_url($candidate, PHP_URL_PATH);
            $basename = strtolower(basename(is_string($path) ? $path : $candidate));
            if ($basename !== '' && isset($basenameIndex[$basename])) {
                $references[$basename] = true;
            }
        }
    };
    $visit($value);
    return $references;
}

function printSummary(array $summary, array $tableCounts, array $byDirectory): void
{
    fwrite(STDOUT, "\nDatabase rows selected: {$summary['database_rows']} in {$summary['database_tables']} tables\n");
    foreach ($tableCounts as $table => $count) {
        fwrite(STDOUT, sprintf("  %-36s %8d\n", $table, $count));
    }
    fwrite(STDOUT, "\nFile plan:\n");
    foreach ($byDirectory as $directory => $counts) {
        fwrite(STDOUT, sprintf(
            "  %-18s archive %6d / %10s | shared %4d / %10s\n",
            $directory,
            $counts['archive_files'],
            humanBytes($counts['archive_bytes']),
            $counts['shared_files'],
            humanBytes($counts['shared_bytes'])
        ));
    }
    fwrite(STDOUT, sprintf(
        "  %-18s archive %6d / %10s | shared %4d / %10s\n",
        'TOTAL',
        $summary['archive_files'],
        humanBytes($summary['archive_bytes']),
        $summary['shared_files_kept_active'],
        humanBytes($summary['shared_bytes_kept_active'])
    ));
}

function validateArchiveDirectory(string $archiveDir, string $repositoryRoot, string $platformRoot): void
{
    if (!isAbsolutePath($archiveDir)) {
        throw new RuntimeException('The archive directory must be an absolute path.');
    }
    $normalizedArchive = comparisonPath($archiveDir);
    foreach ([$repositoryRoot, $platformRoot] as $forbiddenRoot) {
        $normalizedRoot = rtrim(comparisonPath($forbiddenRoot), '/') . '/';
        if (str_starts_with($normalizedArchive . '/', $normalizedRoot)) {
            throw new RuntimeException('The archive must be outside the Git repository and deployment context.');
        }
    }
    if (is_dir($archiveDir)) {
        $contents = array_values(array_diff(scandir($archiveDir) ?: [], ['.', '..']));
        if ($contents !== []) {
            throw new RuntimeException('The archive directory already exists and is not empty. Use a new directory.');
        }
    } elseif (file_exists($archiveDir)) {
        throw new RuntimeException('The archive path exists and is not a directory.');
    }
}

function createArchive(PDO $pdo, string $driver, array $selected, array $schema, array $plan, array $summary, array $tableCounts, string $archiveDir, string $platformRoot): void
{
    ensureDirectory($archiveDir);
    $filesRoot = $archiveDir . DIRECTORY_SEPARATOR . 'files';
    ensureDirectory($filesRoot);

    $databasePath = $archiveDir . DIRECTORY_SEPARATOR . 'database-export.sql';
    writeDatabaseExport($pdo, $driver, $selected, $databasePath);

    $checksumLines = [hash_file('sha256', $databasePath) . ' *database-export.sql'];
    $copied = [];
    $copiedBytes = 0;
    $lastProgressBytes = 0;
    foreach ($plan['archive'] as $file) {
        $relative = $file['path'];
        $source = $platformRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $destinationRelative = 'files/' . $relative;
        $destination = $archiveDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $destinationRelative);
        ensureDirectory(dirname($destination));
        if (!copy($source, $destination)) {
            throw new RuntimeException("Could not copy {$relative}.");
        }
        $sourceHash = hash_file('sha256', $source);
        $destinationHash = hash_file('sha256', $destination);
        if ($sourceHash === false || $destinationHash === false || !hash_equals($sourceHash, $destinationHash)) {
            throw new RuntimeException("Checksum verification failed for {$relative}.");
        }
        $copied[] = ['path' => $relative, 'bytes' => $file['bytes'], 'sha256' => $sourceHash];
        $checksumLines[] = $sourceHash . ' *' . $destinationRelative;
        $copiedBytes += $file['bytes'];
        if ($copiedBytes - $lastProgressBytes >= 250 * 1024 * 1024) {
            fwrite(STDOUT, sprintf("Copied and verified %s of %s...\n", humanBytes($copiedBytes), humanBytes($summary['archive_bytes'])));
            $lastProgressBytes = $copiedBytes;
        }
    }

    file_put_contents($archiveDir . DIRECTORY_SEPARATOR . 'checksums.sha256', implode("\n", $checksumLines) . "\n", LOCK_EX);
    $manifest = [
        'format_version' => 1,
        'status' => 'complete',
        'created_at' => date(DATE_ATOM),
        'source' => ['platform_root' => $platformRoot],
        'user' => ['id' => $summary['user_id'], 'email' => $summary['email']],
        'database' => ['export' => 'database-export.sql', 'table_counts' => $tableCounts],
        'files' => [
            'copied_and_verified' => $copied,
            'kept_active_because_shared' => $plan['shared'],
        ],
        'summary' => $summary,
        'safety' => [
            'source_files_deleted' => false,
            'database_rows_changed' => false,
            'contains_sensitive_account_data' => true,
        ],
    ];
    file_put_contents(
        $archiveDir . DIRECTORY_SEPARATOR . 'manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
        LOCK_EX
    );
}

function writeDatabaseExport(PDO $pdo, string $driver, array $selected, string $path): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException("Could not create {$path}.");
    }
    fwrite($handle, "-- User archive generated " . date(DATE_ATOM) . "\n");
    fwrite($handle, "-- Contains password hashes and possibly integration metadata. Store securely.\n\n");
    if ($driver === 'mysql') {
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n\n");
    } else {
        fwrite($handle, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n");
    }
    foreach ($selected as $table => $rows) {
        foreach ($rows as $row) {
            $columns = array_keys($row);
            $values = array_map(static function (mixed $value) use ($pdo): string {
                if ($value === null) {
                    return 'NULL';
                }
                return $pdo->quote((string)$value);
            }, array_values($row));
            fwrite($handle, 'INSERT INTO ' . quoteIdentifier($table, $driver)
                . ' (' . implode(',', array_map(static fn(string $column): string => quoteIdentifier($column, $driver), $columns)) . ') VALUES ('
                . implode(',', $values) . ");\n");
        }
    }
    fwrite($handle, "\nCOMMIT;\n");
    if ($driver === 'mysql') {
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    } else {
        fwrite($handle, "PRAGMA foreign_keys=ON;\n");
    }
    fclose($handle);
}

function quoteIdentifier(string $identifier, string $driver): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException("Unsafe database identifier: {$identifier}");
    }
    return $driver === 'mysql' ? "`{$identifier}`" : '"' . $identifier . '"';
}

function relativePath(string $path, string $root): string
{
    $normalizedPath = normalizePath($path);
    $normalizedRoot = rtrim(normalizePath($root), '/') . '/';
    $comparisonRoot = rtrim(comparisonPath($root), '/') . '/';
    if (!str_starts_with(comparisonPath($path), $comparisonRoot)) {
        throw new RuntimeException("Path escaped the platform root: {$path}");
    }
    return substr($normalizedPath, strlen($normalizedRoot));
}

function normalizePath(string $path): string
{
    return str_replace('\\', '/', rtrim($path, "\\/"));
}

function comparisonPath(string $path): string
{
    $normalized = normalizePath($path);
    return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
}

function isAbsolutePath(string $path): bool
{
    return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '\\\\');
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
        throw new RuntimeException("Could not create directory {$path}.");
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
