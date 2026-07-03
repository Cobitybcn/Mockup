<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();

$id = max(0, (int)($_GET['id'] ?? 0));

if ($id <= 0) {
    http_response_code(404);
    die('Artwork ID is missing.');
}

// Fetch artwork and check ownership
$stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
$stmt->execute(['id' => $id, 'user_id' => $user['id']]);
$artwork = $stmt->fetch();

if (!$artwork) {
    http_response_code(404);
    die('Artwork not found or access denied.');
}

// Upload Oblique Perspective Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_oblique') {
    $viewType = $_POST['view_type'] ?? '';
    if (in_array($viewType, ['three_quarter_left', 'three_quarter_right'], true)) {
        $dbViewType = str_replace('_', '-', $viewType);
        
        if (isset($_FILES['oblique_file']) && $_FILES['oblique_file']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['oblique_file']['tmp_name'];
            $origName = $_FILES['oblique_file']['name'];
            $ext = pathinfo($origName, PATHINFO_EXTENSION);
            if (!in_array(strtolower($ext), ['png', 'jpg', 'jpeg', 'webp'], true)) {
                die('Invalid file extension. Please upload PNG, JPG, JPEG, or WEBP.');
            }
            
            $newName = 'base_artwork_oblique_' . $viewType . '_' . $id . '_' . time() . '.' . $ext;
            $destPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $newName;
            
            if (move_uploaded_file($tmpPath, $destPath)) {
                Database::withBusyRetry(function() use ($pdo, $id, $newName, $dbViewType) {
                    $stmt = $pdo->prepare('SELECT id FROM root_artwork_candidates WHERE artwork_id = :artwork_id AND view_type = :view_type LIMIT 1');
                    $stmt->execute(['artwork_id' => $id, 'view_type' => $dbViewType]);
                    $candId = $stmt->fetchColumn();
                    
                    if ($candId !== false) {
                        $pdo->prepare('UPDATE root_artwork_candidates SET file_name = :file_name WHERE id = :id')
                            ->execute(['file_name' => $newName, 'id' => $candId]);
                    } else {
                        $pdo->prepare('INSERT INTO root_artwork_candidates (artwork_id, file_name, view_type, is_selected) VALUES (:artwork_id, :file_name, :view_type, 0)')
                            ->execute([
                                'artwork_id' => $id,
                                'file_name' => $newName,
                                'view_type' => $dbViewType
                            ]);
                    }
                }, 12);
                
                header('Location: core_review.php?id=' . $id . '&uploaded=1');
                exit;
            }
        }
    }
}

// 1. Read CORE JSON if exists
$corePath = __DIR__ . '/analysis/core/' . $id . '.core.json';
$coreJson = null;
if (is_file($corePath)) {
    $coreJson = json_decode((string)file_get_contents($corePath), true);
    if (!is_array($coreJson)) {
        $coreJson = null;
    }
}

// 2. Fetch database candidates as fallback
$dbCandidates = [];
try {
    $candStmt = $pdo->prepare("
        SELECT file_name, view_type, is_selected 
        FROM root_artwork_candidates 
        WHERE artwork_id = :id
    ");
    $candStmt->execute(['id' => $id]);
    foreach ($candStmt->fetchAll() as $row) {
        $vt = (string)$row['view_type'];
        if ($vt === 'three-quarter-left') {
            $vt = 'three_quarter_left';
        } elseif ($vt === 'three-quarter-right') {
            $vt = 'three_quarter_right';
        }
        $dbCandidates[$vt] = basename((string)$row['file_name']);
    }
} catch (Throwable $e) {
    // Ignore fallback errors
}

// 3. Resolve Views
$views = [
    'frontal' => [
        'file' => $coreJson['root_artwork_views']['frontal']['file'] ?? $dbCandidates['frontal'] ?? null,
        'role' => $coreJson['root_artwork_views']['frontal']['role'] ?? 'frontal root view',
        'label' => 'Frontal',
    ],
    'three_quarter_left' => [
        'file' => $coreJson['root_artwork_views']['three_quarter_left']['file'] ?? $dbCandidates['three_quarter_left'] ?? null,
        'role' => $coreJson['root_artwork_views']['three_quarter_left']['role'] ?? 'three-quarter left root view',
        'label' => 'Three-quarter Left',
    ],
    'three_quarter_right' => [
        'file' => $coreJson['root_artwork_views']['three_quarter_right']['file'] ?? $dbCandidates['three_quarter_right'] ?? null,
        'role' => $coreJson['root_artwork_views']['three_quarter_right']['role'] ?? 'three-quarter right root view',
        'label' => 'Three-quarter Right',
    ],
];

$selectedView = $coreJson['root_artwork_views']['selected_view'] ?? null;
if (!$selectedView && !empty($artwork['root_file'])) {
    $rf = basename((string)$artwork['root_file']);
    if ($rf === $views['frontal']['file']) {
        $selectedView = 'frontal';
    } elseif ($rf === $views['three_quarter_left']['file']) {
        $selectedView = 'three_quarter_left';
    } elseif ($rf === $views['three_quarter_right']['file']) {
        $selectedView = 'three_quarter_right';
    }
}

// Helper: safe escaping
if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

// Helper: map booleans
if (!function_exists('map_bool')) {
    function map_bool($val): string
    {
        if ($val === true) {
            return 'Yes';
        }
        if ($val === false) {
            return 'No';
        }
        return 'Not detected';
    }
}

// Helper: map array elements
if (!function_exists('map_array')) {
    function map_array($arr): string
    {
        if (!is_array($arr) || empty($arr)) {
            return 'None';
        }
        return implode(', ', array_map('h', $arr));
    }
}

// Helper: map strings / values
if (!function_exists('map_val')) {
    function map_val($val): string
    {
        if ($val === null || trim((string)$val) === '') {
            return 'Not detected';
        }
        return (string)$val;
    }
}

// 4. Physical Artwork Reference Properties
$phys = $coreJson['physical_artwork_reference'] ?? [];
$physObjectType = $phys['object_type'] ?? (($artwork['width'] || $artwork['height']) ? 'stretched_canvas' : null);
$physIsPhysical = $phys['is_physical_object'] ?? (($artwork['width'] || $artwork['height']) ? true : null);
$physDepth = $phys['depth_cm'] ?? ($artwork['depth'] ? (float)$artwork['depth'] : null);
$physHasEdges = $phys['has_visible_edges'] ?? (($views['three_quarter_left']['file'] || $views['three_quarter_right']['file']) ? true : null);
$physPaintContinues = $phys['paint_continues_on_edges'] ?? null;
$physEdgeFinish = $phys['edge_finish'] ?? null;

// View observations
$obs = $phys['view_observations'] ?? [];
$viewObservations = [
    'frontal' => [
        'visible_edges' => $obs['frontal']['visible_edges'] ?? [],
        'canvas_depth_visible' => $obs['frontal']['canvas_depth_visible'] ?? false,
        'paint_continuity_visible' => $obs['frontal']['paint_continuity_visible'] ?? $physPaintContinues,
        'best_for' => $obs['frontal']['best_for'] ?? ['primary_composition_reference', 'frontal_mockup_reference'],
    ],
    'three_quarter_left' => [
        'visible_edges' => $obs['three_quarter_left']['visible_edges'] ?? ($views['three_quarter_left']['file'] ? ['left_edge'] : []),
        'canvas_depth_visible' => $obs['three_quarter_left']['canvas_depth_visible'] ?? ($views['three_quarter_left']['file'] ? true : null),
        'paint_continuity_visible' => $obs['three_quarter_left']['paint_continuity_visible'] ?? ($views['three_quarter_left']['file'] ? $physPaintContinues : null),
        'best_for' => $obs['three_quarter_left']['best_for'] ?? ['left_oblique_reference', 'canvas_depth_reference'],
    ],
    'three_quarter_right' => [
        'visible_edges' => $obs['three_quarter_right']['visible_edges'] ?? ($views['three_quarter_right']['file'] ? ['right_edge'] : []),
        'canvas_depth_visible' => $obs['three_quarter_right']['canvas_depth_visible'] ?? ($views['three_quarter_right']['file'] ? true : null),
        'paint_continuity_visible' => $obs['three_quarter_right']['paint_continuity_visible'] ?? ($views['three_quarter_right']['file'] ? $physPaintContinues : null),
        'best_for' => $obs['three_quarter_right']['best_for'] ?? ['right_oblique_reference', 'canvas_depth_reference'],
    ],
];

// 5. Curatorial Exploration Branches
$suggestedTitles = $coreJson['publishing_texts']['suggested_titles'] ?? [];
if (empty($suggestedTitles)) {
    // Try to load from database analysis JSON as fallback
    try {
        $stmtAnalysis = $pdo->prepare("
            SELECT analysis_json 
            FROM artwork_analysis 
            WHERE artwork_id = :id 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmtAnalysis->execute(['id' => $id]);
        $rowAnalysis = $stmtAnalysis->fetchColumn();
        if ($rowAnalysis) {
            $analysisData = json_decode((string)$rowAnalysis, true);
            if (is_array($analysisData)) {
                $titlesSource = $analysisData['suggested_titles'] ?? $analysisData['publishing_metadata']['suggested_titles'] ?? [];
                if (is_array($titlesSource)) {
                    for ($i = 0; $i < 3; $i++) {
                        if (isset($titlesSource[$i]) && is_array($titlesSource[$i])) {
                            $suggestedTitles[] = [
                                'title' => $titlesSource[$i]['title'] ?? null,
                                'subtitle' => $titlesSource[$i]['subtitle'] ?? null,
                                'description' => $titlesSource[$i]['description'] ?? null
                            ];
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // Ignore fallback errors
    }
}

// Guarantee exactly 3 branches for display
for ($i = 0; $i < 3; $i++) {
    if (!isset($suggestedTitles[$i]) || !is_array($suggestedTitles[$i])) {
        $suggestedTitles[$i] = [
            'title' => null,
            'subtitle' => null,
            'description' => null
        ];
    }
}
// Check if mockups are currently queued to resolve the correct target route (mockup_batch_wait.php vs report.php)
$queuedMockups = 0;
$rootFile = basename((string)($artwork['root_file'] ?? ''));
if ($rootFile !== '') {
    try {
        $queuedMockups = count(MockupBatchQueue::rowsForArtwork($id));
    } catch (Throwable $e) {}
}

$curatedMockupsUrl = $queuedMockups > 0
    ? 'mockup_batch_wait.php?image=' . rawurlencode($rootFile)
    : 'report.php?image=' . rawurlencode($rootFile);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Review Artwork Core - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .core-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .core-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 20px;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .core-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }
        .core-card.missing {
            background: var(--surface-soft);
            border-style: dashed;
            border-color: var(--line-dark);
            opacity: 0.8;
        }
        .core-card img {
            width: 100%;
            height: auto;
            aspect-ratio: 4/3;
            object-fit: contain;
            background: var(--surface-soft);
            border-radius: 2px;
            border: 1px solid var(--line);
            margin-bottom: 12px;
        }
        .core-card .missing-placeholder {
            width: 100%;
            aspect-ratio: 4/3;
            background: rgba(20, 20, 18, 0.02);
            border: 1px dashed var(--line-dark);
            border-radius: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 12px;
        }
        .core-card h3 {
            margin: 0 0 8px 0;
            font-size: 16px;
            font-weight: 600;
            color: var(--ink);
        }
        .core-card p {
            margin: 0;
            font-size: 12px;
            color: var(--muted);
            word-break: break-all;
        }
        .core-card .badge {
            margin-top: 12px;
            display: inline-block;
            align-self: flex-start;
        }
        .badge {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 4px 8px;
            border-radius: 3px;
            background: var(--accent-light);
            color: var(--accent);
            border: 1px solid rgba(154, 123, 86, 0.2);
        }
        .badge.danger {
            background: #FFF5F5;
            color: var(--danger);
            border-color: rgba(166, 60, 60, 0.2);
        }
        .badge.info {
            background: #EBF8FF;
            color: #2B6CB0;
            border-color: rgba(43, 108, 176, 0.2);
        }
        
        .ref-section {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 26px;
            margin-bottom: 30px;
            box-shadow: var(--shadow);
        }
        
        .tech-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .tech-field {
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 12px 16px;
        }
        .tech-field span {
            display: block;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--muted);
            letter-spacing: 0.05em;
            margin-bottom: 4px;
        }
        .tech-field strong {
            font-size: 14px;
            color: var(--ink);
        }
        
        .observations-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 16px;
        }
        .observations-table th,
        .observations-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--line);
        }
        .observations-table th {
            background: var(--surface-soft);
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
        }
        .observations-table tr:last-child td {
            border-bottom: none;
        }
        
        .branch-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .branch-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .branch-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }
        .branch-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }
        .branch-number {
            font-size: 12px;
            font-weight: 700;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .branch-content {
            margin-bottom: 24px;
            flex-grow: 1;
        }
        .branch-content h3 {
            margin: 0 0 6px 0;
            font-family: var(--font-serif);
            font-size: 24px;
            font-weight: 600;
            color: var(--ink);
            line-height: 1.2;
        }
        .branch-content h4 {
            margin: 0 0 12px 0;
            font-size: 13px;
            font-weight: 500;
            color: var(--muted);
            font-style: italic;
        }
        .branch-content p {
            margin: 0;
            font-size: 13px;
            line-height: 1.5;
            color: var(--ink);
            opacity: 0.9;
        }
        
        @media (max-width: 900px) {
            .core-grid,
            .tech-grid,
            .branch-grid {
                grid-template-columns: 1fr;
            }
            .observations-table {
                display: block;
                overflow-x: auto;
            }
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

        <div class="alert-strip">
            Review the structural visual views, observed physical references, and exploration directions parsed from the Core JSON.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Review Artwork Core</h1>
                    <p>Core metadata and visual exploration dashboard for the selected artwork.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="artwork_details.php?id=<?= (int)$id ?>">Artwork Details</a>
                    <?php if (!empty($artwork['root_file'])): ?>
                        <a class="button-link" href="mockup_combinations_review.php?id=<?= (int)$id ?>">Review Mockup Combinations</a>
                        <a class="button-link secondary" href="mockup_prompt_drafts_review.php?id=<?= (int)$id ?>">Prompt Drafts</a>
                        <?php if (Auth::isAdmin($user) && defined('LEGACY_MOCKUP_FLOW_ENABLED') && LEGACY_MOCKUP_FLOW_ENABLED): ?>
                            <a class="button-link secondary" href="curated_mockups.php?image=<?= rawurlencode($rootFile) ?>&id=<?= (int)$id ?>&legacy=1">Curated Mockups (Legacy)</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($coreJson === null): ?>
                <div class="notice" style="border-left-color: var(--danger); background: rgba(166, 60, 60, 0.03); color: var(--ink);">
                    <strong>Notice:</strong> The Core JSON file does not exist for this artwork. Showing basic information using local database references and legacy fallbacks.
                </div>
            <?php endif; ?>

            <!-- SECTION 1: ROOT ARTWORK VIEWS -->
            <div class="section-heading">
                <div>
                    <h2>Root Artwork Views</h2>
                    <p>Frontal and oblique capture reference frames</p>
                </div>
            </div>

            <div class="core-grid">
                <?php foreach ($views as $key => $view): ?>
                    <?php $fileEx = $view['file'] && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $view['file']); ?>
                    <div class="core-card <?= $fileEx ? '' : 'missing' ?>">
                        <?php if ($fileEx): ?>
                            <a href="media.php?file=<?= rawurlencode((string)$view['file']) ?>" target="_blank">
                                <img src="media.php?file=<?= rawurlencode((string)$view['file']) ?>" alt="<?= h($view['label']) ?>">
                            </a>
                            <h3><?= h($view['label']) ?></h3>
                            <p><strong>Filename:</strong> <?= h($view['file']) ?></p>
                            <p><strong>Role:</strong> <?= h($view['role']) ?></p>
                            
                            <!-- Replace Perspective Form -->
                            <?php if ($key !== 'frontal'): ?>
                                <form method="post" enctype="multipart/form-data" style="margin-top: auto; padding-top: 10px; border-top: 1px dashed var(--line);">
                                    <input type="hidden" name="action" value="upload_oblique">
                                    <input type="hidden" name="view_type" value="<?= h($key) ?>">
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <input type="file" name="oblique_file" accept="image/*" required style="font-size: 10px; width: 100%; min-height: unset; padding: 2px;">
                                        <button type="submit" class="button" style="padding: 4px 8px; font-size: 9px; margin: 0; min-height: unset; line-height: 1;">Replace</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="missing-placeholder">
                                <span>No capture available</span>
                            </div>
                            <h3><?= h($view['label']) ?></h3>
                            <p><strong>Status:</strong> <span class="badge danger">Missing</span></p>
                            <p style="margin-bottom: 12px;"><strong>Expected:</strong> <?= h($view['role']) ?></p>
                            
                            <!-- Upload Oblique Form -->
                            <?php if ($key !== 'frontal'): ?>
                                <form method="post" enctype="multipart/form-data" style="margin-top: auto; padding-top: 10px; border-top: 1px dashed var(--line-dark);">
                                    <input type="hidden" name="action" value="upload_oblique">
                                    <input type="hidden" name="view_type" value="<?= h($key) ?>">
                                    <label style="font-size: 9px; text-transform: uppercase; font-weight: 700; color: var(--muted); display: block; margin-bottom: 6px;">Upload Perspective</label>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <input type="file" name="oblique_file" accept="image/*" required style="font-size: 10px; width: 100%; min-height: unset; padding: 2px;">
                                        <button type="submit" class="button" style="padding: 4px 8px; font-size: 9px; margin: 0; min-height: unset; line-height: 1;">Upload</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($selectedView === $key): ?>
                            <span class="badge info" style="margin-top: 10px;">Selected View (Technical Reference)</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- SECTION 2: PHYSICAL ARTWORK REFERENCE -->
            <div class="section-heading">
                <div>
                    <h2>Physical Artwork Reference</h2>
                    <p>Observable physical attributes and view measurements</p>
                </div>
            </div>

            <div class="ref-section">
                <div class="tech-grid">
                    <div class="tech-field">
                        <span>Object Type</span>
                        <strong><?= h(map_val($physObjectType)) ?></strong>
                    </div>
                    <div class="tech-field">
                        <span>Is Physical Object</span>
                        <strong><?= h(map_bool($physIsPhysical)) ?></strong>
                    </div>
                    <div class="tech-field">
                        <span>Support Depth (cm)</span>
                        <strong><?= $physDepth !== null ? h($physDepth . ' cm') : 'Not detected' ?></strong>
                    </div>
                    <div class="tech-field">
                        <span>Has Visible Edges</span>
                        <strong><?= h(map_bool($physHasEdges)) ?></strong>
                    </div>
                    <div class="tech-field">
                        <span>Paint Continues on Edges</span>
                        <strong><?= h(map_bool($physPaintContinues)) ?></strong>
                    </div>
                    <div class="tech-field">
                        <span>Edge Finish Style</span>
                        <strong><?= h(map_val($physEdgeFinish)) ?></strong>
                    </div>
                </div>

                <h3>View Specific Technical Observations</h3>
                <table class="observations-table">
                    <thead>
                        <tr>
                            <th>Capture View</th>
                            <th>Visible Edges</th>
                            <th>Depth Visible</th>
                            <th>Paint Continuity Visible</th>
                            <th>Primary Use / Best For</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($viewObservations as $vKey => $vObs): ?>
                            <tr>
                                <td><strong><?= h($views[$vKey]['label']) ?></strong></td>
                                <td><?= h(map_array($vObs['visible_edges'])) ?></td>
                                <td><?= h(map_bool($vObs['canvas_depth_visible'])) ?></td>
                                <td><?= h(map_bool($vObs['paint_continuity_visible'])) ?></td>
                                <td><?= h(map_array($vObs['best_for'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- SECTION 3: CURATORIAL EXPLORATION BRANCHES -->
            <div class="section-heading">
                <div>
                    <h2>Curatorial Exploration Branches</h2>
                    <p>Three unique curatorial directions for future mockups exploration</p>
                </div>
            </div>

            <div class="branch-grid">
                <?php foreach ($suggestedTitles as $idx => $branch): ?>
                    <div class="branch-card">
                        <div class="branch-header">
                            <span class="branch-number">Branch <?= $idx + 1 ?></span>
                            <span class="badge">Curatorial Direction</span>
                        </div>
                        <div class="branch-content">
                            <h3><?= h(map_val($branch['title'])) ?></h3>
                            <h4><?= h(map_val($branch['subtitle'])) ?></h4>
                            <p><?= h(map_val($branch['description'])) ?></p>
                        </div>
                        <div style="margin-top: auto;">
                            <button class="button secondary" style="opacity: 0.6; cursor: not-allowed; margin-top: 0; width: 100%;" disabled>
                                Explore this direction
                            </button>
                            <small style="text-align: center; margin: 6px 0 0 0; color: var(--muted); font-size: 11px; display: block;">
                                Future mockup exploration
                            </small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </main>
</div>
</body>
</html>
