<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function restore_usage(): never
{
    fwrite(STDERR, "Usage: php scripts/restore_local_user_content.php --source=DB --target=DB --source-email=EMAIL --target-email=EMAIL [--apply]\n");
    exit(2);
}

function restore_identifier(string $value): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        throw new InvalidArgumentException("Invalid SQL identifier: {$value}");
    }

    return '`' . $value . '`';
}

function restore_table_exists(PDO $pdo, string $database, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute([$database, $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function restore_columns(PDO $pdo, string $database, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([$database, $table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$options = getopt('', ['source:', 'target:', 'source-email:', 'target-email:', 'apply']);
$sourceDatabase = trim((string)($options['source'] ?? ''));
$targetDatabase = trim((string)($options['target'] ?? ''));
$sourceEmail = strtolower(trim((string)($options['source-email'] ?? '')));
$targetEmail = strtolower(trim((string)($options['target-email'] ?? '')));
$apply = array_key_exists('apply', $options);

if ($sourceDatabase === '' || $targetDatabase === '' || $sourceEmail === '' || $targetEmail === '') {
    restore_usage();
}
if (!str_contains(strtolower($targetDatabase), 'local')) {
    throw new RuntimeException('This recovery tool only writes to a database whose name contains "local".');
}
if ($sourceDatabase === $targetDatabase) {
    throw new RuntimeException('Source and target databases must be different.');
}

$host = app_env('DB_HOST', '127.0.0.1');
$port = app_env('DB_PORT', '3306');
$username = app_env('DB_USERNAME', 'root');
$password = app_env('DB_PASSWORD', '');
$charset = app_env('DB_CHARSET', 'utf8mb4');
$pdo = new PDO(
    "mysql:host={$host};port={$port};charset={$charset}",
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$sourceDb = restore_identifier($sourceDatabase);
$targetDb = restore_identifier($targetDatabase);
$userStmt = $pdo->prepare("SELECT * FROM {$sourceDb}.`users` WHERE LOWER(`email`) = ? LIMIT 1");
$userStmt->execute([$sourceEmail]);
$sourceUser = $userStmt->fetch();
$userStmt = $pdo->prepare("SELECT * FROM {$targetDb}.`users` WHERE LOWER(`email`) = ? LIMIT 1");
$userStmt->execute([$targetEmail]);
$targetUser = $userStmt->fetch();

if (!$sourceUser || !$targetUser) {
    throw new RuntimeException('The source or target user does not exist.');
}

$sourceUserId = (int)$sourceUser['id'];
$targetUserId = (int)$targetUser['id'];
$ownedContentTables = ['artworks', 'mockups', 'video_projects', 'publications', 'social_campaigns'];
foreach ($ownedContentTables as $table) {
    if (!restore_table_exists($pdo, $targetDatabase, $table)) {
        continue;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$targetDb}." . restore_identifier($table) . ' WHERE `user_id` = ?');
    $stmt->execute([$targetUserId]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new RuntimeException("Target user already has content in {$table}; refusing a duplicate restore.");
    }
}

$artworkWhere = "`artwork_id` IN (SELECT `id` FROM {$sourceDb}.`artworks` WHERE `user_id` = ?)";
$publicationWhere = "`publication_id` IN (SELECT `id` FROM {$sourceDb}.`publications` WHERE `user_id` = ?)";
$videoProjectWhere = "`video_project_id` IN (SELECT `id` FROM {$sourceDb}.`video_projects` WHERE `user_id` = ?)";
$videoSceneWhere = "`video_scene_id` IN (SELECT `id` FROM {$sourceDb}.`video_scenes` WHERE {$videoProjectWhere})";

$plan = [
    ['artist_profiles', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['artwork_series', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['artworks', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['artwork_analysis', $artworkWhere, [$sourceUserId], []],
    ['artwork_embeddings', $artworkWhere, [$sourceUserId], []],
    ['artwork_groups', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['artwork_sheets', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['mockup_contexts', $artworkWhere, [$sourceUserId], []],
    ['mockups', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['mockup_generation_jobs', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['mockup_sheets', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['root_artwork_candidates', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['publications', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['publication_items', $publicationWhere, [$sourceUserId], []],
    ['channel_variants', $publicationWhere, [$sourceUserId], []],
    ['distribution_jobs', $publicationWhere, [$sourceUserId], []],
    ['social_campaigns', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['social_channel_drafts', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['social_publication_plans', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['social_publish_jobs', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['pinterest_pin_drafts', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['pinterest_pin_destinations', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['video_reference_assets', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['video_projects', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['video_scenes', $videoProjectWhere, [$sourceUserId], []],
    ['video_scene_references', $videoSceneWhere, [$sourceUserId], []],
    ['video_generation_jobs', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
    ['video_exports', '`user_id` = ?', [$sourceUserId], ['user_id' => $targetUserId]],
];

$work = [];
foreach ($plan as [$table, $where, $whereParams, $overrides]) {
    if (!restore_table_exists($pdo, $sourceDatabase, $table) || !restore_table_exists($pdo, $targetDatabase, $table)) {
        continue;
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$sourceDb}." . restore_identifier($table) . " WHERE {$where}");
    $countStmt->execute($whereParams);
    $count = (int)$countStmt->fetchColumn();
    if ($count === 0) {
        continue;
    }

    $sourceColumns = restore_columns($pdo, $sourceDatabase, $table);
    $targetColumns = restore_columns($pdo, $targetDatabase, $table);
    $columns = array_values(array_intersect($targetColumns, $sourceColumns));
    if (!in_array('id', $columns, true)) {
        throw new RuntimeException("Expected an id column in {$table}.");
    }

    $collisionStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM {$targetDb}." . restore_identifier($table) . " t "
        . "WHERE t.`id` IN (SELECT `id` FROM {$sourceDb}." . restore_identifier($table) . " WHERE {$where})"
    );
    $collisionStmt->execute($whereParams);
    if ((int)$collisionStmt->fetchColumn() > 0) {
        throw new RuntimeException("ID collision detected in {$table}; refusing the restore.");
    }

    $work[] = compact('table', 'where', 'whereParams', 'overrides', 'columns', 'count');
    echo sprintf("%-32s %d\n", $table, $count);
}

echo 'Total selected rows: ' . array_sum(array_column($work, 'count')) . PHP_EOL;
if (!$apply) {
    echo "Dry run only. Add --apply after reviewing the inventory.\n";
    exit(0);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->beginTransaction();
try {
    foreach ($work as $item) {
        $insertColumns = [];
        $selectColumns = [];
        $selectParams = [];
        foreach ($item['columns'] as $column) {
            $insertColumns[] = restore_identifier($column);
            if (array_key_exists($column, $item['overrides'])) {
                $selectColumns[] = '? AS ' . restore_identifier($column);
                $selectParams[] = $item['overrides'][$column];
            } else {
                $selectColumns[] = restore_identifier($column);
            }
        }

        $sql = "INSERT INTO {$targetDb}." . restore_identifier($item['table'])
            . ' (' . implode(', ', $insertColumns) . ') '
            . 'SELECT ' . implode(', ', $selectColumns)
            . " FROM {$sourceDb}." . restore_identifier($item['table'])
            . ' WHERE ' . $item['where'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($selectParams, $item['whereParams']));
        if ($stmt->rowCount() !== $item['count']) {
            throw new RuntimeException("Unexpected inserted row count for {$item['table']}.");
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

echo "Restore completed successfully.\n";
