<?php
declare(strict_types=1);

final class WebsiteBoardService
{
    private PublicationService $publications;
    /** @var array<int,array<string,array<string,mixed>>> */
    private array $sourceCache = [];

    public function __construct(private PDO $pdo)
    {
        $this->publications = new PublicationService($pdo);
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS social_campaigns (
            id {$id}, user_id INTEGER NOT NULL, campaign_type VARCHAR(40) NOT NULL,
            title VARCHAR(255) NOT NULL, objective {$text} NOT NULL,
            source_type VARCHAR(40) NOT NULL DEFAULT '', source_id VARCHAR(80) NOT NULL DEFAULT '',
            source_label VARCHAR(255) NOT NULL DEFAULT '', status VARCHAR(32) NOT NULL DEFAULT 'draft',
            payload_json {$text} NOT NULL, created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL
        )");
    }

    /** @return array<int,array<string,mixed>> */
    public function sources(int $userId): array
    {
        $sources = [];
        $artworkFallback = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? "CONCAT('Obra #',a.id)"
            : "('Obra #' || a.id)";

        $artworkStmt = $this->pdo->prepare("SELECT a.id,
                COALESCE(NULLIF(sh.title,''),NULLIF(ag.title,''),NULLIF(a.final_title,''),{$artworkFallback}) label,
                COALESCE(NULLIF(sh.source_image_file,''),NULLIF(a.root_file,''),NULLIF(a.main_file,''),'') file,
                COALESCE(a.series_id,0) series_id, COALESCE(NULLIF(s.title,''),NULLIF(a.series,''),'') series_title,
                COALESCE(sh.id,0) artwork_sheet_id,
                CASE WHEN EXISTS(SELECT 1 FROM publications wp WHERE wp.user_id=a.user_id AND wp.artwork_sheet_id=sh.id AND wp.status='published') THEN 1 ELSE 0 END website_published
            FROM artworks a
            LEFT JOIN artwork_groups ag ON ag.id=a.artwork_group_id AND ag.user_id=a.user_id
            LEFT JOIN artwork_series s ON s.id=a.series_id AND s.user_id=a.user_id
            LEFT JOIN artwork_sheets sh ON sh.id=(SELECT MAX(sh2.id) FROM artwork_sheets sh2
                WHERE sh2.user_id=a.user_id AND sh2.canonical_artwork_id=a.id
                AND COALESCE(sh2.status,'')<>'merged')
            WHERE a.user_id=? AND a.status='done'
            AND (COALESCE(a.artwork_group_id,0)=0
                OR (ag.status='active' AND ag.canonical_artwork_id=a.id))
            ORDER BY ag.updated_at DESC,ag.created_at DESC,ag.id DESC,a.id DESC");
        $artworkStmt->execute([$userId]);
        foreach ($artworkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $file = basename((string)$row['file']);
            if ($file === '' || (int)$row['artwork_sheet_id'] <= 0) continue;
            $sources[] = $this->source('artwork', (int)$row['id'], $file, (string)$row['label'], [
                'artworkId' => (int)$row['id'],
                'artworkSheetId' => (int)$row['artwork_sheet_id'],
                'seriesId' => (int)$row['series_id'],
                'seriesTitle' => (string)$row['series_title'],
                'websitePublished' => (bool)$row['website_published'],
            ]);
        }

        $seriesStmt = $this->pdo->prepare("SELECT id,title,header_file FROM artwork_series WHERE user_id=? AND status='active' ORDER BY COALESCE(year_end,year_start,0) DESC,created_at DESC,id DESC");
        $seriesStmt->execute([$userId]);
        foreach ($seriesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $file = basename((string)$row['header_file']);
            if ($file === '') {
                $fallback = $this->pdo->prepare("SELECT COALESCE(NULLIF(sh.source_image_file,''),NULLIF(a.root_file,''),NULLIF(a.main_file,''),'')
                    FROM artworks a
                    LEFT JOIN artwork_groups ag ON ag.id=a.artwork_group_id AND ag.user_id=a.user_id
                    LEFT JOIN artwork_sheets sh ON sh.id=(SELECT MAX(sh2.id) FROM artwork_sheets sh2
                        WHERE sh2.user_id=a.user_id AND sh2.canonical_artwork_id=a.id
                        AND COALESCE(sh2.status,'')<>'merged')
                    WHERE a.user_id=? AND a.series_id=? AND a.status='done'
                    AND (COALESCE(a.artwork_group_id,0)=0
                        OR (ag.status='active' AND ag.canonical_artwork_id=a.id))
                    ORDER BY a.id DESC LIMIT 1");
                $fallback->execute([$userId, (int)$row['id']]);
                $file = basename((string)($fallback->fetchColumn() ?: ''));
            }
            if ($file === '') continue;
            $sources[] = $this->source('series', (int)$row['id'], $file, (string)$row['title'], [
                'seriesId' => (int)$row['id'],
                'seriesTitle' => (string)$row['title'],
            ]);
        }

        $favoriteIds = MockupFavorites::idsForUser($userId);
        $favoriteLookup = array_fill_keys($favoriteIds, true);
        $favoritePosition = array_flip($favoriteIds);
        $mockupStmt = $this->pdo->prepare("SELECT m.id,m.mockup_file,m.context_id,m.source_artwork_id,m.selector_state_json,
                COALESCE(m.series_id,a.series_id,0) series_id,
                COALESCE(NULLIF(ag.title,''),NULLIF(a.final_title,''),{$artworkFallback}) artwork_title,
                COALESCE(NULLIF(s.title,''),NULLIF(a.series,''),'') series_title,
                COALESCE(NULLIF(ms.title,''),'') editorial_title,
                COALESCE(ms.description,'') editorial_description,
                COALESCE(ms.caption,'') editorial_caption,
                COALESCE(ms.alt_text,'') editorial_alt_text,
                COALESCE(ms.keywords,'') editorial_keywords,
                COALESCE(ms.generated_json,'') editorial_generated_json,
                m.created_at
            FROM mockups m
            LEFT JOIN artworks a ON a.id=m.source_artwork_id AND a.user_id=m.user_id
            LEFT JOIN artwork_groups ag ON ag.id=a.artwork_group_id AND ag.user_id=m.user_id AND ag.status='active'
            LEFT JOIN artwork_series s ON s.id=COALESCE(m.series_id,a.series_id) AND s.user_id=m.user_id
            LEFT JOIN mockup_sheets ms ON ms.id=(SELECT MAX(ms2.id) FROM mockup_sheets ms2 WHERE ms2.user_id=m.user_id AND ms2.mockup_file=m.mockup_file)
            WHERE m.user_id=? ORDER BY m.created_at DESC,m.id DESC LIMIT 200");
        $mockupStmt->execute([$userId]);
        $mockups = $mockupStmt->fetchAll(PDO::FETCH_ASSOC);
        usort($mockups, static function (array $left, array $right) use ($favoriteLookup, $favoritePosition): int {
            $leftId = (int)$left['id']; $rightId = (int)$right['id'];
            $lf = isset($favoriteLookup[$leftId]); $rf = isset($favoriteLookup[$rightId]);
            if ($lf !== $rf) return $lf ? -1 : 1;
            if ($lf) return ($favoritePosition[$leftId] ?? PHP_INT_MAX) <=> ($favoritePosition[$rightId] ?? PHP_INT_MAX);
            return strcmp((string)$right['created_at'], (string)$left['created_at']);
        });
        foreach ($mockups as $row) {
            $file = basename((string)$row['mockup_file']);
            if ($file === '') continue;
            $context = Display::contextTitle((string)$row['context_id']);
            $selectorState = json_decode((string)($row['selector_state_json'] ?? ''), true);
            $selectorState = is_array($selectorState) ? $selectorState : [];
            $combination = is_array($selectorState['combination'] ?? null) ? $selectorState['combination'] : [];
            $cameraSlotId = trim((string)($combination['selected_camera_slot_id'] ?? ''));
            $cameraSlotName = trim((string)($combination['camera_slot_name'] ?? ''));
            $cameraContextTitle = trim((string)($combination['context_title'] ?? ''));
            $label = trim((string)$row['editorial_title']);
            if ($label === '') $label = trim((string)$row['artwork_title'] . ' — ' . $context, " —");
            $editorialGuide = $this->mockupEditorialGuide($row);
            $searchTerms = [
                $cameraSlotId,
                str_replace('_', ' ', $cameraSlotId),
                $cameraSlotName,
                $cameraContextTitle,
                (string)($editorialGuide['title'] ?? ''),
                (string)($editorialGuide['caption'] ?? ''),
                (string)($editorialGuide['altText'] ?? ''),
                implode(' ', (array)($editorialGuide['keywords'] ?? [])),
            ];
            if (MockupVariationEligibility::isCloseupMockup($row)) {
                $searchTerms[] = 'close close-up closeup detail detalle macro texture textura edge borde corner esquina surface superficie crop recorte';
            }
            $sources[] = $this->source('mockup', (int)$row['id'], $file, $label, [
                'artworkId' => (int)$row['source_artwork_id'],
                'artworkTitle' => (string)$row['artwork_title'],
                'seriesId' => (int)$row['series_id'],
                'seriesTitle' => (string)$row['series_title'],
                'favorite' => isset($favoriteLookup[(int)$row['id']]),
                'contextTitle' => $context,
                'cameraSlotId' => $cameraSlotId,
                'cameraSlotName' => $cameraSlotName,
                'searchTerms' => implode(' ', array_filter($searchTerms, static fn(string $value): bool => $value !== '')),
                'editorialGuide' => $editorialGuide,
            ]);
        }

        return $sources;
    }

    /** @return array<int,array<string,mixed>> */
    public function catalogEntries(int $userId, bool $includePublished = false): array
    {
        $artworkFallback = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? "CONCAT('Obra #',a.id)"
            : "('Obra #' || a.id)";
        $stmt = $this->pdo->prepare("SELECT p.*,sh.source_image_file,sh.subtitle,sh.canonical_artwork_id artwork_id,
                sh.title sheet_title,sh.short_description sheet_short_description,sh.description sheet_description,
                COALESCE(NULLIF(sh.title,''),NULLIF(ag.title,''),NULLIF(a.final_title,''),NULLIF(p.title,''),{$artworkFallback}) artwork_title,
                COALESCE(NULLIF(s.title,''),NULLIF(a.series,''),'') series_title
            FROM publications p
            JOIN artwork_sheets sh ON sh.id=p.artwork_sheet_id AND sh.user_id=p.user_id
            JOIN artworks a ON a.id=sh.canonical_artwork_id AND a.user_id=p.user_id
            LEFT JOIN artwork_groups ag ON ag.id=a.artwork_group_id AND ag.user_id=p.user_id AND ag.status='active'
            LEFT JOIN artwork_series s ON s.id=a.series_id AND s.user_id=p.user_id
            WHERE p.user_id=?" . ($includePublished ? '' : " AND p.status<>'published'") . "
            ORDER BY ag.updated_at DESC,ag.created_at DESC,ag.id DESC,a.id DESC,p.id DESC");
        $stmt->execute([$userId]);
        $entries = [];
        $seenSheets = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sheetId = (int)$row['artwork_sheet_id'];
            if (isset($seenSheets[$sheetId])) continue;
            $seenSheets[$sheetId] = true;
            $details = $this->publications->get((int)$row['id'], $userId);
            $media = [];
            $main = basename((string)$row['source_image_file']);
            if ($main !== '') $media[] = ['file' => $main, 'role' => 'artwork'];
            foreach ($details['items'] as $item) {
                $file = basename((string)$item['mockup_file']);
                if ($file !== '') $media[] = ['file' => $file, 'role' => 'mockup'];
            }
            $publicationTitle = trim((string)$row['title']);
            $usesPlaceholderTitle = $publicationTitle === '' || preg_match('/^Obra\s+#\d+$/iu', $publicationTitle) === 1;
            $entries[] = [
                'id' => (int)$row['id'],
                'artworkId' => (int)$row['artwork_id'],
                'artworkSheetId' => $sheetId,
                'title' => $usesPlaceholderTitle ? (string)$row['artwork_title'] : $publicationTitle,
                'subtitle' => (string)$row['subtitle'],
                'description' => (string)($row['description'] ?: $row['sheet_description']),
                'shortDescription' => (string)($row['short_description'] ?: $row['sheet_short_description']),
                'ctaLabel' => (string)$row['cta_label'],
                'ctaUrl' => (string)$row['cta_url'],
                'status' => (string)$row['status'],
                'visibility' => (string)$row['visibility'],
                'seriesTitle' => (string)$row['series_title'],
                'updatedAt' => (string)$row['updated_at'],
                'media' => $media,
            ];
        }
        return $entries;
    }

    /** @return array<int,array<string,mixed>> */
    public function notes(int $userId, bool $includePublished = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM social_campaigns WHERE user_id=? ORDER BY updated_at DESC,id DESC');
        $stmt->execute([$userId]);
        $notes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = json_decode((string)$row['payload_json'], true);
            if (!is_array($payload) || !in_array('website_blog', array_map('strval', (array)($payload['channels'] ?? [])), true)) continue;
            if (!$includePublished && (string)$row['status'] === 'published') continue;
            $media = $this->normalizeNoteMedia($userId, $payload);
            $sourceKey = (string)(($payload['source']['key'] ?? ''));
            $source = null;
            foreach ($media as $item) {
                if ((string)($item['key'] ?? '') === $sourceKey) {
                    $source = $item;
                    break;
                }
            }
            $source ??= $media[0] ?? null;
            $notes[] = [
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'objective' => (string)$row['objective'],
                'sourceLabel' => (string)$row['source_label'],
                'source' => $source,
                'media' => $media,
                'status' => (string)$row['status'],
                'updatedAt' => (string)$row['updated_at'],
            ];
        }
        return $notes;
    }

    public function addCatalogArtwork(int $userId, string $sourceKey): array
    {
        $source = $this->resolveSource($userId, $sourceKey);
        if ($source['type'] !== 'artwork') throw new RuntimeException('El catálogo solo acepta obras.');
        $sheetId = (int)($source['artworkSheetId'] ?? 0);
        $stmt = $this->pdo->prepare('SELECT id FROM publications WHERE user_id=? AND artwork_sheet_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId, $sheetId]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            $existing = $this->publications->get($id, $userId);
            if ((string)$existing['status'] === 'published') throw new RuntimeException('Esta obra ya está publicada en el website.');
        }
        if ($id <= 0) $id = $this->publications->createForSheet($sheetId, $userId);
        return $this->catalogEntry($userId, $id);
    }

    public function saveCatalog(int $userId, int $publicationId, array $input): array
    {
        $this->publications->save($publicationId, $userId, [
            'title' => trim((string)($input['title'] ?? '')),
            'short_description' => trim((string)($input['shortDescription'] ?? '')),
            'description' => trim((string)($input['description'] ?? '')),
            'cta_label' => trim((string)($input['ctaLabel'] ?? '')),
            'cta_url' => trim((string)($input['ctaUrl'] ?? '')),
        ], null);
        return $this->catalogEntry($userId, $publicationId);
    }

    public function catalogAction(int $userId, int $publicationId, string $action): ?array
    {
        $publication = $this->publications->get($publicationId, $userId);
        if ($action === 'delete') {
            $this->publications->remove($publicationId, $userId);
            return null;
        }
        if ($action === 'publish') {
            $plan = $this->catalogPublishPlan($userId, $publicationId, $publication);
            $this->publications->save($publicationId, $userId, ['visibility' => 'public', 'publish' => true], $plan['mockupSheetIds']);
        } elseif ($action === 'hide') {
            $this->publications->save($publicationId, $userId, ['visibility' => 'unlisted'], null);
        } elseif ($action === 'show') {
            $this->publications->save($publicationId, $userId, ['visibility' => 'public'], null);
        } elseif ($action === 'unpublish') {
            $this->publications->save($publicationId, $userId, ['visibility' => 'private', 'unpublish' => true], null);
        } else {
            throw new RuntimeException('Acción de catálogo desconocida.');
        }
        return $this->catalogEntry($userId, $publicationId);
    }

    /**
     * Validate every requested draft before changing any publication, then publish
     * the complete set in one database transaction.
     *
     * @param array<int,mixed> $publicationIds
     * @return array{count:int,published:array<int,array<string,mixed>>}
     */
    public function publishCatalogDrafts(int $userId, array $publicationIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $publicationIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!$ids) throw new RuntimeException('No hay borradores de obras para publicar.');

        $plans = [];
        $errors = [];
        foreach ($ids as $publicationId) {
            try {
                $publication = $this->publications->get($publicationId, $userId);
                $plans[] = $this->catalogPublishPlan($userId, $publicationId, $publication);
            } catch (Throwable $error) {
                $label = isset($publication) && (int)($publication['id'] ?? 0) === $publicationId
                    ? (trim((string)($publication['title'] ?? '')) ?: 'Obra #' . $publicationId)
                    : 'Obra #' . $publicationId;
                $errors[] = '«' . $label . '»: ' . $error->getMessage();
            }
            unset($publication);
        }
        if ($errors) {
            throw new RuntimeException('No se publicó ninguna obra. Revisa: ' . implode(' ', $errors));
        }

        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) $this->pdo->beginTransaction();
        try {
            foreach ($plans as $plan) {
                $this->publications->save(
                    (int)$plan['publicationId'],
                    $userId,
                    ['visibility' => 'public', 'publish' => true],
                    $plan['mockupSheetIds']
                );
            }
            if ($startedTransaction) $this->pdo->commit();
        } catch (Throwable $error) {
            if ($startedTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }

        $published = [];
        foreach ($plans as $plan) {
            $published[] = $this->catalogEntry($userId, (int)$plan['publicationId']);
        }
        return ['count' => count($published), 'published' => $published];
    }

    /** @return array{publicationId:int,mockupSheetIds:array<int,int>} */
    private function catalogPublishPlan(int $userId, int $publicationId, ?array $publication = null): array
    {
        $publication ??= $this->publications->get($publicationId, $userId);
        if ((string)$publication['status'] === 'published') throw new RuntimeException('Esta obra ya está publicada.');

        $missing = [];
        if (trim((string)$publication['title']) === '') $missing[] = 'título';
        if (trim((string)($publication['short_description'] ?: $publication['description'])) === '') $missing[] = 'descripción';
        $sheet = $this->pdo->prepare('SELECT source_image_file,canonical_artwork_id FROM artwork_sheets WHERE id=? AND user_id=?');
        $sheet->execute([(int)$publication['artwork_sheet_id'], $userId]);
        $sheetRow = $sheet->fetch(PDO::FETCH_ASSOC) ?: [];
        if (trim((string)($sheetRow['source_image_file'] ?? '')) === '') $missing[] = 'imagen principal';
        if ($missing) throw new RuntimeException('Falta: ' . implode(', ', $missing) . '.');

        $this->publications->assertAssociatedSeriesIsPublished((int)$publication['artwork_sheet_id'], $userId);
        return [
            'publicationId' => $publicationId,
            'mockupSheetIds' => $this->favoriteMockupSheetIds($userId, (int)($sheetRow['canonical_artwork_id'] ?? 0)),
        ];
    }

    public function createNote(int $userId, string $sourceKey): array
    {
        $source = $this->resolveSource($userId, $sourceKey);
        $now = date('c');
        $payload = ['channels' => ['website_blog'], 'source' => $source, 'media' => [$source],
            'mockup_ids' => $source['type'] === 'mockup' ? [(int)$source['id']] : [],
            'channel_status' => ['website_blog' => 'draft']];
        $title = trim((string)$source['label']) . ' — Studio Note';
        $stmt = $this->pdo->prepare('INSERT INTO social_campaigns (user_id,campaign_type,title,objective,source_type,source_id,source_label,status,payload_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$userId, 'website_blog', $title, '', (string)$source['type'], (string)$source['id'], (string)$source['label'], 'draft', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now]);
        return $this->note($userId, (int)$this->pdo->lastInsertId());
    }

    public function addNoteMedia(int $userId, int $noteId, string $sourceKey): array
    {
        [$row, $payload] = $this->noteRow($userId, $noteId);
        $source = $this->resolveSource($userId, $sourceKey);
        $media = $this->normalizeNoteMedia($userId, $payload);
        foreach ($media as $item) if ((string)$item['key'] === (string)$source['key']) return $this->note($userId, $noteId);
        $media[] = $source;
        $payload['media'] = $media;
        $payload['source'] = $payload['source'] ?? $media[0];
        $this->saveNotePayload($userId, $noteId, $payload, (string)$row['status']);
        return $this->note($userId, $noteId);
    }

    /** @param array<int,string> $keys */
    public function reorderNoteMedia(int $userId, int $noteId, array $keys): array
    {
        [$row, $payload] = $this->noteRow($userId, $noteId);
        $media = $this->normalizeNoteMedia($userId, $payload);
        $lookup = [];
        foreach ($media as $item) $lookup[(string)$item['key']] = $item;
        $ordered = [];
        foreach ($keys as $key) if (isset($lookup[$key])) { $ordered[] = $lookup[$key]; unset($lookup[$key]); }
        foreach ($lookup as $item) $ordered[] = $item;
        $payload['media'] = $ordered;
        $this->saveNotePayload($userId, $noteId, $payload, (string)$row['status']);
        return $this->note($userId, $noteId);
    }

    public function removeNoteMedia(int $userId, int $noteId, string $sourceKey): array
    {
        [$row, $payload] = $this->noteRow($userId, $noteId);
        $media = $this->normalizeNoteMedia($userId, $payload);
        if (count($media) <= 1) throw new RuntimeException('La nota necesita conservar al menos una imagen.');
        $sourceKeyStored = (string)(($payload['source']['key'] ?? ''));
        $payload['media'] = array_values(array_filter($media, static fn(array $item): bool => (string)$item['key'] !== $sourceKey));
        if (count($payload['media']) === count($media)) throw new RuntimeException('La imagen ya no forma parte de esta nota.');
        if ($sourceKey === $sourceKeyStored) $payload['source'] = $payload['media'][0];
        $this->saveNotePayload($userId, $noteId, $payload, (string)$row['status']);
        return $this->note($userId, $noteId);
    }

    public function saveNote(int $userId, int $noteId, string $title, string $objective): array
    {
        $title = trim($title);
        if ($title === '') throw new RuntimeException('El título es obligatorio.');
        [$row, $payload] = $this->noteRow($userId, $noteId);
        $normalized = StudioNoteMediaService::normalize($userId, $noteId, $objective, $payload, $this->sources($userId));
        $payload = $normalized['payload'];
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $sourceLabel = (string)($source['label'] ?? $row['source_label'] ?? '');
        if ((string)($source['type'] ?? '') === 'studio_note') $sourceLabel = 'Studio Essay';
        $stmt = $this->pdo->prepare('UPDATE social_campaigns SET title=?,objective=?,source_type=?,source_id=?,source_label=?,payload_json=?,updated_at=? WHERE id=? AND user_id=?');
        $stmt->execute([
            $title,
            $normalized['html'],
            (string)($source['type'] ?? $row['source_type'] ?? ''),
            (string)($source['id'] ?? $row['source_id'] ?? ''),
            $sourceLabel,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            date('c'),
            $noteId,
            $userId,
        ]);
        return $this->note($userId, $noteId);
    }

    public function noteAction(int $userId, int $noteId, string $action): ?array
    {
        [$row, $payload] = $this->noteRow($userId, $noteId);
        if ($action === 'delete') {
            $this->pdo->prepare('DELETE FROM social_campaigns WHERE id=? AND user_id=?')->execute([$noteId, $userId]);
            return null;
        }
        if ($action === 'publish') {
            if ((string)$row['status'] === 'published') throw new RuntimeException('Esta Nota de estudio ya está publicada.');
            if (trim((string)$row['title']) === '') throw new RuntimeException('La nota necesita un título.');
            if (trim(strip_tags((string)$row['objective'])) === '') throw new RuntimeException('La nota necesita contenido antes de publicarse.');
            $status = 'published';
        } elseif ($action === 'unpublish') {
            $status = 'draft';
        } else {
            throw new RuntimeException('Acción de nota desconocida.');
        }
        $payload['channel_status']['website_blog'] = $status;
        $this->saveNotePayload($userId, $noteId, $payload, $status);
        return $this->note($userId, $noteId);
    }

    private function source(string $type, int $id, string $file, string $label, array $extra = []): array
    {
        return array_merge(['key' => $type . ':' . $id, 'type' => $type, 'id' => $id, 'file' => basename($file), 'label' => $label], $extra);
    }

    /** @return array<string,mixed> */
    private function mockupEditorialGuide(array $row): array
    {
        $generated = json_decode((string)($row['editorial_generated_json'] ?? ''), true);
        $neutral = is_array($generated)
            ? (array)($generated['mockup_analysis_v2']['neutral'] ?? [])
            : [];
        $scene = (array)($neutral['scene'] ?? []);
        $keywords = $neutral['keywords'] ?? null;
        if (!is_array($keywords)) {
            $keywords = preg_split('/\s*,\s*/u', trim((string)($row['editorial_keywords'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $atmosphere = is_array($scene['atmosphere'] ?? null) ? $scene['atmosphere'] : [];

        return [
            'title' => trim((string)($row['editorial_title'] ?? '')),
            'description' => trim((string)($row['editorial_description'] ?? ($neutral['contextual_description'] ?? ''))),
            'caption' => trim((string)($row['editorial_caption'] ?? ($neutral['caption'] ?? ''))),
            'altText' => trim((string)($row['editorial_alt_text'] ?? ($neutral['alt_text'] ?? ''))),
            'spaceType' => trim((string)($scene['space_type'] ?? '')),
            'architecture' => trim((string)($scene['architecture'] ?? '')),
            'lighting' => trim((string)($scene['lighting'] ?? '')),
            'artworkRelationship' => trim((string)($scene['artwork_space_relationship'] ?? '')),
            'atmosphere' => array_values(array_filter(array_map('strval', $atmosphere), static fn(string $value): bool => trim($value) !== '')),
            'keywords' => array_values(array_filter(array_map('strval', $keywords), static fn(string $value): bool => trim($value) !== '')),
        ];
    }

    private function resolveSource(int $userId, string $key): array
    {
        if (!isset($this->sourceCache[$userId])) {
            $this->sourceCache[$userId] = [];
            foreach ($this->sources($userId) as $source) $this->sourceCache[$userId][(string)$source['key']] = $source;
        }
        if (!isset($this->sourceCache[$userId][$key]) && preg_match('/^artwork:(\d+)$/', $key, $match) === 1) {
            $stmt = $this->pdo->prepare("SELECT ag.canonical_artwork_id
                FROM artworks a
                INNER JOIN artwork_groups ag ON ag.id=a.artwork_group_id AND ag.user_id=a.user_id AND ag.status='active'
                WHERE a.user_id=? AND a.id=? LIMIT 1");
            $stmt->execute([$userId, (int)$match[1]]);
            $canonicalKey = 'artwork:' . (int)($stmt->fetchColumn() ?: 0);
            if (isset($this->sourceCache[$userId][$canonicalKey])) {
                return $this->sourceCache[$userId][$canonicalKey];
            }
        }
        if (!isset($this->sourceCache[$userId][$key])) throw new RuntimeException('La imagen de origen ya no está disponible.');
        return $this->sourceCache[$userId][$key];
    }

    private function catalogEntry(int $userId, int $publicationId): array
    {
        foreach ($this->catalogEntries($userId, true) as $entry) if ((int)$entry['id'] === $publicationId) return $entry;
        throw new RuntimeException('La ficha de catálogo no está disponible.');
    }

    private function note(int $userId, int $noteId): array
    {
        foreach ($this->notes($userId, true) as $note) if ((int)$note['id'] === $noteId) return $note;
        throw new RuntimeException('La nota no está disponible.');
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function noteRow(int $userId, int $noteId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM social_campaigns WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$noteId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Nota no encontrada.');
        $payload = json_decode((string)$row['payload_json'], true);
        if (!is_array($payload) || !in_array('website_blog', array_map('strval', (array)($payload['channels'] ?? [])), true)) throw new RuntimeException('La publicación no es una Nota de estudio.');
        return [$row, $payload];
    }

    private function saveNotePayload(int $userId, int $noteId, array $payload, string $status): void
    {
        $payload['mockup_ids'] = array_values(array_map(static fn(array $item): int => (int)$item['id'], array_filter((array)($payload['media'] ?? []), static fn(array $item): bool => ($item['type'] ?? '') === 'mockup')));
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $this->pdo->prepare('UPDATE social_campaigns SET status=?,source_type=?,source_id=?,source_label=?,payload_json=?,updated_at=? WHERE id=? AND user_id=?')->execute([
            $status,
            (string)($source['type'] ?? ''),
            (string)($source['id'] ?? ''),
            (string)($source['label'] ?? ''),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            date('c'),
            $noteId,
            $userId,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeNoteMedia(int $userId, array $payload): array
    {
        $media = [];
        foreach ((array)($payload['media'] ?? []) as $item) {
            if (!is_array($item)) continue;
            if ((string)($item['type'] ?? '') === 'studio_note') {
                $file = basename((string)($item['file'] ?? ''));
                if ($file !== '' && str_starts_with($file, 'studio-note-' . $userId . '-')) {
                    $media[] = [
                        'key' => (string)($item['key'] ?? ('studio_note:' . ($item['id'] ?? $file))),
                        'type' => 'studio_note',
                        'id' => (string)($item['id'] ?? ''),
                        'file' => $file,
                        'label' => trim((string)($item['label'] ?? '')) ?: 'Studio Note image',
                    ];
                }
                continue;
            }
            $key = (string)($item['key'] ?? (($item['type'] ?? '') . ':' . ($item['id'] ?? '')));
            try { $media[] = $this->resolveSource($userId, $key); } catch (Throwable) { /* Source was removed; omit it. */ }
        }
        if (!$media) {
            foreach ((array)($payload['mockup_ids'] ?? []) as $id) {
                try { $media[] = $this->resolveSource($userId, 'mockup:' . (int)$id); } catch (Throwable) { }
            }
        }
        $unique = [];
        foreach ($media as $item) $unique[(string)$item['key']] = $item;
        return array_values($unique);
    }

    /** @return array<int,int> */
    private function favoriteMockupSheetIds(int $userId, int $artworkId): array
    {
        $ids = MockupFavorites::idsForUser($userId);
        if (!$ids || $artworkId <= 0) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT m.id mockup_id,MAX(ms.id) mockup_sheet_id
            FROM mockups m JOIN mockup_sheets ms ON ms.user_id=m.user_id AND ms.mockup_file=m.mockup_file
            WHERE m.user_id=? AND m.source_artwork_id=? AND m.id IN ($marks)
            GROUP BY m.id");
        $stmt->execute(array_merge([$userId, $artworkId], $ids));
        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $found[(int)$row['mockup_id']] = (int)$row['mockup_sheet_id'];
        $ordered = [];
        foreach ($ids as $id) if (isset($found[$id])) $ordered[] = $found[$id];
        return $ordered;
    }
}
