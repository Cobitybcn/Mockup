<?php
declare(strict_types=1);

final class MockupGenerationWorker
{
    public function process(int $jobId, string $payloadProvider = ''): array
    {
        $job = $this->claim($jobId);
        if (!$job) {
            return ['ok' => true, 'claimed' => false, 'job_id' => $jobId];
        }
        if (!empty($job['_already_done'])) {
            return [
                'ok' => true,
                'claimed' => false,
                'job_id' => $jobId,
                'mockup_id' => (int)($job['mockup_id'] ?? 0),
            ];
        }

        $pdo = Database::connection();
        $selectorState = json_decode((string)($job['selector_state_json'] ?? ''), true);
        $selectorState = is_array($selectorState) ? $selectorState : [];

        try {
            $artworkFile = basename((string)$job['artwork_file']);
            $localArtworkPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $artworkFile;
            $this->ensureLocalFile($localArtworkPath, 'results/' . $artworkFile, 'Root artwork');

            $generationProvider = ServiceFactory::generationProvider((string)($selectorState['generation_provider'] ?? ''));
            $payloadProvider = strtolower(trim($payloadProvider));
            if ($payloadProvider !== '' && ServiceFactory::generationProvider($payloadProvider) !== $generationProvider) {
                throw new RuntimeException('Generation provider mismatch between queued job and worker payload.');
            }

            $combination = (array)($selectorState['combination'] ?? []);
            $category = basename((string)($selectorState['world_mother_category'] ?? ''));
            $storedWorldMotherPath = (string)($combination['world_mother_image_absolute_path'] ?? '');
            $worldMotherFile = basename($storedWorldMotherPath);
            $worldMotherPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
                . 'world_mothers' . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . $worldMotherFile;
            if (!is_file($worldMotherPath) && $worldMotherFile !== '' && StorageService::isGcsActive()) {
                $this->ensureLocalFile($worldMotherPath, 'storage/world_mothers/' . $category . '/' . $worldMotherFile, 'Scene reference');
            }
            if (!is_file($worldMotherPath) && is_file($storedWorldMotherPath)) {
                $worldMotherPath = $storedWorldMotherPath;
            }
            if (!is_file($worldMotherPath)) {
                throw new RuntimeException('Scene reference image was not found.');
            }

            $stmtArtwork = $pdo->prepare('SELECT * FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
            $stmtArtwork->execute(['id' => (int)$job['artwork_id'], 'user_id' => (int)$job['user_id']]);
            $artwork = $stmtArtwork->fetch();
            if (!$artwork) {
                throw new RuntimeException('Artwork for this generation no longer exists.');
            }

            $profile = ArtistProfile::findForUser((int)$job['user_id']);
            $artworkTitle = trim((string)($artwork['final_title'] ?? ''));
            if ($artworkTitle === '') {
                $artworkTitle = Display::artworkTitle($artworkFile);
            }
            $seoParams = [
                'artistName' => trim((string)($profile['artist_name'] ?? '')),
                'artworkTitle' => $artworkTitle,
                'mockupContext' => (string)($combination['context_title'] ?? 'mockup combination'),
                'cameraAngle' => (string)($combination['selected_camera_slot_id'] ?? ''),
                'cameraSlotName' => (string)($combination['camera_slot_name'] ?? ''),
                'imageType' => 'mockup',
                'extension' => 'jpg',
            ];

            ProviderSettings::set(ProviderSettings::readForRoot($localArtworkPath));
            $generator = ServiceFactory::mockupGenerator($generationProvider);
            $result = $generator->generate($localArtworkPath, (string)$job['context_id'], (string)$job['prompt'], [
                'seo_params' => $seoParams,
                'root_reference_path' => $localArtworkPath,
                'world_mother_reference_path' => $worldMotherPath,
                'world_mother_reference_mode' => (string)($selectorState['world_mother_reference_mode'] ?? 'reconstructed_view'),
                'world_mother_reference_path_original' => $worldMotherPath,
                'world_mother_scale' => (string)($selectorState['world_mother_scale'] ?? ''),
                'prompt_passthrough_mode' => (string)$job['prompt'],
                'skip_world_visual_enhancer' => true,
                'slot_full_prompt_mode' => AdminPromptComposerPreview::hasSlotFullPromptTemplate(
                    (string)($combination['selected_camera_slot_id'] ?? '')
                ),
                'mockup_combination' => $combination,
            ]);

            if (array_key_exists('fidelity_review', $result)) {
                $selectorState['fidelity_validation'] = [
                    'review' => $result['fidelity_review'],
                    'attempts' => (int)($result['fidelity_attempts'] ?? 1),
                    'rejected_candidates' => (int)($result['fidelity_rejected_candidates'] ?? 0),
                    'reviews' => $result['fidelity_reviews'] ?? [],
                ];
            }

            $generatedImage = RESULTS_DIR . DIRECTORY_SEPARATOR . basename((string)$result['file']);
            $generatedPrompt = PROMPTS_DIR . DIRECTORY_SEPARATOR . basename((string)$result['prompt_file']);
            if (StorageService::isGcsActive()) {
                if (!StorageService::uploadFile('results/' . basename((string)$result['file']), $generatedImage)
                    || !StorageService::uploadFile('mockup-prompts/' . basename((string)$result['prompt_file']), $generatedPrompt)) {
                    throw new RuntimeException('Failed to publish generated mockup files.');
                }
            }

            $mockupId = (int)Database::withBusyRetry(function () use ($job, $result, $selectorState): int {
                $stmt = Database::connection()->prepare('
                    INSERT INTO mockups (user_id, artwork_group_id, source_artwork_id, artwork_file, mockup_file, context_id, prompt_file, selector_state_json, created_at)
                    VALUES (:user_id, :artwork_group_id, :source_artwork_id, :artwork_file, :mockup_file, :context_id, :prompt_file, :selector_state_json, :created_at)
                ');
                $stmt->execute([
                    'user_id' => (int)$job['user_id'],
                    'artwork_group_id' => $job['artwork_group_id'],
                    'source_artwork_id' => $job['source_artwork_id'],
                    'artwork_file' => basename((string)$job['artwork_file']),
                    'mockup_file' => basename((string)$result['file']),
                    'context_id' => (string)$job['context_id'],
                    'prompt_file' => basename((string)$result['prompt_file']),
                    'selector_state_json' => json_encode($selectorState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'created_at' => date('c'),
                ]);
                return (int)Database::connection()->lastInsertId();
            }, 24);

            Database::withBusyRetry(function () use ($jobId, $mockupId, $result): void {
                Database::connection()->prepare('
                    UPDATE mockup_generation_jobs
                    SET status = "done", mockup_id = :mockup_id, mockup_file = :mockup_file,
                        prompt_file = :prompt_file, error = NULL, updated_at = :updated_at
                    WHERE id = :id AND status = "processing"
                ')->execute([
                    'mockup_id' => $mockupId,
                    'mockup_file' => basename((string)$result['file']),
                    'prompt_file' => basename((string)$result['prompt_file']),
                    'updated_at' => date('c'),
                    'id' => $jobId,
                ]);
            }, 12);

            $this->updateAudit($selectorState, [
                'status' => 'generated',
                'completed_at' => date(DATE_ATOM),
                'mockup_id' => $mockupId,
                'mockup_file' => basename((string)$result['file']),
                'prompt_file' => basename((string)$result['prompt_file']),
                'error' => '',
            ]);

            if (ProviderSettings::isRealMode() && ProviderSettings::allowRealApi() && $generationProvider === 'gemini') {
                try {
                    $sheetService = new ArtworkSheetService($pdo);
                    $artworkSheet = $sheetService->sheetForArtwork((int)$artwork['id'], (int)$job['user_id']);
                    $sheetService->generateMockupSheet(
                        (int)$artworkSheet['id'],
                        (int)$artwork['id'],
                        basename((string)$result['file']),
                        (int)$job['user_id'],
                        'Automatic mockup analysis v2 during background batch creation.'
                    );
                } catch (Throwable $sheetError) {
                    Logger::log(
                        'Mockup v2 analysis was not generated for mockup #' . $mockupId . ': ' . $sheetError->getMessage(),
                        'analysis_warning'
                    );
                }
            }

            try {
                NextPlatformSync::run();
            } catch (Throwable $syncError) {
                Logger::log('Background generation sync warning: ' . $syncError->getMessage(), 'analysis_warning');
            }

            if (StorageService::isGcsActive()) {
                @unlink($generatedImage);
                @unlink($generatedPrompt);
            }

            return [
                'ok' => true,
                'claimed' => true,
                'job_id' => $jobId,
                'mockup_id' => $mockupId,
                'mockup_file' => basename((string)$result['file']),
            ];
        } catch (Throwable $e) {
            Database::failGenerationAndRefund((int)$job['user_id'], $jobId, $e->getMessage());
            $this->updateAudit($selectorState, [
                'status' => 'failed',
                'completed_at' => date(DATE_ATOM),
                'error' => $e->getMessage(),
            ]);
            Logger::log('Background mockup job #' . $jobId . ' failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'claimed' => true, 'job_id' => $jobId, 'error' => $e->getMessage()];
        }
    }

    private function claim(int $jobId): ?array
    {
        return Database::withBusyRetry(function () use ($jobId): ?array {
            $pdo = Database::connection();
            Database::beginWriteTransaction($pdo);
            try {
                $sql = 'SELECT * FROM mockup_generation_jobs WHERE id = :id LIMIT 1';
                if (Database::isMysql()) {
                    $sql .= ' FOR UPDATE';
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $jobId]);
                $job = $stmt->fetch();
                if (!$job) {
                    $pdo->exec('COMMIT');
                    return null;
                }
                if ((string)$job['status'] === 'done') {
                    $job['_already_done'] = true;
                    $pdo->exec('COMMIT');
                    return $job;
                }
                if (!in_array((string)$job['status'], ['pending_enqueue', 'queued'], true)) {
                    $pdo->exec('COMMIT');
                    return null;
                }
                $pdo->prepare('
                    UPDATE mockup_generation_jobs
                    SET status = "processing", attempts = attempts + 1, updated_at = :updated_at
                    WHERE id = :id
                ')->execute(['updated_at' => date('c'), 'id' => $jobId]);
                $job['status'] = 'processing';
                $job['attempts'] = (int)$job['attempts'] + 1;
                $pdo->exec('COMMIT');
                return $job;
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
        }, 12);
    }

    private function ensureLocalFile(string $localPath, string $storagePath, string $label): void
    {
        if (is_file($localPath)) {
            return;
        }
        if (!StorageService::isGcsActive() || !StorageService::downloadFile($storagePath, $localPath) || !is_file($localPath)) {
            throw new RuntimeException($label . ' file was not found.');
        }
    }

    private function updateAudit(array $selectorState, array $changes): void
    {
        $relative = str_replace('\\', '/', trim((string)($selectorState['audit_file'] ?? '')));
        if (!str_starts_with($relative, 'analysis/mockup-combination-audit/')) {
            return;
        }
        $base = dirname(__DIR__, 2);
        $path = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            return;
        }
        $audit = json_decode((string)file_get_contents($path), true);
        if (!is_array($audit)) {
            return;
        }
        file_put_contents($path, json_encode(array_merge($audit, $changes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
