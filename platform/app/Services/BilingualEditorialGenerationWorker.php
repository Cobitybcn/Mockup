<?php
declare(strict_types=1);

final class BilingualEditorialGenerationWorker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?BilingualEditorialAdapterService $adapter = null
    ) {}

    public function process(int $jobId): array
    {
        $jobs = new BilingualEditorialJobService($this->pdo);
        $existing = $jobs->job($jobId);
        if ((string)$existing['status'] === 'completed') {
            return ['ok' => true, 'job' => $jobs->publicState($existing), 'idempotent' => true];
        }

        $job = $jobs->claim($jobId);
        if (!$job || (string)$job['status'] === 'completed') {
            return ['ok' => true, 'job' => $jobs->publicState($jobs->job($jobId)), 'claimed' => false];
        }

        try {
            $userId = (int)$job['user_id'];
            // Un trabajo encolado no puede seguir generando si la cuenta ya no
            // tiene la capacidad editorial (por ejemplo, tras cambiar de plan).
            if (!FeatureAccess::allowsUserId($userId, FeatureAccess::EDITORIAL_MANAGE, $this->pdo)) {
                throw new RuntimeException('Editorial content requires Artist Pro.');
            }
            $entityType = (string)$job['entity_type'];
            $entityId = (int)$job['entity_id'];
            $action = (string)$job['action'];
            $payload = json_decode((string)$job['payload_json'], true);
            $payload = is_array($payload) ? $payload : [];
            $publishSpanish = !array_key_exists('publish_spanish', $payload) || !empty($payload['publish_spanish']);
            $adapter = $this->adapter ?? new BilingualEditorialAdapterService($this->pdo);
            $editorial = new BilingualEditorialService($this->pdo);
            // Idiomas del artista: master en su idioma de trabajo; adaptacion
            // solo cuando publica en un idioma adicional.
            $workingLocale = $editorial->sourceLocale($userId);
            $adaptationLocale = $editorial->primaryAdaptationTarget($userId);

            if ($action === 'prepare' && $entityType === 'series') {
                $result = $adapter->prepareBilingualSeries(
                    $userId,
                    $entityId,
                    is_array($payload['current_spanish'] ?? null) ? $payload['current_spanish'] : null,
                    array_key_exists('private_memo', $payload) ? (string)$payload['private_memo'] : null,
                    $publishSpanish
                );
            } elseif ($action === 'prepare' && $entityType === 'studio_note') {
                $spanish = $adapter->generateSpanishDraft(
                    $userId,
                    $entityType,
                    $entityId,
                    is_array($payload['current_spanish'] ?? null) ? $payload['current_spanish'] : null,
                    array_key_exists('private_memo', $payload) ? (string)$payload['private_memo'] : null
                );
                $spanishContent = (array)($spanish['content'] ?? []);
                $result = [
                    'spanish_content' => $spanishContent,
                    'spanish_published' => false,
                    'proposal_only' => true,
                    'spanish_first' => true,
                ];
            } elseif ($action === 'prepare') {
                $spanish = $adapter->generateSpanishDraft(
                    $userId,
                    $entityType,
                    $entityId,
                    is_array($payload['current_spanish'] ?? null) ? $payload['current_spanish'] : null,
                    array_key_exists('private_memo', $payload) ? (string)$payload['private_memo'] : null
                );
                $spanishContent = (array)($spanish['content'] ?? []);
                $editorial->save(
                    $userId,
                    $entityType,
                    $entityId,
                    $workingLocale,
                    $spanishContent,
                    (string)($payload['private_memo'] ?? '')
                );
                // La preparacion completa deja el master del idioma de trabajo
                // y, cuando corresponde, su adaptacion lista en una sola accion.
                if ($publishSpanish) {
                    $editorial->setSpanishPublished($userId, $entityType, $entityId, true);
                }
                if ($adaptationLocale !== '') {
                    $english = $adapter->adaptMissing($userId, $entityType, $entityId, $workingLocale, $adaptationLocale);
                    $result = [
                        'spanish_content' => $spanishContent,
                        'english_content' => (array)($english['content'] ?? []),
                        'english_status' => (string)($english['english_status'] ?? 'current'),
                        'spanish_published' => $publishSpanish,
                    ];
                } else {
                    // Publicar solo en el idioma de trabajo no ejecuta ninguna
                    // adaptacion ni deja una columna vacia esperando ingles.
                    $result = [
                        'spanish_content' => $spanishContent,
                        'english_content' => [],
                        'english_status' => 'not_required',
                        'spanish_published' => $publishSpanish,
                    ];
                }
            } elseif ($action === 'publish' && $entityType === 'studio_note') {
                $sourceContent = (array)$editorial->get(
                    $userId,
                    $entityType,
                    $entityId,
                    $workingLocale
                )['content'];
                $expectedSourceHash = trim((string)($payload['source_hash'] ?? ''));
                $actualSourceHash = hash(
                    'sha256',
                    json_encode(
                        $sourceContent,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    )
                );
                if ($expectedSourceHash !== '' && !hash_equals($expectedSourceHash, $actualSourceHash)) {
                    throw new RuntimeException(
                        'El original español cambió mientras se preparaba la publicación. Volvé a pulsar Publicar.'
                    );
                }
                $sourceContent = $adapter->completeStudioNoteMetadata($userId, $entityId, $sourceContent);
                if ($adaptationLocale !== '') {
                    $targetContent = (array)$editorial->get(
                        $userId,
                        $entityType,
                        $entityId,
                        $adaptationLocale
                    )['content'];
                    if (isset($targetContent['body_html'])) {
                        $targetContent['body_html'] = StudioNoteMediaService::removeImages(
                            (string)$targetContent['body_html']
                        );
                    }
                    $english = $adapter->proposeAdaptationFromContent(
                        $userId,
                        $entityType,
                        $entityId,
                        $sourceContent,
                        $targetContent
                    );
                    $englishContent = (array)($english['content'] ?? []);
                } else {
                    // Sin idioma adicional, la nota publica es el propio master.
                    $englishContent = [];
                }

                // WebsiteBoardService performs its schema compatibility check in
                // the constructor. MySQL implicitly commits on DDL, so construct
                // it before opening the atomic bilingual publication transaction.
                $websiteBoard = new WebsiteBoardService($this->pdo);
                $ownsTransaction = !$this->pdo->inTransaction();
                if ($ownsTransaction) $this->pdo->beginTransaction();
                try {
                    $editorial->save($userId, $entityType, $entityId, $workingLocale, $sourceContent);
                    if ($adaptationLocale !== '') {
                        $editorial->save($userId, $entityType, $entityId, $adaptationLocale, $englishContent);
                    }
                    $editorial->setPublished($userId, $entityType, $entityId, $workingLocale, true);
                    if ($adaptationLocale !== '') {
                        $editorial->setPublished($userId, $entityType, $entityId, $adaptationLocale, true);
                    }
                    $publicNoteContent = $adaptationLocale !== '' ? $englishContent : $sourceContent;
                    $saved = $websiteBoard->saveNote(
                        $userId,
                        $entityId,
                        (string)($publicNoteContent['title'] ?? ''),
                        (string)($publicNoteContent['body_html'] ?? ''),
                        (string)($sourceContent['body_html'] ?? '')
                    );
                    if ((string)($saved['status'] ?? 'draft') !== 'published') {
                        $websiteBoard->noteAction($userId, $entityId, 'publish');
                    }
                    if ($ownsTransaction) $this->pdo->commit();
                } catch (Throwable $publishError) {
                    if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
                    throw $publishError;
                }
                $result = [
                    'metadata_completed' => true,
                    'english_status' => 'current',
                    'published' => true,
                ];
            } elseif ($entityType === 'studio_note') {
                $sourceContent = is_array($payload['current_spanish'] ?? null)
                    ? (array)$payload['current_spanish']
                    : [];
                $targetContent = is_array($payload['current_english'] ?? null)
                    ? (array)$payload['current_english']
                    : [];
                if ($action === 'adapt'
                    && $sourceContent !== []
                    && !empty($payload['review_spanish_metadata'])) {
                    $sourceContent = $adapter->completeStudioNoteMetadata(
                        $userId,
                        $entityId,
                        $sourceContent,
                        (array)($payload['protected_spanish_fields'] ?? [])
                    );
                }
                if ($adaptationLocale === '') {
                    throw new RuntimeException('Este artista publica solo en su idioma de trabajo; no hay adaptación que preparar.');
                }
                $english = $sourceContent !== []
                    ? $adapter->proposeAdaptationFromContent(
                        $userId,
                        $entityType,
                        $entityId,
                        $sourceContent,
                        $targetContent
                    )
                    : $adapter->proposeAdaptation(
                        $userId,
                        $entityType,
                        $entityId,
                        $workingLocale,
                        $adaptationLocale
                    );
                $englishContent = (array)($english['content'] ?? []);
                $ownsTransaction = !$this->pdo->inTransaction();
                if ($ownsTransaction) $this->pdo->beginTransaction();
                try {
                    if ($action === 'adapt' && $sourceContent !== []) {
                        $editorial->save($userId, $entityType, $entityId, $workingLocale, $sourceContent);
                    }
                    $englishState = $editorial->save(
                        $userId,
                        $entityType,
                        $entityId,
                        $adaptationLocale,
                        $englishContent
                    );
                    if ($ownsTransaction) $this->pdo->commit();
                } catch (Throwable $saveError) {
                    if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
                    throw $saveError;
                }
                $result = [
                    'english_content' => $englishContent,
                    'english_status' => (string)($englishState['status'] ?? 'current'),
                    'metadata_completed' => $action === 'adapt'
                        && !empty($payload['review_spanish_metadata']),
                    'applied_to_editor' => true,
                ];
            } else {
                if ($adaptationLocale === '') {
                    throw new RuntimeException('Este artista publica solo en su idioma de trabajo; no hay adaptación que preparar.');
                }
                $english = $adapter->adaptMissing($userId, $entityType, $entityId, $workingLocale, $adaptationLocale);
                $result = [
                    'english_content' => (array)($english['content'] ?? []),
                    'english_status' => (string)($english['english_status'] ?? 'current'),
                ];
            }

            $jobs->complete($jobId, $result);
            if ($entityType === 'studio_note') {
                (new StudioNoteWorkspaceService($this->pdo))->syncHistoricalJobs($userId, $entityId);
            }
            $this->refreshEditorialPackages($jobId);
            return ['ok' => true, 'job' => $jobs->publicState($jobs->job($jobId))];
        } catch (Throwable $error) {
            $jobs->fail($jobId, $error->getMessage());
            $this->refreshEditorialPackages($jobId);
            return ['ok' => false, 'job' => $jobs->publicState($jobs->job($jobId)), 'error' => $error->getMessage()];
        }
    }

    private function refreshEditorialPackages(int $jobId): void
    {
        if (!class_exists(ArtworkEditorialPackageService::class)) {
            return;
        }
        try {
            (new ArtworkEditorialPackageService($this->pdo))->refreshPackagesForEditorialJob($jobId);
        } catch (Throwable $error) {
            error_log('Editorial package refresh failed for job ' . $jobId . ': ' . $error->getMessage());
        }
    }
}
