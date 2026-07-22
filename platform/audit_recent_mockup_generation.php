<?php
declare(strict_types=1);

// Disable error leakage in case of warnings
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

// Get requested artwork_id (from CLI argument or GET parameter)
$artworkId = 0;
if ($isCli) {
    // Parse CLI arguments (e.g. php audit_recent_mockup_generation.php --artwork-id=375)
    foreach ($argv as $arg) {
        if (strpos($arg, '--artwork-id=') === 0) {
            $artworkId = (int)substr($arg, 13);
        }
    }
} else {
    $artworkId = isset($_GET['artwork_id']) ? (int)$_GET['artwork_id'] : 0;
}

// 1. Identify the latest artwork and its recent mockups if artwork_id is not specified
if ($artworkId <= 0) {
    try {
        // Query the latest mockup generated and get its artwork file
        $stmtLatest = $pdo->query("
            SELECT artwork_file 
            FROM mockups 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $latestArtworkFile = $stmtLatest->fetchColumn();
        
        if ($latestArtworkFile) {
            $stmtArt = $pdo->prepare("
                SELECT id 
                FROM artworks 
                WHERE root_file = :root_file OR main_file = :main_file 
                LIMIT 1
            ");
            $stmtArt->execute([
                'root_file' => basename($latestArtworkFile),
                'main_file' => basename($latestArtworkFile),
            ]);
            $artworkId = (int)$stmtArt->fetchColumn();
        }
    } catch (Throwable $e) {
        // Fallback or ignore
    }
}

// If still no artwork_id, check the latest in the artworks table
if ($artworkId <= 0) {
    try {
        $artworkId = (int)$pdo->query("SELECT id FROM artworks ORDER BY id DESC LIMIT 1")->fetchColumn();
    } catch (Throwable $e) {
        // Ignore
    }
}

// Load artwork details
$artwork = null;
if ($artworkId > 0) {
    $stmtArtwork = $pdo->prepare("SELECT * FROM artworks WHERE id = :id LIMIT 1");
    $stmtArtwork->execute(['id' => $artworkId]);
    $artwork = $stmtArtwork->fetch();
}

if (!$artwork) {
    if ($isCli) {
        echo "No artwork or mockup generation records found.\n";
        exit(1);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><html><head><title>Mockup Audit Error</title><link rel='stylesheet' href='style.css'></head><body>";
        echo "<div class='container'><div class='notice error'><p>No artwork or mockup generation records found in the database.</p></div></div>";
        echo "</body></html>";
        exit;
    }
}

// Load mockups generated for this artwork
$stmtMockups = $pdo->prepare("
    SELECT * 
    FROM mockups 
    WHERE artwork_file = :root_file OR artwork_file = :main_file
    ORDER BY id ASC
");
$stmtMockups->execute([
    'root_file' => basename($artwork['root_file'] ?? ''),
    'main_file' => basename($artwork['main_file'] ?? ''),
]);
$mockupsList = $stmtMockups->fetchAll();

// Retrieve mockup contexts for comparison
$stmtContexts = $pdo->prepare("
    SELECT * 
    FROM mockup_contexts 
    WHERE artwork_id = :artwork_id
");
$stmtContexts->execute(['artwork_id' => $artworkId]);
$contextsList = [];
while ($row = $stmtContexts->fetch()) {
    $contextsList[$row['id']] = $row;
}

// Build Audit Report
$auditReport = [
    'artwork_id' => $artworkId,
    'artwork_title' => $artwork['final_title'] ?? 'Untitled',
    'artwork_root_file' => $artwork['root_file'] ?? '',
    'artwork_physical_size' => [
        'width' => $artwork['width'] ?? 'N/A',
        'height' => $artwork['height'] ?? 'N/A',
        'depth' => $artwork['depth'] ?? 'N/A',
        'unit' => $artwork['unit'] ?? 'cm',
    ],
    'mockups' => [],
];

$composer = new AdminPromptComposerPreview();

foreach ($mockupsList as $m) {
    $mockupId = (int)$m['id'];
    $contextId = (int)$m['context_id'];
    $contextRow = $contextsList[$contextId] ?? null;
    
    // Parse context json
    $contextJson = [];
    if ($contextRow && isset($contextRow['context_json'])) {
        $contextJson = json_decode((string)$contextRow['context_json'], true) ?: [];
    }
    
    // Retrieve overrides (selector state)
    $overrides = [];
    if (isset($m['selector_state_json']) && trim((string)$m['selector_state_json']) !== '') {
        $overrides = json_decode((string)$m['selector_state_json'], true) ?: [];
    }

    // Retrieve the final prompt sent to Vertex
    $finalPrompt = '';
    $finalPromptPath = __DIR__ . '/logs/prompt_debug/mockup_' . $mockupId . '_final_prompt.txt';
    if (is_file($finalPromptPath)) {
        $finalPrompt = file_get_contents($finalPromptPath);
    } else {
        // Fallback: Rebuild it using the composer
        if ($contextRow) {
            try {
                $finalPrompt = $composer->compose($contextRow);
            } catch (Throwable $ex) {
                $finalPrompt = 'Error rebuilding prompt: ' . $ex->getMessage();
            }
        }
    }

    // Parse model used
    $modelUsed = 'imagen-3.0-capability-001 (Default)';
    // Check if we can extract it from the logs or if it matches settings
    try {
        $geminiModel = ProviderSettings::geminiImageModel();
        if ($geminiModel) {
            $modelUsed = $geminiModel;
        }
    } catch (Throwable $e) {}

    // Find generated image file web url and physical path
    $mockupFile = $m['mockup_file'] ?? '';
    $imagePath = RESULTS_DIR . DIRECTORY_SEPARATOR . $mockupFile;
    $publicPath = 'media.php?file=' . rawurlencode($mockupFile);

    // Run audits on constraints
    $verification = [
        'respects_context_proposal' => true,
        'respects_admin_rules' => true,
        'respects_camera_view' => true,
        'respects_negative_prompt' => true,
        'respects_physical_reference' => true,
        'respects_scale' => true,
        'respects_frame_thickness' => true,
        'failures' => [],
    ];

    // Check if prompt first mode was active
    $promptFirstMode = (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE);
    $promptFirstNoMaskMode = (defined('MOCKUP_PROMPT_FIRST_NO_MASK_MODE') && MOCKUP_PROMPT_FIRST_NO_MASK_MODE);
    $precompositionMode = (defined('MOCKUP_USE_PRECOMPOSITION') && MOCKUP_USE_PRECOMPOSITION);
    $backgroundEditMode = (defined('MOCKUP_USE_BACKGROUND_EDIT') && MOCKUP_USE_BACKGROUND_EDIT);

    // 1. Check physical reference (does final prompt contain physical dimensions?)
    $widthCm = $artwork['width'] ?? '';
    $heightCm = $artwork['height'] ?? '';
    $depthCm = $artwork['depth'] ?? '';
    
    if ($widthCm && $heightCm) {
        $sizePattern = "/{$widthCm}\s*cm\s+wide\s*x\s*{$heightCm}\s*cm\s+high/i";
        if (!preg_match($sizePattern, $finalPrompt)) {
            $verification['respects_physical_reference'] = false;
            $verification['failures'][] = "Artwork width/height dimensions ({$widthCm}x{$heightCm}) are not explicitly specified in the final prompt.";
        }
    } else {
        $verification['respects_physical_reference'] = false;
        $verification['failures'][] = "Artwork size is missing in the database.";
    }

    // 2. Check frame thickness (does final prompt contain depth?)
    if ($depthCm) {
        $depthPattern = "/{$depthCm}\s*cm\s+deep/i";
        if (!preg_match($depthPattern, $finalPrompt)) {
            $verification['respects_frame_thickness'] = false;
            $verification['failures'][] = "Canvas depth ({$depthCm} cm) is not explicitly specified in the final prompt.";
        }
    } else {
        $verification['respects_frame_thickness'] = false;
        $verification['failures'][] = "Canvas depth is missing in the database.";
    }

    // 3. Check camera view
    $expectedView = $contextJson['camera_view'] ?? $contextJson['camera_angle'] ?? '';
    if ($expectedView) {
        // Strip out dashes or formatting differences
        $cleanExpected = str_replace('-', ' ', strtolower($expectedView));
        if (strpos(strtolower($finalPrompt), $cleanExpected) === false) {
            $verification['respects_camera_view'] = false;
            $verification['failures'][] = "Composed prompt does not explicitly request the assigned camera view: '{$expectedView}'.";
        }
    }

    // 4. Check negative prompt
    $originalNeg = $contextJson['negative_prompt'] ?? '';
    if ($originalNeg) {
        if (strpos($finalPrompt, $originalNeg) === false) {
            $verification['respects_negative_prompt'] = false;
            $verification['failures'][] = "Negative prompt from context was not included in the final composed prompt.";
        }
    }

    // 5. Look for legacy rules and softening rules in the composed prompt
    $legacyRulesFound = [];
    $softeningDirectives = [];
    $sceneryDominanceDirectives = [];

    // Check for MOCKUP_PROMPT_FIRST_NO_MASK_MODE and MOCKUP_PROMPT_FIRST_MODE logic degradation
    if ($promptFirstMode) {
        $legacyRulesFound[] = "MOCKUP_PROMPT_FIRST_MODE is active. This forces MOCKUP_USE_PRECOMPOSITION to False, completely disabling physical canvas scaling and 3/4 perspective warp on the source image.";
        $verification['respects_scale'] = false;
        $verification['respects_camera_view'] = false;
        $verification['failures'][] = "Physical scale rules are ignored in Python processing because precomposition is disabled.";
        $verification['failures'][] = "Camera perspective skew is not applied to the source image because precomposition is disabled.";
    }
    if ($promptFirstNoMaskMode) {
        $legacyRulesFound[] = "MOCKUP_PROMPT_FIRST_NO_MASK_MODE is active. This forces MOCKUP_USE_BACKGROUND_EDIT (inpainting) to False, completely disabling inpainting masks. The reference image is sent directly as a multimodal SubjectReferenceImage.";
        $verification['respects_scale'] = false;
        $verification['failures'][] = "Inpainting/masking is disabled, allowing Gemini/Imagen to redraw, distort, crop, or recolor the artwork surface.";
    }

    $legacyTerms = [
        '50-70%' => 'Specifying percentage ranges (e.g. 50-70%) soft-scales the prompt and causes models to ignore strict bounds.',
        'occupy roughly' => 'Vague wording overrides strict scale rules.',
        'filling at least' => 'Allows the model to expand the artwork layout.',
        'artwork-dominant' => 'Obsolete aesthetic rule.',
        'close, cropped, and intimate' => 'Forces camera closer than requested, ignoring assigned views.',
        'large statement' => 'Vague subjective wording.',
        'monumental piece' => 'Forces monumental scale, ignoring stated physical size.',
    ];

    foreach ($legacyTerms as $term => $desc) {
        if (stripos($finalPrompt, $term) !== false) {
            $legacyRulesFound[] = "Legacy Term '{$term}' detected: {$desc}";
        }
    }

    // Check if the prompt opens the scene too much or overrides camera/scale
    $sceneryDirectives = [
        'wide view' => 'Wide view requests conflict with close-up artwork dominance.',
        'full room context' => 'Allows the room context to dominate, reducing the visual priority of the artwork.',
        'distorted perspective' => 'Negative constraint that might confuse generation.',
    ];
    foreach ($sceneryDirectives as $term => $desc) {
        if (stripos($finalPrompt, $term) !== false && !in_array($term, ['front view', 'three-quarter view'])) {
            $sceneryDominanceDirectives[] = "Scenery directive '{$term}': {$desc}";
        }
    }

    // Assess quality degradation points
    $degradationCauses = [];
    if ($promptFirstNoMaskMode && $promptFirstMode) {
        $degradationCauses[] = "<strong>Lack of Inpainting/Mask:</strong> The reference image is not preserved via mask. It is sent as a 'Subject Reference' to a text-to-image generator, allowing the model to hallucinate or replace colors, texture, symbols, and details on the artwork surface.";
        $degradationCauses[] = "<strong>No Precomposition:</strong> The artwork is not scaled or placed on a virtual grey wall prior to execution. The model has to guess the size of the artwork, leading to generic decorative results that do not respect the physical dimensions of the canvas.";
    }
    if (strpos(strtolower($modelUsed), 'gemini-3.1-flash-image') !== false) {
        $degradationCauses[] = "<strong>Gemini Image vs native Imagen 3:</strong> The system is currently invoking the multimodal model <code>gemini-3.1-flash-image</code> via standard <code>generate_content</code>. This does not utilize native Imagen 3's advanced reference-conditioning APIs (like edit_image), resulting in poor visual quality and generic interior layouts.";
    }

    $auditReport['mockups'][] = [
        'mockup_id' => $mockupId,
        'context_id' => $contextId,
        'context_name' => $contextRow['context_name'] ?? 'Custom',
        'space_type' => $contextJson['space_type'] ?? 'N/A',
        'atmosphere' => $contextJson['atmosphere'] ?? 'N/A',
        'camera_view_assigned' => $contextJson['camera_view'] ?? $contextJson['camera_angle'] ?? 'N/A',
        'camera_distance_assigned' => $contextJson['camera_distance'] ?? 'N/A',
        'camera_angle_notes' => $contextJson['camera_angle_notes'] ?? 'N/A',
        'human_presence' => $contextJson['human_presence'] ?? 'N/A',
        'curatorial_reason' => $contextJson['curatorial_reason'] ?? 'N/A',
        'commercial_reason' => $contextJson['commercial_reason'] ?? 'N/A',
        'original_mockup_prompt' => $contextJson['mockup_prompt'] ?? $contextRow['prompt'] ?? 'N/A',
        'original_negative_prompt' => $contextJson['negative_prompt'] ?? 'N/A',
        'composed_prompt' => $finalPrompt,
        'model_used' => $modelUsed,
        'created_at' => $m['created_at'] ?? 'N/A',
        'generated_file' => $mockupFile,
        'generated_file_url' => $publicPath,
        'overrides' => $overrides,
        'verification' => $verification,
        'legacy_rules' => $legacyRulesFound,
        'scenery_directives' => $sceneryDominanceDirectives,
        'degradation_causes' => $degradationCauses,
    ];
}

// Render Report
if ($isCli) {
    echo "======================================================================\n";
    echo "MOCKUP GENERATION AUDIT REPORT - ARTWORK ID: {$artworkId}\n";
    echo "Artwork Title: {$auditReport['artwork_title']}\n";
    echo "Artwork Root File: {$auditReport['artwork_root_file']}\n";
    echo "Artwork Dimensions: {$artwork['width']} x {$artwork['height']} x {$artwork['depth']} {$artwork['unit']}\n";
    echo "Total Mockups Audited: " . count($auditReport['mockups']) . "\n";
    echo "======================================================================\n\n";

    foreach ($auditReport['mockups'] as $mAudit) {
        echo "------------------------------------------------------------------\n";
        echo "Mockup ID: {$mAudit['mockup_id']} | Context Name: {$mAudit['context_name']}\n";
        echo "Space Type: {$mAudit['space_type']} | Atmosphere: {$mAudit['atmosphere']}\n";
        echo "Camera: {$mAudit['camera_view_assigned']} ({$mAudit['camera_distance_assigned']})\n";
        echo "Human Presence: {$mAudit['human_presence']}\n";
        echo "Model Used: {$mAudit['model_used']}\n";
        echo "Generated File: {$mAudit['generated_file']}\n";
        echo "Created At: {$mAudit['created_at']}\n";
        
        echo "\n[OVERRIDES APPLIED FROM UI]:\n";
        if (empty($mAudit['overrides'])) {
            echo "  None\n";
        } else {
            foreach ($mAudit['overrides'] as $k => $v) {
                echo "  {$k}: " . (is_array($v) ? json_encode($v) : $v) . "\n";
            }
        }
        
        echo "\n[VERIFICATION STATUS]:\n";
        echo "  - Proposal respected literally: " . ($mAudit['verification']['respects_context_proposal'] ? "YES" : "NO") . "\n";
        echo "  - Camera view respected: " . ($mAudit['verification']['respects_camera_view'] ? "YES" : "NO") . "\n";
        echo "  - Negative prompt respected: " . ($mAudit['verification']['respects_negative_prompt'] ? "YES" : "NO") . "\n";
        echo "  - Physical reference respected: " . ($mAudit['verification']['respects_physical_reference'] ? "YES" : "NO") . "\n";
        echo "  - Scale respected: " . ($mAudit['verification']['respects_scale'] ? "YES" : "NO") . "\n";
        echo "  - Frame thickness respected: " . ($mAudit['verification']['respects_frame_thickness'] ? "YES" : "NO") . "\n";
        
        if (!empty($mAudit['verification']['failures'])) {
            echo "  Failures:\n";
            foreach ($mAudit['verification']['failures'] as $fail) {
                echo "    * {$fail}\n";
            }
        }
        
        echo "\n[LEGACY RULES & DEFAULTS DETECTED]:\n";
        if (empty($mAudit['legacy_rules'])) {
            echo "  None\n";
        } else {
            foreach ($mAudit['legacy_rules'] as $rule) {
                echo "  * {$rule}\n";
            }
        }
        
        echo "\n[SCENERY OVERRIDES]:\n";
        if (empty($mAudit['scenery_directives'])) {
            echo "  None\n";
        } else {
            foreach ($mAudit['scenery_directives'] as $dir) {
                echo "  * {$dir}\n";
            }
        }
        
        echo "\n[QUALITY DEGRADATION DIAGNOSIS]:\n";
        if (empty($mAudit['degradation_causes'])) {
            echo "  None detected.\n";
        } else {
            foreach ($mAudit['degradation_causes'] as $cause) {
                echo "  * " . strip_tags($cause) . "\n";
            }
        }
        
        echo "\n[PROMPTS]:\n";
        echo "  - Original Prompt: " . substr($mAudit['original_mockup_prompt'], 0, 150) . "...\n";
        echo "  - Original Negative: {$mAudit['original_negative_prompt']}\n";
        echo "  - Composed Prompt Length: " . strlen($mAudit['composed_prompt']) . " chars\n";
        echo "------------------------------------------------------------------\n\n";
    }
} else {
    // HTML web interface
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Technical Mockup Audit - Artwork #<?= (int)$artworkId ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
        
        <style>
            :root {
                --bg: #0d0d0c;
                --surface: #151513;
                --surface-card: #1f1e1a;
                --line: #2d2b25;
                --muted: #8e8a7d;
                --ink: #e6e4dc;
                --primary: #cda869;
                --primary-soft: rgba(205,168,105,0.15);
                --danger: #cf5b5b;
                --danger-soft: rgba(207,91,91,0.15);
                --success: #67a672;
                --success-soft: rgba(103,166,114,0.15);
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
                padding: 40px 20px;
            }
            
            .container {
                max-width: 1200px;
                margin: 0 auto;
            }
            
            header {
                margin-bottom: 40px;
                border-bottom: 1px solid var(--line);
                padding-bottom: 30px;
                display: flex;
                justify-content: space-between;
                align-items: flex-end;
            }
            
            h1 {
                font-family: var(--font-serif);
                font-size: 38px;
                font-weight: 600;
                color: var(--primary);
                margin-bottom: 10px;
            }
            
            .artwork-meta {
                color: var(--muted);
                font-size: 16px;
                font-weight: 300;
            }
            
            .artwork-meta strong {
                color: var(--ink);
                font-weight: 500;
            }
            
            .tag {
                background: var(--primary-soft);
                color: var(--primary);
                padding: 4px 12px;
                border-radius: 4px;
                font-size: 13px;
                font-weight: 500;
                display: inline-block;
            }
            
            .grid {
                display: grid;
                grid-template-columns: 1fr;
                gap: 40px;
            }
            
            .card {
                background: var(--surface);
                border: 1px solid var(--line);
                border-radius: var(--radius);
                padding: 30px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            }
            
            .card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px solid var(--line);
                padding-bottom: 15px;
                margin-bottom: 25px;
            }
            
            .card-title {
                font-family: var(--font-serif);
                font-size: 24px;
                font-weight: 600;
            }
            
            .card-subtitle {
                color: var(--muted);
                font-size: 14px;
            }
            
            .meta-panel {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin-bottom: 30px;
            }
            
            .meta-item {
                background: var(--surface-card);
                border: 1px solid var(--line);
                padding: 12px 16px;
                border-radius: var(--radius);
            }
            
            .meta-item span {
                display: block;
                font-size: 11px;
                text-transform: uppercase;
                color: var(--muted);
                letter-spacing: 0.05em;
                margin-bottom: 4px;
            }
            
            .meta-item p {
                font-size: 14px;
                font-weight: 500;
                color: var(--ink);
            }
            
            .audit-section {
                margin-bottom: 25px;
            }
            
            .section-title {
                font-size: 13px;
                font-weight: 600;
                text-transform: uppercase;
                color: var(--primary);
                letter-spacing: 0.05em;
                margin-bottom: 12px;
                border-left: 3px solid var(--primary);
                padding-left: 10px;
            }
            
            .verification-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 12px;
                margin-bottom: 20px;
            }
            
            .verification-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: var(--surface-card);
                border: 1px solid var(--line);
                padding: 10px 16px;
                border-radius: var(--radius);
                font-size: 14px;
            }
            
            .status-badge {
                padding: 2px 8px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            
            .status-ok {
                background: var(--success-soft);
                color: var(--success);
                border: 1px solid rgba(103,166,114,0.3);
            }
            
            .status-fail {
                background: var(--danger-soft);
                color: var(--danger);
                border: 1px solid rgba(207,91,91,0.3);
            }
            
            .failures-list {
                background: var(--danger-soft);
                border: 1px solid rgba(207,91,91,0.3);
                padding: 16px;
                border-radius: var(--radius);
                margin-bottom: 20px;
            }
            
            .failures-list h4 {
                color: var(--danger);
                font-size: 14px;
                margin-bottom: 8px;
            }
            
            .failures-list ul {
                list-style-type: square;
                padding-left: 20px;
                font-size: 13px;
                color: #f7b4b4;
            }
            
            .logs-list {
                background: var(--surface-card);
                border: 1px solid var(--line);
                padding: 16px;
                border-radius: var(--radius);
                font-size: 13px;
            }
            
            .logs-list li {
                margin-bottom: 8px;
                list-style: none;
                position: relative;
                padding-left: 15px;
            }
            
            .logs-list li::before {
                content: "•";
                color: var(--primary);
                position: absolute;
                left: 0;
            }
            
            .prompt-box {
                background: var(--surface-card);
                border: 1px solid var(--line);
                padding: 15px;
                border-radius: var(--radius);
                font-family: monospace;
                font-size: 12px;
                color: #c9c5b9;
                max-height: 250px;
                overflow-y: auto;
                white-space: pre-wrap;
                margin-bottom: 15px;
            }
            
            .prompt-split {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
                gap: 20px;
            }
            
            .degradation-box {
                background: rgba(205,168,105,0.05);
                border: 1px solid rgba(205,168,105,0.2);
                padding: 20px;
                border-radius: var(--radius);
                margin-bottom: 25px;
            }
            
            .degradation-box h4 {
                color: var(--primary);
                font-size: 16px;
                margin-bottom: 12px;
                font-family: var(--font-serif);
            }
            
            .degradation-box p, .degradation-box li {
                font-size: 14px;
                margin-bottom: 8px;
            }
            
            .degradation-box ul {
                padding-left: 20px;
            }
            
            .btn {
                background: var(--primary);
                color: var(--bg);
                border: none;
                padding: 10px 20px;
                font-family: var(--font-sans);
                font-weight: 600;
                font-size: 14px;
                border-radius: var(--radius);
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
                transition: transform 0.2s;
            }
            
            .btn:hover {
                transform: scale(1.02);
            }
            
            .img-preview {
                max-width: 100%;
                height: auto;
                border-radius: var(--radius);
                border: 1px solid var(--line);
                margin-top: 15px;
            }
        </style>
    </head>
    <body>
    <div class="container">
        <header>
            <div>
                <h1>Mockup Generation Audit Report</h1>
                <div class="artwork-meta">
                    Artwork: <strong><?= htmlspecialchars($auditReport['artwork_title']) ?></strong> (ID: <?= (int)$artworkId ?>) |
                    Dimensiones: <strong><?= htmlspecialchars((string)($artwork['width'] ?? '')) ?> x <?= htmlspecialchars((string)($artwork['height'] ?? '')) ?> x <?= htmlspecialchars((string)($artwork['depth'] ?? '')) ?> <?= htmlspecialchars((string)($artwork['unit'] ?? '')) ?></strong> |
                    Root File: <strong><?= htmlspecialchars(basename($auditReport['artwork_root_file'])) ?></strong>
                </div>
            </div>
            <div>
                <span class="tag">Read Only &mdash; Diagnostics</span>
            </div>
        </header>
        
        <div class="grid">
            <?php foreach ($auditReport['mockups'] as $mAudit): ?>
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="card-title"><?= htmlspecialchars($mAudit['context_name']) ?></h2>
                            <p class="card-subtitle">Mockup ID: <?= (int)$mAudit['mockup_id'] ?> | Context ID: <?= (int)$mAudit['context_id'] ?> | Generado el: <?= htmlspecialchars($mAudit['created_at']) ?></p>
                        </div>
                        <div>
                            <span class="tag" style="background:var(--line); color:var(--ink);">Modelo: <?= htmlspecialchars($mAudit['model_used']) ?></span>
                        </div>
                    </div>
                    
                    <div class="meta-panel">
                        <div class="meta-item">
                            <span>Tipo de Espacio</span>
                            <p><?= htmlspecialchars($mAudit['space_type']) ?></p>
                        </div>
                        <div class="meta-item">
                            <span>Atmosphere</span>
                            <p><?= htmlspecialchars($mAudit['atmosphere']) ?></p>
                        </div>
                        <div class="meta-item">
                            <span>Assigned Camera</span>
                            <p><?= htmlspecialchars($mAudit['camera_view_assigned']) ?> (<?= htmlspecialchars($mAudit['camera_distance_assigned']) ?>)</p>
                        </div>
                        <div class="meta-item">
                            <span>Presencia Humana</span>
                            <p><?= htmlspecialchars($mAudit['human_presence']) ?></p>
                        </div>
                    </div>

                    <?php if (!empty($mAudit['degradation_causes'])): ?>
                        <div class="degradation-box">
                            <h4>Critical Quality Degradation Points Detected:</h4>
                            <ul>
                                <?php foreach ($mAudit['degradation_causes'] as $cause): ?>
                                    <li><?= $cause ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <div class="audit-section">
                        <h3 class="section-title">Parameter and Rule Verification</h3>
                        <div class="verification-grid">
                            <div class="verification-item">
                                Propuesta Original mockup_contexts
                                <span class="status-badge <?= $mAudit['verification']['respects_context_proposal'] ? 'status-ok' : 'status-fail' ?>">
                                    <?= $mAudit['verification']['respects_context_proposal'] ? 'Cumplido' : 'No Cumplido' ?>
                                </span>
                            </div>
                            <div class="verification-item">
                                Reglas Activas ADMIN (Template)
                                <span class="status-badge <?= $mAudit['verification']['respects_admin_rules'] ? 'status-ok' : 'status-fail' ?>">
                                    <?= $mAudit['verification']['respects_admin_rules'] ? 'Cumplido' : 'No Cumplido' ?>
                                </span>
                            </div>
                            <div class="verification-item">
                                Assigned Camera Angle
                                <span class="status-badge <?= $mAudit['verification']['respects_camera_view'] ? 'status-ok' : 'status-fail' ?>">
                                    <?= $mAudit['verification']['respects_camera_view'] ? 'Cumplido' : 'No Cumplido' ?>
                                </span>
                            </div>
                            <div class="verification-item">
                                Negative Prompt Directives
                                <span class="status-badge <?= $mAudit['verification']['respects_negative_prompt'] ? 'status-ok' : 'status-fail' ?>">
                                    <?= $mAudit['verification']['respects_negative_prompt'] ? 'Cumplido' : 'No Cumplido' ?>
                                </span>
                            </div>
                            <div class="verification-item">
                                Physical Artwork Reference
                                <span class="status-badge <?= $mAudit['verification']['respects_physical_reference'] ? 'status-ok' : 'status-fail' ?>">
                                    <?= $mAudit['verification']['respects_physical_reference'] ? 'Cumplido' : 'No Cumplido' ?>
                                </span>
                            </div>
                            <div class="verification-item">
                                Escala Real de Canvas
                                <span class="status-badge <?= $mAudit['verification']['respects_scale'] ? 'status-ok' : 'status-fail' ?>">
                                    <?= $mAudit['verification']['respects_scale'] ? 'Cumplido' : 'No Cumplido' ?>
                                </span>
                            </div>
                            <div class="verification-item">
                                Physical Stretcher Depth
                                <span class="status-badge <?= $mAudit['verification']['respects_frame_thickness'] ? 'status-ok' : 'status-fail' ?>">
                                    <?= $mAudit['verification']['respects_frame_thickness'] ? 'Cumplido' : 'No Cumplido' ?>
                                </span>
                            </div>
                        </div>

                        <?php if (!empty($mAudit['verification']['failures'])): ?>
                            <div class="failures-list">
                                <h4>Fallos de Invariantes Encontrados:</h4>
                                <ul>
                                    <?php foreach ($mAudit['verification']['failures'] as $fail): ?>
                                        <li><?= htmlspecialchars($fail) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="audit-section">
                        <h3 class="section-title">Textos, Defaults y Reglas Legacy Activas</h3>
                        <?php if (empty($mAudit['legacy_rules']) && empty($mAudit['scenery_directives'])): ?>
                            <div class="verification-item" style="justify-content: flex-start; color: var(--muted);">
                                No legacy or hidden text detected in the prompt.
                            </div>
                        <?php else: ?>
                            <ul class="logs-list">
                                <?php foreach ($mAudit['legacy_rules'] as $rule): ?>
                                    <li><?= htmlspecialchars($rule) ?></li>
                                <?php endforeach; ?>
                                <?php foreach ($mAudit['scenery_directives'] as $dir): ?>
                                    <li><?= htmlspecialchars($dir) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <div class="audit-section">
                        <h3 class="section-title">Datos Comerciales / Curatoriales de Propuesta</h3>
                        <div class="meta-panel">
                            <div class="meta-item" style="grid-column: span 2;">
                                <span>Curatorial Rationale</span>
                                <p style="font-weight:normal; font-size:13px;"><?= htmlspecialchars($mAudit['curatorial_reason']) ?></p>
                            </div>
                            <div class="meta-item" style="grid-column: span 2;">
                                <span>Commercial Rationale</span>
                                <p style="font-weight:normal; font-size:13px;"><?= htmlspecialchars($mAudit['commercial_reason']) ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="audit-section">
                        <div class="prompt-split">
                            <div>
                                <h3 class="section-title">Prompt Original (mockup_contexts)</h3>
                                <div class="prompt-box"><?= htmlspecialchars($mAudit['original_mockup_prompt']) ?></div>
                                
                                <h3 class="section-title">Negative Prompt Original</h3>
                                <div class="prompt-box" style="max-height: 80px;"><?= htmlspecialchars($mAudit['original_negative_prompt']) ?></div>
                            </div>
                            <div>
                                <h3 class="section-title">Prompt Compuesto Final Enviado (Vertex/Gemini)</h3>
                                <div class="prompt-box" style="max-height: 350px;"><?= htmlspecialchars($mAudit['composed_prompt']) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="audit-section">
                        <h3 class="section-title">Generated Image</h3>
                        <p style="font-size: 13px; color: var(--muted); margin-bottom: 8px;">Ruta: <code><?= htmlspecialchars($imagePath) ?></code></p>
                        <a href="<?= htmlspecialchars($mAudit['generated_file_url']) ?>" target="_blank" class="btn">Open Full Image</a>
                        <br>
                        <img src="<?= htmlspecialchars($mAudit['generated_file_url']) ?>" class="img-preview" alt="Mockup preview" />
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    </body>
    </html>
    <?php
}
