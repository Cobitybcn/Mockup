<?php
declare(strict_types=1);

ini_set('max_execution_time', '180');
ini_set('max_input_time', '180');
ini_set('memory_limit', '512M');
ini_set('log_errors', '1');

set_time_limit(180); // punto #7: margen suficiente para timeout Python de 150 s

require_once __DIR__ . '/app/bootstrap.php';

$jsonResponseRequested = (string)($_POST['ajax'] ?? $_GET['ajax'] ?? '') === '1' ||
    str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') ||
    strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

ini_set('display_errors', $jsonResponseRequested ? '0' : '1');

if ($jsonResponseRequested) {
    ob_start();
    register_shutdown_function(static function () use (&$jsonResponseRequested): void {
        if (!$jsonResponseRequested) {
            return;
        }

        $error = error_get_last();
        if (!$error || !in_array($error['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode([
            'ok' => false,
            'error' => 'Generation failed before the server could finish the response. Details were written to the PHP error log.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    });
}

// Liberar bloqueo de sesión ya que no necesitamos escribir en $_SESSION durante la generación
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$currentUser = Auth::user();
if (!$currentUser) {
    if ($jsonResponseRequested) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'Your session expired. Please log in again and retry the mockup.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location: login.php');
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function wants_json_response(): bool
{
    global $jsonResponseRequested;

    return $jsonResponseRequested ||
        (string)($_POST['ajax'] ?? $_GET['ajax'] ?? '') === '1' ||
        str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') ||
        strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function fail_page(string $msg): void
{
    if (wants_json_response()) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => $msg,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Error</title><link rel="stylesheet" href="style.css"></head><body><div class="container">';
    echo '<div class="notice error"><p>' . h($msg) . '</p></div>';
    echo '<p><a class="button" href="javascript:history.back()">Go Back</a></p>';
    echo '</div></body></html>';
    exit;
}

function find_image(string $name): ?string
{
    $safe = basename($name);

    $paths = [
        RESULTS_DIR . DIRECTORY_SEPARATOR . $safe,
        __DIR__ . '/uploads/' . $safe,
        __DIR__ . '/' . $safe,
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function public_path_for_file(string $path): string
{
    $base = basename($path);

    if (is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $base)) {
        return 'media.php?file=' . rawurlencode($base);
    }

    if (is_file(__DIR__ . '/uploads/' . $base)) {
        return 'uploads/' . rawurlencode($base);
    }

    return rawurlencode($base);
}

function read_provider_settings_for_root(string $imagePath): array
{
    $metaPath = RESULTS_DIR . DIRECTORY_SEPARATOR . pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.meta.json';

    if (!is_file($metaPath)) {
        return [];
    }

    $data = json_decode((string)file_get_contents($metaPath), true);

    return is_array($data) && isset($data['provider_settings']) && is_array($data['provider_settings'])
        ? $data['provider_settings']
        : [];
}

function assert_root_owner(string $imagePath, array $user): void
{
    $metaPath = RESULTS_DIR . DIRECTORY_SEPARATOR . pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.meta.json';

    if (!is_file($metaPath)) {
        fail_page('No se encontro metadata de propiedad para esta obra.');
    }

    $data = json_decode((string)file_get_contents($metaPath), true);

    if (is_array($data) && (int)($data['user_id'] ?? 0) !== (int)$user['id']) {
        fail_page('No tienes acceso a esta obra.');
    }
}

function override_prompt_directives(string $prompt, ?string $camera, ?string $time, ?string $human, ?string $imagePath, ?string $json, ?string $sizeOverride = null): string
{
    if ($camera) {
        $cameraVal = match ($camera) {
            'front' => 'straight-on front view, eye-level, orthographic-like perspective, artwork dominant in frame',
            '3_4_left' => 'three-quarter view from the left, slight side angle, eye-level, natural perspective',
            '3_4_right' => 'three-quarter view from the right, slight side angle, eye-level, natural perspective',
            default => null
        };

        if ($cameraVal) {
            if (preg_match('/-\s*Camera:[^\r\n]*/i', $prompt)) {
                $prompt = preg_replace('/-\s*Camera:[^\r\n]*/i', '- Camera: ' . $cameraVal, $prompt);
            } else {
                if (preg_match('/MOCKUP ART DIRECTION:[^\r\n]*/i', $prompt)) {
                    $prompt = preg_replace('/(MOCKUP ART DIRECTION:[^\r\n]*)/i', "$1\n- Camera: " . $cameraVal, $prompt);
                } else {
                    $prompt .= "\n- Camera: " . $cameraVal;
                }
            }

            // También limpiar cualquier mención a otros ángulos en el prompt para evitar confundir a la generación de la IA
            if ($camera === 'front') {
                $prompt = str_ireplace(['three_quarter_left', 'three-quarter-left', '3/4 left', 'three_quarter_right', 'three-quarter-right', '3/4 right'], 'front', $prompt);
            } elseif ($camera === '3_4_left') {
                $prompt = str_ireplace(['three_quarter_right', 'three-quarter-right', '3/4 right'], 'three_quarter_left', $prompt);
            } elseif ($camera === '3_4_right') {
                $prompt = str_ireplace(['three_quarter_left', 'three-quarter-left', '3/4 left'], 'three_quarter_right', $prompt);
            }
        }
    }

    if ($time) {
        $lightingVal = match ($time) {
            'day' => 'luminous natural daylight, bright and clear',
            'afternoon' => 'warm afternoon light, golden hour, soft shadows',
            'night' => 'dramatic evening light, spot art lamps, nocturnal gallery ambiance (evening/night)',
            default => null
        };

        if ($lightingVal) {
            // Reemplazar la línea de directiva "- Lighting: ..." o "- lighting: ..."
            $prompt = preg_replace('/-\s*Lighting:[^\r\n]*/i', '- Lighting: ' . $lightingVal, $prompt);

            // Reemplazar términos en el resto del prompt para mantener la consistencia
            if ($time === 'day') {
                $prompt = str_ireplace(['afternoon', 'golden hour', 'evening', 'night', 'nocturnal'], 'day', $prompt);
            } elseif ($time === 'afternoon') {
                $prompt = str_ireplace(['daylight', 'evening', 'night', 'nocturnal'], 'afternoon', $prompt);
            } elseif ($time === 'night') {
                $prompt = str_ireplace(['daylight', 'afternoon', 'golden hour'], 'evening', $prompt);
            }
        }
    }

    if ($human && $imagePath) {
        $widthCm = null;
        $heightCm = null;
        $depthCm = null;

        try {
            $db = Database::connection();
            $stmtArtwork = $db->prepare("SELECT * FROM artworks WHERE root_file = :root_file LIMIT 1");
            $stmtArtwork->execute(['root_file' => basename($imagePath)]);
            $artworkRow = $stmtArtwork->fetch();
            if ($artworkRow) {
                $widthCm = $artworkRow['width'] ?? null;
                $heightCm = $artworkRow['height'] ?? null;
                $depthCm = $artworkRow['depth'] ?? null;
            }
        } catch (Throwable $e) {
            // Fallback silencioso
        }

        if ((!$widthCm || !$heightCm) && $json) {
            $safeJson = basename($json);
            $possiblePaths = [
                ANALYSIS_DIR . DIRECTORY_SEPARATOR . $safeJson,
                RESULTS_DIR . DIRECTORY_SEPARATOR . $safeJson,
            ];
            foreach ($possiblePaths as $path) {
                if (is_file($path)) {
                    $analysisData = json_decode((string)file_get_contents($path), true);
                    if (is_array($analysisData)) {
                        $widthCm = $analysisData['image']['physical_size']['width_cm'] ?? null;
                        $heightCm = $analysisData['image']['physical_size']['height_cm'] ?? null;
                        $depthCm = $analysisData['image']['physical_size']['depth_cm'] ?? null;
                        break;
                    }
                }
            }
        }

        $ctx = [
            'with_human' => ($human !== 'none'),
            'human_profile' => ($human !== 'none' ? $human : null),
        ];

        $builder = new MockPromptBuilder();
        $newHumanRule = $builder->humanRule($ctx);

        // Reemplazar la directiva de figura humana de manera segura (formato nuevo o antiguo)
        if (preg_match('/-\s*Human Figure:[^\r\n]*/i', $prompt)) {
            $prompt = preg_replace('/-\s*Human Figure:[^\r\n]*/i', '- Human Figure: ' . $newHumanRule, $prompt);
        } else {
            $prompt = preg_replace('/-\s*(Include exactly one standing|Do not include any human figure|Include exactly one person)[^\r\n]*/i', '- PLACEHOLDER_HUMAN_RULE', $prompt);
            $prompt = str_replace('PLACEHOLDER_HUMAN_RULE', $newHumanRule, $prompt);
        }

        if ($human === 'none') {
            // Eliminar palabras clave de figura humana para que vertex_bridge.py no aplique la reducción
            $prompt = str_ireplace(
                ['discreet standing', 'standing adult', 'standing human', 'scale figure'],
                ['discreet', 'adult', 'human', 'visual reference'],
                $prompt
            );
        }

        // Construir la nueva línea de SCALE RULES
        $orientation = 'unknown';
        if ($widthCm && $heightCm) {
            $orientation = ((float)$widthCm > (float)$heightCm) ? 'horizontal' : (((float)$heightCm > (float)$widthCm) ? 'vertical' : 'square');
        }

        $imageMeta = [
            'orientation' => $orientation,
            'physical_size' => [
                'width_cm' => $widthCm,
                'height_cm' => $heightCm,
                'depth_cm' => $depthCm,
            ]
        ];

        $newScaleText = $builder->scaleText($imageMeta, $ctx);
        if (preg_match('/-\s*Scale:[^\r\n]*/i', $prompt)) {
            $prompt = preg_replace('/-\s*Scale:[^\r\n]*/i', '- Scale: ' . $newScaleText, $prompt);
        } else {
            $prompt = preg_replace('/-\s*(The physical artwork measures|No physical size was provided)[^\r\n]*/i', '- PLACEHOLDER_SCALE_TEXT', $prompt);
            $prompt = str_replace('PLACEHOLDER_SCALE_TEXT', $newScaleText, $prompt);
        }
    }

    $sizePercent = normalize_size_override($sizeOverride);
    if ($sizePercent !== 0) {
        $direction = $sizePercent > 0 ? 'larger' : 'smaller';
        $absPercent = abs($sizePercent);
        $prompt .= "\n\nARTWORK SIZE CORRECTION FOR THIS REGENERATION:\n"
            . "- Make the artwork appear {$absPercent}% {$direction} than the current/default prompt scale.\n"
            . "- Keep the artwork proportions, placement realism, wall contact, canvas depth and physical believability.\n"
            . "- Apply this only to the artwork display size, not to the room, furniture, human figure, or camera angle.";
    }

    return $prompt;
}

function normalize_size_override(?string $value): int
{
    $size = (int)trim((string)$value);

    if ($size < -50 || $size > 50) {
        return 0;
    }

    return $size;
}

$image = trim((string)($_POST['image'] ?? $_GET['image'] ?? ''));
$json = trim((string)($_POST['json'] ?? $_GET['json'] ?? ''));
$contextId = trim((string)($_POST['context_id'] ?? $_GET['context_id'] ?? ''));
$prompt = trim((string)($_POST['prompt'] ?? $_GET['prompt'] ?? ''));
$cameraOverride = trim((string)($_POST['camera_override'] ?? ''));
$timeOverride = trim((string)($_POST['time_override'] ?? ''));
$humanOverride = trim((string)($_POST['human_override'] ?? ''));
$sizeOverride = trim((string)($_POST['size_override'] ?? '0'));

if ($cameraOverride !== '' || $timeOverride !== '' || $humanOverride !== '' || normalize_size_override($sizeOverride) !== 0) {
    $imagePath = find_image($image);
    if ($imagePath) {
        $prompt = override_prompt_directives($prompt, $cameraOverride, $timeOverride, $humanOverride, $imagePath, $json, $sizeOverride);
    }
}

if ($image === '' || $prompt === '') {
    fail_page('Faltan datos para generar el mockup simulado.');
}

$imagePath = find_image($image);

if (!$imagePath) {
    fail_page('No se encontro la imagen raiz: ' . $image);
}

assert_root_owner($imagePath, $currentUser);

// Add random delay to stagger concurrent requests and avoid database/API rate limit stamps
if (wants_json_response()) {
    usleep(random_int(50, 2500) * 1000); // Sleep between 50ms and 2500ms (2.5 seconds)
}

// Punto #10: descuento real de 1 crédito antes de generar.
// Si el modo es mock no se descuenta (uso en desarrollo).
$creditDeducted = false;
if (ProviderSettings::allowRealApi()) {
    if (!Database::deductCredit((int)$currentUser['id'], 'mockup_generation:' . $contextId)) {
        fail_page('No tienes créditos suficientes para generar un mockup. Contacta al administrador.');
    }
    $creditDeducted = true;
}

try {
    $pdo = Database::connection();

    $stmtArtwork = $pdo->prepare("SELECT * FROM artworks WHERE root_file = :root_file LIMIT 1");
    $stmtArtwork->execute(['root_file' => basename($imagePath)]);
    $artwork = $stmtArtwork->fetch();

    $artistName = '';
    $artworkTitle = '';
    $cameraAngle = '';

    if ($artwork) {
        $artistProfile = ArtistProfile::findForUser((int)$artwork['user_id']);
        $artistName = trim((string)($artistProfile['artist_name'] ?? ''));
        $artworkTitle = trim((string)($artwork['final_title'] ?? ''));
        if ($artworkTitle === '') {
            $artworkTitle = Display::artworkTitle($artwork['root_file']);
        }
    }

    $stmtContext = $pdo->prepare("SELECT * FROM mockup_contexts WHERE id = :id LIMIT 1");
    $stmtContext->execute(['id' => $contextId]);
    $contextRow = $stmtContext->fetch();
    $mockupContextName = '';

    if ($contextRow) {
        $mockupContextName = (string)($contextRow['context_name'] ?? '');
        $contextJson = json_decode((string)($contextRow['context_json'] ?? ''), true);
        if (is_array($contextJson)) {
            $cameraAngle = (string)($contextJson['camera_group'] ?? '');
        }
    }

    if ($cameraOverride !== '') {
        $cameraAngle = $cameraOverride;
    }

    $seoParams = [
        'artistName' => $artistName,
        'artworkTitle' => $artworkTitle,
        'mockupContext' => $mockupContextName,
        'cameraAngle' => $cameraAngle,
        'imageType' => 'mockup',
        'extension' => 'jpg',
    ];

    ProviderSettings::set(read_provider_settings_for_root($imagePath));
    $generator = ServiceFactory::mockupGenerator();
    $result = $generator->generate($imagePath, $contextId, $prompt, [
        'json' => $json,
        'seo_params' => $seoParams,
    ]);

    $mockupId = (int)Database::withBusyRetry(function () use ($currentUser, $imagePath, $result, $contextId): int {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO mockups (user_id, artwork_file, mockup_file, context_id, prompt_file, created_at)
            VALUES (:user_id, :artwork_file, :mockup_file, :context_id, :prompt_file, :created_at)
        ");
        $stmt->execute([
            'user_id' => (int)$currentUser['id'],
            'artwork_file' => basename($imagePath),
            'mockup_file' => basename((string)$result['file']),
            'context_id' => $contextId,
            'prompt_file' => basename((string)$result['prompt_file']),
            'created_at' => date('c'),
        ]);

        return (int)$pdo->lastInsertId();
    }, 24);
} catch (Throwable $e) {
    if (wants_json_response() && isset($result['file']) && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . basename((string)$result['file']))) {
        Logger::log('Mockup generated but database save failed: ' . $e->getMessage(), 'error');

        $resultUrl = 'media.php?file=' . rawurlencode((string)$result['file']);
        $promptUrl = 'media.php?file=' . rawurlencode((string)($result['prompt_file'] ?? ''));
        $rootUrl = public_path_for_file($imagePath);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'warning' => 'The mockup was generated, but it could not be saved to the gallery because the database was busy.',
            'message' => (string)($result['message'] ?? 'Mockup generated.'),
            'context_id' => $contextId,
            'mockup_id' => null,
            'image_url' => $resultUrl,
            'viewer_url' => $resultUrl,
            'download_url' => $resultUrl . '&download=1',
            'prompt_url' => $promptUrl,
            'root_url' => $rootUrl,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    // Punto #10: reembolso automático si la generación falla después de descontar.
    if ($creditDeducted) {
        try {
            Database::withBusyRetry(function () use ($currentUser, $contextId): void {
                Database::refundCredit((int)$currentUser['id'], 'mockup_generation_failed:' . $contextId);
            }, 12);
        } catch (Throwable $refundErr) {
            // Log sin interrumpir el flujo de error original
            Logger::log('Error al reembolsar crédito: ' . $refundErr->getMessage(), 'error');
        }
    }
    fail_page($e->getMessage());
}

$resultUrl = 'media.php?file=' . rawurlencode($result['file']);
$viewerUrl = isset($mockupId) && $mockupId > 0 ? 'viewer.php?id=' . $mockupId : $resultUrl;
$resultDownloadUrl = $resultUrl . '&download=1';
$rootUrl = public_path_for_file($imagePath);
$promptUrl = 'media.php?file=' . rawurlencode($result['prompt_file']);

if (wants_json_response()) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'message' => (string)$result['message'],
        'context_id' => $contextId,
        'mockup_id' => $mockupId,
        'image_url' => $resultUrl,
        'viewer_url' => $viewerUrl,
        'download_url' => $resultDownloadUrl,
        'prompt_url' => $promptUrl,
        'root_url' => $rootUrl,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Generated Mockup - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">

    <style>
        .wrap {
            max-width: 1100px;
            margin: 0 auto;
            background: var(--surface);
            padding: 32px;
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            border-radius: var(--radius);
        }

        .titlebar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 24px;
        }

        .titlebar h1 {
            margin: 0;
            font-family: var(--font-serif);
            font-size: 32px;
        }

        .titlebar .button {
            margin-top: 0;
            margin-right: 0;
            white-space: nowrap;
        }

        img {
            max-width: 100%;
            height: auto;
            display: block;
            border: 1px solid var(--line);
            margin: 20px 0;
            border-radius: var(--radius);
        }

        .actions {
            margin-top: 24px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .info {
            background: var(--surface-soft);
            padding: 14px 18px;
            border-left: 3px solid var(--accent);
            margin-bottom: 20px;
            border-radius: 0 var(--radius) var(--radius) 0;
            font-size: 14px;
        }

        textarea {
            width: 100%;
            min-height: 220px;
            box-sizing: border-box;
            padding: 12px;
            border: 1px solid var(--line);
            background: var(--surface-soft);
            font-family: monospace;
            font-size: 12px;
            line-height: 1.45;
            border-radius: var(--radius);
            color: var(--muted);
        }

        .hero-image {
            background: var(--surface-soft);
            border: 1px solid var(--line);
        }
    </style>
</head>

<body>
<div class="container wide">
<div class="wrap">
    <div class="titlebar">
        <h1>Generated Mockup</h1>
        <a class="button" href="<?= h($resultDownloadUrl) ?>">
            Download Mockup
        </a>
    </div>

    <div class="info">
        <?= h($result['message']) ?><br>
        Context: <strong><?= h($contextId ?: '-') ?></strong>
    </div>

    <img class="hero-image" src="<?= h($resultUrl) ?>" alt="Generated mockup">

    <h2>Generated Prompt</h2>
    <textarea readonly><?= h($prompt) ?></textarea>

    <div class="actions">
        <a class="button" href="<?= h($viewerUrl) ?>">
            Open Mockup
        </a>

        <a class="button" href="<?= h($resultDownloadUrl) ?>">
            Download Mockup
        </a>

        <a class="button secondary" href="<?= h($promptUrl) ?>" target="_blank">
            View Technical Prompt
        </a>

        <a class="button secondary" href="<?= h($rootUrl) ?>" target="_blank">
            View Root Image
        </a>

        <a class="button secondary" href="form2.php?image=<?= rawurlencode(basename($imagePath)) ?>&json=<?= rawurlencode($json) ?>">
            Back to Step 2
        </a>

        <a class="button secondary" href="artwork_new.php">
            Upload another artwork
        </a>

        <a class="button secondary" href="dashboard.php">
            Dashboard
        </a>
    </div>
</div>
</div>
</body>
</html>
