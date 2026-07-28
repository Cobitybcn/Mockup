<?php
declare(strict_types=1);

/**
 * Corrige las referencias de imagen embebidas en las Notas de Estudio
 * importadas: el import (production -> local) remapea correctamente la
 * columna entity_id/note_id, pero el texto (content_json/published_content_json)
 * sigue nombrando los archivos "studio-note-{userId}-{PROD_note_id}-{hash}.ext".
 * Este script:
 *   1) Reconstruye el mapeo prod_note_id -> local_note_id (mismo match que el
 *      import: title+source_type+source_id+created_at).
 *   2) Reescribe esas referencias en bilingual_editorial_content y
 *      studio_note_workspace_items para usar el note_id local.
 *   3) Copia (solo lectura sobre produccion) cada archivo de imagen desde el
 *      bucket de produccion al nombre local correcto.
 *
 * Dry-run por defecto; solo con --apply escribe.
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
function readOnlyQuery(PDO $pdo, string $sql, array $params = []): array
{
    if (!preg_match('/^\s*SELECT/i', $sql)) {
        throw new RuntimeException('Only SELECT is allowed against the source connection.');
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$sourceUserStmt = $source->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$sourceUserStmt->execute([$email]);
$sourceUserId = (int)($sourceUserStmt->fetchColumn() ?: 0);
$targetUserStmt = $target->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$targetUserStmt->execute([$email]);
$targetUserId = (int)($targetUserStmt->fetchColumn() ?: 0);
if ($sourceUserId <= 0 || $targetUserId <= 0) {
    fwrite(STDERR, "User not found on one side (source={$sourceUserId} target={$targetUserId}).\n");
    exit(1);
}

// Rebuild prod_note_id -> local_note_id using the same matching logic as the importer.
$noteFields = ['title', 'source_type', 'source_id', 'created_at'];
$sourceNotes = readOnlyQuery($source, "SELECT id," . implode(',', $noteFields) . " FROM social_campaigns WHERE user_id=? AND campaign_type='website_blog'", [$sourceUserId]);
$targetNotesStmt = $target->prepare("SELECT id," . implode(',', $noteFields) . " FROM social_campaigns WHERE user_id=? AND campaign_type='website_blog'");
$targetNotesStmt->execute([$targetUserId]);
$targetByLegacy = [];
foreach ($targetNotesStmt->fetchAll() as $row) {
    $legacy = hash('sha256', implode('|', [(string)$row['title'], (string)$row['source_type'], (string)$row['source_id'], (string)$row['created_at']]));
    $targetByLegacy[$legacy] = (int)$row['id'];
}
$noteMap = [];
foreach ($sourceNotes as $row) {
    $legacy = hash('sha256', implode('|', [(string)$row['title'], (string)$row['source_type'], (string)$row['source_id'], (string)$row['created_at']]));
    if (isset($targetByLegacy[$legacy])) {
        $noteMap[(int)$row['id']] = $targetByLegacy[$legacy];
    }
}
fwrite(STDERR, "Note map (prod -> local): " . json_encode($noteMap) . "\n");

if (!$noteMap) {
    fwrite(STDERR, "No notes matched; nothing to do.\n");
    exit(0);
}

$filePattern = '/studio-note-' . preg_quote((string)$targetUserId, '/') . '-(\d+)-([a-f0-9]{20})\.(jpg|png|webp)/i';
$noteParamPattern = '/(studio_note_media\.php\?note=)(\d+)/i';
$filesToCopy = []; // oldFile => newFile

$rewrite = function (string $text) use ($filePattern, $noteParamPattern, $noteMap, $targetUserId, &$filesToCopy): array {
    $changed = false;
    $newText = preg_replace_callback($filePattern, function (array $m) use ($noteMap, $targetUserId, &$filesToCopy, &$changed) {
        $prodNoteId = (int)$m[1];
        if (!isset($noteMap[$prodNoteId])) {
            return $m[0];
        }
        $localNoteId = $noteMap[$prodNoteId];
        if ($localNoteId === $prodNoteId) {
            return $m[0];
        }
        $oldFile = $m[0];
        $newFile = 'studio-note-' . $targetUserId . '-' . $localNoteId . '-' . $m[2] . '.' . $m[3];
        $filesToCopy[$oldFile] = $newFile;
        $changed = true;
        return $newFile;
    }, $text);
    $newText = preg_replace_callback($noteParamPattern, function (array $m) use ($noteMap, &$changed) {
        $prodNoteId = (int)$m[2];
        if (!isset($noteMap[$prodNoteId]) || $noteMap[$prodNoteId] === $prodNoteId) {
            return $m[0];
        }
        $changed = true;
        return $m[1] . $noteMap[$prodNoteId];
    }, $newText);
    return [$newText, $changed];
};

$stats = ['bilingual_editorial_content' => 0, 'studio_note_workspace_items' => 0];

// --- bilingual_editorial_content ---
$rows = $target->query("SELECT id, content_json, published_content_json FROM bilingual_editorial_content WHERE user_id={$targetUserId} AND entity_type='studio_note'")->fetchAll();
$updateContent = $target->prepare('UPDATE bilingual_editorial_content SET content_json=?, published_content_json=? WHERE id=?');
foreach ($rows as $row) {
    [$newContent, $c1] = $rewrite((string)$row['content_json']);
    [$newPublished, $c2] = $rewrite((string)($row['published_content_json'] ?? ''));
    if ($c1 || $c2) {
        $stats['bilingual_editorial_content']++;
        if ($apply) {
            $updateContent->execute([$newContent, $row['published_content_json'] !== null ? $newPublished : null, $row['id']]);
        }
    }
}

// --- studio_note_workspace_items ---
$rows = $target->query("SELECT id, content_json FROM studio_note_workspace_items WHERE user_id={$targetUserId}")->fetchAll();
$updateWorkspace = $target->prepare('UPDATE studio_note_workspace_items SET content_json=? WHERE id=?');
foreach ($rows as $row) {
    [$newContent, $changed] = $rewrite((string)$row['content_json']);
    if ($changed) {
        $stats['studio_note_workspace_items']++;
        if ($apply) {
            $updateWorkspace->execute([$newContent, $row['id']]);
        }
    }
}

fwrite(STDERR, "Rows to update: " . json_encode($stats) . "\n");
fwrite(STDERR, "Files to copy (renamed): " . count($filesToCopy) . "\n");

$resultsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'results';
$manifestPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'studio_note_media_rename_manifest.tsv';
$manifest = fopen($manifestPath, 'w');
foreach ($filesToCopy as $oldFile => $newFile) {
    $dest = $resultsDir . DIRECTORY_SEPARATOR . $newFile;
    if (!is_file($dest)) {
        fwrite($manifest, 'results/' . $oldFile . "\t" . $dest . "\n");
    }
}
fclose($manifest);
fwrite(STDERR, "Manifest written to {$manifestPath}\n");

echo json_encode(['mode' => $apply ? 'applied' : 'dry-run', 'stats' => $stats, 'files_pending' => count($filesToCopy)], JSON_PRETTY_PRINT) . "\n";
