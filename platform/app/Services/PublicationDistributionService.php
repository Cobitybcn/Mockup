<?php
declare(strict_types=1);

/**
 * Distribution steps of the Publication section — per-destination adapters that
 * publish reading ONLY the frozen product (publication_products).
 *
 * The product is an ENGINE, not a step: ensureCurrentProduct() regenerates the
 * projection silently whenever the sources changed, so every send always reads
 * an up-to-date frozen product (still read-only, still fingerprint-traced).
 *
 * Social series (Instagram/Facebook) follow the 3×3 criterion with automatic
 * cadence: one confirmed act publishes part 1 now and schedules the remaining
 * parts spaced by the user's gap (default 12 h — covers time zones), via Cloud
 * Tasks hitting publication_distribution_worker.php. Retry never duplicates:
 * published parts/pins are skipped; an error never survives a successful retry
 * (PUBLICACION_DISENO IV.16).
 */
final class PublicationDistributionService
{
    public const DESTINATIONS = ['pinterest', 'instagram', 'instagram_video', 'facebook', 'facebook_video', 'tiktok', 'tiktok_carousel', 'x', 'x_video', 'saatchi'];
    public const DEFAULT_SERIES_GAP_HOURS = 12;
    private const LIVE_DESTINATIONS = ['pinterest', 'instagram', 'facebook', 'tiktok', 'x'];
    private const SERIES_DESTINATIONS = ['instagram', 'facebook', 'x'];
    /** TikTok keeps both media on the same destination rows: video at part 0, carousel at part 1. */
    private const TIKTOK_VIDEO_PART = 0;
    private const TIKTOK_CAROUSEL_PART = 1;
    /**
     * Meta's video is a fourth act, not a fourth part of the series
     * (PUBLICACION_DISENO art. 16): it lives on the network's own rows, far
     * from the series parts (1, 2, 3), so sending the reel never disturbs a
     * series already published and the series never counts it as one of its own.
     */
    private const META_VIDEO_PART = 10;
    /** X's post limit; a link always counts as 23 of it. */
    private const X_LIMIT = 280;
    private const META_VIDEO_DESTINATIONS = ['instagram_video' => 'instagram', 'facebook_video' => 'facebook', 'x_video' => 'x'];

    private PublicationProductService $products;
    private PublicationService $publications;

    /** @param array<string,callable(array):array>|null $transports */
    public function __construct(
        private readonly PDO $pdo,
        ?PublicationProductService $products = null,
        ?PublicationService $publications = null,
        private readonly ?array $transports = null,
    ) {
        $this->publications = $publications ?? new PublicationService($pdo);
        $this->products = $products ?? new PublicationProductService($pdo, $this->publications);
    }

    /** Default send language: the adaptation (international) locale when the product has one. */
    public static function defaultLocale(array $productPayload): string
    {
        $adaptations = (array)($productPayload['locales']['adaptations'] ?? []);
        return (string)($adaptations[0] ?? ($productPayload['locales']['working'] ?? 'es'));
    }

    /**
     * The product engine: finds the frozen product and regenerates it silently
     * when missing or when the live sources changed. Requires the page to be
     * published (throws otherwise, pointing at the Website step).
     *
     * @return array{0:array,1:string} [payload, fingerprint]
     */
    public function ensureCurrentProduct(int $publicationId, int $userId): array
    {
        $product = $this->products->find($publicationId, $userId);
        if ($product === null || $this->products->isStale($product, $userId)) {
            $product = $this->products->generate($publicationId, $userId);
        }
        return [$product['payload'], (string)$product['source_fingerprint']];
    }

    /** Series gap in hours for this user — set once, never per artwork. */
    public function seriesGapHours(int $userId): int
    {
        try {
            $sql = $this->isMysql()
                ? 'SELECT value FROM app_settings WHERE `key` = ? LIMIT 1'
                : 'SELECT value FROM app_settings WHERE key = ? LIMIT 1';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->gapSettingKey($userId)]);
            $value = (int)($stmt->fetchColumn() ?: 0);
        } catch (Throwable) {
            $value = 0;
        }
        return $value > 0 ? min(168, $value) : self::DEFAULT_SERIES_GAP_HOURS;
    }

    public function setSeriesGapHours(int $userId, int $hours): void
    {
        $hours = max(1, min(168, $hours));
        $now = date('c');
        if ($this->isMysql()) {
            $this->pdo->prepare('INSERT INTO app_settings (`key`,`value`,`updated_at`) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),`updated_at`=VALUES(`updated_at`)')
                ->execute([$this->gapSettingKey($userId), (string)$hours, $now]);
        } else {
            $this->pdo->prepare('INSERT INTO app_settings (key,value,updated_at) VALUES (?,?,?)
                ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at')
                ->execute([$this->gapSettingKey($userId), (string)$hours, $now]);
        }
    }

    /**
     * Per-destination card state — DB reads only, never network.
     * Series destinations add a `parts` list and summarize honestly.
     */
    public function states(int $publicationId, int $userId): array
    {
        $rows = [];
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM publication_distributions WHERE publication_id=? AND user_id=? ORDER BY destination, part');
            $stmt->execute([$publicationId, $userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[(string)$row['destination']][(int)$row['part']] = $row;
            }
        } catch (Throwable) {
            $rows = [];
        }
        $saatchiUrl = '';
        try {
            $urlStmt = $this->pdo->prepare('SELECT saatchi_url FROM publications WHERE id=? AND user_id=? LIMIT 1');
            $urlStmt->execute([$publicationId, $userId]);
            $saatchiUrl = trim((string)($urlStmt->fetchColumn() ?: ''));
        } catch (Throwable) {
            $saatchiUrl = '';
        }

        $states = [];
        foreach (self::DESTINATIONS as $destination) {
            $destinationRows = $rows[$destination] ?? [];
            if (in_array($destination, self::SERIES_DESTINATIONS, true)) {
                $parts = [];
                $publishedCount = 0;
                $failedCount = 0;
                $scheduledCount = 0;
                $firstUrl = '';
                $lastError = '';
                $lastAttempt = '';
                foreach ($destinationRows as $part => $row) {
                    // El reel comparte las filas de la red pero no es parte de
                    // la serie: contarlo aquí haría que un video fallido dejara
                    // la serie entera en FALLÓ, y que enviarlo la mostrara
                    // incompleta (art. 16).
                    if ((int)$part === self::META_VIDEO_PART) continue;
                    $partStatus = (string)$row['status'];
                    if ($partStatus === 'published') $publishedCount++;
                    if ($partStatus === 'failed') { $failedCount++; $lastError = (string)$row['error']; }
                    if (in_array($partStatus, ['scheduled', 'publishing'], true)) $scheduledCount++;
                    if ($firstUrl === '' && trim((string)$row['external_url']) !== '') $firstUrl = (string)$row['external_url'];
                    if ((string)$row['attempted_at'] !== '') $lastAttempt = (string)$row['attempted_at'];
                    $parts[] = [
                        'part' => (int)$part,
                        'status' => $partStatus,
                        'scheduled_at' => (string)$row['scheduled_at'],
                        'external_url' => (string)$row['external_url'],
                        'error' => (string)$row['error'],
                    ];
                }
                $total = count($parts);
                $summary = '';
                if ($total > 0) {
                    $summary = match (true) {
                        $publishedCount === $total => 'published',
                        $failedCount > 0 => 'failed',
                        $scheduledCount > 0 => 'scheduled',
                        default => 'partial',
                    };
                }
                $states[$destination] = [
                    'status' => $summary,
                    'locale' => (string)($destinationRows[array_key_first($destinationRows ?: [0 => null])]['locale'] ?? ''),
                    'external_id' => '',
                    'external_url' => $firstUrl,
                    'error' => $lastError,
                    'attempted_at' => $lastAttempt,
                    'connection' => $this->connectionState($destination, $userId),
                    'sent_fingerprint' => (string)($destinationRows[array_key_first($destinationRows ?: [0 => null])]['product_fingerprint'] ?? ''),
                    'published_count' => $publishedCount,
                    'total_count' => $total,
                    'parts' => $parts,
                ];
                continue;
            }

            // TikTok's two media live on the same destination rows; the carousel
            // is surfaced as its own card so each speaks its own vocabulary.
            // Meta's reel does the same on its network's rows.
            if ($destination === 'tiktok_carousel') {
                $destinationRows = $rows['tiktok'] ?? [];
                $row = $destinationRows[self::TIKTOK_CAROUSEL_PART] ?? null;
            } elseif (isset(self::META_VIDEO_DESTINATIONS[$destination])) {
                $destinationRows = $rows[self::META_VIDEO_DESTINATIONS[$destination]] ?? [];
                $row = $destinationRows[self::META_VIDEO_PART] ?? null;
            } else {
                $row = $destinationRows[self::TIKTOK_VIDEO_PART] ?? null;
            }
            $status = (string)($row['status'] ?? '');
            if ($destination === 'saatchi' && $saatchiUrl !== '') {
                $status = 'listed';
            }
            $publishedCount = 0;
            $totalCount = 0;
            if ($destination === 'pinterest' && is_array($row)) {
                $decodedPayload = json_decode((string)($row['payload_json'] ?? ''), true);
                foreach ((array)($decodedPayload['results'] ?? []) as $itemResult) {
                    $totalCount++;
                    if (trim((string)($itemResult['external_id'] ?? '')) !== '') $publishedCount++;
                }
            }
            $states[$destination] = [
                'status' => $status,
                'locale' => (string)($row['locale'] ?? ''),
                'external_id' => (string)($row['external_id'] ?? ''),
                'external_url' => $destination === 'saatchi' && $saatchiUrl !== '' ? $saatchiUrl : (string)($row['external_url'] ?? ''),
                'error' => (string)($row['error'] ?? ''),
                'attempted_at' => (string)($row['attempted_at'] ?? ''),
                'connection' => $this->connectionState($destination === 'tiktok_carousel' ? 'tiktok' : $destination, $userId),
                'sent_fingerprint' => (string)($row['product_fingerprint'] ?? ''),
                'published_count' => $publishedCount,
                'total_count' => $totalCount,
                'parts' => [],
            ];
        }
        return $states;
    }

    /**
     * One act, every connected destination — each with its own mechanics. A
     * failing destination never aborts the rest: a half-finished launch has to
     * be visible, not rolled back. Saatchi is excluded by nature (manual
     * upload).
     *
     * @return array{results:array<string,array{status:string,detail:string}>,sent:int,skipped:int,failed:int}
     */
    public function publishAllConnected(int $publicationId, int $userId, string $locale): array
    {
        [$payload] = $this->ensureCurrentProduct($publicationId, $userId);
        $hasVideo = (int)($payload['media']['video']['export_id'] ?? 0) > 0;
        $states = $this->states($publicationId, $userId);
        $results = [];

        foreach (self::LIVE_DESTINATIONS as $destination) {
            $state = (array)($states[$destination] ?? []);
            if (($state['connection'] ?? '') !== 'connected') {
                $results[$destination] = ['status' => 'skipped', 'detail' => t('not connected', 'sin conexión')];
                continue;
            }

            $options = [];
            if ($destination === 'tiktok') {
                // The page video is the natural TikTok post; without one the
                // carousel keeps the channel alive.
                $options['medium'] = $hasVideo ? 'video' : 'carousel';
                $mediumState = $hasVideo ? $state : (array)($states['tiktok_carousel'] ?? []);
                if (in_array((string)($mediumState['status'] ?? ''), ['published', 'processing', 'inbox'], true)) {
                    $results[$destination] = ['status' => 'skipped', 'detail' => t('already sent', 'ya enviado')];
                    continue;
                }
            } elseif (in_array($destination, self::SERIES_DESTINATIONS, true)) {
                if ((int)($state['total_count'] ?? 0) > 0) {
                    $results[$destination] = ['status' => 'skipped', 'detail' => t('series already started', 'serie ya iniciada')];
                    continue;
                }
            } elseif ((string)($state['status'] ?? '') === 'published') {
                $results[$destination] = ['status' => 'skipped', 'detail' => t('already published', 'ya publicado')];
                continue;
            }

            try {
                $this->publish($publicationId, $userId, $destination, $locale, $options);
                $results[$destination] = ['status' => 'sent', 'detail' => ''];
            } catch (Throwable $e) {
                $results[$destination] = ['status' => 'failed', 'detail' => $e->getMessage()];
            }
        }

        // The reel is its own act: it only runs when the page actually has a
        // video, and it never counts as one of the series parts.
        foreach (self::META_VIDEO_DESTINATIONS as $videoDestination => $channel) {
            if (!$hasVideo) {
                $results[$videoDestination] = ['status' => 'skipped', 'detail' => t('no page video', 'sin video de página')];
                continue;
            }
            if ((string)(((array)($states[$channel] ?? []))['connection'] ?? '') !== 'connected') {
                $results[$videoDestination] = ['status' => 'skipped', 'detail' => t('not connected', 'sin conexión')];
                continue;
            }
            if (in_array((string)(((array)($states[$videoDestination] ?? []))['status'] ?? ''), ['published', 'processing'], true)) {
                $results[$videoDestination] = ['status' => 'skipped', 'detail' => t('already sent', 'ya enviado')];
                continue;
            }
            try {
                $this->publish($publicationId, $userId, $videoDestination, $locale);
                $results[$videoDestination] = ['status' => 'sent', 'detail' => ''];
            } catch (Throwable $e) {
                $results[$videoDestination] = ['status' => 'failed', 'detail' => $e->getMessage()];
            }
        }

        $count = static fn(string $status): int => count(array_filter(
            $results,
            static fn(array $result): bool => $result['status'] === $status
        ));
        return [
            'results' => $results,
            'sent' => $count('sent'),
            'skipped' => $count('skipped'),
            'failed' => $count('failed'),
        ];
    }

    /** One confirmed act per destination step. Series destinations run the cadence. */
    public function publish(int $publicationId, int $userId, string $destination, string $locale, array $options = []): array
    {
        if ($destination === 'saatchi') {
            throw new RuntimeException(t('Saatchi Art is uploaded by hand with the manual package.', 'Saatchi Art se carga a mano con el paquete manual.'));
        }
        $metaVideoChannel = self::META_VIDEO_DESTINATIONS[$destination] ?? '';
        if ($metaVideoChannel === '' && !in_array($destination, self::LIVE_DESTINATIONS, true)) {
            throw new InvalidArgumentException(t('Unknown destination.', 'Destino desconocido.'));
        }

        $publication = $this->publications->get($publicationId, $userId);
        if ((string)$publication['status'] !== 'published' || !in_array((string)$publication['visibility'], ['public', 'unlisted'], true)) {
            throw new RuntimeException(t('The page must be published and visible before distributing.', 'La página debe estar publicada y visible antes de distribuir.'));
        }
        [$payload, $fingerprint] = $this->ensureCurrentProduct($publicationId, $userId);

        $locales = array_merge(
            [(string)($payload['locales']['working'] ?? '')],
            (array)($payload['locales']['adaptations'] ?? [])
        );
        if (!in_array($locale, $locales, true)) {
            throw new InvalidArgumentException(t('Choose one of the product languages for this send.', 'Elegí uno de los idiomas del producto para este envío.'));
        }
        $this->assertConnected($destination, $userId);

        $cover = (array)($payload['media']['items'][0] ?? []);
        $slug = (string)($payload['sources']['page']['slug'] ?? $publication['slug']);
        $link = $this->artistSiteUrl($slug);
        $destinations = (array)($payload['destinations'] ?? []);

        if ($metaVideoChannel !== '') {
            if ((int)($payload['media']['video']['export_id'] ?? 0) <= 0) {
                throw new RuntimeException(t('This page has no video to send.', 'Esta página no tiene video para enviar.'));
            }
            $request = $metaVideoChannel === 'x'
                ? $this->xVideoRequest((int)($payload['media']['video']['export_id'] ?? 0), $userId, $destinations, $locale, $link, $slug)
                : $this->metaVideoRequest($metaVideoChannel, $payload, $destinations, $locale, $link, $slug);
            $request['user_id'] = $userId;
            // The row belongs to the network, at the part the series skips: that
            // is where states() reads the reel from, and why sending it can
            // never disturb the series or be counted as one of its parts.
            try {
                $result = ($this->transport($destination))($request);
            } catch (Throwable $e) {
                $this->record($publicationId, $userId, $metaVideoChannel, self::META_VIDEO_PART, ['locale' => $locale, 'status' => 'failed', 'error' => $e->getMessage(), 'request' => $request, 'fingerprint' => $fingerprint]);
                throw $e;
            }
            $this->record($publicationId, $userId, $metaVideoChannel, self::META_VIDEO_PART, [
                'locale' => $locale,
                'status' => (string)($result['status'] ?? 'published'),
                'external_id' => (string)($result['external_id'] ?? ''),
                'external_url' => (string)($result['external_url'] ?? ''),
                'request' => $request,
                'fingerprint' => $fingerprint,
            ]);
            return $this->states($publicationId, $userId)[$destination];
        }

        if (in_array($destination, self::SERIES_DESTINATIONS, true)) {
            $this->publishSeries($publicationId, $userId, $destination, $locale, $payload, $fingerprint, $cover, $link, $slug);
            return $this->states($publicationId, $userId)[$destination];
        }

        // TikTok publishes two independent media; the carousel writes its own
        // row (part 1) so sending it never touches the video's state.
        $medium = $destination === 'tiktok' ? (string)($options['medium'] ?? 'video') : '';
        $isCarousel = $destination === 'tiktok' && $medium === 'carousel';
        $part = $isCarousel ? self::TIKTOK_CAROUSEL_PART : self::TIKTOK_VIDEO_PART;
        $transportKey = $isCarousel ? 'tiktok_carousel' : $destination;

        $previousResults = $destination === 'pinterest' ? $this->previousItemResults($publicationId, $userId, $destination) : [];
        $request = match (true) {
            $destination === 'pinterest' => $this->pinterestRequest($payload, $destinations, $locale, $link, $slug, $previousResults),
            $destination === 'x' => $this->xRequest($payload, $destinations, $locale, $link),
            $isCarousel => $this->tiktokCarouselRequest($payload, $destinations, $locale, $slug),
            default => $this->tiktokRequest($payload, $destinations, $locale, $link),
        };
        $request['user_id'] = $userId;

        try {
            $result = ($this->transport($transportKey))($request);
        } catch (Throwable $e) {
            $this->record($publicationId, $userId, $destination, $part, ['locale' => $locale, 'status' => 'failed', 'error' => $e->getMessage(), 'request' => $request, 'fingerprint' => $fingerprint]);
            throw $e;
        }

        if (isset($result['items'])) {
            $merged = $previousResults;
            foreach ((array)$result['items'] as $itemResult) {
                $merged[(string)($itemResult['key'] ?? '')] = $itemResult;
            }
            $published = array_filter($merged, static fn(array $r): bool => trim((string)($r['external_id'] ?? '')) !== '');
            $errors = array_filter(array_map(static fn(array $r): string => trim((string)($r['error'] ?? '')), $merged));
            $status = count($published) === 0 ? 'failed' : (count($errors) > 0 ? 'partial' : 'published');
            $firstUrl = '';
            foreach ($merged as $itemResult) {
                if (trim((string)($itemResult['external_url'] ?? '')) !== '') { $firstUrl = (string)$itemResult['external_url']; break; }
            }
            $request['results'] = array_values($merged);
            $this->record($publicationId, $userId, $destination, self::TIKTOK_VIDEO_PART, [
                'locale' => $locale,
                'status' => $status,
                'external_id' => (string)($result['external_id'] ?? ''),
                'external_url' => $firstUrl,
                'error' => $status === 'published' ? '' : mb_substr(implode(' · ', $errors), 0, 1200),
                'request' => $request,
                'fingerprint' => $fingerprint,
            ]);
            // A destination that published NOTHING has not been sent. Returning
            // normally here made the batch summary count it among the successes
            // while every single pin had failed — a report worse than an error,
            // because it stops you from looking.
            if ($status === 'failed') {
                throw new RuntimeException(mb_substr(implode(' · ', $errors), 0, 1200)
                    ?: t('No item could be published.', 'No se pudo publicar ningún ítem.'));
            }
            return $this->states($publicationId, $userId)[$destination];
        }

        $this->record($publicationId, $userId, $destination, $part, [
            'locale' => $locale,
            'status' => (string)($result['status'] ?? 'published'),
            'external_id' => (string)($result['external_id'] ?? ''),
            'external_url' => (string)($result['external_url'] ?? ''),
            'request' => $request,
            'fingerprint' => $fingerprint,
        ]);
        return $this->states($publicationId, $userId)[$isCarousel ? 'tiktok_carousel' : $destination];
    }

    /**
     * «Publicar serie espaciada»: the first pending part publishes NOW; every
     * later pending part gets its own row scheduled at now + gap × distance.
     */
    private function publishSeries(int $publicationId, int $userId, string $destination, string $locale, array $payload, string $fingerprint, array $cover, string $link, string $slug): void
    {
        $series = (array)($payload['destinations'][$destination]['series'] ?? []);
        if ($series === []) {
            throw new RuntimeException(t('The composition has no images for this series.', 'La composición no tiene imágenes para esta serie.'));
        }
        $existing = $this->partRows($publicationId, $userId, $destination);
        $pending = [];
        foreach ($series as $post) {
            $part = (int)($post['part'] ?? 0);
            if (($existing[$part]['status'] ?? '') === 'published') continue;
            $pending[] = $post;
        }
        if ($pending === []) return;

        $gapHours = $this->seriesGapHours($userId);
        $now = new DateTimeImmutable('now');
        foreach ($pending as $index => $post) {
            $part = (int)($post['part'] ?? 0);
            $request = $this->seriesRequest($destination, $post, $cover, $locale, $link, $slug);
            $request['user_id'] = $userId;
            if ($index === 0) {
                try {
                    $result = ($this->transport($destination))($request);
                } catch (Throwable $e) {
                    $this->record($publicationId, $userId, $destination, $part, ['locale' => $locale, 'status' => 'failed', 'error' => $e->getMessage(), 'request' => $request, 'fingerprint' => $fingerprint]);
                    throw $e;
                }
                $this->record($publicationId, $userId, $destination, $part, [
                    'locale' => $locale,
                    'status' => (string)($result['status'] ?? 'published'),
                    'external_id' => (string)($result['external_id'] ?? ''),
                    'external_url' => (string)($result['external_url'] ?? ''),
                    'request' => $request,
                    'fingerprint' => $fingerprint,
                ]);
                continue;
            }
            $when = $now->add(new DateInterval('PT' . ($gapHours * $index) . 'H'));
            $rowId = $this->record($publicationId, $userId, $destination, $part, [
                'locale' => $locale,
                'status' => 'scheduled',
                'scheduled_at' => $when->format(DATE_ATOM),
                'request' => $request,
                'fingerprint' => $fingerprint,
            ]);
            $scheduled = ($this->transport('schedule'))(['row_id' => $rowId, 'when' => $when->format(DATE_ATOM)]);
            $taskName = (string)($scheduled['task_name'] ?? '');
            if ($taskName !== '') {
                $this->pdo->prepare('UPDATE publication_distributions SET task_name=?, updated_at=? WHERE id=?')
                    ->execute([$taskName, date('c'), $rowId]);
            }
        }
    }

    /** Worker entry: idempotent — a row that is no longer scheduled is a silent no-op. */
    public function runScheduledSend(int $rowId): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM publication_distributions WHERE id=? LIMIT 1');
        $stmt->execute([$rowId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return;
        $attempt = bin2hex(random_bytes(16));
        $claim = $this->pdo->prepare("UPDATE publication_distributions
            SET status='publishing', publish_attempt_id=?, updated_at=?
            WHERE id=? AND status='scheduled'");
        $claim->execute([$attempt, date('c'), $rowId]);
        if ($claim->rowCount() !== 1) return;
        $this->sendPartFromRow($row, $attempt);
    }

    /** Fires a scheduled part immediately or retries a failed one. The pending Cloud Task no-ops later. */
    public function publishSeriesPartNow(int $publicationId, int $userId, string $destination, int $part): array
    {
        if (!in_array($destination, self::SERIES_DESTINATIONS, true)) {
            throw new InvalidArgumentException(t('This destination has no series parts.', 'Este destino no tiene partes de serie.'));
        }
        $stmt = $this->pdo->prepare('SELECT * FROM publication_distributions WHERE publication_id=? AND user_id=? AND destination=? AND part=? LIMIT 1');
        $stmt->execute([$publicationId, $userId, $destination, $part]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !in_array((string)$row['status'], ['scheduled', 'failed'], true)) {
            throw new RuntimeException(t('This part is not pending.', 'Esta parte no está pendiente.'));
        }
        $attempt = bin2hex(random_bytes(16));
        $claim = $this->pdo->prepare("UPDATE publication_distributions
            SET status='publishing', publish_attempt_id=?, updated_at=?
            WHERE id=? AND status IN ('scheduled','failed')");
        $claim->execute([$attempt, date('c'), (int)$row['id']]);
        if ($claim->rowCount() !== 1) {
            throw new RuntimeException(t('This part is being processed.', 'Esta parte ya se está procesando.'));
        }
        $this->sendPartFromRow($row, $attempt);
        return $this->states($publicationId, $userId)[$destination];
    }

    /** @param string $medium 'video' (part 0) or 'carousel' (part 1) */
    public function refreshTikTokStatus(int $publicationId, int $userId, string $medium = 'video'): array
    {
        $isCarousel = $medium === 'carousel';
        $part = $isCarousel ? self::TIKTOK_CAROUSEL_PART : self::TIKTOK_VIDEO_PART;
        $stmt = $this->pdo->prepare("SELECT * FROM publication_distributions WHERE publication_id=? AND user_id=? AND destination='tiktok' AND part=? LIMIT 1");
        $stmt->execute([$publicationId, $userId, $part]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException(t('This publication has not been sent to TikTok yet.', 'Esta publicación todavía no fue enviada a TikTok.'));
        }
        if ($isCarousel) {
            $publishId = trim((string)$row['external_id']);
            if ($publishId === '') {
                throw new RuntimeException(t('The carousel send did not record its TikTok id.', 'El envío del carrusel no registró su id de TikTok.'));
            }
            $result = ($this->transport('tiktok_carousel_status'))(['user_id' => $userId, 'publish_id' => $publishId]);
        } else {
            $request = json_decode((string)$row['payload_json'], true);
            $exportId = (int)(($request['video_export_id'] ?? 0));
            if ($exportId <= 0) {
                throw new RuntimeException(t('The TikTok send did not record its video.', 'El envío a TikTok no registró su video.'));
            }
            $result = ($this->transport('tiktok_status'))(['user_id' => $userId, 'video_export_id' => $exportId]);
        }
        $status = (string)($result['status'] ?? 'processing');
        $this->pdo->prepare('UPDATE publication_distributions SET status=?, error=?, updated_at=? WHERE id=?')
            ->execute([$status, $status === 'failed' ? (string)($result['error'] ?? '') : '', date('c'), (int)$row['id']]);
        return $this->states($publicationId, $userId)[$isCarousel ? 'tiktok_carousel' : 'tiktok'];
    }

    /** Maps TikTok's publish lifecycle to our own vocabulary. */
    public static function mapTikTokStatus(string $tiktokStatus): string
    {
        return match (strtoupper(trim($tiktokStatus))) {
            'PUBLISH_COMPLETE' => 'published',
            // The draft reached the creator's inbox: it is NOT published until
            // the artist finishes it on the phone.
            'SEND_TO_USER_INBOX' => 'inbox',
            'FAILED' => 'failed',
            default => 'processing',
        };
    }

    public function markSaatchiUploaded(int $publicationId, int $userId): void
    {
        $this->publications->get($publicationId, $userId);
        [, $fingerprint] = $this->ensureCurrentProduct($publicationId, $userId);
        $this->record($publicationId, $userId, 'saatchi', 0, ['status' => 'uploaded', 'fingerprint' => $fingerprint]);
    }

    // ————— private —————

    /** Rebuilds a part's request from the CURRENT product and sends it. */
    private function sendPartFromRow(array $row, string $attempt): void
    {
        $rowId = (int)$row['id'];
        $publicationId = (int)$row['publication_id'];
        $userId = (int)$row['user_id'];
        $destination = (string)$row['destination'];
        $part = (int)$row['part'];
        $locale = (string)$row['locale'];
        try {
            [$payload, $fingerprint] = $this->ensureCurrentProduct($publicationId, $userId);
            $post = null;
            foreach ((array)($payload['destinations'][$destination]['series'] ?? []) as $candidate) {
                if ((int)($candidate['part'] ?? 0) === $part) { $post = $candidate; break; }
            }
            if ($post === null) {
                throw new RuntimeException(t('This part no longer exists in the current product.', 'Esta parte ya no existe en el producto vigente.'));
            }
            $cover = (array)($payload['media']['items'][0] ?? []);
            $slug = (string)($payload['sources']['page']['slug'] ?? '');
            $request = $this->seriesRequest($destination, $post, $cover, $locale, $this->artistSiteUrl($slug), $slug);
            $request['user_id'] = $userId;
            $result = ($this->transport($destination))($request);
            $this->pdo->prepare("UPDATE publication_distributions
                SET status=?, external_id=?, external_url=?, error='', payload_json=?, product_fingerprint=?, attempted_at=?, updated_at=?
                WHERE id=? AND publish_attempt_id=?")
                ->execute([
                    (string)($result['status'] ?? 'published'),
                    mb_substr((string)($result['external_id'] ?? ''), 0, 120),
                    (string)($result['external_url'] ?? ''),
                    json_encode($this->withoutUser($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                    $fingerprint,
                    date('c'),
                    date('c'),
                    $rowId,
                    $attempt,
                ]);
        } catch (Throwable $e) {
            $this->pdo->prepare("UPDATE publication_distributions SET status='failed', error=?, attempted_at=?, updated_at=? WHERE id=? AND publish_attempt_id=?")
                ->execute([mb_substr($e->getMessage(), 0, 1500), date('c'), date('c'), $rowId, $attempt]);
            throw $e;
        }
    }

    private function seriesRequest(string $destination, array $post, array $cover, string $locale, string $link, string $slug): array
    {
        $block = (array)($post[$locale] ?? []);
        $images = array_values(array_map('strval', (array)($post['images'] ?? [])));
        if ($images === []) {
            throw new RuntimeException(t('This series part has no images.', 'Esta parte de la serie no tiene imágenes.'));
        }
        if ($destination === 'x') {
            $composed = $this->xRequest(
                ['media' => ['items' => array_map(static fn(string $file): array => ['file' => $file], $images)]],
                ['x' => [$locale => $block]],
                $locale,
                $link
            );
            return [
                'text' => $composed['text'],
                'link' => $link,
                'image_files' => $images,
                'part' => (int)($post['part'] ?? 0),
                'slug' => $slug,
            ];
        }
        if ($destination === 'instagram') {
            $this->assertInstagramRatio($images[0]);
            $draft = [
                'channel' => 'instagram',
                'purpose' => 'artist',
                'description' => (string)($block['composed'] ?? ''),
                'hashtags' => json_encode((array)($block['hashtags'] ?? []), JSON_UNESCAPED_UNICODE) ?: '[]',
                'alt_text' => (string)(((array)($block['alts'] ?? []))[0] ?? ($cover[$locale]['alt_text'] ?? '')),
                'source_type' => 'image',
            ];
        } else {
            $draft = [
                'channel' => 'facebook',
                'purpose' => 'artist',
                'title' => (string)($block['headline'] ?? ''),
                'description' => (string)($block['composed'] ?? ''),
                'hashtags' => '[]',
                'alt_text' => (string)(((array)($block['alts'] ?? []))[0] ?? ($cover[$locale]['alt_text'] ?? '')),
                'destination_url' => $link,
                'source_type' => 'image',
            ];
        }
        return [
            'draft' => $draft,
            'image_files' => $images,
            'alts' => array_values(array_map('strval', (array)($block['alts'] ?? []))),
            'part' => (int)($post['part'] ?? 0),
            'slug' => $slug,
        ];
    }

    /** @return array<int,array> rows keyed by part */
    private function partRows(int $publicationId, int $userId, string $destination): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM publication_distributions WHERE publication_id=? AND user_id=? AND destination=?');
        $stmt->execute([$publicationId, $userId, $destination]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int)$row['part']] = $row;
        }
        return $rows;
    }

    private function connectionState(string $destination, int $userId): string
    {
        // El reel no tiene conexión propia: usa la de su red.
        $destination = self::META_VIDEO_DESTINATIONS[$destination] ?? $destination;
        try {
            $connection = match ($destination) {
                'pinterest' => (new PinterestIntegrationService($this->pdo))->connection($userId, 'artist'),
                'facebook' => (new MetaIntegrationService($this->pdo))->connection($userId, 'artist'),
                'instagram' => (new InstagramIntegrationService($this->pdo))->connection($userId, 'artist'),
                'tiktok' => (new TikTokIntegrationService($this->pdo))->connection($userId, 'artist'),
                'x' => (new XIntegrationService($this->pdo))->connection($userId, 'artist'),
                default => null,
            };
        } catch (Throwable) {
            return 'none';
        }
        // X now has a connection of its own, like the other networks.
        if ($destination === 'saatchi') return 'manual';
        return ($connection['status'] ?? '') === 'connected' ? 'connected' : 'missing';
    }

    private function assertConnected(string $destination, int $userId): void
    {
        if ($this->connectionState($destination, $userId) !== 'connected') {
            throw new RuntimeException(t('This destination is not connected: open Connections first.', 'Este destino no está conectado: abrí primero Conexiones.'));
        }
    }

    /**
     * One confirmed act publishes the WHOLE pin series — one pin per
     * composition image, each with its own editorial copy. The board is
     * resolved by the system (product board_suggestions → find or create),
     * never chosen by the artist.
     *
     * @param array<string,array> $previousResults keyed by item key; already-published pins are skipped on retry
     */
    private function pinterestRequest(array $payload, array $destinations, string $locale, string $link, string $slug, array $previousResults): array
    {
        $block = (array)($destinations['pinterest'][$locale] ?? []);
        $boardName = trim((string)(((array)($block['board_suggestions'] ?? []))[0] ?? ''));
        if ($boardName === '') $boardName = t('Artworks', 'Obras');

        $items = [];
        foreach ((array)($payload['media']['items'] ?? []) as $mediaItem) {
            $key = (string)((int)($mediaItem['mockup_sheet_id'] ?? 0));
            if (trim((string)($previousResults[$key]['external_id'] ?? '')) !== '') continue; // already pinned
            $itemPinterest = (array)($mediaItem['social'][$locale]['pinterest'] ?? []);
            $items[] = [
                'key' => $key,
                'title' => MockupSocialContentService::text($itemPinterest['title'] ?? '', (string)($block['title'] ?? '')),
                'description' => MockupSocialContentService::text($itemPinterest['description'] ?? '', (string)($block['description'] ?? '')),
                'alt_text' => (string)($mediaItem[$locale]['alt_text'] ?? ''),
                'image_file' => (string)($mediaItem['file'] ?? ''),
            ];
        }
        if ($items === [] && $previousResults === []) {
            throw new RuntimeException(t('The composition has no images to pin.', 'La composición no tiene imágenes para pinear.'));
        }
        return [
            'items' => $items,
            'board_name' => $boardName,
            'link' => $link,
            'slug' => $slug,
        ];
    }

    /** @return array<string,array> previous per-item results keyed by item key */
    private function previousItemResults(int $publicationId, int $userId, string $destination): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT payload_json FROM publication_distributions WHERE publication_id=? AND user_id=? AND destination=? AND part=0 LIMIT 1');
            $stmt->execute([$publicationId, $userId, $destination]);
            $decoded = json_decode((string)($stmt->fetchColumn() ?: ''), true);
        } catch (Throwable) {
            return [];
        }
        $results = [];
        foreach ((array)($decoded['results'] ?? []) as $result) {
            if (!is_array($result)) continue;
            $key = (string)($result['key'] ?? '');
            if ($key !== '') $results[$key] = $result;
        }
        return $results;
    }

    private function tiktokRequest(array $payload, array $destinations, string $locale, string $link): array
    {
        $exportId = (int)($payload['media']['video']['export_id'] ?? 0);
        if ($exportId <= 0) {
            throw new RuntimeException(t('TikTok publishes video: attach a final video to the page first (Website step).', 'TikTok publica video: adjuntá primero un video final a la página (paso Sitio web).'));
        }
        $caption = (string)($destinations['tiktok'][$locale]['caption'] ?? '');
        if (trim($caption) === '') {
            throw new RuntimeException(t('The product has no TikTok caption for this language.', 'El producto no tiene caption de TikTok para este idioma.'));
        }
        // tags stays empty: the product caption already carries its hashtags.
        return [
            'video_export_id' => $exportId,
            'caption' => $caption,
            'destination_url' => $link,
        ];
    }

    /**
     * The carousel needs no video: mockups always exist, so TikTok is never a
     * dead end. TikTok pulls the images itself, so they must be public https.
     */
    private function tiktokCarouselRequest(array $payload, array $destinations, string $locale, string $slug): array
    {
        $images = array_values(array_map('strval', (array)($destinations['tiktok']['carousel_images'] ?? [])));
        if ($images === []) {
            throw new RuntimeException(t('The composition has no images for the carousel.', 'La composición no tiene imágenes para el carrusel.'));
        }
        $carousel = (array)($destinations['tiktok'][$locale]['carousel'] ?? []);
        return [
            'image_files' => $images,
            'image_urls' => array_map(fn(string $file): string => $this->publicImageUrl($slug, $file), $images),
            'title' => (string)($carousel['title'] ?? ''),
            'description' => (string)($carousel['description'] ?? ''),
            'cover_index' => (int)($destinations['tiktok']['cover_index'] ?? 0),
            'slug' => $slug,
        ];
    }

    /** Cheap local pre-check: IG rejects images outside 4:5 … 1.91:1. Skips silently when the file is not readable locally. */
    private function assertInstagramRatio(string $file): void
    {
        $path = $this->localImagePath($file);
        if ($path === '' || !is_file($path)) return;
        $info = @getimagesize($path);
        if (!is_array($info) || (int)$info[1] <= 0) return;
        $ratio = (int)$info[0] / (int)$info[1];
        if ($ratio < 0.8 || $ratio > 1.91) {
            throw new RuntimeException(t('The lead image is outside the 4:5–1.91:1 range Instagram accepts. Choose a compatible mockup order.', 'La imagen líder está fuera del rango 4:5–1.91:1 que Instagram acepta. Elegí un orden de mockups compatible.'));
        }
    }

    private function localImagePath(string $file): string
    {
        $file = basename($file);
        if ($file === '') return '';
        $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path) && class_exists('StorageService') && StorageService::isGcsActive()) {
            StorageService::downloadFile('results/' . $file, $path);
        }
        return is_file($path) ? $path : '';
    }

    private function artistSiteUrl(string $slug): string
    {
        $catalog = rtrim(app_env('ARTIST_WEBSITE_CATALOG_URL', 'https://mauriziovalch.com/artworks'), '/');
        return $catalog . '/' . rawurlencode($slug) . '/';
    }

    private function publicImageUrl(string $slug, string $file): string
    {
        $base = rtrim(app_env('APP_PUBLIC_URL', ''), '/');
        return $base . '/publication_media.php?slug=' . rawurlencode($slug) . '&file=' . rawurlencode(basename($file));
    }

    /**
     * Meta fetches the media itself, so the page video has to be reachable
     * without a session. publication_video_media.php serves exactly the video
     * the published page shows, and only while that page is public.
     */
    private function publicVideoUrl(string $slug): string
    {
        $base = rtrim(app_env('APP_PUBLIC_URL', ''), '/');
        return $base . '/publication_video_media.php?slug=' . rawurlencode($slug);
    }

    /**
     * The reel: the same editorial copy the network already uses, carried by the
     * page video instead of a composition image.
     */
    private function metaVideoRequest(string $channel, array $payload, array $destinations, string $locale, string $link, string $slug): array
    {
        $series = (array)($destinations[$channel]['series'] ?? []);
        $block = (array)(((array)($series[0] ?? []))[$locale] ?? []);
        $draft = $channel === 'instagram'
            ? [
                'channel' => 'instagram',
                'purpose' => 'artist',
                'description' => (string)($block['composed'] ?? ''),
                'hashtags' => json_encode((array)($block['hashtags'] ?? []), JSON_UNESCAPED_UNICODE) ?: '[]',
                'source_type' => 'video',
            ]
            : [
                'channel' => 'facebook',
                'purpose' => 'artist',
                'title' => (string)($block['headline'] ?? ''),
                'description' => (string)($block['composed'] ?? ''),
                'hashtags' => '[]',
                'destination_url' => $link,
                'source_type' => 'video',
            ];

        return [
            'draft' => $draft,
            'video_url' => $this->publicVideoUrl($slug),
            'slug' => $slug,
            'part' => self::META_VIDEO_PART,
        ];
    }

    /**
     * X's chunked upload takes bytes, not a URL the way Meta's does — the
     * exported video is fetched onto local disk (from GCS when that is where
     * it lives) before the transport can hand it to XPublisher, the same way
     * publication_video_media.php serves it to a browser.
     */
    private function xVideoRequest(int $exportId, int $userId, array $destinations, string $locale, string $link, string $slug): array
    {
        $path = $this->localVideoPath($exportId, $userId);
        if ($path === '') {
            throw new RuntimeException(t('The video file could not be found.', 'No se pudo encontrar el archivo del video.'));
        }
        $series = (array)($destinations['x']['series'] ?? []);
        $block = (array)(((array)($series[0] ?? []))[$locale] ?? []);
        $composed = $this->xRequest(['media' => ['items' => []]], ['x' => [$locale => $block]], $locale, $link);

        return [
            'text' => $composed['text'],
            'video_path' => $path,
            'slug' => $slug,
            'part' => self::META_VIDEO_PART,
        ];
    }

    private function localVideoPath(int $exportId, int $userId): string
    {
        $stmt = $this->pdo->prepare("SELECT output_path FROM video_exports WHERE id=? AND user_id=? AND status='succeeded' LIMIT 1");
        $stmt->execute([$exportId, $userId]);
        $key = trim((string)($stmt->fetchColumn() ?: ''));
        if ($key === '') return '';
        // Mirrors VideoMediaStorage::localObjectPath — a key relative to the
        // platform root, wherever this service happens to run from.
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($key, '/'));
        if (!is_file($path) && class_exists('StorageService') && StorageService::isGcsActive()) {
            StorageService::downloadFile($key, $path);
        }
        return is_file($path) ? $path : '';
    }

    /**
     * X counts every link as 23 characters whatever its real length. What gives
     * way, in order, is the sentence, then the tags, then — only if the title
     * alone would still not fit — the title itself. The link never gives way:
     * without it the post leads nowhere.
     */
    private function xRequest(array $payload, array $destinations, string $locale, string $link): array
    {
        $block = (array)(((array)($destinations['x'] ?? []))[$locale] ?? []);
        $title = trim((string)($block['title'] ?? ''));
        $phrase = trim((string)($block['phrase'] ?? ''));
        $hashtags = array_slice(array_values(array_filter(array_map('strval', (array)($block['hashtags'] ?? [])))), 0, 2);

        if ($title === '' && $phrase === '') {
            throw new RuntimeException(t('This artwork has no copy for X yet.', 'Esta obra todavía no tiene copy para X.'));
        }

        $compose = static function (string $title, string $phrase, array $tags) use ($link): string {
            $text = $title;
            if ($phrase !== '') $text .= ($title !== '' ? ' — ' : '') . $phrase;
            $text .= ($text !== '' ? "
" : '') . $link;
            if ($tags !== []) $text .= "
" . implode(' ', $tags);
            return $text;
        };
        // The measure X applies, not the one the string reports.
        $counted = static fn(string $text): int => mb_strlen(
            (string)preg_replace('#https?://\S+#', str_repeat('x', 23), $text)
        );

        $text = $compose($title, $phrase, $hashtags);
        if ($counted($text) > self::X_LIMIT && $phrase !== '') {
            $over = $counted($text) - self::X_LIMIT;
            $keep = mb_strlen($phrase) - $over - 1;
            $phrase = $keep > 12 ? self::trimToWord($phrase, $keep) : '';
            $text = $compose($title, $phrase, $hashtags);
        }
        if ($counted($text) > self::X_LIMIT && $hashtags !== []) {
            $hashtags = [];
            $text = $compose($title, $phrase, $hashtags);
        }
        if ($counted($text) > self::X_LIMIT) {
            $over = $counted($text) - self::X_LIMIT;
            $title = self::trimToWord($title, max(1, mb_strlen($title) - $over - 1));
            $text = $compose($title, $phrase, $hashtags);
        }

        $images = array_values(array_map(
            static fn(array $item): string => (string)($item['file'] ?? ''),
            array_slice((array)($payload['media']['items'] ?? []), 0, 4)
        ));

        return ['text' => $text, 'link' => $link, 'image_files' => array_values(array_filter($images))];
    }

    /** Cut on a word boundary and say so, rather than stopping mid-word. */
    private static function trimToWord(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) return $value;
        $cut = mb_substr($value, 0, max(1, $length));
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 8) $cut = mb_substr($cut, 0, $lastSpace);
        return rtrim($cut, " ,;:-—") . '…';
    }

    private function transport(string $destination): callable
    {
        if (is_array($this->transports) && isset($this->transports[$destination])) {
            return $this->transports[$destination];
        }
        // Real transports: environment gates live HERE, mirroring the existing
        // publish flows, so injected test fakes are never blocked by env.
        return match ($destination) {
            'pinterest' => function (array $request): array {
                if (app_env('PINTEREST_LIVE_PUBLISH_ENABLED', 'false') !== 'true') {
                    throw new RuntimeException(t('Live Pinterest publishing is not enabled in this environment.', 'La publicación en vivo de Pinterest no está habilitada en este entorno.'));
                }
                $pinterest = new PinterestIntegrationService($this->pdo);
                $userId = (int)$request['user_id'];
                // Board resolved by the system: find the suggested board by
                // name, create it when it does not exist yet (boards:write).
                $boardName = (string)$request['board_name'];
                $boardId = '';
                try {
                    foreach ($pinterest->boards($userId, 'artist') as $board) {
                        if (strcasecmp((string)($board['name'] ?? ''), $boardName) === 0) {
                            $boardId = (string)($board['id'] ?? '');
                            break;
                        }
                    }
                } catch (Throwable) {
                    $boardId = '';
                }
                if ($boardId === '') {
                    $created = $pinterest->createBoard($userId, $boardName, 'artist');
                    $boardId = (string)($created['id'] ?? '');
                }
                if ($boardId === '') {
                    throw new RuntimeException(t('The Pinterest board could not be resolved.', 'No se pudo resolver el tablero de Pinterest.'));
                }

                $base = rtrim(app_env('APP_PUBLIC_URL', ''), '/');
                $useUrl = str_starts_with(strtolower($base), 'https://');
                $results = [];
                foreach ((array)$request['items'] as $item) {
                    try {
                        $variant = ['title' => (string)$item['title'], 'description' => (string)$item['description']];
                        $pinItem = ['alt_text' => (string)$item['alt_text']];
                        if ($useUrl) {
                            $payload = (new PinterestPublisher())->imagePinPayload($variant, $pinItem, $boardId, $request['link'], $this->publicImageUrl($request['slug'], (string)$item['image_file']));
                        } else {
                            $path = $this->localImagePath((string)$item['image_file']);
                            if ($path === '') throw new RuntimeException(t('Image unavailable', 'Imagen no disponible') . ': ' . basename((string)$item['image_file']));
                            $payload = (new PinterestPublisher())->imageBase64PinPayload($variant, $pinItem, $boardId, $request['link'], $path);
                        }
                        $created = $pinterest->createPin($userId, $payload, 'artist');
                        $pinId = (string)($created['id'] ?? '');
                        // A sandbox pin has no page on pinterest.com: linking to
                        // one sends the artist to an empty page and reads as "it
                        // never published". No link is honest; a dead one is not.
                        $live = app_env('PINTEREST_API_ENVIRONMENT', 'production') !== 'sandbox';
                        $results[] = [
                            'key' => (string)$item['key'],
                            'external_id' => $pinId,
                            'external_url' => $pinId !== '' && $live ? 'https://www.pinterest.com/pin/' . rawurlencode($pinId) . '/' : '',
                            'error' => '',
                        ];
                    } catch (Throwable $pinError) {
                        $results[] = ['key' => (string)$item['key'], 'external_id' => '', 'external_url' => '', 'error' => $pinError->getMessage()];
                    }
                }
                return ['items' => $results, 'external_id' => $boardId];
            },
            'facebook' => function (array $request): array {
                if (app_env('META_LIVE_PUBLISH_ENABLED', 'false') !== 'true') {
                    throw new RuntimeException(t('Live Facebook publishing is not enabled in this environment.', 'La publicación en vivo de Facebook no está habilitada en este entorno.'));
                }
                $publisher = new MetaPublisher(new MetaIntegrationService($this->pdo));
                $userId = (int)$request['user_id'];
                $files = (array)($request['image_files'] ?? [$request['image_file'] ?? '']);
                $urls = array_map(fn(string $file): string => $this->publicImageUrl($request['slug'], $file), array_map('strval', $files));
                if (count($urls) > 1) {
                    $alts = (array)($request['alts'] ?? []);
                    $drafts = [];
                    foreach ($urls as $i => $unused) {
                        $draft = $request['draft'];
                        $draft['alt_text'] = (string)($alts[$i] ?? $draft['alt_text'] ?? '');
                        $drafts[] = $draft;
                    }
                    $result = $publisher->publishGroup($drafts, $userId, $urls);
                } else {
                    $result = $publisher->publishDraft($request['draft'], $userId, $urls[0]);
                }
                return ['external_id' => (string)($result['id'] ?? ''), 'external_url' => (string)($result['url'] ?? '')];
            },
            'instagram' => function (array $request): array {
                if (app_env('INSTAGRAM_LIVE_PUBLISH_ENABLED', 'false') !== 'true') {
                    throw new RuntimeException(t('Live Instagram publishing is not enabled in this environment.', 'La publicación en vivo de Instagram no está habilitada en este entorno.'));
                }
                $publisher = new InstagramPublisher(new InstagramIntegrationService($this->pdo));
                $userId = (int)$request['user_id'];
                $files = (array)($request['image_files'] ?? [$request['image_file'] ?? '']);
                $urls = array_map(fn(string $file): string => $this->publicImageUrl($request['slug'], $file), array_map('strval', $files));
                if (count($urls) > 1) {
                    $alts = (array)($request['alts'] ?? []);
                    $drafts = [];
                    foreach ($urls as $i => $unused) {
                        $draft = $request['draft'];
                        $draft['alt_text'] = (string)($alts[$i] ?? $draft['alt_text'] ?? '');
                        $drafts[] = $draft;
                    }
                    $result = $publisher->publishGroup($drafts, $userId, $urls);
                } else {
                    $result = $publisher->publishDraft($request['draft'], $userId, $urls[0]);
                }
                return ['external_id' => (string)($result['id'] ?? ''), 'external_url' => (string)($result['url'] ?? '')];
            },
            'facebook_video' => function (array $request): array {
                if (app_env('META_LIVE_PUBLISH_ENABLED', 'false') !== 'true') {
                    throw new RuntimeException(t('Live Facebook publishing is not enabled in this environment.', 'La publicación en vivo de Facebook no está habilitada en este entorno.'));
                }
                // The publisher already routes a video draft to /videos.
                $publisher = new MetaPublisher(new MetaIntegrationService($this->pdo));
                $result = $publisher->publishDraft($request['draft'], (int)$request['user_id'], (string)$request['video_url']);
                return ['external_id' => (string)($result['id'] ?? ''), 'external_url' => (string)($result['url'] ?? '')];
            },
            'instagram_video' => function (array $request): array {
                if (app_env('INSTAGRAM_LIVE_PUBLISH_ENABLED', 'false') !== 'true') {
                    throw new RuntimeException(t('Live Instagram publishing is not enabled in this environment.', 'La publicación en vivo de Instagram no está habilitada en este entorno.'));
                }
                // A video draft becomes a REELS container and is polled for longer.
                $publisher = new InstagramPublisher(new InstagramIntegrationService($this->pdo));
                $result = $publisher->publishDraft($request['draft'], (int)$request['user_id'], (string)$request['video_url']);
                return ['external_id' => (string)($result['id'] ?? ''), 'external_url' => (string)($result['url'] ?? '')];
            },
            'x' => function (array $request): array {
                if (app_env('X_LIVE_PUBLISH_ENABLED', 'false') !== 'true') {
                    throw new RuntimeException(t('Live X publishing is not enabled in this environment.', 'La publicación en vivo de X no está habilitada en este entorno.'));
                }
                $paths = [];
                foreach ((array)($request['image_files'] ?? []) as $file) {
                    $path = $this->localImagePath((string)$file);
                    if ($path !== '') $paths[] = $path;
                }
                $result = (new XPublisher(new XIntegrationService($this->pdo)))->publishText(
                    (int)$request['user_id'],
                    (string)$request['text'],
                    'artist',
                    $paths
                );
                return ['external_id' => (string)($result['id'] ?? ''), 'external_url' => (string)($result['url'] ?? '')];
            },
            'x_video' => function (array $request): array {
                if (app_env('X_LIVE_PUBLISH_ENABLED', 'false') !== 'true') {
                    throw new RuntimeException(t('Live X publishing is not enabled in this environment.', 'La publicación en vivo de X no está habilitada en este entorno.'));
                }
                $result = (new XPublisher(new XIntegrationService($this->pdo)))->publishVideo(
                    (int)$request['user_id'],
                    (string)$request['text'],
                    'artist',
                    (string)$request['video_path']
                );
                return ['external_id' => (string)($result['id'] ?? ''), 'external_url' => (string)($result['url'] ?? '')];
            },
            'tiktok' => function (array $request): array {
                $result = (new VideoTikTokPublicationService($this->pdo))->publish(
                    (int)$request['user_id'],
                    (int)$request['video_export_id'],
                    (string)$request['caption'],
                    0,
                    (string)$request['destination_url'],
                    ''
                );
                return ['external_id' => (string)($result['publishId'] ?? ''), 'external_url' => '', 'status' => 'processing'];
            },
            'tiktok_status' => function (array $request): array {
                $result = (new VideoTikTokPublicationService($this->pdo))->refreshStatus((int)$request['user_id'], (int)$request['video_export_id']);
                return ['status' => (string)($result['status'] ?? 'processing')];
            },
            'tiktok_carousel' => function (array $request): array {
                $publisher = new TikTokPublisher(new TikTokIntegrationService($this->pdo));
                $result = $publisher->publishPhotoDraft(
                    (int)$request['user_id'],
                    (array)$request['image_urls'],
                    (string)$request['title'],
                    (string)$request['description'],
                    (int)$request['cover_index']
                );
                // Creator's Draft: it is waiting in the artist's TikTok inbox,
                // never "published" until TikTok says so.
                return ['external_id' => (string)($result['publishId'] ?? ''), 'external_url' => '', 'status' => 'inbox'];
            },
            'tiktok_carousel_status' => function (array $request): array {
                $publisher = new TikTokPublisher(new TikTokIntegrationService($this->pdo));
                $status = $publisher->fetchStatus((int)$request['user_id'], (string)$request['publish_id']);
                return [
                    'status' => self::mapTikTokStatus((string)$status['status']),
                    'error' => (string)($status['failReason'] ?? ''),
                ];
            },
            'schedule' => function (array $request): array {
                if (!CloudTasksService::isAvailable()) {
                    throw new RuntimeException(t('Spaced publishing needs the production environment (Cloud Tasks).', 'La publicación espaciada requiere el entorno de producción (Cloud Tasks).'));
                }
                return ['task_name' => CloudTasksService::enqueuePublicationDistribution(
                    (int)$request['row_id'],
                    new DateTimeImmutable((string)$request['when'])
                )];
            },
            default => throw new InvalidArgumentException('Unknown transport.'),
        };
    }

    /** @return int the row id */
    private function record(int $publicationId, int $userId, string $destination, int $part, array $fields): int
    {
        $now = date('c');
        $request = (array)($fields['request'] ?? []);
        $payload = json_encode($this->withoutUser($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $existing = $this->pdo->prepare('SELECT id FROM publication_distributions WHERE publication_id=? AND destination=? AND part=? AND user_id=? LIMIT 1');
        $existing->execute([$publicationId, $destination, $part, $userId]);
        $id = (int)($existing->fetchColumn() ?: 0);
        $values = [
            'locale' => (string)($fields['locale'] ?? ''),
            'status' => (string)($fields['status'] ?? ''),
            'external_id' => mb_substr((string)($fields['external_id'] ?? ''), 0, 120),
            'external_url' => (string)($fields['external_url'] ?? ''),
            'error' => mb_substr((string)($fields['error'] ?? ''), 0, 1500),
            'payload_json' => $payload,
            'product_fingerprint' => (string)($fields['fingerprint'] ?? ''),
            'scheduled_at' => (string)($fields['scheduled_at'] ?? ''),
            'task_name' => (string)($fields['task_name'] ?? ''),
            'attempted_at' => $now,
            'updated_at' => $now,
        ];
        if ($id > 0) {
            $sets = implode(',', array_map(static fn(string $column): string => "{$column}=?", array_keys($values)));
            $this->pdo->prepare("UPDATE publication_distributions SET {$sets} WHERE id=?")
                ->execute([...array_values($values), $id]);
            return $id;
        }
        $columns = array_merge(['user_id', 'publication_id', 'destination', 'part'], array_keys($values), ['created_at']);
        $marks = implode(',', array_fill(0, count($columns), '?'));
        $this->pdo->prepare('INSERT INTO publication_distributions (' . implode(',', $columns) . ") VALUES ({$marks})")
            ->execute(array_merge([$userId, $publicationId, $destination, $part], array_values($values), [$now]));
        return (int)$this->pdo->lastInsertId();
    }

    private function withoutUser(array $request): array
    {
        unset($request['user_id']);
        return $request;
    }

    private function gapSettingKey(int $userId): string
    {
        return 'distribution_series_gap_hours_u' . $userId;
    }

    private function isMysql(): bool
    {
        return strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
    }
}
