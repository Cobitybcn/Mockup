<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function wms_media_url(string $path): string
{
    $relativePath = wms_storage_relative_path($path);
    if ($relativePath === '') {
        return '';
    }

    return 'world_mother_media.php?file=' . rawurlencode($relativePath) . '&thumb=1&w=640';
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
        throw new RuntimeException('No se pudo subir la imagen de referencia.');
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new RuntimeException('Formato no permitido. Usa JPG, PNG o WEBP.');
    }
    $dir = __DIR__ . '/storage/world_mother_uploads';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear la carpeta de uploads.');
    }
    $name = 'world_mother_ref_' . date('Ymd_His') . '_' . random_int(1000, 9999) . '.' . $ext;
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file((string)$file['tmp_name'], $path)) {
        throw new RuntimeException('No se pudo guardar la imagen subida.');
    }
    if (StorageService::isGcsActive()) {
        $storageKey = 'storage/world_mother_uploads/' . $name;
        if (!StorageService::uploadFile($storageKey, $path)) {
            @unlink($path);
            throw new RuntimeException('No se pudo guardar la referencia en el almacenamiento persistente.');
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
            throw new RuntimeException('Sube entre 1 y 4 imagenes de referencia.');
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
            throw new RuntimeException('Sube al menos una imagen de referencia.');
        }
        return $paths;
    }

    return [wms_upload_file($files)];
}

$library = new WorldMotherLibrary();
$generator = new WorldMotherGenerator($library);
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

try {
    $action = trim((string)($_POST['action'] ?? ''));
    $adminActions = ['create_category', 'rename_category', 'merge_category', 'delete_category', 'rebuild_index', 'upload_variant'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, $adminActions, true)) {
        if (!Auth::isAdmin($user)) {
            throw new RuntimeException('Only an administrator can manage the scene library.');
        }
        if (!hash_equals($sceneAdminCsrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('The scene management session expired. Reload the page and try again.');
        }

        if ($action === 'create_category') {
            $createdCategory = $library->createCategory((string)($_POST['category_name'] ?? ''));
            $notice = 'Scene created: ' . (string)($createdCategory['category_name'] ?? $createdCategory['category_slug'] ?? '');
        } elseif ($action === 'rename_category') {
            $renamedCategory = $library->renameCategory(
                (string)($_POST['source_category'] ?? ''),
                (string)($_POST['new_category_name'] ?? '')
            );
            $notice = 'Scene renamed to ' . (string)($renamedCategory['category_name'] ?? $renamedCategory['category_slug'] ?? '') . '.';
        } elseif ($action === 'merge_category') {
            $merge = $library->mergeCategory(
                (string)($_POST['source_category'] ?? ''),
                (string)($_POST['target_category'] ?? '')
            );
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
            $notice = sprintf(
                'Scene deleted: %s (%d images removed).',
                (string)($deleted['category_slug'] ?? ''),
                (int)($deleted['deleted_images'] ?? 0)
            );
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
            if (!is_dir($destDirectory)) {
                throw new RuntimeException('The target scene folder does not exist.');
            }
            if (!isset($_FILES['variant_image']) || ($_FILES['variant_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('No image was uploaded.');
            }
            $tempPath = wms_upload_file($_FILES['variant_image']);
            $finalPath = $destDirectory . DIRECTORY_SEPARATOR . basename($tempPath);
            if (!rename($tempPath, $finalPath)) {
                @unlink($tempPath);
                throw new RuntimeException('Failed to move uploaded variant to the scene folder.');
            }
            if (StorageService::isGcsActive()) {
                $finalStorageKey = 'storage/world_mothers/' . $target . '/' . basename($finalPath);
                if (!StorageService::uploadFile($finalStorageKey, $finalPath)) {
                    @unlink($finalPath);
                    throw new RuntimeException('The new variant could not be saved to persistent storage.');
                }
                StorageService::delete('storage/world_mother_uploads/' . basename($tempPath));
            }
            $library->rebuildIndex();
            $notice = 'New variant uploaded directly to scene: ' . $target;
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
        header('Location: world_mother_studio.php#scene-library');
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

$categories = $library->categories();
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
    ];
}
$totalSceneVariants = array_sum(array_map(
    static fn (array $sceneCard): int => count((array)($sceneCard['images'] ?? [])),
    $sceneCards
));
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
            align-items:end;
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
        .scene-library-search { display:grid; gap:6px; width:min(280px, 100%); }
        .scene-library-search span { color:var(--muted); font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .scene-library-search input { width:100%; height:42px; padding:9px 12px; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); color:var(--ink); }
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
        }
        .scene-card h3 { margin:0; font-family:var(--font-serif), Georgia, serif; font-size:18px; font-weight: 600; line-height:1.25; color: var(--ink); }
        .scene-card code { color:var(--muted); font-size:11px; word-break:break-word; }
        .scene-card-thumbs { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:5px; min-height:72px; }
        .scene-card-thumbs img { width:100%; aspect-ratio: 4 / 3; object-fit:cover; border:1px solid var(--line); border-radius:4px; background:var(--surface-soft); }
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
        .scene-card-admin .danger-button { border-color:var(--danger); background:transparent; color:var(--danger); box-shadow:none; }
        .scene-card-admin .danger-button:hover { background:#fff5f5; border-color:var(--danger); color:var(--danger); }
        .scene-empty-library { grid-column:1 / -1; padding:32px; border:1px dashed var(--line); border-radius:var(--radius); color:var(--muted); text-align:center; }
        @media (min-width: 2400px) { .scene-card-grid { grid-template-columns:repeat(6, minmax(0, 1fr)); } }
        @media (max-width: 1740px) { .scene-card-grid { grid-template-columns:repeat(4, minmax(0, 1fr)); } }
        @media (max-width: 1350px) { .scene-card-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 1050px) { .scene-card-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 980px) { .studio-grid { grid-template-columns: 1fr; } }
        @media (max-width: 720px) { .scene-card-grid { grid-template-columns:1fr; } .scene-library-head, .scene-library-heading { display:block; } .scene-library-search { margin-top:14px; width:100%; } .scene-admin-bar, .scene-admin-bar form { grid-template-columns:1fr; } }

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
        .scene-sync-form {
            position: absolute;
            top: 37.5px;
            right: calc(100% + 50px);
            margin: 0;
        }
        .scene-sync-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
            width: 75px;
            min-width: 75px;
            height: 75px;
            min-height: 75px;
            margin: 0;
            padding: 0;
            border: 1px solid #9fbd99;
            border-radius: 50%;
            background:
                radial-gradient(circle at 31% 24%, rgba(255,255,255,.88) 0 5%, rgba(255,255,255,0) 27%),
                linear-gradient(145deg, #eef7eb 0%, #d9ead5 46%, #bad3b5 100%);
            color: #4f744b;
            box-shadow:
                0 12px 24px rgba(67, 100, 63, .18),
                0 3px 7px rgba(67, 100, 63, .12),
                inset 3px 3px 6px rgba(255, 255, 255, .8),
                inset -4px -4px 8px rgba(79, 116, 75, .16);
            overflow: hidden;
            transition: transform .2s ease, box-shadow .2s ease, filter .2s ease;
        }
        .scene-sync-action::before {
            content: '';
            position: absolute;
            inset: 6px;
            border: 1px solid rgba(92, 132, 87, .34);
            border-radius: 50%;
            box-shadow:
                inset 1px 1px 2px rgba(255,255,255,.9),
                inset -1px -1px 2px rgba(71,105,67,.18),
                0 0 0 2px rgba(255,255,255,.32);
            pointer-events: none;
        }
        .scene-sync-action::after {
            content: '';
            position: absolute;
            inset: 17px;
            border: 1px solid rgba(91, 128, 86, .2);
            border-radius: 50%;
            background: linear-gradient(145deg, rgba(255,255,255,.32), rgba(95,137,89,.08));
            box-shadow: inset 1px 1px 3px rgba(255,255,255,.55), inset -2px -2px 4px rgba(75,111,71,.1);
            pointer-events: none;
        }
        .scene-sync-action:hover {
            border-color: #8eaf88 !important;
            background:
                radial-gradient(circle at 31% 24%, rgba(255,255,255,.92) 0 5%, rgba(255,255,255,0) 27%),
                linear-gradient(145deg, #f1f9ee 0%, #dcecd8 46%, #b7d1b2 100%) !important;
            color: #426a3f !important;
            filter: saturate(1.05);
            box-shadow:
                0 16px 30px rgba(67, 100, 63, .23),
                0 4px 9px rgba(67, 100, 63, .13),
                inset 3px 3px 7px rgba(255,255,255,.86),
                inset -4px -4px 9px rgba(79,116,75,.18);
            transform: translateY(-2px);
        }
        .scene-sync-action:active {
            transform: translateY(1px);
            box-shadow: 0 4px 10px rgba(67,100,63,.14), inset 2px 2px 6px rgba(67,100,63,.15);
        }
        .scene-sync-action:focus-visible { outline: 2px solid #6f9669; outline-offset: 4px; }
        .scene-sync-action svg {
            position: relative;
            z-index: 2;
            width: 30px;
            height: 30px;
            filter: drop-shadow(0 1px 0 rgba(255,255,255,.72));
            transition: transform .45s cubic-bezier(.2, .8, .2, 1);
        }
        .scene-sync-action:hover svg { transform: rotate(180deg); }
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
            .scene-sync-form { top:32px; right:calc(100% + 52px); }
            .scene-sync-action { width:64px; min-width:64px; height:64px; min-height:64px; }
            .scene-sync-action::after { inset:14px; }
            .scene-primary-action { width:128px; min-width:128px; height:128px; min-height:128px; }
        }
        @media (max-width: 720px) {
            .workspace { padding: 20px 16px 46px; }
            .scene-header-v3 { display:block; padding-bottom:20px; }
            .scene-header-v3 h1 { margin-bottom:12px; font-size:34px; }
            .scene-page-desc .desc-kicker { font-size:13px; }
            .scene-page-desc .desc-instructions { font-size:14px; }
            .scene-header-actions { grid-template-columns:46px minmax(0, 1fr); width:100%; margin:18px 0 0; }
            .scene-sync-form { position:static; grid-column:1; grid-row:1; }
            .scene-sync-action { width:46px; min-width:46px; height:46px; min-height:46px; }
            .scene-sync-action::before { inset:4px; }
            .scene-sync-action::after { inset:10px; }
            .scene-sync-action svg { width:21px; height:21px; }
            .scene-primary-action { grid-column:2; }
            .scene-primary-action { width:100%; min-width:0; height:52px; min-height:52px; padding:10px 16px; }
            .scene-artworks-link { grid-column:2; align-self:center; padding:0 8px; }
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
        .scene-gallery-layout {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 8px 0;
        }
        .scene-gallery-featured {
            width: 100%;
            height: 0;
            padding-bottom: 75%; /* Bulletproof 4:3 Aspect Ratio */
            position: relative;
            border: 1px solid var(--line);
            border-radius: 4px;
            overflow: hidden;
            background: var(--surface-soft);
        }
        .scene-gallery-featured img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: opacity 0.25s ease;
        }
        .scene-gallery-thumbs {
            display: flex;
            gap: 5px;
            overflow-x: auto;
            scrollbar-width: none;
            padding-bottom: 2px;
        }
        .scene-gallery-thumbs::-webkit-scrollbar {
            display: none;
        }
        .scene-gallery-thumb-item {
            width: 56px;
            height: 56px;
            flex-shrink: 0;
            border: 1px solid var(--line);
            border-radius: 3px;
            overflow: hidden;
            cursor: pointer;
            background: var(--surface-soft);
            transition: border-color 0.2s, transform 0.2s;
        }
        .scene-gallery-thumb-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .scene-gallery-thumb-item:hover,
        .scene-gallery-thumb-item.active {
            border-color: #b07d80; /* Old rose border accent */
            transform: scale(1.035);
        }
        .scene-card-thumb-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.2s ease;
        }
        .scene-card-thumb-item:hover img {
            transform: scale(1.15);
        }
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

        .popover-preview {
            position: fixed;
            z-index: 10000;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: 0 10px 30px rgba(20, 20, 18, 0.15);
            padding: 6px;
            max-width: 320px;
            pointer-events: none;
            display: none;
        }
        .popover-preview img {
            width: 100%;
            height: auto;
            max-height: 240px;
            object-fit: contain;
            display: block;
            border-radius: 4px;
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
                    <?php if ($canManageScenes): ?>
                        <form class="scene-sync-form" method="post" onsubmit="return confirm('Rebuild the scene index from the folders currently on disk?');">
                            <input type="hidden" name="csrf" value="<?= h($sceneAdminCsrf) ?>">
                            <input type="hidden" name="action" value="rebuild_index">
                            <button class="scene-sync-action" type="submit" aria-label="Sync scene folders" title="Sync scene folders">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path fill="currentColor" d="M12 4V1L8 5l4 4V6a6 6 0 0 1 5.3 8.8l1.46 1.46A7.93 7.93 0 0 0 20 12a8 8 0 0 0-8-8ZM6 10c0-1.01.25-1.97.7-2.8L5.24 5.74A7.93 7.93 0 0 0 4 10a8 8 0 0 0 8 8v3l4-4-4-4v3a6 6 0 0 1-6-6Z"></path>
                                    <circle cx="12" cy="12" r="1.25" fill="currentColor" opacity=".34"></circle>
                                </svg>
                            </button>
                        </form>
                    <?php endif; ?>
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
                            <p>Upload 1–4 references, analyze their visual DNA and generate the reusable scene family.</p>
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
                                    <div class="analysis-thumb-item" data-full-url="<?= $webPath ?>">
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
                                <p>Upload references to extract the visual DNA of the scene:</p>
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
                                    <div class="analysis-thumb-item" data-full-url="<?= $genUrl ?>">
                                        <img src="<?= $genUrl ?>" alt="Generated variant">
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($generated['images']) && !empty($generated['relative_path'])): ?>
                                    <?php $genUrl = h(wms_media_url((string)($generated['relative_path'] ?? ''))); ?>
                                    <div class="analysis-thumb-item" data-full-url="<?= $genUrl ?>">
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

            <section class="scene-library" id="scene-library">
                    <div class="scene-library-heading">
                        <div>
                            <span class="scene-library-kicker">Curated visual worlds</span>
                            <h2>Scene Library</h2>
                            <p><?= count($sceneCards) ?> scenes · <?= $totalSceneVariants ?> variants</p>
                        </div>
                        <label class="scene-library-search">
                            <span>Search scenes</span>
                            <input id="scene-library-search" type="search" placeholder="Search by name" autocomplete="off">
                        </label>
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
                            <article class="scene-card" draggable="<?= $canManageScenes ? 'true' : 'false' ?>" data-slug="<?= h($slug) ?>" data-name="<?= h((string)($category['category_name'] ?? $slug)) ?>">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap: 8px;">
                                    <div>
                                        <div style="display:flex; align-items:center; gap: 4px;">
                                            <?php if ($canManageScenes): ?><span class="scene-drag-handle" title="Drag this scene onto another one to merge">☰</span><?php endif; ?>
                                            <h3><?= h((string)($category['category_name'] ?? $slug)) ?></h3>
                                        </div>
                                        <code style="<?= $canManageScenes ? 'margin-left: 20px;' : '' ?>"><?= h($slug) ?></code>
                                    </div>
                                </div>
                                <?php if ($images): ?>
                                    <div class="scene-gallery-layout">
                                        <!-- Main featured thumbnail (Big Thumb) -->
                                        <?php $firstImage = h(wms_media_url((string)($images[0]['relative_path'] ?? ''))); ?>
                                        <div class="scene-gallery-featured" data-full-url="<?= $firstImage ?>">
                                            <img class="featured-img" src="<?= $firstImage ?>" alt="Featured variant" id="featured-<?= h(md5($slug)) ?>">
                                        </div>
                                        
                                        <!-- Other variants (Small Thumbs) -->
                                        <div class="scene-gallery-thumbs">
                                            <?php foreach ($images as $idx => $image): ?>
                                                <?php $variantUrl = h(wms_media_url((string)($image['relative_path'] ?? ''))); ?>
                                                <div class="scene-gallery-thumb-item <?= $idx === 0 ? 'active' : '' ?>" data-full-url="<?= $variantUrl ?>" data-target-id="featured-<?= h(md5($slug)) ?>">
                                                    <img src="<?= $variantUrl ?>" alt="<?= h((string)($image['title'] ?? 'Scene variant')) ?>">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="scene-card-empty">Drop an image here to add a variant</div>
                                <?php endif; ?>
                                <div class="scene-card-meta">
                                    <span class="scene-pill variants-count"><?= (int)($category['image_count'] ?? count($images)) ?> variants</span>
                                </div>
                                <?php if ($canManageScenes): ?>
                                    <details class="scene-card-admin">
                                        <summary>Manage Scene</summary>
                                        <div class="scene-card-admin-body">
                                            <form method="post">
                                                <input type="hidden" name="csrf" value="<?= h($sceneAdminCsrf) ?>">
                                                <input type="hidden" name="action" value="rename_category">
                                                <input type="hidden" name="source_category" value="<?= h($slug) ?>">
                                                <label for="rename-<?= h(md5($slug)) ?>">Rename Scene</label>
                                                <input id="rename-<?= h(md5($slug)) ?>" type="text" name="new_category_name" value="<?= h((string)($category['category_name'] ?? $slug)) ?>" maxlength="80" required>
                                                <div class="button-row">
                                                    <button class="button-link secondary" type="submit">Rename</button>
                                                </div>
                                            </form>

                                            <form method="post" onsubmit="return confirm('Move every image into the selected destination and remove this source scene?');">
                                                <input type="hidden" name="csrf" value="<?= h($sceneAdminCsrf) ?>">
                                                <input type="hidden" name="action" value="merge_category">
                                                <input type="hidden" name="source_category" value="<?= h($slug) ?>">
                                                <label for="merge-<?= h(md5($slug)) ?>">Merge Into</label>
                                                <select id="merge-<?= h(md5($slug)) ?>" name="target_category" required <?= count($sceneCards) < 2 ? 'disabled' : '' ?>>
                                                    <option value="">Choose destination scene</option>
                                                    <?php foreach ($sceneCards as $targetSceneCard): ?>
                                                        <?php
                                                        $targetCategory = (array)($targetSceneCard['category'] ?? []);
                                                        $targetSlug = (string)($targetCategory['category_slug'] ?? '');
                                                        if ($targetSlug === '' || $targetSlug === $slug) {
                                                            continue;
                                                        }
                                                        ?>
                                                        <option value="<?= h($targetSlug) ?>"><?= h((string)($targetCategory['category_name'] ?? $targetSlug)) ?> · <?= count((array)($targetSceneCard['images'] ?? [])) ?> variants</option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="button-row">
                                                    <button class="button-link secondary" type="submit" <?= count($sceneCards) < 2 ? 'disabled' : '' ?>>Merge Scene</button>
                                                </div>
                                            </form>

                                            <form method="post" onsubmit="return confirm('Delete the scene &quot;<?= h(addslashes($slug)) ?>&quot; and all of its <?= count($images) ?> images? This cannot be undone.');">
                                                <input type="hidden" name="csrf" value="<?= h($sceneAdminCsrf) ?>">
                                                <input type="hidden" name="action" value="delete_category">
                                                <input type="hidden" name="source_category" value="<?= h($slug) ?>">
                                                <input type="hidden" name="confirm_delete" value="yes">
                                                <div class="button-row">
                                                    <button class="danger-button" type="submit">Delete Scene</button>
                                                </div>
                                            </form>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
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

    const sceneSearch = document.getElementById('scene-library-search');
    if (sceneSearch) {
        sceneSearch.addEventListener('input', () => {
            const query = sceneSearch.value.trim().toLowerCase();
            document.querySelectorAll('.scene-card').forEach(card => {
                const searchable = `${card.dataset.name || ''} ${card.dataset.slug || ''}`.toLowerCase();
                card.style.display = searchable.includes(query) ? '' : 'none';
            });
        });
    }

    // -------------------------------------------------------------
    // 2. POPOVER PREVIEW FOR COMPACT THUMBNAILS
    // -------------------------------------------------------------
    const popover = document.createElement('div');
    popover.className = 'popover-preview';
    document.body.appendChild(popover);

    // Hover variant swap in card
    document.addEventListener('mouseover', e => {
        const thumbItem = e.target.closest('.scene-gallery-thumb-item');
        if (thumbItem) {
            const targetId = thumbItem.getAttribute('data-target-id');
            const fullUrl = thumbItem.getAttribute('data-full-url');
            const featuredImg = document.getElementById(targetId);
            if (featuredImg && featuredImg.src !== fullUrl) {
                featuredImg.src = fullUrl;
                
                // Highlight active state
                const card = thumbItem.closest('.scene-card');
                if (card) {
                    card.querySelectorAll('.scene-gallery-thumb-item').forEach(item => {
                        item.classList.remove('active');
                    });
                    thumbItem.classList.add('active');
                }
            }
        }
    });

    document.addEventListener('mouseover', e => {
        const thumbItem = e.target.closest('.analysis-thumb-item');
        if (thumbItem) {
            const img = thumbItem.querySelector('img');
            if (img) {
                const fullUrl = thumbItem.getAttribute('data-full-url') || img.src;
                popover.innerHTML = `<img src="${fullUrl}" alt="Preview">`;
                popover.style.display = 'block';
            }
        }
    });

    document.addEventListener('mousemove', e => {
        if (popover.style.display === 'block') {
            const offset = 15;
            let left = e.clientX + offset;
            let top = e.clientY + offset;
            if (left + 330 > window.innerWidth) {
                left = e.clientX - 335;
            }
            if (top + 250 > window.innerHeight) {
                top = e.clientY - 255;
            }
            popover.style.left = `${left}px`;
            popover.style.top = `${top}px`;
        }
    });

    document.addEventListener('mouseout', e => {
        const thumbItem = e.target.closest('.analysis-thumb-item');
        if (thumbItem) {
            popover.style.display = 'none';
        }
    });

    // -------------------------------------------------------------
    // 3. DROPZONE FOR REFERENCE UPLOAD
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
            if (laboratoryAction && laboratoryAction.getAttribute('form') === 'main-upload-form') {
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
                    form.submit();
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
