<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Native snapshot updater to keep regression tests in sync
(function() {
    $engine = new MockupCombinationEngine();
    $active = $engine->activeCameraSlots();
    $snapshotData = [];
    foreach ($active as $slotId => $slot) {
        $snapshotData[$slotId] = [
            'slot_name' => $slot['slot_name'],
            'enabled' => (bool)($slot['enabled'] ?? false),
            'camera_slot_geometry' => $slot['camera_slot_geometry'],
        ];
    }
    ksort($snapshotData);
    $snapshotPath = __DIR__ . '/tests/fixtures/camera_slots_snapshot.json';
    @file_put_contents($snapshotPath, json_encode($snapshotData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
})();

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$pdo = Database::connection();
(new ArtworkGroupService($pdo))->syncUser((int)$user['id']);
AdminSceneEditor::handlePost($user);

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function scenes_adopt_root_artwork(PDO $pdo, int $userId, string $rootFile): array
{
    $rootFile = basename($rootFile);
    $jobId = 'adopted_root_' . $userId . '_' . substr(sha1($rootFile), 0, 16);

    $stmt = $pdo->prepare('SELECT id, final_title, subtitle, root_file, updated_at FROM artworks WHERE user_id = :user_id AND job_id = :job_id LIMIT 1');
    $stmt->execute([
        'user_id' => $userId,
        'job_id' => $jobId,
    ]);
    $existing = $stmt->fetch();
    if (is_array($existing)) {
        return $existing;
    }

    $now = date('c');
    Database::withBusyRetry(function () use ($pdo, $userId, $jobId, $rootFile, $now): void {
        $stmt = $pdo->prepare('
            INSERT INTO artworks (user_id, job_id, main_file, root_file, status, width, height, depth, unit, created_at, updated_at)
            VALUES (:user_id, :job_id, :main_file, :root_file, :status, :width, :height, :depth, :unit, :created_at, :updated_at)
        ');
        $stmt->execute([
            'user_id' => $userId,
            'job_id' => $jobId,
            'main_file' => $rootFile,
            'root_file' => $rootFile,
            'status' => 'done',
            'width' => '',
            'height' => '',
            'depth' => '',
            'unit' => 'cm',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }, 12);

    $stmt->execute([
        'user_id' => $userId,
        'job_id' => $jobId,
    ]);
    $created = $stmt->fetch();

    return is_array($created) ? $created : [
        'id' => 0,
        'final_title' => '',
        'subtitle' => '',
        'root_file' => $rootFile,
        'updated_at' => $now,
    ];
}

function scenes_root_group_key(string $rootFile): string
{
    $rootFile = basename($rootFile);
    if (preg_match('/^(.*)_v\d+(\.[A-Za-z0-9]+)$/', $rootFile, $matches)) {
        return $matches[1] . $matches[2];
    }

    return $rootFile;
}

function scenes_dedupe_root_artwork_options(array $options): array
{
    $deduped = [];
    $seen = [];
    foreach ($options as $option) {
        $rootFile = basename((string)($option['root_file'] ?? ''));
        if ($rootFile === '') {
            continue;
        }
        $groupKey = scenes_root_group_key($rootFile);
        if (isset($seen[$groupKey])) {
            continue;
        }
        $seen[$groupKey] = true;
        $deduped[] = $option;
    }

    return $deduped;
}

function scenes_root_artwork_options(PDO $pdo, array $user): array
{
    $userId = (int)$user['id'];
    $options = [];
    $knownRootFiles = [];

    $sql = "
        SELECT id, root_file, final_title, subtitle, updated_at, created_at
        FROM artworks
        WHERE status = 'done'
        AND root_file IS NOT NULL
        AND root_file != ''
        AND user_id = :user_id
    ";
    $params = ['user_id' => $userId];
    $sql .= " ORDER BY updated_at DESC, created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $file = basename((string)($row['root_file'] ?? ''));
        if ($file === '' || !is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
            continue;
        }
        $row['root_file'] = $file;
        $options[] = $row;
        $knownRootFiles[$file] = true;
    }

    $mockupStmt = $pdo->prepare("
        SELECT artwork_file, MAX(created_at) AS updated_at
        FROM mockups
        WHERE user_id = :user_id
        AND artwork_file IS NOT NULL
        AND artwork_file != ''
        GROUP BY artwork_file
        ORDER BY MAX(created_at) DESC
    ");
    $mockupStmt->execute(['user_id' => $userId]);

    foreach ($mockupStmt->fetchAll() as $row) {
        $file = basename((string)($row['artwork_file'] ?? ''));
        if ($file === '' || isset($knownRootFiles[$file]) || !is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
            continue;
        }
        $adopted = scenes_adopt_root_artwork($pdo, $userId, $file);
        if ((int)($adopted['id'] ?? 0) > 0) {
            $adopted['root_file'] = $file;
            $adopted['updated_at'] = (string)($row['updated_at'] ?? ($adopted['updated_at'] ?? ''));
            $options[] = $adopted;
            $knownRootFiles[$file] = true;
        }
    }

    return scenes_dedupe_root_artwork_options($options);
}

function scenes_last_artwork_session_key(int $userId): string
{
    return 'last_scene_artwork_id_user_' . $userId;
}

function scenes_last_artwork_setting_key(int $userId): string
{
    return 'last_scene_artwork_id_user_' . $userId;
}

function scenes_option_id_exists(array $artworkOptions, int $artworkId): bool
{
    foreach ($artworkOptions as $option) {
        if ((int)($option['id'] ?? 0) === $artworkId) {
            return true;
        }
    }

    return false;
}

function scenes_option_id_for_root_file(array $artworkOptions, string $rootFile): int
{
    $rootFile = basename($rootFile);
    if ($rootFile === '') {
        return 0;
    }

    $groupKey = scenes_root_group_key($rootFile);
    foreach ($artworkOptions as $option) {
        if (scenes_root_group_key((string)($option['root_file'] ?? '')) === $groupKey) {
            return (int)($option['id'] ?? 0);
        }
    }

    return 0;
}

function scenes_artwork_option_id(PDO $pdo, int $userId, array $artworkOptions, int $artworkId): int
{
    if ($artworkId <= 0) {
        return 0;
    }
    if (scenes_option_id_exists($artworkOptions, $artworkId)) {
        return $artworkId;
    }

    $stmt = $pdo->prepare('
        SELECT root_file
        FROM artworks
        WHERE id = :id
        AND user_id = :user_id
        LIMIT 1
    ');
    $stmt->execute([
        'id' => $artworkId,
        'user_id' => $userId,
    ]);

    return scenes_option_id_for_root_file($artworkOptions, (string)$stmt->fetchColumn());
}

function scenes_recent_mockup_artwork_id(PDO $pdo, int $userId, array $artworkOptions): int
{
    $stmt = $pdo->prepare('
        SELECT source_artwork_id, artwork_file
        FROM mockups
        WHERE user_id = :user_id
        ORDER BY created_at DESC
        LIMIT 50
    ');
    $stmt->execute(['user_id' => $userId]);

    foreach ($stmt->fetchAll() as $mockup) {
        $sourceArtworkId = scenes_artwork_option_id($pdo, $userId, $artworkOptions, (int)($mockup['source_artwork_id'] ?? 0));
        if ($sourceArtworkId > 0) {
            return $sourceArtworkId;
        }

        $artworkFileId = scenes_option_id_for_root_file($artworkOptions, (string)($mockup['artwork_file'] ?? ''));
        if ($artworkFileId > 0) {
            return $artworkFileId;
        }
    }

    return 0;
}

function scenes_last_artwork_id(PDO $pdo, int $userId, array $artworkOptions): int
{
    $sessionKey = scenes_last_artwork_session_key($userId);
    $sessionArtworkId = scenes_artwork_option_id($pdo, $userId, $artworkOptions, max(0, (int)($_SESSION[$sessionKey] ?? 0)));
    if ($sessionArtworkId > 0 && scenes_option_id_exists($artworkOptions, $sessionArtworkId)) {
        return $sessionArtworkId;
    }

    $settingKey = scenes_last_artwork_setting_key($userId);
    $sql = Database::isMysql()
        ? 'SELECT value FROM app_settings WHERE `key` = :key LIMIT 1'
        : 'SELECT value FROM app_settings WHERE key = :key LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['key' => $settingKey]);
    $storedArtworkId = scenes_artwork_option_id($pdo, $userId, $artworkOptions, max(0, (int)$stmt->fetchColumn()));
    if ($storedArtworkId > 0 && scenes_option_id_exists($artworkOptions, $storedArtworkId)) {
        $_SESSION[$sessionKey] = $storedArtworkId;
        return $storedArtworkId;
    }

    $recentMockupArtworkId = scenes_recent_mockup_artwork_id($pdo, $userId, $artworkOptions);
    if ($recentMockupArtworkId > 0 && scenes_option_id_exists($artworkOptions, $recentMockupArtworkId)) {
        scenes_remember_last_artwork($pdo, $userId, $recentMockupArtworkId);
        return $recentMockupArtworkId;
    }

    return 0;
}

function scenes_remember_last_artwork(PDO $pdo, int $userId, int $artworkId): void
{
    if ($userId <= 0 || $artworkId <= 0) {
        return;
    }

    $_SESSION[scenes_last_artwork_session_key($userId)] = $artworkId;
    $stmt = $pdo->prepare(Database::appSettingUpsertSql());
    $stmt->execute([
        'key' => scenes_last_artwork_setting_key($userId),
        'value' => (string)$artworkId,
        'updated_at' => date('c'),
    ]);
}

$artworkOptions = scenes_root_artwork_options($pdo, $user);
$id = max(0, (int)($_GET['id'] ?? 0));
if ($id <= 0) {
    $rememberedArtworkId = scenes_last_artwork_id($pdo, (int)$user['id'], $artworkOptions);
    if ($rememberedArtworkId > 0) {
        header('Location: mockup_combinations_review.php?id=' . $rememberedArtworkId);
        exit;
    }

    $firstOption = $artworkOptions[0] ?? null;
    if (is_array($firstOption) && (int)($firstOption['id'] ?? 0) > 0) {
        header('Location: mockup_combinations_review.php?id=' . (int)$firstOption['id']);
        exit;
    }
    http_response_code(404);
    die('Artwork ID is missing and no root artwork is available.');
}

$stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$artwork = $stmt->fetch();
if (!$artwork) {
    http_response_code(404);
    die('Artwork not found.');
}
if ((int)$artwork['user_id'] !== (int)$user['id'] && !Auth::isAdmin($user)) {
    http_response_code(403);
    die('Access denied.');
}
if ((int)$artwork['user_id'] === (int)$user['id']) {
    scenes_remember_last_artwork($pdo, (int)$user['id'], $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'choose_scene_root_view') {
    $candidateFile = basename((string)($_POST['root_file'] ?? ''));
    $redirectCategory = trim(str_replace(['\\', '/'], '', (string)($_POST['world_mother_category'] ?? '')));
    $redirectBoard = max(1, min(3, (int)($_POST['board'] ?? $_GET['board'] ?? 1)));
    if ($candidateFile !== '' && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $candidateFile)) {
        Database::withBusyRetry(function () use ($pdo, $id, $candidateFile): void {
            $pdo->prepare('UPDATE root_artwork_candidates SET is_selected = 0 WHERE artwork_id = :artwork_id')
                ->execute(['artwork_id' => $id]);
            $pdo->prepare('UPDATE root_artwork_candidates SET is_selected = 1 WHERE artwork_id = :artwork_id AND file_name = :file_name')
                ->execute([
                    'artwork_id' => $id,
                    'file_name' => $candidateFile,
                ]);
            $pdo->prepare('UPDATE artworks SET root_file = :root_file, status = :status, updated_at = :updated_at WHERE id = :id')
                ->execute([
                    'root_file' => $candidateFile,
                    'status' => 'done',
                    'updated_at' => date('c'),
                    'id' => $id,
                ]);
        }, 12);
    }

    header('Location: mockup_combinations_review.php?id=' . (int)$id . '&board=' . $redirectBoard . ($redirectCategory !== '' ? '&world_mother_category=' . rawurlencode($redirectCategory) : ''));
    exit;
}

function get_friendly_camera_name(string $slug): string
{
    $mapping = [
        'corte-agresivo-de-esquina-de-obra-loft' => 'Loft - Close-up Corner',
        'corte-agresivo-de-esquina-de-obra-loft-1' => 'Loft - Close-up Corner A',
        'corte-agresivo-de-esquina-de-obra-loft-2' => 'Loft - Close-up Corner B',
        'frontal-close-up-loft' => 'Loft - Frontal Close-up',
        'frontal-close-up-loft-1' => 'Loft - Frontal Close-up A',
        'frontal-close-up-loft-2' => 'Loft - Frontal Close-up B',
        'borde-de-canvas-close-up-loft' => 'Loft - Canvas Edge Detail',
        'contrapicado-78-loft' => 'Loft - Low Angle 7/8',
        'frontal-lejos-loft' => 'Loft - Frontal Wide View'
    ];

    if (isset($mapping[$slug])) {
        return $mapping[$slug];
    }

    // Clean up slug
    $clean = str_replace(['-', '_'], ' ', $slug);
    $clean = str_replace(['de obra', 'de', 'para'], '', $clean);
    $clean = preg_replace('/\s+/', ' ', $clean);
    return ucwords(trim($clean));
}

function world_mother_image_url(string $file): string
{
    $file = str_replace('\\', '/', trim($file));
    if ($file === '' || !str_starts_with($file, 'storage/world_mothers/')) {
        return '';
    }

    return 'world_mother_media.php?file=' . rawurlencode($file) . '&thumb=1&w=640';
}

function scene_root_view_label(string $viewType): string
{
    return [
        'frontal' => 'Frontal',
        'three-quarter-left' => '3/4 Left',
        'three-quarter-right' => '3/4 Right',
    ][$viewType] ?? ucwords(str_replace(['-', '_'], ' ', $viewType));
}

function scene_root_sibling_candidates(string $rootFile): array
{
    $rootFile = basename($rootFile);
    if (!preg_match('/^(.*)_v(\d+)(\.[A-Za-z0-9]+)$/', $rootFile, $matches)) {
        return [];
    }

    $viewTypes = [
        1 => 'frontal',
        2 => 'three-quarter-left',
        3 => 'three-quarter-right',
    ];
    $candidates = [];
    for ($version = 1; $version <= 3; $version++) {
        $file = $matches[1] . '_v' . $version . $matches[3];
        if (!is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
            continue;
        }
        $candidates[] = [
            'file_name' => $file,
            'view_type' => $viewTypes[$version] ?? 'frontal',
        ];
    }

    return $candidates;
}

function world_mother_favorites_path(int $userId): string
{
    return __DIR__ . '/storage/world_mother_favorites/user_' . $userId . '.json';
}

function world_mother_favorites(int $userId): array
{
    $path = world_mother_favorites_path($userId);
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter(array_map('strval', $decoded), static fn (string $slug): bool => $slug !== ''));
}

function world_mother_search_aliases(string $slug): string
{
    $aliases = [];
    if (str_contains($slug, 'sunlit') || str_contains($slug, 'sun')) {
        $aliases[] = 'sunlight sunny sun morning light daylight';
    }
    if (str_contains($slug, 'blue_hour')) {
        $aliases[] = 'blue hour twilight dusk evening cobalt light';
    }
    if (str_contains($slug, 'low_light') || str_contains($slug, 'dark') || str_contains($slug, 'night')) {
        $aliases[] = 'low light moody evening night shadow';
    }
    if (str_contains($slug, 'atelier') || str_contains($slug, 'studio') || str_contains($slug, 'workspace')) {
        $aliases[] = 'atelier studio workspace artist workroom';
    }
    if (str_contains($slug, 'concrete') || str_contains($slug, 'brutalist')) {
        $aliases[] = 'concrete brutalist raw architecture mineral';
    }

    return implode(' ', $aliases);
}

function stable_world_mother_pool_for_artwork(array $pool, string $category, int $artworkId): array
{
    usort($pool, static function (array $a, array $b): int {
        return strcmp((string)($a['relative_path'] ?? ''), (string)($b['relative_path'] ?? ''));
    });

    usort($pool, static function (array $a, array $b) use ($category, $artworkId): int {
        $aKey = sprintf('%u', crc32($category . '|' . $artworkId . '|' . (string)($a['relative_path'] ?? '')));
        $bKey = sprintf('%u', crc32($category . '|' . $artworkId . '|' . (string)($b['relative_path'] ?? '')));
        return $aKey <=> $bKey;
    });

    return array_values($pool);
}

function variant_offset_for_world_image(int $comboIndex, int $targetPosition, int $poolCount): int
{
    if ($poolCount <= 0) {
        return 0;
    }

    $base = max(0, $comboIndex - 1);
    return ($targetPosition - ($base % $poolCount) + $poolCount) % $poolCount;
}

$selectedSlots = [];
foreach (($_GET['slot'] ?? []) as $index => $slotId) {
    $selectedSlots[(int)$index] = trim((string)$slotId);
}
$selectedWorldMotherVariants = [];
foreach (($_GET['world_variant'] ?? []) as $index => $offset) {
    $selectedWorldMotherVariants[(int)$index] = max(0, (int)$offset);
}
$selectedWorldMotherCategory = trim(str_replace(['\\', '/'], '', (string)($_GET['world_mother_category'] ?? '')));
$sceneBoardIndex = max(1, min(3, (int)($_GET['board'] ?? 1)));
$canSelectGenerationProvider = ProviderSettings::canSelectGenerationProvider(
    $isAdmin,
    (string)($_SERVER['HTTP_HOST'] ?? '')
);
$selectedGenerationProvider = $canSelectGenerationProvider
    ? ServiceFactory::generationProvider((string)($_GET['generation_provider'] ?? ''))
    : ServiceFactory::generationProvider();
$generationProviderQuery = $canSelectGenerationProvider ? '&generation_provider=' . rawurlencode($selectedGenerationProvider) : '';
$sceneSelectionFlow = !empty($_GET['scene_select']);
$compactSceneFlow = !empty($_GET['compact']);
$autoGenerateSceneFlow = !empty($_GET['auto_generate']);
$compactSceneLimit = max(1, min(4, (int)($_GET['scene_limit'] ?? 4)));

$engine = new MockupCombinationEngine();
$review = $engine->buildForArtwork($id, $selectedSlots, [
    'selected_world_mother_category' => $selectedWorldMotherCategory,
    'world_mother_variant_offsets' => $selectedWorldMotherVariants,
    'scene_board_index' => $sceneBoardIndex,
]);
$combinations = $review['combinations'] ?? [];
$cameraSlots = $review['available_camera_slots'] ?? [];
$sceneStudio = $isAdmin ? new CameraSlotStudio() : null;
$cameraSlotsById = [];
foreach ($cameraSlots as $cameraSlot) {
    $cameraSlotId = (string)($cameraSlot['slot_id'] ?? '');
    if ($cameraSlotId !== '') {
        $cameraSlotsById[$cameraSlotId] = $cameraSlot;
    }
}
$suggestedWorldMotherCategories = (array)($review['suggested_world_mother_categories'] ?? []);
$selectedWorldMotherCategory = (string)($review['selected_world_mother_category'] ?? $selectedWorldMotherCategory);
$sceneBoardIndex = max(1, min(3, (int)($review['scene_board_index'] ?? $sceneBoardIndex)));
$scenePageTitle = $sceneBoardIndex > 1 ? 'Create Scenes Batch ' . $sceneBoardIndex : 'Create Scenes';
$selectedWorldMotherImages = stable_world_mother_pool_for_artwork(
    (new WorldMotherLibrary())->imagesForCategory($selectedWorldMotherCategory),
    $selectedWorldMotherCategory,
    $id
);
$sceneDirectionOptions = [];
$selectedSceneDirectionName = $selectedWorldMotherCategory;
$worldMotherLibrary = new WorldMotherLibrary();
foreach ($suggestedWorldMotherCategories as $scene) {
    $slug = (string)($scene['category_slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    $pool = stable_world_mother_pool_for_artwork($worldMotherLibrary->imagesForCategory($slug), $slug, $id);
    $previewPath = (string)($pool[0]['relative_path'] ?? '');
    $previewUrls = [];
    foreach ($pool as $poolImage) {
        $previewUrl = world_mother_image_url((string)($poolImage['relative_path'] ?? ''));
        if ($previewUrl !== '') {
            $previewUrls[] = $previewUrl;
        }
    }
    $sceneName = (string)($scene['category_name'] ?? $slug);
    if ($slug === $selectedWorldMotherCategory) {
        $selectedSceneDirectionName = $sceneName;
    }
    $sceneDirectionOptions[] = [
        'slug' => $slug,
        'name' => $sceneName,
        'image_count' => (int)($scene['image_count'] ?? count($pool)),
        'preview_url' => world_mother_image_url($previewPath),
        'preview_urls' => $previewUrls,
    ];
}
$selectedScenePreviewUrl = '';
foreach ($selectedWorldMotherImages as $sceneImage) {
    $selectedScenePreviewUrl = world_mother_image_url((string)($sceneImage['relative_path'] ?? ''));
    if ($selectedScenePreviewUrl !== '') {
        break;
    }
}
$favoriteWorldMotherCategories = world_mother_favorites((int)$user['id']);
$favoriteWorldMotherLookup = array_fill_keys($favoriteWorldMotherCategories, true);
$favoriteWorldMotherNormalizedLookup = [];
foreach ($favoriteWorldMotherCategories as $favoriteWorldMotherCategory) {
    $favoriteWorldMotherNormalizedLookup[WorldMotherGenerator::safeSlug($favoriteWorldMotherCategory)] = true;
}

$rootUrl = '';
$rootPath = (string)($review['root_artwork_path'] ?? '');
if ($rootPath !== '') {
    $rootUrl = 'media.php?file=' . rawurlencode(basename($rootPath));
}

$currentOptionKnown = false;
$currentRootGroupKey = scenes_root_group_key((string)($artwork['root_file'] ?? ''));
foreach ($artworkOptions as $option) {
    if ((int)($option['id'] ?? 0) === $id || scenes_root_group_key((string)($option['root_file'] ?? '')) === $currentRootGroupKey) {
        $currentOptionKnown = true;
        break;
    }
}
if (!$currentOptionKnown) {
    array_unshift($artworkOptions, [
        'id' => $id,
        'root_file' => basename((string)($artwork['root_file'] ?? '')),
        'final_title' => (string)($artwork['final_title'] ?? ''),
        'subtitle' => (string)($artwork['subtitle'] ?? ''),
        'updated_at' => (string)($artwork['updated_at'] ?? ''),
    ]);
}

$rootViewOptions = [];
$rootViewKnownFiles = [];
$candidateStmt = $pdo->prepare('
    SELECT file_name, view_type
    FROM root_artwork_candidates
    WHERE artwork_id = :artwork_id
    ORDER BY id ASC
');
$candidateStmt->execute(['artwork_id' => $id]);
foreach ($candidateStmt->fetchAll() as $candidate) {
    $file = basename((string)($candidate['file_name'] ?? ''));
    if ($file === '' || !is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
        continue;
    }
    $rootViewOptions[] = [
        'file_name' => $file,
        'view_type' => (string)($candidate['view_type'] ?? 'frontal'),
    ];
    $rootViewKnownFiles[$file] = true;
}
$currentRootFile = basename((string)($artwork['root_file'] ?? ''));
if ($currentRootFile !== '' && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $currentRootFile) && !isset($rootViewKnownFiles[$currentRootFile])) {
    $rootViewOptions[] = [
        'file_name' => $currentRootFile,
        'view_type' => 'frontal',
    ];
    $rootViewKnownFiles[$currentRootFile] = true;
}
foreach (scene_root_sibling_candidates($currentRootFile) as $siblingCandidate) {
    $file = (string)$siblingCandidate['file_name'];
    if (isset($rootViewKnownFiles[$file])) {
        continue;
    }
    $rootViewOptions[] = $siblingCandidate;
    $rootViewKnownFiles[$file] = true;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= h($scenePageTitle) ?> - Artwork Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <?= AdminSceneEditor::styles() ?>
    <style>
        .review-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }
        .workspace-header {
            align-items: center;
            gap: 16px;
            padding-bottom: 14px;
            margin-bottom: 16px;
        }
        .workspace-header h1 {
            font-size: 36px;
            line-height: 1.05;
            margin-bottom: 6px;
        }
        .workspace-header p {
            margin: 0;
            font-size: 13px;
        }
        .workspace-header .topbar-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            justify-content: flex-end;
            gap: 6px;
            max-width: none;
        }
        .workspace-header .topbar-actions .button-link,
        .workspace-header .topbar-actions button.button-link {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            width: auto !important;
            min-width: 0 !important;
            height: 36px !important;
            min-height: 0 !important;
            padding: 0 16px !important;
            margin: 0 !important;
            border-radius: 4px;
            font-size: 11px !important;
            line-height: 1 !important;
            letter-spacing: .06em;
            box-shadow: none !important;
        }
        .workspace-header .topbar-actions #generate-all-btn {
            min-width: 0 !important;
            flex: 0 0 auto;
        }
        .compact-specs {
            margin: -6px 0 10px;
            color: var(--muted);
        }
        .compact-specs .specs-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-start;
            gap: 6px 14px;
        }
        .compact-specs strong {
            display: inline;
            font-size: 8px;
            text-transform: uppercase;
            color: var(--muted);
            letter-spacing: .04em;
            margin-right: 4px;
        }
        .compact-specs code {
            font-size: 9px;
            color: var(--muted);
        }
        .review-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            align-items: start;
        }
        .review-group-title {
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 10px 0 -2px;
            color: var(--muted);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .review-group-title::after {
            content: "";
            height: 1px;
            flex: 1 1 auto;
            background: var(--line);
        }
        .combination-card {
            background: #fbfaf7;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: visible;
            position: relative;
            z-index: 1;
        }
        .combination-card:hover,
        .combination-card:focus-within {
            z-index: 30;
        }
        .combination-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            padding: 7px 10px;
            background: #f4f0e9;
            cursor: pointer;
            list-style: none;
        }
        .combination-head::-webkit-details-marker {
            display: none;
        }
        .combination-head::after {
            content: "⌄";
            flex: 0 0 auto;
            color: var(--muted);
            font-size: 18px;
            line-height: 1;
            transform: rotate(-90deg);
            transition: transform .16s ease;
            margin-top: 0;
        }
        .combination-card[open] .combination-head {
            border-bottom: 1px dashed var(--line);
        }
        .combination-card[open] .combination-head::after {
            transform: rotate(0deg);
        }
        .combination-title {
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .combination-status {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 4px;
            margin-left: auto;
        }
        .combination-body {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 8px 10px 10px;
        }
        .combination-head h3 {
            margin: 0;
            font-family: var(--font-sans);
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
            word-break: break-word;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            height: 20px;
            padding: 0 6px;
            border-radius: 3px;
            border: 1px solid rgba(154, 123, 86, 0.25);
            background: var(--accent-light);
            color: var(--accent);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .badge.ready {
            background: #e6fffa;
            border-color: rgba(35, 78, 82, .25);
            color: #234e52;
        }
        .badge.warn {
            background: #fffdf5;
            border-color: rgba(140, 109, 31, .25);
            color: #8c6d1f;
        }
        .scene-admin-inline {
            border: 1px solid var(--line);
            border-radius: 4px;
            background: rgba(255,255,255,.52);
            padding: 6px 8px;
        }
        .scene-admin-inline summary {
            cursor: pointer;
            list-style: none;
            color: var(--muted);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .07em;
            text-transform: uppercase;
        }
        .scene-admin-inline summary::-webkit-details-marker {
            display: none;
        }
        .scene-admin-inline summary::after {
            content: "+";
            float: right;
            color: var(--accent);
        }
        .scene-admin-inline[open] summary {
            margin-bottom: 8px;
        }
        .scene-admin-inline[open] summary::after {
            content: "-";
        }
        .scene-admin-form {
            display: grid;
            gap: 8px;
        }
        .scene-admin-fields {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            align-items: end;
        }
        .scene-admin-form label {
            display: grid;
            gap: 4px;
            color: var(--muted);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }
        .scene-admin-form input[type="text"],
        .scene-admin-form textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 4px;
            background: var(--surface);
            color: var(--ink);
            padding: 7px 8px;
            font-size: 11px;
        }
        .scene-admin-form textarea {
            min-height: 180px;
            font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
            line-height: 1.42;
            resize: vertical;
        }
        .scene-admin-toggle {
            min-width: 96px;
            height: 32px;
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 1px solid var(--line);
            border-radius: 4px;
            background: var(--surface);
            color: var(--ink) !important;
            font-size: 10px !important;
        }
        .scene-admin-actions {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }
        .scene-admin-actions .button-link {
            width: auto !important;
            min-width: 0 !important;
            height: 30px !important;
            min-height: 0 !important;
            padding: 0 10px !important;
            margin: 0 !important;
            font-size: 9px !important;
            box-shadow: none !important;
        }
        .slot-id {
            display: block;
            margin-top: 4px;
            color: var(--muted);
            font-size: 10px;
            line-height: 1.25;
            word-break: break-word;
        }
        .thumb-row {
            display: grid;
            grid-template-columns: minmax(118px, .46fr) 42px minmax(0, 1fr);
            gap: 8px;
            align-items: center;
        }
        .combination-plus {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border: 1px solid rgba(183, 127, 134, .34);
            border-radius: 50%;
            background: rgba(183, 127, 134, .12);
            color: #9d6770;
            font-size: 29px;
            line-height: 1;
            font-weight: 300;
            box-shadow: inset 0 0 0 5px rgba(255, 250, 247, .82), 0 5px 14px rgba(52, 36, 28, .08);
            transform: translate(-12px, -2px);
        }
        .combination-plus::before {
            content: "+";
        }
        .card-icon-actions {
            display: none;
        }
        .refresh-world-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border: 1px solid var(--line);
            border-radius: 4px;
            background: rgba(251, 250, 247, .68);
            color: var(--accent);
            text-decoration: none;
            font-size: 16px;
            line-height: 1;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(20, 20, 18, .08);
        }
        .refresh-world-btn:hover {
            background: rgba(251, 250, 247, .92);
            border-color: rgba(154, 123, 86, .35);
        }
        .thumb-box {
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: visible;
            min-height: 0;
            position: relative;
        }
        .scene-thumb-picker {
            position: absolute;
            right: 0;
            bottom: 0;
            z-index: 80;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 4px;
            padding: 5px;
            width: min(520px, 86vw);
            max-height: 360px;
            overflow: auto;
            background: rgba(251, 250, 247, .94);
            border: 1px solid rgba(228, 222, 211, .8);
            border-radius: 7px;
            opacity: 0;
            pointer-events: none;
            transform: translate(8px, 8px);
            transition: opacity .16s ease, transform .16s ease;
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 26px rgba(20, 20, 18, .12);
            scrollbar-width: none;
        }
        .scene-thumb-picker::-webkit-scrollbar {
            display: none;
        }
        .thumb-box:hover .scene-thumb-picker,
        .scene-thumb-picker:focus-within {
            opacity: 1;
            pointer-events: auto;
            transform: translate(0, 0);
        }
        .root-thumb-picker {
            position: absolute;
            left: 0;
            bottom: 0;
            z-index: 85;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 4px;
            padding: 5px;
            width: min(360px, 86vw);
            background: rgba(251, 250, 247, .94);
            border: 1px solid rgba(228, 222, 211, .8);
            border-radius: 7px;
            opacity: 0;
            pointer-events: none;
            transform: translate(-8px, 8px);
            transition: opacity .16s ease, transform .16s ease;
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 26px rgba(20, 20, 18, .12);
        }
        .thumb-box:hover .root-thumb-picker,
        .root-thumb-picker:focus-within {
            opacity: 1;
            pointer-events: auto;
            transform: translate(0, 0);
        }
        .root-thumb-option {
            position: relative;
            height: 112px;
            border: 2px solid transparent;
            border-radius: 4px;
            overflow: hidden;
            background: var(--surface);
            opacity: .88;
            padding: 0;
            cursor: pointer;
        }
        .root-thumb-option.active {
            border-color: var(--accent);
            opacity: 1;
            cursor: default;
        }
        .root-thumb-option img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: var(--surface-soft);
        }
        .root-thumb-option span {
            position: absolute;
            left: 5px;
            bottom: 5px;
            border-radius: 3px;
            background: rgba(18, 17, 15, .64);
            color: #fff;
            padding: 3px 5px;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .scene-thumb-option {
            width: 100%;
            height: 112px;
            border: 2px solid transparent;
            border-radius: 4px;
            overflow: hidden;
            background: var(--surface);
            opacity: .86;
            transition: opacity .16s ease, transform .16s ease, border-color .16s ease;
        }
        .scene-thumb-option:hover {
            opacity: 1;
            transform: translateY(-2px);
        }
        .scene-thumb-option.active {
            border-color: var(--accent);
            opacity: 1;
        }
        .scene-thumb-option img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        @media (min-width: 1500px) {
            .scene-thumb-picker {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                width: 640px;
            }
        }
        .combination-card.edge-left .scene-thumb-picker {
            left: 0;
            right: auto;
        }
        .combination-card.edge-right .scene-thumb-picker {
            right: 0;
            left: auto;
        }
        .thumb-box img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            background: var(--surface-soft);
            border-radius: var(--radius);
        }
        .thumb-box.root-reference-thumb {
            aspect-ratio: 3 / 4;
        }
        .thumb-box.scene-reference-thumb {
            aspect-ratio: 4 / 3;
        }
        .thumb-box.root-reference-thumb img {
            object-fit: contain;
            object-position: center;
            background: #f4f1ea;
        }
        .thumb-box.scene-reference-thumb img {
            object-fit: cover;
        }
        .thumb-box .root-thumb-picker .root-thumb-option img {
            height: 100%;
            object-fit: contain;
            object-position: center;
            background: var(--surface-soft);
            border-radius: 0;
        }
        .thumb-label {
            display: block;
            padding: 8px 10px;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: .05em;
            color: var(--muted);
            border-top: 1px solid var(--line);
            word-break: break-word;
        }
        .meta-list {
            display: grid;
            gap: 9px;
            font-size: 13px;
            line-height: 1.45;
        }
        .meta-list strong {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--muted);
            margin-bottom: 2px;
        }
        .camera-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: end;
        }
        .camera-form select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 10px 12px;
            background: var(--surface-soft);
            color: var(--ink);
            font-size: 13px;
        }
        .prompt-preview {
            width: 100%;
            min-height: 190px;
            resize: vertical;
            font-family: monospace;
            font-size: 11px;
            line-height: 1.55;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface-soft);
            color: var(--ink);
        }
        .beta-hidden-stage {
            display: none !important;
        }
        .camera-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface-soft);
        }
        .camera-title-row strong {
            font-size: 14px;
        }
        .camera-title-row code {
            font-size: 10px;
            color: var(--muted);
        }
        .notes {
            margin: 0;
            padding-left: 18px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }
        .auto-world-panel {
            border: 1px solid rgba(35, 78, 82, .22);
            background: #f0fdfa;
            color: #234e52;
            border-radius: var(--radius);
            padding: 11px 12px;
            font-size: 12px;
            line-height: 1.45;
            word-break: break-word;
        }
        .auto-world-panel strong {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .05em;
            margin-bottom: 4px;
        }
        .auto-world-panel a {
            color: #234e52;
            font-weight: 700;
        }
        .prepare-result {
            font-size: 12px;
            min-height: 0;
            color: var(--muted);
        }
        .prepare-result:empty {
            display: none;
        }
        .generation-overlay {
            position: fixed;
            inset: 0;
            z-index: 1200;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(250, 248, 244, .68);
            backdrop-filter: blur(3px);
            transition: all 0.3s ease;
        }
        .generation-overlay.active {
            display: flex;
        }
        .generation-overlay.minimized {
            inset: auto;
            right: 24px;
            bottom: 24px;
            background: transparent;
            backdrop-filter: none;
            pointer-events: auto;
            z-index: 1200;
        }
        .generation-overlay.minimized .generation-overlay-card {
            width: auto;
            max-width: 480px;
            box-shadow: 0 10px 30px rgba(20, 20, 18, 0.16);
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            text-align: left;
        }
        .generation-overlay.minimized .generation-spinner {
            width: 20px;
            height: 20px;
            border-width: 2.5px;
            margin: 0;
            flex: 0 0 auto;
        }
        .generation-overlay.minimized .generation-overlay-card strong {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 0;
            font-family: var(--font-sans);
            white-space: nowrap;
        }
        .generation-overlay.minimized .generation-overlay-card p {
            font-size: 11px;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }
        .overlay-actions {
            margin-top: 14px;
            display: flex;
            justify-content: center;
            gap: 12px;
            font-size: 11px;
        }
        .overlay-actions button {
            padding: 4px 10px;
            height: auto !important;
            min-height: 0 !important;
            font-size: 10px !important;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
        }
        .generation-overlay-card {
            width: min(360px, calc(100vw - 32px));
            padding: 30px 28px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--surface);
            box-shadow: 0 18px 48px rgba(20, 20, 18, .16);
            text-align: center;
        }
        .generation-spinner {
            width: 58px;
            height: 58px;
            margin: 0 auto 18px;
            border: 4px solid rgba(154, 123, 86, .18);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: generation-spin .8s linear infinite;
        }
        .generation-overlay-card strong {
            display: block;
            margin-bottom: 8px;
            font-family: var(--font-sans);
            font-size: 24px;
            font-weight: 400;
            color: var(--ink);
        }
        .generation-overlay-card p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.45;
        }
        @keyframes generation-spin {
            to {
                transform: rotate(360deg);
            }
        }
        .scene-browser-panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 4px;
            padding: 12px;
            margin-bottom: 16px;
        }
        .scene-mother-select {
            display: grid;
            gap: 5px;
            min-width: 320px;
            margin: 0;
        }
        .scene-mother-select span {
            color: var(--muted);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .scene-mother-select select {
            width: 100%;
            height: 38px;
            border: 1px solid var(--line);
            border-radius: 4px;
            padding: 8px 32px 8px 10px;
            background-color: var(--surface-soft);
            color: var(--ink);
            font-size: 13px;
            cursor: pointer;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239A7B56' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 14px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .scene-mother-select select:hover,
        .scene-mother-select select:focus {
            border-color: var(--accent);
        }
        .scene-browser-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }
        .scene-browser-head strong {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            color: var(--muted);
            letter-spacing: .04em;
        }
        .scene-browser-head code {
            color: var(--ink);
            font-size: 12px;
        }
        .scene-list-toggle {
            margin-top: 8px;
        }
        .scene-list-toggle:not([open]) .scene-choice-grid {
            display: none;
        }
        .scene-list-toggle summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            color: var(--muted);
            font-size: 12px;
            list-style: none;
            padding: 6px 0 0;
        }
        .scene-list-toggle summary::-webkit-details-marker {
            display: none;
        }
        .scene-list-toggle summary::after {
            content: "open";
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--accent);
        }
        .scene-list-toggle[open] summary::after {
            content: "close";
        }
        .scene-choice-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            margin-top: 8px;
            max-height: 230px;
            overflow: auto;
            padding-right: 4px;
        }
        .scene-choice {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            gap: 10px;
            align-items: center;
            border: 1px solid var(--line);
            border-radius: 4px;
            padding: 7px 8px;
            background: var(--surface-soft);
            color: var(--ink);
            text-decoration: none;
            min-height: 0;
        }
        .scene-choice.hidden { display: none; }
        .scene-choice.active {
            border-color: rgba(154, 123, 86, .55);
            background: var(--accent-light);
        }
        .favorite-scene-btn {
            width: 26px;
            height: 26px;
            border: 1px solid var(--line);
            border-radius: 3px;
            background: var(--surface);
            color: var(--muted);
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
        }
        .favorite-scene-btn.active {
            color: #8c6d1f;
            background: #fffdf5;
            border-color: rgba(140, 109, 31, .35);
        }
        .scene-choice strong {
            display: block;
            font-size: 12px;
            line-height: 1.25;
            word-break: break-word;
        }
        .scene-choice span {
            display: block;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.35;
        }
        .scene-choice-meta {
            color: var(--muted);
            font-size: 11px;
            white-space: nowrap;
        }
        .scene-browser-controls {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) minmax(220px, 320px) max-content;
            gap: 8px;
            align-items: center;
        }
        .scene-browser-controls input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 4px;
            padding: 8px 10px;
            background: var(--surface-soft);
            color: var(--ink);
            font-size: 13px;
            height: 38px;
        }
        .scene-browser-controls select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 4px;
            padding: 8px 32px 8px 10px;
            background-color: var(--surface-soft);
            color: var(--ink);
            font-size: 13px;
            height: 38px;
            cursor: pointer;
            outline: none;

            /* Custom clean dropdown arrow */
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239A7B56' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 14px;

            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .scene-browser-controls select:hover {
            border-color: var(--accent);
        }
        .scene-browser-controls select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(154, 123, 86, 0.15);
        }
        .scene-browser-controls .scene-filter-tabs {
            display: inline-flex;
            flex-wrap: nowrap;
            gap: 0;
            justify-content: flex-start;
            border: 1px solid var(--line);
            border-radius: 4px;
            overflow: hidden;
            background: var(--surface-soft);
            height: 38px;
            align-self: stretch;
        }
        .scene-browser-controls .scene-filter-tabs button {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            width: auto !important;
            min-width: 0 !important;
            height: 36px !important;
            min-height: 0 !important;
            border: 0;
            border-right: 1px solid var(--line);
            border-radius: 0;
            background: transparent;
            color: var(--muted);
            padding: 0 10px;
            margin: 0 !important;
            font-size: 10px;
            line-height: 1 !important;
            cursor: pointer;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: .04em;
            box-shadow: none;
        }
        .scene-browser-controls .scene-filter-tabs button:last-child {
            border-right: 0;
        }
        .scene-browser-controls .scene-filter-tabs button.active {
            background: var(--accent-light);
            color: var(--accent);
        }
        .scene-browser-count {
            color: var(--muted);
            font-size: 12px;
        }
        .combination-card .button-link {
            background: #f1eee8;
            border-color: var(--line);
            color: var(--muted);
            box-shadow: none;
            padding: 10px 14px;
            min-height: 0;
            margin-top: 0;
        }
        .combination-card .button-link:hover {
            background: var(--accent-light);
            border-color: rgba(154, 123, 86, .35);
            color: var(--accent);
            box-shadow: none;
        }
        .combination-card .combination-generate-btn {
            width: 100%;
            background: rgba(183, 127, 134, .18);
            border-color: rgba(183, 127, 134, .32);
            color: #8f5f67;
            font-weight: 800;
            letter-spacing: .08em;
        }
        .combination-card .combination-generate-btn:hover {
            background: rgba(183, 127, 134, .28);
            border-color: rgba(183, 127, 134, .48);
            color: #7e535b;
        }
        @media (max-width: 980px) {
            .review-grid,
            .scene-choice-grid {
                grid-template-columns: 1fr;
            }
            .thumb-row {
                grid-template-columns: 1fr;
            }
            .combination-plus {
                justify-self: center;
                transform: none;
            }
            .scene-browser-head {
                display: block;
            }
            .scene-browser-controls {
                grid-template-columns: 1fr;
            }
            .scene-filter-tabs {
                justify-content: flex-start;
            }
            .camera-form {
                grid-template-columns: 1fr;
            }
            .workspace-header,
            .workspace-header .topbar-actions {
                display: block;
            }
            .workspace-header .topbar-actions .button-link {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin: 6px 6px 0 0;
            }
            .compact-specs .specs-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (min-width: 981px) and (max-width: 1280px) {
            .review-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .thumb-row {
                grid-template-columns: minmax(112px, .44fr) 38px minmax(0, 1fr);
            }
            .combination-plus {
                width: 38px;
                height: 38px;
                font-size: 26px;
            }
        }
        .breadcrumb-steps {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-weight: 600;
        }
        .breadcrumb-steps span,
        .breadcrumb-steps a {
            color: var(--muted);
            text-decoration: none;
        }
        .breadcrumb-steps a:hover {
            color: var(--accent);
        }
        .breadcrumb-steps span.active {
            color: var(--accent);
            border-bottom: 1.5px solid var(--accent);
            padding-bottom: 2px;
        }
        .breadcrumb-steps .step-arrow {
            color: var(--line-dark);
            font-weight: normal;
        }
        .scene-header-v3 {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 36px;
            padding: 6px 0 24px;
            margin-bottom: 26px;
            border-bottom: 1px solid var(--line);
        }
        .scene-header-v3 .header-main-info {
            display: block;
            flex: 1;
            min-width: 0;
        }
        .scene-header-v3 .header-title-block {
            margin-bottom: 14px;
        }
        .scene-header-v3 .header-title-block h1 {
            font-size: 44px;
            line-height: 1;
            margin: 0;
            font-family: var(--font-serif);
            font-weight: 500;
        }
        .scene-header-v3 .header-desc-block {
            flex: 1;
            min-width: 0;
            max-width: 980px;
            padding-top: 4px;
        }
        .scene-page-desc {
            margin: 0;
            line-height: 1.55;
        }
        .scene-page-desc .desc-kicker {
            display: block;
            font-size: 14px;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .scene-page-desc .desc-instructions {
            display: block;
            max-width: 900px;
            font-size: 16px;
            font-weight: 600;
            color: var(--accent);
        }
        .scene-header-v3 .topbar-actions {
            flex-shrink: 0;
            display: flex;
            align-items: flex-end;
            gap: 12px;
            padding-top: 4px;
        }
        .scene-primary-action {
            flex: 0 0 150px;
            align-self: flex-start;
            padding-top: 2px;
            display: grid;
            gap: 8px;
        }
        .scene-provider-control {
            display: grid;
            gap: 4px;
            width: 150px;
            color: var(--muted);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .scene-provider-control select {
            width: 150px;
            height: 34px;
            padding: 0 28px 0 10px;
            border: 1px solid var(--line);
            border-radius: 4px;
            background: var(--surface);
            color: var(--text);
            font: inherit;
            font-size: 11px;
            letter-spacing: 0;
            text-transform: none;
        }
        .scene-primary-action #generate-all-btn {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            zoom: 1 !important;
            width: 150px !important;
            min-width: 150px !important;
            height: 150px !important;
            min-height: 150px !important;
            margin: 0 !important;
            padding: 20px !important;
            border-radius: 4px;
            font-size: 13px !important;
            line-height: 1.32 !important;
            text-align: center;
            white-space: normal;
            overflow-wrap: normal;
            background: #b77f86 !important;
            border-color: #b77f86 !important;
            color: #fffaf7 !important;
        }
        .scene-primary-action #generate-all-btn:hover {
            background: #a86f77 !important;
            border-color: #a86f77 !important;
        }
        .scene-direction-panel {
            display: block;
            margin: -8px 0 26px;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface);
            box-shadow: var(--shadow);
        }
        .scene-direction-browser > span {
            display: block;
            margin-bottom: 5px;
            color: var(--muted);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .scene-direction-browser {
            min-width: 0;
        }
        .scene-direction-strip {
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: 164px;
            gap: 8px;
            overflow-x: auto;
            padding: 1px 2px 10px;
            scrollbar-color: #d8cbbb transparent;
            scrollbar-width: thin;
        }
        .scene-direction-strip::-webkit-scrollbar {
            height: 6px;
        }
        .scene-direction-strip::-webkit-scrollbar-track {
            background: transparent;
        }
        .scene-direction-strip::-webkit-scrollbar-thumb {
            background: #d8cbbb;
            border-radius: 999px;
        }
        .scene-direction-strip::-webkit-scrollbar-thumb:hover {
            background: #bda98f;
        }
        .scene-direction-card {
            position: relative;
            display: block;
            min-width: 0;
            padding: 7px;
            border: 1px solid var(--line);
            border-radius: 4px;
            background: var(--surface-soft);
            color: var(--ink);
            text-decoration: none;
        }
        .scene-direction-card:hover,
        .scene-direction-card.active {
            border-color: var(--accent);
            background: #fbf7ef;
        }
        .scene-direction-card.active {
            box-shadow: inset 0 0 0 2px var(--accent);
        }
        .scene-direction-card.active::after {
            content: "Selected";
            position: absolute;
            top: 10px;
            left: 10px;
            padding: 4px 7px;
            border-radius: 3px;
            background: rgba(32, 24, 18, .86);
            color: #fffaf7;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .scene-direction-card img {
            display: block;
            width: 100%;
            aspect-ratio: 3 / 4;
            height: auto;
            object-fit: cover;
            border-radius: 2px;
            background: var(--surface);
        }
        .scene-direction-card strong {
            display: block;
            margin-top: 6px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 12px;
            line-height: 1.2;
        }
        .scene-direction-preview-grid {
            position: fixed;
            left: var(--scene-preview-left, 50%);
            top: var(--scene-preview-top, 160px);
            z-index: 200;
            display: none;
            width: 520px;
            max-width: calc(100vw - 32px);
            grid-template-columns: repeat(3, 1fr);
            gap: 5px;
            padding: 7px;
            border: 1px solid var(--line);
            border-radius: 5px;
            background: var(--surface);
            box-shadow: var(--shadow-hover);
            transform: translateX(-50%);
            pointer-events: none;
        }
        .scene-direction-card:hover .scene-direction-preview-grid,
        .scene-direction-card:focus-visible .scene-direction-preview-grid {
            display: grid;
        }
        .scene-direction-preview-grid img {
            width: 100%;
            height: 108px;
            object-fit: cover;
            border-radius: 2px;
            border: 1px solid var(--line);
        }
        .scene-select-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
        }
        @media (max-width: 980px) {
            .scene-header-v3 {
                flex-direction: column;
                align-items: stretch;
                gap: 16px;
            }
            .scene-header-v3 .topbar-actions {
                justify-content: flex-start;
                align-items: center;
            }
            .scene-primary-action {
                flex: 0 0 auto;
                width: 100%;
            }
            .scene-primary-action #generate-all-btn {
                width: 100% !important;
                min-width: 0 !important;
                height: 56px !important;
                min-height: 56px !important;
            }
        }
        @media (max-width: 768px) {
            .scene-header-v3 .header-main-info {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }
            .scene-header-v3 .header-desc-block {
                padding-top: 0;
            }
        }
        body.compact-scene-runner {
            min-height: 100vh;
            background: var(--bg);
        }
        body.compact-scene-runner .sidebar,
        body.compact-scene-runner .app-header,
        body.compact-scene-runner .alert-strip,
        body.compact-scene-runner .workspace,
        body.compact-scene-runner .generation-overlay {
            display: none !important;
        }
        body.compact-scene-runner .app-shell {
            display: block;
            min-height: 100vh;
        }
        body.compact-scene-runner .main-area {
            min-height: 100vh;
            margin: 0;
            padding: 0;
            display: grid;
            place-items: center;
            background: var(--bg);
        }
        .compact-scene-status {
            display: none;
        }
        body.compact-scene-runner .compact-scene-status {
            width: min(520px, calc(100vw - 32px));
            display: block;
            text-align: center;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: clamp(26px, 8vw, 48px);
            box-shadow: var(--shadow);
        }
        .compact-scene-status .generation-spinner {
            margin: 0 auto 22px;
        }
        .compact-scene-status h1 {
            margin: 0;
            font-family: var(--font-serif);
            font-size: clamp(34px, 8vw, 54px);
            font-weight: 500;
            line-height: 0.95;
        }
        .compact-scene-status p {
            margin: 14px auto 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.5;
        }
        .compact-view-progress {
            display: grid;
            gap: 10px;
            margin-top: 26px;
            text-align: left;
        }
        .compact-view-row {
            display: grid;
            gap: 7px;
        }
        .compact-view-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 12px;
            color: var(--muted);
        }
        .compact-view-head strong {
            color: var(--ink);
            font-size: 13px;
        }
        .compact-view-track {
            height: 5px;
            border-radius: 999px;
            background: var(--surface-soft);
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.04);
        }
        .compact-view-bar {
            width: 8%;
            height: 100%;
            border-radius: inherit;
            background: var(--line);
            transition: width 0.35s ease, background 0.35s ease;
        }
        .compact-view-row.creating .compact-view-bar,
        .compact-view-row.retrying .compact-view-bar {
            width: 58%;
            background: linear-gradient(90deg, var(--accent), rgba(183,127,134,0.35), var(--accent));
            background-size: 180% 100%;
            animation: compactProgress 1.15s linear infinite;
        }
        .compact-view-row.ready .compact-view-bar {
            width: 100%;
            background: var(--accent);
            animation: none;
        }
        .compact-view-row.failed .compact-view-bar {
            width: 100%;
            background: #b95c5c;
            animation: none;
        }
        .compact-tip {
            min-height: 38px;
            margin-top: 22px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }
        @keyframes compactProgress {
            from { background-position: 0% 0; }
            to { background-position: 180% 0; }
        }
    </style>
</head>
<body class="<?= $compactSceneFlow ? 'compact-scene-runner' : '' ?>">
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <?php if ($compactSceneFlow): ?>
            <section class="compact-scene-status" aria-live="polite">
                <div class="generation-spinner" aria-hidden="true"></div>
                <h1>Creating 4 scenes</h1>
                <p>Rendering four scene views.</p>
                <div class="compact-view-progress" aria-label="Scene progress">
                    <?php for ($compactViewIndex = 1; $compactViewIndex <= 4; $compactViewIndex++): ?>
                        <div class="compact-view-row queued" data-compact-view-row="<?= $compactViewIndex ?>">
                            <div class="compact-view-head">
                                <strong>View <?= $compactViewIndex ?></strong>
                                <span data-compact-view-status="<?= $compactViewIndex ?>">Queued</span>
                            </div>
                            <div class="compact-view-track"><div class="compact-view-bar"></div></div>
                        </div>
                    <?php endfor; ?>
                </div>
                <div class="compact-tip" id="compactSceneTip"></div>
            </section>
        <?php endif; ?>
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">Choose a visual direction and generate scene combinations for the active artwork.</div>

        <div class="workspace">
            <div class="scene-header-v3">
                <div class="header-main-info">
                    <div class="header-title-block">
                        <h1><?= h($scenePageTitle) ?></h1>
                    </div>
                    <div class="header-desc-block">
                        <p class="scene-page-desc">
                            <span class="desc-kicker">Choose a scene style for your artwork.</span>
                            <span class="desc-instructions">Each set combines your root artwork with a visual scene direction: architecture, light, atmosphere and spatial mood. The AI uses this reference to place your artwork naturally into high-end mockup environments, without changing the original artwork.</span>
                        </p>
                    </div>
                </div>
                <div class="scene-primary-action">
                    <button class="button-link" type="button" id="generate-all-btn" onclick="<?= $sceneSelectionFlow ? 'startCompactSceneFlow(this)' : 'generateAllCombinations(this)' ?>"><?= ($compactSceneFlow || $sceneSelectionFlow) ? 'Create 4 scenes' : 'Generate All Combinations' ?></button>
                </div>
            </div>

            <section class="scene-direction-panel" aria-label="Scene direction">
                <div class="scene-direction-browser">
                    <span>Choose a visual direction</span>
                    <div class="scene-direction-strip">
                        <?php foreach ($sceneDirectionOptions as $sceneOption): ?>
                            <?php
                            $slug = (string)$sceneOption['slug'];
                            $sceneUrl = 'mockup_combinations_review.php?id=' . (int)$id
                                . '&board=' . (int)$sceneBoardIndex
                                . '&world_mother_category=' . rawurlencode($slug)
                                . $generationProviderQuery
                                . ($sceneSelectionFlow ? '&scene_select=1&scene_limit=' . (int)$compactSceneLimit : '');
                            ?>
                            <a class="scene-direction-card <?= $slug === $selectedWorldMotherCategory ? 'active' : '' ?>" href="<?= h($sceneUrl) ?>">
                                <?php if ((string)$sceneOption['preview_url'] !== ''): ?>
                                    <img src="<?= h((string)$sceneOption['preview_url']) ?>" alt="">
                                <?php endif; ?>
                                <strong><?= h((string)$sceneOption['name']) ?></strong>
                                <?php if (!empty($sceneOption['preview_urls'])): ?>
                                    <span class="scene-direction-preview-grid" aria-hidden="true">
                                        <?php foreach ((array)$sceneOption['preview_urls'] as $previewUrl): ?>
                                            <img src="<?= h((string)$previewUrl) ?>" alt="">
                                        <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <label class="scene-select-hidden">
                        Scene direction
                        <select id="scene-select" aria-label="Select scene direction">
                            <?php foreach ($sceneDirectionOptions as $sceneOption): ?>
                                <option value="<?= h((string)$sceneOption['slug']) ?>" <?= (string)$sceneOption['slug'] === $selectedWorldMotherCategory ? 'selected' : '' ?>>
                                    <?= h((string)$sceneOption['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
            </section>

            <?php if (!empty($review['validation_notes'])): ?>
                <div class="notice warning">
                    <strong>Review notes:</strong>
                    <ul style="margin: 6px 0 0 18px;">
                        <?php foreach ((array)$review['validation_notes'] as $note): ?>
                            <li><?= h($note) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="review-grid">
                <?php $lastCameraGroupKey = null; ?>
                <?php foreach ($combinations as $combo): ?>
                    <?php
                    $idx = (int)$combo['combination_index'];
                    $worldImage = (string)$combo['world_mother_image_path'];
                    $worldImageUrl = world_mother_image_url($worldImage);
                    $generatedWorldMother = (array)($combo['world_mother_selection']['generated_world_mother'] ?? []);
                    $missingWorldMother = (array)($combo['world_mother_selection']['missing_world_mother'] ?? []);
                    $isGeneratedWorldMother = !empty($generatedWorldMother);
                    $isMissingWorldMother = !empty($missingWorldMother);
                    $currentVariantOffset = max(0, (int)($combo['world_mother_variant_offset'] ?? ($selectedWorldMotherVariants[$idx] ?? 0)));
                    $refreshVariantOffsets = [];
                    foreach ($combinations as $otherComboForVariantUrl) {
                        $otherIndexForVariantUrl = (int)($otherComboForVariantUrl['combination_index'] ?? 0);
                        if ($otherIndexForVariantUrl > 0) {
                            $refreshVariantOffsets[$otherIndexForVariantUrl] = max(0, (int)($otherComboForVariantUrl['world_mother_variant_offset'] ?? ($selectedWorldMotherVariants[$otherIndexForVariantUrl] ?? 0)));
                        }
                    }
                    $refreshVariantOffsets[$idx] = $currentVariantOffset + 1;
                    $refreshParams = [
                        'id' => (int)$id,
                        'board' => (int)$sceneBoardIndex,
                        'world_mother_category' => $selectedWorldMotherCategory,
                        'slot' => [],
                        'world_variant' => [],
                    ];
                    foreach ($combinations as $otherComboForUrl) {
                        $otherIndexForUrl = (int)($otherComboForUrl['combination_index'] ?? 0);
                        if ($otherIndexForUrl > 0) {
                            $refreshParams['slot'][$otherIndexForUrl] = (string)($otherComboForUrl['selected_camera_slot_id'] ?? '');
                        }
                    }
                    foreach ($refreshVariantOffsets as $variantIndex => $variantOffset) {
                        if ((int)$variantIndex > 0 && (int)$variantOffset > 0) {
                            $refreshParams['world_variant'][(int)$variantIndex] = (int)$variantOffset;
                        }
                    }
                    $refreshUrl = 'mockup_combinations_review.php?' . http_build_query($refreshParams);
                    $variantBaseParams = $refreshParams;
                    unset($variantBaseParams['world_variant'][$idx]);
                    $selectedWorldImagePath = (string)$combo['world_mother_image_path'];
                    $displayCameraName = trim((string)($combo['camera_slot_name'] ?? ''));
                    if ($displayCameraName === '') {
                        $displayCameraName = get_friendly_camera_name((string)($combo['selected_camera_slot_id'] ?? ''));
                    }
                    $cameraGroupKey = trim((string)($combo['camera_slot_group_id'] ?? ''));
                    if ($cameraGroupKey === '') {
                        $cameraGroupKey = 'group_' . (string)floor(($idx - 1) / 3);
                    }
                    $cameraGroupName = trim((string)($combo['camera_slot_group_name'] ?? ''));
                    if ($cameraGroupName === '') {
                        $cameraGroupName = 'Camera group ' . ((int)floor(($idx - 1) / 3) + 1);
                    }
                    $cameraVariantLabel = trim((string)($combo['camera_slot_variant_label'] ?? ''));
                    if ($cameraVariantLabel === '') {
                        $cameraVariantLabel = 'Set ' . $idx;
                    }
                    ?>
                    <?php if ($cameraGroupKey !== $lastCameraGroupKey): ?>
                        <div class="review-group-title"><?= h($cameraGroupName) ?></div>
                        <?php $lastCameraGroupKey = $cameraGroupKey; ?>
                    <?php endif; ?>
                    <?php $columnClass = (($idx - 1) % 3) === 0 ? 'edge-left' : (((($idx - 1) % 3) === 2) ? 'edge-right' : ''); ?>
                    <details class="combination-card <?= h($columnClass) ?>" data-combination-card data-combination-row="<?= h($cameraGroupKey) ?>" <?= $idx <= 3 ? 'open' : '' ?>>
                        <summary class="combination-head">
                            <div class="combination-title">
                                <span class="badge"><?= h($cameraVariantLabel) ?></span>
                                <h3><?= h($displayCameraName) ?></h3>
                            </div>
                        </summary>

                        <div class="combination-body">
                            <div class="thumb-row">
                                <div class="thumb-box root-reference-thumb">
                                    <?php if ($rootUrl !== ''): ?>
                                        <img src="<?= h($rootUrl) ?>" alt="">
                                    <?php endif; ?>
                                    <?php if (count($rootViewOptions) > 1): ?>
                                        <div class="root-thumb-picker" aria-label="Choose root view">
                                            <?php foreach ($rootViewOptions as $rootViewOption): ?>
                                                <?php
                                                $rootViewFile = (string)$rootViewOption['file_name'];
                                                $isActiveRootView = $rootViewFile === $currentRootFile;
                                                ?>
                                                <form method="post" action="mockup_combinations_review.php?id=<?= (int)$id ?>">
                                                    <input type="hidden" name="action" value="choose_scene_root_view">
                                                    <input type="hidden" name="board" value="<?= (int)$sceneBoardIndex ?>">
                                                    <input type="hidden" name="world_mother_category" value="<?= h($selectedWorldMotherCategory) ?>">
                                                    <input type="hidden" name="root_file" value="<?= h($rootViewFile) ?>">
                                                    <button
                                                        class="root-thumb-option <?= $isActiveRootView ? 'active' : '' ?>"
                                                        type="submit"
                                                        title="<?= h(scene_root_view_label((string)$rootViewOption['view_type'])) ?>"
                                                        aria-label="Use <?= h(scene_root_view_label((string)$rootViewOption['view_type'])) ?>"
                                                        <?= $isActiveRootView ? 'disabled' : '' ?>
                                                    >
                                                        <img src="<?= h('media.php?file=' . rawurlencode($rootViewFile)) ?>" alt="">
                                                        <span><?= h(scene_root_view_label((string)$rootViewOption['view_type'])) ?></span>
                                                    </button>
                                                </form>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="combination-plus" aria-label="plus"></div>
                                <div class="thumb-box scene-reference-thumb">
                                    <?php if ($worldImageUrl !== ''): ?>
                                        <img src="<?= h($worldImageUrl) ?>" alt="">
                                    <?php endif; ?>
                                    <?php if (count($selectedWorldMotherImages) > 1): ?>
                                        <div class="scene-thumb-picker" aria-label="Choose scene reference">
                                            <?php foreach ($selectedWorldMotherImages as $imagePosition => $sceneImage): ?>
                                                <?php
                                                $sceneImagePath = (string)($sceneImage['relative_path'] ?? '');
                                                $sceneImageUrl = world_mother_image_url($sceneImagePath);
                                                if ($sceneImageUrl === '') {
                                                    continue;
                                                }
                                                $variantUrlParams = $variantBaseParams;
                                                $variantOffset = variant_offset_for_world_image($idx, (int)$imagePosition, count($selectedWorldMotherImages));
                                                if ($variantOffset > 0) {
                                                    $variantUrlParams['world_variant'][$idx] = $variantOffset;
                                                }
                                                $variantUrl = 'mockup_combinations_review.php?' . http_build_query($variantUrlParams);
                                                ?>
                                                <a
                                                    class="scene-thumb-option <?= $sceneImagePath === $selectedWorldImagePath ? 'active' : '' ?>"
                                                    href="<?= h($variantUrl) ?>"
                                                    title="<?= h((string)($sceneImage['title'] ?? 'Scene reference')) ?>"
                                                    aria-label="Choose <?= h((string)($sceneImage['title'] ?? 'scene reference')) ?>"
                                                >
                                                    <img src="<?= h($sceneImageUrl) ?>" alt="">
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($isGeneratedWorldMother): ?>
                                <div class="auto-world-panel">
                                    <strong>Beta auto-generated scene mother</strong>
                                    This scene mother was created earlier. For this beta flow, prefer replacing it with a curated manual image if quality is not enough.
                                    <?php if ($worldImageUrl !== ''): ?>
                                        <br><a href="<?= h($worldImageUrl) ?>" target="_blank" rel="noopener">Open generated image</a>
                                    <?php endif; ?>
                                    <?php if (!empty($generatedWorldMother['audit_file'])): ?>
                                        <br>Audit: <code><?= h($generatedWorldMother['audit_file']) ?></code>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($isMissingWorldMother): ?>
                                <div class="auto-world-panel">
                                    <strong>Beta scene mother pending</strong>
                                    Add one image manually to <code><?= h($missingWorldMother['folder'] ?? ('storage/world_mothers/' . $combo['world_mother_category'])) ?></code>, then refresh. The system will not generate this scene mother automatically.
                                </div>
                            <?php endif; ?>

                            <?= AdminSceneEditor::render($user, (string)($combo['selected_camera_slot_id'] ?? ''), 'mockup_combinations_review.php?' . (string)($_SERVER['QUERY_STRING'] ?? ('id=' . (int)$id))) ?>

                            <form class="camera-form beta-hidden-stage" method="get" action="mockup_combinations_review.php">
                                <input type="hidden" name="id" value="<?= (int)$id ?>">
                                <input type="hidden" name="board" value="<?= (int)$sceneBoardIndex ?>">
                                <input type="hidden" name="world_mother_category" value="<?= h($selectedWorldMotherCategory) ?>">
                                <?php foreach ($combinations as $other): ?>
                                    <?php if ((int)$other['combination_index'] !== $idx): ?>
                                        <input type="hidden" name="slot[<?= (int)$other['combination_index'] ?>]" value="<?= h($other['selected_camera_slot_id']) ?>">
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <label>
                                    <strong style="display:block; font-size:10px; text-transform:uppercase; color:var(--muted); margin-bottom:4px;">Selected Camera Slot</strong>
                                    <select name="slot[<?= $idx ?>]" onchange="this.form.submit()">
                                        <?php foreach ($cameraSlots as $slot): ?>
                                            <?php $slotId = (string)($slot['slot_id'] ?? ''); ?>
                                            <option value="<?= h($slotId) ?>" <?= $slotId === (string)$combo['selected_camera_slot_id'] ? 'selected' : '' ?>>
                                                <?= h(get_friendly_camera_name($slotId)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="button-link secondary" type="submit">Refresh Preview</button>
                            </form>

                            <div class="beta-hidden-stage">
                                <strong style="display:block; font-size:10px; text-transform:uppercase; color:var(--muted); margin-bottom:6px;">Final Prompt Preview</strong>
                                <textarea class="prompt-preview" readonly><?= h($combo['final_prompt_preview']) ?></textarea>
                            </div>

                            <?php if (!empty($combo['validation_notes'])): ?>
                                <ul class="notes">
                                    <?php foreach ((array)$combo['validation_notes'] as $note): ?>
                                        <li><?= h($note) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <div>
                                <input
                                    type="hidden"
                                    class="world-mother-scale-input"
                                    id="world-mother-scale-<?= $idx ?>"
                                    value="1.0"
                                >
                                <div id="prepare-result-<?= $idx ?>" class="prepare-result"></div>
                                <button
                                    class="button-link combination-generate-btn"
                                    type="button"
                                    data-index="<?= $idx ?>"
                                    data-artwork-id="<?= (int)$id ?>"
                                    data-camera-slot="<?= h($combo['selected_camera_slot_id']) ?>"
                                    data-camera-name="<?= h($displayCameraName) ?>"
                                    data-world-mother-category="<?= h($selectedWorldMotherCategory) ?>"
                                    data-world-mother-variant="<?= $currentVariantOffset ?>"
                                    data-scene-board="<?= (int)$sceneBoardIndex ?>"
                                    data-validation-notes="<?= h(implode(' | ', array_map('strval', (array)($combo['validation_notes'] ?? [])))) ?>"
                                    onclick="prepareCombination(this)"
                                    <?= empty($combo['generation_ready']) ? 'disabled' : '' ?>
                                >Generate This Combination</button>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</div>

<div class="generation-overlay" id="generation-overlay" role="status" aria-live="polite" aria-hidden="true">
    <div class="generation-overlay-card">
        <div class="generation-spinner" aria-hidden="true"></div>
        <strong id="generation-overlay-title">Generating scenes</strong>
        <p id="generation-overlay-message">Preparing the selected artwork and scene reference.</p>
        <div class="overlay-actions">
            <button type="button" id="overlay-minimize-btn" class="button-link secondary" onclick="toggleOverlayMinimize(event)">Minimizar</button>
            <button type="button" id="overlay-maximize-btn" class="button-link secondary" style="display: none;" onclick="toggleOverlayMaximize(event)">Ver Detalles</button>
        </div>
    </div>
</div>

<script>
const ACTIVE_ARTWORK_ROOT_FILE = <?= json_encode(basename((string)$artwork['root_file'])) ?>;
const MOCKUP_BATCH_WORKER_COUNT = <?= (int)ProviderSettings::mockupWorkerCount() ?>;
const GENERATION_PROVIDER = <?= json_encode($selectedGenerationProvider) ?>;
const GENERATION_PROVIDER_LABEL = GENERATION_PROVIDER === 'openai' ? 'OpenAI' : 'Vertex';
const SCENE_SELECTION_FLOW = <?= $sceneSelectionFlow ? 'true' : 'false' ?>;
const SELECTED_SCENE_CATEGORY = <?= json_encode($selectedWorldMotherCategory, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const USER_SCENE_FLOW = <?= $compactSceneFlow ? 'true' : 'false' ?>;
const USER_SCENE_AUTO_GENERATE = <?= $autoGenerateSceneFlow ? 'true' : 'false' ?>;
const USER_SCENE_LIMIT = <?= (int)$compactSceneLimit ?>;
const SCENE_RESULTS_URL = <?= json_encode(
    'mockup_combination_results.php?id=' . (int)$id
    . '&board=' . (int)$sceneBoardIndex
    . '&generation_provider=' . rawurlencode($selectedGenerationProvider)
    . ($selectedWorldMotherCategory !== '' ? '&world_mother_category=' . rawurlencode($selectedWorldMotherCategory) : '')
    . ($compactSceneFlow ? '&compact=1&scene_limit=' . (int)$compactSceneLimit : ''),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) ?>;
const generationOverlay = document.getElementById('generation-overlay');
const generationOverlayTitle = document.getElementById('generation-overlay-title');
const generationOverlayMessage = document.getElementById('generation-overlay-message');

let isGenerationRunning = false;

window.addEventListener('beforeunload', function (e) {
    if (isGenerationRunning) {
        e.preventDefault();
        e.returnValue = 'The generation requests are still being registered. Wait a moment until they appear in the background activity indicator.';
        return e.returnValue;
    }
});

function toggleOverlayMinimize(e) {
    if (e) e.stopPropagation();
    const overlay = document.getElementById('generation-overlay');
    const minBtn = document.getElementById('overlay-minimize-btn');
    const maxBtn = document.getElementById('overlay-maximize-btn');
    if (!overlay) return;

    overlay.classList.add('minimized');
    if (minBtn) minBtn.style.display = 'none';
    if (maxBtn) maxBtn.style.display = 'inline-flex';
}

function toggleOverlayMaximize(e) {
    if (e) e.stopPropagation();
    const overlay = document.getElementById('generation-overlay');
    const minBtn = document.getElementById('overlay-minimize-btn');
    const maxBtn = document.getElementById('overlay-maximize-btn');
    if (!overlay) return;

    overlay.classList.remove('minimized');
    if (minBtn) minBtn.style.display = 'inline-flex';
    if (maxBtn) maxBtn.style.display = 'none';
}

function showGenerationOverlay(title, message) {
    if (USER_SCENE_FLOW) return;
    if (!generationOverlay) return;
    generationOverlayTitle.textContent = title || 'Generating scenes';
    generationOverlayMessage.textContent = message || 'Preparing the selected artwork and scene reference.';
    generationOverlay.classList.add('active');
    generationOverlay.setAttribute('aria-hidden', 'false');
}

function hideGenerationOverlay() {
    if (USER_SCENE_FLOW) return;
    if (!generationOverlay) return;
    generationOverlay.classList.remove('active');
    generationOverlay.setAttribute('aria-hidden', 'true');
    toggleOverlayMaximize();
}

function prepareCombination(btn, skipConfirm = false) {
    const cameraName = btn.getAttribute('data-camera-name') || 'selected camera';
    const cameraSlot = btn.getAttribute('data-camera-slot') || '';
    const label = cameraName + (cameraSlot ? ' [' + cameraSlot + ']' : '');
    if (!skipConfirm && !confirm('Generate this camera now?\n\n' + label + '\n\nThis may consume a real API credit when real API mode is enabled.')) {
        return;
    }
    if (!skipConfirm) {
        showGenerationOverlay('Generating scene', 'Working on ' + cameraName + '. This can take a moment.');
        isGenerationRunning = true;
    }
    const generation = runCombinationGeneration(btn);
    if (skipConfirm) {
        return generation;
    }

    return generation.then(result => {
        const index = btn.getAttribute('data-index');
        const status = document.getElementById('prepare-result-' + index);
        btn.textContent = 'Working in background';
        if (status) {
            status.textContent = 'Task registered. You can continue through the app; a notice will appear when the result is ready.';
        }
        return result;
    });
}

function runCombinationGeneration(btn) {
    const index = btn.getAttribute('data-index');
    const status = document.getElementById('prepare-result-' + index);
    const formData = new FormData();
    formData.append('artwork_id', btn.getAttribute('data-artwork-id'));
    formData.append('combination_index', index);
    formData.append('camera_slot_id', btn.getAttribute('data-camera-slot'));
    formData.append('world_mother_category', btn.getAttribute('data-world-mother-category'));
    formData.append('world_mother_variant_offset', btn.getAttribute('data-world-mother-variant') || '0');
    formData.append('board', btn.getAttribute('data-scene-board') || '1');
    formData.append('generation_provider', GENERATION_PROVIDER);
    const scaleInput = document.getElementById('world-mother-scale-' + index);
    if (scaleInput && scaleInput.value) {
        formData.append('world_mother_scale', scaleInput.value);
    }

    btn.disabled = true;
    const originalText = btn.textContent;
    btn.textContent = 'Generating...';
    status.textContent = 'Generating image from root artwork, world mother reference, selected camera, and ADMIN prompt.';
    console.info('[scene-generation] request start', { index: index, camera: btn.getAttribute('data-camera-name') || '', provider: GENERATION_PROVIDER });

    return fetch('generate_mockup_combination.php', { method: 'POST', body: formData })
        .then(response => response.text().then(text => {
            let parsed;
            try { parsed = JSON.parse(text); } catch (err) { throw new Error(text.substring(0, 220)); }
            return { status: response.status, body: parsed };
        }))
        .then(result => {
            if (result.status === 200 && result.body.ok) {
                if (result.body.enqueued) {
                    const jobId = result.body.job_id;
                    status.textContent = 'Queued. It will continue if you leave this page.';
                    btn.textContent = 'In background';
                    window.artworkGenerationTracker?.trackJobs([jobId]);
                    console.info('[scene-generation] request queued', { index: index, jobId: jobId });
                    return result.body;
                } else {
                    console.info('[scene-generation] request done', { index: index, enqueued: false });
                    status.innerHTML = (result.body.message || 'Image generated.') + ' <a href="' + result.body.results_url + '">Evaluate results</a>';
                    btn.textContent = 'Generated';
                    return result.body;
                }
            } else {
                status.textContent = (result.body && result.body.error) ? result.body.error : 'Preparation failed.';
                btn.disabled = false;
                btn.textContent = originalText;
                throw new Error(status.textContent);
            }
        })
        .catch(err => {
            status.textContent = 'Preparation failed: ' + err.message;
            btn.disabled = false;
            btn.textContent = originalText;
            throw err;
        })
        .finally(() => {
            if (!btn.dataset.batchGeneration) {
                isGenerationRunning = false;
                hideGenerationOverlay();
            }
        });
}

function isRetryableGenerationError(err) {
    const message = err && err.message ? err.message : '';
    return /429|RESOURCE_EXHAUSTED|quota|rate limit|temporar/i.test(message);
}

function waitForRetry(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function startCompactSceneFlow(btn) {
    const slug = SELECTED_SCENE_CATEGORY || '';
    if (slug === '') {
        alert('Choose a scene style before creating the mockups.');
        return;
    }

    if (btn) btn.disabled = true;
    window.location.href = 'mockup_combinations_review.php?id=<?= (int)$id ?>&board=<?= (int)$sceneBoardIndex ?>'
        + '&world_mother_category=' + encodeURIComponent(slug)
        + '&generation_provider=' + encodeURIComponent(GENERATION_PROVIDER)
        + '&auto_generate=1&compact=1&scene_limit=' + USER_SCENE_LIMIT;
}

function setCompactViewState(index, state, label) {
    if (!USER_SCENE_FLOW) return;
    const row = document.querySelector('[data-compact-view-row="' + index + '"]');
    const status = document.querySelector('[data-compact-view-status="' + index + '"]');
    if (!row || !status) return;
    row.classList.remove('queued', 'creating', 'ready', 'retrying', 'failed');
    row.classList.add(state);
    status.textContent = label;
}

const compactSceneTips = [
    'Use a clear photo with the artwork filling most of the frame.',
    'Scene views explore different angles and compositions for the same artwork.',
    'Scale helps the mockups keep the artwork believable in each space.',
    'You can create 4 more views after the first set is ready.'
];
let compactSceneTipIndex = 0;

function rotateCompactSceneTip() {
    const target = document.getElementById('compactSceneTip');
    if (!target || !USER_SCENE_FLOW) return;
    target.textContent = compactSceneTips[compactSceneTipIndex % compactSceneTips.length];
    compactSceneTipIndex++;
}

async function generateAllCombinations(btn) {
    let buttons = Array.from(document.querySelectorAll('button[data-index][data-artwork-id][data-camera-slot]:not([disabled])'));
    if (USER_SCENE_FLOW) {
        buttons = buttons.slice(0, USER_SCENE_LIMIT);
    }
    if (buttons.length === 0) {
        const disabled = Array.from(document.querySelectorAll('button[data-index][data-artwork-id][data-camera-slot][disabled]'));
        const reasons = disabled
            .map(button => button.getAttribute('data-validation-notes') || '')
            .filter(Boolean)
            .filter((reason, index, list) => list.indexOf(reason) === index)
            .slice(0, 6);
        alert(reasons.length
            ? 'No combinations are available to generate.\n\nReason:\n- ' + reasons.join('\n- ')
            : 'No combinations are available to generate. Open one scene card to inspect its validation notes.');
        return;
    }
    const confirmText = USER_SCENE_FLOW
        ? 'Create ' + buttons.length + ' scenes with ' + GENERATION_PROVIDER_LABEL + ' now?'
        : 'Generate all ' + buttons.length + ' combinations with ' + GENERATION_PROVIDER_LABEL + '? This may consume one real API credit per combination when real API mode is enabled.';
    const shouldConfirmBatch = btn.getAttribute('data-skip-batch-confirm') !== '1';
    if (shouldConfirmBatch && !confirm(confirmText)) {
        return;
    }

    btn.disabled = true;
    const originalText = btn.textContent;
    let successCount = 0;
    let failCount = 0;
    const failures = [];

    isGenerationRunning = true;

    const workerCount = Math.max(1, Math.min(MOCKUP_BATCH_WORKER_COUNT || 1, buttons.length));
    console.info('[scene-generation] starting batch', {
        userSceneFlow: USER_SCENE_FLOW,
        requestedScenes: buttons.length,
        workerCount: workerCount
    });
    let nextIndex = 0;
    let completedCount = 0;
    btn.textContent = 'Generating 0 / ' + buttons.length + '...';
    showGenerationOverlay(
        USER_SCENE_FLOW ? 'Creating 4 scenes' : 'Generating scenes',
        'Registering ' + buttons.length + ' tasks. Queued 0 of ' + buttons.length + '.'
    );

    const runNext = async () => {
        while (nextIndex < buttons.length) {
            const currentIndex = nextIndex++;
            const comboBtn = buttons[currentIndex];
            const compactViewIndex = currentIndex + 1;
            comboBtn.dataset.batchGeneration = '1';
            setCompactViewState(compactViewIndex, 'creating', 'Creating');
            btn.textContent = 'Generating ' + completedCount + ' / ' + buttons.length + '...';
            showGenerationOverlay(
                USER_SCENE_FLOW ? 'Creating 4 scenes' : 'Generating scenes',
                'Registering tasks. Queued ' + completedCount + ' of ' + buttons.length + '.'
            );
            try {
                try {
                    await prepareCombination(comboBtn, true);
                } catch (err) {
                    if (!isRetryableGenerationError(err)) {
                        throw err;
                    }

                    const retryDelay = 12000 + Math.floor(Math.random() * 8000);
                    const cameraName = comboBtn.getAttribute('data-camera-name') || 'selected camera';
                    showGenerationOverlay(
                        'Retrying one scene',
                        GENERATION_PROVIDER_LABEL + ' quota pushed back on ' + cameraName + '. Waiting ' + Math.round(retryDelay / 1000) + 's before retry.'
                    );
                    setCompactViewState(compactViewIndex, 'retrying', 'Retrying');
                    await waitForRetry(retryDelay);
                    setCompactViewState(compactViewIndex, 'creating', 'Creating');
                    await prepareCombination(comboBtn, true);
                }
                successCount++;
                setCompactViewState(compactViewIndex, 'queued', 'In background');
            } catch (err) {
                failCount++;
                setCompactViewState(compactViewIndex, 'failed', 'Failed');
                failures.push({
                    index: comboBtn.getAttribute('data-index') || '?',
                    camera: comboBtn.getAttribute('data-camera-name') || 'selected camera',
                    error: err && err.message ? err.message : 'Unknown error',
                });
            } finally {
                completedCount++;
                btn.textContent = 'Generating ' + completedCount + ' / ' + buttons.length + '...';
                showGenerationOverlay(
                    USER_SCENE_FLOW ? 'Creating 4 scenes' : 'Generating scenes',
                    'Registering tasks. Queued ' + completedCount + ' of ' + buttons.length + '.'
                );
                delete comboBtn.dataset.batchGeneration;
            }
        }
    };

    await Promise.all(Array.from({ length: workerCount }, () => runNext()));

    btn.disabled = false;
    btn.textContent = originalText;
    isGenerationRunning = false;
    hideGenerationOverlay();

    if (successCount > 0) {
        if (failures.length > 0) {
            let summary = (USER_SCENE_FLOW
                ? successCount + ' scenes registered. Failed to register: ' + failCount + '.'
                : 'Tasks registered: ' + successCount + ', failed to register: ' + failCount + '.')
                + '\n\nFailed combinations:\n' + failures
                .slice(0, 8)
                .map(item => '- #' + item.index + ' ' + item.camera + ': ' + item.error.substring(0, 600))
                .join('\n')
                + '\n\nRegistered tasks will continue in the background.';
            alert(summary);
        }
        btn.textContent = successCount === 1 ? '1 TASK IN BACKGROUND' : successCount + ' TASKS IN BACKGROUND';
        window.artworkGenerationTracker?.refresh(100);
        window.setTimeout(() => {
            btn.disabled = false;
            btn.textContent = originalText;
        }, 3200);
        return;
    } else {
        let summary = 'No combinations were generated.';
        if (failures.length > 0) {
            summary += '\n\nFailed combinations:\n' + failures
                .slice(0, 8)
                .map(item => '- #' + item.index + ' ' + item.camera + ': ' + item.error.substring(0, 600))
                .join('\n');
        } else {
            summary += ' Check the messages on each card.';
        }
        alert(summary);
    }
}

if (USER_SCENE_AUTO_GENERATE) {
    window.addEventListener('DOMContentLoaded', () => {
        rotateCompactSceneTip();
        setInterval(rotateCompactSceneTip, 5200);
        const btn = document.getElementById('generate-all-btn');
        if (!btn) return;
        btn.setAttribute('data-skip-batch-confirm', '1');
        generateAllCombinations(btn);
    });
}

const sceneSelect = document.getElementById('scene-select');
if (sceneSelect) {
    sceneSelect.addEventListener('change', () => {
        const slug = sceneSelect.value || '';
        if (slug !== '') {
            window.location.href = 'mockup_combinations_review.php?id=<?= (int)$id ?>&board=<?= (int)$sceneBoardIndex ?>&world_mother_category='
                + encodeURIComponent(slug)
                + '&generation_provider=' + encodeURIComponent(GENERATION_PROVIDER)
                + (SCENE_SELECTION_FLOW ? '&scene_select=1&scene_limit=' + USER_SCENE_LIMIT : '');
        }
    });
}

document.querySelectorAll('.scene-direction-card').forEach(card => {
    const preview = card.querySelector('.scene-direction-preview-grid');
    if (!preview) return;

    const positionPreview = () => {
        const rect = card.getBoundingClientRect();
        const previewWidth = Math.min(520, window.innerWidth - 32);
        let left = rect.left + (rect.width / 2);
        left = Math.max(16 + (previewWidth / 2), Math.min(window.innerWidth - 16 - (previewWidth / 2), left));

        const belowTop = rect.bottom + 10;
        const previewHeight = Math.min(470, preview.scrollHeight || 360);
        const fitsBelow = belowTop + previewHeight < window.innerHeight - 16;
        const top = fitsBelow ? belowTop : Math.max(16, rect.top - previewHeight - 10);

        preview.style.setProperty('--scene-preview-left', left + 'px');
        preview.style.setProperty('--scene-preview-top', top + 'px');
    };

    card.addEventListener('mouseenter', positionPreview);
    card.addEventListener('focusin', positionPreview);
});

document.querySelectorAll('[data-combination-card]').forEach(card => {
    card.addEventListener('toggle', () => {
        if (card.dataset.syncingRow === '1') return;
        const row = card.getAttribute('data-combination-row');
        if (row === null) return;
        document.querySelectorAll('[data-combination-card][data-combination-row="' + row + '"]').forEach(rowCard => {
            if (rowCard === card) return;
            rowCard.dataset.syncingRow = '1';
            rowCard.open = card.open;
            window.setTimeout(() => {
                delete rowCard.dataset.syncingRow;
            }, 0);
        });
    });
});

</script>
</body>
</html>
