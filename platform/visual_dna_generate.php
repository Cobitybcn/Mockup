<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST is required.');
    }
    if (!filter_var(app_env('STUDIO_REFERENCES_LAB_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN)) {
        http_response_code(404);
        throw new RuntimeException('Visual DNA LAB is disabled.');
    }

    $user = Auth::requireUser();
    if (!Auth::isAdmin($user)) {
        http_response_code(403);
        throw new RuntimeException('You do not have access to this ADMIN LAB.');
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid Visual DNA generation payload.');
    }
    $expectedCsrf = (string)($_SESSION['reference_set_csrf'] ?? '');
    if ($expectedCsrf === '' || !hash_equals($expectedCsrf, (string)($input['csrf'] ?? ''))) {
        http_response_code(403);
        throw new RuntimeException('The editor session expired. Reload the page and try again.');
    }

    $artworkId = max(0, (int)($input['artwork_id'] ?? 0));
    $referenceSetId = max(0, (int)($input['reference_set_id'] ?? 0));
    if ($artworkId <= 0 || $referenceSetId <= 0) {
        throw new InvalidArgumentException('Choose an artwork and a saved Visual DNA.');
    }

    $pdo = Database::connection();
    $artworkStmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
    $artworkStmt->execute(['id' => $artworkId, 'user_id' => (int)$user['id']]);
    $artwork = $artworkStmt->fetch();
    if (!is_array($artwork)) {
        throw new InvalidArgumentException('The selected artwork is not available.');
    }
    $artworkFile = basename((string)($artwork['root_file'] ?? ''));
    if ($artworkFile === '') {
        throw new InvalidArgumentException('The selected artwork does not have an official root image.');
    }

    $referenceSetService = new ReferenceSetService($pdo);
    $referenceSet = $referenceSetService->findForUser((int)$user['id'], $referenceSetId, true);
    if (!$referenceSet) {
        throw new InvalidArgumentException('The selected Visual DNA is not available.');
    }
    $realReferences = (new ReferenceAssetService($pdo))->referencesForSet((int)$user['id'], $referenceSetId, 6);
    if (!$realReferences) {
        throw new InvalidArgumentException('This Visual DNA contains only demo material. Upload real references and save a new set first.');
    }

    ServiceFactory::mockupGenerator('gemini');
    $prompt = VisualDnaLabMockupGenerator::buildPrompt($referenceSet);
    $contextId = 'visual-dna-lab-set-' . $referenceSetId;
    $requestedKey = strtolower(trim((string)($input['idempotency_key'] ?? '')));
    if ($requestedKey === '' || !preg_match('/^[a-z0-9-]{16,80}$/', $requestedKey)) {
        $requestedKey = bin2hex(random_bytes(16));
    }
    $idempotencyKey = hash('sha256', 'visual_dna_lab_v1|' . (int)$user['id'] . '|' . $artworkId . '|' . $referenceSetId . '|' . $requestedKey);
    $selectorState = [
        'generation_source' => 'visual_dna_lab',
        'generation_provider' => 'gemini',
        'reference_set_id' => $referenceSetId,
        'reference_set_name' => (string)$referenceSet['name'],
        'reference_asset_ids' => array_map(static fn(array $row): int => (int)$row['id'], $realReferences),
        'idempotency_key' => $idempotencyKey,
    ];

    $jobCreated = false;
    $jobId = Database::createGenerationJobWithTransaction(
        (int)$user['id'],
        $artworkId,
        $contextId,
        $prompt,
        RESULTS_DIR . DIRECTORY_SEPARATOR . $artworkFile,
        json_encode($selectorState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $idempotencyKey,
        $jobCreated
    );

    if ($jobCreated) {
        try {
            Database::withBusyRetry(function () use ($pdo, $jobId, $referenceSetId): void {
                $pdo->prepare('UPDATE mockup_generation_jobs SET reference_set_id = :reference_set_id WHERE id = :id')
                    ->execute(['reference_set_id' => $referenceSetId, 'id' => $jobId]);
            }, 12);
            $dispatchMode = (new MockupGenerationDispatcher())->dispatch(
                $jobId,
                (int)$user['id'],
                $artworkId,
                $contextId,
                'gemini'
            );
        } catch (Throwable $dispatchError) {
            Database::failEnqueueAndRefund((int)$user['id'], $jobId, $dispatchError->getMessage());
            throw $dispatchError;
        }
    } else {
        $dispatchMode = 'existing_job';
    }

    echo json_encode([
        'ok' => true,
        'job_id' => $jobId,
        'deduplicated' => !$jobCreated,
        'dispatch_mode' => $dispatchMode,
        'message' => 'Visual DNA test generation continues in the isolated LAB connection.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (http_response_code() < 400) {
        http_response_code(500);
    }
    error_log('Visual DNA LAB generation failed: ' . $error->getMessage());
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
