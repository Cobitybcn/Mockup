<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function selected_option(string $value, array $allowed, string $fallback = ''): string
{
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function build_combination_adjustment_prompt(array $controls): string
{
    $lines = [];
    if (!empty($controls['existing_mockup_file'])) {
        $lines[] = 'Selected existing mockup reference: #' . (int)($controls['existing_mockup_id'] ?? 0) . ' (' . (string)$controls['existing_mockup_file'] . '). Use this as the improvement target direction when interpreting the controls.';
    }
    $referenceMode = (string)($controls['reference_mode'] ?? '');
    if ($referenceMode === 'existing_only') {
        $lines[] = 'Reference mode adjustment: prioritize improving the existing generated mockup direction. Preserve the current scene family, artwork identity, and commercial intent while correcting only the requested aspects.';
    } elseif ($referenceMode === 'existing_plus_world') {
        $lines[] = 'Reference mode adjustment: use both the existing mockup direction and the selected world mother reference. Keep the result visually close to the current successful direction, but restore material, lighting, and room credibility from the world mother.';
    } elseif ($referenceMode === 'rebuild_from_combo') {
        $lines[] = 'Reference mode adjustment: rebuild from the full combination prompt and selected world mother instead of copying the previous result. Keep the same combination identity.';
    }

    $experimentalCamera = (string)($controls['experimental_camera'] ?? '');
    $cameraStrength = (string)($controls['camera_strength'] ?? 'normal');
    if ($experimentalCamera !== '') {
        $lines[] = 'Experimental camera adjustment: run without visible human figures and without artwork scale changes. Apply only the requested camera change with ' . $cameraStrength . ' strength.';
        $cameraMap = [
            'closer_crop' => 'Move the camera/crop closer so the artwork becomes easier to inspect and more commercially dominant, without changing its physical scale.',
            'wider_context' => 'Open the crop to show more architecture/context, maintaining the artwork as the primary subject.',
            'lower_angle' => 'cámara más baja para dar presencia, con escala y geometría creíbles.',
            'higher_angle' => 'cámara más alta/editorial, perspectiva controlada.',
            'stronger_oblique' => 'perspectiva más oblicua, más profundidad, más contacto físico con pared/espacio.',
        ];
        $lines[] = $cameraMap[$experimentalCamera] ?? '';
    } else {
        $humanPresence = (string)($controls['human_presence'] ?? 'none');
        if ($humanPresence === 'female_160') {
            $lines[] = 'Human presence adjustment: include exactly one discreet adult woman, 1.60 meters tall, as a secondary scale reference. She must not distract from the artwork.';
        } elseif ($humanPresence === 'male_180') {
            $lines[] = 'Human presence adjustment: include exactly one discreet adult man, 1.80 meters tall, as a secondary scale reference. He must not distract from the artwork.';
        } else {
            $lines[] = 'Human presence adjustment: do not include visible people, silhouettes, hands, faces, bodies, or mannequins.';
        }

        $scale = (int)($controls['artwork_scale'] ?? 0);
        if ($scale !== 0) {
            $direction = $scale > 0 ? 'increase' : 'decrease';
            $lines[] = 'Artwork visual scale adjustment: ' . $direction . ' the artwork visual presence by about ' . abs($scale) . ' percent through camera distance, crop, and framing while preserving the artwork real physical dimensions and believable room scale.';
        }
    }

    $lightingMap = [
        'gallery_spotlight' => 'Lighting adjustment: use premium gallery spotlighting with controlled wall falloff, clean shadows, and excellent artwork readability.',
        'soft_daylight' => 'Lighting adjustment: use soft natural daylight with realistic ambient depth and faithful artwork color.',
        'golden_hour' => 'Lighting adjustment: use directional golden-hour light with elegant shadow structure, without tinting or altering the artwork.',
        'moody_evening' => 'Lighting adjustment: use moody evening collector light with focused artwork illumination and rich surrounding shadows.',
        'brighter_artwork' => 'Lighting adjustment: improve artwork readability and exposure while keeping the room photorealistic and not flatly lit.',
    ];
    $lighting = (string)($controls['lighting'] ?? '');
    if (isset($lightingMap[$lighting])) {
        $lines[] = $lightingMap[$lighting];
    }

    $instruction = trim((string)($controls['prompt_instruction'] ?? ''));
    if ($instruction !== '') {
        $lines[] = 'User prompt instruction: ' . $instruction;
    }

    $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
    return $lines ? "MOCKUP IMPROVEMENT CONTROLS\n\n" . implode("\n", $lines) : '';
}

try {
    $user = Auth::requireUser();
    $isAdmin = Auth::isAdmin($user);
    $canSelectGenerationProvider = ProviderSettings::canSelectGenerationProvider(
        $isAdmin,
        (string)($_SERVER['HTTP_HOST'] ?? '')
    );
    $requestedGenerationProvider = strtolower(trim((string)($_POST['generation_provider'] ?? $_GET['generation_provider'] ?? '')));
    $generationProvider = $canSelectGenerationProvider
        ? ServiceFactory::generationProvider($requestedGenerationProvider)
        : ServiceFactory::generationProvider();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $artworkId = max(0, (int)($_POST['artwork_id'] ?? $_GET['artwork_id'] ?? 0));
    $combinationIndex = max(0, (int)($_POST['combination_index'] ?? $_GET['combination_index'] ?? 0));
    $cameraSlotId = trim((string)($_POST['camera_slot_id'] ?? $_GET['camera_slot_id'] ?? ''));
    $worldMotherCategory = trim(str_replace(['\\', '/'], '', (string)($_POST['world_mother_category'] ?? $_GET['world_mother_category'] ?? '')));
    $sceneBoardIndex = max(1, min(3, (int)($_POST['board'] ?? $_GET['board'] ?? 1)));
    $worldMotherVariantOffset = max(0, (int)($_POST['world_mother_variant_offset'] ?? $_GET['world_mother_variant_offset'] ?? 0));
    $worldMotherScale = trim((string)($_POST['world_mother_scale'] ?? $_GET['world_mother_scale'] ?? ''));
    if ($worldMotherScale !== '' && (!is_numeric($worldMotherScale) || (float)$worldMotherScale < 1.0 || (float)$worldMotherScale > 3.0)) {
        $worldMotherScale = '';
    }
    $improvementControls = [
        'existing_mockup_id' => max(0, (int)($_POST['existing_mockup_id'] ?? $_GET['existing_mockup_id'] ?? 0)),
        'reference_mode' => selected_option((string)($_POST['reference_mode'] ?? $_GET['reference_mode'] ?? ''), ['existing_only', 'existing_plus_world', 'rebuild_from_combo'], 'existing_only'),
        'human_presence' => selected_option((string)($_POST['human_presence'] ?? $_GET['human_presence'] ?? ''), ['none', 'female_160', 'male_180'], 'none'),
        'artwork_scale' => max(-60, min(60, (int)($_POST['artwork_scale'] ?? $_GET['artwork_scale'] ?? 0))),
        'lighting' => selected_option((string)($_POST['lighting'] ?? $_GET['lighting'] ?? ''), ['', 'gallery_spotlight', 'soft_daylight', 'golden_hour', 'moody_evening', 'brighter_artwork'], ''),
        'experimental_camera' => selected_option((string)($_POST['experimental_camera'] ?? $_GET['experimental_camera'] ?? ''), ['', 'closer_crop', 'wider_context', 'lower_angle', 'higher_angle', 'stronger_oblique'], ''),
        'camera_strength' => selected_option((string)($_POST['camera_strength'] ?? $_GET['camera_strength'] ?? ''), ['normal', 'subtle', 'strong', 'extreme'], 'normal'),
        'prompt_instruction' => substr(trim((string)($_POST['prompt_instruction'] ?? $_GET['prompt_instruction'] ?? '')), 0, 1200),
    ];

    if ($artworkId <= 0 || $combinationIndex <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing artwork_id or combination_index.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $artworkId]);
    $artwork = $stmt->fetch();
    if (!$artwork) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Artwork not found.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((int)$artwork['user_id'] !== (int)$user['id'] && !Auth::isAdmin($user)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $existingMockup = null;
    if ((int)$improvementControls['existing_mockup_id'] > 0) {
        $stmtExistingMockup = $pdo->prepare('
            SELECT id, mockup_file, selector_state_json, source_artwork_id, artwork_group_id
            FROM mockups
            WHERE id = :id AND user_id = :user_id
            LIMIT 1
        ');
        $stmtExistingMockup->execute([
            'id' => (int)$improvementControls['existing_mockup_id'],
            'user_id' => (int)$artwork['user_id'],
        ]);
        $existingMockup = $stmtExistingMockup->fetch();
        if ($existingMockup) {
            $improvementControls['existing_mockup_file'] = basename((string)$existingMockup['mockup_file']);
        } else {
            $improvementControls['existing_mockup_id'] = 0;
        }
    }
    $improvementPrompt = build_combination_adjustment_prompt($improvementControls);

    $selectedSlots = [];
    if ($cameraSlotId !== '') {
        $selectedSlots[$combinationIndex] = $cameraSlotId;
    }

    $review = (new MockupCombinationEngine($pdo))->buildForArtwork($artworkId, $selectedSlots, [
        'selected_world_mother_category' => $worldMotherCategory,
        'world_mother_variant_offsets' => [
            $combinationIndex => $worldMotherVariantOffset,
        ],
        'scene_board_index' => $sceneBoardIndex,
    ]);
    $combination = null;
    foreach ((array)($review['combinations'] ?? []) as $candidate) {
        if ((int)($candidate['combination_index'] ?? 0) === $combinationIndex) {
            $combination = $candidate;
            break;
        }
    }

    if (!$combination && $existingMockup) {
        $storedState = json_decode((string)($existingMockup['selector_state_json'] ?? ''), true);
        $storedCombination = is_array($storedState) ? (array)($storedState['combination'] ?? []) : [];
        $storedGenerationSource = is_array($storedState) ? (string)($storedState['generation_source'] ?? '') : '';
        $storedArtworkId = (int)($storedCombination['artwork_id'] ?? $existingMockup['source_artwork_id'] ?? 0);
        if (
            $storedGenerationSource === 'mockup_combination_review'
            && $storedCombination
            && $storedArtworkId === $artworkId
            && (int)($storedCombination['combination_index'] ?? 0) === $combinationIndex
        ) {
            $combination = $storedCombination;
            if ($cameraSlotId !== '') {
                $combination['selected_camera_slot_id'] = $cameraSlotId;
            }
            if ($worldMotherCategory !== '') {
                $combination['world_mother_category'] = $worldMotherCategory;
            }
            $combination['world_mother_variant_offset'] = $worldMotherVariantOffset;
        }
    }

    if (!$combination) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Combination not found.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (empty($combination['generation_ready'])) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'Combination is not generation-ready.',
            'validation_notes' => $combination['validation_notes'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $auditDir = __DIR__ . '/analysis/mockup-combination-audit/' . $artworkId;
    if (!is_dir($auditDir) && !mkdir($auditDir, 0775, true) && !is_dir($auditDir)) {
        throw new RuntimeException('Could not create combination audit directory.');
    }

    $timestamp = date('Ymd-His') . '-' . random_int(1000, 9999);
    $auditFile = "combination-{$combinationIndex}-{$timestamp}.generation.json";
    $auditPath = $auditDir . '/' . $auditFile;
    $audit = [
        'schema' => 'mockup_combination_generation.v1',
        'generation_mode' => 'mockup_combination_full_generation',
        'generation_provider' => $generationProvider,
        'started_at' => date(DATE_ATOM),
        'requested_by_user_id' => (int)$user['id'],
        'combination' => $combination,
        'status' => 'prepared',
        'mockup_id' => null,
        'mockup_file' => null,
        'prompt_file' => null,
        'error' => '',
    ];
    file_put_contents($auditPath, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $rootPath = (string)($combination['root_artwork_path'] ?? '');
    $worldMotherPath = (string)($combination['world_mother_image_absolute_path'] ?? '');
    if (!is_file($rootPath)) {
        throw new RuntimeException('Root artwork image file not found.');
    }
    if (!is_file($worldMotherPath)) {
        throw new RuntimeException('World mother reference image file not found.');
    }

    ProviderSettings::set(ProviderSettings::readForRoot($rootPath));

    $artistProfile = ArtistProfile::findForUser((int)$artwork['user_id']);
    $artistName = trim((string)($artistProfile['artist_name'] ?? ''));
    $artworkTitle = trim((string)($artwork['final_title'] ?? ''));
    if ($artworkTitle === '') {
        $artworkTitle = Display::artworkTitle((string)$artwork['root_file']);
    }

    $seoParams = [
        'artistName' => $artistName,
        'artworkTitle' => $artworkTitle,
        'mockupContext' => (string)($combination['context_title'] ?? 'mockup combination'),
        'cameraAngle' => (string)($combination['selected_camera_slot_id'] ?? ''),
        'cameraSlotName' => (string)($combination['camera_slot_name'] ?? ''),
        'imageType' => 'mockup',
        'extension' => 'jpg',
    ];

    $contextId = 'combination_' . $combinationIndex;
    $worldMotherReferenceMode = (string)($combination['world_mother_reference_mode'] ?? 'reconstructed_view');
    $slotFullPromptMode = AdminPromptComposerPreview::hasSlotFullPromptTemplate(
        (string)($combination['selected_camera_slot_id'] ?? '')
    );
    $finalPrompt = trim((string)$combination['final_prompt_preview'] . ($improvementPrompt !== '' ? "\n\n" . $improvementPrompt : ''));
    $combinationForStorage = $combination;
    if ($improvementPrompt !== '') {
        $combinationForStorage['improvement_controls'] = $improvementControls;
        $combinationForStorage['improvement_prompt'] = $improvementPrompt;
        $combinationForStorage['final_prompt_preview'] = $finalPrompt;
    }

    $audit['combination'] = $combinationForStorage;
    file_put_contents($auditPath, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // Prepare UI Selector State to save in the job
    $selectorState = [
        'generation_source' => 'mockup_combination_review',
        'generation_provider' => $generationProvider,
        'audit_file' => 'analysis/mockup-combination-audit/' . $artworkId . '/' . $auditFile,
        'combination' => $combinationForStorage,
        'world_mother_reference_mode' => $worldMotherReferenceMode,
        'world_mother_scale' => $worldMotherScale,
        'scene_board_index' => $sceneBoardIndex,
        'world_mother_category' => $worldMotherCategory,
    ];
    $selectorStateJson = json_encode($selectorState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // 1. Database Transaction: Lock user, check credits, deduct 1 credit, register ledger, insert job
    $jobId = 0;
    try {
        $jobId = Database::createGenerationJobWithTransaction(
            (int)$user['id'],
            (int)$artworkId,
            $contextId,
            $finalPrompt,
            $rootPath,
            $selectorStateJson
        );
    } catch (Exception $e) {
        $audit['status'] = 'failed';
        $audit['error'] = $e->getMessage();
        file_put_contents($auditPath, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Dispatch once and return immediately. From here on the page only reads
    // status; navigating through the application never starts another worker.
    try {
        $dispatchMode = (new MockupGenerationDispatcher())->dispatch(
            $jobId,
            (int)$user['id'],
            $artworkId,
            $contextId,
            $generationProvider
        );
        $audit['status'] = 'queued';
        $audit['dispatch_mode'] = $dispatchMode;
        $audit['queued_at'] = date(DATE_ATOM);
        file_put_contents($auditPath, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        echo json_encode([
            'ok' => true,
            'enqueued' => true,
            'job_id' => $jobId,
            'message' => 'Generation continues in the background. You can move through the application.',
            'audit_file' => 'analysis/mockup-combination-audit/' . $artworkId . '/' . $auditFile,
            'results_url' => 'mockup_combination_results.php?id=' . $artworkId . '&board=' . $sceneBoardIndex . '&generation_provider=' . rawurlencode($generationProvider) . ($worldMotherCategory !== '' ? '&world_mother_category=' . rawurlencode($worldMotherCategory) : '') . '&highlight_job=' . $jobId,
            'generation_mode' => 'mockup_combination_full_generation',
            'generation_provider' => $generationProvider,
            'dispatch_mode' => $dispatchMode,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        Database::failEnqueueAndRefund((int)$user['id'], $jobId, $e->getMessage());

        $audit['status'] = 'failed';
        $audit['completed_at'] = date(DATE_ATOM);
        $audit['error'] = $e->getMessage();
        file_put_contents($auditPath, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo registrar la generación: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
} catch (Throwable $e) {
    if (isset($auditPath, $audit)) {
        $audit['status'] = 'failed';
        $audit['completed_at'] = date(DATE_ATOM);
        $audit['error'] = $e->getMessage();
        file_put_contents($auditPath, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
