<?php
declare(strict_types=1);

ini_set('max_execution_time', '120');
ini_set('max_input_time', '120');
ini_set('memory_limit', '512M');
ini_set('display_errors', '1');
ini_set('log_errors', '1');

set_time_limit(120);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();

function json_fail(string $message, int $status = 400, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'ok' => false,
        'mode' => ServiceFactory::appMode(),
        'error' => $message,
    ], $extra), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function resolve_image_path(string $imageParam): ?string
{
    $safeName = basename($imageParam);

    $candidates = [
        __DIR__ . '/results/' . $safeName,
        __DIR__ . '/uploads/' . $safeName,
        __DIR__ . '/' . $safeName,
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return realpath($candidate) ?: $candidate;
        }
    }

    return null;
}

function read_root_metadata(string $imagePath): array
{
    $metaPath = __DIR__ . '/results/' . pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.meta.json';

    if (!is_file($metaPath)) {
        return [];
    }

    $data = json_decode((string)file_get_contents($metaPath), true);

    if (!is_array($data)) {
        return [];
    }

    $m = $data['measurements'] ?? [];

    $unit = (string)($m['unit'] ?? 'cm');

    return [
        'artist_notes' => $data['artist_notes'] ?? '',
        'width_cm' => $unit === 'cm' ? ($m['width'] ?? null) : null,
        'height_cm' => $unit === 'cm' ? ($m['height'] ?? null) : null,
        'depth_cm' => $unit === 'cm' ? ($m['depth'] ?? null) : null,
        'measurements' => $m,
        'provider_settings' => $data['provider_settings'] ?? [],
        'scale_text' => $data['scale_text'] ?? '',
    ];
}

function assert_root_owner(string $imagePath, array $user): void
{
    $metaPath = __DIR__ . '/results/' . pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.meta.json';

    if (!is_file($metaPath)) {
        json_fail('No se encontro metadata de propiedad para esta obra.', 403);
    }

    $data = json_decode((string)file_get_contents($metaPath), true);

    if (is_array($data) && (int)($data['user_id'] ?? 0) !== (int)$user['id']) {
        json_fail('No tienes acceso a esta obra.', 403);
    }
}

$image = trim((string)($_GET['image'] ?? $_POST['image'] ?? ''));

if (!$image && !empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $qs);

    foreach ($qs as $key => $value) {
        if (preg_match('/\.(png|jpg|jpeg|webp|svg)$/i', $key)) {
            $image = $key;
            break;
        }
    }
}

if (!$image) {
    json_fail('Falta parametro image.');
}

$imagePath = resolve_image_path($image);

if (!$imagePath) {
    json_fail('No se encontro la imagen: ' . $image, 404);
}

assert_root_owner($imagePath, $currentUser);

try {
    $sidecarMetadata = read_root_metadata($imagePath);
    ProviderSettings::set($sidecarMetadata['provider_settings'] ?? []);
    $artistProfile = ArtistProfile::findForUser((int)$currentUser['id']);

    $metadata = array_merge($sidecarMetadata, [
        'artist_notes' => trim((string)($_GET['artist_notes'] ?? $_POST['artist_notes'] ?? '')),
        'region' => trim((string)($_GET['region'] ?? $_POST['region'] ?? '')),
        'artist_profile' => $artistProfile,
        'artist_profile_prompt' => ArtistProfile::forPrompt($artistProfile),
    ]);

    if (($metadata['artist_notes'] ?? '') === '' && ($sidecarMetadata['artist_notes'] ?? '') !== '') {
        $metadata['artist_notes'] = $sidecarMetadata['artist_notes'];
    }

    $analyzer = ServiceFactory::artworkAnalyzer();
    $response = $analyzer->analyze($imagePath, $metadata);

    if (!is_dir(__DIR__ . '/results')) {
        mkdir(__DIR__ . '/results', 0775, true);
    }

    $jsonName = pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.analysis.json';
    $analysisFile = __DIR__ . '/results/' . $jsonName;

    file_put_contents(
        $analysisFile,
        json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    if (isset($_GET['redirect']) && $_GET['redirect'] === '1') {
        header(
            'Location: form2.php?image=' . rawurlencode(basename($imagePath)) .
            '&json=' . rawurlencode($jsonName)
        );
        exit;
    }

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    json_fail($e->getMessage(), 500);
}
