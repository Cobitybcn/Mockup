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

    // 2. Dispatch the Task to Cloud Tasks (Async)
    try {
        if (ProviderSettings::allowRealApi() && $generationProvider === 'gemini' && class_exists('Google\Cloud\Tasks\V2\CloudTasksClient')) {
            // Real environment: Enqueue job to Cloud Tasks queue targeting the worker
            CloudTasksService::enqueueGeneration($jobId, (int)$user['id'], (int)$artworkId, $contextId, $generationProvider);
            Database::updateJobStatus($jobId, 'queued');
        } elseif (ProviderSettings::allowRealApi()) {
            Database::updateJobStatus($jobId, 'processing');

            ProviderSettings::set(ProviderSettings::readForRoot($rootPath));

            $generator = ServiceFactory::mockupGenerator($generationProvider);
            $mockResult = $generator->generate($rootPath, $contextId, $finalPrompt, [
                'seo_params' => $seoParams,
                'root_reference_path' => $rootPath,
                'world_mother_reference_path' => is_file($worldMotherPath) ? $worldMotherPath : '',
                'world_mother_reference_mode' => $worldMotherReferenceMode,
                'world_mother_reference_path_original' => is_file($worldMotherPath) ? $worldMotherPath : '',
                'world_mother_scale' => $worldMotherScale,
                'prompt_passthrough_mode' => $finalPrompt,
                'skip_world_visual_enhancer' => true,
                'slot_full_prompt_mode' => $slotFullPromptMode,
                'mockup_combination' => $combinationForStorage,
            ]);

            if (array_key_exists('fidelity_review', $mockResult)) {
                $selectorState['fidelity_validation'] = [
                    'review' => $mockResult['fidelity_review'],
                    'attempts' => (int)($mockResult['fidelity_attempts'] ?? 1),
                    'rejected_candidates' => (int)($mockResult['fidelity_rejected_candidates'] ?? 0),
                    'reviews' => $mockResult['fidelity_reviews'] ?? [],
                ];
                $selectorStateJson = json_encode($selectorState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $mockupId = (int)Database::withBusyRetry(function () use ($user, $artwork, $mockResult, $contextId, $selectorStateJson): int {
                $stmtInsert = Database::connection()->prepare("
                    INSERT INTO mockups (user_id, artwork_group_id, source_artwork_id, artwork_file, mockup_file, context_id, prompt_file, selector_state_json, created_at)
                    VALUES (:user_id, :artwork_group_id, :source_artwork_id, :artwork_file, :mockup_file, :context_id, :prompt_file, :selector_state_json, :created_at)
                ");
                $stmtInsert->execute([
                    'user_id' => (int)$user['id'],
                    'artwork_group_id' => ((int)($artwork['artwork_group_id'] ?? 0) > 0) ? (int)$artwork['artwork_group_id'] : null,
                    'source_artwork_id' => (int)$artwork['id'],
                    'artwork_file' => basename((string)$artwork['root_file']),
                    'mockup_file' => basename((string)$mockResult['file']),
                    'context_id' => $contextId,
                    'prompt_file' => basename((string)$mockResult['prompt_file']),
                    'selector_state_json' => $selectorStateJson,
                    'created_at' => date('c'),
                ]);
                return (int)Database::connection()->lastInsertId();
            }, 24);

            Database::withBusyRetry(function () use ($jobId, $mockupId, $mockResult): void {
                $pdo = Database::connection();
                $pdo->prepare('
                    UPDATE mockup_generation_jobs
                    SET status = "done", mockup_id = :mockup_id, mockup_file = :mockup_file, prompt_file = :prompt_file, updated_at = :now
                    WHERE id = :id
                ')->execute([
                    'mockup_id' => $mockupId,
                    'mockup_file' => basename($mockResult['file']),
                    'prompt_file' => basename($mockResult['prompt_file']),
                    'now' => date('c'),
                    'id' => $jobId
                ]);
            });
        } else {
            // Local offline debug / mock mode: run mock generation synchronously
            $mockResult = [
                'file' => 'mockup_' . $contextId . '_' . time() . '.jpg',
                'prompt_file' => 'mockup-prompts/prompt_' . $contextId . '_' . time() . '.txt',
                'message' => 'Mock mockup generated successfully.'
            ];
            
            // Build mock files physically
            if (is_file($worldMotherPath)) {
                copy($worldMotherPath, __DIR__ . '/results/' . $mockResult['file']);
            } else {
                file_put_contents(__DIR__ . '/results/' . $mockResult['file'], 'mock mockup image content');
            }
            file_put_contents(__DIR__ . '/results/' . $mockResult['prompt_file'], $finalPrompt);

            // Insert mockup record in DB
            $mockupId = (int)Database::withBusyRetry(function () use ($user, $artwork, $mockResult, $contextId, $selectorStateJson): int {
                $stmtInsert = Database::connection()->prepare("
                    INSERT INTO mockups (user_id, artwork_group_id, source_artwork_id, artwork_file, mockup_file, context_id, prompt_file, selector_state_json, created_at)
                    VALUES (:user_id, :artwork_group_id, :source_artwork_id, :artwork_file, :mockup_file, :context_id, :prompt_file, :selector_state_json, :created_at)
                ");
                $stmtInsert->execute([
                    'user_id' => (int)$user['id'],
                    'artwork_group_id' => ((int)($artwork['artwork_group_id'] ?? 0) > 0) ? (int)$artwork['artwork_group_id'] : null,
                    'source_artwork_id' => (int)$artwork['id'],
                    'artwork_file' => basename((string)$artwork['root_file']),
                    'mockup_file' => basename((string)$mockResult['file']),
                    'context_id' => $contextId,
                    'prompt_file' => basename((string)$mockResult['prompt_file']),
                    'selector_state_json' => $selectorStateJson,
                    'created_at' => date('c'),
                ]);
                return (int)Database::connection()->lastInsertId();
            }, 24);

            // Update job status to completed/done
            Database::withBusyRetry(function () use ($jobId, $mockupId, $mockResult): void {
                $pdo = Database::connection();
                $pdo->prepare('
                    UPDATE mockup_generation_jobs
                    SET status = "done", mockup_id = :mockup_id, mockup_file = :mockup_file, prompt_file = :prompt_file, updated_at = :now
                    WHERE id = :id
                ')->execute([
                    'mockup_id' => $mockupId,
                    'mockup_file' => basename($mockResult['file']),
                    'prompt_file' => basename($mockResult['prompt_file']),
                    'now' => date('c'),
                    'id' => $jobId
                ]);
            });
        }

        if (isset($mockupId) && ProviderSettings::isRealMode() && ProviderSettings::allowRealApi() && $generationProvider === 'gemini') {
            try {
                $v2Sheets=new ArtworkSheetService(Database::connection());$v2ArtworkSheet=$v2Sheets->sheetForArtwork((int)$artwork['id'],(int)$user['id']);
                $v2Sheets->generateMockupSheet((int)$v2ArtworkSheet['id'],(int)$artwork['id'],basename((string)$mockResult['file']),(int)$user['id'],'Automatic mockup analysis v2 during batch creation.');
            }catch(Throwable $mockupV2Error){Logger::log('Mockup v2 analysis was not generated for mockup #'.$mockupId.': '.$mockupV2Error->getMessage(),'analysis_warning');}
        }

        // Return immediate response to the caller
        $audit['status'] = ProviderSettings::allowRealApi() && !isset($mockupId) ? 'queued' : 'generated';
        $audit['completed_at'] = date(DATE_ATOM);
        if (isset($mockupId)) {
            $audit['mockup_id'] = $mockupId;
            $audit['mockup_file'] = basename((string)$mockResult['file']);
            $audit['prompt_file'] = basename((string)$mockResult['prompt_file']);
        }
        file_put_contents($auditPath, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if(isset($mockupId))NextPlatformSync::run();
        echo json_encode([
            'ok' => true,
            'enqueued' => ProviderSettings::allowRealApi() && !isset($mockupId),
            'job_id' => $jobId,
            'message' => ProviderSettings::allowRealApi() && !isset($mockupId)
                ? 'Generation enqueued in Cloud Tasks.'
                : (ProviderSettings::allowRealApi() ? 'Mockup generated locally with real API.' : 'Mockup generated (mock mode).'),
            'audit_file' => 'analysis/mockup-combination-audit/' . $artworkId . '/' . $auditFile,
            'results_url' => 'mockup_combination_results.php?id=' . $artworkId . '&board=' . $sceneBoardIndex . '&generation_provider=' . rawurlencode($generationProvider) . ($worldMotherCategory !== '' ? '&world_mother_category=' . rawurlencode($worldMotherCategory) : ''),
            'generation_mode' => 'mockup_combination_full_generation',
            'generation_provider' => $generationProvider,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        // Dispatch failed: Run Database refund transaction and mark job failed
        if (ProviderSettings::allowRealApi()) {
            Database::failEnqueueAndRefund((int)$user['id'], $jobId, $e->getMessage());
        } else {
            Database::updateJobStatus($jobId, 'error', $e->getMessage());
        }

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
