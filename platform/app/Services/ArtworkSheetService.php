<?php
declare(strict_types=1);

final class ArtworkSheetService
{
    private PDO $pdo;
    private GeminiImageClient $client;

    public function __construct(?PDO $pdo = null, ?GeminiImageClient $client = null)
    {
        $this->pdo = $pdo ?: Database::connection();
        $this->client = $client ?: new GeminiImageClient();
    }

    /**
     * @return array<string,mixed>
     */
    public function artwork(int $artworkId, int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute(['id' => $artworkId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Obra no encontrada.');
        }
        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    public function sheetForArtwork(int $artworkId, int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM artwork_sheets WHERE canonical_artwork_id = :artwork_id AND user_id = :user_id AND COALESCE(status, '') <> 'merged' ORDER BY id DESC LIMIT 1");
        $stmt->execute(['artwork_id' => $artworkId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            return $row;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM artwork_sheets WHERE user_id = :user_id AND COALESCE(status, '') <> 'merged' ORDER BY id DESC");
        $stmt->execute(['user_id' => $userId]);
        foreach ($stmt->fetchAll() as $relatedSheet) {
            if (in_array($artworkId, $this->relatedIdsFromSheet($relatedSheet), true)) {
                return $relatedSheet;
            }
        }

        $artwork = $this->artwork($artworkId, $userId);
        $now = date('c');
        $this->pdo->prepare('
            INSERT INTO artwork_sheets (
                user_id, canonical_artwork_id, related_artwork_ids, source_image_file,
                user_notes, title, subtitle, description, short_description, keywords,
                tags, alt_text, caption, status, generated_json, created_at, updated_at
            ) VALUES (
                :user_id, :canonical_artwork_id, :related_artwork_ids, :source_image_file,
                :user_notes, :title, :subtitle, :description, :short_description, :keywords,
                :tags, :alt_text, :caption, :status, :generated_json, :created_at, :updated_at
            )
        ')->execute([
            'user_id' => $userId,
            'canonical_artwork_id' => $artworkId,
            'related_artwork_ids' => json_encode([$artworkId], JSON_UNESCAPED_SLASHES),
            'source_image_file' => (string)($artwork['root_file'] ?? $artwork['main_file'] ?? ''),
            'user_notes' => '',
            'title' => (string)($artwork['final_title'] ?? ''),
            'subtitle' => (string)($artwork['subtitle'] ?? ''),
            'description' => '',
            'short_description' => '',
            'keywords' => '',
            'tags' => '',
            'alt_text' => '',
            'caption' => '',
            'status' => 'draft',
            'generated_json' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int)$this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('SELECT * FROM artwork_sheets WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : [];
    }

    /**
     * @param array<string,mixed> $input
     */
    public function saveArtworkSheet(int $sheetId, int $userId, array $input): void
    {
        $this->pdo->prepare('
            UPDATE artwork_sheets
            SET related_artwork_ids = :related_artwork_ids,
                source_image_file = :source_image_file,
                user_notes = :user_notes,
                title = :title,
                subtitle = :subtitle,
                description = :description,
                short_description = :short_description,
                keywords = :keywords,
                tags = :tags,
                alt_text = :alt_text,
                caption = :caption,
                status = :status,
                updated_at = :updated_at
            WHERE id = :id AND user_id = :user_id
        ')->execute([
            'related_artwork_ids' => $this->normalizeRelatedIds((string)($input['related_artwork_ids'] ?? '')),
            'source_image_file' => trim((string)($input['source_image_file'] ?? '')),
            'user_notes' => trim((string)($input['user_notes'] ?? '')),
            'title' => trim((string)($input['title'] ?? '')),
            'subtitle' => trim((string)($input['subtitle'] ?? '')),
            'description' => trim((string)($input['description'] ?? '')),
            'short_description' => trim((string)($input['short_description'] ?? '')),
            'keywords' => trim((string)($input['keywords'] ?? '')),
            'tags' => trim((string)($input['tags'] ?? '')),
            'alt_text' => trim((string)($input['alt_text'] ?? '')),
            'caption' => trim((string)($input['caption'] ?? '')),
            'status' => trim((string)($input['status'] ?? 'draft')) ?: 'draft',
            'updated_at' => date('c'),
            'id' => $sheetId,
            'user_id' => $userId,
        ]);
        (new PublicationService($this->pdo))->syncInheritedFromSheet($sheetId, $userId);
    }

    /**
     * Persist a validated V2 analysis and promote its suggested title when the
     * artwork does not have a title yet. Existing artist-edited titles win.
     *
     * @param array<string,mixed> $draft
     * @return array<string,mixed>
     */
    public function applyAnalysisV2Draft(int $artworkId, int $userId, array $draft): array
    {
        $errors = ArtworkAnalysisV2::validate($draft, false);
        if ($errors) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $artwork = $this->artwork($artworkId, $userId);
        $sheet = $this->sheetForArtwork($artworkId, $userId);
        $editorial = (array)($draft['canonical_editorial'] ?? []);
        $search = (array)($draft['search_metadata'] ?? []);
        $keywords = array_values(array_unique(array_filter(array_map('trim', (array)($search['search_terms'] ?? [])))));

        // EDITORIAL_CORE.md Libro I Cap. 6: el titulo lo decide el artista,
        // siempre. La IA jamas rellena un titulo vacio — el gate de contexto
        // integro impide llegar aca sin titulo. El subtitulo si es propuesta
        // editorial editable.
        $existingTitle = trim((string)($artwork['final_title'] ?? ''));
        $existingSubtitle = trim((string)($artwork['subtitle'] ?? ''));
        $suggestedSubtitle = trim((string)($editorial['subtitle'] ?? ''));
        $title = $existingTitle;
        $subtitle = $existingSubtitle !== '' ? $existingSubtitle : $suggestedSubtitle;

        $bilingual = new BilingualEditorialService($this->pdo);
        // El analisis alimenta la edicion fuente solo cuando fue generado en el
        // idioma de trabajo del artista.
        $spanishFirst = $bilingual->isEnabled($userId)
            && (string)($draft['analysis_language'] ?? '') === $bilingual->sourceLocale($userId);
        if ($spanishFirst) {
            $bilingual->fillSourceFromAnalysis($userId, 'artwork', $artworkId, ArtworkAnalysisV2::editorialContent($draft));
            $now = date('c');
            $this->pdo->prepare("
                UPDATE artwork_sheets
                SET title=CASE WHEN TRIM(COALESCE(title,''))='' THEN :title ELSE title END,
                    generated_json=:generated_json,status='draft',updated_at=:updated_at
                WHERE id=:id AND user_id=:user_id
            ")->execute([
                'title' => $title,
                'generated_json' => json_encode($draft, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
                'id' => (int)$sheet['id'],
                'user_id' => $userId,
            ]);
            if ($title !== '') {
                $this->pdo->prepare("
                    UPDATE artworks
                    SET final_title=CASE WHEN TRIM(COALESCE(final_title,''))='' THEN :title ELSE final_title END,
                        updated_at=:updated_at
                    WHERE id=:id AND user_id=:user_id
                ")->execute([
                    'title' => $title,
                    'updated_at' => $now,
                    'id' => $artworkId,
                    'user_id' => $userId,
                ]);
            }
            return $this->artwork($artworkId, $userId);
        }

        $this->saveArtworkSheet((int)$sheet['id'], $userId, [
            'related_artwork_ids' => (string)$sheet['related_artwork_ids'],
            'source_image_file' => (string)$sheet['source_image_file'],
            'title' => $title,
            'subtitle' => $subtitle,
            'description' => (string)($editorial['master_description'] ?? ''),
            'short_description' => (string)($editorial['short_description'] ?? ''),
            'keywords' => implode(', ', $keywords),
            'tags' => (string)$sheet['tags'],
            'alt_text' => (string)($editorial['alt_text'] ?? ''),
            'caption' => (string)($editorial['caption'] ?? ''),
            'user_notes' => (string)$sheet['user_notes'],
            'status' => 'validated',
        ]);

        $this->pdo->prepare('
            UPDATE artwork_sheets
            SET generated_json = :generated_json, updated_at = :updated_at
            WHERE id = :id AND user_id = :user_id
        ')->execute([
            'generated_json' => json_encode($draft, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => date('c'),
            'id' => (int)$sheet['id'],
            'user_id' => $userId,
        ]);

        if ($title !== '') {
            $this->pdo->prepare("\n                UPDATE artworks\n                SET final_title = CASE WHEN TRIM(COALESCE(final_title, '')) = '' THEN :title ELSE final_title END,\n                    subtitle = CASE WHEN TRIM(COALESCE(subtitle, '')) = '' THEN :subtitle ELSE subtitle END,\n                    updated_at = :updated_at\n                WHERE id = :id AND user_id = :user_id\n            ")->execute([
                'title' => $title,
                'subtitle' => $subtitle,
                'updated_at' => date('c'),
                'id' => $artworkId,
                'user_id' => $userId,
            ]);
        }

        return $this->artwork($artworkId, $userId);
    }

    public function saveArtworkTitle(int $artworkId, int $userId, string $title): void
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('The artwork title cannot be empty.');
        }

        $artwork = $this->artwork($artworkId, $userId);
        $sheet = $this->sheetForArtwork($artworkId, $userId);
        $groupId = (int)($artwork['artwork_group_id'] ?? 0);
        $now = date('c');

        Database::withBusyRetry(function () use ($artworkId, $userId, $title, $sheet, $groupId, $now): void {
            $ownsTransaction = !$this->pdo->inTransaction();
            if ($ownsTransaction) {
                Database::beginWriteTransaction($this->pdo);
            }
            try {
                $this->pdo->prepare('
                    UPDATE artworks
                    SET final_title = :title, updated_at = :updated_at
                    WHERE user_id = :user_id
                    AND (id = :id OR (:group_id > 0 AND artwork_group_id = :group_id_match))
                ')->execute([
                    'title' => $title,
                    'updated_at' => $now,
                    'user_id' => $userId,
                    'id' => $artworkId,
                    'group_id' => $groupId,
                    'group_id_match' => $groupId,
                ]);
                $this->pdo->prepare('
                    UPDATE artwork_sheets
                    SET title = :title, updated_at = :updated_at
                    WHERE id = :id AND user_id = :user_id
                ')->execute([
                    'title' => $title,
                    'updated_at' => $now,
                    'id' => (int)$sheet['id'],
                    'user_id' => $userId,
                ]);
                $this->pdo->prepare("
                    UPDATE artwork_groups
                    SET title = :title, updated_at = :updated_at
                    WHERE user_id = :user_id
                    AND status = 'active'
                    AND (canonical_artwork_id = :artwork_id OR (:group_id > 0 AND id = :group_id_match))
                ")->execute([
                    'title' => $title,
                    'updated_at' => $now,
                    'user_id' => $userId,
                    'artwork_id' => $artworkId,
                    'group_id' => $groupId,
                    'group_id_match' => $groupId,
                ]);
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
            } catch (Throwable $error) {
                if ($ownsTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        });
        (new PublicationService($this->pdo))->syncInheritedFromSheet((int)$sheet['id'], $userId);
    }

    /**
     * @param array<int|string> $artworkIds
     */
    public function mergeArtworkIds(int $primaryArtworkId, int $userId, array $artworkIds): array
    {
        $primarySheet = $this->sheetForArtwork($primaryArtworkId, $userId);
        $ids = [$primaryArtworkId];

        foreach ($this->relatedIdsFromSheet($primarySheet) as $existingId) {
            $ids[] = $existingId;
        }

        foreach ($artworkIds as $rawId) {
            $id = (int)$rawId;
            if ($id <= 0) {
                continue;
            }
            $this->artwork($id, $userId);
            $ids[] = $id;

            $stmt = $this->pdo->prepare('SELECT * FROM artwork_sheets WHERE user_id = :user_id AND canonical_artwork_id = :artwork_id LIMIT 1');
            $stmt->execute(['user_id' => $userId, 'artwork_id' => $id]);
            $existingSheet = $stmt->fetch();
            if (is_array($existingSheet)) {
                foreach ($this->relatedIdsFromSheet($existingSheet) as $relatedId) {
                    $ids[] = $relatedId;
                }
            }
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $this->pdo->prepare('
            UPDATE artwork_sheets
            SET related_artwork_ids = :related_artwork_ids,
                updated_at = :updated_at
            WHERE id = :id AND user_id = :user_id
        ')->execute([
            'related_artwork_ids' => json_encode($ids, JSON_UNESCAPED_SLASHES),
            'updated_at' => date('c'),
            'id' => (int)$primarySheet['id'],
            'user_id' => $userId,
        ]);

        return $this->sheet((int)$primarySheet['id'], $userId);
    }


    /**
     * @return array<int,array<string,mixed>>
     */
    public function associatedMockups(array $sheet, int $userId): array
    {
        $ids = $this->relatedIdsFromSheet($sheet);
        $mockups = [];

        foreach ($ids as $artworkId) {
            $stmt = $this->pdo->prepare('
                SELECT id, artwork_id, mockup_file, prompt_file, context_id, status, created_at
                FROM mockup_generation_jobs
                WHERE artwork_id = :artwork_id
                AND mockup_file IS NOT NULL
                AND mockup_file <> \'\'
                ORDER BY id DESC
            ');
            $stmt->execute(['artwork_id' => $artworkId]);
            foreach ($stmt->fetchAll() as $row) {
                $row['source_table'] = 'mockup_generation_jobs';
                $mockups[] = $row;
            }
        }

        $artworkFiles = $this->artworkFilesForIds($ids, $userId);
        foreach ($artworkFiles as $artworkId => $files) {
            foreach ($files as $file) {
                $stmt = $this->pdo->prepare('SELECT id, mockup_file, prompt_file, context_id, created_at FROM mockups WHERE user_id = :user_id AND artwork_file = :artwork_file ORDER BY id DESC');
                $stmt->execute(['user_id' => $userId, 'artwork_file' => $file]);
                foreach ($stmt->fetchAll() as $row) {
                    $row['artwork_id'] = $artworkId;
                    $row['source_table'] = 'mockups';
                    $mockups[] = $row;
                }
            }
        }

        $stmt = $this->pdo->prepare('
            SELECT id, artwork_id, mockup_file, created_at
            FROM mockup_sheets
            WHERE user_id = :user_id
            AND artwork_sheet_id = :artwork_sheet_id
            ORDER BY id DESC
        ');
        $stmt->execute([
            'user_id' => $userId,
            'artwork_sheet_id' => (int)($sheet['id'] ?? 0),
        ]);
        foreach ($stmt->fetchAll() as $row) {
            $row['prompt_file'] = '';
            $row['context_id'] = '';
            $row['status'] = (string)($row['status'] ?? 'linked');
            $row['source_table'] = 'mockup_sheets';
            $mockups[] = $row;
        }

        $seen = [];
        $out = [];
        foreach ($mockups as $mockup) {
            $file = basename((string)($mockup['mockup_file'] ?? ''));
            if ($file === '' || isset($seen[$file])) {
                continue;
            }
            $seen[$file] = true;
            $mockup['mockup_file'] = $file;
            $mockup['sheet'] = $this->mockupSheetForFile((int)($sheet['id'] ?? 0), (int)($mockup['artwork_id'] ?? 0), $file, $userId);
            $out[] = $mockup;
        }

        return $out;
    }

    public function attachMockupFile(int $artworkSheetId, int $userId, string $mockupFile, string $notes = ''): array
    {
        $sheet = $this->sheet($artworkSheetId, $userId);
        $mockupFile = basename(str_replace('\\', '/', $mockupFile));
        if ($mockupFile === '') {
            throw new RuntimeException('Archivo de mockup inválido.');
        }
        if ($this->resolveImagePath($mockupFile) === '') {
            throw new RuntimeException('No se encontró el archivo de mockup en results.');
        }

        return $this->ensureMockupSheet(
            $artworkSheetId,
            (int)$sheet['canonical_artwork_id'],
            $mockupFile,
            $userId,
            $notes
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function generateMockupSheet(int $artworkSheetId, int $artworkId, string $mockupFile, int $userId, string $notes): array
    {
        $mockupFile = basename($mockupFile);
        $sheet = $this->ensureMockupSheet($artworkSheetId, $artworkId, $mockupFile, $userId, $notes);
        $artworkSheet = $this->sheet($artworkSheetId, $userId);
        $imagePath = $this->resolveImagePath($mockupFile);
        $fallback = $this->fallbackMockupCopy($artworkSheet, $mockupFile, $notes);
        $generated = $fallback;
        $analysisGenerated = false;
        $bilingual = new BilingualEditorialService($this->pdo);
        $spanishFirst = $bilingual->isEnabled($userId);
        $analysisLocale = $bilingual->sourceLocale($userId);
        $analysisLanguageName = $analysisLocale === 'en' ? 'English' : 'Spanish';
        $analysisCounterpart = $analysisLocale === 'en' ? 'Spanish' : 'English';
        $analysisStyle = $analysisLocale === 'en' ? 'natural international English' : 'natural Spanish';
        $languageInstruction = "Think, analyze and write directly in {$analysisStyle}. Do not draft in {$analysisCounterpart} and translate afterward. Every user-facing string in the JSON must be {$analysisLanguageName}.";

        // EDITORIAL_CORE Libro I Cap. 7 + Libro VI Cap. 1: el mockup hereda la
        // identidad y la lectura APROBADA de su obra — la cadena se lee EN VIVO
        // de las tablas canonicas, nunca del JSON viejo de un analisis. El blob
        // se consulta unicamente como lista de alias a limpiar (transicion).
        $artworkRow = $this->artwork($artworkId, $userId);
        $realTitle = trim((string)($artworkRow['final_title'] ?? '')) ?: trim((string)($artworkSheet['title'] ?? ''));
        $realSubtitle = trim((string)($artworkRow['subtitle'] ?? '')) ?: trim((string)($artworkSheet['subtitle'] ?? ''));
        $artworkState = null;
        if ($spanishFirst) {
            $artworkState = $bilingual->get($userId, 'artwork', $artworkId, $analysisLocale);
            // Publicar = aprobar: sin lectura de obra publicada, sus mockups no
            // generan contenido todavia.
            if (!($artworkState['is_published'] ?? false)) {
                throw new DomainException('La obra necesita su contenido publicado antes de generar el contenido de sus mockups.');
            }
        }
        $staleAliases = [];
        $legacyBlobTitle = trim((string)(json_decode((string)($artworkSheet['generated_json'] ?? ''), true)['canonical_editorial']['title'] ?? ''));
        if ($legacyBlobTitle !== '' && $realTitle !== '' && strcasecmp($legacyBlobTitle, $realTitle) !== 0) {
            $staleAliases[] = $legacyBlobTitle;
        }
        $seriesDirection = $this->seriesDirectionForArtwork($artworkRow, $userId);
        $seriesTitle = trim((string)($seriesDirection['title'] ?? ''));
        $approvedReading = is_array($artworkState['published_content'] ?? null) ? $artworkState['published_content'] : [];
        if ($staleAliases !== [] && $approvedReading !== []) {
            $approvedReading = EditorialIdentityGuard::rewriteAliases($approvedReading, $realTitle, $staleAliases, $seriesTitle, []);
        }
        $liveIdentity = array_filter([
            'title' => $realTitle,
            'subtitle' => $realSubtitle,
            'medium' => trim((string)($artworkRow['medium'] ?? '')),
            'year' => trim((string)($artworkRow['artwork_year'] ?? '')),
            'width_cm' => trim((string)($artworkRow['width'] ?? '')),
            'height_cm' => trim((string)($artworkRow['height'] ?? '')),
            'depth_cm' => trim((string)($artworkRow['depth'] ?? '')),
        ], static fn($value): bool => $value !== '');
        $artistProfile = ArtistProfile::findForUser($userId);
        $sceneMetadata = $this->mockupSceneMetadata($userId, $mockupFile);
        $keywordResearchBlock = $this->mockupKeywordResearchBlock($userId, (int)($artworkRow['series_id'] ?? 0));

        if (ProviderSettings::isRealMode() && ProviderSettings::allowRealApi() && ProviderSettings::imageProvider() === 'gemini' && $imagePath !== '') {
            $prompt = "Analyze this exact mockup image. {$languageInstruction} The artwork identity and its approved reading below are authoritative; analyze the scene without renaming, reinterpreting, or inventing facts about the artwork. Return strict JSON only.\n"
                . "ARTIST PROFILE (voice and context only, never public text):\n" . (ArtistProfile::hasContent($artistProfile) ? ArtistProfile::forPrompt($artistProfile) : '- No profile provided.') . "\n"
                . "CONFIRMED ARTWORK IDENTITY (immutable input, set by the artist):\n" . json_encode($liveIdentity, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n"
                . "ARTIST-AUTHORED SERIES DIRECTION (its interpretive limits are prohibitions):\n" . (string)$seriesDirection['prompt'] . "\n"
                . ($approvedReading !== [] ? "APPROVED ARTWORK READING (the published editorial reading — inherit, never reinterpret):\n" . json_encode($approvedReading, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n" : '')
                . ($sceneMetadata !== '' ? "MOCKUP SCENE METADATA (the requested scene for this exact mockup):\n" . $sceneMetadata . "\n" : '')
                . $keywordResearchBlock
                . "MOCKUP RULES: describe space type, architecture, materials, lighting, camera, scale perception, atmosphere, and artwork-space relationship. Keywords, long tails, tags, captions and channel copy must be justified by the visible image. Never use generic repeated copy. Never call the artwork framed unless a real frame is visible. Exclude home decor, wall art, perfect for any room, elevate your space, decor inspiration, and generic interior-marketing filler. Do not invent furniture, materials, colors, light, artwork facts, or destination links. Website is detailed and collector-facing; Pinterest is shorter and traffic-oriented; Instagram is visual/community-oriented; Facebook is conversational; TikTok is future preparation only.\n"
                . EditorialIntegrityPolicy::promptRules('mockup') . "\n"
                . SearchIntentPrompt::forEntity('mockup') . "\n"
                . "SEARCH FIELD MAPPING: neutral.tags contains ten to fourteen standardized catalogue filters when supported; neutral.search_terms contains twelve to sixteen distinct searches a real buyer could type, including at least six genuine long tails; neutral.seo_title and neutral.seo_description are the page metadata. Do not create parallel keyword buckets or poetic search phrases. Derive channel search fields from this same compact evidence without duplication.\n"
                . "USER NOTES: {$notes}\n"
                . 'Return: {"schema_version":"mockup-analysis.v2","analysis_language":"' . $analysisLocale . '","neutral":{"context_title":"","contextual_description":"","alt_text":"","caption":"","tags":[],"search_terms":[],"seo_title":"","seo_description":"","scene":{"space_type":"","architecture":"","materials":[],"lighting":"","camera":"","scale_reading":"","atmosphere":[],"artwork_space_relationship":"","distinctive_features":[]}},"channels":{"website":{"description":"","caption":"","alt_text":""},"pinterest":{"title":"","description":"","board_suggestions":[],"topic_suggestions":[],"keywords":[]},"instagram":{"caption":"","hook":"","hashtags":[],"cta":""},"facebook":{"headline":"","post_text":"","link_description":"","cta":""},"tiktok":{"status":"future","tiktok_ready":false,"visual_hook":"","suggested_motion":"","sequence_role":"","caption_seed":"","video_notes":""}},"review":{"status":"draft","warnings":[]}}';
            try {
                $text = $this->client->generateText([
                    $this->client->textPart($prompt),
                    $this->client->imagePart($imagePath),
                ], 'gemini-2.5-flash');
                $decoded = json_decode($this->extractJson($text), true);
                if (is_array($decoded)) {
                    $decoded['analysis_language'] = $analysisLocale;
                    // Red secundaria del guardian: el contexto ya entra limpio,
                    // pero ninguna mencion divergente puede persistirse.
                    if ($staleAliases !== [] && $realTitle !== '') {
                        $decoded = EditorialIdentityGuard::rewriteAliases($decoded, $realTitle, $staleAliases, $seriesTitle, []);
                    }
                    $neutral = is_array($decoded['neutral'] ?? null) ? $decoded['neutral'] : [];
                    $generated = array_merge($fallback, [
                        'title'=>(string)($neutral['context_title']??''),
                        'description'=>(string)($neutral['contextual_description']??''),
                        'tags'=>(array)($neutral['tags']??[]),
                        'search_terms'=>(array)($neutral['search_terms']??[]),
                        'seo_title'=>(string)($neutral['seo_title']??''),
                        'seo_description'=>(string)($neutral['seo_description']??''),
                        'alt_text'=>(string)($neutral['alt_text']??''),
                        'caption'=>(string)($neutral['caption']??''),
                        'mockup_analysis_v2'=>$decoded,
                    ]);
                    $integrityIssues = EditorialIntegrityPolicy::issues($generated, 'mockup');
                    if ($integrityIssues !== []) {
                        throw new RuntimeException('Mockup editorial integrity failed: ' . implode('; ', $integrityIssues));
                    }
                    unset($generated['mockup_analysis_v2_en']);
                    $analysisGenerated = true;
                }
            } catch (Throwable $e) {
                $generated = $fallback;
                $generated['_warning'] = $e->getMessage();
            }
        }

        if ($spanishFirst && $analysisGenerated) {
            if ($staleAliases !== [] && $realTitle !== '') {
                $generated = EditorialIdentityGuard::rewriteAliases($generated, $realTitle, $staleAliases, $seriesTitle, []);
            }
            $this->updateMockupAnalysisDraft((int)$sheet['id'], $userId, $notes, $generated);
            $mockupId = $this->mockupIdForFile($userId, $mockupFile);
            if ($mockupId > 0) {
                $bilingual->fillSourceFromAnalysis($userId, 'mockup', $mockupId, [
                    'description' => trim((string)($generated['description'] ?? '')),
                    'tags' => $this->csv($generated['tags'] ?? ''),
                    'search_terms' => $this->csv($generated['search_terms'] ?? ''),
                    'seo_title' => trim((string)($generated['seo_title'] ?? '')),
                    'seo_description' => trim((string)($generated['seo_description'] ?? '')),
                    'alt_text' => trim((string)($generated['alt_text'] ?? '')),
                    'caption' => trim((string)($generated['caption'] ?? '')),
                    'social' => (array)($generated['mockup_analysis_v2']['channels'] ?? []),
                ]);
            }
        } else {
            $this->updateMockupSheet((int)$sheet['id'], $userId, $notes, $generated);
        }
        return $generated;
    }

    /**
     * Direccion de la serie leida EN VIVO (EDITORIAL_CORE Libro I Cap. 4):
     * titulo + nucleo conceptual + limites interpretativos del artista.
     *
     * @return array{title:string,prompt:string}
     */
    private function seriesDirectionForArtwork(array $artworkRow, int $userId): array
    {
        $seriesId = (int)($artworkRow['series_id'] ?? 0);
        $fallbackTitle = trim((string)($artworkRow['series'] ?? ''));
        if ($seriesId <= 0) {
            return ['title' => $fallbackTitle, 'prompt' => $fallbackTitle !== '' ? "Series title: {$fallbackTitle}" : '- No series context available.'];
        }
        try {
            $stmt = $this->pdo->prepare('SELECT title,subtitle,conceptual_core,interpretive_limits FROM artwork_series WHERE id=? AND user_id=? LIMIT 1');
            $stmt->execute([$seriesId, $userId]);
            $series = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $series = false;
        }
        if (!is_array($series)) {
            return ['title' => $fallbackTitle, 'prompt' => $fallbackTitle !== '' ? "Series title: {$fallbackTitle}" : '- No series context available.'];
        }
        $lines = [];
        foreach (['title' => 'Series title', 'subtitle' => 'Series subtitle', 'conceptual_core' => 'Artist direction', 'interpretive_limits' => 'Interpretive limits'] as $field => $label) {
            $value = trim((string)($series[$field] ?? ''));
            if ($value !== '') $lines[] = "{$label}: {$value}";
        }
        return [
            'title' => trim((string)($series['title'] ?? '')) ?: $fallbackTitle,
            'prompt' => $lines !== [] ? implode("\n", $lines) : '- No series context available.',
        ];
    }

    /**
     * Metadata de escena ya generada del mockup (contexto y camara): evidencia
     * estructural de la escena pedida, para que el modelo no la re-derive solo
     * de los pixeles.
     */
    private function mockupSceneMetadata(int $userId, string $mockupFile): string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT context_id, selector_state_json FROM mockups WHERE user_id=? AND mockup_file=? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$userId, $mockupFile]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return '';
        }
        if (!is_array($row)) return '';
        $state = json_decode((string)($row['selector_state_json'] ?? ''), true);
        $combination = is_array($state['combination'] ?? null) ? $state['combination'] : [];
        $meta = array_filter([
            'context' => trim((string)($row['context_id'] ?? '')),
            'context_title' => trim((string)($combination['context_title'] ?? '')),
            'camera' => trim((string)($combination['camera_slot_name'] ?? '')),
            'world_reference_mode' => trim((string)($combination['world_mother_reference_mode'] ?? '')),
        ], static fn($value): bool => $value !== '');
        return $meta !== [] ? (string)json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    }

    /**
     * EDITORIAL_CORE Libro III Cap. 4: investigacion de keywords como refuerzo
     * opcional automatico — jamas obligacion ni bloqueo.
     */
    private function mockupKeywordResearchBlock(int $userId, int $seriesId): string
    {
        if ($seriesId <= 0 || !class_exists('SeriesKeywordResearchService')) return '';
        try {
            $research = (new SeriesKeywordResearchService($this->pdo))->promptContext($userId, $seriesId);
        } catch (Throwable) {
            return '';
        }
        if (($research['status'] ?? '') === 'not_validated' && ($research['candidates'] ?? []) === []) return '';
        return "KEYWORD RESEARCH EVIDENCE\n"
            . (string)($research['instruction'] ?? '')
            . "\n" . json_encode(
                ['status' => $research['status'] ?? '', 'selected' => $research['selected'] ?? [], 'candidates' => array_slice((array)($research['candidates'] ?? []), 0, 40)],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) . "\n";
    }

    /**
     * @return array<string,mixed>
     */
    public function sheet(int $sheetId, int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artwork_sheets WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute(['id' => $sheetId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Ficha no encontrada.');
        }
        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function mockupSheetForFile(int $artworkSheetId, int $artworkId, string $mockupFile, int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mockup_sheets WHERE user_id = :user_id AND artwork_id = :artwork_id AND mockup_file = :mockup_file ORDER BY id DESC LIMIT 1');
        $stmt->execute(['user_id' => $userId, 'artwork_id' => $artworkId, 'mockup_file' => basename($mockupFile)]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : [
            'id' => 0,
            'artwork_sheet_id' => $artworkSheetId,
            'artwork_id' => $artworkId,
            'mockup_file' => basename($mockupFile),
            'user_notes' => '',
            'title' => '',
            'description' => '',
            'keywords' => '',
            'tags' => '',
            'alt_text' => '',
            'caption' => '',
            'status' => 'draft',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ensureMockupSheet(int $artworkSheetId, int $artworkId, string $mockupFile, int $userId, string $notes): array
    {
        $existing = $this->mockupSheetForFile($artworkSheetId, $artworkId, $mockupFile, $userId);
        if ((int)($existing['id'] ?? 0) > 0) {
            return $existing;
        }
        $now = date('c');
        $this->pdo->prepare('
            INSERT INTO mockup_sheets (
                user_id, artwork_sheet_id, artwork_id, mockup_file, user_notes,
                title, description, keywords, tags, alt_text, caption, status, generated_json,
                created_at, updated_at
            ) VALUES (
                :user_id, :artwork_sheet_id, :artwork_id, :mockup_file, :user_notes,
                :title, :description, :keywords, :tags, :alt_text, :caption, :status, :generated_json,
                :created_at, :updated_at
            )
        ')->execute([
            'user_id' => $userId,
            'artwork_sheet_id' => $artworkSheetId,
            'artwork_id' => $artworkId,
            'mockup_file' => basename($mockupFile),
            'user_notes' => $notes,
            'title' => '',
            'description' => '',
            'keywords' => '',
            'tags' => '',
            'alt_text' => '',
            'caption' => '',
            'status' => 'draft',
            'generated_json' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->mockupSheetForFile($artworkSheetId, $artworkId, $mockupFile, $userId);
    }

    /**
     * @param array<string,mixed> $input
     */
    public function saveMockupSheet(int $mockupSheetId, int $userId, array $input): void
    {
        $fields = [
            'user_notes',
            'title',
            'description',
            'keywords',
            'tags',
            'alt_text',
            'caption',
            'status',
        ];
        $payload = [];
        foreach ($fields as $field) {
            $payload[$field] = trim((string)($input[$field] ?? ''));
        }
        $stmt = $this->pdo->prepare(
            'UPDATE mockup_sheets SET
                user_notes = :user_notes,
                title = :title,
                description = :description,
                keywords = :keywords,
                tags = :tags,
                alt_text = :alt_text,
                caption = :caption,
                status = :status,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id'
        );
        $payload['id'] = $mockupSheetId;
        $payload['user_id'] = $userId;
        $stmt->execute($payload);
    }

    /**
     * @param array<string,mixed> $generated
     */
    private function updateMockupSheet(int $mockupSheetId, int $userId, string $notes, array $generated): void
    {
        $this->pdo->prepare('
            UPDATE mockup_sheets
            SET user_notes = :user_notes,
                title = :title,
                description = :description,
                keywords = :keywords,
                tags = :tags,
                alt_text = :alt_text,
                caption = :caption,
                generated_json = :generated_json,
                updated_at = :updated_at
            WHERE id = :id AND user_id = :user_id
        ')->execute([
            'user_notes' => $notes,
            'title' => trim((string)($generated['title'] ?? '')),
            'description' => trim((string)($generated['description'] ?? '')),
            'keywords' => $this->csv($generated['keywords'] ?? ''),
            'tags' => $this->csv($generated['tags'] ?? ''),
            'alt_text' => trim((string)($generated['alt_text'] ?? '')),
            'caption' => trim((string)($generated['caption'] ?? '')),
            'generated_json' => json_encode($generated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => date('c'),
            'id' => $mockupSheetId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Stores a Spanish-first analysis without copying it into the legacy
     * English publication columns.
     *
     * @param array<string,mixed> $generated
     */
    private function updateMockupAnalysisDraft(int $mockupSheetId, int $userId, string $notes, array $generated): void
    {
        $this->pdo->prepare('
            UPDATE mockup_sheets
            SET user_notes = :user_notes,
                generated_json = :generated_json,
                updated_at = :updated_at
            WHERE id = :id AND user_id = :user_id
        ')->execute([
            'user_notes' => $notes,
            'generated_json' => json_encode($generated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => date('c'),
            'id' => $mockupSheetId,
            'user_id' => $userId,
        ]);
    }

    private function mockupIdForFile(int $userId, string $mockupFile): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM mockups WHERE user_id=? AND mockup_file=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId, basename($mockupFile)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<int,int>
     */
    private function relatedIdsFromSheet(array $sheet): array
    {
        $decoded = json_decode((string)($sheet['related_artwork_ids'] ?? ''), true);
        $ids = is_array($decoded) ? $decoded : preg_split('/[,\s]+/', (string)($sheet['related_artwork_ids'] ?? ''));
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
        if (!in_array((int)$sheet['canonical_artwork_id'], $ids, true)) {
            array_unshift($ids, (int)$sheet['canonical_artwork_id']);
        }
        return $ids;
    }

    /**
     * @return array<int,array<int,string>>
     */
    private function artworkFilesForIds(array $ids, int $userId): array
    {
        $out = [];
        foreach ($ids as $id) {
            try {
                $artwork = $this->artwork((int)$id, $userId);
            } catch (Throwable $e) {
                continue;
            }
            $files = [];
            foreach (['root_file', 'main_file'] as $key) {
                $file = basename((string)($artwork[$key] ?? ''));
                if ($file !== '') {
                    $files[] = $file;
                }
            }
            $out[(int)$id] = array_values(array_unique($files));
        }
        return $out;
    }

    private function normalizeRelatedIds(string $value): string
    {
        $decoded = json_decode($value, true);
        $raw = is_array($decoded) ? $decoded : preg_split('/[,\s]+/', $value);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$raw))));
        return json_encode($ids, JSON_UNESCAPED_SLASHES);
    }

    private function resolveImagePath(string $file): string
    {
        $file = trim($file);
        if ($file === '') {
            return '';
        }
        $candidates = [
            $file,
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $file,
            RESULTS_DIR . DIRECTORY_SEPARATOR . basename($file),
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($file),
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'roots' . DIRECTORY_SEPARATOR . basename($file),
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function fallbackMockupCopy(array $artworkSheet, string $mockupFile, string $notes): array
    {
        $title = trim((string)($artworkSheet['title'] ?? 'Obra'));
        if ($title === '') {
            $title = 'Obra';
        }
        $mockupTitle = $title . ' en un mockup de interior';
        $description = $notes !== ''
            ? 'Borrador del mockup basado en las notas del artista. Revisar antes de publicar: ' . $notes
            : 'Borrador del mockup pendiente de una descripción específica del entorno y del contexto de presentación.';
        return [
            'title' => $mockupTitle,
            'description' => $description,
            'keywords' => ['mockup de obra', 'arte contemporáneo', 'interior', 'presentación visual'],
            'tags' => ['mockup', 'obra', 'contexto-interior'],
            'alt_text' => 'Mockup de ' . $title . ' mostrado en un contexto de presentación interior.',
            'caption' => $mockupTitle,
        ];
    }

    private function csv($value): string
    {
        if (is_array($value)) {
            return implode(', ', array_values(array_filter(array_map('strval', $value))));
        }
        return trim((string)$value);
    }

    private function extractJson(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', (string)$text);
        $start = strpos((string)$text, '{');
        $end = strrpos((string)$text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr((string)$text, $start, $end - $start + 1);
        }
        return (string)$text;
    }
}
