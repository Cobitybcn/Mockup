<?php
declare(strict_types=1);

// Ensure PHP warnings/notices never leak into the JSON output stream.
ini_set('display_errors', '0');

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!defined('LEGACY_MOCKUP_FLOW_ENABLED') || !LEGACY_MOCKUP_FLOW_ENABLED) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Legacy mockup context analysis disabled. Use the direct world mother combination flow (mockup_combinations_review.php).'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // 1. Require authentication
    $user = Auth::requireUser();

    // 2. Parse and validate inputs
    $artworkId = isset($_POST['artwork_id']) ? (int)$_POST['artwork_id'] : (isset($_GET['artwork_id']) ? (int)$_GET['artwork_id'] : 0);
    $contextId = isset($_POST['context_id']) ? (int)$_POST['context_id'] : (isset($_GET['context_id']) ? (int)$_GET['context_id'] : 0);

    if ($artworkId <= 0 || $contextId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing artwork_id or context_id.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = Database::connection();

    // 3. Load and verify artwork ownership
    $stmtArtwork = $pdo->prepare('SELECT * FROM artworks WHERE id = :id LIMIT 1');
    $stmtArtwork->execute(['id' => $artworkId]);
    $artwork = $stmtArtwork->fetch();

    if (!$artwork) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Artwork not found.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$artwork['user_id'] !== (int)$user['id']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. Load context proposal from mockup_contexts
    $stmtContext = $pdo->prepare('SELECT * FROM mockup_contexts WHERE id = :id AND artwork_id = :artwork_id LIMIT 1');
    $stmtContext->execute(['id' => $contextId, 'artwork_id' => $artworkId]);
    $contextRow = $stmtContext->fetch();

    if (!$contextRow) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Mockup context not found for this artwork.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 5. Determine proposal index (1-based index based on ID sort order)
    $stmtAllContexts = $pdo->prepare('SELECT id FROM mockup_contexts WHERE artwork_id = :artwork_id ORDER BY id ASC');
    $stmtAllContexts->execute(['artwork_id' => $artworkId]);
    $allIds = $stmtAllContexts->fetchAll(PDO::FETCH_COLUMN);
    $proposalIndex = array_search($contextId, $allIds);
    $proposalIndex = ($proposalIndex !== false) ? $proposalIndex + 1 : 1;

    // 6. Parse details for audit fields
    $contextJson = json_decode((string)($contextRow['context_json'] ?? ''), true);
    if (!is_array($contextJson)) {
        $contextJson = [];
    }

    // 7. Compose prompt
    $composer = new AdminPromptComposerPreview();
    $composedPrompt = $composer->compose($contextRow);
    $slotFullPromptMode = AdminPromptComposerPreview::hasSlotFullPromptTemplate(
        (string)($contextJson['camera_slot_id'] ?? $contextRow['camera_slot_id'] ?? '')
    );

    // 8. Security/Invariant Check: Verify that we pass composed prompt exactly.
    // This used to compare $composedPrompt against itself (always true, never
    // caught anything). The real downstream step for a real $contextId is
    // GeminiMockupGenerator::finalPrompt() / OpenAIMockupGenerator::finalPrompt(),
    // which run $composedPrompt through MockupWorldVisualPromptEnhancer with this
    // context's real numeric id — unlike the direct-combination flow, that id DOES
    // match a mockup_contexts row here, so the enhancer is genuinely active and can
    // append a "WORLD VISUAL CONTRACT" block (see docs/AUDITORIA_PROMPTS_MOCKUPS_20260701.md,
    // Fase 4). We replicate that exact call so this check reflects reality. This does
    // NOT change what gets generated (the real generator call below is untouched);
    // it only makes the audit trail honest. Not used with the Mock provider, which
    // bypasses the enhancer entirely by design.
    $finalPromptSentToVertex = $slotFullPromptMode
        ? $composedPrompt
        : (new MockupWorldVisualPromptEnhancer())->enhancePromptForContextId($composedPrompt, (string)$contextId);
    $promptExactMatch = ($composedPrompt === $finalPromptSentToVertex);

    // 9. Prepare Pre-generation Audit JSON
    $timestamp = date('Ymd-His') . '-' . random_int(1000, 9999);
    $auditFilename = "composed-admin-context-{$contextId}-{$timestamp}.generation.json";
    
    $auditDir = __DIR__ . '/analysis/mockup-generation-audit/' . $artworkId;
    if (!is_dir($auditDir)) {
        mkdir($auditDir, 0775, true);
    }
    
    $auditPath = $auditDir . '/' . $auditFilename;

    $initialWarnings = [];
    if (!$promptExactMatch) {
        $initialWarnings[] = 'World visual contract enhancer modified the composed prompt before it reached the generator (added '
            . (strlen($finalPromptSentToVertex) - strlen($composedPrompt)) . ' characters). '
            . 'composed_final_admin_prompt is NOT what was actually sent; see final_prompt_sent_to_vertex.';
    }

    $auditData = [
        'schema' => 'mockup_generation_audit.v1',
        'generation_source' => 'composed_admin_prompt',
        'prompt_authority' => 'admin_v7_mockup_final_request',
        'artwork_id' => $artworkId,
        'context_id' => $contextId,
        'proposal_index' => $proposalIndex,
        'context_name' => $contextRow['context_name'] ?? '',
        'admin_prompt_source' => 'app_settings.mockup_final_request',
        'admin_prompt_placeholder' => '{{MOCKUP_CONTEXT_PROPOSAL}}',
        'context_block_inserted' => (new ReflectionClass($composer))->getMethod('buildContextBlock')->invoke(
            $composer,
            (new ReflectionClass($composer))->getMethod('parseContextFields')->invoke($composer, $contextRow)
        ),
        'composed_final_admin_prompt' => $composedPrompt,
        'final_prompt_sent_to_vertex' => $finalPromptSentToVertex,
        'prompt_exact_match' => $promptExactMatch,
        'camera_view' => $contextJson['camera_view'] ?? '',
        'camera_distance' => $contextJson['camera_distance'] ?? '',
        'queued_or_generated_mockup_id' => null,
        'queued_or_generated_mockup_file' => null,
        'queued_or_generated_at' => date('c'),
        'status' => 'prepared',
        'warnings' => $initialWarnings,
    ];

    // Write initial audit JSON file
    file_put_contents($auditPath, json_encode($auditData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // 10. Deduct credit if real API allowed
    $creditDeducted = false;
    if (ProviderSettings::allowRealApi()) {
        if (!Database::deductCredit((int)$user['id'], 'composed_admin_mockup_generation:' . $contextId)) {
            $auditData['status'] = 'failed';
            $auditData['warnings'][] = 'Insufficient credits.';
            file_put_contents($auditPath, json_encode($auditData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'You do not have enough credits to generate a mockup.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $creditDeducted = true;
    }

    // 11. Run generation
    $imagePath = RESULTS_DIR . '/' . basename($artwork['root_file']);
    if (!is_file($imagePath)) {
        throw new RuntimeException('Root artwork image file not found: ' . $artwork['root_file']);
    }

    // Inject provider settings for the root artwork
    ProviderSettings::set(ProviderSettings::readForRoot($imagePath));

    $artistProfile = ArtistProfile::findForUser((int)$artwork['user_id']);
    $artistName = trim((string)($artistProfile['artist_name'] ?? ''));
    $artworkTitle = trim((string)($artwork['final_title'] ?? ''));
    if ($artworkTitle === '') {
        $artworkTitle = Display::artworkTitle($artwork['root_file']);
    }

    $seoParams = [
        'artistName' => $artistName,
        'artworkTitle' => $artworkTitle,
        'mockupContext' => $contextRow['context_name'],
        'cameraAngle' => $contextJson['camera_group'] ?? '',
        'imageType' => 'mockup',
        'extension' => 'jpg',
    ];

    $generator = ServiceFactory::mockupGenerator();
    $result = $generator->generate($imagePath, (string)$contextId, $composedPrompt, [
        'json' => trim((string)($artwork['analysis_json'] ?? '')), // analysis_json lives in artwork_analysis, not artworks; safe fallback for passthrough mode
        'seo_params' => $seoParams,
        'root_reference_path' => $imagePath,
        'prompt_passthrough_mode' => $composedPrompt, // Strict passthrough mode
        // This call sends a single reference image (no world_mother_reference_path), so
        // vertex_bridge.py's precomposition/fill_ratio block is structurally reachable if
        // MOCKUP_USE_PRECOMPOSITION were ever re-enabled globally (unlike the direct-combination
        // flow, which always sends 2+ images and is immune by structure — see
        // docs/AUDITORIA_PROMPTS_MOCKUPS_20260701.md, Fase 5). This flag forces it off for this
        // call specifically, independent of the global flag.
        'force_disable_precomposition' => true,
        'slot_full_prompt_mode' => $slotFullPromptMode,
    ]);

    // 12. Save mockup to database
    $mockupId = (int)Database::withBusyRetry(function () use ($user, $artwork, $result, $contextId): int {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO mockups (user_id, artwork_file, mockup_file, context_id, prompt_file, selector_state_json, created_at)
            VALUES (:user_id, :artwork_file, :mockup_file, :context_id, :prompt_file, '{}', :created_at)
        ");
        $stmt->execute([
            'user_id' => (int)$user['id'],
            'artwork_file' => basename($artwork['root_file']),
            'mockup_file' => basename((string)$result['file']),
            'context_id' => (string)$contextId,
            'prompt_file' => basename((string)$result['prompt_file']),
            'created_at' => date('c'),
        ]);
        return (int)$pdo->lastInsertId();
    }, 24);

    if ($mockupId > 0) {
        try {
            Logger::logMockupGeneration(
                $mockupId,
                (int)$artworkId,
                (string)$contextId,
                $composedPrompt,
                $contextJson['camera_view'] ?? '',
                $contextJson['human_presence'] ?? ''
            );
        } catch (Throwable $logEx) {
            Logger::log("Failed to log composed mockup generation: " . $logEx->getMessage(), 'error');
        }
    }

    // 13. Update Audit JSON with result
    $auditData['queued_or_generated_mockup_id'] = $mockupId;
    $auditData['queued_or_generated_mockup_file'] = basename((string)$result['file']);
    $auditData['status'] = 'generated';
    file_put_contents($auditPath, json_encode($auditData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    echo json_encode([
        'ok' => true,
        'mockup_id' => $mockupId,
        'mockup_file' => basename((string)$result['file']),
        'audit_file' => 'analysis/mockup-generation-audit/' . $artworkId . '/' . $auditFilename,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($auditPath) && isset($auditData)) {
        $auditData['status'] = 'failed';
        $auditData['warnings'][] = $e->getMessage();
        file_put_contents($auditPath, json_encode($auditData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
