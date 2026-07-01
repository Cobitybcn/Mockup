<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = Auth::requireUser();
    $artworkId = max(0, (int)($_POST['artwork_id'] ?? $_GET['artwork_id'] ?? 0));
    $combinationIndex = max(0, (int)($_POST['combination_index'] ?? $_GET['combination_index'] ?? 0));
    $cameraSlotId = trim((string)($_POST['camera_slot_id'] ?? $_GET['camera_slot_id'] ?? ''));
    $worldMotherCategory = WorldMotherGenerator::safeSlug((string)($_POST['world_mother_category'] ?? $_GET['world_mother_category'] ?? ''));

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

    $selectedSlots = [];
    if ($cameraSlotId !== '') {
        $selectedSlots[$combinationIndex] = $cameraSlotId;
    }

    $review = (new MockupCombinationEngine($pdo))->buildForArtwork($artworkId, $selectedSlots, [
        'selected_world_mother_category' => $worldMotherCategory,
    ]);
    $combination = null;
    foreach ((array)($review['combinations'] ?? []) as $candidate) {
        if ((int)($candidate['combination_index'] ?? 0) === $combinationIndex) {
            $combination = $candidate;
            break;
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

    if (ProviderSettings::allowRealApi()) {
        if (!Database::deductCredit((int)$user['id'], 'mockup_combination_generation:' . $artworkId . ':' . $combinationIndex)) {
            $audit['status'] = 'failed';
            $audit['error'] = 'Insufficient credits.';
            file_put_contents($auditPath, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'You do not have enough credits to generate a mockup.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

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
        'imageType' => 'mockup',
        'extension' => 'jpg',
    ];

    $contextId = 'combination_' . $combinationIndex;
    $worldMotherReferenceMode = (string)($combination['world_mother_reference_mode'] ?? 'literal_scene_view');
    $worldMotherGeneratorPath = $worldMotherReferenceMode === 'literal_scene_view' ? $worldMotherPath : '';
    $result = ServiceFactory::mockupGenerator()->generate($rootPath, $contextId, (string)$combination['final_prompt_preview'], [
        'seo_params' => $seoParams,
        'root_reference_path' => $rootPath,
        'world_mother_reference_path' => $worldMotherGeneratorPath,
        'world_mother_reference_mode' => $worldMotherReferenceMode,
        'world_mother_reference_path_original' => $worldMotherPath,
        'prompt_passthrough_mode' => (string)$combination['final_prompt_preview'],
        'mockup_combination' => $combination,
    ]);

    $selectorState = [
        'generation_source' => 'mockup_combination_review',
        'audit_file' => 'analysis/mockup-combination-audit/' . $artworkId . '/' . $auditFile,
        'combination' => $combination,
    ];

    $mockupId = (int)Database::withBusyRetry(function () use ($user, $artwork, $result, $contextId, $selectorState): int {
        $stmtInsert = Database::connection()->prepare("
            INSERT INTO mockups (user_id, artwork_file, mockup_file, context_id, prompt_file, selector_state_json, created_at)
            VALUES (:user_id, :artwork_file, :mockup_file, :context_id, :prompt_file, :selector_state_json, :created_at)
        ");
        $stmtInsert->execute([
            'user_id' => (int)$user['id'],
            'artwork_file' => basename((string)$artwork['root_file']),
            'mockup_file' => basename((string)$result['file']),
            'context_id' => $contextId,
            'prompt_file' => basename((string)$result['prompt_file']),
            'selector_state_json' => json_encode($selectorState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => date('c'),
        ]);
        return (int)Database::connection()->lastInsertId();
    }, 24);

    $audit['status'] = 'generated';
    $audit['completed_at'] = date(DATE_ATOM);
    $audit['mockup_id'] = $mockupId;
    $audit['mockup_file'] = basename((string)$result['file']);
    $audit['prompt_file'] = basename((string)$result['prompt_file']);
    file_put_contents($auditPath, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    echo json_encode([
        'ok' => true,
        'message' => 'Combination image generated.',
        'mockup_id' => $mockupId,
        'mockup_file' => basename((string)$result['file']),
        'audit_file' => 'analysis/mockup-combination-audit/' . $artworkId . '/' . $auditFile,
        'results_url' => 'mockup_combination_results.php?id=' . $artworkId . ($worldMotherCategory !== '' ? '&world_mother_category=' . rawurlencode($worldMotherCategory) : ''),
        'generation_mode' => 'mockup_combination_full_generation',
    ], JSON_UNESCAPED_UNICODE);
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
