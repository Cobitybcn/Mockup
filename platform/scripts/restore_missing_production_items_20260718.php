<?php
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$password = (string)getenv('PROD_DB_PASSWORD');
if ($password === '') {
    throw new RuntimeException('PROD_DB_PASSWORD is required.');
}

$source = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=artwork_mockups_faithful_clone;charset=utf8mb4',
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$target = new PDO(
    'mysql:host=127.0.0.1;port=13306;dbname=mockups;charset=utf8mb4',
    'mockups_app',
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$sourceUser = $source->query("SELECT id,email FROM users WHERE id=23")->fetch();
$targetUser = $target->query("SELECT id,email FROM users WHERE id=2")->fetch();
if (($sourceUser['email'] ?? '') !== 'mauriziovalch@gmail.com' || ($targetUser['email'] ?? '') !== 'mauriziovalch@gmail.com') {
    throw new RuntimeException('Maurizio user identity did not match the reviewed source and target IDs.');
}

$plan = [
    ['table' => 'artworks', 'where' => 'id = 10077', 'expected' => 1, 'rewrite_user' => true],
    ['table' => 'artwork_groups', 'where' => 'id = 135', 'expected' => 1, 'rewrite_user' => true],
    ['table' => 'artwork_sheets', 'where' => 'id = 105', 'expected' => 1, 'rewrite_user' => true],
    ['table' => 'mockups', 'where' => 'id BETWEEN 2724 AND 2729', 'expected' => 6, 'rewrite_user' => true],
    ['table' => 'mockup_generation_jobs', 'where' => 'id BETWEEN 1264 AND 1268', 'expected' => 5, 'rewrite_user' => true],
    ['table' => 'mockup_sheets', 'where' => 'id BETWEEN 1403 AND 1407', 'expected' => 5, 'rewrite_user' => true],
    ['table' => 'video_projects', 'where' => 'id = 137', 'expected' => 1, 'rewrite_user' => true],
    ['table' => 'video_scenes', 'where' => 'video_project_id = 137', 'expected' => 3, 'rewrite_user' => false],
    ['table' => 'video_exports', 'where' => 'video_project_id = 137', 'expected' => 1, 'rewrite_user' => true],
];

function repair_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([$table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$selected = [];
foreach ($plan as $item) {
    $table = $item['table'];
    $rows = $source->query("SELECT * FROM `{$table}` WHERE {$item['where']} ORDER BY id")->fetchAll();
    if (count($rows) !== $item['expected']) {
        throw new RuntimeException("Unexpected source row count for {$table}.");
    }
    if ($item['rewrite_user']) {
        foreach ($rows as $row) {
            if ((int)($row['user_id'] ?? 0) !== 23) {
                throw new RuntimeException("Unexpected source owner in {$table}.");
            }
        }
    }

    $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $collision = $target->prepare("SELECT id FROM `{$table}` WHERE id IN ({$marks}) ORDER BY id");
    $collision->execute($ids);
    $existingIds = $collision->fetchAll(PDO::FETCH_COLUMN);
    if ($existingIds !== []) {
        throw new RuntimeException("Target ID collision in {$table}: " . implode(',', $existingIds));
    }

    $sourceColumns = repair_columns($source, $table);
    $targetColumns = repair_columns($target, $table);
    $columns = array_values(array_intersect($sourceColumns, $targetColumns));
    $selected[] = $item + ['rows' => $rows, 'columns' => $columns];
    echo sprintf("%-28s %d [%s]\n", $table, count($rows), implode(',', $ids));
}

echo 'Total selected rows: ' . array_sum(array_column($plan, 'expected')) . PHP_EOL;
if (!$apply) {
    echo "Dry run only. Add --apply to perform the reviewed inserts.\n";
    exit(0);
}

$target->exec('SET FOREIGN_KEY_CHECKS = 0');
$target->beginTransaction();
try {
    foreach ($selected as $item) {
        $columnSql = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $item['columns']));
        $marks = implode(', ', array_fill(0, count($item['columns']), '?'));
        $insert = $target->prepare("INSERT INTO `{$item['table']}` ({$columnSql}) VALUES ({$marks})");

        foreach ($item['rows'] as $row) {
            if ($item['rewrite_user']) {
                $row['user_id'] = 2;
            }
            foreach ($row as $key => $value) {
                if (is_string($value)) {
                    $row[$key] = str_replace(
                        ['storage/video/finals/23/137/'],
                        ['storage/video/finals/2/137/'],
                        $value
                    );
                }
            }
            $insert->execute(array_map(static fn(string $column) => $row[$column] ?? null, $item['columns']));
        }
    }
    $target->commit();
} catch (Throwable $e) {
    if ($target->inTransaction()) {
        $target->rollBack();
    }
    throw $e;
} finally {
    $target->exec('SET FOREIGN_KEY_CHECKS = 1');
}

echo "Production restore transaction committed.\n";
