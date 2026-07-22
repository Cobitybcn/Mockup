<?php
declare(strict_types=1);

// Disable error leakage in case of warnings/notices
ini_set('display_errors', '0');

require_once __DIR__ . '/app/bootstrap.php';

$isCli = (php_sapi_name() === 'cli');

// Database connection
try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    if ($isCli) {
        echo "Error connecting to the database: " . $e->getMessage() . "\n";
        exit(1);
    } else {
        http_response_code(500);
        exit("Error connecting to the database: " . htmlspecialchars($e->getMessage()));
    }
}

// 1. Parse GET parameters
$artworkId = isset($_GET['artwork_id']) ? (int)$_GET['artwork_id'] : 0;
$contextId = isset($_GET['context_id']) ? (int)$_GET['context_id'] : 0;
$mockupId = isset($_GET['mockup_id']) ? (int)$_GET['mockup_id'] : 0;

// Query all artworks for selection dropdowns
$artworks = [];
try {
    $stmtArtworks = $pdo->query("SELECT id, final_title, root_file FROM artworks ORDER BY id DESC LIMIT 100");
    $artworks = $stmtArtworks->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Ignore
}

// If artwork_id is set, query its mockup contexts
$contexts = [];
if ($artworkId > 0) {
    try {
        $stmtContexts = $pdo->prepare("SELECT id, context_name FROM mockup_contexts WHERE artwork_id = :artwork_id ORDER BY id ASC");
        $stmtContexts->execute(['artwork_id' => $artworkId]);
        $contexts = $stmtContexts->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Ignore
    }
}

// If mockup_id is set but artwork_id is not, find artwork_id from mockup
if ($mockupId > 0 && $artworkId <= 0) {
    try {
        $stmtMockup = $pdo->prepare("SELECT artwork_file, context_id FROM mockups WHERE id = :id LIMIT 1");
        $stmtMockup->execute(['id' => $mockupId]);
        $mRow = $stmtMockup->fetch(PDO::FETCH_ASSOC);
        if ($mRow) {
            $contextId = (int)$mRow['context_id'];
            
            // Find artwork from file
            $stmtArt = $pdo->prepare("SELECT id FROM artworks WHERE root_file = :root_file OR main_file = :main_file LIMIT 1");
            $stmtArt->execute([
                'root_file' => basename((string)($mRow['artwork_file'] ?? '')),
                'main_file' => basename((string)($mRow['artwork_file'] ?? '')),
            ]);
            $artworkId = (int)$stmtArt->fetchColumn();
        }
    } catch (Throwable $e) {
        // Ignore
    }
}

// If artwork_id is selected but no context_id, default to first context
if ($artworkId > 0 && $contextId <= 0 && !empty($contexts)) {
    $contextId = (int)$contexts[0]['id'];
}

// Query mockups generated for this artwork/context for the mockup selection dropdown
$mockupsList = [];
if ($artworkId > 0) {
    try {
        $stmtMList = $pdo->prepare("
            SELECT id, mockup_file, created_at, context_id 
            FROM mockups 
            WHERE artwork_file IN (
                SELECT root_file FROM artworks WHERE id = :id UNION SELECT main_file FROM artworks WHERE id = :id2
            )
            ORDER BY id DESC
        ");
        $stmtMList->execute(['id' => $artworkId, 'id2' => $artworkId]);
        $mockupsList = $stmtMList->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// Load selected data
$selectedArtwork = null;
$selectedContext = null;
$selectedMockup = null;

if ($artworkId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM artworks WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $artworkId]);
    $selectedArtwork = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($contextId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM mockup_contexts WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $contextId]);
    $selectedContext = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($mockupId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM mockups WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $mockupId]);
    $selectedMockup = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($contextId > 0) {
    // Try to auto-load the latest mockup generated for this context
    $stmt = $pdo->prepare("SELECT * FROM mockups WHERE context_id = :context_id ORDER BY id DESC LIMIT 1");
    $stmt->execute(['context_id' => $contextId]);
    $selectedMockup = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($selectedMockup) {
        $mockupId = (int)$selectedMockup['id'];
    }
}

// HTML Web page rendering
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Prompt Composition Audit &mdash; Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg: #090908;
            --surface: #121210;
            --surface-card: #191815;
            --line: #24231f;
            --muted: #858174;
            --ink: #e3e1d8;
            --primary: #cda869;
            --primary-soft: rgba(205,168,105,0.15);
            --danger: #cf5b5b;
            --danger-soft: rgba(207,91,91,0.15);
            --success: #67a672;
            --success-soft: rgba(103,166,114,0.15);
            --info: #5bc0de;
            --info-soft: rgba(91,192,222,0.15);
            --purple: #9d80d8;
            --purple-soft: rgba(157,128,216,0.15);
            --radius: 8px;
            --font-sans: 'Outfit', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --font-serif: 'Playfair Display', Georgia, serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--bg);
            color: var(--ink);
            font-family: var(--font-sans);
            line-height: 1.6;
            padding: 30px 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        header {
            margin-bottom: 30px;
            border-bottom: 1px solid var(--line);
            padding-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        h1 {
            font-family: var(--font-serif);
            font-size: 32px;
            font-weight: 600;
            color: var(--primary);
        }

        .subtitle {
            color: var(--muted);
            font-size: 15px;
            font-weight: 300;
        }

        .tag-read-only {
            background: var(--primary-soft);
            color: var(--primary);
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            border: 1px solid rgba(205,168,105,0.3);
        }

        /* Selectors panel */
        .selector-panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 35px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.4);
        }

        .selector-form {
            display: flex;
            gap: 20px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
            min-width: 250px;
        }

        .form-group label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            font-weight: 600;
        }

        .form-group select {
            background: var(--surface-card);
            border: 1px solid var(--line);
            color: var(--ink);
            padding: 10px;
            border-radius: var(--radius);
            font-family: var(--font-sans);
            font-size: 14px;
            cursor: pointer;
            width: 100%;
        }

        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .btn-submit {
            background: var(--primary);
            color: var(--bg);
            border: none;
            padding: 11px 24px;
            font-family: var(--font-sans);
            font-weight: 600;
            font-size: 14px;
            border-radius: var(--radius);
            cursor: pointer;
            transition: background 0.2s, transform 0.2s;
        }

        .btn-submit:hover {
            background: #e0bb7d;
            transform: translateY(-1px);
        }

        /* Audit Grid Layout */
        .audit-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }

        @media (max-width: 1000px) {
            .audit-grid {
                grid-template-columns: 1fr;
            }
        }

        .audit-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .card-title {
            font-family: var(--font-serif);
            font-size: 22px;
            color: var(--primary);
            border-bottom: 1px solid var(--line);
            padding-bottom: 10px;
            margin-bottom: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-badge {
            font-size: 11px;
            background: var(--line);
            color: var(--muted);
            padding: 2px 8px;
            border-radius: 4px;
            text-transform: uppercase;
            font-weight: 600;
        }

        /* Key-Value Lists */
        .kv-table {
            width: 100%;
            border-collapse: collapse;
        }

        .kv-table td {
            padding: 8px 12px;
            border-bottom: 1px solid var(--line);
            font-size: 14px;
            vertical-align: top;
        }

        .kv-table tr:last-child td {
            border-bottom: none;
        }

        .kv-label {
            color: var(--muted);
            font-weight: 500;
            width: 35%;
        }

        .kv-value {
            color: var(--ink);
            font-family: monospace;
            word-break: break-all;
        }

        .kv-value.highlight-val {
            color: var(--primary);
            font-weight: 600;
            font-family: var(--font-sans);
        }

        /* Highlighting container */
        .prompt-preview-box {
            background: #0f0f0e;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 20px;
            font-family: monospace;
            font-size: 12px;
            line-height: 1.5;
            white-space: pre-wrap;
            color: var(--muted);
            max-height: 700px;
            overflow-y: auto;
            position: relative;
        }

        /* Color legend */
        .legend-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            padding: 12px;
            background: var(--surface-card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            margin-bottom: 15px;
            font-size: 12px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            display: inline-block;
        }

        /* Colors for highlight tags and spans */
        .color-admin { background-color: var(--purple); }
        .color-contexts { background-color: var(--success); }
        .color-core { background-color: var(--info); }
        .color-composer { background-color: var(--danger); }
        .color-legacy { background-color: #f0ad4e; }

        .origin-badge {
            display: inline-block;
            font-size: 9px;
            font-weight: 700;
            padding: 1px 4px;
            border-radius: 3px;
            margin-right: 4px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            vertical-align: middle;
        }

        .badge-admin { background: var(--purple-soft); color: var(--purple); border: 1px solid var(--purple); }
        .badge-contexts { background: var(--success-soft); color: var(--success); border: 1px solid var(--success); }
        .badge-core { background: var(--info-soft); color: var(--info); border: 1px solid var(--info); }
        .badge-composer { background: var(--danger-soft); color: var(--danger); border: 1px solid var(--danger); }
        .badge-legacy { background: rgba(240,173,78,0.15); color: #f0ad4e; border: 1px solid #f0ad4e; }

        .highlight-admin { color: #d0c8f2; }
        .highlight-contexts { color: #a4ebb0; background: rgba(103,166,114,0.08); padding: 1px 2px; }
        .highlight-core { color: #a7e0f0; background: rgba(91,192,222,0.08); padding: 1px 2px; }
        .highlight-composer { color: #f7b0b0; background: rgba(207,91,91,0.08); padding: 1px 2px; }
        .highlight-legacy { color: #fdd29b; background: rgba(240,173,78,0.12); font-weight: 600; text-decoration: underline wavy #f0ad4e; }

        /* Alerts card */
        .alerts-card {
            background: rgba(207,91,91,0.03);
            border: 1px solid rgba(207,91,91,0.2);
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .alerts-title {
            font-family: var(--font-serif);
            font-size: 22px;
            color: var(--danger);
            border-bottom: 1px solid rgba(207,91,91,0.15);
            padding-bottom: 10px;
        }

        .alert-item {
            background: var(--surface-card);
            border-left: 4px solid var(--danger);
            padding: 12px 16px;
            border-radius: 0 var(--radius) var(--radius) 0;
            font-size: 13.5px;
        }

        .alert-item-warning {
            border-left-color: #f0ad4e;
        }

        .alert-header {
            font-weight: 600;
            margin-bottom: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .alert-desc {
            color: var(--muted);
            font-size: 13px;
        }

        .alert-code {
            font-family: monospace;
            background: #111;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            color: #ccc;
        }

        .alert-badge {
            font-size: 10px;
            padding: 1px 6px;
            border-radius: 3px;
            text-transform: uppercase;
            font-weight: bold;
        }
        .badge-alert-critical { background: var(--danger-soft); color: var(--danger); }
        .badge-alert-warn { background: rgba(240,173,78,0.15); color: #f0ad4e; }

        /* Payload card */
        .payload-card {
            background: rgba(91,192,222,0.03);
            border: 1px solid rgba(91,192,222,0.2);
        }
        .payload-title {
            color: var(--info);
            border-bottom-color: rgba(91,192,222,0.15);
        }

        /* Prompt MASTER active full text */
        .prompt-textarea {
            width: 100%;
            background: var(--surface-card);
            border: 1px solid var(--line);
            color: #b0ac9f;
            font-family: monospace;
            font-size: 11.5px;
            padding: 12px;
            border-radius: var(--radius);
            resize: vertical;
            height: 180px;
        }

        .no-data-alert {
            text-align: center;
            padding: 40px;
            color: var(--muted);
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            font-size: 16px;
        }
    </style>
</head>
<body>
<div class="container">
    <header>
        <div>
            <h1>Prompt Composition Comparison and Diagnostics</h1>
            <div class="subtitle">Detailed analysis tool for the composer and text origins</div>
        </div>
        <div>
            <span class="tag-read-only">Read Only &mdash; Diagnostics</span>
        </div>
    </header>

    <!-- Dropdown Selector Panel -->
    <div class="selector-panel">
        <form class="selector-form" method="GET" action="compare_mockup_prompt_composition.php">
            <div class="form-group">
                <label for="artwork_id">Select Artwork</label>
                <select name="artwork_id" id="artwork_id" onchange="this.form.submit()">
                    <option value="0">-- Select an Artwork --</option>
                    <?php foreach ($artworks as $art): ?>
                        <option value="<?= $art['id'] ?>" <?= $art['id'] === $artworkId ? 'selected' : '' ?>>
                            [ID: <?= $art['id'] ?>] <?= htmlspecialchars($art['final_title'] ?: 'Untitled') ?> (<?= htmlspecialchars(basename((string)($art['root_file'] ?? ''))) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="context_id">Select Context (Proposal)</label>
                <select name="context_id" id="context_id" onchange="this.form.submit()" <?= empty($contexts) ? 'disabled' : '' ?>>
                    <?php if (empty($contexts)): ?>
                        <option value="0">-- No contexts available --</option>
                    <?php else: ?>
                        <?php foreach ($contexts as $ctx): ?>
                            <option value="<?= $ctx['id'] ?>" <?= $ctx['id'] === $contextId ? 'selected' : '' ?>>
                                [Context ID: <?= $ctx['id'] ?>] <?= htmlspecialchars($ctx['context_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="mockup_id">Mockup Generado (Historial Opcional)</label>
                <select name="mockup_id" id="mockup_id" onchange="this.form.submit()" <?= empty($mockupsList) ? 'disabled' : '' ?>>
                    <option value="0">-- Load from context or select --</option>
                    <?php foreach ($mockupsList as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $m['id'] === $mockupId ? 'selected' : '' ?>>
                            [ID: <?= $m['id'] ?>] <?= htmlspecialchars($m['mockup_file']) ?> (Contexto: <?= $m['context_id'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <button type="submit" class="btn-submit">Analizar</button>
            </div>
        </form>
    </div>

    <?php if (!$selectedArtwork || !$selectedContext): ?>
        <div class="no-data-alert">
            <p>Select an Artwork and Context above to start the Composer audit.</p>
        </div>
    <?php else: 
        // ----------------------------------------------------
        // LOGICA DE RECONSTRUCCIÓN Y AUDITORÍA
        // ----------------------------------------------------
        
        // 1. Resolve master prompt from admin
        $adminPrompt = PromptSettings::mockupFinalRequest();
        $adminPromptOrigin = 'app_settings.mockup_final_request';
        
        $stmtCheckDb = $pdo->prepare("SELECT value FROM app_settings WHERE `key` = 'mockup_final_request' LIMIT 1");
        $stmtCheckDb->execute();
        $dbVal = $stmtCheckDb->fetchColumn();
        if ($dbVal === false || trim($dbVal) === '') {
            $adminPromptOrigin = 'PromptSettings builtInDefaultDirectives fallback (Empty template)';
        }

        // 2. Resolve variables from Core JSON or DB
        $coreJsonPath = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . $artworkId . '.core.json';
        $coreJsonUsed = false;
        
        $width = null;
        $height = null;
        $depth = null;
        $fallbackReason = [];
        $fallbackUsed = false;

        if (is_file($coreJsonPath)) {
            $coreContent = file_get_contents($coreJsonPath);
            if ($coreContent !== false) {
                $coreJson = json_decode($coreContent, true);
                if (is_array($coreJson)) {
                    $coreJsonUsed = true;
                    $artworkData = $coreJson['artwork'] ?? [];
                    $dims = $artworkData['dimensions'] ?? [];
                    if (isset($dims['width_cm']) && trim((string)$dims['width_cm']) !== '') {
                        $width = (float)$dims['width_cm'];
                    }
                    if (isset($dims['height_cm']) && trim((string)$dims['height_cm']) !== '') {
                        $height = (float)$dims['height_cm'];
                    }
                    if (isset($dims['depth_cm']) && trim((string)$dims['depth_cm']) !== '') {
                        $depth = (float)$dims['depth_cm'];
                    }
                    $physRef = $coreJson['physical_artwork_reference'] ?? [];
                    if (isset($physRef['depth_cm']) && trim((string)$physRef['depth_cm']) !== '') {
                        $depth = (float)$physRef['depth_cm'];
                    }
                }
            }
        }

        // DB check for fallback
        if ($width === null || $height === null || $depth === null) {
            $widthDb = (float)($selectedArtwork['width'] ?? 0);
            $heightDb = (float)($selectedArtwork['height'] ?? 0);
            $depthDb = (float)($selectedArtwork['depth'] ?? 0);

            if ($width === null && $widthDb > 0) { $width = $widthDb; $fallbackUsed = true; $fallbackReason[] = "width from artworks DB"; }
            if ($height === null && $heightDb > 0) { $height = $heightDb; $fallbackUsed = true; $fallbackReason[] = "height from artworks DB"; }
            if ($depth === null && $depthDb > 0) { $depth = $depthDb; $fallbackUsed = true; $fallbackReason[] = "depth from artworks DB"; }
        }

        // Absolute defaults
        if ($width === null || $width <= 0) { $width = 120.0; $fallbackUsed = true; $fallbackReason[] = "width defaulted to 120"; }
        if ($height === null || $height <= 0) { $height = 80.0; $fallbackUsed = true; $fallbackReason[] = "height defaulted to 80"; }
        if ($depth === null || $depth <= 0) { $depth = 4.0; $fallbackUsed = true; $fallbackReason[] = "depth defaulted to 4"; }

        // Core variables
        $orientation = $selectedArtwork['orientation'] ?? 'landscape';
        if ($width > $height) { $orientation = 'landscape'; }
        elseif ($width < $height) { $orientation = 'portrait'; }
        else { $orientation = 'square'; }

        // 3. Context Fields
        $contextJson = json_decode((string)($selectedContext['context_json'] ?? ''), true) ?: [];
        
        // Extract fields exactly as parsed by AdminPromptComposerPreview
        $composer = new AdminPromptComposerPreview();
        $fields = (new ReflectionClass($composer))->getMethod('parseContextFields')->invoke($composer, $selectedContext);
        $contextBlock = (new ReflectionClass($composer))->getMethod('buildContextBlock')->invoke($composer, $fields);
        
        // Composed final prompt
        $composedPrompt = '';
        try {
            $composedPrompt = $composer->compose($selectedContext);
        } catch (Throwable $e) {
            $composedPrompt = "Error running composer compose: " . $e->getMessage();
        }

        // 4. Overrides applied
        $overrides = [];
        if ($selectedMockup && isset($selectedMockup['selector_state_json'])) {
            $overrides = json_decode((string)$selectedMockup['selector_state_json'], true) ?: [];
        }

        // 5. Environmental constants
        $envVars = [
            'MOCKUP_PROMPT_FIRST_MODE' => defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE,
            'MOCKUP_PROMPT_FIRST_NO_MASK_MODE' => defined('MOCKUP_PROMPT_FIRST_NO_MASK_MODE') && MOCKUP_PROMPT_FIRST_NO_MASK_MODE,
            'MOCKUP_USE_PRECOMPOSITION' => defined('MOCKUP_USE_PRECOMPOSITION') && MOCKUP_USE_PRECOMPOSITION,
            'MOCKUP_USE_BACKGROUND_EDIT' => defined('MOCKUP_USE_BACKGROUND_EDIT') && MOCKUP_USE_BACKGROUND_EDIT,
            'IMAGE_PROVIDER' => defined('IMAGE_PROVIDER') ? IMAGE_PROVIDER : 'N/A',
            'ALLOW_REAL_API' => defined('ALLOW_REAL_API') && ALLOW_REAL_API,
            'GEMINI_IMAGE_MODEL' => defined('GEMINI_IMAGE_MODEL') ? GEMINI_IMAGE_MODEL : 'N/A',
        ];

        // 6. Highlights generator
        $markers = [];
        $markerIndex = 0;

        $registerMarker = function($text, $class, $label) use (&$markers, &$markerIndex) {
            $id = "__MARKER_" . $markerIndex++ . "__";
            $markers[$id] = [
                'text' => $text,
                'class' => $class,
                'label' => $label
            ];
            return $id;
        };

        // Track and mark composed segments
        $markedPrompt = $composedPrompt;

        // A. Mark Legacy rule keywords
        $legacyTerms = [
            '50-70%', 'occupy roughly', 'filling at least', 'artwork-dominant',
            'close, cropped, and intimate', 'large statement', 'monumental piece'
        ];
        foreach ($legacyTerms as $term) {
            if (stripos($markedPrompt, $term) !== false) {
                $markedPrompt = preg_replace_callback('/' . preg_quote($term, '/') . '/i', function($m) use ($registerMarker) {
                    return $registerMarker($m[0], 'legacy', 'LEGACY RULE');
                }, $markedPrompt);
            }
        }

        // B. Mark physical dimensions text
        $widthCm = (string)$width;
        $heightCm = (string)$height;
        $depthCm = (string)$depth;
        $dimensionsLineText = "The artwork physical size is {$widthCm} cm wide × {$heightCm} cm high × {$depthCm} cm deep.";
        if (strpos($markedPrompt, $dimensionsLineText) !== false) {
            $markedPrompt = str_replace($dimensionsLineText, $registerMarker($dimensionsLineText, 'core', 'CORE JSON / DB'), $markedPrompt);
        } else {
            $markedPrompt = str_replace("{$widthCm} cm wide", $registerMarker("{$widthCm} cm wide", 'core', 'CORE JSON / DB'), $markedPrompt);
            $markedPrompt = str_replace("{$heightCm} cm high", $registerMarker("{$heightCm} cm high", 'core', 'CORE JSON / DB'), $markedPrompt);
            $markedPrompt = str_replace("{$depthCm} cm deep", $registerMarker("{$depthCm} cm deep", 'core', 'CORE JSON / DB'), $markedPrompt);
        }

        // C. Mark composer hardcoded scale rule blocks
        $scaleRuleBlock = "Scale correction rule:\nThe artwork must be rendered at its actual physical size according to the Core JSON dimensions. Do not enlarge the canvas for visual dominance or dramatic impact. When a human figure is present, use the person as the primary scale reference. The artwork should appear as a real collectible artwork of the stated dimensions, not as an oversized installation piece. The human figure may be naturally cropped in close-up, near close-up, high-angle, low-angle, or low floor compositions. Do not enlarge the artwork or canvas to force full-body human figures, furniture, or the entire room into the frame.";
        if (strpos($markedPrompt, $scaleRuleBlock) !== false) {
            $markedPrompt = str_replace($scaleRuleBlock, $registerMarker($scaleRuleBlock, 'composer', 'COMPOSER SCALE RULE'), $markedPrompt);
        }

        $negativeScaleRuleBlock = "Negative scale rule:\nNo oversized artwork. No enlarged canvas for impact. No gallery-installation scale unless the Core JSON dimensions justify it. No artwork larger than its declared physical dimensions when compared to a standing person, furniture, windows, doorways, floorboards, or wall height. Do not create a monumental canvas unless the actual artwork dimensions justify it. No mural-scale painting. No oversized installation artwork. No physically impossible scale compared with the human figure, furniture, doors, windows, floorboards, or wall height.";
        if (strpos($markedPrompt, $negativeScaleRuleBlock) !== false) {
            $markedPrompt = str_replace($negativeScaleRuleBlock, $registerMarker($negativeScaleRuleBlock, 'composer', 'COMPOSER NEGATIVE SCALE RULE'), $markedPrompt);
        }

        $contextHeader = "MOCKUP CONTEXT PROPOSAL:\nUse the following context only as subordinated scene data. These values define the\nenvironment, placement, lighting and camera direction, but they do not override the\nartwork fidelity rules, scale rules, human figure policy, camera proximity rules, or\nnegative directives stated in the admin master prompt.\n\n";
        if (strpos($markedPrompt, $contextHeader) !== false) {
            $markedPrompt = str_replace($contextHeader, $registerMarker($contextHeader, 'composer', 'COMPOSER CONTEXT DECORATOR'), $markedPrompt);
        }

        $physSizeHeader = "Artwork physical dimensions:\n";
        if (strpos($markedPrompt, $physSizeHeader) !== false) {
            $markedPrompt = str_replace($physSizeHeader, $registerMarker($physSizeHeader, 'composer', 'COMPOSER HEADER'), $markedPrompt);
        }

        // D. Mark Context dynamic fields (to separate from composer template texts)
        foreach ($fields as $key => $val) {
            if (trim((string)$val) !== '') {
                if (strpos($markedPrompt, $val) !== false) {
                    $markedPrompt = str_replace($val, $registerMarker($val, 'contexts', 'CONTEXT FIELD: ' . strtoupper($key)), $markedPrompt);
                }
            }
        }

        // Convert unmarked text to HTML-safe format
        $escapedPrompt = htmlspecialchars($markedPrompt);

        // Substitute markers back with HTML styles
        foreach ($markers as $id => $info) {
            $class = $info['class'];
            $label = $info['label'];
            $text = $info['text'];
            
            $badge = "<span class=\"origin-badge badge-{$class}\" title=\"{$label}\">{$label}</span>";
            $highlightSpan = "<span class=\"highlight-{$class}\">" . htmlspecialchars($text) . "</span>";
            
            $escapedPrompt = str_replace($id, $badge . $highlightSpan, $escapedPrompt);
        }

        // 7. Run Alerts / Verification Checks
        $alerts = [];

        // Alert: Text contamination (Hardcoded text in Composer code)
        $hasContamination = false;
        $contaminationReason = [];
        if (strpos($composedPrompt, 'Scale correction rule:') !== false) {
            $hasContamination = true;
            $contaminationReason[] = "Detected 'Scale correction rule:' injection.";
        }
        if (strpos($composedPrompt, 'Negative scale rule:') !== false) {
            $hasContamination = true;
            $contaminationReason[] = "Detected 'Negative scale rule:' injection.";
        }
        if (strpos($composedPrompt, 'Use the following context only as subordinated scene data') !== false) {
            $hasContamination = true;
            $contaminationReason[] = "Detected old context subordination header injection.";
        }
        
        if ($hasContamination) {
            $alerts[] = [
                'level' => 'critical',
                'title' => 'Code-Injected Rules (Composer Contamination)',
                'desc' => "Composer contains hardcoded instructions injected directly in PHP code: " . implode(' ', $contaminationReason) . " These should be configured in ADMIN templates.",
                'code' => 'AdminPromptComposerPreview.php'
            ];
        }

        // Alert: Legacy rules active
        foreach ($legacyTerms as $term) {
            if (stripos($composedPrompt, $term) !== false) {
                $alerts[] = [
                    'level' => 'critical',
                    'title' => 'Active Legacy Phrase Detected',
                    'desc' => "The phrase '{$term}' is active in the composed prompt. Vague legacy scaling text degrades layout control.",
                    'code' => $term
                ];
            }
        }

        // Alert: Defaults active
        if ($fallbackUsed) {
            $alerts[] = [
                'level' => 'warn',
                'title' => 'Physical Dimension Fallbacks Active',
                'desc' => "Real dimensions were not found or were incomplete in the CORE JSON. The composer resolved them using DB or hardcoded defaults: " . implode(', ', $fallbackReason) . ".",
                'code' => "width_cm={$width}, height_cm={$height}, depth_cm={$depth}"
            ];
        }

        // Alert: Camera view overwritten deterministically
        if (isset($contextJson['camera_view_original']) && $contextJson['camera_view_original'] !== $fields['camera_view']) {
            $alerts[] = [
                'level' => 'warn',
                'title' => 'Deterministic Camera Overwrite Active',
                'desc' => "The original camera view proposed by Gemini ('{$contextJson['camera_view_original']}') was overridden deterministically in PHP to '{$fields['camera_view']}' by the slot engine.",
                'code' => 'MockupContextEngine.php:151'
            ];
        }

        // Alert: Scale UI Overrides detected
        if (!empty($overrides)) {
            $alerts[] = [
                'level' => 'warn',
                'title' => 'UI Scale/Camera Overrides Active',
                'desc' => "Overrides have been saved in the mockup's database record (selector_state_json).",
                'code' => json_encode($overrides)
            ];
        }

        // Alert: Master prompt fallback
        if ($adminPromptOrigin !== 'app_settings.mockup_final_request') {
            $alerts[] = [
                'level' => 'critical',
                'title' => 'Master Prompt Fallback Active',
                'desc' => "The template key 'mockup_final_request' is missing or empty in the database. A fallback (or empty template) is being used.",
                'code' => $adminPromptOrigin
            ];
        }

        // Alert: Loss of negative prompt
        $originalNegPrompt = $selectedContext['negative_prompt'] ?? $contextJson['negative_prompt'] ?? '';
        if (trim($originalNegPrompt) !== '' && strpos($composedPrompt, $originalNegPrompt) === false) {
            $alerts[] = [
                'level' => 'critical',
                'title' => 'Negative Prompt Dropped',
                'desc' => "The original negative prompt from context ('{$originalNegPrompt}') is missing in the composed prompt.",
                'code' => 'mockup_contexts.negative_prompt'
            ];
        }

        // Alert: Loss of physical dimensions / depth
        if (strpos($composedPrompt, (string)$width) === false || strpos($composedPrompt, (string)$height) === false) {
            $alerts[] = [
                'level' => 'critical',
                'title' => 'Physical Size Dropped',
                'desc' => "The canvas width ({$width} cm) or height ({$height} cm) was not found in the final composed prompt.",
                'code' => 'dimensions check'
            ];
        }

        if (strpos($composedPrompt, (string)$depth) === false) {
            $alerts[] = [
                'level' => 'critical',
                'title' => 'Frame Thickness (Depth) Dropped',
                'desc' => "The canvas depth ({$depth} cm) is not found in the final composed prompt.",
                'code' => 'depth_cm check'
            ];
        }

        // Alert: Mode mismatch (python bridge precomposition overrides)
        if ($envVars['MOCKUP_PROMPT_FIRST_MODE'] && $envVars['MOCKUP_USE_PRECOMPOSITION']) {
            $alerts[] = [
                'level' => 'warn',
                'title' => 'Conflict in Precomposition settings',
                'desc' => "Both MOCKUP_PROMPT_FIRST_MODE=true and MOCKUP_USE_PRECOMPOSITION=true are active. MOCKUP_PROMPT_FIRST_MODE overrides and disables precomposition in vertex_bridge.py.",
                'code' => 'vertex_bridge.py'
            ];
        }
        
        ?>

        <div class="audit-grid">
            <!-- COLUMNA IZQUIERDA: ORÍGENES / DETALLES DE PROPUESTA -->
            <div class="audit-card">
                <div>
                    <h2 class="card-title">
                        1. ADMIN MASTER PROMPT
                        <span class="card-badge">Template</span>
                    </h2>
                    <p style="font-size: 13px; color: var(--muted); margin-bottom: 8px;">
                        Origen: <code><?= htmlspecialchars($adminPromptOrigin) ?></code>
                    </p>
                    <textarea class="prompt-textarea" readonly><?= htmlspecialchars($adminPrompt) ?></textarea>
                </div>

                <div>
                    <h2 class="card-title">
                        2. RESOLVED VARIABLES (Physical Scale)
                        <span class="card-badge">Dynamic Context</span>
                    </h2>
                    <table class="kv-table">
                        <tr>
                            <td class="kv-label">Artwork ID</td>
                            <td class="kv-value highlight-val"><?= $artworkId ?></td>
                        </tr>
                        <tr>
                            <td class="kv-label">Core JSON Usado</td>
                            <td class="kv-value">
                                <?php if ($coreJsonUsed): ?>
                                    <span style="color:var(--success)">SÍ</span> (<code><?= htmlspecialchars(basename($coreJsonPath)) ?></code>)
                                <?php else: ?>
                                    <span style="color:var(--danger)">NO (No encontrado / No legible)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="kv-label">Ancho Resuelto (width_cm)</td>
                            <td class="kv-value highlight-val"><?= $width ?> cm</td>
                        </tr>
                        <tr>
                            <td class="kv-label">Alto Resuelto (height_cm)</td>
                            <td class="kv-value highlight-val"><?= $height ?> cm</td>
                        </tr>
                        <tr>
                            <td class="kv-label">Grosor Bastidor (depth_cm)</td>
                            <td class="kv-value highlight-val"><?= $depth ?> cm</td>
                        </tr>
                        <tr>
                            <td class="kv-label">Orientation (Resolution)</td>
                            <td class="kv-value"><?= htmlspecialchars($orientation) ?></td>
                        </tr>
                        <tr>
                            <td class="kv-label">Reference Artwork (File)</td>
                            <td class="kv-value"><code><?= htmlspecialchars($selectedArtwork['root_file'] ?? '') ?></code></td>
                        </tr>
                    </table>
                </div>

                <div>
                    <h2 class="card-title">
                        3. DETALLES ORIGINALES (mockup_contexts)
                        <span class="card-badge">Proposal Proposal</span>
                    </h2>
                    <table class="kv-table">
                        <tr>
                            <td class="kv-label">Nombre del Contexto</td>
                            <td class="kv-value highlight-val"><?= htmlspecialchars($selectedContext['context_name'] ?? 'N/A') ?></td>
                        </tr>
                        <tr>
                            <td class="kv-label">Tipo de Espacio</td>
                            <td class="kv-value"><?= htmlspecialchars($contextJson['space_type'] ?? 'N/A') ?></td>
                        </tr>
                        <tr>
                            <td class="kv-label">Atmosphere</td>
                            <td class="kv-value"><?= htmlspecialchars($contextJson['atmosphere'] ?? 'N/A') ?></td>
                        </tr>
                        <tr>
                            <td class="kv-label">Camera Produced (Gemini)</td>
                            <td class="kv-value"><?= htmlspecialchars($contextJson['camera_view_original'] ?? $contextJson['camera_view'] ?? $contextJson['camera_angle'] ?? 'N/A') ?></td>
                        </tr>
                        <tr>
                            <td class="kv-label">Final Assigned Camera</td>
                            <td class="kv-value highlight-val"><?= htmlspecialchars($fields['camera_view']) ?></td>
                        </tr>
                        <tr>
                            <td class="kv-label">Camera Distance</td>
                            <td class="kv-value"><?= htmlspecialchars($fields['camera_distance']) ?></td>
                        </tr>
                        <tr>
                            <td class="kv-label">Notas de Ángulo</td>
                            <td class="kv-value"><?= htmlspecialchars($fields['camera_angle_notes']) ?></td>
                        </tr>
                        <tr>
                            <td class="kv-label">Presencia Humana</td>
                            <td class="kv-value"><?= htmlspecialchars($contextJson['human_presence'] ?? 'N/A') ?></td>
                        </tr>
                        <tr>
                            <td class="kv-label">Curatorial Rationale</td>
                            <td class="kv-value" style="font-size:12.5px; font-family:var(--font-sans);"><?= htmlspecialchars($contextJson['curatorial_reason'] ?? 'N/A') ?></td>
                        </tr>
                        <tr>
                            <td class="kv-label">Commercial Rationale</td>
                            <td class="kv-value" style="font-size:12.5px; font-family:var(--font-sans);"><?= htmlspecialchars($contextJson['commercial_reason'] ?? 'N/A') ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- COLUMNA DERECHA: PROMPT COMPUESTO / ALERTA Y VERTEX PAYLOAD -->
            <div class="audit-card">
                <div>
                    <h2 class="card-title">
                        4. PROMPT COMPUESTO &mdash; MAPA DE ORÍGENES
                        <span class="card-badge">Composer View</span>
                    </h2>
                    
                    <div class="legend-bar">
                        <div class="legend-item">
                            <span class="legend-color color-admin"></span>
                            <span>ADMIN Master (Purple)</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-color color-contexts"></span>
                            <span>mockup_contexts (Green)</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-color color-core"></span>
                            <span>Core JSON / DB Size (Blue)</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-color color-composer"></span>
                            <span>Composer Injected (Red)</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-color color-legacy"></span>
                            <span>Legacy Rule (Orange)</span>
                        </div>
                    </div>

                    <div class="prompt-preview-box highlight-admin"><?= $escapedPrompt ?></div>
                </div>

                <!-- ALERTS PANEL -->
                <div class="alerts-card">
                    <h2 class="alerts-title">5. ALERTS AND FINDINGS (Invariant Audit)</h2>
                    <?php if (empty($alerts)): ?>
                        <div class="alert-item" style="border-left-color: var(--success); color: var(--success);">
                            <strong>All clear:</strong> No anomalies or critical alerts detected.
                        </div>
                    <?php else: ?>
                        <?php foreach ($alerts as $a): ?>
                            <div class="alert-item <?= $a['level'] === 'warn' ? 'alert-item-warning' : '' ?>">
                                <div class="alert-header">
                                    <span><?= htmlspecialchars($a['title']) ?></span>
                                    <span class="alert-badge <?= $a['level'] === 'critical' ? 'badge-alert-critical' : 'badge-alert-warn' ?>">
                                        <?= htmlspecialchars($a['level']) ?>
                                    </span>
                                </div>
                                <div class="alert-desc"><?= htmlspecialchars($a['desc']) ?></div>
                                <div style="margin-top: 6px;">
                                    <span class="alert-code">Target: <?= htmlspecialchars($a['code']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- PAYLOAD HACIA VERTEX -->
                <div class="audit-card payload-card">
                    <h2 class="card-title payload-title">6. PAYLOAD Y CONFIGURACIÓN DE VERTEX BRIDGE</h2>
                    <table class="kv-table">
                        <tr>
                            <td class="kv-label">Modelo Activo (gemini_image_model)</td>
                            <td class="kv-value highlight-val"><?= htmlspecialchars($envVars['GEMINI_IMAGE_MODEL']) ?></td>
                        </tr>
                        <tr>
                            <td class="kv-label">Image Provider (IMAGE_PROVIDER)</td>
                            <td class="kv-value"><code><?= htmlspecialchars($envVars['IMAGE_PROVIDER']) ?></code></td>
                        </tr>
                        <tr>
                            <td class="kv-label">MOCKUP_PROMPT_FIRST_MODE</td>
                            <td class="kv-value">
                                <span class="tag-read-only" style="background: <?= $envVars['MOCKUP_PROMPT_FIRST_MODE'] ? 'var(--danger-soft); color: var(--danger); border-color: var(--danger);' : 'var(--line); color: var(--muted); border-color: var(--line);' ?>">
                                    <?= $envVars['MOCKUP_PROMPT_FIRST_MODE'] ? 'ACTIVE (Disables Precomposition)' : 'INACTIVE' ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="kv-label">MOCKUP_PROMPT_FIRST_NO_MASK_MODE</td>
                            <td class="kv-value">
                                <span class="tag-read-only" style="background: <?= $envVars['MOCKUP_PROMPT_FIRST_NO_MASK_MODE'] ? 'var(--danger-soft); color: var(--danger); border-color: var(--danger);' : 'var(--line); color: var(--muted); border-color: var(--line);' ?>">
                                    <?= $envVars['MOCKUP_PROMPT_FIRST_NO_MASK_MODE'] ? 'ACTIVE (Disables Inpainting and Mask)' : 'INACTIVE' ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="kv-label">MOCKUP_USE_PRECOMPOSITION</td>
                            <td class="kv-value">
                                <span class="tag-read-only" style="background: <?= $envVars['MOCKUP_USE_PRECOMPOSITION'] && !$envVars['MOCKUP_PROMPT_FIRST_MODE'] ? 'var(--success-soft); color: var(--success); border-color: var(--success);' : 'var(--danger-soft); color: var(--danger); border-color: var(--danger);' ?>">
                                    <?= $envVars['MOCKUP_USE_PRECOMPOSITION'] && !$envVars['MOCKUP_PROMPT_FIRST_MODE'] ? 'ACTIVE (Applies warping and physical scale)' : 'DISABLED OR OVERRIDDEN' ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="kv-label">MOCKUP_USE_BACKGROUND_EDIT</td>
                            <td class="kv-value">
                                <span class="tag-read-only" style="background: <?= $envVars['MOCKUP_USE_BACKGROUND_EDIT'] && !$envVars['MOCKUP_PROMPT_FIRST_NO_MASK_MODE'] ? 'var(--success-soft); color: var(--success); border-color: var(--success);' : 'var(--danger-soft); color: var(--danger); border-color: var(--danger);' ?>">
                                    <?= $envVars['MOCKUP_USE_BACKGROUND_EDIT'] && !$envVars['MOCKUP_PROMPT_FIRST_NO_MASK_MODE'] ? 'ACTIVE (Protects the artwork with a mask)' : 'DISABLED OR OVERRIDDEN' ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="kv-label">Reference Image Used</td>
                            <td class="kv-value">
                                <code>results/<?= htmlspecialchars(basename($selectedArtwork['root_file'] ?? '')) ?></code>
                            </td>
                        </tr>
                        <tr>
                            <td class="kv-label">Vertex Execution Mode</td>
                            <td class="kv-value highlight-val">
                                <?php
                                if ($envVars['MOCKUP_PROMPT_FIRST_NO_MASK_MODE'] && $envVars['MOCKUP_PROMPT_FIRST_MODE']) {
                                    echo "multimodal generate_content (SubjectReferenceImage - NO INPAINTING)";
                                } else {
                                    echo "edit_image (Image 3 - INPAINTING / INSERTION MASK)";
                                }
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
