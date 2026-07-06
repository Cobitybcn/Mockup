<?php
// LEGACY / DO NOT USE IN PHASE 2.3 FLOW
declare(strict_types=1);

ini_set('max_execution_time', '180');
ini_set('max_input_time', '180');
ini_set('memory_limit', '512M');
ini_set('log_errors', '1');

set_time_limit(180); // punto #7: margen suficiente para timeout Python de 150 s

require_once __DIR__ . '/app/bootstrap.php';

if (!defined('LEGACY_MOCKUP_FLOW_ENABLED') || !LEGACY_MOCKUP_FLOW_ENABLED) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Legacy mockup flow disabled. Use Phase 2 reviewed mockup generation.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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

function override_prompt_directives(string $prompt, ?string $camera, ?string $time, ?string $human, ?string $imagePath, ?string $json, ?string $sizeOverride = null, ?string $distance = null): string
{
    $heightCm = null;
    if ($camera) {
        $cameraVal = match ($camera) {
            'front' => 'straight-on front view, eye-level, orthographic-like perspective',
            '3_4_left' => 'three-quarter view from the left, slight side angle, eye-level, natural perspective',
            '3_4_right' => 'three-quarter view from the right, slight side angle, eye-level, natural perspective',
            default => null
        };

        if ($cameraVal) {
            if (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE) {
                if (preg_match('/-\s*View:[^\r\n]*/i', $prompt)) {
                    $prompt = preg_replace('/-\s*View:[^\r\n]*/i', '- View: ' . $cameraVal, $prompt);
                } else {
                    $prompt = preg_replace('/(CAMERA DIRECTION:[^\r\n]*)/i', "$1\n- View: " . $cameraVal, $prompt);
                }
            } else {
                if (preg_match('/-\s*Camera:[^\r\n]*/i', $prompt)) {
                    $prompt = preg_replace('/-\s*Camera:[^\r\n]*/i', '- Camera: ' . $cameraVal, $prompt);
                } else {
                    if (preg_match('/MOCKUP ART DIRECTION:[^\r\n]*/i', $prompt)) {
                        $prompt = preg_replace('/(MOCKUP ART DIRECTION:[^\r\n]*)/i', "$1\n- Camera: " . $cameraVal, $prompt);
                    } else {
                        $prompt .= "\n- Camera: " . $cameraVal;
                    }
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
            'sunny_day' => 'luminous natural daylight, bright, sunny day, clear blue sky reflections, clean direct sunlight',
            'cloudy_day' => 'soft diffused natural daylight, overcast cloudy day, muted shadows, soft white sky reflection',
            'afternoon' => 'warm afternoon light, golden hour, long soft shadows, amber interior ambiance',
            'night' => 'dramatic evening light, spot art lamps, nocturnal gallery ambiance (evening/night), subtle warm spot lighting',
            default => null
        };

        if ($lightingVal) {
            // Reemplazar la línea de directiva "- Lighting: ..." o "- lighting: ..."
            $prompt = preg_replace('/-\s*Lighting:[^\r\n]*/i', '- Lighting: ' . $lightingVal, $prompt);

            // Reemplazar términos en el resto del prompt para mantener la consistencia
            if ($time === 'sunny_day') {
                $prompt = str_ireplace(['cloudy', 'afternoon', 'golden hour', 'evening', 'night', 'nocturnal'], 'sunny day', $prompt);
            } elseif ($time === 'cloudy_day') {
                $prompt = str_ireplace(['sunny', 'afternoon', 'golden hour', 'evening', 'night', 'nocturnal'], 'cloudy day', $prompt);
            } elseif ($time === 'afternoon') {
                $prompt = str_ireplace(['daylight', 'sunny', 'cloudy', 'evening', 'night', 'nocturnal'], 'afternoon', $prompt);
            } elseif ($time === 'night') {
                $prompt = str_ireplace(['daylight', 'sunny', 'cloudy', 'afternoon', 'golden hour'], 'evening', $prompt);
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

        $depthText = $depthCm ? " × {$depthCm} cm deep" : " × 4 cm deep";
        $humanHeightStr = ($human === 'male_180' || $human === 'male_180m') ? "1.80m" : "1.55m";
        $humanHeightCm = ($human === 'male_180' || $human === 'male_180m') ? 180 : 155;
        $genderNoun = ($human === 'male_180' || $human === 'male_180m') ? "male" : "female";
        $genderSubject = ($human === 'male_180' || $human === 'male_180m') ? "man" : "woman";
        $genderPronoun = ($human === 'male_180' || $human === 'male_180m') ? "him" : "her";
        $genderPossessive = ($human === 'male_180' || $human === 'male_180m') ? "his" : "her";

        $scaleDirective = "";
        if ($heightCm && $widthCm) {
            $pct = (int)round(($heightCm / $humanHeightCm) * 100);
            $scaleDirective = " The artwork is {$widthCm} cm wide × {$heightCm} cm high{$depthText}. The human figure is {$humanHeightStr} tall. The artwork height is {$heightCm} cm, so it must appear as approximately {$pct}% of the {$genderNoun} figure's full standing height.";
            if ($heightCm < $humanHeightCm) {
                $scaleDirective .= " The artwork must appear clearly shorter than the {$genderSubject}, not equal to {$genderPossessive} full height and not taller than {$genderPronoun}.";
            } else if ($heightCm > $humanHeightCm) {
                $scaleDirective .= " The artwork must appear taller than the {$genderSubject}'s full standing height.";
            }
        }

        $newHumanRule = 'Include exactly one standing human figure for scale reference. The full-body figure must remain completely visible from head to shoes, standing on the exact same floor plane as the artwork, positioned at a comparable depth relative to the camera (not blocking or overlapping the artwork), and placed close enough to the artwork to serve as a reliable visual scale reference to verify and audit the physical scale of the artwork.' . $scaleDirective;
        if ($human === 'none') {
            $newHumanRule = 'Do not include any human figure.';
        } elseif ($human === 'female_155' || $human === 'female_155m') {
            $newHumanRule = 'Include exactly one elegant standing female figure (1.55m tall) for scale reference. The full-body figure must remain completely visible from head to shoes, standing on the exact same floor plane as the artwork, positioned at a comparable depth relative to the camera (not blocking or overlapping the artwork), and placed close enough to the artwork to serve as a reliable visual scale reference to verify and audit the physical scale of the artwork.' . $scaleDirective;
        } elseif ($human === 'male_180' || $human === 'male_180m') {
            $newHumanRule = 'Include exactly one elegant standing male figure (1.80m tall) for scale reference. The full-body figure must remain completely visible from head to shoes, standing on the exact same floor plane as the artwork, positioned at a comparable depth relative to the camera (not blocking or overlapping the artwork), and placed close enough to the artwork to serve as a reliable visual scale reference to verify and audit the physical scale of the artwork.' . $scaleDirective;
        }

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
    }

    if ($distance) {
        if (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE) {
            $distanceVal = match ($distance) {
                'close' => 'close-up view, room only suggested',
                'medium' => 'medium-close view, enough space for context and scale',
                default => null
            };
            if ($distanceVal) {
                if (preg_match('/-\s*Distance:[^\r\n]*/i', $prompt)) {
                    $prompt = preg_replace('/-\s*Distance:[^\r\n]*/i', '- Distance: ' . $distanceVal, $prompt);
                } else {
                    $prompt = preg_replace('/(CAMERA DIRECTION:[^\r\n]*)/i', "$1\n- Distance: " . $distanceVal, $prompt);
                }
            }
        } else {
            $distanceVal = match ($distance) {
                'close' => '- Structured Camera Distance: close-up view, room only suggested',
                'medium' => '- Structured Camera Distance: medium-close view, enough space for context and scale',
                default => null
            };

            if ($distanceVal) {
                if (preg_match('/-\s*Structured Camera Distance:[^\r\n]*/i', $prompt)) {
                    $prompt = preg_replace('/-\s*Structured Camera Distance:[^\r\n]*/i', $distanceVal, $prompt);
                } else {
                    if (preg_match('/MOCKUP ART DIRECTION:[^\r\n]*/i', $prompt)) {
                        $prompt = preg_replace('/(MOCKUP ART DIRECTION:[^\r\n]*)/i', "$1\n" . $distanceVal, $prompt);
                    } else {
                        $prompt .= "\n" . $distanceVal;
                    }
                }
            }
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

    // Convert fraction ratios like 120/155 or 80/180 into explicit percentages with height context
    $prompt = preg_replace_callback(
        '#\b(\d+)\s*/\s*(155|180)(?:\s+of\s+the\s+1\.(?:55|80)m\s+(?:female\s+|male\s+)?figure\'s\s+(?:full\s+)?(?:standing\s+)?height)?\b#i',
        function ($matches) use ($heightCm) {
            $denom = (int)$matches[2];
            $num = ($heightCm !== null) ? (int)$heightCm : (int)$matches[1];
            $pct = (int)round(($num / $denom) * 100);
            if ($denom === 155) {
                return "{$pct}% of the 1.55m female figure's full standing height";
            } else {
                return "{$pct}% of the 1.80m male figure's full standing height";
            }
        },
        $prompt
    );

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

function build_selector_edit_instruction(string $camera, string $time, string $human, string $sizeOverride, string $distanceOverride, array $previousState = []): string
{
    $changes = [];
    $previousCamera = (string)($previousState['camera_override'] ?? '');
    $previousTime = (string)($previousState['time_override'] ?? '');
    $previousHuman = (string)($previousState['human_override'] ?? '');
    $previousDistance = (string)($previousState['distance_override'] ?? '');
    $previousSize = normalize_size_override((string)($previousState['size_override'] ?? '0'));
    $currentSize = normalize_size_override($sizeOverride);

    $cameraText = match ($camera) {
        'front' => 'change only the ANGULO DE CAMARA / CAMERA ANGLE to a straight front view',
        '3_4_left' => 'change only the ANGULO DE CAMARA / CAMERA ANGLE to a left three-quarter view',
        '3_4_right' => 'change only the ANGULO DE CAMARA / CAMERA ANGLE to a right three-quarter view',
        default => '',
    };

    if ($cameraText !== '' && ($previousCamera === '' || $previousCamera !== $camera)) {
        $changes[] = $cameraText;
    }

    $timeText = match ($time) {
        'sunny_day' => 'change the lighting to luminous natural sunny day light',
        'cloudy_day' => 'change the lighting to soft diffused overcast cloudy day light',
        'afternoon' => 'change the lighting to warm golden hour afternoon light',
        'night' => 'change the lighting to refined evening or night gallery light with spot lamps',
        default => '',
    };

    if ($timeText !== '' && ($previousTime === '' || $previousTime !== $time)) {
        $changes[] = $timeText;
    }

    $humanText = match ($human) {
        'none' => 'remove any human figure',
        'female_155' => 'include one discreet standing woman, 1.55 m tall, as a scale reference',
        'male_180' => 'include one discreet standing man, 1.80 m tall, as a scale reference',
        default => '',
    };

    if ($humanText !== '' && ($previousHuman === '' || $previousHuman !== $human)) {
        $changes[] = $humanText;
    }

    $distanceText = match ($distanceOverride) {
        'close' => 'change camera distance to close-up view where artwork is dominant',
        'medium' => 'change camera distance to medium-close view showing more of the context room and furniture',
        default => '',
    };

    if ($distanceText !== '' && ($previousDistance === '' || $previousDistance !== $distanceOverride)) {
        $changes[] = $distanceText;
    }

    if ($currentSize !== $previousSize) {
        $delta = $currentSize - $previousSize;
        $direction = $delta > 0 ? 'larger' : 'smaller';
        $changes[] = 'make only the artwork appear ' . abs($delta) . '% ' . $direction;
    }

    if ($changes === []) {
        $changes[] = 'make a subtle faithful refinement while preserving the current mockup';
    }

    return "Edit the provided generated mockup image. Apply only this requested change: "
        . implode('; ', $changes)
        . ". Preserve the existing artwork identity, room, composition, wall contact, shadows, furniture relationship, and premium presentation as much as possible. Do not redraw, reinterpret, crop, recolor, or alter the artwork surface.";
}

$image = trim((string)($_POST['image'] ?? $_GET['image'] ?? ''));
$json = trim((string)($_POST['json'] ?? $_GET['json'] ?? ''));
$contextId = trim((string)($_POST['context_id'] ?? $_GET['context_id'] ?? ''));
$prompt = trim((string)($_POST['prompt'] ?? $_GET['prompt'] ?? ''));
$cameraOverrideTouched = (($_POST['camera_override_touched'] ?? '') === '1');
$timeOverrideTouched = (($_POST['time_override_touched'] ?? $_POST['lighting_override_touched'] ?? '') === '1');
$humanOverrideTouched = (($_POST['human_override_touched'] ?? '') === '1');
$distanceOverrideTouched = (($_POST['distance_override_touched'] ?? '') === '1');
$sizeOverrideTouched = (($_POST['size_override_touched'] ?? '') === '1');
$anyOverrideTouched = ($cameraOverrideTouched || $timeOverrideTouched || $humanOverrideTouched || $distanceOverrideTouched || $sizeOverrideTouched);

$selectorState = [
    'camera_override' => (in_array($cameraOverride, ['front', '3_4_left', '3_4_right'], true) && (!defined('MOCKUP_PROMPT_FIRST_MODE') || !MOCKUP_PROMPT_FIRST_MODE || $cameraOverrideTouched)) ? $cameraOverride : '',
    'time_override' => (in_array($timeOverride, ['sunny_day', 'cloudy_day', 'afternoon', 'night'], true) && (!defined('MOCKUP_PROMPT_FIRST_MODE') || !MOCKUP_PROMPT_FIRST_MODE || $timeOverrideTouched)) ? $timeOverride : '',
    'human_override' => (in_array($humanOverride, ['none', 'female_155', 'male_180'], true) && (!defined('MOCKUP_PROMPT_FIRST_MODE') || !MOCKUP_PROMPT_FIRST_MODE || $humanOverrideTouched)) ? $humanOverride : '',
    'distance_override' => (in_array($distanceOverride, ['close', 'medium'], true) && (!defined('MOCKUP_PROMPT_FIRST_MODE') || !MOCKUP_PROMPT_FIRST_MODE || $distanceOverrideTouched)) ? $distanceOverride : '',
    'size_override' => (!defined('MOCKUP_PROMPT_FIRST_MODE') || !MOCKUP_PROMPT_FIRST_MODE || $sizeOverrideTouched) ? normalize_size_override($sizeOverride) : 0,
];

$shouldApplyOverrides = false;
if (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE) {
    $shouldApplyOverrides = $anyOverrideTouched;
} else {
    $shouldApplyOverrides = ($cameraOverride !== '' || $timeOverride !== '' || $humanOverride !== '' || $distanceOverride !== '' || normalize_size_override($sizeOverride) !== 0);
}

if ($currentMockupFile === '' && $shouldApplyOverrides) {
    $imagePath = find_image($image);
    if ($imagePath) {
        $prompt = override_prompt_directives(
            $prompt,
            (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE && !$cameraOverrideTouched) ? null : $cameraOverride,
            (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE && !$timeOverrideTouched) ? null : $timeOverride,
            (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE && !$humanOverrideTouched) ? null : $humanOverride,
            $imagePath,
            $json,
            (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE && !$sizeOverrideTouched) ? '0' : $sizeOverride,
            (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE && !$distanceOverrideTouched) ? null : $distanceOverride
        );
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
        fail_page('You do not have enough credits to generate a mockup. Please contact the administrator.');
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

    if ($cameraOverride !== '' && (!defined('MOCKUP_PROMPT_FIRST_MODE') || !MOCKUP_PROMPT_FIRST_MODE || $cameraOverrideTouched)) {
        $cameraAngle = $cameraOverride;
    }

    $generationImagePath = $imagePath;
    $editBaseFile = '';

    if ($currentMockupFile !== '' && $artwork) {
        $stmtExistingMockup = $pdo->prepare("
            SELECT *
            FROM mockups
            WHERE user_id = :user_id
            AND artwork_file = :artwork_file
            AND context_id = :context_id
            AND mockup_file = :mockup_file
            LIMIT 1
        ");
        $stmtExistingMockup->execute([
            'user_id' => (int)$currentUser['id'],
            'artwork_file' => basename($imagePath),
            'context_id' => $contextId,
            'mockup_file' => $currentMockupFile,
        ]);

        $existingMockupRow = $stmtExistingMockup->fetch();

        if ($existingMockupRow) {
            $existingMockupPath = find_image($currentMockupFile);
            if ($existingMockupPath && is_file($existingMockupPath)) {
                $previousSelectorState = json_decode((string)($existingMockupRow['selector_state_json'] ?? ''), true);
                $previousSelectorState = is_array($previousSelectorState) ? $previousSelectorState : [];
                $generationImagePath = $existingMockupPath;
                $editBaseFile = $currentMockupFile;
                $prompt = build_selector_edit_instruction(
                    (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE && !$cameraOverrideTouched) ? '' : $cameraOverride,
                    (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE && !$timeOverrideTouched) ? '' : $timeOverride,
                    (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE && !$humanOverrideTouched) ? '' : $humanOverride,
                    (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE && !$sizeOverrideTouched) ? '0' : $sizeOverride,
                    (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE && !$distanceOverrideTouched) ? '' : $distanceOverride,
                    $previousSelectorState
                );
            }
        }
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
    $result = $generator->generate($generationImagePath, $contextId, $prompt, [
        'json' => $json,
        'seo_params' => $seoParams,
        'root_reference_path' => $imagePath,
        'edit_base_file' => $editBaseFile,
    ]);

    $selectorStateJson = json_encode($selectorState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $mockupId = (int)Database::withBusyRetry(function () use ($currentUser, $imagePath, $result, $contextId, $selectorStateJson): int {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO mockups (user_id, artwork_file, mockup_file, context_id, prompt_file, selector_state_json, created_at)
            VALUES (:user_id, :artwork_file, :mockup_file, :context_id, :prompt_file, :selector_state_json, :created_at)
        ");
        $stmt->execute([
            'user_id' => (int)$currentUser['id'],
            'artwork_file' => basename($imagePath),
            'mockup_file' => basename((string)$result['file']),
            'context_id' => $contextId,
            'prompt_file' => basename((string)$result['prompt_file']),
            'selector_state_json' => $selectorStateJson,
            'created_at' => date('c'),
        ]);

        return (int)$pdo->lastInsertId();
    }, 24);

    if ($mockupId > 0 && $artwork) {
        try {
            Logger::logMockupGeneration(
                $mockupId,
                (int)$artwork['id'],
                $contextId,
                $prompt,
                $cameraOverride !== '' ? $cameraOverride : ($contextRow['camera_view'] ?? ''),
                $humanOverride !== '' ? $humanOverride : ($contextRow['human_presence'] ?? '')
            );
        } catch (Throwable $logEx) {
            Logger::log("Failed to log mockup audit: " . $logEx->getMessage(), 'error');
        }
    }
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
            'mockup_file' => basename((string)($result['file'] ?? '')),
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
        'mockup_file' => basename((string)$result['file']),
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

        <a class="button secondary" href="report.php?image=<?= rawurlencode(basename($imagePath)) ?>&json=<?= rawurlencode($json) ?>">
            Back to Step 2
        </a>

        <a class="button secondary" href="artwork_new.php">
            Upload another artwork
        </a>

        <a class="button secondary" href="root_album.php">
            Root Artworks
        </a>
    </div>
</div>
</div>
</body>
</html>
