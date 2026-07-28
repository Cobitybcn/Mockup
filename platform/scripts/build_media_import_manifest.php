<?php
declare(strict_types=1);

/**
 * Emite un manifiesto (gcs_key<TAB>local_dest_path) de todos los archivos de
 * medios referenciados por un artista en la base LOCAL, para copiar desde el
 * bucket de produccion. No descarga nada: solo lee la base local y arma la
 * lista. Los que ya existen en disco local se omiten.
 *
 * Uso:
 *   php scripts/build_media_import_manifest.php --user-email=mauriziovalch@gmail.com > manifest.tsv
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
$resultsDir = $platformRoot . DIRECTORY_SEPARATOR . 'results';
$promptsDir = $platformRoot . DIRECTORY_SEPARATOR . 'mockup-prompts';
$jobsDir = $platformRoot . DIRECTORY_SEPARATOR . 'jobs';
$profilesDir = $platformRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'artist_profiles';

/** @var array<string, string> $entries gcsKey => localDestPath (dedup by gcsKey) */
$entries = [];

$addResultsFile = static function (?string $file) use (&$entries, $resultsDir): void {
    $file = basename(trim((string)$file));
    if ($file === '') { return; }
    $key = 'results/' . $file;
    if (isset($entries[$key])) { return; }
    $dest = $resultsDir . DIRECTORY_SEPARATOR . $file;
    if (is_file($dest)) { return; }
    $entries[$key] = $dest;
};

$addPromptFile = static function (?string $file) use (&$entries, $promptsDir): void {
    $file = basename(trim((string)$file));
    if ($file === '') { return; }
    $key = 'mockup-prompts/' . $file;
    if (isset($entries[$key])) { return; }
    $dest = $promptsDir . DIRECTORY_SEPARATOR . $file;
    if (is_file($dest)) { return; }
    $entries[$key] = $dest;
};

$addJobFile = static function (?string $jobId, ?string $file) use (&$entries, $jobsDir): void {
    $jobId = basename(trim((string)$jobId));
    $file = basename(trim((string)$file));
    if ($jobId === '' || $file === '') { return; }
    $key = 'jobs/' . $jobId . '/' . $file;
    if (isset($entries[$key])) { return; }
    $dest = $jobsDir . DIRECTORY_SEPARATOR . $jobId . DIRECTORY_SEPARATOR . $file;
    if (is_file($dest)) { return; }
    $entries[$key] = $dest;
};

// mockups: mockup_file + prompt_file (results/ + mockup-prompts/); artwork_file mirrors artworks.root_file (results/)
$stmt = $pdo->prepare('SELECT mockup_file, artwork_file, prompt_file FROM mockups WHERE user_id = ?');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $addResultsFile($row['mockup_file'] ?? null);
    $addResultsFile($row['artwork_file'] ?? null);
    $addPromptFile($row['prompt_file'] ?? null);
}

// mockup_sheets.mockup_file (results/)
$stmt = $pdo->prepare('SELECT mockup_file FROM mockup_sheets WHERE user_id = ?');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $addResultsFile($row['mockup_file'] ?? null);
}

// artworks: root_file (results/) + main_file (jobs/<job_id>/)
$stmt = $pdo->prepare('SELECT job_id, main_file, root_file FROM artworks WHERE user_id = ?');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $addResultsFile($row['root_file'] ?? null);
    $addJobFile($row['job_id'] ?? null, $row['main_file'] ?? null);
}

// artwork_sheets.source_image_file (results/ primarily; also try as job-relative in case it's a main_artwork copy)
$stmt = $pdo->prepare('SELECT source_image_file FROM artwork_sheets WHERE user_id = ?');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $addResultsFile($row['source_image_file'] ?? null);
}

// artwork_series.header_file (results/)
$stmt = $pdo->prepare('SELECT header_file FROM artwork_series WHERE user_id = ?');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $addResultsFile($row['header_file'] ?? null);
}

// publications.header_file (results/)
$stmt = $pdo->prepare('SELECT header_file FROM publications WHERE user_id = ?');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $addResultsFile($row['header_file'] ?? null);
}

// root_artwork_candidates.file_name (results/)
$stmt = $pdo->prepare('SELECT file_name FROM root_artwork_candidates WHERE artwork_id IN (SELECT id FROM artworks WHERE user_id = ?)');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $addResultsFile($row['file_name'] ?? null);
}

// artist_profiles.photo_file (uploads/artist_profiles/)
$stmt = $pdo->prepare('SELECT photo_file FROM artist_profiles WHERE user_id = ?');
$stmt->execute([$userId]);
$photoFile = basename(trim((string)($stmt->fetchColumn() ?: '')));
if ($photoFile !== '') {
    $key = 'uploads/artist_profiles/' . $photoFile;
    $dest = $profilesDir . DIRECTORY_SEPARATOR . $photoFile;
    if (!is_file($dest)) {
        $entries[$key] = $dest;
    }
}

foreach ($entries as $key => $dest) {
    echo $key . "\t" . $dest . "\n";
}

fwrite(STDERR, count($entries) . " archivo(s) pendientes de copiar.\n");
