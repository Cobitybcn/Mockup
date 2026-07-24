<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::ADMIN_SCENE_LIBRARY, 'Scene Studio');

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function wms_media_url(string $path, int $width = 640): string
{
    $relativePath = wms_storage_relative_path($path);
    if ($relativePath === '') {
        return '';
    }

    $width = max(240, min(1200, $width));
    return 'world_mother_media.php?file=' . rawurlencode($relativePath) . '&thumb=1&w=' . $width;
}

function wms_storage_relative_path(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') {
        return '';
    }

    foreach (['storage/world_mothers/', 'storage/world_mother_uploads/'] as $prefix) {
        $prefixPos = strpos($path, $prefix);
        if ($prefixPos !== false) {
            return substr($path, $prefixPos);
        }
    }

    return '';
}

function wms_ensure_local_storage_file(string $path): string
{
    if (is_file($path)) {
        return $path;
    }

    $relativePath = wms_storage_relative_path($path);
    if ($relativePath === '') {
        return $path;
    }
    $localPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($localPath) && StorageService::isGcsActive()) {
        StorageService::downloadFile($relativePath, $localPath);
    }
    return $localPath;
}

function wms_analysis_path(string $jobId): string
{
    $fileName = basename($jobId) . '.analysis.json';
    $localPath = __DIR__ . '/analysis/world-mother-studio/' . $fileName;
    if (!is_file($localPath) && StorageService::isGcsActive()) {
        StorageService::downloadFile('analysis/world-mother-studio/' . $fileName, $localPath);
    }
    return $localPath;
}

function wms_upload_file(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('The reference image could not be uploaded.');
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new RuntimeException('Formato no permitido. Usa JPG, PNG o WEBP.');
    }
    $dir = __DIR__ . '/storage/world_mother_uploads';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('The upload folder could not be created.');
    }
    $name = 'world_mother_ref_' . date('Ymd_His') . '_' . random_int(1000, 9999) . '.' . $ext;
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file((string)$file['tmp_name'], $path)) {
        throw new RuntimeException('The uploaded image could not be saved.');
    }
    if (StorageService::isGcsActive()) {
        $storageKey = 'storage/world_mother_uploads/' . $name;
        if (!StorageService::uploadFile($storageKey, $path)) {
            @unlink($path);
            throw new RuntimeException('The reference could not be saved to persistent storage.');
        }
    }
    return $path;
}

/**
 * @return array<int,string>
 */
function wms_upload_files(array $files): array
{
    if (isset($files['tmp_name']) && is_array($files['tmp_name'])) {
        $paths = [];
        $count = count($files['tmp_name']);
        if ($count < 1 || $count > 4) {
            throw new RuntimeException('Upload between 1 and 4 reference images.');
        }
        for ($i = 0; $i < $count; $i++) {
            $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $paths[] = wms_upload_file([
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $error,
                'size' => $files['size'][$i] ?? 0,
            ]);
        }
        if (!$paths) {
            throw new RuntimeException('Upload at least one reference image.');
        }
        return $paths;
    }

    return [wms_upload_file($files)];
}

function wms_resolve_scene_slug(WorldMotherLibrary $library, string $value): string
{
    $value = trim(str_replace(['\\', '/'], '', $value));
    if ($value === '') {
        return '';
    }
    $normalized = WorldMotherGenerator::safeSlug($value);
    foreach ($library->categories() as $category) {
        $slug = (string)($category['category_slug'] ?? '');
        if ($slug === $value || WorldMotherGenerator::safeSlug($slug) === $normalized) {
            return $slug;
        }
    }
    return '';
}

$library = new WorldMotherLibrary();
$generator = new WorldMotherGenerator($library);
$sceneRanking = new SceneRankingService(Database::connection());
$sceneDiversity = new SceneReferenceDiversityService(Database::connection());
$error = '';
$notice = (string)($_SESSION['world_mother_studio_notice'] ?? '');
unset($_SESSION['world_mother_studio_notice']);
$sceneAdminCsrf = (string)($_SESSION['world_mother_admin_csrf'] ?? '');
if ($sceneAdminCsrf === '') {
    $sceneAdminCsrf = bin2hex(random_bytes(24));
    $_SESSION['world_mother_admin_csrf'] = $sceneAdminCsrf;
}
$analysis = null;
$jobId = trim((string)($_POST['job_id'] ?? $_GET['job_id'] ?? ''));
$referencePath = '';
$referencePaths = [];
$generated = null;
$requestedSceneSlug = trim(str_replace(['\\', '/'], '', (string)($_POST['return_scene'] ?? $_GET['scene'] ?? '')));
$redirectSceneSlug = $requestedSceneSlug;

try {
    $action = trim((string)($_POST['action'] ?? ''));
    $adminActions = ['create_category', 'rename_category', 'merge_category', 'delete_category', 'delete_variant', 'generate_similar', 'transform_reference', 'rebuild_index', 'upload_variant', 'update_ranking', 'update_similarity_groups'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, $adminActions, true)) {
        if (!Auth::isAdmin($user)) {
            throw new RuntimeException('Only an administrator can manage the scene library.');
        }
        if (!hash_equals($sceneAdminCsrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('The scene management session expired. Reload the page and try again.');
        }

        if ($action === 'update_similarity_groups') {
            $sourceCategory = trim((string)($_POST['source_category'] ?? ''));
            $sceneImages = $library->imagesForCategory($sourceCategory);
            if (!$sceneImages) {
                throw new RuntimeException('The selected scene has no references to organize.');
            }
            $referenceKeys = array_values(array_map('strval', (array)($_POST['reference_key'] ?? [])));
            $similarityGroups = array_values(array_map('strval', (array)($_POST['similarity_group'] ?? [])));
            $groupsByReferenceKey = [];
            foreach ($referenceKeys as $position => $referenceKey) {
                $groupsByReferenceKey[$referenceKey] = (string)($similarityGroups[$position] ?? '');
            }
            $updatedCount = $sceneDiversity->updateSimilarityGroups($sceneImages, $groupsByReferenceKey);
            $notice = sprintf('Reference diversity updated for %d images in %s.', $updatedCount, $sourceCategory);
        } elseif ($action === 'update_ranking') {
            $updatedRanking = $sceneRanking->updateProfile(
                (string)($_POST['source_category'] ?? ''),
                (int)($_POST['featured_score'] ?? 0),
                (string)($_POST['featured_until'] ?? ''),
                (int)($_POST['editorial_score'] ?? 50)
            );
            $notice = sprintf(
                'Scene ranking updated: featured %d, editorial %d.',
                (int)($updatedRanking['featured_score'] ?? 0),
                (int)($updatedRanking['editorial_score'] ?? 50)
            );
        } elseif ($action === 'create_category') {
            $createdCategory = $library->createCategory((string)($_POST['category_name'] ?? ''));
            $notice = 'Scene created: ' . (string)($createdCategory['category_name'] ?? $createdCategory['category_slug'] ?? '');
        } elseif ($action === 'rename_category') {
            $sourceCategory = (string)($_POST['source_category'] ?? '');
            $renamedCategory = $library->renameCategory(
                $sourceCategory,
                (string)($_POST['new_category_name'] ?? '')
            );
            $redirectSceneSlug = (string)($renamedCategory['category_slug'] ?? '');
            $sceneRanking->renameCategory($sourceCategory, $redirectSceneSlug);
            $sceneDiversity->renameCategory($sourceCategory, $redirectSceneSlug);
            $notice = 'Scene renamed to ' . (string)($renamedCategory['category_name'] ?? $renamedCategory['category_slug'] ?? '') . '.';
        } elseif ($action === 'merge_category') {
            $merge = $library->mergeCategory(
                (string)($_POST['source_category'] ?? ''),
                (string)($_POST['target_category'] ?? '')
            );
            $sceneRanking->mergeCategory((string)($merge['source_slug'] ?? ''), (string)($merge['target_slug'] ?? ''));
            $notice = sprintf(
                'Scenes unified: %d images moved to %s%s.',
                (int)($merge['moved_count'] ?? 0),
                (string)($merge['target_slug'] ?? ''),
                (int)($merge['duplicate_count'] ?? 0) > 0 ? ' and ' . (int)$merge['duplicate_count'] . ' identical duplicates removed' : ''
            );
        } elseif ($action === 'delete_category') {
            if ((string)($_POST['confirm_delete'] ?? '') !== 'yes') {
                throw new RuntimeException('Explicit deletion confirmation is required.');
            }
            $deleted = $library->deleteCategory((string)($_POST['source_category'] ?? ''));
            $sceneRanking->deleteCategory((string)($deleted['category_slug'] ?? ''));
            $sceneDiversity->deleteCategory((string)($deleted['category_slug'] ?? ''));
            $notice = sprintf(
                'Scene deleted: %s (%d images removed).',
                (string)($deleted['category_slug'] ?? ''),
                (int)($deleted['deleted_images'] ?? 0)
            );
            $redirectSceneSlug = '';
        } elseif ($action === 'delete_variant') {
            $deleted = $library->deleteImage(
                (string)($_POST['source_category'] ?? ''),
                (string)($_POST['file_name'] ?? '')
            );
            $notice = 'Scene reference removed: ' . (string)($deleted['file_name'] ?? '') . '.';
        } elseif ($action === 'generate_similar') {
            $target = wms_resolve_scene_slug($library, (string)($_POST['target_category'] ?? ''));
            $prompt = trim((string)($_POST['similar_prompt'] ?? ''));
            $count = max(1, min(4, (int)($_POST['variant_count'] ?? 1)));
            $sceneImages = $library->imagesForCategory($target);
            if (!$sceneImages) {
                throw new RuntimeException('Add at least one visual reference before creating similar styles.');
            }
            if ($prompt === '') {
                throw new RuntimeException('Describe the variation you want to explore.');
            }
            $referencePaths = [];
            foreach (array_slice($sceneImages, 0, 4) as $sceneImage) {
                $localReference = wms_ensure_local_storage_file((string)($sceneImage['absolute_path'] ?? $sceneImage['relative_path'] ?? ''));
                if (is_file($localReference)) {
                    $referencePaths[] = $localReference;
                }
            }
            if (!$referencePaths) {
                throw new RuntimeException('The scene references could not be prepared for generation.');
            }
            $generated = $generator->generateOriginalWorldMotherSet($referencePaths, $target, [
                'scene_type' => ucwords(str_replace('_', ' ', $target)),
                'architecture_language' => 'Preserve the established architectural identity visible in the supplied scene references.',
                'wall_language' => 'Preserve the scene family while creating a distinct artwork-ready spatial interpretation.',
                'negative_risks' => ['no existing artwork', 'no logos', 'no readable text', 'no people', 'no visual clone'],
            ], [
                'notes' => $prompt,
                'count' => $count,
            ]);
            $notice = $count === 1
                ? 'A new related scene style was created.'
                : $count . ' new related scene styles were created.';
        } elseif ($action === 'transform_reference') {
            $target = wms_resolve_scene_slug($library, (string)($_POST['target_category'] ?? ''));
            $prompt = trim((string)($_POST['transform_prompt'] ?? ''));
            if ($target === '') {
                throw new RuntimeException('Select an existing scene before transforming an image.');
            }
            if ($prompt === '') {
                throw new RuntimeException('Describe how the uploaded image should become a new scene source.');
            }
            if (!isset($_FILES['source_image'])) {
                throw new RuntimeException('Choose the source image you want to transform.');
            }
            $sourcePath = wms_upload_file((array)$_FILES['source_image']);
            $sourceAnalysis = [
                'scene_type' => ucwords(str_replace('_', ' ', $target)),
                'architecture_language' => 'Use the uploaded image as visual evidence, then rebuild it according to the transformation prompt.',
                'wall_language' => 'Create a credible artwork-ready wall or architectural plane.',
                'negative_risks' => ['no existing artwork', 'no logos', 'no readable text', 'no people', 'no literal copy'],
            ];
            $generated = $generator->generateOriginalWorldMother($sourcePath, $target, $sourceAnalysis, [
                'notes' => $prompt,
            ]);
            $notice = 'The uploaded image was transformed into a new source for this scene.';
        } elseif ($action === 'upload_variant') {
            $requestedTarget = trim((string)($_POST['target_category'] ?? ''));
            $target = '';
            foreach ($library->categories() as $availableCategory) {
                $availableSlug = (string)($availableCategory['category_slug'] ?? '');
                if ($availableSlug === $requestedTarget || WorldMotherGenerator::safeSlug($availableSlug) === WorldMotherGenerator::safeSlug($requestedTarget)) {
                    $target = $availableSlug;
                    break;
                }
            }
            if ($target === '') {
                throw new RuntimeException('Select a valid scene category.');
            }
            $destDirectory = $library->basePath() . DIRECTORY_SEPARATOR . $target;
            if (!is_dir($destDirectory) && !mkdir($destDirectory, 0775, true) && !is_dir($destDirectory)) {
                throw new RuntimeException('The target scene workspace could not be created.');
            }
            $variantUpload = (array)($_FILES['variant_images'] ?? $_FILES['variant_image'] ?? []);
            if (!$variantUpload) {
                throw new RuntimeException('No image was uploaded.');
            }
            $variantFiles = [];
            if (isset($variantUpload['tmp_name']) && is_array($variantUpload['tmp_name'])) {
                $uploadCount = count($variantUpload['tmp_name']);
                if ($uploadCount < 1 || $uploadCount > 24) {
                    throw new RuntimeException('Upload between 1 and 24 images at a time.');
                }
                for ($uploadIndex = 0; $uploadIndex < $uploadCount; $uploadIndex++) {
                    if ((int)($variantUpload['error'][$uploadIndex] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $variantFiles[] = [
                        'name' => $variantUpload['name'][$uploadIndex] ?? '',
                        'type' => $variantUpload['type'][$uploadIndex] ?? '',
                        'tmp_name' => $variantUpload['tmp_name'][$uploadIndex] ?? '',
                        'error' => $variantUpload['error'][$uploadIndex] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $variantUpload['size'][$uploadIndex] ?? 0,
                    ];
                }
            } else {
                $variantFiles[] = $variantUpload;
            }
            if (!$variantFiles) {
                throw new RuntimeException('No image was uploaded.');
            }
            $uploadedVariantCount = 0;
            foreach ($variantFiles as $variantFile) {
                $tempPath = wms_upload_file($variantFile);
                $finalPath = $destDirectory . DIRECTORY_SEPARATOR . basename($tempPath);
                if (!rename($tempPath, $finalPath)) {
                    @unlink($tempPath);
                    throw new RuntimeException('Failed to move an uploaded image to the scene folder.');
                }
                if (StorageService::isGcsActive()) {
                    $finalStorageKey = 'storage/world_mothers/' . $target . '/' . basename($finalPath);
                    if (!StorageService::uploadFile($finalStorageKey, $finalPath)) {
                        @unlink($finalPath);
                        throw new RuntimeException('A new scene image could not be saved to persistent storage.');
                    }
                    StorageService::delete('storage/world_mother_uploads/' . basename($tempPath));
                }
                $uploadedVariantCount++;
            }
            $library->rebuildIndex();
            $notice = $uploadedVariantCount === 1
                ? 'Image added to the scene.'
                : $uploadedVariantCount . ' images added to the scene.';
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'notice' => $notice]);
                exit;
            }
        } else {
            $index = $library->rebuildIndex();
            $notice = sprintf(
                'Scene folders synchronized: %d scenes indexed.',
                count((array)($index['categories'] ?? []))
            );
        }

        $_SESSION['world_mother_studio_notice'] = $notice;
        $redirectTarget = $redirectSceneSlug !== ''
            ? 'world_mother_studio.php?scene=' . rawurlencode($redirectSceneSlug) . '#scene-detail'
            : 'world_mother_studio.php#scene-library';
        header('Location: ' . $redirectTarget);
        exit;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'analyze') {
        $uploadField = isset($_FILES['reference_images']) ? (array)$_FILES['reference_images'] : (array)($_FILES['reference_image'] ?? []);
        $referencePaths = wms_upload_files($uploadField);
        $referencePath = $referencePaths[0] ?? '';
        $analysis = $generator->analyzeReferences($referencePaths, ['notes' => trim((string)($_POST['notes'] ?? ''))]);
        $jobId = date('Ymd_His') . '_' . random_int(1000, 9999);
        $analysis['reference_path'] = $referencePath;
        $analysis['reference_paths'] = $referencePaths;
        $analysis['created_by_user_id'] = (int)$user['id'];
        $dir = __DIR__ . '/analysis/world-mother-studio';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $analysisPath = $dir . '/' . $jobId . '.analysis.json';
        file_put_contents($analysisPath, json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (StorageService::isGcsActive() && !StorageService::uploadFile('analysis/world-mother-studio/' . $jobId . '.analysis.json', $analysisPath)) {
            throw new RuntimeException('Scene analysis could not be saved to persistent storage.');
        }
        $notice = 'Scene analyzed. Confirm the category before generating.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'generate') {
        $jobId = trim((string)($_POST['job_id'] ?? ''));
        $analysisPath = wms_analysis_path($jobId);
        if (!is_file($analysisPath)) {
            throw new RuntimeException('Previous scene analysis was not found.');
        }
        $analysis = json_decode((string)file_get_contents($analysisPath), true);
        if (!is_array($analysis)) {
            throw new RuntimeException('Previous scene analysis is not valid.');
        }
        $referencePaths = array_values(array_filter(array_map('strval', (array)($analysis['reference_paths'] ?? []))));
        $referencePath = (string)($analysis['reference_path'] ?? ($referencePaths[0] ?? ''));
        if (!$referencePaths && $referencePath !== '') {
            $referencePaths = [$referencePath];
        }
        $referencePaths = array_values(array_filter(array_map('wms_ensure_local_storage_file', $referencePaths), 'is_file'));
        $referencePath = $referencePaths[0] ?? '';
        if (!$referencePaths) {
            throw new RuntimeException('The uploaded scene references are no longer available.');
        }
        $choice = trim((string)($_POST['category_choice'] ?? ''));
        $newCategory = trim((string)($_POST['new_category'] ?? ''));
        $category = $newCategory !== '' ? $newCategory : $choice;
        $category = WorldMotherGenerator::safeSlug($category);
        if ($category === '') {
            throw new RuntimeException('Write a folder name or select an existing scene category.');
        }
        $generated = $generator->generateOriginalWorldMotherSet($referencePaths, $category, $analysis, [
            'notes' => trim((string)($_POST['generation_notes'] ?? '')),
            'count' => 4,
        ]);
        $notice = 'Set of 4 scene references generated and saved.';
    } elseif ($jobId !== '') {
        $analysisPath = wms_analysis_path($jobId);
        if (is_file($analysisPath)) {
            $analysis = json_decode((string)file_get_contents($analysisPath), true);
            $referencePath = is_array($analysis) ? (string)($analysis['reference_path'] ?? '') : '';
            $referencePaths = is_array($analysis) ? array_values(array_filter(array_map('strval', (array)($analysis['reference_paths'] ?? [])))) : [];
            if (!$referencePaths && $referencePath !== '') {
                $referencePaths = [$referencePath];
            }
            $referencePaths = array_values(array_filter(array_map('wms_ensure_local_storage_file', $referencePaths), 'is_file'));
            $referencePath = $referencePaths[0] ?? '';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }
}

$categories = $sceneRanking->sort($sceneRanking->enrich($library->categories()), 'recommended');
$canManageScenes = Auth::isAdmin($user);
$sceneCards = [];
foreach ($categories as $category) {
    $slug = (string)($category['category_slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    $images = $library->imagesForCategory($slug);
    $sceneCards[] = [
        'category' => $category,
        'images' => $images,
        'similarity_groups' => $sceneDiversity->manualGroupsForImages($images),
    ];
}
$totalSceneVariants = array_sum(array_map(
    static fn (array $sceneCard): int => count((array)($sceneCard['images'] ?? [])),
    $sceneCards
));
$selectedSceneCard = null;
foreach ($sceneCards as $sceneCard) {
    $candidateSceneSlug = (string)($sceneCard['category']['category_slug'] ?? '');
    if (
        $candidateSceneSlug === $requestedSceneSlug
        || (
            $requestedSceneSlug !== ''
            && WorldMotherGenerator::safeSlug($candidateSceneSlug) === WorldMotherGenerator::safeSlug($requestedSceneSlug)
        )
    ) {
        $selectedSceneCard = $sceneCard;
        $requestedSceneSlug = $candidateSceneSlug;
        break;
    }
}
$creatorOpen = is_array($analysis)
    || is_array($generated)
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(trim((string)($_POST['action'] ?? '')), ['analyze', 'generate'], true));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Scene Studio - Artwork Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
    <style>
        .studio-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(360px, .8fr); gap: 24px; align-items: start; }
        .panel-box { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); padding: 22px; }
        .field { display: grid; gap: 7px; margin-bottom: 16px; }
        .field label, .small-label { font-size: 10px; text-transform: uppercase; color: var(--muted); font-weight: 700; letter-spacing: .05em; }
        textarea, input[type="text"], input[type="file"] { width: 100%; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface-soft); color: var(--ink); padding: 10px; }
        .ref-img { width: 100%; max-height: 380px; object-fit: contain; border: 1px solid var(--line); background: var(--surface-soft); }
        .candidate { border: 1px solid var(--line); border-radius: var(--radius); padding: 10px; margin: 8px 0; background: var(--surface-soft); }
        .candidate code { font-size: 12px; }
        .analysis-list { font-size: 13px; line-height: 1.55; color: var(--ink); }
        .analysis-list strong { color: var(--muted); font-size: 10px; text-transform: uppercase; display: block; margin-top: 10px; }
        .scene-library { margin-top: 24px; }
        .scene-library-head { display:flex; justify-content:space-between; gap:16px; align-items:end; margin-bottom:14px; }
        .scene-library-head p { margin:4px 0 0; color:var(--muted); font-size:13px; }
        .scene-library-heading {
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:22px;
            padding-bottom:18px;
            margin-bottom:18px;
            border-bottom:1px solid var(--line);
        }
        .scene-library-kicker {
            display:block;
            margin-bottom:5px;
            color:var(--accent);
            font-size:10px;
            font-weight:700;
            letter-spacing:.09em;
            text-transform:uppercase;
        }
        .scene-library-heading h2 { margin:0; font-size:28px; }
        .scene-library-heading p { margin:5px 0 0; color:var(--muted); font-size:13px; }
        .scene-library-controls { display:flex; justify-content:flex-end; align-items:flex-end; gap:10px; flex-wrap:wrap; flex:1 1 680px; }
        .scene-library-control { display:grid; gap:6px; width:180px; }
        .scene-library-control.scene-library-search { width:min(280px, 100%); }
        .scene-library-control span { color:var(--muted); font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .scene-library-control input,
        .scene-library-control select { width:100%; height:42px; padding:9px 12px; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); color:var(--ink); }
        .scene-library-result { min-width:78px; height:42px; display:flex; align-items:center; justify-content:flex-end; color:var(--muted); font-size:11px; white-space:nowrap; }
        .scene-card-grid { display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:24px; align-items:start; }
        .scene-card {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface);
            box-shadow: var(--shadow);
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-width: 0;
            overflow: hidden;
            transition: all 0.25s ease;
            position: relative;
            color:inherit;
            text-decoration:none;
        }
        .scene-card:focus-visible { outline:2px solid var(--accent); outline-offset:4px; }
        .scene-card h3 { margin:0; font-family:var(--font-serif), Georgia, serif; font-size:18px; font-weight: 600; line-height:1.25; color: var(--ink); }
        .scene-card code { color:var(--muted); font-size:11px; word-break:break-word; }
        .scene-card-title-link { color:inherit; text-decoration:none; }
        .scene-card-title-link:focus-visible,
        .scene-card-thumbs-link:focus-visible,
        .scene-card-open:focus-visible { outline:2px solid var(--accent); outline-offset:3px; }
        .scene-card-open {
            align-self:flex-start;
            display:inline-flex;
            padding:6px 9px;
            border:1px solid var(--line);
            border-radius:var(--radius);
            background:var(--surface-soft);
            color:var(--accent);
            font-size:10px;
            font-weight:700;
            letter-spacing:.07em;
            text-decoration:none;
            text-transform:uppercase;
        }
        .scene-card-thumbs-link { display:block; color:inherit; text-decoration:none; }
        .scene-card-thumbs { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:5px; min-height:72px; margin:8px 0; }
        .scene-card-thumb-item { aspect-ratio:4 / 3; overflow:hidden; border:1px solid var(--line); border-radius:4px; background:var(--surface-soft); }
        .scene-card-thumb-item img { width:100%; height:100%; object-fit:cover; display:block; transition:transform 0.2s ease; }
        .scene-card-empty { display:grid; place-items:center; min-height:120px; border:1px dashed var(--line); border-radius:4px; color:var(--muted); font-size:12px; }
        .scene-card-meta { display:flex; flex-wrap:wrap; gap:6px; margin-top:2px; }
        .scene-pill {
            font-size: 11px;
            font-weight: 500;
            border-radius: 4px;
            padding: 3px 8px;
            border: 1px solid transparent;
        }
        .scene-pill.variants-count {
            background: #f5e6e8;
            color: #a05a63;
            border-color: #ebd0d4;
        }
        .scene-pill.modified-date {
            background: #eaf2e8;
            color: #5a8a58;
            border-color: #d6e8d4;
        }
        .scene-pill.ranking-score {
            background:#eef0f7;
            color:#5f647d;
            border-color:#dfe2ee;
        }
        .scene-pill.featured-score {
            background:#f3ead7;
            color:#80643c;
            border-color:#e7d8b8;
        }
        .scene-admin-bar {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            padding: 16px;
            margin-bottom: 16px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface);
            box-shadow: var(--shadow);
            flex-wrap: wrap;
        }
        .scene-admin-bar > div:first-child {
            flex: 1 1 500px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
        }
        .scene-admin-bar > div:first-child form {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            width: 100%;
        }
        .scene-admin-bar > div:first-child .field {
            margin: 0;
            flex: 1 1 auto;
            min-width: 0;
        }
        .scene-admin-bar input[type="text"] {
            width: 100%;
            height: 44px;
            box-sizing: border-box;
        }
        .scene-admin-bar button {
            width: 160px;
            height: 44px;
            margin: 0 !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            line-height: 1;
            box-sizing: border-box;
            padding: 0 16px;
        }
        .scene-card-admin button { width: auto; margin: 0; }
        .scene-admin-bar form:last-child {
            margin: 0;
        }
        .scene-admin-note { margin:5px 0 0; color:var(--muted); font-size:12px; line-height:1.5; }
        .scene-card-admin { border-top:1px dashed var(--line); padding-top:10px; }
        .scene-card-admin summary { cursor:pointer; width:max-content; padding:6px 9px; border:1px solid var(--line); border-radius:var(--radius); color:var(--accent); background:var(--surface-soft); font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; list-style:none; }
        .scene-card-admin summary::-webkit-details-marker { display:none; }
        .scene-card-admin-body { display:grid; gap:12px; padding-top:12px; }
        .scene-card-admin form { display:grid; gap:7px; }
        .scene-card-admin label { color:var(--muted); font-size:10px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
        .scene-card-admin input, .scene-card-admin select, .scene-admin-bar select { width:100%; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface-soft); color:var(--ink); padding:10px; }
        .scene-card-admin .button-row { display:flex; gap:8px; align-items:center; }
        .scene-ranking-form { padding-bottom:12px; border-bottom:1px dashed var(--line); }
        .scene-diversity-admin { border-bottom:1px dashed var(--line); padding-bottom:12px; }
        .scene-diversity-admin summary { cursor:pointer; color:var(--ink); font-size:11px; text-transform:uppercase; letter-spacing:.04em; padding:4px 0; }
        .scene-diversity-list { display:grid; gap:8px; margin-top:10px; }
        .scene-diversity-row { display:grid; grid-template-columns:54px minmax(0,1fr); gap:9px; align-items:center; }
        .scene-diversity-row img { width:54px; height:46px; object-fit:cover; border:1px solid var(--line); border-radius:4px; background:var(--surface-soft); }
        .scene-diversity-row input[type="text"] { padding:8px 9px; font-size:12px; }
        .scene-diversity-note { margin:8px 0 0; color:var(--muted); font-size:11px; line-height:1.5; }
        .scene-score-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px; }
        .scene-score-grid label { display:grid; gap:5px; }
        .scene-score-grid .featured-until-field { grid-column:1 / -1; }
        .scene-ranking-note { margin:0; color:var(--muted); font-size:11px; line-height:1.5; }
        .scene-card-admin .danger-button { border-color:var(--danger); background:transparent; color:var(--danger); box-shadow:none; }
        .scene-card-admin .danger-button:hover { background:#fff5f5; border-color:var(--danger); color:var(--danger); }
        .scene-empty-library { grid-column:1 / -1; padding:32px; border:1px dashed var(--line); border-radius:var(--radius); color:var(--muted); text-align:center; }
        .scene-empty-library[hidden] { display:none; }
        .scene-detail { margin:24px 0 40px; padding:28px; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); }
        .scene-detail-header { display:flex; justify-content:space-between; align-items:flex-start; gap:24px; padding-bottom:22px; border-bottom:1px solid var(--line); }
        .scene-detail-back { display:inline-flex; margin-bottom:12px; color:var(--accent); font-size:11px; font-weight:700; letter-spacing:.06em; text-decoration:none; text-transform:uppercase; }
        .scene-detail-header h2 { margin:0; font-size:36px; }
        .scene-detail-header p { margin:7px 0 0; color:var(--muted); font-size:13px; }
        .scene-detail-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:18px; margin-top:24px; }
        .scene-detail-image { position:relative; min-width:0; margin:0; border:1px solid var(--line); border-radius:var(--radius); overflow:hidden; background:var(--surface-soft); }
        .scene-detail-image img { display:block; width:100%; aspect-ratio:4 / 3; object-fit:cover; }
        .scene-detail-image figcaption { padding:10px 12px; color:var(--muted); font-size:11px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .scene-detail-edit { display:block; color:inherit; text-decoration:none; }
        .scene-detail-edit:focus-visible { outline:2px solid var(--accent); outline-offset:-2px; }
        .scene-detail-edit-label { position:absolute; left:10px; bottom:42px; padding:7px 9px; border:1px solid rgba(255,255,255,.72); border-radius:999px; background:rgba(250,248,244,.84); color:var(--accent); font-size:9px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; backdrop-filter:blur(8px); }
        .scene-detail-delete { position:absolute; top:10px; right:10px; }
        .scene-detail-delete button { display:grid; place-items:center; width:36px; height:36px; padding:0; border:1px solid rgba(255,255,255,.72); border-radius:50%; background:rgba(250,248,244,.82); color:var(--danger); box-shadow:0 2px 10px rgba(30,25,20,.12); backdrop-filter:blur(8px); }
        .scene-detail-delete svg { width:17px; height:17px; }
        .scene-source-uploader { margin-top:22px; }
        .scene-source-uploader input[type="file"] {
            position:absolute;
            width:1px;
            height:1px;
            opacity:0;
            pointer-events:none;
        }
        .scene-source-dropzone {
            min-height:150px;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:24px;
            border:1px dashed var(--line);
            border-radius:var(--radius);
            box-sizing:border-box;
            background:var(--surface-soft);
            color:var(--muted);
            cursor:pointer;
            transition:border-color .18s ease, background .18s ease, color .18s ease;
        }
        .scene-source-dropzone:hover,
        .scene-source-dropzone.is-dragging {
            border-color:var(--accent);
            background:var(--accent-light);
            color:var(--accent);
        }
        .scene-source-dropzone.is-uploading { cursor:wait; opacity:.7; }
        .scene-source-dropzone-inner { display:grid; justify-items:center; gap:10px; text-align:center; }
        .scene-source-dropzone svg {
            width:30px;
            height:30px;
            fill:none;
            stroke:currentColor;
            stroke-width:1.4;
            stroke-linecap:round;
            stroke-linejoin:round;
        }
        .scene-source-dropzone span { font-size:13px; line-height:1.45; }
        .scene-source-dropzone small { font-size:10px; letter-spacing:.04em; text-transform:uppercase; }
        .scene-detail-panel { padding:22px; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface-soft); }
        .scene-detail-panel h3 { margin:0 0 7px; font-size:24px; }
        .scene-detail-panel > p { margin:0 0 18px; color:var(--muted); font-size:13px; line-height:1.55; }
        .scene-detail-panel form { display:grid; gap:14px; }
        .scene-detail-panel label { display:grid; gap:7px; color:var(--muted); font-size:10px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
        .scene-detail-panel textarea { min-height:150px; resize:vertical; border:0; border-radius:var(--radius); background:var(--surface); color:var(--ink); padding:16px; font:inherit; line-height:1.55; }
        .scene-detail-panel input, .scene-detail-panel select { width:100%; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); color:var(--ink); padding:11px; }
        .scene-detail-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .scene-detail-actions button { width:auto; margin:0; }
        .scene-detail-settings { margin-top:22px; padding-top:18px; border-top:1px dashed var(--line); }
        .scene-detail-settings summary { cursor:pointer; color:var(--accent); font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .scene-detail-settings-body { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:20px; padding-top:18px; }
        .scene-detail-danger { grid-column:1 / -1; padding-top:18px; border-top:1px solid var(--line); }
        .scene-detail-danger button { width:auto; border-color:var(--danger); background:transparent; color:var(--danger); box-shadow:none; }
        @media (min-width: 2400px) { .scene-card-grid { grid-template-columns:repeat(6, minmax(0, 1fr)); } }
        @media (max-width: 1740px) { .scene-card-grid { grid-template-columns:repeat(4, minmax(0, 1fr)); } }
        @media (max-width: 1350px) { .scene-card-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 1050px) { .scene-card-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } .scene-detail-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 980px) { .studio-grid { grid-template-columns: 1fr; } }
        @media (max-width: 720px) { .scene-card-grid, .scene-detail-grid, .scene-detail-settings-body { grid-template-columns:1fr; } .scene-detail { padding:20px; } .scene-detail-header { display:block; } .scene-library-head, .scene-library-heading { display:block; } .scene-library-controls { justify-content:stretch; margin-top:14px; } .scene-library-control, .scene-library-control.scene-library-search { width:100%; } .scene-library-result { justify-content:flex-start; height:auto; } .scene-admin-bar, .scene-admin-bar form, .scene-score-grid { grid-template-columns:1fr; } .scene-score-grid .featured-until-field { grid-column:auto; } }

        /* Modern layout and drag and drop enhancements */
        /* Scene Studio follows the generous editorial hierarchy used by Mockup Lab. */
        .workspace {
            padding: 24px 24px 60px;
        }
        .alert-strip {
            padding: 10px 24px;
            font-size: 11px;
            line-height: 1.4;
        }
        .scene-header-v3 {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 36px;
            padding: 6px 0 24px;
            margin-bottom: 0;
            border-bottom: 1px solid var(--line);
        }
        .scene-header-main {
            flex: 1 1 auto;
            min-width: 0;
        }
        .scene-header-v3 h1 {
            margin: 0 0 18px;
            font-family: var(--font-serif);
            font-size: 44px;
            font-weight: 500;
            line-height: 1;
            letter-spacing: -.01em;
        }
        .scene-page-desc {
            margin: 0;
            line-height: 1.55;
        }
        .scene-page-desc .desc-kicker {
            display: block;
            margin-bottom: 8px;
            color: var(--muted);
            font-size: 14px;
        }
        .scene-page-desc .desc-instructions {
            display: block;
            max-width: 940px;
            color: var(--accent);
            font-size: 16px;
            font-weight: 600;
        }
        .scene-header-actions {
            position: relative;
            flex: 0 0 150px;
            display: grid;
            grid-template-columns: 150px;
            grid-template-rows: auto auto;
            gap: 9px;
            align-items: start;
            margin-right: 48px;
        }
        .scene-primary-action {
            display: inline-flex;
            grid-column: 1;
            grid-row: 1;
            align-items: center;
            justify-content: center;
            width: 150px;
            min-width: 150px;
            height: 150px;
            min-height: 150px;
            margin: 0;
            padding: 20px;
            border-radius: 4px;
            border-color: #b77f86;
            background: #b77f86;
            color: #fffaf7;
            font-size: 13px;
            line-height: 1.32;
            text-align: center;
            white-space: normal;
        }
        .scene-primary-action:hover {
            border-color: #a86f77;
            background: #a86f77;
        }
        .scene-primary-action:disabled {
            border-color:#d8c9ca;
            background:#d8c9ca;
            color:#fffaf7;
            cursor:not-allowed;
            box-shadow:none;
            transform:none;
        }
        .scene-artworks-link {
            grid-column: 1;
            grid-row: 2;
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .07em;
            text-align: center;
            text-decoration: none;
            text-transform: uppercase;
        }
        .scene-artworks-link:hover { color: var(--accent); }
        .panel-submit-action {
            display: inline-flex;
            width: auto;
            margin-top: 2px;
        }
        .scene-creator-panel {
            display: none;
            margin-top: 24px;
        }
        .scene-creator-panel.is-open { display: block; }
        .scene-creator-drawer {
            position: relative;
            width: 100%;
            padding: 24px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface);
            box-shadow: var(--shadow);
        }
        .scene-creator-drawer-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 24px;
            padding-bottom: 18px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--line);
        }
        .scene-creator-drawer-head h2 {
            margin: 0 0 5px;
            font-family: var(--font-serif);
            font-size: 30px;
            font-weight: 500;
        }
        .scene-creator-drawer-head p { margin: 0; color: var(--muted); font-size: 13px; }
        .scene-creator-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            min-width: 36px;
            height: 36px;
            min-height: 36px;
            margin: 0;
            padding: 0;
            border: 1px solid var(--line);
            border-radius: 50%;
            background: var(--surface);
            color: var(--ink);
            font-size: 22px;
            font-weight: 400;
            line-height: 1;
        }
        .scene-creator-drawer .panel-box { box-shadow: none; }
        .analysis-empty-state {
            padding: 14px 0 2px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }
        .analysis-empty-state p { margin: 0 0 8px; }
        .analysis-empty-state ul { margin: 0; padding-left: 18px; }
        @media (max-width: 900px) {
            .scene-header-v3 h1 { font-size: 38px; }
            .scene-page-desc .desc-instructions { font-size: 15px; }
            .scene-header-actions { flex-basis:128px; grid-template-columns:128px; margin-right:32px; }
            .scene-primary-action { width:128px; min-width:128px; height:128px; min-height:128px; }
        }
        @media (max-width: 720px) {
            .workspace { padding: 20px 16px 46px; }
            .scene-header-v3 { display:block; padding-bottom:20px; }
            .scene-header-v3 h1 { margin-bottom:12px; font-size:34px; }
            .scene-page-desc .desc-kicker { font-size:13px; }
            .scene-page-desc .desc-instructions { font-size:14px; }
            .scene-header-actions { grid-template-columns:minmax(0, 1fr); width:100%; margin:18px 0 0; }
            .scene-primary-action { grid-column:1; }
            .scene-primary-action { width:100%; min-width:0; height:52px; min-height:52px; padding:10px 16px; }
            .scene-artworks-link { grid-column:1; align-self:center; padding:0 8px; }
            .scene-creator-panel { margin-top:18px; }
            .scene-creator-drawer { padding:18px; }
            .analysis-thumbs-grid { grid-template-columns:repeat(auto-fill, 82px); }
            .analysis-thumb-item { width:82px; height:82px; }
        }

        .dropzone-container {
            border: 2px dashed var(--line);
            border-radius: var(--radius);
            background: var(--surface-soft);
            padding: 32px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
        }
        .dropzone-container:hover, .dropzone-container.dragover {
            border-color: var(--accent);
            background: var(--accent-light);
            color: var(--accent);
        }
        .dropzone-icon {
            font-size: 28px;
            color: var(--muted);
            transition: color 0.25s ease;
        }
        .dropzone-container:hover .dropzone-icon, .dropzone-container.dragover .dropzone-icon {
            color: var(--accent);
        }
        .dropzone-previews {
            display: grid;
            grid-template-columns: repeat(auto-fill, 96px);
            gap: 12px;
            margin-top: 16px;
            width: 100%;
        }
        .dropzone-preview-item {
            position: relative;
            aspect-ratio: 1 / 1;
            border: 1px solid var(--line);
            border-radius: 4px;
            overflow: hidden;
            background: var(--surface);
        }
        .dropzone-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .dropzone-preview-item .remove-btn {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: rgba(20, 20, 18, 0.6);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            cursor: pointer;
            border: none;
            line-height: 1;
            transition: background 0.2s ease;
        }
        .dropzone-preview-item .remove-btn:hover {
            background: var(--danger);
        }

        .scene-card.drag-over {
            border-color: var(--accent);
            background: var(--accent-light);
            box-shadow: 0 0 10px rgba(154, 123, 86, 0.2);
        }
        .scene-card:hover .scene-card-thumb-item img { opacity:.92; }
        .scene-card-thumb-item.more-badge {
            background: var(--surface-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            color: var(--muted);
            border-style: dashed;
        }
        .scene-drag-handle {
            cursor: grab;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            font-size: 16px;
            padding: 2px 6px;
            margin-right: 4px;
            border-radius: 3px;
            user-select: none;
            transition: background 0.2s, color 0.2s;
        }
        .scene-drag-handle:hover {
            background: var(--surface-soft);
            color: var(--ink);
        }
        .scene-drag-handle:active {
            cursor: grabbing;
        }

        .scene-card-upload-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.85);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 10;
            border-radius: var(--radius);
            gap: 8px;
        }
        .spinner-sutil {
            width: 20px;
            height: 20px;
            border: 2px solid var(--line);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin-sutil 0.8s linear infinite;
        }
        @keyframes spin-sutil {
            to { transform: rotate(360deg); }
        }
        .upload-progress-text {
            font-size: 11px;
            font-weight: 700;
            color: var(--accent);
        }

        .analysis-thumbs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, 92px);
            gap: 10px;
            margin-bottom: 16px;
        }
        .analysis-thumb-item {
            width: 92px;
            height: 92px;
            border: 1px solid var(--line);
            border-radius: 4px;
            overflow: hidden;
            background: var(--surface-soft);
            cursor: pointer;
        }
        .analysis-thumb-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <div class="alert-strip">Scene Studio: create and manage reusable visual worlds for artwork mockups.</div>
        <div class="workspace website-catalog">
            <div class="scene-header-v3" id="scene-studio-header">
                <div class="scene-header-main">
                    <h1>Scene Studio</h1>
                    <p class="scene-page-desc">
                        <span class="desc-kicker">Create and manage reusable environment families for future mockup combinations.</span>
                        <span class="desc-instructions">Upload 1–4 references. All of them define the scene family; each mockup uses one automatically rotated variant.</span>
                    </p>
                </div>
                <div class="scene-header-actions">
                    <button class="scene-primary-action" id="scene-new-action" type="button" aria-controls="scene-creator-panel" aria-expanded="<?= $creatorOpen ? 'true' : 'false' ?>">Create Scene</button>
                </div>
            </div>

            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>

            <div
                class="scene-creator-panel<?= $creatorOpen ? ' is-open' : '' ?>"
                id="scene-creator-panel"
                aria-hidden="<?= $creatorOpen ? 'false' : 'true' ?>"
            >
                <section class="scene-creator-drawer" role="region" aria-labelledby="scene-creator-title">
                    <header class="scene-creator-drawer-head">
                        <div>
                            <h2 id="scene-creator-title">Create Scene</h2>
                            <p>Upload 1–4 references, analyze their visual language and generate the reusable scene family.</p>
                        </div>
                        <button class="scene-creator-close" type="button" data-close-scene-creator aria-label="Close scene creator">&times;</button>
                    </header>
                <div class="studio-grid">
                    <section class="panel-box">
                        <h2>Scene References</h2>
                        <form method="post" enctype="multipart/form-data" id="main-upload-form">
                            <input type="hidden" name="action" value="analyze">
                            <div class="field">
                                <label>Scene Images (1-4)</label>
                                <div class="dropzone-container" id="upload-dropzone">
                                    <span class="dropzone-icon">📷</span>
                                    <span style="font-weight:600; font-size:13px; color:var(--ink);">Drop images here or click to choose files</span>
                                    <span style="font-size:11px; color:var(--muted);">JPG, PNG or WEBP · Up to 4 references</span>
                                    <input type="file" id="dropzone-file-input" name="reference_images[]" accept="image/jpeg,image/png,image/webp" multiple required style="display:none;">
                                </div>
                                <div class="dropzone-previews" id="dropzone-previews-container"></div>
                            </div>
                            <div class="field">
                                <label>Scene Guidelines</label>
                                <textarea name="notes" rows="4" placeholder="Example: blue-hour coastal room, low bed, soft paper screens, calm luxury, no visible artwork, no people..."></textarea>
                            </div>
                            <button class="button-link secondary panel-submit-action" id="scene-laboratory-action" type="submit" disabled>Analyze Scene</button>
                        </form>

                        <?php if (is_array($analysis)): ?>
                            <hr style="border:0; border-top:1px dashed var(--line); margin:24px 0;">
                            <h2>Confirm Scene</h2>
                            <form method="post" id="scene-generation-form">
                                <input type="hidden" name="action" value="generate">
                                <input type="hidden" name="job_id" value="<?= h($jobId) ?>">
                                <div class="field">
                                    <label>Final Scene Folder Name</label>
                                    <input type="text" name="new_category" value="<?= h($analysis['new_category_suggestion'] ?? '') ?>" placeholder="Example: blue_hour_atelier">
                                    <span style="display:block; color:var(--muted); font-size:12px; margin-top:6px;">This name is editable and takes priority over the suggested category list below.</span>
                                </div>
                                <?php foreach ((array)($analysis['category_candidates'] ?? []) as $idx => $candidate): ?>
                                    <label class="candidate">
                                        <input type="radio" name="category_choice" value="<?= h($candidate['category_slug'] ?? '') ?>" <?= $idx === 0 ? 'checked' : '' ?>>
                                        <code><?= h($candidate['category_slug'] ?? '') ?></code>
                                        <span style="color:var(--muted);">score <?= (int)($candidate['score'] ?? 0) ?> · images <?= (int)($candidate['image_count'] ?? 0) ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <label class="candidate">
                                    <input type="radio" name="category_choice" value="__new__">
                                    Use the custom folder name above
                                </label>
                                <div class="field">
                                    <label>Generation Notes</label>
                                    <textarea name="generation_notes" rows="3" placeholder="Optional refinements before generation"></textarea>
                                </div>
                            <button class="button-link secondary panel-submit-action" type="submit">Generate Scene References</button>
                            </form>
                        <?php endif; ?>
                    </section>

                    <aside class="panel-box">
                        <h2>Scene Analysis</h2>
                        <div class="analysis-thumbs-grid">
                            <?php foreach (($referencePaths ?: ($referencePath !== '' ? [$referencePath] : [])) as $refPath): ?>
                                <?php if (is_file($refPath)): ?>
                                    <?php $webPath = h(wms_media_url($refPath)); ?>
                                    <div class="analysis-thumb-item">
                                        <img src="<?= $webPath ?>" alt="Reference preview">
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php if (is_array($analysis)): ?>
                            <div class="analysis-list">
                                <strong>Scene Type</strong><?= h($analysis['scene_type'] ?? '') ?>
                                <strong>Architecture</strong><?= h($analysis['architecture_language'] ?? '') ?>
                                <strong>Walls</strong><?= h($analysis['wall_language'] ?? '') ?>
                                <strong>Lighting</strong><?= h($analysis['lighting'] ?? '') ?>
                                <strong>Materials</strong><?= h(implode(', ', (array)($analysis['materials'] ?? []))) ?>
                                <strong>Palette</strong><?= h(implode(', ', (array)($analysis['palette'] ?? []))) ?>
                                <strong>Camera Potential</strong><?= h(implode(', ', (array)($analysis['camera_potential'] ?? []))) ?>
                                <strong>Risks To Remove</strong><?= h(implode(', ', (array)($analysis['negative_risks'] ?? []))) ?>
                            </div>
                        <?php else: ?>
                            <div class="analysis-empty-state">
                                <p>Upload references to extract the visual language of the scene:</p>
                                <ul>
                                    <li>architecture and spatial language</li>
                                    <li>materials, palette and lighting</li>
                                    <li>camera potential and elements to remove</li>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (is_array($generated)): ?>
                            <hr style="border:0; border-top:1px dashed var(--line); margin:24px 0;">
                            <h2>Generated</h2>
                            <div class="analysis-thumbs-grid">
                                <?php foreach ((array)($generated['images'] ?? []) as $image): ?>
                                    <?php $genUrl = h(wms_media_url((string)($image['relative_path'] ?? ''))); ?>
                                    <div class="analysis-thumb-item">
                                        <img src="<?= $genUrl ?>" alt="Generated variant">
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($generated['images']) && !empty($generated['relative_path'])): ?>
                                    <?php $genUrl = h(wms_media_url((string)($generated['relative_path'] ?? ''))); ?>
                                    <div class="analysis-thumb-item">
                                        <img src="<?= $genUrl ?>" alt="Generated variant">
                                    </div>
                                <?php endif; ?>
                            </div>
                            <p style="font-size:11px; margin-top: 6px;"><code><?= h($generated['audit_file'] ?? '') ?></code></p>
                        <?php endif; ?>
                    </aside>
                </div>
                </section>
            </div>

            <?php if (is_array($selectedSceneCard)): ?>
                <?php
                $detailCategory = (array)($selectedSceneCard['category'] ?? []);
                $detailImages = (array)($selectedSceneCard['images'] ?? []);
                $detailSimilarityGroups = (array)($selectedSceneCard['similarity_groups'] ?? []);
                $detailSlug = (string)($detailCategory['category_slug'] ?? '');
                $detailName = (string)($detailCategory['category_name'] ?? $detailSlug);
                ?>
                <section class="scene-detail" id="scene-detail" aria-labelledby="scene-detail-title">
                    <header class="scene-detail-header">
                        <div>
                            <a class="scene-detail-back" href="world_mother_studio.php#scene-library">← Scene Library</a>
                            <span class="scene-library-kicker">Scene workspace</span>
                            <h2 id="scene-detail-title"><?= h($detailName) ?></h2>
                            <p><?= count($detailImages) ?> visual sources · <code><?= h($detailSlug) ?></code></p>
                        </div>
                    </header>

                    <?php if ($detailImages): ?>
                        <div class="scene-detail-grid">
                            <?php foreach ($detailImages as $detailImage): ?>
                                <?php
                                $detailRelativePath = (string)($detailImage['relative_path'] ?? '');
                                $detailFileName = (string)($detailImage['file_name'] ?? '');
                                ?>
                                <figure class="scene-detail-image">
                                    <a class="scene-detail-edit" href="world_mother_variation_lab.php?scene=<?= rawurlencode($detailSlug) ?>&source=<?= rawurlencode($detailFileName) ?>">
                                        <img src="<?= h(wms_media_url($detailRelativePath, 960)) ?>" alt="<?= h((string)($detailImage['title'] ?? 'Scene source')) ?>">
                                        <span class="scene-detail-edit-label">Edit Source</span>
                                        <figcaption><?= h((string)($detailImage['title'] ?? $detailFileName)) ?></figcaption>
                                    </a>
                                    <?php if ($canManageScenes): ?>
                                        <form class="scene-detail-delete" method="post" onsubmit="return confirm('Remove this visual source from the scene?');">
                                            <input type="hidden" name="csrf" value="<?= h($sceneAdminCsrf) ?>">
                                            <input type="hidden" name="action" value="delete_variant">
                                            <input type="hidden" name="source_category" value="<?= h($detailSlug) ?>">
                                            <input type="hidden" name="return_scene" value="<?= h($detailSlug) ?>">
                                            <input type="hidden" name="file_name" value="<?= h($detailFileName) ?>">
                                            <button type="submit" aria-label="Remove scene source" title="Remove scene source">
                                                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9 3h6l1 2h4v2H4V5h4l1-2Zm-2 6h10l-1 11H8L7 9Zm3 2v7h2v-7h-2Zm4 0v7h2v-7h-2Z"/></svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </figure>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="scene-empty-library">This scene has no visual sources yet. Add or transform an image below.</div>
                    <?php endif; ?>

                    <?php if ($canManageScenes): ?>
                        <form class="scene-source-uploader" method="post" enctype="multipart/form-data" data-scene-source-uploader>
                            <input type="hidden" name="csrf" value="<?= h($sceneAdminCsrf) ?>">
                            <input type="hidden" name="action" value="upload_variant">
                            <input type="hidden" name="target_category" value="<?= h($detailSlug) ?>">
                            <input type="hidden" name="return_scene" value="<?= h($detailSlug) ?>">
                            <label class="scene-source-dropzone" data-scene-source-dropzone>
                                <input type="file" name="variant_images[]" accept="image/jpeg,image/png,image/webp" multiple required data-scene-source-input>
                                <span class="scene-source-dropzone-inner">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"></path>
                                        <path d="M4 15.5V20h16v-4.5"></path>
                                    </svg>
                                    <span data-scene-source-label>Drop images here or choose files</span>
                                    <small>JPG · PNG · WEBP</small>
                                </span>
                            </label>
                        </form>

                        <details class="scene-detail-settings">
                            <summary>Scene settings</summary>
                            <div class="scene-detail-settings-body">
                                <form class="scene-detail-panel" method="post">
                                    <input type="hidden" name="csrf" value="<?= h($sceneAdminCsrf) ?>">
                                    <input type="hidden" name="action" value="rename_category">
                                    <input type="hidden" name="source_category" value="<?= h($detailSlug) ?>">
                                    <input type="hidden" name="return_scene" value="<?= h($detailSlug) ?>">
                                    <h3>Rename scene</h3>
                                    <label>
                                        Scene name
                                        <input type="text" name="new_category_name" value="<?= h($detailName) ?>" maxlength="80" required>
                                    </label>
                                    <div class="scene-detail-actions"><button class="button-link secondary" type="submit">Rename</button></div>
                                </form>

                                <form class="scene-detail-panel" method="post">
                                    <input type="hidden" name="csrf" value="<?= h($sceneAdminCsrf) ?>">
                                    <input type="hidden" name="action" value="update_ranking">
                                    <input type="hidden" name="source_category" value="<?= h($detailSlug) ?>">
                                    <input type="hidden" name="return_scene" value="<?= h($detailSlug) ?>">
                                    <h3>Editorial priority</h3>
                                    <label>Featured score <input type="number" name="featured_score" min="0" max="100" value="<?= (int)($detailCategory['featured_score'] ?? 0) ?>"></label>
                                    <label>Editorial score <input type="number" name="editorial_score" min="0" max="100" value="<?= (int)($detailCategory['editorial_score'] ?? 50) ?>"></label>
                                    <label>Featured until <input type="date" name="featured_until" value="<?= h((string)($detailCategory['featured_until'] ?? '')) ?>"></label>
                                    <div class="scene-detail-actions"><button class="button-link secondary" type="submit">Save Priority</button></div>
                                </form>

                                <?php if ($detailImages): ?>
                                    <details class="scene-detail-panel scene-diversity-admin">
                                        <summary>Reference Diversity</summary>
                                        <form method="post">
                                            <input type="hidden" name="csrf" value="<?= h($sceneAdminCsrf) ?>">
                                            <input type="hidden" name="action" value="update_similarity_groups">
                                            <input type="hidden" name="source_category" value="<?= h($detailSlug) ?>">
                                            <input type="hidden" name="return_scene" value="<?= h($detailSlug) ?>">
                                            <p class="scene-diversity-note">Use the same group name for visually equivalent references, or leave the field empty for automatic analysis.</p>
                                            <div class="scene-diversity-list">
                                                <?php foreach ($detailImages as $detailImage): ?>
                                                    <?php
                                                    $detailReferenceKey = (string)($detailImage['world_mother_id'] ?? '');
                                                    $detailRelativePath = (string)($detailImage['relative_path'] ?? '');
                                                    ?>
                                                    <label class="scene-diversity-row">
                                                        <img src="<?= h(wms_media_url($detailRelativePath, 320)) ?>" alt="">
                                                        <span>
                                                            <input type="hidden" name="reference_key[]" value="<?= h($detailReferenceKey) ?>">
                                                            <input type="text" name="similarity_group[]" value="<?= h((string)($detailSimilarityGroups[$detailReferenceKey] ?? '')) ?>" maxlength="80" placeholder="Automatic">
                                                        </span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="scene-detail-actions"><button class="button-link secondary" type="submit">Save Groups</button></div>
                                        </form>
                                    </details>
                                <?php endif; ?>

                                <form class="scene-detail-danger" method="post" onsubmit="return confirm('Delete this scene and every visual source it contains? This cannot be undone.');">
                                    <input type="hidden" name="csrf" value="<?= h($sceneAdminCsrf) ?>">
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="source_category" value="<?= h($detailSlug) ?>">
                                    <input type="hidden" name="confirm_delete" value="yes">
                                    <button type="submit">Delete Scene</button>
                                </form>
                            </div>
                        </details>
                    <?php endif; ?>
                </section>
            <?php elseif ($requestedSceneSlug !== ''): ?>
                <div class="notice error">The selected scene was not found.</div>
            <?php endif; ?>

            <section class="scene-library" id="scene-library">
                    <div class="scene-library-heading">
                        <div>
                            <span class="scene-library-kicker">Curated visual worlds</span>
                            <h2>Scene Library</h2>
                            <p><?= count($sceneCards) ?> scenes · <?= $totalSceneVariants ?> variants</p>
                        </div>
                        <div class="scene-library-controls" aria-label="Scene library controls">
                            <label class="scene-library-control scene-library-search">
                                <span>Search scenes</span>
                                <input id="scene-library-search" type="search" placeholder="Search by name" autocomplete="off">
                            </label>
                            <label class="scene-library-control">
                                <span>Sort by</span>
                                <select id="scene-library-sort">
                                    <option value="recommended">Recommended</option>
                                    <option value="featured">Featured</option>
                                    <option value="editorial">Editorial</option>
                                    <option value="popular">Popular</option>
                                    <option value="versatile">Most versatile</option>
                                    <option value="usage">Usage</option>
                                    <option value="newest">Newest</option>
                                    <option value="alpha">A–Z</option>
                                </select>
                            </label>
                            <label class="scene-library-control">
                                <span>Show</span>
                                <select id="scene-library-filter">
                                    <option value="all">All scenes</option>
                                    <option value="featured">Active Featured</option>
                                    <option value="low-usage">Low usage (1–2)</option>
                                    <option value="no-data">Needs data</option>
                                </select>
                            </label>
                            <span class="scene-library-result" id="scene-library-result" aria-live="polite"></span>
                        </div>
                    </div>
                    <div class="scene-card-grid">
                        <?php if (!$sceneCards): ?>
                            <div class="scene-empty-library">No scenes yet. Use Create Scene to upload references and generate the first one.</div>
                        <?php endif; ?>
                        <?php foreach ($sceneCards as $sceneCard): ?>
                            <?php
                            $category = (array)$sceneCard['category'];
                            $images = (array)$sceneCard['images'];
                            $slug = (string)($category['category_slug'] ?? '');
                            ?>
                            <?php $sceneDetailUrl = 'world_mother_studio.php?scene=' . rawurlencode($slug) . '#scene-detail'; ?>
                            <article class="scene-card"
                                data-slug="<?= h($slug) ?>"
                                data-name="<?= h((string)($category['category_name'] ?? $slug)) ?>"
                                data-recommended-score="<?= (int)($category['recommended_score'] ?? 0) ?>"
                                data-featured-score="<?= (int)($category['featured_score_effective'] ?? 0) ?>"
                                data-featured-active="<?= !empty($category['featured_active']) ? '1' : '0' ?>"
                                data-editorial-score="<?= (int)($category['editorial_score'] ?? 0) ?>"
                                data-popularity-score="<?= (int)($category['popularity_score'] ?? 0) ?>"
                                data-versatility-score="<?= (int)($category['versatility_score'] ?? 0) ?>"
                                data-usage-count="<?= (int)($category['usage_count'] ?? 0) ?>"
                                data-newest="<?= h((string)($category['discovered_at'] ?? '')) ?>">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap: 8px;">
                                    <div>
                                        <div style="display:flex; align-items:center; gap: 4px;">
                                            <h3><a class="scene-card-title-link" href="<?= h($sceneDetailUrl) ?>"><?= h((string)($category['category_name'] ?? $slug)) ?></a></h3>
                                        </div>
                                        <code><?= h($slug) ?></code>
                                    </div>
                                </div>
                                <?php if ($images): ?>
                                    <a class="scene-card-thumbs-link" href="<?= h($sceneDetailUrl) ?>" aria-label="Manage <?= h((string)($category['category_name'] ?? $slug)) ?> scene">
                                        <div class="scene-card-thumbs">
                                            <?php foreach (array_slice($images, 0, 3) as $image): ?>
                                                <?php
                                                $relativePath = (string)($image['relative_path'] ?? '');
                                                $thumbnailUrl = h(wms_media_url($relativePath, 320));
                                                ?>
                                                <div class="scene-card-thumb-item">
                                                    <img src="<?= $thumbnailUrl ?>" alt="<?= h((string)($image['title'] ?? 'Scene variant')) ?>" loading="lazy" draggable="false">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </a>
                                <?php else: ?>
                                    <div class="scene-card-empty">Drop an image here to add a variant</div>
                                <?php endif; ?>
                                <div class="scene-card-meta">
                                    <span class="scene-pill variants-count"><?= (int)($category['image_count'] ?? count($images)) ?> variants</span>
                                    <span class="scene-pill ranking-score">Recommended <?= (int)($category['recommended_score'] ?? 0) ?></span>
                                    <span class="scene-pill ranking-score">Popular <?= (int)($category['popularity_score'] ?? 0) ?></span>
                                    <span class="scene-pill ranking-score">Versatile <?= (int)($category['versatility_score'] ?? 0) ?></span>
                                    <span class="scene-pill ranking-score"><?= (int)($category['usage_count'] ?? 0) ?> uses</span>
                                    <?php if (!empty($category['featured_active'])): ?>
                                        <span class="scene-pill featured-score">Featured <?= (int)($category['featured_score_effective'] ?? 0) ?></span>
                                    <?php endif; ?>
                                </div>
                                <a class="scene-card-open" href="<?= h($sceneDetailUrl) ?>">Manage Scene</a>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($sceneCards): ?>
                            <div class="scene-empty-library" id="scene-library-no-results" hidden>No scenes match these controls.</div>
                        <?php endif; ?>
                    </div>
            </section>
        </div>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // -------------------------------------------------------------
    // 1. INLINE SCENE CREATOR
    // -------------------------------------------------------------
    const sceneNewAction = document.getElementById('scene-new-action');
    const creatorPanel = document.getElementById('scene-creator-panel');

    function setCreatorOpen(open, restoreFocus = true) {
        if (!creatorPanel) return;
        creatorPanel.classList.toggle('is-open', open);
        creatorPanel.setAttribute('aria-hidden', open ? 'false' : 'true');
        sceneNewAction?.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            creatorPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else if (restoreFocus) {
            sceneNewAction?.focus();
        }
    }

    sceneNewAction?.addEventListener('click', () => {
        setCreatorOpen(!creatorPanel?.classList.contains('is-open'));
    });
    creatorPanel?.querySelectorAll('[data-close-scene-creator]').forEach(control => {
        control.addEventListener('click', () => setCreatorOpen(false));
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && creatorPanel?.classList.contains('is-open')) {
            setCreatorOpen(false);
        }
    });

    const sourceUploader = document.querySelector('[data-scene-source-uploader]');
    const sourceDropzone = sourceUploader?.querySelector('[data-scene-source-dropzone]');
    const sourceInput = sourceUploader?.querySelector('[data-scene-source-input]');
    const sourceLabel = sourceUploader?.querySelector('[data-scene-source-label]');
    const acceptedImage = file => (
        ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)
        || /\.(?:jpe?g|png|webp)$/i.test(file.name || '')
    );
    const uploadSelectedSources = (files, assignToInput = false) => {
        const supplied = Array.from(files || []);
        const selected = supplied.filter(acceptedImage);
        if (!sourceUploader || !sourceInput || selected.length === 0) return;
        if (selected.length !== supplied.length || selected.length > 24) {
            alert('Only JPG, PNG or WEBP images can be added. Maximum 24 images at a time.');
            return;
        }
        if (assignToInput && files) {
            sourceInput.files = files;
        }
        sourceDropzone?.classList.add('is-uploading');
        if (sourceLabel) {
            sourceLabel.textContent = selected.length === 1
                ? 'Adding image…'
                : `Adding ${selected.length} images…`;
        }
        sourceUploader.requestSubmit();
    };
    sourceInput?.addEventListener('change', () => uploadSelectedSources(sourceInput.files));
    ['dragenter', 'dragover'].forEach(type => {
        sourceDropzone?.addEventListener(type, event => {
            event.preventDefault();
            sourceDropzone.classList.add('is-dragging');
        });
    });
    ['dragleave', 'drop'].forEach(type => {
        sourceDropzone?.addEventListener(type, event => {
            event.preventDefault();
            sourceDropzone.classList.remove('is-dragging');
        });
    });
    sourceDropzone?.addEventListener('drop', event => {
        uploadSelectedSources(event.dataTransfer?.files, true);
    });

    const sceneSearch = document.getElementById('scene-library-search');
    const sceneSort = document.getElementById('scene-library-sort');
    const sceneFilter = document.getElementById('scene-library-filter');
    const sceneGrid = document.querySelector('.scene-card-grid');
    const sceneResult = document.getElementById('scene-library-result');
    const sceneNoResults = document.getElementById('scene-library-no-results');
    const sceneCards = Array.from(document.querySelectorAll('.scene-card'));

    function applySceneLibraryControls() {
        if (!sceneGrid) return;

        const query = (sceneSearch?.value || '').trim().toLowerCase();
        const filter = sceneFilter?.value || 'all';
        const mode = sceneSort?.value || 'recommended';
        const numeric = (card, key) => Number(card.dataset[key] || 0);
        const byName = (a, b) => (a.dataset.name || '').localeCompare(b.dataset.name || '', undefined, { sensitivity: 'base' });
        const byRecommended = (a, b) => numeric(b, 'recommendedScore') - numeric(a, 'recommendedScore') || byName(a, b);
        const comparators = {
            recommended: byRecommended,
            featured: (a, b) => numeric(b, 'featuredScore') - numeric(a, 'featuredScore') || byRecommended(a, b),
            editorial: (a, b) => numeric(b, 'editorialScore') - numeric(a, 'editorialScore') || byRecommended(a, b),
            popular: (a, b) => numeric(b, 'popularityScore') - numeric(a, 'popularityScore') || numeric(b, 'usageCount') - numeric(a, 'usageCount') || byName(a, b),
            versatile: (a, b) => numeric(b, 'versatilityScore') - numeric(a, 'versatilityScore') || byRecommended(a, b),
            usage: (a, b) => numeric(b, 'usageCount') - numeric(a, 'usageCount') || byRecommended(a, b),
            newest: (a, b) => Date.parse(b.dataset.newest || 0) - Date.parse(a.dataset.newest || 0) || byName(a, b),
            alpha: byName,
        };

        sceneCards.sort(comparators[mode] || byRecommended).forEach(card => sceneGrid.appendChild(card));

        let visible = 0;
        sceneCards.forEach(card => {
            const searchable = `${card.dataset.name || ''} ${card.dataset.slug || ''}`.toLowerCase();
            const usage = numeric(card, 'usageCount');
            const matchesQuery = searchable.includes(query);
            const matchesFilter = filter === 'all'
                || (filter === 'featured' && card.dataset.featuredActive === '1')
                || (filter === 'low-usage' && usage > 0 && usage <= 2)
                || (filter === 'no-data' && usage === 0);
            const shouldShow = matchesQuery && matchesFilter;
            card.hidden = !shouldShow;
            if (shouldShow) visible += 1;
        });

        if (sceneResult) sceneResult.textContent = `${visible} of ${sceneCards.length}`;
        if (sceneNoResults) sceneNoResults.hidden = visible !== 0;
    }

    sceneSearch?.addEventListener('input', applySceneLibraryControls);
    sceneSort?.addEventListener('change', applySceneLibraryControls);
    sceneFilter?.addEventListener('change', applySceneLibraryControls);
    applySceneLibraryControls();

    // -------------------------------------------------------------
    // 2. DROPZONE FOR REFERENCE UPLOAD
    // -------------------------------------------------------------
    const dropzone = document.getElementById('upload-dropzone');
    const fileInput = document.getElementById('dropzone-file-input');
    const previewsContainer = document.getElementById('dropzone-previews-container');
    const laboratoryAction = document.getElementById('scene-laboratory-action');
    let uploadedFiles = [];

    if (dropzone && fileInput && previewsContainer) {
        dropzone.addEventListener('click', () => fileInput.click());

        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, e => {
                e.preventDefault();
                dropzone.classList.add('dragover');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, e => {
                e.preventDefault();
                dropzone.classList.remove('dragover');
            }, false);
        });

        dropzone.addEventListener('drop', e => {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles(files);
        });

        fileInput.addEventListener('change', () => {
            handleFiles(fileInput.files);
        });

        function handleFiles(files) {
            const validFiles = Array.from(files).filter(file => 
                ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)
            );
            
            if (uploadedFiles.length + validFiles.length > 4) {
                alert('You can upload up to 4 scene references.');
                return;
            }

            validFiles.forEach(file => {
                uploadedFiles.push(file);
                const reader = new FileReader();
                reader.readAsDataURL(file);
                reader.onloadend = () => {
                    const previewItem = document.createElement('div');
                    previewItem.className = 'dropzone-preview-item';
                    previewItem.innerHTML = `
                        <img src="${reader.result}" alt="${file.name}">
                        <button type="button" class="remove-btn">&times;</button>
                    `;
                    
                    previewItem.querySelector('.remove-btn').addEventListener('click', (e) => {
                        e.stopPropagation();
                        const idx = uploadedFiles.indexOf(file);
                        if (idx > -1) {
                            uploadedFiles.splice(idx, 1);
                        }
                        previewItem.remove();
                        syncFileInput();
                    });

                    previewsContainer.appendChild(previewItem);
                };
            });
            
            setTimeout(syncFileInput, 100);
        }

        function syncFileInput() {
            const dt = new DataTransfer();
            uploadedFiles.forEach(file => dt.items.add(file));
            fileInput.files = dt.files;
            if (laboratoryAction) {
                laboratoryAction.disabled = uploadedFiles.length === 0;
            }
        }
    }

    // -------------------------------------------------------------
    // 4. DRAG & DROP FOR MERGING SCENES
    // -------------------------------------------------------------
    let draggedCard = null;

    document.querySelectorAll('.scene-card').forEach(card => {
        const dragHandle = card.querySelector('.scene-drag-handle');
        if (dragHandle) {
            dragHandle.addEventListener('mousedown', () => card.dataset.dragArmed = 'true');
            dragHandle.addEventListener('mouseup', () => card.dataset.dragArmed = 'false');
        }
        card.addEventListener('dragstart', e => {
            if (e.dataTransfer.types.includes('Files')) return;
            if (card.dataset.dragArmed !== 'true') {
                e.preventDefault();
                return;
            }
            draggedCard = card;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.getAttribute('data-slug'));
            card.style.opacity = '0.5';
        });

        card.addEventListener('dragend', () => {
            draggedCard = null;
            card.dataset.dragArmed = 'false';
            card.style.opacity = '1';
            document.querySelectorAll('.scene-card').forEach(c => c.classList.remove('drag-over'));
        });

        card.addEventListener('dragover', e => {
            e.preventDefault();
            if (draggedCard && draggedCard !== card) {
                card.classList.add('drag-over');
            }
        });

        card.addEventListener('dragleave', () => {
            card.classList.remove('drag-over');
        });

        card.addEventListener('drop', e => {
            e.preventDefault();
            card.classList.remove('drag-over');

            if (draggedCard && draggedCard !== card) {
                const sourceSlug = draggedCard.getAttribute('data-slug');
                const sourceName = draggedCard.getAttribute('data-name');
                const targetSlug = card.getAttribute('data-slug');
                const targetName = card.getAttribute('data-name');

                if (confirm(`Merge "${sourceName}" into "${targetName}"?\n\nAll variants will be moved and the source scene will be removed.`)) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'world_mother_studio.php';
                    form.innerHTML = `
                        <input type="hidden" name="csrf" value="<?= h($sceneAdminCsrf) ?>">
                        <input type="hidden" name="action" value="merge_category">
                        <input type="hidden" name="source_category" value="${sourceSlug}">
                        <input type="hidden" name="target_category" value="${targetSlug}">
                    `;
                    document.body.appendChild(form);
                    form.requestSubmit();
                }
            }
        });
    });

    // -------------------------------------------------------------
    // 5. DRAG & DROP FOR DIRECT UPLOAD ON SCENE CARDS
    // -------------------------------------------------------------
    document.querySelectorAll('.scene-card').forEach(card => {
        card.addEventListener('dragover', e => {
            if (e.dataTransfer.types.includes('Files')) {
                e.preventDefault();
                card.classList.add('drag-over');
            }
        });

        card.addEventListener('dragleave', () => {
            card.classList.remove('drag-over');
        });

        card.addEventListener('drop', e => {
            if (e.dataTransfer.types.includes('Files')) {
                e.preventDefault();
                card.classList.remove('drag-over');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const file = files[0];
                    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                        alert('Unsupported format. Use JPG, PNG or WEBP.');
                        return;
                    }

                    const targetSlug = card.getAttribute('data-slug');
                    const targetName = card.getAttribute('data-name');

                    if (confirm(`Upload "${file.name}" as a new variant in "${targetName}"?`)) {
                        uploadVariantToCard(card, targetSlug, file);
                    }
                }
            }
        });
    });

    function uploadVariantToCard(card, slug, file) {
        const overlay = document.createElement('div');
        overlay.className = 'scene-card-upload-overlay';
        overlay.innerHTML = `
            <div class="spinner-sutil"></div>
            <div class="upload-progress-text">Uploading...</div>
        `;
        card.appendChild(overlay);

        const formData = new FormData();
        formData.append('csrf', '<?= h($sceneAdminCsrf) ?>');
        formData.append('action', 'upload_variant');
        formData.append('target_category', slug);
        formData.append('variant_image', file);
        formData.append('ajax', '1');

        fetch('world_mother_studio.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => { throw new Error(err.error || 'Upload failed'); });
            }
            return response.json();
        })
        .then(data => {
            overlay.remove();
            alert(data.notice || 'Variant uploaded successfully.');
            window.location.reload();
        })
        .catch(err => {
            overlay.remove();
            alert('Upload error: ' + err.message);
        });
    }
});
</script>
</body>
</html>
