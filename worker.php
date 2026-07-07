<?php
// worker.php
declare(strict_types=1);

// Ensure warnings/notices never leak into Cloud Run response stream
ini_set('display_errors', '0');

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Parse and validate input
    $rawInput = file_get_contents('php://input');
    $payload = json_decode((string)$rawInput, true);

    if (!is_array($payload) || empty($payload['job_id'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing job_id in payload.']);
        exit;
    }

    $jobId = (int)$payload['job_id'];
    $pdo = Database::connection();

    // 2. Fetch job details
    $stmtJob = $pdo->prepare('SELECT * FROM mockup_generation_jobs WHERE id = :id LIMIT 1');
    $stmtJob->execute(['id' => $jobId]);
    $job = $stmtJob->fetch();

    if (!$job) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Job not found.']);
        exit;
    }

    // 3. Idempotency check: If already completed, ignore and return 200 OK
    if ($job['status'] === 'done') {
        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'Job already completed previously (Idempotent bypass).']);
        exit;
    }

    // 4. Retry check: If attempts exceed threshold, mark as error and reject
    $attempts = (int)$job['attempts'] + 1;
    if ($attempts > 3) {
        $pdo->prepare('UPDATE mockup_generation_jobs SET status = "error", error = "Max attempts exceeded", attempts = :attempts, updated_at = :now WHERE id = :id')
            ->execute(['attempts' => $attempts, 'now' => date('c'), 'id' => $jobId]);
        http_response_code(200); // Stop Cloud Tasks retries
        echo json_encode(['ok' => false, 'error' => 'Max attempts exceeded. Marked job as error.']);
        exit;
    }

    // Update status to processing and increment attempts
    $pdo->prepare('UPDATE mockup_generation_jobs SET status = "processing", attempts = :attempts, updated_at = :now WHERE id = :id')
        ->execute(['attempts' => $attempts, 'now' => date('c'), 'id' => $jobId]);

    // 5. Ensure root artwork is available locally
    $artworkFile = basename((string)$job['artwork_file']);
    $localArtworkPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $artworkFile;

    if (!is_file($localArtworkPath)) {
        if (StorageService::isGcsActive()) {
            $gcsPath = 'results/' . $artworkFile;
            $gcsContent = StorageService::get($gcsPath);
            if ($gcsContent === null) {
                throw new RuntimeException("Could not retrieve root artwork '{$gcsPath}' from GCS.");
            }
            $dir = dirname($localArtworkPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($localArtworkPath, $gcsContent);
        } else {
            throw new RuntimeException("Root artwork file not found locally and GCS is inactive.");
        }
    }

    // 6. Decode generation options
    $selectorState = json_decode((string)($job['selector_state_json'] ?? ''), true);
    if (!is_array($selectorState)) {
        $selectorState = [];
    }

    $combination = $selectorState['combination'] ?? [];
    $worldMotherPath = (string)($combination['world_mother_image_absolute_path'] ?? '');
    
    // Resolve world mother path inside the worker container directory structure
    $category = basename((string)($selectorState['world_mother_category'] ?? ''));
    $fileName = basename($worldMotherPath);
    $containerWorldMotherPath = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'world_mothers' . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . $fileName;

    if (!is_file($containerWorldMotherPath)) {
        if (StorageService::isGcsActive()) {
            $gcsPath = 'storage/world_mothers/' . $category . '/' . $fileName;
            $dir = dirname($containerWorldMotherPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            StorageService::downloadFile($gcsPath, $containerWorldMotherPath);
        }
        if (!is_file($containerWorldMotherPath)) {
            $containerWorldMotherPath = $worldMotherPath;
        }
    }

    // 7. Load SEO params for the generator
    $stmtArtwork = $pdo->prepare('SELECT * FROM artworks WHERE id = :id LIMIT 1');
    $stmtArtwork->execute(['id' => $job['artwork_id']]);
    $artwork = $stmtArtwork->fetch();

    $artistName = '';
    $artworkTitle = '';
    if ($artwork) {
        $artistProfile = ArtistProfile::findForUser((int)$artwork['user_id']);
        $artistName = trim((string)($artistProfile['artist_name'] ?? ''));
        $artworkTitle = trim((string)($artwork['final_title'] ?? ''));
        if ($artworkTitle === '') {
            $artworkTitle = Display::artworkTitle((string)$artwork['root_file']);
        }
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

    // Set provider settings configured for this artwork root
    ProviderSettings::set(ProviderSettings::readForRoot($localArtworkPath));

    $options = [
        'seo_params' => $seoParams,
        'root_reference_path' => $localArtworkPath,
        'world_mother_reference_path' => $containerWorldMotherPath,
        'world_mother_reference_mode' => (string)($selectorState['world_mother_reference_mode'] ?? 'literal_scene_view'),
        'world_mother_reference_path_original' => $containerWorldMotherPath,
        'world_mother_scale' => (string)($selectorState['world_mother_scale'] ?? ''),
        'prompt_passthrough_mode' => $job['prompt'],
        'skip_world_visual_enhancer' => true,
        'slot_full_prompt_mode' => AdminPromptComposerPreview::hasSlotFullPromptTemplate(
            (string)($combination['selected_camera_slot_id'] ?? '')
        ),
        'mockup_combination' => $combination,
    ];

    // 8. Execute generation
    $generator = ServiceFactory::mockupGenerator();
    $result = $generator->generate($localArtworkPath, $job['context_id'], $job['prompt'], $options);

    $generatedImageLocal = RESULTS_DIR . DIRECTORY_SEPARATOR . basename((string)$result['file']);
    $generatedPromptLocal = RESULTS_DIR . DIRECTORY_SEPARATOR . basename((string)$result['prompt_file']);

    // 9. Persist result files to GCS if active
    if (StorageService::isGcsActive()) {
        $uploadedImg = StorageService::uploadFile('results/' . basename($result['file']), $generatedImageLocal);
        $uploadedPrompt = StorageService::uploadFile('results/' . basename($result['prompt_file']), $generatedPromptLocal);

        if (!$uploadedImg || !$uploadedPrompt) {
            throw new RuntimeException('Failed to upload generation output files to GCS.');
        }

        // Clean up container disk space (ephemeral container)
        @unlink($generatedImageLocal);
        @unlink($generatedPromptLocal);
    }

    // 10. Save final mockup record in DB
    $mockupId = 0;
    Database::withBusyRetry(function () use ($job, $result, $selectorState, &$mockupId): void {
        $pdo = Database::connection();
        $stmtInsert = $pdo->prepare("
            INSERT INTO mockups (user_id, artwork_group_id, source_artwork_id, artwork_file, mockup_file, context_id, prompt_file, selector_state_json, created_at)
            VALUES (:user_id, :artwork_group_id, :source_artwork_id, :artwork_file, :mockup_file, :context_id, :prompt_file, :selector_state_json, :created_at)
        ");
        $stmtInsert->execute([
            'user_id' => (int)$job['user_id'],
            'artwork_group_id' => $job['artwork_group_id'],
            'source_artwork_id' => $job['source_artwork_id'],
            'artwork_file' => basename((string)$job['artwork_file']),
            'mockup_file' => basename((string)$result['file']),
            'context_id' => $job['context_id'],
            'prompt_file' => basename((string)$result['prompt_file']),
            'selector_state_json' => json_encode($selectorState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => date('c'),
        ]);
        $mockupId = (int)$pdo->lastInsertId();
    }, 24);

    // 11. Complete job in DB
    $pdo->prepare('
        UPDATE mockup_generation_jobs
        SET status = "done", mockup_id = :mockup_id, mockup_file = :mockup_file, prompt_file = :prompt_file, error = NULL, updated_at = :now
        WHERE id = :id
    ')->execute([
        'mockup_id' => $mockupId,
        'mockup_file' => basename($result['file']),
        'prompt_file' => basename($result['prompt_file']),
        'now' => date('c'),
        'id' => $jobId
    ]);

    echo json_encode([
        'ok' => true,
        'message' => 'Job executed and saved successfully.',
        'job_id' => $jobId,
        'mockup_id' => $mockupId,
        'mockup_file' => basename($result['file'])
    ]);

} catch (Throwable $e) {
    // Save error inside job table
    if (isset($pdo, $jobId)) {
        try {
            $pdo->prepare('UPDATE mockup_generation_jobs SET status = "error", error = :error, updated_at = :now WHERE id = :id')
                ->execute([
                    'error' => $e->getMessage(),
                    'now' => date('c'),
                    'id' => $jobId
                ]);
        } catch (Throwable $dbErr) {
        }
    }

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
