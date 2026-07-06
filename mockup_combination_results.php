<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$pdo = Database::connection();
AdminSceneEditor::handlePost($user);
$id = max(0, (int)($_GET['id'] ?? 0));
$selectedWorldMotherCategory = trim(str_replace(['\\', '/'], '', (string)($_GET['world_mother_category'] ?? '')));
$sceneBoardIndex = max(1, min(3, (int)($_GET['board'] ?? 1)));
if ($id <= 0) {
    http_response_code(404);
    die('Artwork ID is missing.');
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

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function world_mother_image_url(string $file): string
{
    $file = str_replace('\\', '/', trim($file));
    if ($file === '' || !str_starts_with($file, 'storage/world_mothers/')) {
        return '';
    }

    return 'world_mother_media.php?file=' . rawurlencode($file);
}

function results_friendly_camera_name(string $slug): string
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
        'frontal-lejos-loft' => 'Loft - Frontal Wide View',
    ];

    if (isset($mapping[$slug])) {
        return $mapping[$slug];
    }

    $clean = str_replace(['-', '_'], ' ', $slug);
    $clean = str_replace(['de obra', 'de', 'para'], '', $clean);
    $clean = preg_replace('/\s+/', ' ', $clean);
    return ucwords(trim((string)$clean));
}

$combinationEngine = new MockupCombinationEngine($pdo);
$cameraSlotsByBoard = [];
for ($boardIndex = 1; $boardIndex <= 3; $boardIndex++) {
    $cameraSlotsByBoard[$boardIndex] = $combinationEngine->activeCameraSlots($boardIndex);
}
$cameraSlotsById = $cameraSlotsByBoard[$sceneBoardIndex] ?? [];
function current_camera_slot_name(string $slotId, string $fallback, array $cameraSlotsById): string
{
    $slotId = trim($slotId);
    if ($slotId !== '' && isset($cameraSlotsById[$slotId])) {
        $currentName = trim((string)($cameraSlotsById[$slotId]['slot_name'] ?? ''));
        if ($currentName !== '') {
            return $currentName;
        }
    }

    $fallback = trim($fallback);
    if ($fallback !== '') {
        return $fallback;
    }

    return $slotId !== '' ? results_friendly_camera_name($slotId) : 'Camera';
}

$stmt = $pdo->prepare('
    SELECT *
    FROM mockups
    WHERE user_id = :user_id
    AND (
        artwork_file = :artwork_file
        OR selector_state_json LIKE :audit_path
    )
    ORDER BY id DESC
');
$stmt->execute([
    'user_id' => (int)$artwork['user_id'],
    'artwork_file' => basename((string)$artwork['root_file']),
    'audit_path' => '%analysis/mockup-combination-audit/' . (int)$id . '/%',
]);
$rows = [];
foreach ($stmt->fetchAll() ?: [] as $row) {
    $state = json_decode((string)($row['selector_state_json'] ?? ''), true);
    if (!is_array($state) || ($state['generation_source'] ?? '') !== 'mockup_combination_review') {
        continue;
    }
    $combo = (array)($state['combination'] ?? []);
    $rowSceneBoardIndex = max(1, min(3, (int)($combo['camera_slot_scene_board_index'] ?? 1)));
    $combo['camera_slot_scene_board_index'] = $rowSceneBoardIndex;
    $state['combination'] = $combo;
    $row['selector_state'] = $state;
    $rows[] = $row;
}
$favoriteMockupLookup = MockupFavorites::lookupForUser((int)$user['id']);
$generatedBoardIndexes = [];
foreach ($rows as $row) {
    $combo = (array)(($row['selector_state'] ?? [])['combination'] ?? []);
    $generatedBoardIndexes[max(1, min(3, (int)($combo['camera_slot_scene_board_index'] ?? 1)))] = true;
}
$nextSceneBoardIndex = 0;
for ($boardIndex = 2; $boardIndex <= 3; $boardIndex++) {
    if (!empty($cameraSlotsByBoard[$boardIndex]) && empty($generatedBoardIndexes[$boardIndex])) {
        $nextSceneBoardIndex = $boardIndex;
        break;
    }
}
$nextSceneBoardHasScenes = $nextSceneBoardIndex > 0;

$review = $combinationEngine->buildForArtwork($id, [], [
    'selected_world_mother_category' => $selectedWorldMotherCategory,
    'scene_board_index' => $sceneBoardIndex,
]);
$expectedCombinations = (array)($review['combinations'] ?? []);
$selectedWorldMotherCategory = (string)($review['selected_world_mother_category'] ?? $selectedWorldMotherCategory);
$sceneBoardIndex = max(1, min(3, (int)($review['scene_board_index'] ?? $sceneBoardIndex)));

$generatedByIndex = [];
foreach ($rows as $row) {
    $combo = (array)(($row['selector_state'] ?? [])['combination'] ?? []);
    if (max(1, min(3, (int)($combo['camera_slot_scene_board_index'] ?? 1))) !== $sceneBoardIndex) {
        continue;
    }
    $comboIndex = (int)($combo['combination_index'] ?? 0);
    if ($comboIndex <= 0) {
        continue;
    }
    $generatedByIndex[$comboIndex] = [
        'mockup_id' => (int)$row['id'],
        'mockup_file' => basename((string)($row['mockup_file'] ?? '')),
        'created_at' => (string)($row['created_at'] ?? ''),
        'camera_slot_id' => (string)($combo['selected_camera_slot_id'] ?? ''),
        'camera_name' => current_camera_slot_name(
            (string)($combo['selected_camera_slot_id'] ?? ''),
            (string)($combo['camera_slot_name'] ?? ''),
            $cameraSlotsByBoard[max(1, min(3, (int)($combo['camera_slot_scene_board_index'] ?? 1)))] ?? []
        ),
    ];
}

$auditByIndex = [];
$auditDir = __DIR__ . '/analysis/mockup-combination-audit/' . $id;
foreach (glob($auditDir . '/combination-*.generation.json') ?: [] as $auditFile) {
    $decoded = json_decode((string)file_get_contents($auditFile), true);
    if (!is_array($decoded)) {
        continue;
    }
    if (max(1, min(3, (int)($decoded['combination']['camera_slot_scene_board_index'] ?? 1))) !== $sceneBoardIndex) {
        continue;
    }
    $comboIndex = (int)($decoded['combination']['combination_index'] ?? 0);
    if ($comboIndex <= 0) {
        continue;
    }
    $completedAt = (string)($decoded['completed_at'] ?? $decoded['started_at'] ?? '');
    if (!isset($auditByIndex[$comboIndex]) || strcmp($completedAt, (string)($auditByIndex[$comboIndex]['completed_at'] ?? '')) > 0) {
        $decoded['completed_at'] = $completedAt;
        $auditByIndex[$comboIndex] = $decoded;
    }
}

$cameraReport = [];
foreach ($expectedCombinations as $combo) {
    $comboIndex = (int)($combo['combination_index'] ?? 0);
    if ($comboIndex <= 0) {
        continue;
    }
    $cameraSlotId = (string)($combo['selected_camera_slot_id'] ?? '');
    $generated = $generatedByIndex[$comboIndex] ?? null;
    $audit = $auditByIndex[$comboIndex] ?? null;
    $status = 'pending';
    $detail = 'Not generated yet.';
    if (is_array($generated)) {
        $status = 'generated';
        $detail = 'Mockup #' . (int)$generated['mockup_id'];
    } elseif (is_array($audit) && (string)($audit['status'] ?? '') === 'generated') {
        $status = 'generated';
        $detail = 'Generated in audit' . (!empty($audit['mockup_id']) ? ' - Mockup #' . (int)$audit['mockup_id'] : '');
    } elseif (is_array($audit) && (string)($audit['status'] ?? '') === 'failed') {
        $status = 'failed';
        $detail = trim((string)($audit['error'] ?? 'Generation failed.'));
    } elseif (is_array($audit) && in_array((string)($audit['status'] ?? ''), ['prepared', 'processing'], true)) {
        $startedAt = strtotime((string)($audit['started_at'] ?? '')) ?: 0;
        $isStale = $startedAt > 0 && (time() - $startedAt) > 900;
        $status = $isStale ? 'failed' : 'generating';
        $detail = $isStale ? 'Generation started but did not finish. Try this set again.' : 'Generation is running.';
    } else {
        $detail = 'Not attempted yet.';
    }
    $cameraReport[] = [
        'index' => $comboIndex,
        'status' => $status,
        'detail' => $detail,
        'camera_slot_id' => $cameraSlotId,
        'camera_name' => current_camera_slot_name(
            $cameraSlotId,
            (string)($combo['camera_slot_name'] ?? ''),
            $cameraSlotsById
        ),
        'world_mother_category' => (string)($combo['world_mother_category'] ?? $selectedWorldMotherCategory),
        'generated' => $generated,
        'audit' => $audit,
    ];
}
$generatedCount = count(array_filter($cameraReport, static fn (array $item): bool => $item['status'] === 'generated'));
$failedCount = count(array_filter($cameraReport, static fn (array $item): bool => $item['status'] === 'failed'));
$pendingCount = count(array_filter($cameraReport, static fn (array $item): bool => $item['status'] === 'pending'));
$visibleCameraReport = array_values(array_filter($cameraReport, static fn (array $item): bool => $item['status'] !== 'pending'));

$resultGroups = [[
    'group_id' => 'scene_board',
    'group_name' => 'Scene Boards Results',
    'group_order' => 1,
    'items' => [],
]];
foreach ($rows as $row) {
    $combo = (array)(($row['selector_state'] ?? [])['combination'] ?? []);
    $slotId = (string)($combo['selected_camera_slot_id'] ?? '');
    $rowSceneBoardIndex = max(1, min(3, (int)($combo['camera_slot_scene_board_index'] ?? 1)));
    $rowCameraSlotsById = $cameraSlotsByBoard[$rowSceneBoardIndex] ?? [];
    $slotMeta = isset($rowCameraSlotsById[$slotId]) && is_array($rowCameraSlotsById[$slotId]) ? $rowCameraSlotsById[$slotId] : [];
    $boardOrder = (int)($slotMeta['board_order'] ?? $combo['camera_slot_board_order'] ?? 0);
    if ($boardOrder <= 0) {
        $boardOrder = (int)($combo['combination_index'] ?? 999);
    }
    $resultGroups[0]['items'][] = [
        'row' => $row,
        'variant_label' => 'Batch ' . $rowSceneBoardIndex . ($boardOrder > 0 ? ' · Posición ' . $boardOrder : ''),
        'scene_board_index' => $rowSceneBoardIndex,
        'board_order' => $boardOrder > 0 ? $boardOrder : 999,
    ];
}
foreach ($resultGroups as &$resultGroup) {
    usort($resultGroup['items'], static function (array $a, array $b): int {
        $batchCompare = ((int)($a['scene_board_index'] ?? 1)) <=> ((int)($b['scene_board_index'] ?? 1));
        if ($batchCompare !== 0) {
            return $batchCompare;
        }
        $boardCompare = ((int)$a['board_order']) <=> ((int)$b['board_order']);
        if ($boardCompare !== 0) {
            return $boardCompare;
        }
        return (int)($b['row']['id'] ?? 0) <=> (int)($a['row']['id'] ?? 0);
    });
}
unset($resultGroup);
$resultGroups = array_values(array_filter($resultGroups, static fn (array $group): bool => !empty($group['items'])));

$evalPath = __DIR__ . '/analysis/mockup-combination-evaluations/' . $id . '.evaluations.json';
$evaluations = [];
if (is_file($evalPath)) {
    $decoded = json_decode((string)file_get_contents($evalPath), true);
    if (is_array($decoded['evaluations'] ?? null)) {
        $evaluations = $decoded['evaluations'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mockup Combination Results - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <?= AdminSceneEditor::styles() ?>
    <style>
        .results-group { margin-bottom: 26px; }
        .results-group-head {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 0 10px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }
        .results-group-head::after {
            content: "";
            height: 1px;
            flex: 1;
            background: var(--line);
        }
        .results-group-count {
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 3px 8px;
            background: var(--surface-soft);
            color: var(--muted);
            font-size: 9px;
            white-space: nowrap;
        }
        .results-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
        .batch-filter {
            display: inline-flex;
            align-items: center;
            gap: 0;
            margin: 0 0 14px;
            border: 1px solid var(--line);
            border-radius: 999px;
            overflow: hidden;
            background: var(--surface-soft);
        }
        .batch-filter button {
            min-height: 28px;
            border: 0;
            border-right: 1px solid var(--line);
            background: transparent;
            color: var(--muted);
            padding: 0 12px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            cursor: pointer;
        }
        .batch-filter button:last-child {
            border-right: 0;
        }
        .batch-filter button.active {
            background: var(--surface);
            color: var(--accent);
        }
        .next-batch-prompt {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            width: fit-content;
            margin: 0 0 20px;
            padding: 8px 10px 8px 12px;
            border: 1px solid rgba(244, 196, 204, .72);
            border-radius: 999px;
            background: rgba(244, 196, 204, .18);
            color: var(--muted);
            font-size: 11px;
            line-height: 1;
        }
        .next-batch-prompt strong {
            color: var(--ink);
            font-weight: 600;
        }
        .next-batch-prompt a {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 0 10px;
            border: 1px solid rgba(154, 123, 86, .32);
            border-radius: 999px;
            color: var(--accent);
            background: rgba(255, 255, 255, .66);
            text-decoration: none;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .07em;
            text-transform: uppercase;
        }
        .next-batch-prompt a:hover {
            border-color: var(--accent);
            background: var(--surface);
        }
        .result-card { background: var(--surface); border: 1px solid var(--line); border-left: 4px solid rgba(154, 123, 86, .28); border-radius: var(--radius); box-shadow: var(--shadow); padding: 18px; }
        .result-card.batch-1 { border-left-color: rgba(174, 136, 91, .42); }
        .result-card.batch-2 { border-left-color: rgba(225, 151, 166, .46); }
        .result-card.batch-3 { border-left-color: rgba(132, 154, 178, .46); }
        .result-image-wrap { position: relative; }
        .result-image-link { display: block; text-decoration: none; position: relative; z-index: 1; }
        .result-card > img { width: 100%; aspect-ratio: 4 / 3; height: auto; object-fit: cover; background: var(--surface-soft); border: 1px solid var(--line); display: block; }
        .result-image-link img { width: 100%; aspect-ratio: 4 / 3; height: auto; object-fit: cover; background: var(--surface-soft); border: 1px solid var(--line); display: block; }
        .favorite-overlay-btn {
            position: absolute;
            top: 8px;
            left: 8px;
            z-index: 4;
            pointer-events: auto;
            width: 32px !important;
            height: 32px !important;
            min-width: 32px !important;
            min-height: 32px !important;
            max-width: 32px !important;
            max-height: 32px !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 1px solid rgba(255, 255, 255, .34);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(18, 17, 15, .16);
            color: rgba(255, 255, 255, .68);
            font-size: 15px;
            line-height: 1;
            cursor: pointer;
            box-sizing: border-box;
            appearance: none;
            -webkit-appearance: none;
            opacity: .36;
            box-shadow: 0 6px 16px rgba(0, 0, 0, .12);
            backdrop-filter: blur(8px);
            transition: opacity .16s ease, background .16s ease, border-color .16s ease, color .16s ease, transform .16s ease;
        }
        .result-image-wrap:hover .favorite-overlay-btn,
        .result-image-wrap:focus-within .favorite-overlay-btn,
        .favorite-overlay-btn:hover,
        .favorite-overlay-btn:focus-visible,
        .favorite-overlay-btn.active {
            background: rgba(154, 123, 86, .72);
            border-color: rgba(255, 255, 255, .62);
            color: #fff;
            opacity: .94;
            outline: none;
        }
        .favorite-overlay-btn[disabled] {
            opacity: .55;
            cursor: wait;
        }
        .result-image-actions {
            position: absolute;
            top: 8px;
            right: 8px;
            z-index: 3;
            pointer-events: auto;
            display: flex;
            align-items: center;
            gap: 5px;
            height: 32px;
            opacity: .36;
            transform: translateY(0);
            transition: opacity .16s ease, transform .16s ease;
        }
        .result-image-wrap:hover .result-image-actions,
        .result-image-wrap:focus-within .result-image-actions {
            opacity: .94;
            transform: translateY(0);
        }
        .result-icon-action {
            width: 32px !important;
            height: 32px !important;
            min-width: 32px !important;
            min-height: 32px !important;
            max-width: 32px !important;
            max-height: 32px !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 1px solid rgba(255, 255, 255, .34);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 32px;
            background: rgba(18, 17, 15, .16);
            color: rgba(255, 255, 255, .72);
            font-size: 15px;
            line-height: 1;
            cursor: pointer;
            pointer-events: auto;
            box-sizing: border-box;
            appearance: none;
            -webkit-appearance: none;
            backdrop-filter: blur(6px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, .12);
            transition: background .16s ease, border-color .16s ease, color .16s ease;
        }
        .result-icon-action:hover,
        .result-icon-action:focus-visible {
            background: rgba(18, 17, 15, .62);
            border-color: rgba(255, 255, 255, .62);
            color: #fff;
            outline: none;
        }
        .result-icon-action.danger:hover,
        .result-icon-action.danger:focus-visible {
            background: rgba(124, 43, 35, .72);
        }
        .result-icon-action[disabled] {
            opacity: .55;
            cursor: wait;
        }
        .result-card h3 { margin: 12px 0 6px; font-family: var(--font-serif); font-size: 18px; }
        .result-variant-badge {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            min-height: 20px;
            margin: 10px 0 0;
            padding: 0 7px;
            border: 1px solid rgba(154, 123, 86, .28);
            border-radius: 3px;
            background: var(--surface-soft);
            color: var(--accent);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .result-variant-badge.batch-1 {
            background: rgba(174, 136, 91, .10);
        }
        .result-variant-badge.batch-2 {
            border-color: rgba(225, 151, 166, .38);
            background: rgba(244, 196, 204, .22);
        }
        .result-variant-badge.batch-3 {
            border-color: rgba(132, 154, 178, .34);
            background: rgba(132, 154, 178, .14);
        }
        .meta { color: var(--muted); font-size: 12px; line-height: 1.5; word-break: break-word; }
        .eval-form { display: grid; gap: 10px; margin-top: 14px; }
        .eval-form[hidden] { display: none !important; }
        .eval-form textarea,
        .eval-form label:nth-of-type(2) {
            display: none;
        }
        .eval-form select, .eval-form textarea { width: 100%; border: 1px solid var(--line); border-radius: var(--radius); padding: 10px; background: var(--surface-soft); color: var(--ink); }
        .eval-status { min-height: 18px; color: var(--muted); font-size: 12px; }
        .improve-panel {
            margin-top: 14px;
            border-top: 1px solid var(--line);
            padding-top: 14px;
            display: none;
            gap: 16px;
        }
        .improve-panel.active {
            display: grid;
        }
        .tweak-toggle-btn {
            background: transparent;
            border: 1px solid var(--line);
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            cursor: pointer;
            transition: all 0.2s ease;
            outline: none;
            padding: 0;
        }
        .tweak-toggle-btn:hover {
            color: var(--accent);
            border-color: var(--accent);
            background: var(--surface-soft);
        }
        .tweak-toggle-btn.active {
            color: var(--accent);
            border-color: var(--accent);
            background: var(--accent-light);
        }
        .tweak-toggle-btn.active .toggle-caret {
            transform: rotate(180deg);
        }
        .improve-panel label,
        .improve-label {
            display: grid;
            gap: 6px;
            color: var(--ink);
            font-size: 11px;
            letter-spacing: .06em;
            text-transform: uppercase;
        }
        .improve-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .improve-panel select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 6px 8px;
            background: var(--surface-soft);
            color: var(--ink);
            font-size: 12px;
            outline: none;
        }
        .human-toggle {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
        }
        .human-toggle input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .human-toggle span {
            min-height: 30px;
            border: 1px solid var(--line);
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--surface-soft);
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            padding: 0 4px;
            text-align: center;
        }
        .human-toggle input:checked + span {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }
        .improve-scale-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--ink);
        }
        .improve-scale-val-label {
            font-size: 10px;
            font-weight: 700;
            color: var(--accent);
        }
        .improve-scale-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 2px;
        }
        .improve-panel input[type="range"] {
            flex: 1;
            height: 6px;
            accent-color: var(--accent);
            cursor: pointer;
        }
        .improve-submit {
            margin-top: 2px;
            width: 100%;
            justify-content: center;
        }
        .camera-report {
            margin: 28px 0 24px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .camera-report-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--line);
        }
        .camera-report-head h2 {
            margin: 0;
            font-family: var(--font-serif);
            font-size: 24px;
            font-weight: 400;
        }
        .camera-report-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .camera-report-summary span,
        .camera-status {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 0 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .camera-status.generated,
        .camera-report-summary .generated {
            background: #e6fffa;
            color: #234e52;
            border: 1px solid rgba(35, 78, 82, .22);
        }
        .camera-status.failed,
        .camera-report-summary .failed {
            background: #fff5f3;
            color: #8a3429;
            border: 1px solid rgba(138, 52, 41, .22);
        }
        .camera-status.pending,
        .camera-report-summary .pending {
            background: #f5f2ec;
            color: var(--muted);
            border: 1px solid var(--line);
        }
        .camera-status.generating {
            background: #eef4ff;
            color: #315a8a;
            border: 1px solid rgba(49, 90, 138, .22);
        }
        .camera-report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .camera-report-table th,
        .camera-report-table td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
        }
        .camera-report-table tr:last-child td {
            border-bottom: 0;
        }
        .camera-report-table th {
            color: var(--muted);
            font-size: 10px;
            letter-spacing: .06em;
            text-transform: uppercase;
            background: var(--surface-soft);
        }
        .camera-report-table code {
            color: var(--muted);
            font-size: 11px;
        }
        @media (max-width: 980px) { .results-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <div class="alert-strip">Evaluate generated mockup combinations and keep the best candidates.</div>
        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Mockup Combination Results</h1>
                    <p>Generated images from the six-combination review flow.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link" href="mockup_combinations_review.php?id=<?= (int)$id ?>&board=<?= (int)$sceneBoardIndex ?><?= $selectedWorldMotherCategory !== '' ? '&world_mother_category=' . rawurlencode($selectedWorldMotherCategory) : '' ?>">Back to Combinations</a>
                    <a class="button-link secondary" href="artwork_details.php?id=<?= (int)$id ?>">Artwork Details</a>
                </div>
            </div>

            <?php if (!$rows): ?>
                <div class="notice">No generated combination images yet. Generate one from the combinations review screen.</div>
            <?php endif; ?>

            <?php if ($rows && $isAdmin && $nextSceneBoardHasScenes): ?>
                <div class="next-batch-prompt">
                    <strong>Would you like to generate a <?= $nextSceneBoardIndex === 2 ? 'second' : 'third' ?> mockup board?</strong>
                    <a href="mockup_combinations_review.php?id=<?= (int)$id ?>&board=<?= (int)$nextSceneBoardIndex ?><?= $selectedWorldMotherCategory !== '' ? '&world_mother_category=' . rawurlencode($selectedWorldMotherCategory) : '' ?>">Prepare Batch <?= (int)$nextSceneBoardIndex ?></a>
                </div>
            <?php endif; ?>

            <?php foreach ($resultGroups as $resultGroup): ?>
                <section class="results-group">
                    <div class="results-group-head">
                        <span><?= h((string)$resultGroup['group_name']) ?></span>
                        <span class="results-group-count"><?= count((array)$resultGroup['items']) ?> resultados</span>
                    </div>
                    <?php
                    $availableResultBoards = array_values(array_unique(array_map(
                        static fn (array $item): int => (int)($item['scene_board_index'] ?? 1),
                        (array)$resultGroup['items']
                    )));
                    sort($availableResultBoards);
                    ?>
                    <?php if (count($availableResultBoards) > 1): ?>
                        <div class="batch-filter" aria-label="Filter result batches">
                            <button type="button" class="active" data-batch-filter="all">All</button>
                            <?php foreach ($availableResultBoards as $availableResultBoard): ?>
                                <button type="button" data-batch-filter="<?= (int)$availableResultBoard ?>">Batch <?= (int)$availableResultBoard ?></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="results-grid">
                <?php foreach ((array)$resultGroup['items'] as $resultItem): ?>
                    <?php
                    $row = (array)$resultItem['row'];
                    $state = $row['selector_state'];
                    $combo = (array)($state['combination'] ?? []);
                    $mockupId = (int)$row['id'];
                    $existing = (array)($evaluations[(string)$mockupId] ?? []);
                    $variantLabel = trim((string)($resultItem['variant_label'] ?? ''));
                    $resultSceneBoardIndex = max(1, min(3, (int)($resultItem['scene_board_index'] ?? $combo['camera_slot_scene_board_index'] ?? 1)));
                    $rowCameraSlotsById = $cameraSlotsByBoard[$resultSceneBoardIndex] ?? [];
                    $cameraTitle = current_camera_slot_name(
                        (string)($combo['selected_camera_slot_id'] ?? ''),
                        (string)($combo['camera_slot_name'] ?? ''),
                        $rowCameraSlotsById
                    );
                    $sceneTitle = (string)($combo['world_mother_category'] ?? 'Scene');
                    ?>
                    <section class="result-card batch-<?= (int)$resultSceneBoardIndex ?>" id="result-card-<?= $mockupId ?>" data-result-batch="<?= (int)$resultSceneBoardIndex ?>">
                        <div class="result-image-wrap">
                            <a class="result-image-link" href="viewer.php?id=<?= $mockupId ?>&back=<?= rawurlencode('mockup_combination_results.php?id=' . (int)$id . ($selectedWorldMotherCategory !== '' ? '&world_mother_category=' . rawurlencode($selectedWorldMotherCategory) : '')) ?>" aria-label="Open in Mockup Album">
                                <img src="media.php?file=<?= rawurlencode(basename((string)$row['mockup_file'])) ?>" alt="">
                            </a>
                            <button
                                class="favorite-overlay-btn <?= isset($favoriteMockupLookup[$mockupId]) ? 'active' : '' ?>"
                                type="button"
                                title="<?= isset($favoriteMockupLookup[$mockupId]) ? 'Remove favorite' : 'Add favorite' ?>"
                                aria-label="<?= isset($favoriteMockupLookup[$mockupId]) ? 'Remove favorite' : 'Add favorite' ?>"
                                data-favorite-mockup
                                data-mockup-id="<?= $mockupId ?>"
                            >★</button>
                            <div class="result-image-actions" aria-label="Mockup actions">
                                <button
                                    class="result-icon-action"
                                    type="button"
                                    title="Redo mockup"
                                    aria-label="Redo mockup"
                                    data-redo-result
                                    data-artwork-id="<?= (int)$id ?>"
                                    data-combination-index="<?= (int)($combo['combination_index'] ?? 0) ?>"
                                    data-camera-slot="<?= h((string)($combo['selected_camera_slot_id'] ?? '')) ?>"
                                    data-scene-board="<?= (int)$resultSceneBoardIndex ?>"
                                    data-world-mother-category="<?= h((string)($combo['world_mother_category'] ?? $selectedWorldMotherCategory)) ?>"
                                    data-world-mother-variant="<?= (int)($combo['world_mother_variant_offset'] ?? 0) ?>"
                                >↻</button>
                                <button
                                    class="result-icon-action danger"
                                    type="button"
                                    title="Delete mockup"
                                    aria-label="Delete mockup"
                                    data-delete-result
                                    data-mockup-id="<?= $mockupId ?>"
                                >×</button>
                            </div>
                        </div>
                        <?php if ($variantLabel !== ''): ?>
                            <span class="result-variant-badge batch-<?= (int)$resultSceneBoardIndex ?>"><?= h($variantLabel) ?></span>
                        <?php endif; ?>
                        <div class="result-title-row" style="display: flex; justify-content: space-between; align-items: center; margin: 12px 0 6px;">
                            <h3 style="margin: 0;"><?= h($cameraTitle) ?></h3>
                            <button type="button" class="tweak-toggle-btn" onclick="toggleTweakPanel(this)" title="Toggle settings" aria-label="Toggle settings">
                                <svg class="toggle-caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 0.2s ease;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            </button>
                        </div>
                        <?= AdminSceneEditor::render($user, (string)($combo['selected_camera_slot_id'] ?? ''), 'mockup_combination_results.php?' . (string)($_SERVER['QUERY_STRING'] ?? ('id=' . (int)$id))) ?>
                        <div class="improve-panel" data-improve-panel id="improve-panel-<?= $mockupId ?>">
                            <input type="hidden" name="existing_mockup_id" value="<?= $mockupId ?>">
                            <input type="hidden" name="reference_mode" value="existing_only">
                            <input type="hidden" name="camera_strength" value="normal">

                            <!-- Artwork Scale -->
                            <div class="improve-label">
                                <div class="improve-scale-header">
                                    <span>Artwork scale</span>
                                    <span class="improve-scale-val-label" id="scale-val-label-<?= $mockupId ?>">Normal</span>
                                </div>
                                <div class="improve-scale-row">
                                    <input name="artwork_scale" type="range" min="-60" max="60" step="5" value="0" oninput="updateScaleLabel(this, '<?= $mockupId ?>')">
                                </div>
                            </div>

                            <!-- Human Presence -->
                            <div class="improve-label">
                                Human presence
                                <div class="human-toggle">
                                    <label><input type="radio" name="human_presence_<?= $mockupId ?>" value="none" checked><span>None</span></label>
                                    <label><input type="radio" name="human_presence_<?= $mockupId ?>" value="female_160"><span>Female</span></label>
                                    <label><input type="radio" name="human_presence_<?= $mockupId ?>" value="male_180"><span>Male</span></label>
                                </div>
                            </div>

                            <!-- Lighting & Camera -->
                            <div class="improve-row">
                                <label>
                                    Lighting
                                    <select name="lighting">
                                        <option value="">No change</option>
                                        <option value="gallery_spotlight">Gallery spotlight</option>
                                        <option value="soft_daylight">Soft daylight</option>
                                        <option value="golden_hour">Golden hour</option>
                                        <option value="moody_evening">Evening collector light</option>
                                        <option value="brighter_artwork">Brighter artwork</option>
                                    </select>
                                </label>
                                <label>
                                    Camera
                                    <select name="experimental_camera">
                                        <option value="">No change</option>
                                        <option value="closer_crop">Closer crop</option>
                                        <option value="wider_context">Wider context</option>
                                        <option value="lower_angle">Lower angle</option>
                                        <option value="higher_angle">Higher angle</option>
                                        <option value="stronger_oblique">Stronger oblique</option>
                                    </select>
                                </label>
                            </div>
                            <button class="button-link improve-submit" type="button" data-redo-result
                                data-artwork-id="<?= (int)$id ?>"
                                data-combination-index="<?= (int)($combo['combination_index'] ?? 0) ?>"
                                data-camera-slot="<?= h((string)($combo['selected_camera_slot_id'] ?? '')) ?>"
                                data-scene-board="<?= (int)$resultSceneBoardIndex ?>"
                                data-world-mother-category="<?= h((string)($combo['world_mother_category'] ?? $selectedWorldMotherCategory)) ?>"
                                data-world-mother-variant="<?= (int)($combo['world_mother_variant_offset'] ?? 0) ?>"
                                style="margin-top: 15px;"
                            >Create Variation</button>
                        </div>
                        <form class="eval-form" hidden onsubmit="saveEvaluation(event, this)">
                            <input type="hidden" name="artwork_id" value="<?= (int)$id ?>">
                            <input type="hidden" name="mockup_id" value="<?= $mockupId ?>">
                            <label>
                                Score
                                <select name="score">
                                    <?php for ($score = 5; $score >= 1; $score--): ?>
                                        <option value="<?= $score ?>" <?= (int)($existing['score'] ?? 0) === $score ? 'selected' : '' ?>><?= $score ?></option>
                                    <?php endfor; ?>
                                </select>
                            </label>
                            <label>
                                Evaluation notes
                                <textarea name="notes" rows="4" placeholder="Fidelity, scale, camera, world fit, commercial usefulness..."><?= h($existing['notes'] ?? '') ?></textarea>
                            </label>
                            <label style="display:inline-flex; gap:8px; align-items:center;">
                                <input type="checkbox" name="keeper" value="1" <?= !empty($existing['keeper']) ? 'checked' : '' ?>>
                                Keep as candidate
                            </label>
                            <div class="eval-status"></div>
                            <button class="button-link" type="submit">Save Evaluation</button>
                        </form>
                    </section>
                <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <section class="camera-report">
                <div class="camera-report-head">
                    <h2>Camera Generation Report</h2>
                    <div class="camera-report-summary" aria-label="Generation summary">
                        <span class="generated"><?= $generatedCount ?> generated</span>
                        <span class="failed"><?= $failedCount ?> failed</span>
                        <span class="pending"><?= $pendingCount ?> pending</span>
                    </div>
                </div>
                <?php if ($visibleCameraReport): ?>
                    <table class="camera-report-table">
                        <thead>
                            <tr>
                                <th>Set</th>
                                <th>Camera</th>
                                <th>Scene</th>
                                <th>Status</th>
                                <th>Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visibleCameraReport as $report): ?>
                                <tr>
                                    <td>Set <?= (int)$report['index'] ?></td>
                                    <td>
                                        <strong><?= h((string)$report['camera_name']) ?></strong><br>
                                        <code><?= h((string)$report['camera_slot_id']) ?></code>
                                    </td>
                                    <td><?= h((string)$report['world_mother_category']) ?></td>
                                    <td><span class="camera-status <?= h((string)$report['status']) ?>"><?= h((string)$report['status']) ?></span></td>
                                    <td>
                                        <?php if ($report['status'] === 'generated' && is_array($report['generated'])): ?>
                                            <a href="#result-card-<?= (int)$report['generated']['mockup_id'] ?>"><?= h((string)$report['detail']) ?></a>
                                        <?php else: ?>
                                            <?= h((string)$report['detail']) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="notice" style="margin: 18px 20px;">No generated or failed cameras yet.</div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
<script>
function saveEvaluation(event, form) {
    event.preventDefault();
    const status = form.querySelector('.eval-status');
    const button = form.querySelector('button');
    button.disabled = true;
    status.textContent = 'Saving...';
    fetch('save_mockup_combination_evaluation.php', { method: 'POST', body: new FormData(form) })
        .then(response => response.text().then(text => {
            let parsed;
            try { parsed = JSON.parse(text); } catch (err) { throw new Error(text.substring(0, 220)); }
            return { status: response.status, body: parsed };
        }))
        .then(result => {
            status.textContent = result.body.ok ? 'Saved.' : (result.body.error || 'Save failed.');
            button.disabled = false;
        })
        .catch(err => {
            status.textContent = 'Save failed: ' + err.message;
            button.disabled = false;
        });
}

function parseJsonResponse(response) {
    return response.text().then(text => {
        let parsed;
        try { parsed = JSON.parse(text); } catch (err) { throw new Error(text.substring(0, 220)); }
        return { status: response.status, body: parsed };
    });
}

function actionTarget(event) {
    return event.target && event.target.closest
        ? event.target
        : (event.target && event.target.parentElement ? event.target.parentElement : null);
}

function deleteResult(button) {
    if (!confirm('Delete this mockup?')) {
        return;
    }
    const card = button.closest('.result-card');
    const formData = new FormData();
    formData.append('mockup_id', button.getAttribute('data-mockup-id') || '');
    button.disabled = true;

    fetch('delete_mockup_result.php', { method: 'POST', body: formData })
        .then(parseJsonResponse)
        .then(result => {
            if (!result.body.ok) {
                throw new Error(result.body.error || 'Delete failed.');
            }
            const deletedId = parseInt(result.body.deleted_mockup_id || button.getAttribute('data-mockup-id') || '0', 10);
            const deletedCard = deletedId > 0 ? document.getElementById('result-card-' + deletedId) : null;
            if (deletedCard) {
                deletedCard.remove();
            } else if (card) {
                card.remove();
            }
        })
        .catch(err => {
            alert(err.message);
            button.disabled = false;
        });
}

function redoResult(button) {
    if (!confirm('Regenerate this mockup with these controls?')) {
        return;
    }
    const panel = button.closest('[data-improve-panel]');
    const formData = new FormData();
    formData.append('artwork_id', button.getAttribute('data-artwork-id') || '');
    formData.append('combination_index', button.getAttribute('data-combination-index') || '');
    formData.append('camera_slot_id', button.getAttribute('data-camera-slot') || '');
    formData.append('board', button.getAttribute('data-scene-board') || '1');
    formData.append('world_mother_category', button.getAttribute('data-world-mother-category') || '');
    formData.append('world_mother_variant_offset', button.getAttribute('data-world-mother-variant') || '0');
    formData.append('world_mother_scale', '1.0');
    if (panel) {
        const human = panel.querySelector('input[name^="human_presence_"]:checked');
        const lighting = panel.querySelector('select[name="lighting"]');
        const experimentalCamera = panel.querySelector('select[name="experimental_camera"]');

        formData.append('existing_mockup_id', panel.querySelector('[name="existing_mockup_id"]')?.value || '');
        formData.append('reference_mode', panel.querySelector('[name="reference_mode"]')?.value || '');
        formData.append('human_presence', human ? human.value : 'none');
        formData.append('artwork_scale', panel.querySelector('[name="artwork_scale"]')?.value || '0');
        formData.append('lighting', lighting?.value || '');
        formData.append('experimental_camera', experimentalCamera?.value || '');
        formData.append('camera_strength', panel.querySelector('[name="camera_strength"]')?.value || 'normal');
    }
    button.disabled = true;

    fetch('generate_mockup_combination.php', { method: 'POST', body: formData })
        .then(parseJsonResponse)
        .then(result => {
            if (!result.body.ok) {
                throw new Error(result.body.error || 'Regeneration failed.');
            }
            window.location.href = result.body.results_url || window.location.href;
        })
        .catch(err => {
            alert(err.message);
            button.disabled = false;
        });
}

document.addEventListener('click', event => {
    const target = actionTarget(event);
    if (!target) {
        return;
    }

    const batchFilterButton = target.closest('[data-batch-filter]');
    if (batchFilterButton) {
        event.preventDefault();
        const filterValue = batchFilterButton.getAttribute('data-batch-filter') || 'all';
        document.querySelectorAll('[data-batch-filter]').forEach(button => {
            button.classList.toggle('active', button === batchFilterButton);
        });
        document.querySelectorAll('[data-result-batch]').forEach(card => {
            const show = filterValue === 'all' || card.getAttribute('data-result-batch') === filterValue;
            card.hidden = !show;
        });
        return;
    }

    const favoriteButton = target.closest('[data-favorite-mockup]');
    if (favoriteButton) {
        event.preventDefault();
        event.stopPropagation();
        const formData = new FormData();
        formData.append('mockup_id', favoriteButton.getAttribute('data-mockup-id') || '');
        favoriteButton.disabled = true;
        fetch('toggle_mockup_favorite.php', { method: 'POST', body: formData })
            .then(parseJsonResponse)
            .then(result => {
                if (!result.body.ok) {
                    throw new Error(result.body.error || 'Could not update favorite.');
                }
                favoriteButton.classList.toggle('active', !!result.body.favorite);
                favoriteButton.title = result.body.favorite ? 'Remove favorite' : 'Add favorite';
                favoriteButton.setAttribute('aria-label', favoriteButton.title);
            })
            .catch(err => {
                alert(err.message);
            })
            .finally(() => {
                favoriteButton.disabled = false;
            });
        return;
    }

    const deleteButton = target.closest('[data-delete-result]');
    if (deleteButton) {
        event.preventDefault();
        event.stopPropagation();
        deleteResult(deleteButton);
        return;
    }

    const redoButton = target.closest('[data-redo-result]');
    if (redoButton) {
        event.preventDefault();
        event.stopPropagation();
        redoResult(redoButton);
    }
});

function toggleTweakPanel(btn) {
    const panels = document.querySelectorAll('[data-improve-panel]');
    const buttons = document.querySelectorAll('.tweak-toggle-btn');
    const isActive = !btn.classList.contains('active');

    panels.forEach(p => p.classList.toggle('active', isActive));
    buttons.forEach(b => b.classList.toggle('active', isActive));
}

function updateScaleLabel(range, id) {
    const label = document.getElementById('scale-val-label-' + id);
    if (!label) return;
    const val = parseInt(range.value, 10);
    if (val === 0) {
        label.textContent = 'Normal';
    } else {
        label.textContent = (val > 0 ? '+' : '') + val + '%';
    }
}

</script>
</body>
</html>
