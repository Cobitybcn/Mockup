<?php
declare(strict_types=1);

/**
 * Merge the user-owned state captured by archive_user.php into another MySQL
 * database without replacing target-only users or rows.
 *
 * The manifest root must contain one or more complete user archives. The
 * manifests define both the selected users and the set of user-owned tables.
 * Existing users are matched by email. Conflicting auto-increment IDs are
 * reassigned and child foreign keys are updated in the same transaction.
 *
 * Dry run (transaction is rolled back):
 *   TARGET_DB_PASSWORD=... php scripts/sync_current_app_to_mysql.php \
 *     --manifest-root=C:\archives\current-app \
 *     --target-database=mockups_restore_test
 *
 * Apply:
 *   TARGET_DB_PASSWORD=... php scripts/sync_current_app_to_mysql.php \
 *     --manifest-root=C:\archives\current-app \
 *     --target-database=mockups --apply --confirm-target=mockups
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/app/Support/Database.php';

$options = getopt('', [
    'manifest-root:',
    'target-host:',
    'target-port:',
    'target-database:',
    'target-username:',
    'allow-empty-target-password',
    'apply',
    'confirm-target:',
    'help',
]);

if (isset($options['help'])) {
    usage();
    exit(0);
}

$manifestRoot = trim((string)($options['manifest-root'] ?? ''));
$targetHost = trim((string)($options['target-host'] ?? '127.0.0.1'));
$targetPort = trim((string)($options['target-port'] ?? '3307'));
$targetDatabase = trim((string)($options['target-database'] ?? 'mockups'));
$targetUsername = trim((string)($options['target-username'] ?? 'mockups_app'));
$targetPasswordEnv = getenv('TARGET_DB_PASSWORD');
$targetPassword = $targetPasswordEnv === false ? '' : (string)$targetPasswordEnv;
$apply = array_key_exists('apply', $options);
$confirmation = trim((string)($options['confirm-target'] ?? ''));

if ($manifestRoot === '' || !is_dir($manifestRoot)) {
    fail('Provide an existing --manifest-root directory.');
}
foreach ([$targetHost, $targetPort, $targetDatabase, $targetUsername] as $required) {
    if ($required === '') {
        fail('Target connection parameters cannot be empty.');
    }
}
if ($targetPasswordEnv === false && !array_key_exists('allow-empty-target-password', $options)) {
    fail('TARGET_DB_PASSWORD is required.');
}
if ($apply && !hash_equals($targetDatabase, $confirmation)) {
    fail("Applying requires --confirm-target={$targetDatabase}.");
}

try {
    [$emails, $selectedTables] = readArchiveScope($manifestRoot);
    $source = Database::connection();
    if ((string)$source->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The current source database must be MySQL.');
    }
    $target = new PDO(
        "mysql:host={$targetHost};port={$targetPort};dbname={$targetDatabase};charset=utf8mb4",
        $targetUsername,
        $targetPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $sourceSchema = inspectSchema($source);
    $targetSchema = inspectSchema($target);
    validateScope($emails, $selectedTables, $sourceSchema, $targetSchema, $source);
    $relations = selectedRelations($sourceSchema['relations'], $selectedTables);
    $tableOrder = dependencyOrder($selectedTables, $relations);

    $target->beginTransaction();
    try {
        $report = mergeUsersAndRows(
            $source,
            $target,
            $emails,
            $tableOrder,
            $relations,
            $sourceSchema,
            $targetSchema
        );
        if ($apply) {
            $target->commit();
        } else {
            $target->rollBack();
        }
    } catch (Throwable $e) {
        if ($target->inTransaction()) {
            $target->rollBack();
        }
        throw $e;
    }

    printReport($report, $emails, $targetDatabase, $apply);
} catch (Throwable $e) {
    fail($e->getMessage());
}

function usage(): void
{
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  TARGET_DB_PASSWORD=... php scripts/sync_current_app_to_mysql.php --manifest-root=<absolute-path> --target-database=<database>\n");
    fwrite(STDOUT, "  Add --apply --confirm-target=<database> to commit. The default is rollback-only.\n");
}

function fail(string $message): never
{
    fwrite(STDERR, "Current-app sync failed: {$message}\n");
    exit(1);
}

/** @return array{0:array<int,string>,1:array<int,string>} */
function readArchiveScope(string $root): array
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $emails = [];
    $tables = [];
    $manifestCount = 0;
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getFilename()) !== 'manifest.json') {
            continue;
        }
        $manifest = json_decode((string)file_get_contents($file->getPathname()), true, 128, JSON_THROW_ON_ERROR);
        if (($manifest['status'] ?? '') !== 'complete') {
            throw new RuntimeException("Incomplete archive manifest: {$file->getPathname()}");
        }
        $email = strtolower(trim((string)($manifest['user']['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Invalid archive user email in {$file->getPathname()}");
        }
        $emails[$email] = true;
        foreach (array_keys((array)($manifest['database']['table_counts'] ?? [])) as $table) {
            assertIdentifier((string)$table);
            $tables[(string)$table] = true;
        }
        $manifestCount++;
    }
    if ($manifestCount === 0 || $emails === [] || $tables === []) {
        throw new RuntimeException('No complete archive manifests were found.');
    }
    $tables['users'] = true;
    $emailList = array_keys($emails);
    $tableList = array_keys($tables);
    sort($emailList);
    sort($tableList);
    return [$emailList, $tableList];
}

/** @return array{tables:array<string,array{columns:array<int,string>,primary:array<int,string>,auto_increment:?string}>,relations:array<int,array{child:string,child_column:string,parent:string,parent_column:string}>} */
function inspectSchema(PDO $pdo): array
{
    $tables = [];
    $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $columnStmt = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_KEY,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $columnStmt->execute([$table]);
        $columns = [];
        $primary = [];
        $autoIncrement = null;
        foreach ($columnStmt as $column) {
            $name = (string)$column['COLUMN_NAME'];
            $columns[] = $name;
            if ((string)$column['COLUMN_KEY'] === 'PRI') {
                $primary[] = $name;
            }
            if (str_contains((string)$column['EXTRA'], 'auto_increment')) {
                $autoIncrement = $name;
            }
        }
        $tables[(string)$table] = [
            'columns' => $columns,
            'primary' => $primary,
            'auto_increment' => $autoIncrement,
        ];
    }

    $relations = [];
    $relationStmt = $pdo->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL");
    foreach ($relationStmt as $relation) {
        $relations[] = [
            'child' => (string)$relation['TABLE_NAME'],
            'child_column' => (string)$relation['COLUMN_NAME'],
            'parent' => (string)$relation['REFERENCED_TABLE_NAME'],
            'parent_column' => (string)$relation['REFERENCED_COLUMN_NAME'],
        ];
    }
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

function validateScope(array $emails, array $tables, array $sourceSchema, array $targetSchema, PDO $source): void
{
    foreach ($tables as $table) {
        if (!isset($sourceSchema['tables'][$table])) {
            throw new RuntimeException("Archive table {$table} is missing from the source schema.");
        }
        if (!isset($targetSchema['tables'][$table])) {
            throw new RuntimeException("Target schema is missing table {$table}; deploy schema migrations first.");
        }
        $missingColumns = array_diff($sourceSchema['tables'][$table]['columns'], $targetSchema['tables'][$table]['columns']);
        if ($missingColumns !== []) {
            throw new RuntimeException("Target table {$table} is missing columns: " . implode(', ', $missingColumns));
        }
        if ($sourceSchema['tables'][$table]['primary'] === []) {
            throw new RuntimeException("Selected table {$table} has no primary key.");
        }
    }

    $placeholders = implode(',', array_fill(0, count($emails), '?'));
    $stmt = $source->prepare("SELECT LOWER(email) FROM users WHERE LOWER(email) IN ({$placeholders})");
    $stmt->execute($emails);
    $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
    sort($found);
    $expected = $emails;
    sort($expected);
    if ($found !== $expected) {
        throw new RuntimeException('The source users do not exactly match the archive manifests.');
    }

    $ownerColumns = ['user_id', 'created_by_user_id', 'actor_user_id'];
    $sourceIdsStmt = $source->prepare("SELECT id FROM users WHERE LOWER(email) IN ({$placeholders})");
    $sourceIdsStmt->execute($emails);
    $sourceIds = array_map('intval', $sourceIdsStmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ($tables as $table) {
        if ($table === 'users') {
            continue;
        }
        $matching = array_values(array_intersect($ownerColumns, $sourceSchema['tables'][$table]['columns']));
        if ($matching === []) {
            continue;
        }
        $conditions = [];
        $params = [];
        foreach ($matching as $column) {
            $conditions[] = '`' . $column . '` NOT IN (' . implode(',', array_fill(0, count($sourceIds), '?')) . ')';
            array_push($params, ...$sourceIds);
        }
        $check = $source->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . implode(' OR ', $conditions));
        $check->execute($params);
        if ((int)$check->fetchColumn() > 0) {
            throw new RuntimeException("Selected table {$table} contains rows owned by a user outside the archive scope.");
        }
    }
}

function selectedRelations(array $relations, array $tables): array
{
    $selected = array_flip($tables);
    return array_values(array_filter($relations, static fn(array $relation): bool => isset($selected[$relation['child']], $selected[$relation['parent']])));
}

/** @return array<int,string> */
function dependencyOrder(array $tables, array $relations): array
{
    $selected = array_flip($tables);
    unset($selected['users']);
    $dependencies = array_fill_keys(array_keys($selected), []);
    foreach ($relations as $relation) {
        if ($relation['child'] === 'users' || $relation['parent'] === 'users' || $relation['child'] === $relation['parent']) {
            continue;
        }
        $dependencies[$relation['child']][$relation['parent']] = true;
    }
    $ordered = [];
    while ($dependencies !== []) {
        $ready = [];
        foreach ($dependencies as $table => $parents) {
            $remaining = array_diff_key($parents, array_flip($ordered));
            if ($remaining === []) {
                $ready[] = $table;
            }
        }
        if ($ready === []) {
            // Foreign-key cycles are rare here. Stable ordering still works when
            // their IDs do not need remapping; a needed unresolved map fails later.
            $ready[] = array_key_first($dependencies);
        }
        sort($ready);
        foreach ($ready as $table) {
            $ordered[] = $table;
            unset($dependencies[$table]);
        }
    }
    return $ordered;
}

function mergeUsersAndRows(PDO $source, PDO $target, array $emails, array $tableOrder, array $relations, array $sourceSchema, array $targetSchema): array
{
    $report = [];
    $context = 'initialization';
    try {
    $idMaps = ['users' => []];
    $existingSourceUsers = [];
    $placeholders = implode(',', array_fill(0, count($emails), '?'));
    $sourceUsersStmt = $source->prepare("SELECT * FROM users WHERE LOWER(email) IN ({$placeholders}) ORDER BY id");
    $sourceUsersStmt->execute($emails);

    foreach ($sourceUsersStmt as $row) {
        $context = 'users email=' . (string)$row['email'];
        $sourceId = (int)$row['id'];
        $find = $target->prepare('SELECT * FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1');
        $find->execute([(string)$row['email']]);
        $existing = $find->fetch();
        if ($existing) {
            $targetId = (int)$existing['id'];
            $existingSourceUsers[$sourceId] = true;
            $update = $row;
            unset($update['id'], $update['email'], $update['password_hash'], $update['created_at']);
            updateRow($target, 'users', $update, ['id' => $targetId]);
            bump($report, 'users', 'updated');
        } else {
            $candidate = findByPrimary($target, 'users', ['id' => $sourceId]);
            if ($candidate) {
                unset($row['id']);
            }
            insertRow($target, 'users', $row);
            $targetId = isset($row['id']) ? $sourceId : (int)$target->lastInsertId();
            bump($report, 'users', 'inserted');
        }
        $idMaps['users'][(string)$sourceId] = $targetId;
    }

    $relationsByChild = [];
    foreach ($relations as $relation) {
        $relationsByChild[$relation['child']][] = $relation;
    }
    $ownerColumns = ['user_id', 'created_by_user_id', 'actor_user_id'];

    foreach ($tableOrder as $table) {
        $meta = $sourceSchema['tables'][$table];
        $primary = $meta['primary'];
        $autoIncrement = $targetSchema['tables'][$table]['auto_increment'];
        $rows = $source->query('SELECT * FROM `' . $table . '`')->fetchAll();
        foreach ($rows as $sourceRow) {
            $context = $table . ' primary=' . json_encode(
                primaryValues($sourceRow, $primary),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if ($table === 'credit_transactions') {
                $sourceUserId = (int)($sourceRow['user_id'] ?? 0);
                if (isset($existingSourceUsers[$sourceUserId])) {
                    bump($report, $table, 'preserved_target');
                    continue;
                }
            }

            $row = $sourceRow;
            if ($table === 'root_artwork_candidates' && !array_key_exists('user_id', $row)) {
                $artworkStmt = $source->prepare('SELECT user_id,job_id,updated_at FROM artworks WHERE id=? LIMIT 1');
                $artworkStmt->execute([$sourceRow['artwork_id'] ?? null]);
                $artwork = $artworkStmt->fetch();
                if (!$artwork) {
                    throw new RuntimeException('Could not resolve the owning artwork.');
                }
                $row['user_id'] = $artwork['user_id'];
                $row['job_id'] = (string)($artwork['job_id'] ?? '');
                $row['updated_at'] = (string)($artwork['updated_at'] ?? $sourceRow['created_at'] ?? date(DATE_ATOM));
            }
            foreach ($ownerColumns as $ownerColumn) {
                if (array_key_exists($ownerColumn, $row) && $row[$ownerColumn] !== null && $row[$ownerColumn] !== '') {
                    $key = (string)$row[$ownerColumn];
                    if (!isset($idMaps['users'][$key])) {
                        throw new RuntimeException("No user ID mapping for {$table}.{$ownerColumn}={$key}");
                    }
                    $row[$ownerColumn] = $idMaps['users'][$key];
                }
            }
            foreach ($relationsByChild[$table] ?? [] as $relation) {
                $column = $relation['child_column'];
                if (!array_key_exists($column, $row) || $row[$column] === null || $row[$column] === '') {
                    continue;
                }
                $parent = $relation['parent'];
                $oldValue = (string)$row[$column];
                if (isset($idMaps[$parent][$oldValue])) {
                    $row[$column] = $idMaps[$parent][$oldValue];
                }
            }

            $sourcePrimary = primaryValues($sourceRow, $primary);
            $candidatePrimary = primaryValues($row, $primary);
            $existing = findByPrimary($target, $table, $candidatePrimary);
            $mustReassign = false;
            if ($existing) {
                foreach (array_intersect($ownerColumns, array_keys($row)) as $ownerColumn) {
                    if ((string)($existing[$ownerColumn] ?? '') !== (string)($row[$ownerColumn] ?? '')) {
                        $mustReassign = true;
                        break;
                    }
                }
                if (!$mustReassign) {
                    foreach ($relationsByChild[$table] ?? [] as $relation) {
                        $column = $relation['child_column'];
                        if (isset($idMaps[$relation['parent']])
                            && (string)($existing[$column] ?? '') !== (string)($row[$column] ?? '')) {
                            $mustReassign = true;
                            break;
                        }
                    }
                }
            }

            if ($mustReassign) {
                if (count($primary) !== 1 || $autoIncrement !== $primary[0]) {
                    throw new RuntimeException("Cannot safely reassign conflicting primary key in {$table}.");
                }
                unset($row[$primary[0]]);
                insertRow($target, $table, $row);
                $targetPrimaryValue = (int)$target->lastInsertId();
                $idMaps[$table][(string)reset($sourcePrimary)] = $targetPrimaryValue;
                bump($report, $table, 'remapped');
                continue;
            }

            if ($existing) {
                $update = $row;
                foreach ($primary as $column) {
                    unset($update[$column]);
                }
                updateRow($target, $table, $update, $candidatePrimary);
                bump($report, $table, 'updated');
                $targetPrimary = $candidatePrimary;
            } else {
                insertRow($target, $table, $row);
                bump($report, $table, 'inserted');
                $targetPrimary = $candidatePrimary;
            }
            if (count($primary) === 1) {
                $idMaps[$table][(string)reset($sourcePrimary)] = reset($targetPrimary);
            }
        }
    }
    return $report;
    } catch (Throwable $error) {
        throw new RuntimeException("{$context}: {$error->getMessage()}", 0, $error);
    }
}

function primaryValues(array $row, array $primary): array
{
    $values = [];
    foreach ($primary as $column) {
        if (!array_key_exists($column, $row)) {
            throw new RuntimeException("Missing primary key column {$column}.");
        }
        $values[$column] = $row[$column];
    }
    return $values;
}

function findByPrimary(PDO $pdo, string $table, array $primary): array|false
{
    $conditions = [];
    foreach (array_keys($primary) as $column) {
        $conditions[] = '`' . assertIdentifier($column) . '` <=> ?';
    }
    $stmt = $pdo->prepare('SELECT * FROM `' . assertIdentifier($table) . '` WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1');
    $stmt->execute(array_values($primary));
    return $stmt->fetch();
}

function insertRow(PDO $pdo, string $table, array $row): void
{
    if ($row === []) {
        throw new RuntimeException("Cannot insert an empty row into {$table}.");
    }
    $columns = array_keys($row);
    $sql = 'INSERT INTO `' . assertIdentifier($table) . '` ('
        . implode(',', array_map(static fn(string $column): string => '`' . assertIdentifier($column) . '`', $columns))
        . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($row));
}

function updateRow(PDO $pdo, string $table, array $row, array $primary): void
{
    if ($row === []) {
        return;
    }
    $sets = array_map(static fn(string $column): string => '`' . assertIdentifier($column) . '`=?', array_keys($row));
    $where = array_map(static fn(string $column): string => '`' . assertIdentifier($column) . '` <=> ?', array_keys($primary));
    $stmt = $pdo->prepare('UPDATE `' . assertIdentifier($table) . '` SET ' . implode(',', $sets) . ' WHERE ' . implode(' AND ', $where));
    $stmt->execute(array_merge(array_values($row), array_values($primary)));
}

function assertIdentifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException("Unsafe SQL identifier: {$identifier}");
    }
    return $identifier;
}

function bump(array &$report, string $table, string $action): void
{
    $report[$table] ??= ['inserted' => 0, 'updated' => 0, 'remapped' => 0, 'preserved_target' => 0];
    $report[$table][$action]++;
}

function printReport(array $report, array $emails, string $targetDatabase, bool $applied): void
{
    ksort($report);
    fwrite(STDOUT, "\nCurrent-app merge " . ($applied ? 'APPLIED' : 'DRY RUN (ROLLED BACK)') . " to {$targetDatabase}\n");
    fwrite(STDOUT, 'Users: ' . implode(', ', $emails) . "\n\n");
    fwrite(STDOUT, sprintf("%-36s %10s %10s %10s %12s\n", 'Table', 'Inserted', 'Updated', 'Remapped', 'Preserved'));
    foreach ($report as $table => $counts) {
        fwrite(STDOUT, sprintf(
            "%-36s %10d %10d %10d %12d\n",
            $table,
            $counts['inserted'],
            $counts['updated'],
            $counts['remapped'],
            $counts['preserved_target']
        ));
    }
}
