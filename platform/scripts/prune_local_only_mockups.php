<?php
declare(strict_types=1);

/**
 * Borra en LOCAL los mockups (y todo lo que depende de ellos) que no
 * existen en produccion, para que el catalogo local sea un espejo exacto
 * del catalogo curado en produccion. Solo opera sobre datos locales, no
 * toca produccion. Dry-run por defecto; --apply para borrar de verdad.
 *
 * Uso:
 *   php scripts/prune_local_only_mockups.php --user-email=mauriziovalch@gmail.com \
 *       --source-host=127.0.0.1 --source-port=3307 [--apply]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['user-email:', 'source-host:', 'source-port::', 'source-database::', 'source-username::', 'apply']);
$email = strtolower(trim((string)($options['user-email'] ?? '')));
$sourceHost = trim((string)($options['source-host'] ?? ''));
$sourcePort = max(1, (int)($options['source-port'] ?? 3307));
$sourceDatabase = trim((string)($options['source-database'] ?? 'mockups'));
$sourceUsername = trim((string)($options['source-username'] ?? 'mockups_app'));
$sourcePassword = (string)(getenv('PRODUCTION_IMPORT_SOURCE_PASSWORD') ?: '');
$apply = array_key_exists('apply', $options);

if ($email === '' || $sourceHost === '' || $sourcePassword === '') {
    fwrite(STDERR, "Missing required options/env.\n");
    exit(1);
}
if (app_env('APP_ENV', '') !== 'local') {
    fwrite(STDERR, "Safety stop: APP_ENV must be local.\n");
    exit(1);
}

$target = Database::connection();
$targetDbName = (string)$target->query('SELECT DATABASE()')->fetchColumn();
if (stripos($targetDbName, 'local') === false) {
    fwrite(STDERR, "Safety stop: target database '{$targetDbName}' is not local.\n");
    exit(1);
}

$source = new PDO(
    "mysql:host={$sourceHost};port={$sourcePort};dbname={$sourceDatabase};charset=utf8mb4",
    $sourceUsername,
    $sourcePassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$targetUserStmt = $target->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$targetUserStmt->execute([$email]);
$targetUserId = (int)($targetUserStmt->fetchColumn() ?: 0);
$sourceUserStmt = $source->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$sourceUserStmt->execute([$email]);
$sourceUserId = (int)($sourceUserStmt->fetchColumn() ?: 0);
if ($targetUserId <= 0 || $sourceUserId <= 0) {
    fwrite(STDERR, "User not found on one side.\n");
    exit(1);
}

echo "Mode: " . ($apply ? 'APPLY (deleting from local)' : 'DRY RUN (no deletes)') . "\n";

// Production's mockup_file set for this user (read-only).
$prodFiles = [];
$stmt = $source->prepare('SELECT mockup_file FROM mockups WHERE user_id = ?');
$stmt->execute([$sourceUserId]);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $f) { $prodFiles[$f] = true; }

$localStmt = $target->prepare('SELECT id, mockup_file, prompt_file FROM mockups WHERE user_id = ?');
$localStmt->execute([$targetUserId]);
$extraIds = [];
$extraFiles = [];
$promptFiles = [];
foreach ($localStmt->fetchAll() as $row) {
    if (!isset($prodFiles[$row['mockup_file']])) {
        $extraIds[] = (int)$row['id'];
        $extraFiles[] = (string)$row['mockup_file'];
        if ((string)$row['prompt_file'] !== '') { $promptFiles[] = (string)$row['prompt_file']; }
    }
}

if (!$extraIds) {
    echo "Nothing to prune; local already matches production.\n";
    exit(0);
}

echo count($extraIds) . " local-only mockups to remove.\n";

$target->beginTransaction();
try {
    $marks = implode(',', array_fill(0, count($extraIds), '?'));
    $fileMarks = implode(',', array_fill(0, count($extraFiles), '?'));

    $counts = [];

    // publication_items depending on mockup_sheets that reference these files
    $stmt = $target->prepare("SELECT pi.id FROM publication_items pi JOIN mockup_sheets ms ON ms.id = pi.mockup_sheet_id WHERE ms.user_id = ? AND ms.mockup_file IN ({$fileMarks})");
    $stmt->execute(array_merge([$targetUserId], $extraFiles));
    $pubItemIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $counts['publication_items'] = count($pubItemIds);
    if ($apply && $pubItemIds) {
        $pm = implode(',', array_fill(0, count($pubItemIds), '?'));
        $target->prepare("DELETE FROM publication_items WHERE id IN ({$pm})")->execute($pubItemIds);
    }

    // video_scene_references pointing at these mockups
    $stmt = $target->prepare("SELECT id FROM video_scene_references WHERE source_type='mockup' AND source_id IN ({$marks})");
    $stmt->execute($extraIds);
    $sceneRefIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $counts['video_scene_references'] = count($sceneRefIds);
    if ($apply && $sceneRefIds) {
        $sm = implode(',', array_fill(0, count($sceneRefIds), '?'));
        $target->prepare("DELETE FROM video_scene_references WHERE id IN ({$sm})")->execute($sceneRefIds);
    }

    // mockup_sheets referencing these mockup files
    $stmt = $target->prepare("SELECT COUNT(*) FROM mockup_sheets WHERE user_id = ? AND mockup_file IN ({$fileMarks})");
    $stmt->execute(array_merge([$targetUserId], $extraFiles));
    $counts['mockup_sheets'] = (int)$stmt->fetchColumn();
    if ($apply) {
        $target->prepare("DELETE FROM mockup_sheets WHERE user_id = ? AND mockup_file IN ({$fileMarks})")->execute(array_merge([$targetUserId], $extraFiles));
    }

    // mockup_generation_jobs (historical logs) referencing these mockup files
    $stmt = $target->prepare("SELECT COUNT(*) FROM mockup_generation_jobs WHERE user_id = ? AND mockup_file IN ({$fileMarks})");
    $stmt->execute(array_merge([$targetUserId], $extraFiles));
    $counts['mockup_generation_jobs'] = (int)$stmt->fetchColumn();
    if ($apply) {
        $target->prepare("DELETE FROM mockup_generation_jobs WHERE user_id = ? AND mockup_file IN ({$fileMarks})")->execute(array_merge([$targetUserId], $extraFiles));
    }

    // the mockups themselves
    $counts['mockups'] = count($extraIds);
    if ($apply) {
        $target->prepare("DELETE FROM mockups WHERE id IN ({$marks})")->execute($extraIds);
    }

    if ($apply) {
        $target->commit();
    } else {
        $target->rollBack();
    }

    echo json_encode(['mode' => $apply ? 'applied' : 'dry-run', 'deleted' => $counts, 'extra_mockup_ids' => $extraIds], JSON_PRETTY_PRINT) . "\n";

    if ($apply) {
        $resultsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'results';
        $promptsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mockup-prompts';
        $filesDeleted = 0;
        foreach ($extraFiles as $f) {
            $p = $resultsDir . DIRECTORY_SEPARATOR . basename($f);
            if (is_file($p) && @unlink($p)) { $filesDeleted++; }
        }
        $promptsDeleted = 0;
        foreach (array_unique($promptFiles) as $f) {
            $p = $promptsDir . DIRECTORY_SEPARATOR . basename($f);
            if (is_file($p) && @unlink($p)) { $promptsDeleted++; }
        }
        echo "Files deleted: results={$filesDeleted} prompts={$promptsDeleted}\n";
    }
} catch (Throwable $e) {
    if ($target->inTransaction()) { $target->rollBack(); }
    fwrite(STDERR, "Failed: " . $e->getMessage() . "\n");
    exit(1);
}
