<?php
declare(strict_types=1);

final class BilingualEditorialGenerationWorker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?BilingualEditorialAdapterService $adapter = null,
        private readonly ?ArtworkAnalysisV2Service $artworkAnalysis = null
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

            if ($action === 'channel' && $entityType === 'artwork') {
                // Textos de canal: se DERIVAN de la lectura aprobada, no vuelven
                // a mirar la obra. El listing va al idioma de publicacion —Saatchi
                // se carga en ingles— y el vocabulario al idioma de trabajo, cada
                // uno nacido de su propio texto, porque traducir una keyword la
                // deja de ser una busqueda. Todo queda en BORRADOR: aprobar es del
                // artista.
                require_once __DIR__ . '/SaatchiListingService.php';

                // El paso de canal escribe SOLO LO QUE FALTA. Un texto que ya
                // existe no se toca ni se paga de nuevo: eso vuelve el paso
                // idempotente y deja que las obras viejas se completen —el
                // espanol del listing, los pies, las influencias— sin regenerar
                // lo que ya esta bien.
                $saatchi = new SaatchiListingService($this->pdo);
                $listingLocale = $editorial->primaryAdaptationTarget($userId) ?: 'en';
                $faltanCamposListing = static function (array $contenido): bool {
                    foreach (['saatchi_title', 'saatchi_description', 'saatchi_keywords'] as $campo) {
                        if (trim((string)($contenido[$campo] ?? '')) === '') {
                            return true;
                        }
                    }
                    return false;
                };
                $notasListado = [];
                $piesEscritos = 0;
                $avisosPies = [];

                $contenidoListing = (array)$editorial->get($userId, 'artwork', $entityId, $listingLocale)['content'];
                if ($faltanCamposListing($contenidoListing)) {
                    $listado = $saatchi->generate($userId, $entityId);
                    $estadoListado = (string)($listado['validation']['status'] ?? '');
                    if ($estadoListado === 'ok') {
                        $saatchi->save($userId, $entityId, $listado);
                    }
                    // Un texto que no paso la validacion no se guarda ni se
                    // disimula: el job queda fallado con el motivo exacto, y el
                    // paquete lo muestra. Los pies son aparte: uno flojo no
                    // invalida el listing, se descarta solo y se avisa.
                    if ($estadoListado !== 'ok') {
                        throw new RuntimeException('Listing: ' . implode(' · ', (array)($listado['validation']['errors'] ?? ['sin detalle'])));
                    }
                    $notasListado[] = "listing {$listingLocale}: escrito";
                    $piesEscritos = count(array_filter((array)($listado['captions'] ?? []), static fn ($c): bool => trim((string)$c) !== ''));
                    $avisosPies = array_values(array_filter(
                        (array)($listado['validation']['warnings'] ?? []),
                        static fn (string $w): bool => str_starts_with($w, 'Pie sin escribir')
                    ));
                } else {
                    $notasListado[] = "listing {$listingLocale}: conservado";
                    // Los pies que falten se completan igual: la reutilizacion
                    // hace que solo se paguen los que no existen.
                    try {
                        $soloPies = $saatchi->generateCaptionsOnly($userId, $entityId);
                        $piesEscritos = $saatchi->saveCaptions($userId, (array)$soloPies['captions']);
                        foreach ((array)$soloPies['errors'] as $errorPie) {
                            $avisosPies[] = 'Pie sin escribir — ' . $errorPie;
                        }
                    } catch (Throwable $errorPies) {
                        $avisosPies[] = 'Pies: ' . $errorPies->getMessage();
                    }
                }

                // El mismo sistema en los dos idiomas: el listing tambien se
                // deriva en el idioma de trabajo del artista —derivado de la
                // misma lectura, no traducido—. No tiene destino externo (el
                // formulario de Saatchi se carga en ingles): es material del
                // artista, sin pies (los pies viven en una sola columna, del
                // canal real), y un fallo aca avisa y no tumba el paso.
                if ($workingLocale !== '' && $workingLocale !== $listingLocale) {
                    $contenidoTrabajo = (array)$editorial->get($userId, 'artwork', $entityId, $workingLocale)['content'];
                    if (!$faltanCamposListing($contenidoTrabajo)) {
                        $notasListado[] = "listing {$workingLocale}: conservado";
                    } else {
                        try {
                            $listadoTrabajo = $saatchi->generate($userId, $entityId, $workingLocale, false);
                            if ((string)($listadoTrabajo['validation']['status'] ?? '') === 'ok') {
                                $saatchi->save($userId, $entityId, $listadoTrabajo);
                                $notasListado[] = "listing {$workingLocale}: escrito";
                            } else {
                                $notasListado[] = "listing {$workingLocale}: descartado — "
                                    . implode(' · ', (array)($listadoTrabajo['validation']['errors'] ?? ['sin detalle']));
                            }
                        } catch (Throwable $errorListadoTrabajo) {
                            $notasListado[] = "listing {$workingLocale}: " . $errorListadoTrabajo->getMessage();
                        }
                    }
                }

                // Ultima pasada: TODOS los mockups del grupo, no solo los
                // primeros 5 de la composicion (esos ya se cubrieron arriba,
                // al escribir o conservar el listing). "Todos los captions"
                // es la regla completa, no la del listing principal nada
                // mas — esto es lo que la cumple en cada corrida del paso.
                try {
                    $faltantes = $saatchi->mockupsMissingValidCaptions($userId, $entityId);
                    if ($faltantes !== []) {
                        $extraPies = $saatchi->generateCaptionsForMockups($userId, $entityId, $faltantes);
                        $piesEscritos += $saatchi->saveCaptions($userId, (array)$extraPies['captions']);
                        foreach ((array)$extraPies['errors'] as $errorPie) {
                            $avisosPies[] = 'Pie sin escribir — ' . $errorPie;
                        }
                    }
                } catch (Throwable $errorPiesTodos) {
                    $avisosPies[] = 'Pies (resto de mockups): ' . $errorPiesTodos->getMessage();
                }

                // Analisis segun influencias: prosa derivada por idioma de la
                // lectura de ese idioma, anclada a las afinidades DECLARADAS.
                // Solo llena el campo si esta vacio (lo editado a mano no se
                // pisa), vacio es un resultado valido, y un fallo aca es un
                // aviso: nunca tumba el paso de canal.
                require_once __DIR__ . '/InfluencesAnalysisService.php';
                $influencias = new InfluencesAnalysisService($this->pdo);
                $notasInfluencias = [];
                $idiomasInfluencias = array_values(array_unique(array_filter([
                    $workingLocale,
                    (string)($listado['locale'] ?? ''),
                ])));
                foreach ($idiomasInfluencias as $idioma) {
                    try {
                        $notasInfluencias[] = $influencias->deriveIfEmpty($userId, $entityId, $idioma);
                    } catch (Throwable $errorInfluencias) {
                        $notasInfluencias[] = "influencias {$idioma}: " . $errorInfluencias->getMessage();
                    }
                }

                $result = [
                    'channel_texts' => true,
                    'listing' => $notasListado,
                    'influences' => $notasInfluencias,
                    'listing_locale' => $listingLocale,
                    'captions_written' => $piesEscritos,
                    'notes' => $avisosPies,
                    'spanish_published' => false,
                ];
            } elseif ($action === 'prepare' && $entityType === 'series') {
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
                if ($entityType === 'artwork') {
                    // Una obra tiene un unico generador: su analisis. Es la sola
                    // fuente que produce a la vez la ficha de catalogo y el
                    // master del idioma de trabajo, asi que generar el editorial
                    // por separado seria pagar dos veces la misma lectura.
                    $spanishContent = $this->generateArtworkFromAnalysis($userId, $entityId, $workingLocale);
                } else {
                    $spanish = $adapter->generateSpanishDraft(
                        $userId,
                        $entityType,
                        $entityId,
                        is_array($payload['current_spanish'] ?? null) ? $payload['current_spanish'] : null,
                        array_key_exists('private_memo', $payload) ? (string)$payload['private_memo'] : null
                    );
                    $spanishContent = (array)($spanish['content'] ?? []);
                }
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

    /**
     * Ejecuta el analisis v2 de una obra y devuelve el master resultante en el
     * idioma de trabajo. `applyAnalysisV2Draft` deja la ficha de catalogo; el
     * guardado explicito posterior reemplaza el master, porque regenerar es una
     * peticion del artista y no debe limitarse a rellenar huecos.
     *
     * @return array<string,mixed>
     */
    private function generateArtworkFromAnalysis(int $userId, int $artworkId, string $workingLocale): array
    {
        $editorial = new BilingualEditorialService($this->pdo);
        $sheetService = new ArtworkSheetService($this->pdo);
        $artwork = $sheetService->artwork($artworkId, $userId);
        if (!is_array($artwork) || $artwork === []) {
            throw new RuntimeException('The artwork no longer exists.');
        }

        $imageFile = basename(trim((string)($artwork['root_file'] ?? '')));
        if ($imageFile === '') {
            $imageFile = basename(trim((string)($artwork['main_file'] ?? '')));
        }
        if ($imageFile === '' || !defined('RESULTS_DIR')) {
            throw new RuntimeException('Select the root artwork image before generating its content.');
        }
        $imagePath = RESULTS_DIR . DIRECTORY_SEPARATOR . $imageFile;
        if (!is_file($imagePath) && class_exists('StorageService') && StorageService::isGcsActive()) {
            StorageService::downloadFile('results/' . $imageFile, $imagePath);
        }
        if (!is_file($imagePath)) {
            throw new RuntimeException('Selected root artwork image was not found.');
        }

        $sheet = $sheetService->sheetForArtwork($artworkId, $userId);
        // EDITORIAL_CORE.md Libro VI Cap. 2: regenerar = refinar sobre la
        // lectura vigente con el memo del artista como direccion, nunca
        // empezar de cero.
        $currentState = $editorial->get($userId, 'artwork', $artworkId, $workingLocale);
        $currentReading = array_filter(
            (array)($currentState['content'] ?? []),
            static fn($value): bool => is_string($value) && trim($value) !== ''
        );
        $privateMemo = (string)($currentState['private_memo'] ?? '');
        $analysis = $this->artworkAnalysis ?? new ArtworkAnalysisV2Service(new GeminiImageClient(), $this->pdo);
        $generated = $analysis->generateDraft(
            $artwork,
            ArtistProfile::findForUser($userId),
            $imagePath,
            (string)($sheet['user_notes'] ?? ''),
            $workingLocale,
            $currentReading,
            $privateMemo
        );
        $draft = (array)$generated['draft'];
        $sheetService->applyAnalysisV2Draft($artworkId, $userId, $draft);

        $content = ArtworkAnalysisV2::editorialContent($draft);
        $editorial->save(
            $userId,
            'artwork',
            $artworkId,
            $workingLocale,
            $content,
            $privateMemo
        );

        return $content;
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
