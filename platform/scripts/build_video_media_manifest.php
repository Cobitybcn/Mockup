<?php
declare(strict_types=1);

/**
 * Emite un manifiesto (gcs_key<TAB>local_dest_path) de los archivos de video
 * (exports, referencias, miniaturas) referenciados por un artista en la base
 * LOCAL, para copiar desde el bucket de produccion. Estos paths ya son la
 * clave GCS relativa tal cual (ver StorageService), no necesitan prefijo.
 *
 * Uso:
 *   php scripts/build_video_media_manifest.php --user-email=mauriziovalch@gmail.com > manifest.tsv
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['user-email:']);
$email = strtolower(trim((string)($options['user-email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "A valid --user-email is required.\n");
    exit(1);
}
if (app_env('APP_ENV', '') !== 'local') {
    fwrite(STDERR, "Safety stop: this script only runs with APP_ENV=local.\n");
    exit(1);
}

$pdo = Database::connection();
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (stripos($dbName, 'local') === false) {
    fwrite(STDERR, "Safety stop: target database '{$dbName}' does not look like a local database.\n");
    exit(1);
}

$userStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$userStmt->execute([$email]);
$userId = (int)($userStmt->fetchColumn() ?: 0);
if ($userId <= 0) {
    fwrite(STDERR, "No local user found for {$email}\n");
    exit(1);
}

$platformRoot = dirname(__DIR__);
$entries = []; // gcsKey => localDestPath

$add = static function (?string $relPath) use (&$entries, $platformRoot): void {
    $relPath = trim(str_replace('\\', '/', (string)$relPath));
    $relPath = ltrim($relPath, '/');
    if ($relPath === '') { return; }
    if (isset($entries[$relPath])) { return; }
    $dest = $platformRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    if (is_file($dest)) { return; }
    $entries[$relPath] = $dest;
};

$stmt = $pdo->prepare('SELECT output_path, thumbnail_path FROM video_generation_jobs WHERE user_id = ?');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $add($row['output_path'] ?? null);
    $add($row['thumbnail_path'] ?? null);
}

$stmt = $pdo->prepare('SELECT output_path FROM video_exports WHERE user_id = ?');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $add($row['output_path'] ?? null);
}

$stmt = $pdo->prepare('SELECT file_path FROM video_reference_assets WHERE user_id = ?');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $add($row['file_path'] ?? null);
}

$stmt = $pdo->prepare('SELECT vsr.file_path FROM video_scene_references vsr JOIN video_scenes vs ON vs.id = vsr.video_scene_id JOIN video_projects vp ON vp.id = vs.video_project_id WHERE vp.user_id = ?');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $add($row['file_path'] ?? null);
}

foreach ($entries as $key => $dest) {
    echo $key . "\t" . $dest . "\n";
}

fwrite(STDERR, count($entries) . " archivo(s) de video pendientes de copiar.\n");
