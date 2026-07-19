<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Esta vista contiene recursos privados y puede quedar inconsistente si la obra
// se elimina desde otra pestaña. Evita restaurar desde caché tarjetas cuyos
// archivos ya no están autorizados.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$pdo = Database::connection();
AdminSceneEditor::handlePost($user);
$id = max(0, (int)($_GET['id'] ?? 0));
$selectedWorldMotherCategory = trim(str_replace(['\\', '/'], '', (string)($_GET['world_mother_category'] ?? '')));
$sceneBoardIndex = max(1, min(3, (int)($_GET['board'] ?? 1)));
$requestedGenerationProvider = strtolower(trim((string)($_GET['generation_provider'] ?? '')));
$generationProviderFilter = in_array($requestedGenerationProvider, ['gemini', 'openai'], true)
    ? $requestedGenerationProvider
    : '';
$generationProviderQuery = $generationProviderFilter !== ''
    ? '&generation_provider=' . rawurlencode($generationProviderFilter)
    : '';
$compactSceneFlow = !empty($_GET['compact']);
$compactSceneLimit = max(1, min(4, (int)($_GET['scene_limit'] ?? 4)));
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

$requestedGenerationRunId = strtolower(trim((string)($_GET['generation_run'] ?? '')));
if ($requestedGenerationRunId !== '' && !preg_match('/^[a-z0-9-]{8,64}$/', $requestedGenerationRunId)) {
    $requestedGenerationRunId = '';
}
$highlightGenerationRunId = '';
$highlightJobId = max(0, (int)($_GET['highlight_job'] ?? 0));
$highlightMockupId = 0;
$highlightLabel = 'New result';
if ($highlightJobId > 0) {
    $highlightStmt = $pdo->prepare('
        SELECT mockup_id, selector_state_json
        FROM mockup_generation_jobs
        WHERE id = :id AND user_id = :user_id AND artwork_id = :artwork_id AND status = "done"
        LIMIT 1
    ');
    $highlightStmt->execute([
        'id' => $highlightJobId,
        'user_id' => (int)$user['id'],
        'artwork_id' => $id,
    ]);
    $highlightJob = $highlightStmt->fetch();
    if ($highlightJob) {
        $highlightMockupId = max(0, (int)($highlightJob['mockup_id'] ?? 0));
        $highlightState = json_decode((string)($highlightJob['selector_state_json'] ?? ''), true);
        $highlightGenerationRunId = is_array($highlightState)
            ? strtolower(trim((string)($highlightState['generation_run_id'] ?? '')))
            : '';
        if ($highlightGenerationRunId !== '' && !preg_match('/^[a-z0-9-]{8,64}$/', $highlightGenerationRunId)) {
            $highlightGenerationRunId = '';
        }
        $highlightCombination = is_array($highlightState) ? (array)($highlightState['combination'] ?? []) : [];
        $highlightControls = (array)($highlightCombination['improvement_controls'] ?? []);
        if ((int)($highlightControls['existing_mockup_id'] ?? 0) > 0) {
            $highlightLabel = 'Recently regenerated';
        }
    }
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

    return 'world_mother_media.php?file=' . rawurlencode($file) . '&thumb=1&w=640';
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
        OR source_artwork_id = :source_artwork_id
        OR selector_state_json LIKE :audit_path
    )
    ORDER BY id DESC
');
$stmt->execute([
    'user_id' => (int)$artwork['user_id'],
    'artwork_file' => basename((string)$artwork['root_file']),
    'source_artwork_id' => (int)$id,
    'audit_path' => '%analysis/mockup-combination-audit/' . (int)$id . '/%',
]);
$rows = [];
foreach ($stmt->fetchAll() ?: [] as $row) {
    $state = json_decode((string)($row['selector_state_json'] ?? ''), true);
    if (!is_array($state) || ($state['generation_source'] ?? '') !== 'mockup_combination_review') {
        continue;
    }
    $rowGenerationProvider = strtolower(trim((string)($state['generation_provider'] ?? 'gemini')));
    $rowGenerationProvider = in_array($rowGenerationProvider, ['gemini', 'openai'], true) ? $rowGenerationProvider : 'gemini';
    if ($generationProviderFilter !== '' && $rowGenerationProvider !== $generationProviderFilter) {
        continue;
    }
    $state['generation_provider'] = $rowGenerationProvider;
    $combo = (array)($state['combination'] ?? []);
    $rowSceneBoardIndex = max(1, min(3, (int)($combo['camera_slot_scene_board_index'] ?? 1)));
    $combo['camera_slot_scene_board_index'] = $rowSceneBoardIndex;
    $state['combination'] = $combo;
    $row['selector_state'] = $state;
    $rows[] = $row;
}

$rowIds = [];
$rowFiles = [];
foreach ($rows as $row) {
    $rowIds[(int)$row['id']] = true;
    $rowFile = basename((string)($row['mockup_file'] ?? ''));
    if ($rowFile !== '') {
        $rowFiles[$rowFile] = true;
    }
}

$jobStmt = $pdo->prepare('
    SELECT *
    FROM mockup_generation_jobs
    WHERE user_id = :user_id
    AND status = "done"
    AND mockup_file IS NOT NULL
    AND mockup_file <> ""
    AND (
        artwork_id = :artwork_id
        OR source_artwork_id = :source_artwork_id
        OR artwork_file = :artwork_file
    )
    ORDER BY id DESC
');
$jobStmt->execute([
    'user_id' => (int)$artwork['user_id'],
    'artwork_id' => (int)$id,
    'source_artwork_id' => (int)$id,
    'artwork_file' => basename((string)$artwork['root_file']),
]);
foreach ($jobStmt->fetchAll() ?: [] as $jobRow) {
    $mockupId = (int)($jobRow['mockup_id'] ?? 0);
    if ($mockupId > 0 && isset($rowIds[$mockupId])) {
        continue;
    }
    $mockupFile = basename((string)($jobRow['mockup_file'] ?? ''));
    if ($mockupFile === '' || isset($rowFiles[$mockupFile])) {
        continue;
    }

    $state = json_decode((string)($jobRow['selector_state_json'] ?? ''), true);
    if (!is_array($state) || ($state['generation_source'] ?? '') !== 'mockup_combination_review') {
        continue;
    }
    $rowGenerationProvider = strtolower(trim((string)($state['generation_provider'] ?? 'gemini')));
    $rowGenerationProvider = in_array($rowGenerationProvider, ['gemini', 'openai'], true) ? $rowGenerationProvider : 'gemini';
    if ($generationProviderFilter !== '' && $rowGenerationProvider !== $generationProviderFilter) {
        continue;
    }
    $state['generation_provider'] = $rowGenerationProvider;

    $combo = (array)($state['combination'] ?? []);
    $rowSceneBoardIndex = max(1, min(3, (int)($combo['camera_slot_scene_board_index'] ?? $state['scene_board_index'] ?? 1)));
    $combo['camera_slot_scene_board_index'] = $rowSceneBoardIndex;
    $state['combination'] = $combo;

    $fallbackRow = [
        'id' => $mockupId > 0 ? $mockupId : (int)$jobRow['id'],
        'user_id' => (int)$jobRow['user_id'],
        'artwork_file' => basename((string)$jobRow['artwork_file']),
        'mockup_file' => $mockupFile,
        'context_id' => (string)$jobRow['context_id'],
        'prompt_file' => basename((string)($jobRow['prompt_file'] ?? '')),
        'selector_state_json' => (string)($jobRow['selector_state_json'] ?? ''),
        'created_at' => (string)($jobRow['updated_at'] ?? ''),
        'selector_state' => $state,
    ];

    $rows[] = $fallbackRow;
    $rowIds[(int)$fallbackRow['id']] = true;
    $rowFiles[$mockupFile] = true;
}
$favoriteMockupLookup = MockupFavorites::lookupForUser((int)$user['id']);
$generationRuns = [];
foreach ($rows as $row) {
    $rowState = (array)($row['selector_state'] ?? []);
    $generationRunId = strtolower(trim((string)($rowState['generation_run_id'] ?? '')));
    if ($generationRunId === '' || !preg_match('/^[a-z0-9-]{8,64}$/', $generationRunId)) {
        continue;
    }
    $createdSort = strtotime((string)($row['created_at'] ?? '')) ?: 0;
    $rowSort = max($createdSort, (int)($row['id'] ?? 0));
    if (!isset($generationRuns[$generationRunId])) {
        $generationRuns[$generationRunId] = ['count' => 0, 'sort' => 0];
    }
    $generationRuns[$generationRunId]['count']++;
    $generationRuns[$generationRunId]['sort'] = max((int)$generationRuns[$generationRunId]['sort'], $rowSort);
}
$activeGenerationRunId = $requestedGenerationRunId !== ''
    ? $requestedGenerationRunId
    : $highlightGenerationRunId;
if ($activeGenerationRunId === '' || !isset($generationRuns[$activeGenerationRunId])) {
    $activeGenerationRunId = '';
    foreach ($generationRuns as $generationRunId => $generationRunMeta) {
        if (
            $activeGenerationRunId === ''
            || (int)$generationRunMeta['sort'] > (int)$generationRuns[$activeGenerationRunId]['sort']
        ) {
            $activeGenerationRunId = $generationRunId;
        }
    }
}
$activeGenerationCount = $activeGenerationRunId !== ''
    ? (int)($generationRuns[$activeGenerationRunId]['count'] ?? 0)
    : 0;
$generatedBoardIndexes = [];
foreach ($rows as $row) {
    $rowState = (array)($row['selector_state'] ?? []);
    $combo = (array)($rowState['combination'] ?? []);
    $rowWorldMotherCategory = trim((string)($combo['world_mother_category'] ?? $rowState['world_mother_category'] ?? ''));
    if ($selectedWorldMotherCategory !== '' && strcasecmp($rowWorldMotherCategory, $selectedWorldMotherCategory) !== 0) {
        continue;
    }
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
$reviewSceneBoardIndex = $nextSceneBoardHasScenes ? $nextSceneBoardIndex : 1;

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
    $auditGenerationProvider = strtolower(trim((string)($decoded['generation_provider'] ?? 'gemini')));
    $auditGenerationProvider = in_array($auditGenerationProvider, ['gemini', 'openai'], true) ? $auditGenerationProvider : 'gemini';
    if ($generationProviderFilter !== '' && $auditGenerationProvider !== $generationProviderFilter) {
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
    'group_name' => 'Scene Boards Results' . ($generationProviderFilter !== '' ? ' · ' . ($generationProviderFilter === 'openai' ? 'OpenAI' : 'Vertex') : ''),
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
        'variant_label' => 'Set ' . $rowSceneBoardIndex . ($boardOrder > 0 ? ' · View ' . $boardOrder : ''),
        'scene_board_index' => $rowSceneBoardIndex,
        'board_order' => $boardOrder > 0 ? $boardOrder : 999,
        'generation_run_id' => strtolower(trim((string)(($row['selector_state'] ?? [])['generation_run_id'] ?? ''))),
    ];
}
foreach ($resultGroups as &$resultGroup) {
    usort($resultGroup['items'], static function (array $a, array $b) use ($generationRuns): int {
        $aRunId = (string)($a['generation_run_id'] ?? '');
        $bRunId = (string)($b['generation_run_id'] ?? '');
        $aRow = (array)($a['row'] ?? []);
        $bRow = (array)($b['row'] ?? []);
        $aSort = $aRunId !== '' && isset($generationRuns[$aRunId])
            ? (int)$generationRuns[$aRunId]['sort']
            : max(strtotime((string)($aRow['created_at'] ?? '')) ?: 0, (int)($aRow['id'] ?? 0));
        $bSort = $bRunId !== '' && isset($generationRuns[$bRunId])
            ? (int)$generationRuns[$bRunId]['sort']
            : max(strtotime((string)($bRow['created_at'] ?? '')) ?: 0, (int)($bRow['id'] ?? 0));
        $generationCompare = $bSort <=> $aSort;
        if ($generationCompare !== 0) {
            return $generationCompare;
        }
        if ($aRunId !== '' && $aRunId === $bRunId) {
            $boardCompare = ((int)$a['board_order']) <=> ((int)$b['board_order']);
            if ($boardCompare !== 0) {
                return $boardCompare;
            }
        }
        return (int)($bRow['id'] ?? 0) <=> (int)($aRow['id'] ?? 0);
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
    <title>Mockup Combination Results - Artwork Mockups</title>
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
            flex: 0 0 auto;
            width: auto;
            min-height: 36px;
            margin: 0;
            border: 0;
            border-right: 1px solid var(--line);
            border-radius: 0;
            background: transparent;
            color: var(--muted);
            padding: 0 16px;
            font-size: 10px;
            font-weight: 800;
            line-height: 1;
            letter-spacing: .06em;
            white-space: nowrap;
            text-transform: uppercase;
            cursor: pointer;
            box-shadow: none;
        }
        .batch-filter button:hover,
        .batch-filter button:focus-visible {
            transform: none;
            box-shadow: none;
        }
        .batch-filter button:last-child {
            border-right: 0;
        }
        .batch-filter button.active {
            background: var(--surface);
            color: var(--accent);
        }
        .results-header-v3 {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 36px;
            padding: 6px 0 24px;
            margin-bottom: 26px;
            border-bottom: 1px solid var(--line);
        }
        .results-header-v3 .header-main-info {
            display: block;
            flex: 1;
            min-width: 0;
        }
        .results-header-v3 h1 {
            margin: 0 0 18px;
            font-size: 44px;
            line-height: 1;
            font-family: var(--font-serif);
            font-weight: 500;
            color: var(--ink);
        }
        .results-page-desc {
            margin: 0;
            line-height: 1.55;
        }
        .results-page-desc .desc-kicker {
            display: block;
            font-size: 14px;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .results-page-desc .desc-instructions {
            display: block;
            max-width: 900px;
            font-size: 16px;
            font-weight: 600;
            color: var(--accent);
        }
        .next-batch-prompt {
            display: grid;
            grid-template-columns: repeat(2, 150px);
            align-items: end;
            justify-content: flex-end;
            gap: 12px;
            margin: 0;
            color: var(--accent);
        }
        .next-batch-prompt.is-explore-only {
            grid-template-columns: 150px;
        }
        .next-batch-primary-group {
            display: grid;
            gap: 9px;
            width: 150px;
        }
        .next-batch-scene-name {
            width: 150px;
            overflow: hidden;
            color: #a3a09b;
            font-size: 11px;
            font-weight: 400;
            line-height: 1.25;
            letter-spacing: .025em;
            text-align: center;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .next-batch-prompt a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            text-align: center;
        }
        .next-batch-prompt .next-batch-primary {
            width: 150px;
            min-width: 150px;
            height: 150px;
            min-height: 150px;
            padding: 20px;
            border: 1px solid #b77f86;
            border-radius: 4px;
            color: #fffaf7;
            background: #b77f86;
            font-size: 13px;
            line-height: 1.32;
            box-shadow: 0 8px 22px rgba(183, 127, 134, .18);
        }
        .next-batch-prompt .next-batch-primary:hover {
            border-color: #a86f77;
            background: #a86f77;
            color: #fffaf7;
        }
        .next-batch-prompt .next-batch-secondary {
            width: 150px;
            min-width: 150px;
            height: 150px;
            min-height: 150px;
            padding: 20px;
            border: 1px solid #d2c37f;
            border-radius: 4px;
            background: #efe5b8;
            color: #66592f;
            font-size: 13px;
            line-height: 1.32;
            box-shadow: 0 8px 22px rgba(178, 156, 70, .16);
        }
        .next-batch-prompt .next-batch-secondary:hover {
            border-color: #c5b363;
            background: #e7d99d;
            color: #55491f;
        }
        .result-card {
            position: relative;
            padding: 10px;
            border: 1px solid rgba(213, 208, 199, .72);
            border-radius: 6px;
            background: var(--surface);
            box-shadow: 0 2px 8px rgba(50, 45, 38, .055);
        }
        .result-card.batch-1 { border-left-color: rgba(174, 136, 91, .42); }
        .result-card.batch-2 { border-left-color: rgba(225, 151, 166, .46); }
        .result-card.batch-3 { border-left-color: rgba(132, 154, 178, .46); }
        .result-card.is-new-generation {
            border-color: #91aa8a;
            border-left-color: #6f8b68;
            background: #fbfdf9;
            box-shadow: 0 0 0 3px rgba(111, 139, 104, .17), var(--shadow);
        }
        .new-generation-badge {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            margin-top: 10px;
            padding: 5px 9px;
            border: 1px solid #b9ccb5;
            border-radius: 999px;
            background: #e4eee1;
            color: #486245;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
        }
        .result-image-wrap {
            position: relative;
            overflow: hidden;
            border: 0;
            border-radius: 3px;
            background: transparent;
            box-shadow: none;
        }
        .result-image-toolbar {
            position: absolute;
            top: 8px;
            right: 8px;
            left: 8px;
            z-index: 3;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            border: 0;
            background: transparent;
            box-shadow: none;
            pointer-events: none;
        }
        .result-image-link { display: block; text-decoration: none; position: relative; z-index: 1; }
        .result-card > img { width: 100%; aspect-ratio: 4 / 3; height: auto; object-fit: cover; background: var(--surface-soft); border: 1px solid var(--line); display: block; }
        .result-image-link img { width: 100%; aspect-ratio: 4 / 3; height: auto; object-fit: cover; background: var(--surface-soft); border: 0; border-radius: 3px; display: block; }
        .favorite-overlay-btn,
        .result-icon-action {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            width: 34px !important;
            height: 34px !important;
            min-width: 34px !important;
            min-height: 34px !important;
            max-width: 34px !important;
            max-height: 34px !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 1px solid rgba(255, 255, 255, .43);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 34px;
            background:
                radial-gradient(circle at 31% 20%, rgba(255, 255, 255, .39) 0 8%, rgba(255, 255, 255, .09) 35%, rgba(255, 255, 255, 0) 61%),
                linear-gradient(145deg, rgba(255, 255, 255, .17), rgba(186, 198, 199, .07)),
                rgba(226, 233, 233, .055);
            color: rgba(47, 55, 55, .76);
            cursor: pointer;
            pointer-events: auto;
            box-sizing: border-box;
            appearance: none;
            -webkit-appearance: none;
            backdrop-filter: blur(9px) saturate(1.18);
            -webkit-backdrop-filter: blur(9px) saturate(1.18);
            box-shadow:
                inset 1px 1px 1px rgba(255, 255, 255, .57),
                inset -2px -3px 5px rgba(60, 69, 70, .16),
                0 4px 10px rgba(24, 30, 30, .2),
                0 0 0 1px rgba(73, 83, 83, .11);
            transition: background .16s ease, border-color .16s ease, color .16s ease, box-shadow .16s ease, transform .16s ease;
        }
        .favorite-overlay-btn::before,
        .result-icon-action::before {
            content: '';
            position: absolute;
            z-index: -1;
            top: 2px;
            left: 5px;
            right: 5px;
            height: 44%;
            border-radius: 999px;
            background: radial-gradient(ellipse at 36% 10%, rgba(255,255,255,.5), rgba(255,255,255,.125) 35%, rgba(255,255,255,0) 72%);
            pointer-events: none;
        }
        .result-action-icon {
            width: 16px;
            height: 16px;
            display: block;
            overflow: visible;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.65;
            stroke-linecap: round;
            stroke-linejoin: round;
            pointer-events: none;
        }
        .favorite-overlay-btn:hover,
        .favorite-overlay-btn:focus-visible,
        .result-icon-action:hover,
        .result-icon-action:focus-visible {
            border-color: rgba(255, 255, 255, .7);
            background:
                radial-gradient(circle at 31% 20%, rgba(255,255,255,.5), rgba(255,255,255,.125) 38%, rgba(255,255,255,0) 64%),
                linear-gradient(145deg, rgba(255,255,255,.27), rgba(193,202,203,.14)),
                rgba(225,230,229,.1);
            color: #3f4747;
            box-shadow:
                inset 0 1px 0 #fff,
                inset 0 -3px 7px rgba(72,82,83,.15),
                0 7px 15px rgba(54,61,60,.2),
                0 0 0 1px rgba(104,115,114,.2);
            transform: translateY(-1.5px);
            outline: none;
        }
        .favorite-overlay-btn:active,
        .result-icon-action:active {
            transform: translateY(1px);
            box-shadow:
                inset 0 3px 7px rgba(77, 87, 88, .18),
                inset 0 -1px 0 rgba(255,255,255,.82),
                0 2px 5px rgba(64,70,69,.12);
        }
        .favorite-overlay-btn.active {
            border-color: rgba(255, 250, 218, .96);
            background:
                linear-gradient(145deg, rgba(255,251,223,.9), rgba(218,198,119,.62)),
                rgba(239,226,172,.76);
            color: #806b24;
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,.98),
                inset 0 -3px 7px rgba(129,108,37,.13),
                0 5px 12px rgba(111,94,39,.17),
                0 0 0 1px rgba(157,133,51,.17);
        }
        .favorite-overlay-btn.active .result-action-icon {
            fill: currentColor;
        }
        .favorite-overlay-btn[disabled] {
            opacity: .55;
            cursor: wait;
        }
        .result-image-actions {
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .result-icon-action.danger:hover,
        .result-icon-action.danger:focus-visible {
            border-color: rgba(255, 238, 234, .98);
            background:
                linear-gradient(145deg, rgba(255,247,245,.92), rgba(223,188,181,.62)),
                rgba(239,216,211,.76);
            color: #8a443a;
        }
        .result-icon-action[disabled] {
            opacity: .55;
            cursor: wait;
        }
        .result-icon-action.is-regenerating[disabled] {
            opacity: 1;
            cursor: progress;
        }
        .result-icon-action.is-regenerating .result-action-icon {
            animation: result-action-spin 1.15s linear infinite;
        }
        @keyframes result-action-spin {
            to { transform: rotate(360deg); }
        }
        .result-card h3 { margin: 12px 0 6px; font-family: var(--font-serif); font-size: 18px; }
        .result-scene-badge {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            min-height: 20px;
            margin: 10px 5px 0 0;
            padding: 3px 7px;
            border: 1px solid rgba(117, 139, 105, .28);
            border-radius: 3px;
            background: rgba(210, 223, 199, .28);
            color: #5f7058;
            font-size: 9px;
            font-weight: 800;
            line-height: 1.3;
            letter-spacing: .05em;
            overflow-wrap: anywhere;
            text-transform: uppercase;
        }
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
        @media (max-width: 980px) {
            .results-header-v3 {
                flex-direction: column;
                align-items: stretch;
                gap: 16px;
            }
            .results-grid { grid-template-columns: 1fr; }
            .next-batch-prompt {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                width: 100%;
                margin-top: 18px;
            }
            .next-batch-prompt.is-explore-only {
                grid-template-columns: 1fr;
            }
            .next-batch-primary-group,
            .next-batch-scene-name,
            .next-batch-prompt .next-batch-primary,
            .next-batch-prompt .next-batch-secondary {
                width: 100%;
                min-width: 0;
            }
            .next-batch-prompt .next-batch-primary,
            .next-batch-prompt .next-batch-secondary {
                height: 56px;
                min-height: 56px;
            }
        }
        @media (max-width: 760px) {
            .app-header {
                display: none;
            }
            .workspace {
                padding-left: 10px;
                padding-right: 10px;
            }
            .alert-strip,
            .results-page-desc .desc-instructions,
            .results-group-head,
            .batch-filter,
            .result-variant-badge,
            .result-title-row,
            .camera-report {
                display: none !important;
            }
            .results-header-v3 {
                gap: 10px;
                padding: 2px 0 14px;
                margin-bottom: 14px;
            }
            .results-header-v3 h1 {
                margin-bottom: 8px;
                font-size: 31px;
                line-height: 1.04;
            }
            .results-page-desc .desc-kicker {
                font-size: 12px;
                line-height: 1.45;
                margin-bottom: 0;
            }
            .results-group {
                margin-bottom: 14px;
            }
            .results-grid {
                position: relative;
                display: grid;
                grid-template-columns: none;
                grid-auto-flow: column;
                grid-auto-columns: calc(100% - 18px);
                gap: 10px;
                overflow-x: auto;
                overflow-y: hidden;
                scroll-snap-type: x mandatory;
                scroll-padding-inline: 0;
                overscroll-behavior-x: contain;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                padding-bottom: 2px;
            }
            .results-grid::-webkit-scrollbar {
                display: none;
            }
            .result-card {
                display: block;
                scroll-snap-align: start;
                scroll-snap-stop: always;
                padding: 10px;
                border-left-width: 0;
                border-radius: 6px;
                user-select: none;
                -webkit-user-drag: none;
            }
            .result-card.active {
                box-shadow: inset 0 0 0 2px #b77f86, var(--shadow);
            }
            .result-image-link img {
                aspect-ratio: 3 / 4;
                border-radius: 4px;
                pointer-events: none;
            }
            .result-image-actions {
                opacity: 1;
                transform: none;
            }
            .next-batch-prompt {
                margin-top: 8px;
            }
            .next-batch-prompt {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <link rel="stylesheet" href="media-controls.css?v=2">
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
            <div class="results-header-v3">
                <div class="header-main-info">
                    <h1>Scene Mockups</h1>
                    <p class="results-page-desc">
                        <span class="desc-kicker">Evaluate generated mockup combinations and keep the best candidates.</span>
                        <span class="desc-instructions">Regenerate individual mockups, delete weak results, or mark your best options as favorites. Use the controls on each image card to refine the board.</span>
                    </p>
                </div>
                <?php if ($rows): ?>
                    <div class="next-batch-prompt<?= $nextSceneBoardHasScenes ? '' : ' is-explore-only' ?>">
                        <?php if ($nextSceneBoardHasScenes): ?>
                            <div class="next-batch-primary-group">
                                <?php if ($selectedWorldMotherCategory !== ''): ?>
                                    <span class="next-batch-scene-name" title="<?= h($selectedWorldMotherCategory) ?>"><?= h($selectedWorldMotherCategory) ?></span>
                                <?php endif; ?>
                                <a class="next-batch-primary" data-compact-scene-launch href="mockup_combinations_review.php?id=<?= (int)$id ?>&board=<?= (int)$nextSceneBoardIndex ?><?= $generationProviderQuery ?><?= $selectedWorldMotherCategory !== '' ? '&world_mother_category=' . rawurlencode($selectedWorldMotherCategory) : '' ?>&auto_generate=1&compact=1&scene_limit=<?= (int)$compactSceneLimit ?>">Create 4 more views</a>
                            </div>
                            <a class="next-batch-secondary" href="mockup_combinations_review.php?id=<?= (int)$id ?>&board=<?= (int)$reviewSceneBoardIndex ?><?= $generationProviderQuery ?><?= $selectedWorldMotherCategory !== '' ? '&world_mother_category=' . rawurlencode($selectedWorldMotherCategory) : '' ?>">Explore new scenes</a>
                        <?php else: ?>
                            <a class="next-batch-primary" href="mockup_combinations_review.php?id=<?= (int)$id ?>&board=<?= (int)$reviewSceneBoardIndex ?><?= $generationProviderQuery ?><?= $selectedWorldMotherCategory !== '' ? '&world_mother_category=' . rawurlencode($selectedWorldMotherCategory) : '' ?>">Explore new scenes</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$rows): ?>
                <div class="notice">No generated combination images yet. Generate one from the combinations review screen.</div>
            <?php endif; ?>

            <?php foreach ($resultGroups as $resultGroup): ?>
                <section class="results-group">
                    <div class="results-group-head">
                        <span><?= h((string)$resultGroup['group_name']) ?></span>
                        <span class="results-group-count"><?= count((array)$resultGroup['items']) ?> results</span>
                    </div>
                    <?php if ($activeGenerationCount > 0): ?>
                        <div class="batch-filter" aria-label="Filter result sets">
                            <button type="button" class="active" data-result-filter="run:<?= h($activeGenerationRunId) ?>">Latest generation (<?= (int)$activeGenerationCount ?>)</button>
                            <button type="button" data-result-filter="all">All (<?= count((array)$resultGroup['items']) ?>)</button>
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
                    $resultGenerationRunId = strtolower(trim((string)($resultItem['generation_run_id'] ?? '')));
                    $resultSceneBoardIndex = max(1, min(3, (int)($resultItem['scene_board_index'] ?? $combo['camera_slot_scene_board_index'] ?? 1)));
                    $rowCameraSlotsById = $cameraSlotsByBoard[$resultSceneBoardIndex] ?? [];
                    $cameraTitle = current_camera_slot_name(
                        (string)($combo['selected_camera_slot_id'] ?? ''),
                        (string)($combo['camera_slot_name'] ?? ''),
                        $rowCameraSlotsById
                    );
                    $sceneTitle = trim((string)($combo['world_mother_category'] ?? $state['world_mother_category'] ?? $selectedWorldMotherCategory));
                    if ($sceneTitle === '') {
                        $sceneTitle = 'Scene not recorded';
                    }
                    $resultGenerationProvider = strtolower(trim((string)($state['generation_provider'] ?? 'gemini'))) === 'openai' ? 'openai' : 'gemini';
                    ?>
                    <section class="result-card batch-<?= (int)$resultSceneBoardIndex ?><?= $mockupId === $highlightMockupId ? ' is-new-generation' : '' ?>" id="result-card-<?= $mockupId ?>" data-result-batch="<?= (int)$resultSceneBoardIndex ?>" data-result-run="<?= h($resultGenerationRunId) ?>"<?= $activeGenerationCount > 0 && $resultGenerationRunId !== $activeGenerationRunId ? ' hidden' : '' ?>>
                        <div class="result-image-wrap">
                            <div class="result-image-toolbar media-thumb-toolbar" aria-label="Mockup actions">
                                <button
                                    class="favorite-overlay-btn media-icon-button <?= isset($favoriteMockupLookup[$mockupId]) ? 'active' : '' ?>"
                                    type="button"
                                    title="<?= isset($favoriteMockupLookup[$mockupId]) ? 'Remove favorite' : 'Add favorite' ?>"
                                    aria-label="<?= isset($favoriteMockupLookup[$mockupId]) ? 'Remove favorite' : 'Add favorite' ?>"
                                    data-favorite-mockup
                                    data-mockup-id="<?= $mockupId ?>"
                                >
                                    <svg class="result-action-icon" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="m12 3.7 2.55 5.17 5.71.83-4.13 4.03.97 5.69L12 16.73l-5.1 2.69.97-5.69L3.74 9.7l5.71-.83L12 3.7Z"></path>
                                    </svg>
                                </button>
                                <div class="result-image-actions media-thumb-action-group">
                                    <button
                                        class="result-icon-action media-icon-button"
                                        type="button"
                                        title="Redo mockup"
                                        aria-label="Redo mockup"
                                        data-redo-result
                                        data-artwork-id="<?= (int)$id ?>"
                                        data-combination-index="<?= (int)($combo['combination_index'] ?? 0) ?>"
                                        data-camera-slot="<?= h((string)($combo['selected_camera_slot_id'] ?? '')) ?>"
                                        data-mockup-id="<?= $mockupId ?>"
                                        data-scene-board="<?= (int)$resultSceneBoardIndex ?>"
                                        data-world-mother-category="<?= h((string)($combo['world_mother_category'] ?? $selectedWorldMotherCategory)) ?>"
                                        data-world-mother-variant="<?= (int)($combo['world_mother_variant_offset'] ?? 0) ?>"
                                        data-generation-provider="<?= h($resultGenerationProvider) ?>"
                                    >
                                        <svg class="result-action-icon" viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M19.2 8.2A7.6 7.6 0 1 0 19 16"></path>
                                            <path d="M19.2 4.7v3.5h-3.5"></path>
                                        </svg>
                                    </button>
                                    <button
                                        class="result-icon-action danger media-icon-button is-danger"
                                        type="button"
                                        title="Delete mockup"
                                        aria-label="Delete mockup"
                                        data-delete-result
                                        data-mockup-id="<?= $mockupId ?>"
                                    >
                                        <svg class="result-action-icon" viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M7.5 8.5h9l-.65 10h-7.7l-.65-10Z"></path>
                                            <path d="M6 5.8h12M9.5 5.8V4h5v1.8M10.2 11.2v4.6M13.8 11.2v4.6"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <a class="result-image-link" href="viewer.php?id=<?= $mockupId ?>&back=<?= rawurlencode('mockup_combination_results.php?id=' . (int)$id . $generationProviderQuery . ($selectedWorldMotherCategory !== '' ? '&world_mother_category=' . rawurlencode($selectedWorldMotherCategory) : '')) ?>" aria-label="Open in Mockup Album">
                                <img src="media.php?file=<?= rawurlencode(basename((string)$row['mockup_file'])) ?>&thumb=1&w=640&v=<?= $mockupId ?>" alt="" loading="lazy" decoding="async">
                            </a>
                        </div>
                        <?php if ($mockupId === $highlightMockupId): ?>
                            <span class="new-generation-badge"><?= h($highlightLabel) ?></span>
                        <?php endif; ?>
                        <span class="result-scene-badge" title="Scene used for this result">Scene · <?= h($sceneTitle) ?></span>
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
                                data-mockup-id="<?= $mockupId ?>"
                                data-scene-board="<?= (int)$resultSceneBoardIndex ?>"
                                data-world-mother-category="<?= h((string)($combo['world_mother_category'] ?? $selectedWorldMotherCategory)) ?>"
                                data-world-mother-variant="<?= (int)($combo['world_mother_variant_offset'] ?? 0) ?>"
                                data-generation-provider="<?= h($resultGenerationProvider) ?>"
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

            <?php if ($isAdmin && !$compactSceneFlow): ?>
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
            <?php endif; ?>
        </div>
    </main>
</div>
<?php include __DIR__ . '/compact_scene_progress_layer.php'; ?>
<script>
const ACTIVE_ARTWORK_ID = <?= (int)$id ?>;
const ACTIVE_ARTWORK_ROOT_FILE = <?= json_encode(basename((string)$artwork['root_file'])) ?>;
const REGENERATION_STORAGE_KEY = 'artworkMockupsPendingRegenerations:' + ACTIVE_ARTWORK_ID;
let sceneResultsRefreshScheduled = false;

document.querySelectorAll('[data-compact-scene-launch]').forEach(link => {
    link.addEventListener('click', event => {
        if (typeof window.openArtworkSceneProgress !== 'function') return;
        event.preventDefault();
        window.openArtworkSceneProgress(link.href);
    });
});

function pendingRegenerationJobIds() {
    try {
        const parsed = JSON.parse(sessionStorage.getItem(REGENERATION_STORAGE_KEY) || '[]');
        return Array.isArray(parsed) ? parsed.map(Number).filter(Number.isFinite) : [];
    } catch (error) {
        return [];
    }
}

function savePendingRegenerationJobIds(values) {
    sessionStorage.setItem(
        REGENERATION_STORAGE_KEY,
        JSON.stringify(Array.from(new Set((values || []).map(Number).filter(Number.isFinite))))
    );
}

function rememberRegenerationJob(jobId) {
    savePendingRegenerationJobIds(pendingRegenerationJobIds().concat(Number(jobId)));
}

function forgetRegenerationJob(jobId) {
    savePendingRegenerationJobIds(pendingRegenerationJobIds().filter(id => id !== Number(jobId)));
}

function resetRegenerationButton(jobId) {
    const button = document.querySelector('[data-regeneration-job-id="' + Number(jobId) + '"]');
    if (!button) return;
    button.disabled = false;
    button.classList.remove('is-regenerating');
    button.removeAttribute('data-regeneration-job-id');
    button.title = 'Redo mockup';
    button.setAttribute('aria-label', 'Redo mockup');
}

window.addEventListener('artworkmockups:generation-completed', event => {
    const item = event.detail || {};
    const jobId = Number(item.id || 0);
    if (
        jobId <= 0
        || Number(item.artwork_id || 0) !== ACTIVE_ARTWORK_ID
        || !pendingRegenerationJobIds().includes(jobId)
    ) {
        return;
    }

    forgetRegenerationJob(jobId);
    if (item.status === 'done') {
        window.artworkGenerationTracker?.dismissJobs([jobId]);
        const target = item.results_url ? new URL(item.results_url, window.location.href).href : window.location.href;
        window.location.replace(target);
        return;
    }

    resetRegenerationButton(jobId);
});

window.addEventListener('artworkmockups:generation-ready', event => {
    const item = event.detail || {};
    const jobId = Number(item.id || 0);
    const newMockupId = Number(item.mockup_id || 0);
    const replacedMockupId = Number(item.replaces_mockup_id || 0);
    if (
        jobId <= 0
        || item.kind !== 'regeneration'
        || item.status !== 'done'
        || Number(item.artwork_id || 0) !== ACTIVE_ARTWORK_ID
        || replacedMockupId <= 0
        || !document.getElementById('result-card-' + replacedMockupId)
        || (newMockupId > 0 && document.getElementById('result-card-' + newMockupId))
    ) {
        return;
    }

    forgetRegenerationJob(jobId);
    window.artworkGenerationTracker?.dismissJobs([jobId]);
    const target = item.results_url ? new URL(item.results_url, window.location.href).href : window.location.href;
    window.location.replace(target);
});

window.addEventListener('artworkmockups:generation-ready', event => {
    const item = event.detail || {};
    const jobId = Number(item.id || 0);
    const newMockupId = Number(item.mockup_id || 0);
    if (
        jobId <= 0
        || item.kind !== 'generation'
        || item.status !== 'done'
        || Number(item.artwork_id || 0) !== ACTIVE_ARTWORK_ID
    ) {
        return;
    }

    window.artworkGenerationTracker?.dismissJobs([jobId]);
    if (newMockupId > 0 && document.getElementById('result-card-' + newMockupId)) {
        return;
    }
    if (sceneResultsRefreshScheduled) {
        return;
    }

    sceneResultsRefreshScheduled = true;
    const target = item.results_url ? new URL(item.results_url, window.location.href).href : window.location.href;
    window.location.replace(target);
});

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
    formData.append('generation_provider', button.getAttribute('data-generation-provider') || 'gemini');
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
    } else {
        formData.append('existing_mockup_id', button.getAttribute('data-mockup-id') || '');
        formData.append('reference_mode', 'existing_only');
        formData.append('human_presence', 'none');
        formData.append('artwork_scale', '0');
        formData.append('lighting', '');
        formData.append('experimental_camera', '');
        formData.append('camera_strength', 'normal');
    }
    button.disabled = true;

    fetch('generate_mockup_combination.php', { method: 'POST', body: formData })
        .then(parseJsonResponse)
        .then(result => {
            if (!result.body.ok) {
                throw new Error(result.body.error || 'Regeneration failed.');
            }
            if (result.body.enqueued) {
                const jobId = Number(result.body.job_id || 0);
                rememberRegenerationJob(jobId);
                button.dataset.regenerationJobId = String(jobId);
                button.classList.add('is-regenerating');
                button.title = 'Regenerating in background';
                button.setAttribute('aria-label', 'Regenerating in background');
                window.artworkGenerationTracker?.trackJobs([jobId]);
                return result.body;
            } else {
                window.location.href = result.body.results_url || window.location.href;
            }
        })
        .catch(err => {
            alert(err.message);
            button.disabled = false;
            button.classList.remove('is-regenerating');
            button.removeAttribute('data-regeneration-job-id');
            button.title = 'Redo mockup';
            button.setAttribute('aria-label', 'Redo mockup');
        });
}

function initMobileResultsSliders() {
    const mobileQuery = window.matchMedia('(max-width: 760px)');
    document.querySelectorAll('.results-grid').forEach(grid => {
        const cards = Array.from(grid.querySelectorAll('.result-card'));
        if (!cards.length || grid.dataset.sliderReady === '1') return;
        grid.dataset.sliderReady = '1';

        let index = 0;
        let scrollTimer = 0;
        let suppressClick = false;
        let startX = 0;
        let startY = 0;

        function isMobile() {
            return mobileQuery.matches;
        }

        function apply() {
            if (!isMobile()) {
                cards.forEach(card => {
                    card.classList.remove('active');
                    card.removeAttribute('aria-current');
                });
                return;
            }
            cards.forEach((card, cardIndex) => {
                const active = cardIndex === index;
                card.classList.toggle('active', active);
                card.setAttribute('aria-current', active ? 'true' : 'false');
            });
        }

        function setIndex(nextIndex, options = {}) {
            index = Math.max(0, Math.min(cards.length - 1, nextIndex));
            apply();
            if (options.scroll) {
                const activeCard = cards[index];
                if (activeCard) {
                    grid.scrollTo({
                        left: activeCard.offsetLeft - grid.offsetLeft,
                        behavior: options.smooth ? 'smooth' : 'auto'
                    });
                }
            }
        }

        function syncFromScroll() {
            if (!isMobile() || !cards.length) return;
            const gridRect = grid.getBoundingClientRect();
            const targetLeft = gridRect.left + 10;
            let closestIndex = index;
            let closestDistance = Number.POSITIVE_INFINITY;
            cards.forEach((card, cardIndex) => {
                const distance = Math.abs(card.getBoundingClientRect().left - targetLeft);
                if (distance < closestDistance) {
                    closestDistance = distance;
                    closestIndex = cardIndex;
                }
            });
            setIndex(closestIndex);
        }

        grid.addEventListener('pointerdown', event => {
            if (!isMobile() || !cards.length || event.target.closest('button')) return;
            suppressClick = false;
            startX = event.clientX;
            startY = event.clientY;
        }, { passive: true });

        grid.addEventListener('pointermove', event => {
            if (!isMobile()) return;
            const dx = Math.abs(event.clientX - startX);
            const dy = Math.abs(event.clientY - startY);
            if (dx > 10 && dx > dy) {
                suppressClick = true;
            }
        }, { passive: true });

        grid.addEventListener('pointerup', () => {
            window.setTimeout(() => { suppressClick = false; }, 180);
        }, { passive: true });

        grid.addEventListener('pointercancel', () => {
            window.setTimeout(() => { suppressClick = false; }, 180);
        }, { passive: true });

        grid.addEventListener('scroll', () => {
            window.clearTimeout(scrollTimer);
            scrollTimer = window.setTimeout(syncFromScroll, 80);
        }, { passive: true });

        grid.addEventListener('click', event => {
            if (!isMobile() || !suppressClick) return;
            event.preventDefault();
            event.stopPropagation();
        }, true);
        mobileQuery.addEventListener?.('change', () => setIndex(index, { scroll: true }));
        window.addEventListener('resize', () => setIndex(index, { scroll: true }));
        setIndex(index, { scroll: true });
    });
}
initMobileResultsSliders();

const highlightedResult = document.querySelector('.result-card.is-new-generation');
if (highlightedResult) {
    window.requestAnimationFrame(() => {
        highlightedResult.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
    });
}

document.addEventListener('click', event => {
    const target = actionTarget(event);
    if (!target) {
        return;
    }

    const resultFilterButton = target.closest('[data-result-filter]');
    if (resultFilterButton) {
        event.preventDefault();
        const filterValue = resultFilterButton.getAttribute('data-result-filter') || 'all';
        document.querySelectorAll('[data-result-filter]').forEach(button => {
            button.classList.toggle('active', button === resultFilterButton);
        });
        document.querySelectorAll('[data-result-run]').forEach(card => {
            const show = filterValue === 'all'
                || (filterValue.startsWith('run:') && card.getAttribute('data-result-run') === filterValue.slice(4));
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
<script>
// Chrome puede restaurar una página completa desde su back/forward cache aun
// después de eliminar la obra. Fuerza la validación del servidor en ese caso.
window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});
</script>
</body>
</html>
