<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = Auth::requireUser();
    $pdo = Database::connection();

    $mockupId = max(0, (int)($_POST['mockup_id'] ?? 0));
    $referenceMode = mockup_variation_lab_reference_mode((string)($_POST['reference_mode'] ?? 'mockup_root_strict'));
    $legacyVariationType = mockup_variation_lab_variation_type((string)($_POST['variation_type'] ?? ''));
    $humanPresence = mockup_variation_lab_human_presence((string)($_POST['human_presence'] ?? ''));
    $artworkScale = mockup_variation_lab_artwork_scale((string)($_POST['artwork_scale'] ?? ''));
    $lightingModifier = mockup_variation_lab_lighting_modifier((string)($_POST['lighting_modifier'] ?? ''));
    $cameraModifier = mockup_variation_lab_camera_modifier((string)($_POST['camera_modifier'] ?? ''));
    $cameraStrength = mockup_variation_lab_camera_strength((string)($_POST['camera_strength'] ?? 'normal'));
    $customInstruction = trim((string)($_POST['custom_instruction'] ?? ''));

    if ($humanPresence === '' && $artworkScale === '' && $lightingModifier === '' && $cameraModifier === '' && $legacyVariationType !== '') {
        [$humanPresence, $artworkScale, $lightingModifier, $cameraModifier] = mockup_variation_lab_legacy_variation_to_modifiers($legacyVariationType);
    }
    if ($cameraModifier !== '') {
        if ($referenceMode === 'mockup_root_strict') {
            $referenceMode = 'mockup_root';
        }
        $humanPresence = '';
        $artworkScale = '';
    } else {
        $cameraStrength = 'normal';
    }
    $variationType = mockup_variation_lab_variation_label($humanPresence, $artworkScale, $lightingModifier, $cameraModifier, $cameraStrength, $customInstruction);

    if ($mockupId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing mockup_id.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!ProviderSettings::allowRealApi() || ProviderSettings::imageProvider() !== 'gemini') {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'The LAB uses real Gemini generation. Enable real API mode and Gemini as image provider in Admin API Settings before running it.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM mockups WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $mockupId]);
    $mockup = $stmt->fetch();
    if (!$mockup) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Mockup not found.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((int)$mockup['user_id'] !== (int)$user['id'] && !Auth::isAdmin($user)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have access to this mockup.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!MockupVariationEligibility::canUseVariationLab($mockup)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Variation LAB is not available for this mockup.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ownerId = (int)$mockup['user_id'];
    $artworkFile = basename((string)$mockup['artwork_file']);
    $mockupFile = basename((string)$mockup['mockup_file']);
    $mockupPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $mockupFile;
    if (!is_file($mockupPath)) {
        throw new RuntimeException('The mockup file was not found in results/.');
    }

    $stmt = $pdo->prepare('SELECT * FROM artworks WHERE user_id = :user_id AND root_file = :root_file LIMIT 1');
    $stmt->execute([
        'user_id' => $ownerId,
        'root_file' => $artworkFile,
    ]);
    $artwork = $stmt->fetch();
    if (!$artwork) {
        $stmt = $pdo->prepare('SELECT * FROM artworks WHERE user_id = :user_id ORDER BY created_at DESC');
        $stmt->execute(['user_id' => $ownerId]);
        foreach ($stmt->fetchAll() ?: [] as $candidate) {
            if (basename((string)($candidate['root_file'] ?? '')) === $artworkFile) {
                $artwork = $candidate;
                break;
            }
        }
    }
    if (!$artwork) {
        throw new RuntimeException('The mockup could not be linked to its root artwork.');
    }

    $rootFile = basename((string)$artwork['root_file']);
    $rootPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $rootFile;
    if (!is_file($rootPath)) {
        throw new RuntimeException('The root artwork image was not found in results/.');
    }

    if (!Database::deductCredit((int)$user['id'], 'mockup_variation_lab:' . $mockupId . ':' . $variationType)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'You do not have enough credits to run this test.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $creditDeducted = true;

    ProviderSettings::set(ProviderSettings::readForRoot($rootPath));

    $labDir = __DIR__ . '/storage/experiments/mockup-variation-lab';
    if (!is_dir($labDir) && !mkdir($labDir, 0775, true) && !is_dir($labDir)) {
        throw new RuntimeException('The LAB folder could not be created.');
    }

    $stamp = date('Ymd-His') . '-' . random_int(1000, 9999);
    $baseName = 'variation-lab-mockup-' . $mockupId . '-' . $stamp;
    $outputFile = $baseName . '.png';
    $promptFile = $baseName . '.prompt.txt';
    $auditFile = $baseName . '.audit.json';

    $prompt = mockup_variation_lab_prompt(
        $referenceMode,
        $humanPresence,
        $artworkScale,
        $lightingModifier,
        $cameraModifier,
        $cameraStrength,
        $customInstruction,
        (string)($artwork['final_title'] ?? ''),
        (string)($artwork['width'] ?? ''),
        (string)($artwork['height'] ?? ''),
        (string)($artwork['unit'] ?? 'cm')
    );

    $audit = [
        'schema' => 'mockup_variation_lab.v1',
        'started_at' => date(DATE_ATOM),
        'requested_by_user_id' => (int)$user['id'],
        'mockup_owner_user_id' => $ownerId,
        'mockup_id' => $mockupId,
        'artwork_id' => (int)$artwork['id'],
        'reference_mode' => $referenceMode,
        'variation_type' => $variationType,
        'human_presence' => $humanPresence,
        'artwork_scale' => $artworkScale,
        'lighting_modifier' => $lightingModifier,
        'camera_modifier' => $cameraModifier,
        'camera_strength' => $cameraStrength,
        'custom_instruction' => $customInstruction,
        'input_mockup_file' => $mockupFile,
        'input_root_file' => $rootFile,
        'output_file' => $outputFile,
        'prompt_file' => $promptFile,
        'status' => 'prepared',
        'error' => '',
    ];
    file_put_contents($labDir . DIRECTORY_SEPARATOR . $auditFile, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    file_put_contents($labDir . DIRECTORY_SEPARATOR . $promptFile, $prompt);

    $client = new GeminiImageClient();
    $parts = [$client->textPart($prompt)];
    $parts[] = $client->imagePart($mockupPath);
    if ($referenceMode !== 'mockup_only') {
        $parts[] = $client->imagePart($rootPath);
    }

    $imageB64 = $client->generateImage($parts, ProviderSettings::geminiImageModel(), [
        'MOCKUP_USE_PRECOMPOSITION' => 'false',
        'MOCKUP_USE_BACKGROUND_EDIT' => 'false',
        'MOCKUP_PROMPT_FIRST_NO_MASK_MODE' => 'false',
    ]);
    $bytes = base64_decode($imageB64);
    if ($bytes === false) {
        throw new RuntimeException('Gemini did not return a valid image.');
    }
    file_put_contents($labDir . DIRECTORY_SEPARATOR . $outputFile, $bytes);

    $audit['status'] = 'generated';
    $audit['completed_at'] = date(DATE_ATOM);
    file_put_contents($labDir . DIRECTORY_SEPARATOR . $auditFile, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    echo json_encode([
        'ok' => true,
        'message' => 'Test generated.',
        'output_file' => $outputFile,
        'output_url' => 'mockup_variation_lab_file.php?file=' . rawurlencode($outputFile),
        'viewer_url' => 'mockup_variation_lab_viewer.php?mockup_id=' . $mockupId . '&file=' . rawurlencode($outputFile),
        'prompt_url' => 'mockup_variation_lab_file.php?file=' . rawurlencode($promptFile),
        'audit_url' => 'mockup_variation_lab_file.php?file=' . rawurlencode($auditFile),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (!empty($creditDeducted) && isset($user)) {
        try {
            Database::refundCredit((int)$user['id'], 'mockup_variation_lab_failed:' . ($mockupId ?? 0));
        } catch (Throwable $refundError) {
        }
    }
    if (isset($audit, $labDir, $auditFile)) {
        $audit['status'] = 'failed';
        $audit['completed_at'] = date(DATE_ATOM);
        $audit['error'] = $e->getMessage();
        file_put_contents($labDir . DIRECTORY_SEPARATOR . $auditFile, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function mockup_variation_lab_reference_mode(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['mockup_only', 'mockup_root', 'mockup_root_strict'], true)
        ? $value
        : 'mockup_only';
}

function mockup_variation_lab_variation_type(string $value): string
{
    $value = strtolower(trim($value));
    $allowed = [
        'female_155',
        'female_160',
        'female_180',
        'male_180',
        'male_200',
        'scale_minus_20',
        'scale_plus_20',
        'scale_minus_40',
        'scale_plus_40',
        'scale_minus_60',
        'scale_plus_60',
        'light_day',
        'light_night',
        'light_golden',
        'camera_aerial',
        'camera_nadir',
        'camera_profile_left',
        'camera_profile_right',
        'custom',
    ];

    return in_array($value, $allowed, true) ? $value : '';
}

function mockup_variation_lab_human_presence(string $value): string
{
    $value = strtolower(trim($value));
    if (in_array($value, ['female_155', 'female_160'], true)) {
        return 'female_180';
    }
    if ($value === 'male_180') {
        return 'male_200';
    }

    return in_array($value, ['none', '', 'female_180', 'male_200'], true) ? ($value === 'none' ? '' : $value) : '';
}

function mockup_variation_lab_artwork_scale(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['none', '', 'scale_minus_20', 'scale_plus_20', 'scale_minus_40', 'scale_plus_40', 'scale_minus_60', 'scale_plus_60'], true) ? ($value === 'none' ? '' : $value) : '';
}

function mockup_variation_lab_lighting_modifier(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['none', '', 'light_day', 'light_night', 'light_golden'], true) ? ($value === 'none' ? '' : $value) : '';
}

function mockup_variation_lab_camera_modifier(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['none', '', 'camera_aerial', 'camera_nadir', 'camera_profile_left', 'camera_profile_right'], true) ? ($value === 'none' ? '' : $value) : '';
}

function mockup_variation_lab_camera_strength(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['normal', 'intermediate', 'extreme'], true) ? $value : 'normal';
}

function mockup_variation_lab_legacy_variation_to_modifiers(string $variationType): array
{
    return match ($variationType) {
        'female_155', 'female_160', 'female_180' => ['female_180', '', '', ''],
        'male_180', 'male_200' => ['male_200', '', '', ''],
        'scale_minus_20', 'scale_plus_20', 'scale_minus_40', 'scale_plus_40', 'scale_minus_60', 'scale_plus_60' => ['', $variationType, '', ''],
        'light_day', 'light_night', 'light_golden' => ['', '', $variationType, ''],
        'camera_aerial', 'camera_nadir', 'camera_profile_left', 'camera_profile_right' => ['', '', '', $variationType],
        default => ['', '', '', ''],
    };
}

function mockup_variation_lab_variation_label(
    string $humanPresence,
    string $artworkScale,
    string $lightingModifier,
    string $cameraModifier,
    string $cameraStrength,
    string $customInstruction
): string {
    $parts = array_filter([
        $humanPresence,
        $artworkScale,
        $lightingModifier,
        $cameraModifier,
        $cameraModifier !== '' && $cameraStrength !== 'normal' ? 'camera_' . $cameraStrength : '',
        $customInstruction !== '' ? 'custom' : '',
    ]);

    return $parts ? implode('+', $parts) : 'custom';
}

function mockup_variation_lab_prompt(
    string $referenceMode,
    string $humanPresence,
    string $artworkScale,
    string $lightingModifier,
    string $cameraModifier,
    string $cameraStrength,
    string $customInstruction,
    string $title,
    string $width,
    string $height,
    string $unit
): string {
    $usesRoot = $referenceMode !== 'mockup_only';
    $strict = $referenceMode === 'mockup_root_strict';
    $title = trim($title) !== '' ? trim($title) : 'obra sin titulo';
    $dimensions = trim($height) !== ''
        ? trim($height) . ' ' . (trim($unit) !== '' ? trim($unit) : 'cm')
        : 'altura cargada en el sistema';

    $referenceBlock = $usesRoot
        ? "REFERENCIAS ADJUNTAS:\n- IMAGEN 1 es el mockup existente que debe editarse.\n- IMAGEN 2 es la obra raiz autoritativa. La obra visible en el resultado debe conservar la identidad de IMAGEN 2.\n"
        : "REFERENCIAS ADJUNTAS:\n- IMAGEN 1 es el mockup existente que debe editarse.\n";

    $base = "Edita la imagen proporcionada como una prueba de laboratorio. No generes una explicacion; devuelve solo una imagen final.\n\n"
        . $referenceBlock
        . "\nOBRA:\n- Titulo: {$title}\n- Altura de referencia de la obra: {$dimensions}\n";

    if ($strict) {
        $base .= "- Modo estricto: prioriza conservar la escena original de IMAGEN 1 y la fidelidad de la obra de IMAGEN 2. Si una instruccion compite con esa fidelidad, simplifica la variacion antes que rehacer la escena.\n";
    }

    $variationBlocks = [];
    if ($cameraModifier !== '') {
        $variationBlocks[] = match ($cameraModifier) {
            'camera_aerial' => mockup_variation_lab_camera_text('vista aerea', $cameraStrength),
            'camera_nadir' => mockup_variation_lab_nadir_text($cameraStrength),
            'camera_profile_left' => mockup_variation_lab_camera_text('vista perfilada hacia la izquierda', $cameraStrength),
            'camera_profile_right' => mockup_variation_lab_camera_text('vista perfilada hacia la derecha', $cameraStrength),
            default => '',
        };
    }

    if ($cameraModifier === '') {
        if ($artworkScale !== '') {
            $variationBlocks[] = match ($artworkScale) {
                'scale_minus_20' => mockup_variation_lab_scale_text('20', 'mas pequena', $dimensions),
                'scale_plus_20' => mockup_variation_lab_scale_text('20', 'mas grande', $dimensions),
                'scale_minus_40' => mockup_variation_lab_scale_text('40', 'mas pequena', $dimensions),
                'scale_plus_40' => mockup_variation_lab_scale_text('40', 'mas grande', $dimensions),
                'scale_minus_60' => mockup_variation_lab_scale_text('60', 'mas pequena', $dimensions),
                'scale_plus_60' => mockup_variation_lab_scale_text('60', 'mas grande', $dimensions),
                default => '',
            };
        }
        if ($humanPresence !== '') {
            $variationBlocks[] = match ($humanPresence) {
                'female_180' => "PRESENCIA HUMANA:\nAgrega una figura humana femenina de 1.8 mts de altura observando la obra y postura relajada. Puede ser asiatica, negra, blanca o de otra procedencia. Vestimenta de lujo occidental, no religiosa, con zapatos de lujo.",
                'male_200' => "PRESENCIA HUMANA:\nAgrega una figura humana masculina de 2 mts de altura observando la obra y postura relajada. Puede ser asiatico, negro, blanco o de otra procedencia. Vestimenta de lujo occidental, no religiosa, con zapatos de lujo.",
                default => '',
            };
        }
    }

    if ($lightingModifier !== '') {
        $variationBlocks[] = match ($lightingModifier) {
            'light_day' => "ILUMINACION:\nCambia la iluminacion del mockup a luz de dia natural, limpia y premium.",
            'light_night' => "ILUMINACION:\nCambia la iluminacion del mockup a una escena nocturna elegante, con luz artificial premium.",
            'light_golden' => "ILUMINACION:\nCambia la iluminacion del mockup a luz dorada de la tarde.",
            default => '',
        };
    }

    if ($customInstruction !== '') {
        $variationBlocks[] = "INSTRUCCION ADICIONAL DEL USUARIO:\n" . $customInstruction;
    }
    if (!$variationBlocks) {
        $variationBlocks[] = "VARIACION SOLICITADA:\nRealiza una variacion sutil conservando el mockup y la obra.";
    }
    $variation = implode("\n\n", array_filter($variationBlocks));

    return trim($base . "\n" . $variation);
}

function mockup_variation_lab_scale_text(string $percent, string $direction, string $dimensions): string
{
    if ($direction === 'mas pequena') {
        return "TAMANO DE LA OBRA DE ARTE:\nReduce un {$percent}% el tamano de la obra de arte.";
    }

    return "TAMANO DE LA OBRA DE ARTE:\nAumenta un {$percent}% el tamano de la obra de arte.";
}

function mockup_variation_lab_camera_text(string $camera, string $strength): string
{
    $cameraText = match ($strength) {
        'intermediate' => $camera . ' intermedia',
        'extreme' => $camera . ' extrema',
        default => $camera,
    };

    return "VARIACION SOLICITADA:\nGenera una reinterpretacion controlada del mockup con {$cameraText}.";
}

function mockup_variation_lab_nadir_text(string $strength): string
{
    return match ($strength) {
        'intermediate' => "VARIACION SOLICITADA:\nModifica la vista de la fotografia, casi a ras de suelo, a muy baja altura, apuntando claramente hacia arriba en un contrapicado pronunciado pero no vertical. El angulo debe sentirse mas intenso que un contrapicado natural, sin llegar a una vista extrema de 90 grados. Utilizar un lente gran angular de 16-20 mm para generar una perspectiva inmersiva, con lineas verticales acentuadas y distorsion controlada.",
        'extreme' => "VARIACION SOLICITADA NADIR EXTREMO:\nFotografía realizada con la cámara situada directamente sobre el suelo, apuntando verticalmente hacia arriba (90°). Utilizar un lente ultra gran angular de 10–14 mm o un ojo de pez para capturar el espacio superior completo. La composición debe transmitir una perspectiva extrema y dramática, con fuerte sensación de altura, monumentalidad o surrealismo. Coordinar la iluminación para evitar sobreexposiciones y aprovechar el espacio arquitectónico.",
        default => "VARIACION SOLICITADA:\nGenera una reinterpretacion controlada del mockup con vista nadir.\n\nNADIR NORMAL:\nFotografia tomada a ras de suelo con un contrapicado natural. Camara muy baja y cercana al sujeto, apuntando suavemente hacia arriba. Utilizar un lente de 24-35 mm para lograr una perspectiva creible, con una ligera acentuacion de las lineas verticales y sin distorsiones extremas.",
    };
}
