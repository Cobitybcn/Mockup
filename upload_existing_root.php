<?php
declare(strict_types=1);

ini_set('upload_max_filesize', '64M');
ini_set('post_max_size', '72M');
ini_set('memory_limit', '768M');

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();

function fail_existing_root(string $message): void
{
    http_response_code(400);
    echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    exit;
}

function safe_existing_root_ext(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'png';
}

function save_existing_root_upload(array $file, string $targetPath): bool
{
    return isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])
        ? move_uploaded_file($file['tmp_name'], $targetPath)
        : false;
}

function build_existing_root_scale_text(array $measurements): string
{
    $width = trim((string)($measurements['width'] ?? ''));
    $height = trim((string)($measurements['height'] ?? ''));
    $depth = trim((string)($measurements['depth'] ?? ''));
    $unit = trim((string)($measurements['unit'] ?? 'cm'));

    if ($width === '' || $height === '') {
        return 'No physical artwork size was provided. Keep scale plausible for the visible artwork proportions.';
    }

    $text = "The real physical artwork measures {$width} {$unit} wide x {$height} {$unit} high.";
    $text .= " These measurements refer only to the artwork, not to the photo, wall, furniture, background or surrounding objects.";
    $text .= " In mockups, scale the artwork realistically relative to architecture, furniture and human figures.";

    if ($depth !== '') {
        $text .= " Physical stretcher/support depth: {$depth} {$unit}.";
    }

    return $text;
}

function quick_scene_size_measurements(string $size): array
{
    return match (strtoupper(trim($size))) {
        'L' => ['width' => '120', 'height' => '150', 'depth' => '4', 'unit' => 'cm'],
        'XL' => ['width' => '170', 'height' => '210', 'depth' => '4', 'unit' => 'cm'],
        'MONUMENTAL' => ['width' => '240', 'height' => '300', 'depth' => '5', 'unit' => 'cm'],
        default => ['width' => '80', 'height' => '100', 'depth' => '3', 'unit' => 'cm'],
    };
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail_existing_root('Method not allowed.');
}

if (empty($_FILES['existing_root_artwork']) || $_FILES['existing_root_artwork']['error'] !== UPLOAD_ERR_OK) {
    fail_existing_root('Did not receive the existing root artwork correctly.');
}

$tmpPath = (string)($_FILES['existing_root_artwork']['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    fail_existing_root('Uploaded file is not valid.');
}

$imageInfo = @getimagesize($tmpPath);
if ($imageInfo === false) {
    fail_existing_root('The uploaded file is not a valid image.');
}

$isUserSceneFlow = !empty($_POST['user_scene_flow']);
$quickSize = trim((string)($_POST['quick_size'] ?? ''));
$quickMeasurements = $isUserSceneFlow ? quick_scene_size_measurements($quickSize) : [];

$width = trim((string)($_POST['width'] ?? ($quickMeasurements['width'] ?? '')));
$height = trim((string)($_POST['height'] ?? ($quickMeasurements['height'] ?? '')));
$depth = trim((string)($_POST['depth'] ?? ($quickMeasurements['depth'] ?? '')));
$unit = trim((string)($_POST['unit'] ?? ($quickMeasurements['unit'] ?? 'cm')));

if ($width === '' || $height === '') {
    fail_existing_root('Width and height are required.');
}

if (!in_array($unit, ['cm', 'in'], true)) {
    $unit = 'cm';
}

$jobsDir = __DIR__ . DIRECTORY_SEPARATOR . 'jobs';
$resultsDir = RESULTS_DIR;

if (!is_dir($jobsDir) && !mkdir($jobsDir, 0775, true) && !is_dir($jobsDir)) {
    fail_existing_root('Could not create the job directory.');
}

if (!is_dir($resultsDir) && !mkdir($resultsDir, 0775, true) && !is_dir($resultsDir)) {
    fail_existing_root('Could not create the results directory.');
}

$jobId = 'uploaded_root_' . time() . '_' . random_int(1000, 9999);
$jobDir = $jobsDir . DIRECTORY_SEPARATOR . $jobId;

if (!mkdir($jobDir, 0775, true)) {
    fail_existing_root('Could not create the upload job folder.');
}

$ext = safe_existing_root_ext((string)($_FILES['existing_root_artwork']['name'] ?? ''));
$mainInputFile = $jobDir . DIRECTORY_SEPARATOR . 'main_artwork.' . $ext;

if (!save_existing_root_upload($_FILES['existing_root_artwork'], $mainInputFile)) {
    fail_existing_root('Could not save the existing root artwork.');
}

$rootFileName = 'base_artwork_uploaded_' . $jobId . '_v1.' . $ext;
$rootPath = $resultsDir . DIRECTORY_SEPARATOR . $rootFileName;

if (!copy($mainInputFile, $rootPath)) {
    fail_existing_root('Could not copy the existing root artwork to results.');
}

if (StorageService::isGcsActive()) {
    StorageService::uploadFile('results/' . $rootFileName, $rootPath);
}

$now = date('c');
$measurements = [
    'width' => $width,
    'height' => $height,
    'depth' => $depth,
    'unit' => $unit,
    'quick_size' => $quickSize,
];

$status = [
    'ok' => true,
    'job_id' => $jobId,
    'status' => 'done',
    'created_at' => $now,
    'updated_at' => $now,
    'message' => 'Existing root artwork uploaded directly. Root generation skipped.',
    'main_file' => basename($mainInputFile),
    'extra_files' => [],
    'candidates' => [$rootFileName],
    'result_file' => $rootFileName,
    'error' => null,
    'measurements' => $measurements,
    'artist_notes' => '',
    'provider_settings' => ProviderSettings::all(),
    'user_id' => (int)$currentUser['id'],
    'root_source' => 'uploaded_final',
    'generation_skipped' => true,
];

file_put_contents(
    $jobDir . DIRECTORY_SEPARATOR . 'status.json',
    json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

$db = Database::connection();
$stmt = $db->prepare("
    INSERT INTO artworks (user_id, job_id, main_file, root_file, status, width, height, depth, unit, created_at, updated_at)
    VALUES (:user_id, :job_id, :main_file, :root_file, :status, :width, :height, :depth, :unit, :created_at, :updated_at)
");
$stmt->execute([
    'user_id' => (int)$currentUser['id'],
    'job_id' => $jobId,
    'main_file' => basename($mainInputFile),
    'root_file' => $rootFileName,
    'status' => 'done',
    'width' => $width,
    'height' => $height,
    'depth' => $depth,
    'unit' => $unit,
    'created_at' => $now,
    'updated_at' => $now,
]);

$artworkId = (int)$db->lastInsertId();

$metaName = pathinfo($rootFileName, PATHINFO_FILENAME) . '.meta.json';
$metaPath = $resultsDir . DIRECTORY_SEPARATOR . $metaName;
file_put_contents(
    $metaPath,
    json_encode([
        'source_job_id' => $jobId,
        'user_id' => (int)$currentUser['id'],
        'root_file' => $rootFileName,
        'measurements' => $measurements,
        'artist_notes' => '',
        'provider_settings' => ProviderSettings::all(),
        'scale_text' => build_existing_root_scale_text($measurements),
        'root_source' => 'uploaded_final',
        'generation_skipped' => true,
        'user_scene_flow' => $isUserSceneFlow,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

$sceneCategory = trim(str_replace(['\\', '/'], '', (string)($_POST['scene_category'] ?? 'selected')));
$sceneBoard = max(1, min(3, (int)($_POST['scene_board'] ?? 1)));
$sceneLimit = max(1, min(4, (int)($_POST['scene_limit'] ?? 4)));

if ($isUserSceneFlow) {
    header('Location: mockup_combinations_review.php?id=' . $artworkId
        . '&board=' . $sceneBoard
        . '&world_mother_category=' . rawurlencode($sceneCategory)
        . '&auto_generate=1&compact=1&scene_limit=' . $sceneLimit);
    exit;
}

header('Location: mockup_combinations_review.php?id=' . $artworkId . '&scene_select=1&scene_limit=4');
exit;
