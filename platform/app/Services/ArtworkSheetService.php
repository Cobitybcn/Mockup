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
     * @return array<string,mixed>
     */
    public function generateArtworkSheet(int $sheetId, int $userId): array
    {
        $sheet = $this->sheet($sheetId, $userId);
        $artwork = $this->artwork((int)$sheet['canonical_artwork_id'], $userId);
        $imagePath = $this->resolveImagePath((string)($sheet['source_image_file'] ?: ($artwork['root_file'] ?? $artwork['main_file'] ?? '')));
        $notes = trim((string)($sheet['user_notes'] ?? ''));
        $fallback = $this->fallbackArtworkCopy($artwork, $notes);

        $generated = $fallback;
        if (ProviderSettings::isRealMode() && ProviderSettings::allowRealApi() && ProviderSettings::imageProvider() === 'gemini' && $imagePath !== '') {
            $artistProfile = ArtistProfile::findForUser($userId);
            $prompt = $this->buildAdminArtworkAnalysisPrompt($artwork, $artistProfile, $notes);
            try {
                $text = $this->client->generateText([
                    $this->client->textPart($prompt),
                    $this->client->imagePart($imagePath),
                ], 'gemini-2.5-flash');
                $decoded = json_decode($this->extractJson($text), true);
                if (is_array($decoded)) {
                    $this->saveArtworkAnalysisArtifacts((int)$artwork['id'], $imagePath, $decoded, $prompt, $text);
                    $generated = $this->metadataFromAdminAnalysis($decoded, $fallback);
                }
            } catch (Throwable $e) {
                $generated = $fallback;
                $generated['_warning'] = $e->getMessage();
            }
        }

        $this->applyGeneratedArtworkSheet($sheetId, $userId, $generated);
        return $generated;
    }

    private function buildAdminArtworkAnalysisPrompt(array $artwork, array $artistProfile, string $notes): string
    {
        $width = trim((string)($artwork['width'] ?? ''));
        $height = trim((string)($artwork['height'] ?? ''));
        $orientation = 'Not specified';
        if ((float)$width > 0 && (float)$height > 0) {
            $orientation = (float)$width > (float)$height ? 'horizontal' : (((float)$height > (float)$width) ? 'vertical' : 'square');
        }

        $prompt = PromptSettings::artworkAnalysisPrompt();
        return strtr($prompt, [
            '{artist_profile_prompt}' => ArtistProfile::hasContent($artistProfile) ? ArtistProfile::forPrompt($artistProfile) : '',
            '{artist_statement}' => (string)($artistProfile['statement'] ?? ''),
            '{visual_language}' => (string)($artistProfile['visual_language'] ?? ''),
            '{recurring_symbols}' => (string)($artistProfile['recurring_themes'] ?? ''),
            '{preferred_atmospheres}' => (string)($artistProfile['preferred_contexts'] ?? ''),
            '{title}' => trim((string)($artwork['final_title'] ?? '')) ?: 'Untitled artwork',
            '{width_cm}' => $width,
            '{height_cm}' => $height,
            '{depth_cm}' => trim((string)($artwork['depth'] ?? '')),
            '{notes}' => $notes,
            '{preferred_style}' => '',
            '{target_market}' => trim((string)($artistProfile['target_audience'] ?? 'collectors')),
            '{orientation}' => $orientation,
            '{region}' => trim((string)($artistProfile['preferred_regions'] ?? '')),
            '{scale_text}' => trim($width . ' x ' . $height . ' ' . (string)($artwork['unit'] ?? 'cm')),
            '{context_count}' => (string)PromptSettings::mockupContextCount(),
        ]);
    }

    /**
     * @param array<string,mixed> $analysis
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>
     */
    private function metadataFromAdminAnalysis(array $analysis, array $fallback): array
    {
        $profile = is_array($analysis['artwork_analysis'] ?? null) ? $analysis['artwork_analysis'] : $analysis;
        $publishing = is_array($profile['publishing_metadata'] ?? null) ? $profile['publishing_metadata'] : [];
        $titles = is_array($analysis['suggested_titles'] ?? null)
            ? $analysis['suggested_titles']
            : (is_array($publishing['suggested_titles'] ?? null) ? $publishing['suggested_titles'] : []);
        $firstTitle = is_array($titles[0] ?? null) ? $titles[0] : [];
        $rootMeta = is_array($publishing['root_image_metadata'] ?? null) ? $publishing['root_image_metadata'] : [];

        $keywords = $publishing['keywords'] ?? $this->keywordsFromAdminAnalysis($analysis);
        $longTail = $publishing['long_tail_keywords'] ?? $this->longTailFromAdminAnalysis($analysis, $firstTitle);
        $title = trim((string)($firstTitle['title'] ?? $fallback['title'] ?? ''));
        $subtitle = trim((string)($firstTitle['subtitle'] ?? $fallback['subtitle'] ?? ''));
        $description = trim((string)($firstTitle['description'] ?? $profile['one_line_curatorial_read'] ?? $fallback['description'] ?? ''));
        $generated = [
            'title' => $title,
            'subtitle' => $subtitle,
            'description' => $description,
            'short_description' => trim((string)($profile['one_line_curatorial_read'] ?? $fallback['short_description'] ?? '')),
            'keywords' => is_array($keywords) ? $keywords : $fallback['keywords'],
            'tags' => is_array($keywords) ? $keywords : $fallback['tags'],
            'alt_text' => trim((string)($rootMeta['alt_text'] ?? ($title !== '' ? 'Abstract artwork titled ' . $title . ', showing bold color fields, symbolic ladders, geometric forms, and a luminous circular motif.' : ($fallback['alt_text'] ?? '')))),
            'caption' => trim((string)($rootMeta['caption'] ?? ($title . ($subtitle !== '' ? ' - ' . $subtitle : '')))),
            'long_tail_terms' => is_array($longTail) ? $longTail : [],
            '_admin_analysis' => $analysis,
        ];

        return array_merge($fallback, $generated);
    }

    /**
     * @param array<string,mixed> $analysis
     * @return array<int,string>
     */
    private function keywordsFromAdminAnalysis(array $analysis): array
    {
        $terms = [];
        foreach ((array)($analysis['contextual_proposals'] ?? []) as $proposal) {
            if (!is_array($proposal)) {
                continue;
            }
            foreach (['space_type', 'atmosphere', 'lighting', 'camera_view'] as $key) {
                $value = trim((string)($proposal[$key] ?? ''));
                if ($value !== '') {
                    $terms[] = $value;
                }
            }
            foreach ((array)($proposal['materials'] ?? []) as $material) {
                $terms[] = (string)$material;
            }
        }
        $terms[] = 'contemporary abstract art';
        $terms[] = 'original canvas artwork';
        return array_slice(array_values(array_unique(array_filter(array_map('trim', $terms)))), 0, 15);
    }

    /**
     * @param array<string,mixed> $analysis
     * @param array<string,mixed> $firstTitle
     * @return array<int,string>
     */
    private function longTailFromAdminAnalysis(array $analysis, array $firstTitle): array
    {
        $title = trim((string)($firstTitle['title'] ?? 'abstract artwork'));
        $terms = [
            $title . ' original contemporary artwork',
            $title . ' abstract canvas painting',
            'large contemporary abstract artwork for collectors',
            'blue red and ochre abstract canvas painting',
            'symbolic ladder abstract artwork',
            'premium contemporary art for interiors',
        ];
        foreach ((array)($analysis['contextual_proposals'] ?? []) as $proposal) {
            if (!is_array($proposal)) {
                continue;
            }
            $contextName = trim((string)($proposal['context_name'] ?? ''));
            if ($contextName !== '') {
                $terms[] = $title . ' in ' . $contextName . ' context';
            }
        }
        return array_slice(array_values(array_unique(array_filter($terms))), 0, 15);
    }

    /**
     * @param array<string,mixed> $analysis
     */
    private function saveArtworkAnalysisArtifacts(int $artworkId, string $imagePath, array $analysis, string $prompt, string $rawText): void
    {
        $json = json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        if (!is_dir(ANALYSIS_DIR)) {
            @mkdir(ANALYSIS_DIR, 0775, true);
        }
        $base = pathinfo(basename($imagePath), PATHINFO_FILENAME);
        @file_put_contents(ANALYSIS_DIR . DIRECTORY_SEPARATOR . $base . '.analysis.json', $json);
        @file_put_contents(ANALYSIS_DIR . DIRECTORY_SEPARATOR . $base . '.analysis-prompt.txt', $prompt);
        @file_put_contents(ANALYSIS_DIR . DIRECTORY_SEPARATOR . $base . '.analysis-raw.txt', $rawText);

        $this->pdo->prepare('
            INSERT INTO artwork_analysis (artwork_id, provider, analysis_json, created_at)
            VALUES (:artwork_id, :provider, :analysis_json, :created_at)
        ')->execute([
            'artwork_id' => $artworkId,
            'provider' => 'gemini-admin-analysis',
            'analysis_json' => $json,
            'created_at' => date('c'),
        ]);
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

        if (ProviderSettings::isRealMode() && ProviderSettings::allowRealApi() && ProviderSettings::imageProvider() === 'gemini' && $imagePath !== '') {
            $artworkIdentity = json_decode((string)($artworkSheet['generated_json'] ?? ''), true);
            $artworkIdentity = is_array($artworkIdentity) ? $artworkIdentity : [];
            $prompt = "Analyze this exact mockup image. The approved artwork identity is authoritative; analyze the scene without renaming, reinterpreting, or inventing facts about the artwork. Return strict JSON only.\n"
                . "APPROVED ARTWORK IDENTITY:\n" . json_encode($artworkIdentity ?: ['title'=>$artworkSheet['title'],'subtitle'=>$artworkSheet['subtitle'],'description'=>$artworkSheet['description']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n"
                . "MOCKUP RULES: describe space type, architecture, materials, lighting, camera, scale perception, atmosphere, and artwork-space relationship. Keywords, long tails, tags, captions and channel copy must be justified by the visible image. Never use generic repeated copy. Never call the artwork framed unless a real frame is visible. Exclude home decor, wall art, perfect for any room, elevate your space, decor inspiration, and generic interior-marketing filler. Do not invent furniture, materials, colors, light, artwork facts, or destination links. Website is detailed and collector-facing; Pinterest is shorter and traffic-oriented; Instagram is visual/community-oriented; Facebook is conversational; TikTok is future preparation only.\n"
                . "USER NOTES: {$notes}\n"
                . 'Return: {"schema_version":"mockup-analysis.v2","neutral":{"context_title":"","contextual_description":"","alt_text":"","caption":"","keywords":[],"long_tail_keywords":[],"tags":[],"scene":{"space_type":"","architecture":"","materials":[],"lighting":"","camera":"","scale_reading":"","atmosphere":[],"artwork_space_relationship":"","distinctive_features":[]}},"channels":{"website":{"description":"","caption":"","alt_text":"","seo_keywords":[],"long_tail_keywords":[]},"pinterest":{"title":"","description":"","board_suggestions":[],"topic_suggestions":[],"keywords":[]},"instagram":{"caption":"","hook":"","hashtags":[],"cta":""},"facebook":{"headline":"","post_text":"","link_description":"","cta":""},"tiktok":{"status":"future","tiktok_ready":false,"visual_hook":"","suggested_motion":"","sequence_role":"","caption_seed":"","video_notes":""}},"review":{"status":"draft","warnings":[]}}';
            try {
                $text = $this->client->generateText([
                    $this->client->textPart($prompt),
                    $this->client->imagePart($imagePath),
                ], 'gemini-2.5-flash');
                $decoded = json_decode($this->extractJson($text), true);
                if (is_array($decoded)) {
                    $neutral = is_array($decoded['neutral'] ?? null) ? $decoded['neutral'] : [];
                    $generated = array_merge($fallback, [
                        'title'=>(string)($neutral['context_title']??''),
                        'description'=>(string)($neutral['contextual_description']??''),
                        'keywords'=>(array)($neutral['keywords']??[]),
                        'tags'=>(array)($neutral['tags']??[]),
                        'alt_text'=>(string)($neutral['alt_text']??''),
                        'caption'=>(string)($neutral['caption']??''),
                        'long_tail_keywords'=>(array)($neutral['long_tail_keywords']??[]),
                        'mockup_analysis_v2'=>$decoded,
                    ]);
                }
            } catch (Throwable $e) {
                $generated = $fallback;
                $generated['_warning'] = $e->getMessage();
            }
        }

        $this->updateMockupSheet((int)$sheet['id'], $userId, $notes, $generated);
        return $generated;
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
     * @param array<string,mixed> $generated
     */
    private function applyGeneratedArtworkSheet(int $sheetId, int $userId, array $generated): void
    {
        $this->pdo->prepare('
            UPDATE artwork_sheets
            SET title = :title,
                subtitle = :subtitle,
                description = :description,
                short_description = :short_description,
                keywords = :keywords,
                tags = :tags,
                alt_text = :alt_text,
                caption = :caption,
                generated_json = :generated_json,
                updated_at = :updated_at
            WHERE id = :id AND user_id = :user_id
        ')->execute([
            'title' => trim((string)($generated['title'] ?? '')),
            'subtitle' => trim((string)($generated['subtitle'] ?? '')),
            'description' => trim((string)($generated['description'] ?? '')),
            'short_description' => trim((string)($generated['short_description'] ?? '')),
            'keywords' => $this->csv($generated['keywords'] ?? ''),
            'tags' => $this->csv($generated['tags'] ?? ''),
            'alt_text' => trim((string)($generated['alt_text'] ?? '')),
            'caption' => trim((string)($generated['caption'] ?? '')),
            'generated_json' => json_encode($generated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => date('c'),
            'id' => $sheetId,
            'user_id' => $userId,
        ]);
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
    private function fallbackArtworkCopy(array $artwork, string $notes): array
    {
        $title = trim((string)($artwork['final_title'] ?? ''));
        if ($title === '') {
            $title = 'Untitled Artwork';
        }
        $subtitle = trim((string)($artwork['subtitle'] ?? ''));
        $medium = trim((string)($artwork['medium'] ?? 'original artwork'));
        $dimensions = trim((string)($artwork['width'] ?? '') . ' x ' . (string)($artwork['height'] ?? '') . ' ' . (string)($artwork['unit'] ?? 'cm'));
        $description = $notes !== ''
            ? 'Draft artwork description based on the curator notes. Translate and refine before publishing: ' . $notes
            : 'Draft metadata for an original artwork. Review the source image and refine the curatorial reading before publishing.';
        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'description' => $description,
            'short_description' => substr($description, 0, 180),
            'keywords' => array_values(array_filter([$medium, 'contemporary art', 'original artwork'])),
            'tags' => ['artwork', 'catalog', 'contemporary-art'],
            'alt_text' => 'Image of the artwork ' . $title . ($dimensions !== ' x  cm' ? ', ' . $dimensions : '') . '.',
            'caption' => $title . ($subtitle !== '' ? ' — ' . $subtitle : ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fallbackMockupCopy(array $artworkSheet, string $mockupFile, string $notes): array
    {
        $title = trim((string)($artworkSheet['title'] ?? 'Artwork'));
        if ($title === '') {
            $title = 'Artwork';
        }
        $mockupTitle = $title . ' in a styled interior mockup';
        $description = $notes !== ''
            ? 'Artwork mockup draft based on the user notes. Translate and refine before publishing: ' . $notes
            : 'Artwork mockup draft pending a specific description of the visual setting and presentation context.';
        return [
            'title' => $mockupTitle,
            'description' => $description,
            'keywords' => ['artwork mockup', 'contemporary art', 'interior styling', 'visual presentation'],
            'tags' => ['mockup', 'artwork', 'interior-context'],
            'alt_text' => 'Mockup of ' . $title . ' shown in a styled presentation context.',
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
