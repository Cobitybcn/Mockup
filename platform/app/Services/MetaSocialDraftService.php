<?php
declare(strict_types=1);

final class MetaSocialDraftService
{
    private const CHANNELS = ['facebook', 'instagram'];

    public function __construct(private readonly PDO $pdo) {}

    public function create(
        int $mockupId,
        array $user,
        string $channel,
        string $destinationUrl = '',
        string $purpose = 'artist',
        string $locale = 'en'
    ): int {
        $userId = (int)($user['id'] ?? 0);
        $channel = $this->channel($channel);
        $purpose = $this->purpose($purpose, $user);
        $locale = $locale === 'es' ? 'es' : 'en';
        $this->assertDestination($destinationUrl);

        $resolved = $this->resolveMockupContent($userId, $mockupId, $channel, $locale);
        $copy = $this->buildCopyFields($resolved['variant'], $resolved['neutral'], $channel, (string)($resolved['row']['sheet_alt_text'] ?? ''));

        $payload = [
            'schema_version' => 'meta-draft.v1',
            'mockup_id' => $mockupId,
            'channel' => $channel,
            'purpose' => $purpose,
            'locale' => $locale,
            'source' => $resolved['variant'],
        ];
        $now = date('c');
        $mediaToken = bin2hex(random_bytes(32));
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->pdo->prepare(
                'INSERT INTO social_channel_drafts
                (user_id,mockup_id,video_export_id,source_type,channel,purpose,title,description,hashtags,alt_text,destination_url,status,payload_json,media_token,media_expires_at,variant_file,variant_width,variant_height,crop_x,crop_y,crop_zoom,publish_attempt_id,external_id,external_url,error,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $userId,
                $mockupId,
                null,
                'mockup',
                $channel,
                $purpose,
                mb_substr($copy['title'], 0, 255),
                mb_substr($copy['description'], 0, 5000),
                json_encode($copy['hashtags'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                mb_substr($copy['altText'], 0, 1000),
                trim($destinationUrl),
                'draft',
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $mediaToken,
                date('c', time() + 172800),
                '',
                0,
                0,
                0.5,
                0.5,
                1,
                '',
                '',
                '',
                '',
                $now,
                $now,
            ]);
            $draftId = (int)$this->pdo->lastInsertId();
            $this->saveCrop($draftId, $userId, 0.5, 0.5, 1.0);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $draftId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Same publication flow as create(), sourced from a finished video export
     * instead of a mockup. There is no editorial copy pipeline for video yet,
     * so the caption/hashtags/alt text are reused from a reviewed mockup that
     * belongs to the same artwork (or, failing that, the same series) as the
     * video — per the artist's direction, not generated fresh for video.
     */
    public function createFromVideoExport(
        int $videoExportId,
        array $user,
        string $channel,
        string $destinationUrl = '',
        string $purpose = 'artist',
        string $locale = 'en'
    ): int {
        $userId = (int)($user['id'] ?? 0);
        $channel = $this->channel($channel);
        $purpose = $this->purpose($purpose, $user);
        $locale = $locale === 'es' ? 'es' : 'en';
        $this->assertDestination($destinationUrl);

        $export = $this->videoExport($videoExportId, $userId);
        if ($export === null) {
            throw new RuntimeException('Video no encontrado o todavía no terminó de procesarse.');
        }
        $candidates = $this->candidateMockupIdsForVideo(
            $userId,
            (int)($export['artwork_id'] ?? 0),
            (int)($export['series_id'] ?? 0)
        );
        if (!$candidates) {
            throw new RuntimeException('Este video no está vinculado a ninguna obra con mockups todavía.');
        }

        $resolved = null;
        foreach ($candidates as $candidateId) {
            try {
                $resolved = $this->resolveMockupContent($userId, $candidateId, $channel, $locale);
                break;
            } catch (Throwable) {
                continue;
            }
        }
        if ($resolved === null) {
            throw new RuntimeException('Todavía no hay contenido editorial revisado para esta obra. Revisá primero el contenido de un mockup de esta obra.');
        }
        $copy = $this->buildCopyFields($resolved['variant'], $resolved['neutral'], $channel, '');

        $payload = [
            'schema_version' => 'meta-draft.v1',
            'video_export_id' => $videoExportId,
            'channel' => $channel,
            'purpose' => $purpose,
            'locale' => $locale,
            'source' => $resolved['variant'],
        ];
        $now = date('c');
        $mediaToken = bin2hex(random_bytes(32));

        $this->pdo->prepare(
            'INSERT INTO social_channel_drafts
            (user_id,mockup_id,video_export_id,source_type,channel,purpose,title,description,hashtags,alt_text,destination_url,status,payload_json,media_token,media_expires_at,variant_file,variant_width,variant_height,crop_x,crop_y,crop_zoom,publish_attempt_id,external_id,external_url,error,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $userId,
            null,
            $videoExportId,
            'video',
            $channel,
            $purpose,
            mb_substr($copy['title'], 0, 255),
            mb_substr($copy['description'], 0, 5000),
            json_encode($copy['hashtags'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            mb_substr($copy['altText'], 0, 1000),
            trim($destinationUrl),
            'draft',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $mediaToken,
            date('c', time() + 172800),
            '',
            0,
            0,
            0.5,
            0.5,
            1,
            '',
            '',
            '',
            '',
            $now,
            $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function draft(int $draftId, int $userId): array
    {
        // video_exports only exists once the Video Lab schema has been
        // migrated (lazily, on first use of that feature) — an install that
        // has never touched it must still be able to read mockup drafts.
        $sql = $this->tableExists('video_exports')
            ? 'SELECT d.*, m.mockup_file, ve.output_path AS video_output_path
               FROM social_channel_drafts d
               LEFT JOIN mockups m ON m.id=d.mockup_id
               LEFT JOIN video_exports ve ON ve.id=d.video_export_id
               WHERE d.id=? AND d.user_id=? LIMIT 1'
            : "SELECT d.*, m.mockup_file, '' AS video_output_path
               FROM social_channel_drafts d
               LEFT JOIN mockups m ON m.id=d.mockup_id
               WHERE d.id=? AND d.user_id=? LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$draftId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Meta draft not found.');
        }
        return $row;
    }

    /** @return array{row:array,variant:array,neutral:array} */
    private function resolveMockupContent(int $userId, int $mockupId, string $channel, string $locale): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.mockup_file,s.generated_json,s.alt_text sheet_alt_text
             FROM mockups m
             LEFT JOIN mockup_sheets s ON s.user_id=m.user_id AND s.mockup_file=m.mockup_file
             WHERE m.id=? AND m.user_id=? ORDER BY s.id DESC LIMIT 1'
        );
        $stmt->execute([$mockupId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Mockup not found.');
        }

        $reviewed = (new MockupSocialContentService($this->pdo))->forMockup($userId, $mockupId, $locale);
        $reviewedContent = (array)($reviewed['content'] ?? []);
        $variant = (array)($reviewedContent['social'][$channel] ?? []);
        $neutral = $reviewedContent;
        if (!$variant) {
            $generated = json_decode((string)($row['generated_json'] ?? ''), true);
            $v2 = (array)($generated['mockup_analysis_v2'] ?? []);
            if ((string)($v2['analysis_language'] ?? '') !== 'es') {
                throw new RuntimeException('Generá y revisá primero el contenido bilingüe del mockup.');
            }
            $variant = (array)($v2['channels'][$channel] ?? []);
            $neutral = (array)($v2['neutral'] ?? []);
        }
        if (!$variant) {
            throw new RuntimeException('El contenido del mockup no contiene material para este canal de Meta.');
        }
        return ['row' => $row, 'variant' => $variant, 'neutral' => $neutral];
    }

    /** @return array{title:string,description:string,hashtags:array,altText:string} */
    private function buildCopyFields(array $variant, array $neutral, string $channel, string $fallbackAltText): array
    {
        $title = trim((string)($variant['headline'] ?? $variant['hook'] ?? ''));
        $copyParts = $channel === 'facebook'
            ? [$variant['post_text'] ?? '', $variant['link_description'] ?? '', $variant['cta'] ?? '']
            : [$variant['caption'] ?? '', $variant['cta'] ?? ''];
        $description = implode("\n\n", array_values(array_filter(array_map(
            static fn (mixed $part): string => trim((string)$part),
            $copyParts
        ))));
        $hashtags = $this->normalizeHashtags(MockupSocialContentService::list($variant['hashtags'] ?? []));
        $altText = trim((string)($neutral['alt_text'] ?? $fallbackAltText));
        if ($description === '') {
            throw new RuntimeException('The Meta analysis did not produce publication copy.');
        }
        return ['title' => $title, 'description' => $description, 'hashtags' => $hashtags, 'altText' => $altText];
    }

    private function tableExists(string $table): bool
    {
        if (strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql') {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function videoExport(int $exportId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.id,e.output_path,e.status,e.timeline_snapshot_json,p.artwork_id,p.series_id
             FROM video_exports e
             INNER JOIN video_projects p ON p.id=e.video_project_id AND p.user_id=e.user_id
             WHERE e.id=? AND e.user_id=? AND e.status='succeeded' LIMIT 1"
        );
        $stmt->execute([$exportId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        // A finished video can later be assigned to a (possibly canonical/merged)
        // artwork via the Video Lab's "assign final artwork" step, which only
        // updates the export's snapshot JSON, not video_projects.artwork_id.
        // Mirror VideoStudioRepository::finalVideos() and prefer that snapshot.
        $snapshot = json_decode((string)($row['timeline_snapshot_json'] ?? ''), true);
        $snapshotArtworkId = is_array($snapshot) ? (int)($snapshot['artworkId'] ?? 0) : 0;
        if ($snapshotArtworkId > 0) {
            $row['artwork_id'] = $snapshotArtworkId;
        }
        return $row;
    }

    /** @return list<int> */
    private function candidateMockupIdsForVideo(int $userId, int $artworkId, int $seriesId): array
    {
        if ($artworkId > 0) {
            $stmt = $this->pdo->prepare('SELECT id FROM mockups WHERE user_id=? AND source_artwork_id=? ORDER BY id DESC');
            $stmt->execute([$userId, $artworkId]);
            $ids = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
            if ($ids) {
                return $ids;
            }
        }
        if ($seriesId > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT m.id FROM mockups m
                 INNER JOIN artworks a ON a.id=m.source_artwork_id AND a.user_id=m.user_id
                 WHERE m.user_id=? AND a.series_id=? ORDER BY m.id DESC'
            );
            $stmt->execute([$userId, $seriesId]);
            return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
        }
        return [];
    }

    public function updateContent(
        int $draftId,
        int $userId,
        string $title,
        string $description,
        array|string $hashtags,
        string $altText,
        string $destinationUrl
    ): array {
        $draft = $this->draft($draftId, $userId);
        if (in_array((string)$draft['status'], ['publishing', 'published'], true)) {
            throw new RuntimeException('Meta content cannot be edited while it is publishing or after publication.');
        }
        $description = trim($description);
        if ($description === '') {
            throw new InvalidArgumentException('Publication copy is required.');
        }
        $this->assertDestination($destinationUrl);
        $normalizedHashtags = is_array($hashtags)
            ? $this->normalizeHashtags($hashtags)
            : $this->normalizeHashtags(preg_split('/[\s,]+/', $hashtags) ?: []);
        if ((string)$draft['channel'] === 'instagram' && count($normalizedHashtags) > 30) {
            throw new InvalidArgumentException('Instagram accepts at most 30 hashtags.');
        }

        $this->pdo->prepare(
            "UPDATE social_channel_drafts SET title=?,description=?,hashtags=?,alt_text=?,destination_url=?,
             status=CASE WHEN status='published' THEN status ELSE 'draft' END,error='',updated_at=? WHERE id=? AND user_id=?"
        )->execute([
            mb_substr(trim($title), 0, 255),
            mb_substr($description, 0, 5000),
            json_encode($normalizedHashtags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            mb_substr(trim($altText), 0, 1000),
            trim($destinationUrl),
            date('c'),
            $draftId,
            $userId,
        ]);
        return $this->draft($draftId, $userId);
    }

    public function saveCrop(int $draftId, int $userId, float $x, float $y, float $zoom): array
    {
        $draft = $this->draft($draftId, $userId);
        if ((string)($draft['source_type'] ?? 'mockup') !== 'mockup') {
            throw new RuntimeException('Video publications use the exported video file directly; there is no crop to save.');
        }
        if (in_array((string)$draft['status'], ['publishing', 'published'], true)) {
            throw new RuntimeException('Meta media cannot be replaced while it is publishing or after publication.');
        }

        $x = max(0, min(1, $x));
        $y = max(0, min(1, $y));
        $zoom = max(1, min(3, $zoom));
        $sourceFile = basename((string)$draft['mockup_file']);
        $sourcePath = RESULTS_DIR . DIRECTORY_SEPARATOR . $sourceFile;
        if (!is_file($sourcePath) && StorageService::isGcsActive()) {
            StorageService::downloadFile('results/' . $sourceFile, $sourcePath);
        }
        $info = @getimagesize($sourcePath);
        if (!$info) {
            throw new RuntimeException('Mockup image is unavailable.');
        }
        [$sourceWidth, $sourceHeight, $type] = $info;
        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sourcePath),
            default => false,
        };
        if (!$source) {
            throw new RuntimeException('Unsupported mockup image.');
        }

        $targetWidth = 1080;
        $targetHeight = 1350;
        $scale = max($targetWidth / $sourceWidth, $targetHeight / $sourceHeight) * $zoom;
        $cropWidth = $targetWidth / $scale;
        $cropHeight = $targetHeight / $scale;
        $left = ($sourceWidth - $cropWidth) * $x;
        $top = ($sourceHeight - $cropHeight) * $y;
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            (int)round($left),
            (int)round($top),
            $targetWidth,
            $targetHeight,
            (int)round($cropWidth),
            (int)round($cropHeight)
        );

        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meta_drafts';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($source);
            imagedestroy($canvas);
            throw new RuntimeException('Cannot create the Meta media directory.');
        }
        $file = 'meta-crop-' . $draftId . '-' . substr(hash('sha256', (string)$draft['media_token']), 0, 12) . '.jpg';
        $path = $directory . DIRECTORY_SEPARATOR . $file;
        $saved = imagejpeg($canvas, $path, 92);
        imagedestroy($source);
        imagedestroy($canvas);
        if (!$saved) {
            throw new RuntimeException('Cannot save Meta media.');
        }
        if (StorageService::isGcsActive() && !StorageService::uploadFile('meta-drafts/' . $file, $path)) {
            throw new RuntimeException('Cannot persist Meta media in Cloud Storage.');
        }

        $this->pdo->prepare(
            "UPDATE social_channel_drafts SET crop_x=?,crop_y=?,crop_zoom=?,variant_file=?,variant_width=?,variant_height=?,
             status=CASE WHEN status='published' THEN status ELSE 'draft' END,error='',updated_at=? WHERE id=? AND user_id=?"
        )->execute([$x, $y, $zoom, $file, $targetWidth, $targetHeight, date('c'), $draftId, $userId]);
        return $this->draft($draftId, $userId);
    }

    public function refreshMediaAccess(int $draftId, int $userId, int $ttlSeconds = 3600): array
    {
        $this->draft($draftId, $userId);
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('c', time() + max(900, min(86400, $ttlSeconds)));
        $this->pdo->prepare('UPDATE social_channel_drafts SET media_token=?,media_expires_at=?,updated_at=? WHERE id=? AND user_id=?')
            ->execute([$token, $expiresAt, date('c'), $draftId, $userId]);
        return $this->draft($draftId, $userId);
    }

    public function claimForPublishing(int $draftId, int $userId, string $attemptId): bool
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/', $attemptId)) {
            throw new InvalidArgumentException('Invalid Meta publication attempt.');
        }
        $stmt = $this->pdo->prepare(
            "UPDATE social_channel_drafts SET status='publishing',publish_attempt_id=?,error='',updated_at=?
             WHERE id=? AND user_id=? AND (
                status IN ('draft','failed') OR (status='publishing' AND updated_at<?)
             )"
        );
        $stmt->execute([$attemptId, date('c'), $draftId, $userId, date('c', time() - 900)]);
        return $stmt->rowCount() === 1;
    }

    public function markPublished(
        int $draftId,
        int $userId,
        string $attemptId,
        string $externalId,
        string $externalUrl,
        array $response
    ): void {
        if ($externalId === '') {
            throw new InvalidArgumentException('Meta did not return a publication ID.');
        }
        $draft = $this->draft($draftId, $userId);
        $payload = json_decode((string)$draft['payload_json'], true);
        $payload = is_array($payload) ? $payload : [];
        $payload['publication_response'] = $response;
        $stmt = $this->pdo->prepare(
            "UPDATE social_channel_drafts SET status='published',external_id=?,external_url=?,error='',payload_json=?,updated_at=?
             WHERE id=? AND user_id=? AND publish_attempt_id=?"
        );
        $stmt->execute([
            mb_substr($externalId, 0, 255),
            $externalUrl,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            date('c'),
            $draftId,
            $userId,
            $attemptId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The Meta publication attempt no longer owns this draft.');
        }
    }

    public function markFailed(int $draftId, int $userId, string $attemptId, string $error): void
    {
        $this->pdo->prepare(
            "UPDATE social_channel_drafts SET status='failed',error=?,updated_at=?
             WHERE id=? AND user_id=? AND publish_attempt_id=? AND status='publishing'"
        )->execute([mb_substr(trim($error), 0, 1500), date('c'), $draftId, $userId, $attemptId]);
    }

    public function markNeedsVerification(int $draftId, int $userId, string $attemptId, string $error): void
    {
        $this->pdo->prepare(
            "UPDATE social_channel_drafts SET status='needs_verification',error=?,updated_at=?
             WHERE id=? AND user_id=? AND publish_attempt_id=? AND status='publishing'"
        )->execute([mb_substr(trim($error), 0, 1500), date('c'), $draftId, $userId, $attemptId]);
    }

    public function resolveVerification(
        int $draftId,
        int $userId,
        string $decision,
        string $externalId = '',
        string $externalUrl = ''
    ): void {
        $draft = $this->draft($draftId, $userId);
        if ((string)$draft['status'] !== 'needs_verification') {
            throw new RuntimeException('This Meta draft does not require manual verification.');
        }
        if ($decision === 'retry') {
            $this->pdo->prepare(
                "UPDATE social_channel_drafts SET status='failed',publish_attempt_id='',
                 error='Manually verified: no external post was created.',updated_at=? WHERE id=? AND user_id=?"
            )->execute([date('c'), $draftId, $userId]);
            return;
        }
        if ($decision !== 'published' || trim($externalId) === '') {
            throw new InvalidArgumentException('Enter the external Meta publication ID.');
        }
        if ($externalUrl !== '' && !$this->isHttpsUrl($externalUrl)) {
            throw new InvalidArgumentException('The external publication URL must use HTTPS.');
        }
        $this->pdo->prepare(
            "UPDATE social_channel_drafts SET status='published',external_id=?,external_url=?,error='',updated_at=?
             WHERE id=? AND user_id=?"
        )->execute([mb_substr(trim($externalId), 0, 255), trim($externalUrl), date('c'), $draftId, $userId]);
    }

    public function readiness(array $draft): array
    {
        $blockers = [];
        if ((string)($draft['status'] ?? '') === 'needs_verification') {
            $blockers[] = 'The previous API response was inconclusive; verify the real account before any retry.';
        }
        if ((string)($draft['status'] ?? '') === 'publishing') {
            $blockers[] = 'A publication attempt is still in progress.';
        }
        if (!in_array((string)($draft['channel'] ?? ''), self::CHANNELS, true)) {
            $blockers[] = 'Unsupported Meta channel.';
        }
        if (trim((string)($draft['description'] ?? '')) === '') {
            $blockers[] = 'Publication copy is missing.';
        }
        $isVideo = (string)($draft['source_type'] ?? 'mockup') === 'video';
        if ($isVideo) {
            if (trim((string)($draft['video_output_path'] ?? '')) === '') {
                $blockers[] = 'The exported video file is missing.';
            }
        } elseif (trim((string)($draft['variant_file'] ?? '')) === '') {
            $blockers[] = 'The reviewed 4:5 image is missing.';
        }
        $destination = trim((string)($draft['destination_url'] ?? ''));
        if ($destination !== '' && (!$this->isHttpsUrl($destination))) {
            $blockers[] = 'The destination must use public HTTPS.';
        }
        return $blockers;
    }

    private function channel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('Unsupported Meta channel.');
        }
        return $channel;
    }

    private function purpose(string $purpose, array $user): string
    {
        $purpose = strtolower(trim($purpose));
        if (!in_array($purpose, ['artist', 'platform'], true)) {
            throw new InvalidArgumentException('Invalid Meta identity.');
        }
        if ($purpose === 'platform' && !Auth::isAdmin($user)) {
            throw new RuntimeException('The Artwork Mockups Meta identity is administrator-only.');
        }
        return $purpose;
    }

    private function assertDestination(string $destinationUrl): void
    {
        if (trim($destinationUrl) !== '' && !$this->isHttpsUrl($destinationUrl)) {
            throw new InvalidArgumentException('Meta destinations must use a public HTTPS URL.');
        }
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    private function normalizeHashtags(array $hashtags): array
    {
        $normalized = [];
        foreach ($hashtags as $hashtag) {
            $value = trim((string)$hashtag);
            if ($value === '') {
                continue;
            }
            $value = '#' . ltrim($value, '#');
            if (preg_match('/^#[\p{L}\p{N}_]+$/u', $value) !== 1) {
                continue;
            }
            $normalized[$value] = true;
        }
        return array_slice(array_keys($normalized), 0, 30);
    }
}
