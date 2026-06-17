<?php
declare(strict_types=1);

ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');
ini_set('log_errors', '1');
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');

require_once __DIR__ . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$artworkId = isset($argv[1]) ? (int)$argv[1] : null;
$maxJobs = isset($argv[2]) ? max(1, min(20, (int)$argv[2])) : MockupBatchQueue::INITIAL_BATCH_LIMIT;
$preAssignedJobId = isset($argv[3]) ? (int)$argv[3] : null;

// --- Pre-assignment mode (Opción A parallel strategy) ---
// If a specific job ID was pre-assigned by the launcher, process only that job.
// The job was already marked "processing" by claimBatch() in select_root.php,
// so no lock competition with other workers.
if ($preAssignedJobId > 0) {
    $job = MockupBatchQueue::claimById($preAssignedJobId);

    if ($job) {
        $creditDeducted = false;

        try {
            $rootFile = basename((string)$job['artwork_file']);
            $imagePath = RESULTS_DIR . DIRECTORY_SEPARATOR . $rootFile;
            if (!is_file($imagePath)) {
                throw new RuntimeException('No se encontro la imagen raiz para generar el mockup automatico.');
            }

            $existing = Database::connection()->prepare('
                SELECT * FROM mockups
                WHERE user_id = :user_id AND artwork_file = :artwork_file AND context_id = :context_id
                ORDER BY id DESC
                LIMIT 1
            ');
            $existing->execute([
                'user_id' => (int)$job['user_id'],
                'artwork_file' => $rootFile,
                'context_id' => (string)$job['context_id'],
            ]);
            $existingMockup = $existing->fetch();
            if ($existingMockup) {
                MockupBatchQueue::markDone(
                    (int)$job['id'],
                    (int)$existingMockup['id'],
                    (string)$existingMockup['mockup_file'],
                    (string)$existingMockup['prompt_file']
                );
                exit(0);
            }

            ProviderSettings::set(read_provider_settings_for_queue($imagePath));

            if (ProviderSettings::allowRealApi()) {
                $reason = 'auto_mockup_generation:' . (string)$job['context_id'];
                if (!Database::deductCredit((int)$job['user_id'], $reason)) {
                    throw new RuntimeException('No hay creditos suficientes para generar este mockup automatico.');
                }
                $creditDeducted = true;
            }

            $seoParams = seo_params_for_queue_job($job);
            $generator = ServiceFactory::mockupGenerator();
            $result = $generator->generate($imagePath, (string)$job['context_id'], (string)$job['prompt'], [
                'seo_params' => $seoParams,
            ]);

            $mockupId = (int)Database::withBusyRetry(function () use ($job, $rootFile, $result): int {
                $pdo = Database::connection();
                $stmt = $pdo->prepare('
                    INSERT INTO mockups (user_id, artwork_file, mockup_file, context_id, prompt_file, created_at)
                    VALUES (:user_id, :artwork_file, :mockup_file, :context_id, :prompt_file, :created_at)
                ');
                $stmt->execute([
                    'user_id' => (int)$job['user_id'],
                    'artwork_file' => $rootFile,
                    'mockup_file' => basename((string)$result['file']),
                    'context_id' => (string)$job['context_id'],
                    'prompt_file' => basename((string)$result['prompt_file']),
                    'created_at' => date('c'),
                ]);
                return (int)$pdo->lastInsertId();
            }, 24);

            MockupBatchQueue::markDone(
                (int)$job['id'],
                $mockupId,
                (string)$result['file'],
                (string)$result['prompt_file']
            );
        } catch (Throwable $e) {
            if ($creditDeducted) {
                try {
                    Database::refundCredit((int)$job['user_id'], 'auto_mockup_generation_failed:' . (string)$job['context_id']);
                } catch (Throwable $refundError) {
                    Logger::log('Error al reembolsar credito de mockup automatico: ' . $refundError->getMessage(), 'error');
                }
            }
            Logger::log('Error en worker pre-asignado (job ' . $preAssignedJobId . '): ' . $e->getMessage(), 'error');
            MockupBatchQueue::markError((int)$job['id'], $e->getMessage());
        }
    } else {
        Logger::log('Worker pre-asignado: job ' . $preAssignedJobId . ' no encontrado o no está en estado processing.', 'warn');
    }

    exit(0);
}

// --- Legacy fallback mode: claimNext() competition (used if no pre-assigned job ID) ---

for ($i = 0; $i < $maxJobs; $i++) {
    $job = MockupBatchQueue::claimNext($artworkId ?: null);
    if (!$job) {
        break;
    }

    $creditDeducted = false;

    try {
        $rootFile = basename((string)$job['artwork_file']);
        $imagePath = RESULTS_DIR . DIRECTORY_SEPARATOR . $rootFile;
        if (!is_file($imagePath)) {
            throw new RuntimeException('No se encontro la imagen raiz para generar el mockup automatico.');
        }

        $existing = Database::connection()->prepare('
            SELECT * FROM mockups
            WHERE user_id = :user_id AND artwork_file = :artwork_file AND context_id = :context_id
            ORDER BY id DESC
            LIMIT 1
        ');
        $existing->execute([
            'user_id' => (int)$job['user_id'],
            'artwork_file' => $rootFile,
            'context_id' => (string)$job['context_id'],
        ]);
        $existingMockup = $existing->fetch();
        if ($existingMockup) {
            MockupBatchQueue::markDone(
                (int)$job['id'],
                (int)$existingMockup['id'],
                (string)$existingMockup['mockup_file'],
                (string)$existingMockup['prompt_file']
            );
            continue;
        }

        ProviderSettings::set(read_provider_settings_for_queue($imagePath));

        if (ProviderSettings::allowRealApi()) {
            $reason = 'auto_mockup_generation:' . (string)$job['context_id'];
            if (!Database::deductCredit((int)$job['user_id'], $reason)) {
                throw new RuntimeException('No hay creditos suficientes para generar este mockup automatico.');
            }
            $creditDeducted = true;
        }

        $seoParams = seo_params_for_queue_job($job);
        $generator = ServiceFactory::mockupGenerator();
        $result = $generator->generate($imagePath, (string)$job['context_id'], (string)$job['prompt'], [
            'seo_params' => $seoParams,
        ]);

        $mockupId = (int)Database::withBusyRetry(function () use ($job, $rootFile, $result): int {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('
                INSERT INTO mockups (user_id, artwork_file, mockup_file, context_id, prompt_file, created_at)
                VALUES (:user_id, :artwork_file, :mockup_file, :context_id, :prompt_file, :created_at)
            ');
            $stmt->execute([
                'user_id' => (int)$job['user_id'],
                'artwork_file' => $rootFile,
                'mockup_file' => basename((string)$result['file']),
                'context_id' => (string)$job['context_id'],
                'prompt_file' => basename((string)$result['prompt_file']),
                'created_at' => date('c'),
            ]);

            return (int)$pdo->lastInsertId();
        }, 24);

        MockupBatchQueue::markDone(
            (int)$job['id'],
            $mockupId,
            (string)$result['file'],
            (string)$result['prompt_file']
        );
    } catch (Throwable $e) {
        if ($creditDeducted) {
            try {
                Database::refundCredit((int)$job['user_id'], 'auto_mockup_generation_failed:' . (string)$job['context_id']);
            } catch (Throwable $refundError) {
                Logger::log('Error al reembolsar credito de mockup automatico: ' . $refundError->getMessage(), 'error');
            }
        }

        Logger::log('Error en cola de mockups: ' . $e->getMessage(), 'error');
        MockupBatchQueue::markError((int)$job['id'], $e->getMessage());
    }
}

function read_provider_settings_for_queue(string $imagePath): array
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

function seo_params_for_queue_job(array $job): array
{
    $pdo = Database::connection();
    $artistName = '';
    $artworkTitle = Display::artworkTitle((string)$job['artwork_file']);
    $mockupContext = '';
    $cameraAngle = '';

    $artworkStmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id LIMIT 1');
    $artworkStmt->execute(['id' => (int)$job['artwork_id']]);
    $artwork = $artworkStmt->fetch();
    if ($artwork) {
        $profile = ArtistProfile::findForUser((int)$artwork['user_id']);
        $artistName = trim((string)($profile['artist_name'] ?? ''));
        $storedTitle = trim((string)($artwork['final_title'] ?? ''));
        if ($storedTitle !== '') {
            $artworkTitle = $storedTitle;
        }
    }

    $contextStmt = $pdo->prepare('SELECT * FROM mockup_contexts WHERE id = :id LIMIT 1');
    $contextStmt->execute(['id' => (string)$job['context_id']]);
    $context = $contextStmt->fetch();
    if ($context) {
        $mockupContext = (string)($context['context_name'] ?? '');
        $contextJson = json_decode((string)($context['context_json'] ?? ''), true);
        if (is_array($contextJson)) {
            $cameraAngle = (string)($contextJson['camera_group'] ?? '');
        }
    }

    return [
        'artistName' => $artistName,
        'artworkTitle' => $artworkTitle,
        'mockupContext' => $mockupContext,
        'cameraAngle' => $cameraAngle,
        'imageType' => 'mockup',
        'extension' => 'jpg',
    ];
}
