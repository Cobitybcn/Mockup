<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = Auth::requireUser();
    $isAdmin = Auth::isAdmin($user);
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

    if (!$isAdmin) {
        $referenceMode = 'mockup_root';
        $allowedUserAngles = [
            'camera_less_profile',
            'camera_more_profile',
        ];
        if (!in_array($cameraModifier, $allowedUserAngles, true)) {
            $cameraModifier = '';
        }
        $cameraStrength = 'normal';
    }

    if ($humanPresence === '' && $artworkScale === '' && $lightingModifier === '' && $cameraModifier === '' && $legacyVariationType !== '') {
        [$humanPresence, $artworkScale, $lightingModifier, $cameraModifier] = mockup_variation_lab_legacy_variation_to_modifiers($legacyVariationType);
    }
    if ($cameraModifier !== '') {
        if ($referenceMode === 'mockup_root_strict') {
            $referenceMode = 'mockup_root';
        }
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
    if ((int)$mockup['user_id'] !== (int)$user['id'] && !$isAdmin) {
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
    $selectorState = json_decode((string)($mockup['selector_state_json'] ?? ''), true);
    $selectorState = is_array($selectorState) ? $selectorState : [];
    $sourceCombination = (array)($selectorState['combination'] ?? []);
    $worldMotherRelativePath = trim(str_replace(['\\'], '/', (string)($sourceCombination['world_mother_image_path'] ?? '')));
    $worldMotherPath = mockup_variation_lab_world_mother_path($worldMotherRelativePath);
    if (!$isAdmin) {
        $worldMotherRelativePath = '';
        $worldMotherPath = '';
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
        (string)($artwork['unit'] ?? 'cm'),
        $worldMotherPath !== ''
    );

    $isDirectSceneEdit = in_array($cameraModifier, ['camera_less_profile', 'camera_more_profile'], true)
        || $customInstruction !== '';
    $attachedImages = $isDirectSceneEdit
        ? [$mockupFile]
        : [$mockupFile, $rootFile];
    $effectiveReferenceMode = $isDirectSceneEdit ? 'mockup_only' : 'mockup_root';

    $audit = [
        'schema' => 'mockup_variation_lab.v1',
        'started_at' => date(DATE_ATOM),
        'requested_by_user_id' => (int)$user['id'],
        'mockup_owner_user_id' => $ownerId,
        'mockup_id' => $mockupId,
        'artwork_id' => (int)$artwork['id'],
        'reference_mode' => $effectiveReferenceMode,
        'variation_type' => $variationType,
        'human_presence' => $humanPresence,
        'artwork_scale' => $artworkScale,
        'lighting_modifier' => $lightingModifier,
        'camera_modifier' => $cameraModifier,
        'camera_strength' => $cameraStrength,
        'custom_instruction' => $customInstruction,
        'input_mockup_file' => $mockupFile,
        'scene_root_file' => $mockupFile,
        'input_root_file' => $rootFile,
        'attached_images' => $attachedImages,
        'input_world_mother_file' => '',
        'input_world_mother_found' => false,
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
    if (!$isDirectSceneEdit) {
        $parts[] = $client->imagePart($rootPath);
    }

    $imageB64 = $client->generateImage($parts, ProviderSettings::geminiImageModel(), [
        'MOCKUP_USE_PRECOMPOSITION' => 'false',
        'MOCKUP_USE_BACKGROUND_EDIT' => 'false',
        'MOCKUP_PROMPT_FIRST_NO_MASK_MODE' => 'false',
        'GEMINI_SKIP_OUTPUT_FRAME_CONTRACT' => 'true',
    ]);
    $bytes = base64_decode($imageB64);
    if ($bytes === false) {
        throw new RuntimeException('Gemini did not return a valid image.');
    }
    file_put_contents($labDir . DIRECTORY_SEPARATOR . $outputFile, $bytes);

    $registeredMockupFile = $baseName . '-mockup.png';
    $registeredPromptFile = $baseName . '-prompt.txt';
    $registeredSelectorState = [
        'generation_source' => 'mockup_variation_lab',
        'source_mockup_id' => $mockupId,
        'variation_type' => $variationType,
        'reference_mode' => $effectiveReferenceMode,
        'human_presence' => $humanPresence,
        'artwork_scale' => $artworkScale,
        'lighting_modifier' => $lightingModifier,
        'camera_modifier' => $cameraModifier,
        'camera_strength' => $cameraStrength,
        'custom_instruction' => $customInstruction,
        'input_mockup_file' => $mockupFile,
        'input_root_file' => $rootFile,
        'attached_images' => $attachedImages,
        'input_world_mother_file' => $worldMotherRelativePath,
        'lab_output_file' => 'storage/experiments/mockup-variation-lab/' . $outputFile,
        'lab_prompt_file' => 'storage/experiments/mockup-variation-lab/' . $promptFile,
        'lab_audit_file' => 'storage/experiments/mockup-variation-lab/' . $auditFile,
    ];
    file_put_contents(RESULTS_DIR . DIRECTORY_SEPARATOR . $registeredMockupFile, $bytes);
    file_put_contents(PROMPTS_DIR . DIRECTORY_SEPARATOR . $registeredPromptFile, $prompt);

    if (StorageService::isGcsActive()) {
        $persistentFiles = [
            'results/' . $registeredMockupFile => RESULTS_DIR . DIRECTORY_SEPARATOR . $registeredMockupFile,
            'mockup-prompts/' . $registeredPromptFile => PROMPTS_DIR . DIRECTORY_SEPARATOR . $registeredPromptFile,
            'storage/experiments/mockup-variation-lab/' . $outputFile => $labDir . DIRECTORY_SEPARATOR . $outputFile,
            'storage/experiments/mockup-variation-lab/' . $promptFile => $labDir . DIRECTORY_SEPARATOR . $promptFile,
            'storage/experiments/mockup-variation-lab/' . $auditFile => $labDir . DIRECTORY_SEPARATOR . $auditFile,
        ];
        foreach ($persistentFiles as $storageKey => $localPath) {
            if (!StorageService::uploadFile($storageKey, $localPath)) {
                throw new RuntimeException('The generated variation could not be saved to persistent storage.');
            }
        }
    }

    $registeredMockupId = (int)Database::withBusyRetry(function () use (
        $ownerId,
        $artwork,
        $rootFile,
        $registeredMockupFile,
        $registeredPromptFile,
        $registeredSelectorState
    ): int {
        $insert = Database::connection()->prepare("
            INSERT INTO mockups (user_id, artwork_group_id, source_artwork_id, artwork_file, mockup_file, context_id, prompt_file, selector_state_json, created_at)
            VALUES (:user_id, :artwork_group_id, :source_artwork_id, :artwork_file, :mockup_file, :context_id, :prompt_file, :selector_state_json, :created_at)
        ");
        $insert->execute([
            'user_id' => $ownerId,
            'artwork_group_id' => !empty($artwork['artwork_group_id']) ? (int)$artwork['artwork_group_id'] : null,
            'source_artwork_id' => !empty($artwork['id']) ? (int)$artwork['id'] : null,
            'artwork_file' => $rootFile,
            'mockup_file' => $registeredMockupFile,
            'context_id' => 'variation_lab',
            'prompt_file' => $registeredPromptFile,
            'selector_state_json' => json_encode($registeredSelectorState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => date('c'),
        ]);

        return (int)Database::connection()->lastInsertId();
    }, 12);

    $audit['status'] = 'generated';
    $audit['completed_at'] = date(DATE_ATOM);
    $audit['registered_mockup_id'] = $registeredMockupId;
    $audit['registered_mockup_file'] = $registeredMockupFile;
    $audit['registered_prompt_file'] = $registeredPromptFile;
    file_put_contents($labDir . DIRECTORY_SEPARATOR . $auditFile, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if (StorageService::isGcsActive() && !StorageService::uploadFile(
        'storage/experiments/mockup-variation-lab/' . $auditFile,
        $labDir . DIRECTORY_SEPARATOR . $auditFile
    )) {
        Logger::log('The completed variation lab audit could not be refreshed in persistent storage: ' . $auditFile, 'warning');
    }

    NextPlatformSync::run();
    echo json_encode([
        'ok' => true,
        'message' => 'Variation generated.',
        'output_file' => $outputFile,
        'output_url' => 'media.php?file=' . rawurlencode($registeredMockupFile),
        'viewer_url' => 'viewer.php?id=' . rawurlencode((string)$registeredMockupId) . '&back=' . rawurlencode('mockup_variation_lab.php?mockup_id=' . $mockupId),
        'prompt_url' => 'mockup_variation_lab_file.php?file=' . rawurlencode($promptFile),
        'audit_url' => 'mockup_variation_lab_file.php?file=' . rawurlencode($auditFile),
        'registered_mockup_id' => $registeredMockupId,
        'registered_mockup_url' => 'media.php?file=' . rawurlencode($registeredMockupFile),
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

function mockup_variation_lab_world_mother_path(string $relativePath): string
{
    $relativePath = trim(str_replace('\\', '/', $relativePath));
    if ($relativePath === '' || !str_starts_with($relativePath, 'storage/world_mothers/')) {
        return '';
    }

    $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    return is_file($absolutePath) ? $absolutePath : '';
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
        'light_overcast',
        'light_night',
        'light_golden',
        'camera_aerial',
        'camera_nadir',
        'camera_profile_left',
        'camera_profile_right',
        'camera_less_profile',
        'camera_more_profile',
        'camera_left_3_4',
        'camera_right_3_4',
        'custom',
    ];

    return in_array($value, $allowed, true) ? $value : '';
}

function mockup_variation_lab_human_presence(string $value): string
{
    $value = strtolower(trim($value));
    if (in_array($value, ['female_155', 'female_160', 'female_180'], true)) {
        return 'female_160';
    }
    if (in_array($value, ['male_180', 'male_200'], true)) {
        return 'male_180';
    }

    return in_array($value, ['none', '', 'female_160', 'male_180'], true) ? $value : '';
}

function mockup_variation_lab_artwork_scale(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['none', '', 'scale_minus_20', 'scale_plus_20', 'scale_minus_40', 'scale_plus_40', 'scale_minus_60', 'scale_plus_60'], true) ? ($value === 'none' ? '' : $value) : '';
}

function mockup_variation_lab_lighting_modifier(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['none', '', 'light_day', 'light_overcast', 'light_night', 'light_golden'], true) ? ($value === 'none' ? '' : $value) : '';
}

function mockup_variation_lab_camera_modifier(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, [
        'none', '',
        'camera_aerial', 'camera_nadir', 'camera_profile_left', 'camera_profile_right',
        'camera_less_profile', 'camera_more_profile',
        'camera_left_3_4', 'camera_right_3_4',
    ], true) ? ($value === 'none' ? '' : $value) : '';
}

function mockup_variation_lab_camera_strength(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['normal', 'intermediate', 'extreme'], true) ? $value : 'normal';
}

function mockup_variation_lab_legacy_variation_to_modifiers(string $variationType): array
{
    return match ($variationType) {
        'female_155', 'female_160', 'female_180' => ['female_160', '', '', ''],
        'male_180', 'male_200' => ['male_180', '', '', ''],
        'scale_minus_20', 'scale_plus_20', 'scale_minus_40', 'scale_plus_40', 'scale_minus_60', 'scale_plus_60' => ['', $variationType, '', ''],
        'light_day', 'light_overcast', 'light_night', 'light_golden' => ['', '', $variationType, ''],
        'camera_aerial', 'camera_nadir', 'camera_profile_left', 'camera_profile_right',
        'camera_less_profile', 'camera_more_profile',
        'camera_left_3_4', 'camera_right_3_4' => ['', '', '', $variationType],
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
    string $unit,
    bool $hasWorldMotherReference = false
): string {
    if ($cameraModifier === 'camera_less_profile') {
        return 'Quiero tener una vista de la obra de arte menos perfilada.';
    }
    if ($cameraModifier === 'camera_more_profile') {
        return 'Quiero tener una vista de la obra de arte mas perfilada.';
    }
    if ($cameraModifier === 'camera_left_3_4') {
        return mockup_variation_lab_side_angle_text('izquierdo');
    }
    if ($cameraModifier === 'camera_right_3_4') {
        return mockup_variation_lab_side_angle_text('derecho');
    }

    $variationBlocks = [];
    if ($cameraModifier !== '') {
        $variationBlocks[] = match ($cameraModifier) {
            'camera_aerial' => 'Cambia la perspectiva de esta imagen a una vista aerea.',
            'camera_nadir' => 'Cambia la perspectiva de esta imagen a una vista desde abajo.',
            'camera_profile_left' => 'Haz un cambio de perspectiva marcado desde el lado izquierdo.',
            'camera_profile_right' => 'Haz un cambio de perspectiva marcado desde el lado derecho.',
            'camera_left_3_4' => mockup_variation_lab_side_angle_text('izquierdo'),
            'camera_right_3_4' => mockup_variation_lab_side_angle_text('derecho'),
            default => '',
        };
    }

    if ($artworkScale !== '') {
        $variationBlocks[] = match ($artworkScale) {
            'scale_minus_20' => mockup_variation_lab_scale_text('20', 'reduce'),
            'scale_plus_20' => mockup_variation_lab_scale_text('20', 'aumenta'),
            'scale_minus_40' => mockup_variation_lab_scale_text('40', 'reduce'),
            'scale_plus_40' => mockup_variation_lab_scale_text('40', 'aumenta'),
            'scale_minus_60' => mockup_variation_lab_scale_text('60', 'reduce'),
            'scale_plus_60' => mockup_variation_lab_scale_text('60', 'aumenta'),
            default => '',
        };
    }
    if ($humanPresence !== '') {
        $variationBlocks[] = match ($humanPresence) {
            'none' => 'Quita todas las figuras humanas de esta imagen.',
            'female_160' => mockup_variation_lab_human_text('femenina', '1.80 m'),
            'male_180' => mockup_variation_lab_human_text('masculina', '2.00 m'),
            default => '',
        };
    }

    if ($lightingModifier !== '') {
        $variationBlocks[] = match ($lightingModifier) {
            'light_day' => 'Agrega luz natural de dia a esta imagen.',
            'light_overcast' => 'Agrega luz natural de un dia nublado a esta imagen.',
            'light_night' => 'Convierte la iluminacion de esta imagen en luz nocturna.',
            'light_golden' => 'Agrega luz de atardecer dorada a esta imagen.',
            default => '',
        };
    }

    if ($customInstruction !== '') {
        $variationBlocks[] = $customInstruction;
    }

    return trim(implode("\n", array_filter($variationBlocks)));
}

function mockup_variation_lab_scale_text(string $percent, string $action): string
{
    $percentValue = max(0, min(100, (int)$percent));
    $verb = $action === 'reduce' ? 'Reduce' : 'Aumenta';
    return "{$verb} la obra de arte un {$percentValue}% en relacion con el resto de la imagen.";
}

function mockup_variation_lab_size_class(string $width, string $height, string $unit): string
{
    $w = (float)str_replace(',', '.', trim($width));
    $h = (float)str_replace(',', '.', trim($height));
    $longSide = max($w, $h);
    if ($longSide <= 0) {
        return 'unknown';
    }
    if (strtolower(trim($unit)) === 'in') {
        $longSide *= 2.54;
    }
    if ($longSide <= 40) {
        return 'M - lado mayor hasta 40 cm';
    }
    if ($longSide < 80) {
        return 'L - lado mayor mayor a 40 cm y menor a 80 cm';
    }
    if ($longSide < 150) {
        return 'XL - lado mayor mayor a 80 cm y menor a 150 cm';
    }
    return 'Monumental - lado mayor entre 150 cm y 250 cm';
}

function mockup_variation_lab_human_text(string $gender, string $height): string
{
    return "Agrega una nueva figura humana {$gender} de aproximadamente {$height}, de cuerpo completo, situada cerca de la obra de arte y observando atentamente un sector especifico de la obra de arte, dibujo o pintura. "
        . "No debe posar para la camara, tocar la obra de arte, ni interponerse adelante.";
}

function mockup_variation_lab_side_angle_text(string $side): string
{
    $direction = $side === 'izquierdo' ? 'izquierda' : 'derecha';
    return "quiero tener una vista del mockup más de costado, más perfilada hacia la {$direction}";
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
        'intermediate' => "VARIACION SOLICITADA - NADIR FUERTE:\nRecompone el mockup como una fotografia tomada casi a ras de suelo. La lente debe estar muy baja, aproximadamente 5 a 10 cm sobre el piso, mirando hacia arriba hacia la obra y la arquitectura. El piso debe ocupar un primer plano visible y las lineas verticales del espacio deben converger hacia arriba. Mantener la escena premium y plausible, con un lente gran angular de 16-20 mm y distorsion arquitectonica controlada.\n\nREGLAS NEGATIVAS:\nNo usar vista a altura de ojos, no usar camara elevada, no usar una toma frontal normal, no hacer solo un leve contrapicado.",
        'extreme' => "VARIACION SOLICITADA - NADIR EXTREMO OBLIGATORIO:\nRecompone el mockup desde una camara realmente pegada al suelo. La lente debe estar a 1-3 cm del piso, cerca de una esquina del ambiente, del zocalo, de una pata de mueble o de una zona de primer plano, apuntando con fuerza hacia arriba hacia la obra. El resultado debe leerse inmediatamente como una toma extrema desde el piso, no como una vista amplia normal del interior.\n\nREQUISITOS VISUALES:\n- El piso debe dominar el primer plano inferior y sentirse muy cercano a la lente.\n- La obra debe verse desde abajo, con presencia vertical fuerte y escala arquitectonica.\n- Ventanas, paredes, columnas, techo y lineas del espacio deben converger de forma marcada hacia arriba.\n- Usar lente ultra gran angular arquitectonico de 12-16 mm; permitir distorsion agresiva pero creible en la arquitectura.\n- Conservar la identidad de la obra y mantenerla plana, rectangular, fisicamente posible y sin deformarla como objeto.\n\nREGLAS NEGATIVAS:\nNo altura de ojos. No camara de pie. No vista elevada. No encuadre frontal centrado normal. No vista amplia de catalogo. No contrapicado suave. Si la imagen podria confundirse con una foto normal del living o galeria, la variacion es incorrecta.",
        default => "VARIACION SOLICITADA - NADIR CONTROLADO:\nRecompone el mockup como una fotografia a ras de suelo con contrapicado natural. La camara debe estar baja y cercana al piso, apuntando suavemente hacia arriba hacia la obra. El piso debe aparecer como primer plano y las lineas verticales deben sentirse ligeramente acentuadas. Utilizar un lente de 24-35 mm para lograr una perspectiva creible, sin distorsiones extremas.\n\nREGLAS NEGATIVAS:\nNo usar vista a altura de ojos, no usar camara elevada, no hacer una toma frontal normal.",
    };
}
