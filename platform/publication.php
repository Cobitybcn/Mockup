<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Video/VideoStudioRepository.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::WEBSITE_MANAGE, 'Publication');
$pdo = Database::connection();
$userId = (int)$user['id'];

function pub_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pub_media_url(?string $file): string
{
    $file = basename((string)$file);
    return $file !== '' ? 'media.php?file=' . rawurlencode($file) : '';
}

/** Única fuente de moneda: la configuración de la tienda. */
function pub_store_currency(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare('SELECT currency FROM artist_site_settings WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $currency = strtoupper(trim((string)($stmt->fetchColumn() ?: '')));
    return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'EUR';
}

$csrf = Auth::csrfToken('publication');
$bilingualService = new BilingualEditorialService($pdo);
$bilingualEnabled = $bilingualService->isEnabled($userId);
$sourceLocale = $bilingualService->sourceLocale($userId);
$sheetService = new ArtworkSheetService($pdo);
$publicationService = new PublicationService($pdo);
$videoRepository = new VideoStudioRepository($pdo);

$artworkId = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$pinterestPublishToken = '';
Auth::start();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $artworkId > 0) {
    $pinterestPublishToken = bin2hex(random_bytes(24));
    $_SESSION['pinterest_publish_tokens'][$artworkId] = $pinterestPublishToken;
}

/** Editorial resolution — the ficha's terminal state ("obra resuelta"). */
$pubEditorialResolved = static function (int $artworkId) use ($pdo, $bilingualService, $bilingualEnabled, $sourceLocale, $userId): bool {
    if ($bilingualEnabled) {
        $editorial = $bilingualService->get($userId, 'artwork', $artworkId, $sourceLocale);
        return (bool)array_filter(
            (array)($editorial['content'] ?? []),
            static fn($value): bool => !is_array($value) && trim((string)$value) !== ''
        );
    }
    $stmt = $pdo->prepare("SELECT title, description, short_description FROM artwork_sheets
        WHERE canonical_artwork_id=? AND user_id=? AND COALESCE(status,'')<>'merged' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$artworkId, $userId]);
    $sheet = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return trim((string)($sheet['title'] ?? '')) !== ''
        && (trim((string)($sheet['description'] ?? '')) !== '' || trim((string)($sheet['short_description'] ?? '')) !== '');
};

// ————— POST: save/publish/unpublish the website composition —————
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_website' && $artworkId > 0) {
    $transactionStarted = false;
    try {
        Auth::requireValidCsrf((string)($_POST['csrf'] ?? ''), 'publication');
        $owned = $pdo->prepare('SELECT id FROM artworks WHERE id=? AND user_id=? LIMIT 1');
        $owned->execute([$artworkId, $userId]);
        if (!$owned->fetchColumn()) throw new RuntimeException(t('Artwork not found.', 'Obra no encontrada.'));

        $transactionStarted = !$pdo->inTransaction();
        if ($transactionStarted) $pdo->beginTransaction();
        $sheet = $sheetService->sheetForArtwork($artworkId, $userId);
        $intent = (string)($_POST['website_intent'] ?? 'save');
        if (!in_array($intent, ['save', 'publish', 'unpublish'], true)) $intent = 'save';

        // EDITORIAL_CORE Libro VI Cap. 1 (enmienda 2026-07-31): "Publicar Obra"
        // sigue siendo UN solo acto — página + texto aprobado + cascada de
        // mockups — ejecutado desde la sección Publicación. La compuerta: sin
        // contenido editorial generado no se publica.
        $unifiedCascade = null;
        if ($bilingualEnabled && in_array($intent, ['publish', 'unpublish'], true)) {
            $unifiedSpanish = $bilingualService->get($userId, 'artwork', $artworkId, $sourceLocale);
            $unifiedHasContent = (bool)array_filter(
                (array)($unifiedSpanish['content'] ?? []),
                static fn($value): bool => !is_array($value) && trim((string)$value) !== ''
            );
            if ($intent === 'publish') {
                if (!$unifiedHasContent) {
                    throw new RuntimeException(t('Generate the editorial content before publishing the artwork (artwork file, "Generate content").', 'Generá el contenido editorial antes de publicar la obra (ficha de obra, «Generar contenido»).'));
                }
                $bilingualService->setSpanishPublished($userId, 'artwork', $artworkId, true);
                $unifiedCascade = (new BilingualEditorialJobService($pdo))->queueMockupCascadeForArtwork($userId, $artworkId);
            } elseif ($unifiedHasContent) {
                $bilingualService->setSpanishPublished($userId, 'artwork', $artworkId, false);
            }
        }

        // Explicit media selection: ordered mockup ids. Captions/alt come from
        // each mockup's own editorial (depurado antes de llegar acá) — this
        // section composes the page, it never edits content.
        $selectedMockupIds = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['selected_mockups'] ?? ''))), static fn(int $id): bool => $id > 0));
        $explicitItems = array_map(static fn(int $mockupId): array => ['mockup_id' => $mockupId], $selectedMockupIds);

        $savedPublication = $publicationService->saveWebsiteSettings((int)$sheet['id'], $userId, $_POST, $intent, $explicitItems);

        // Una sola portada, decidida en un solo lugar: la imagen 1 de la
        // composición encabeza la página, abre el carrusel de TikTok y lidera
        // el primer post. El selector aparte tenía lista propia y las dos
        // elecciones terminaban separadas. Sin composición no se toca la
        // portada vigente: la galería sigue mostrando todo y la página queda
        // como está.
        $headerFile = basename(trim((string)($savedPublication['header_file'] ?? '')));
        if ($selectedMockupIds !== []) {
            $firstMockup = $pdo->prepare('SELECT mockup_file FROM mockups WHERE id=? AND user_id=? LIMIT 1');
            $firstMockup->execute([$selectedMockupIds[0], $userId]);
            $firstFile = basename((string)($firstMockup->fetchColumn() ?: ''));
            if ($firstFile !== '') $headerFile = $firstFile;
        }
        $saatchiUrl = trim((string)($_POST['saatchi_url'] ?? ''));
        if ($saatchiUrl !== '') {
            $saatchiHost = strtolower((string)(parse_url($saatchiUrl, PHP_URL_HOST) ?: ''));
            if (!str_starts_with($saatchiUrl, 'https://')
                || ($saatchiHost !== 'saatchiart.com' && !str_ends_with($saatchiHost, '.saatchiart.com'))) {
                throw new RuntimeException(t('The Saatchi Art link must be an https URL on saatchiart.com.', 'El enlace de Saatchi Art debe ser una URL https de saatchiart.com.'));
            }
        }
        $pdo->prepare('UPDATE publications SET header_file=?,saatchi_url=?,updated_at=? WHERE id=? AND user_id=?')
            ->execute([$headerFile, $saatchiUrl, date('c'), (int)$savedPublication['id'], $userId]);

        $constellationCountry = trim((string)($_POST['constellation_country'] ?? ''));
        $constellation = $pdo->prepare('SELECT id FROM artist_site_constellations WHERE user_id=? AND artwork_id=? LIMIT 1');
        $constellation->execute([$userId, $artworkId]);
        $constellationId = (int)($constellation->fetchColumn() ?: 0);
        if ($constellationId > 0) {
            $pdo->prepare("UPDATE artist_site_constellations SET enabled=?,country=?,region='',city='',postal_code='',latitude='',longitude='',privacy=?,public_note='',updated_at=? WHERE id=? AND user_id=?")
                ->execute([$constellationCountry === '' ? 0 : 1, $constellationCountry, $constellationCountry === '' ? 'private' : 'country', date('c'), $constellationId, $userId]);
        } elseif ($constellationCountry !== '') {
            $now = date('c');
            $pdo->prepare("INSERT INTO artist_site_constellations (user_id,artwork_id,enabled,country,region,city,postal_code,latitude,longitude,privacy,public_note,created_at,updated_at) VALUES (?,?,1,?,'','','','','','country','',?,?)")
                ->execute([$userId, $artworkId, $constellationCountry, $now, $now]);
        }

        $saleVariant = $pdo->prepare('SELECT * FROM artist_site_print_variants WHERE user_id=? AND artwork_id=? ORDER BY id LIMIT 1');
        $saleVariant->execute([$userId, $artworkId]);
        $sale = $saleVariant->fetch(PDO::FETCH_ASSOC) ?: null;
        $priceInput = trim((string)($_POST['sale_price'] ?? ''));
        if ($sale || $priceInput !== '') {
            $price = str_replace(',', '.', $priceInput === '' ? '0' : $priceInput);
            if (!is_numeric($price) || (float)$price < 0) throw new RuntimeException(t('Enter a valid artwork price.', 'Ingresá un precio válido para la obra.'));
            // La moneda no se decide obra por obra: la tienda tiene una sola y
            // toda la vitrina la hereda. Cuando se elegía acá, una obra podía
            // quedar en EUR contra una tienda en USD y la ficha escondía precio
            // y botón de compra sin decir por qué.
            $currency = pub_store_currency($pdo, $userId);
            $saleStatus = (string)($_POST['sale_status'] ?? 'draft');
            if (!in_array($saleStatus, ['draft', 'active', 'paused', 'sold_out'], true)) $saleStatus = 'draft';
            $stock = max(0, (int)($_POST['sale_stock'] ?? 0));
            if ($saleStatus === 'active' && ((float)$price <= 0 || $stock <= 0)) {
                throw new RuntimeException(t('Available artworks need a price and at least one available unit.', 'Las obras disponibles necesitan un precio y al menos una unidad disponible.'));
            }
            $now = date('c');
            if ($sale) {
                $stockOnHand = $stock + max(0, (int)($sale['stock_reserved'] ?? 0));
                $pdo->prepare('UPDATE artist_site_print_variants SET stock_on_hand=?,price_minor=?,currency=?,status=?,updated_at=? WHERE id=? AND user_id=? AND artwork_id=?')
                    ->execute([$stockOnHand, (int)round((float)$price * 100), $currency, $saleStatus, $now, (int)$sale['id'], $userId, $artworkId]);
            } else {
                $pdo->prepare("INSERT INTO artist_site_print_variants (user_id,artwork_id,title,sku,size_label,support,finish,inventory_mode,edition_size,stock_on_hand,stock_reserved,price_minor,currency,status,created_at,updated_at) VALUES (?,?,?,?,'','','','in_stock',1,?,0,?,?,?, ?,?)")
                    ->execute([$userId, $artworkId, trim((string)($sheet['title'] ?? '')), 'ART-' . $artworkId, $stock, (int)round((float)$price * 100), $currency, $saleStatus, $now, $now]);
            }
        }
        if ($transactionStarted) $pdo->commit();
        // La cascada se despacha tras el commit: las tareas no deben correr
        // contra jobs aun no confirmados.
        if ($unifiedCascade !== null) {
            (new BilingualEditorialJobService($pdo))->dispatchCascade($userId, $unifiedCascade);
        }

        // Video attach/detach runs after commit: the video repository manages
        // its own transaction and requires the page to already be published.
        $videoWarning = '';
        try {
            $requestedVideoId = max(0, (int)($_POST['video_export_id'] ?? 0));
            $currentVideoId = 0;
            foreach ($videoRepository->finalVideosForArtwork($userId, $artworkId) as $finalVideo) {
                if (!empty($finalVideo['sitePublished'])) $currentVideoId = (int)$finalVideo['id'];
            }
            if ($requestedVideoId !== $currentVideoId) {
                if ($requestedVideoId > 0) {
                    $videoRepository->publishFinalVideo($userId, $requestedVideoId);
                } elseif ($currentVideoId > 0) {
                    $videoRepository->unpublishFinalVideo($userId, $currentVideoId);
                }
            }
        } catch (Throwable $videoError) {
            $videoWarning = $videoError->getMessage();
        }

        $message = $intent === 'publish' ? t('published', 'publicada') : ($intent === 'unpublish' ? t('unpublished', 'despublicada') : t('saved', 'guardada'));
        $suffix = $unifiedCascade !== null && $unifiedCascade['queued'] !== [] ? '&cascade_count=' . count($unifiedCascade['queued']) : '';
        if ($videoWarning !== '') $suffix .= '&video_warning=' . rawurlencode($videoWarning);
        header('Location: publication.php?id=' . rawurlencode((string)$artworkId) . '&saved=' . rawurlencode($message) . $suffix);
        exit;
    } catch (Throwable $e) {
        if ($transactionStarted && $pdo->inTransaction()) $pdo->rollBack();
        header('Location: publication.php?id=' . rawurlencode((string)$artworkId) . '&error=' . rawurlencode($e->getMessage()));
        exit;
    }
}


// ————— POST: attach a finished desktop edit without leaving the step —————
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_final_video' && $artworkId > 0) {
    try {
        Auth::requireValidCsrf((string)($_POST['csrf'] ?? ''), 'publication');
        $owned = $pdo->prepare('SELECT id FROM artworks WHERE id=? AND user_id=? LIMIT 1');
        $owned->execute([$artworkId, $userId]);
        if (!$owned->fetchColumn()) throw new RuntimeException(t('Artwork not found.', 'Obra no encontrada.'));
        $file = $_FILES['video'] ?? null;
        if (!is_array($file)) throw new InvalidArgumentException(t('Select a final video.', 'Seleccioná un video final.'));
        // The Video subsystem is loaded lazily: only this action needs it.
        require_once __DIR__ . '/app/Video/bootstrap.php';
        $videoRepositoryForUpload = new VideoStudioRepository($pdo);
        $uploadService = new VideoFinalUploadService($videoRepositoryForUpload, new VideoJobRepository($videoRepositoryForUpload->pdo()));
        $uploadService->uploadForArtwork($userId, $artworkId, $file);
        header('Location: publication.php?id=' . rawurlencode((string)$artworkId) . '&video_uploaded=1');
        exit;
    } catch (Throwable $e) {
        header('Location: publication.php?id=' . rawurlencode((string)$artworkId) . '&error=' . rawurlencode($e->getMessage()));
        exit;
    }
}

// ————— POST: distribution (immediate per-destination sends) —————
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['distribute', 'distribute_all', 'tiktok_status', 'saatchi_uploaded', 'series_part_now', 'distribution_settings'], true) && $artworkId > 0) {
    $distributionAction = (string)$_POST['action'];
    try {
        Auth::requireValidCsrf((string)($_POST['csrf'] ?? ''), 'publication');
        $owned = $pdo->prepare('SELECT id FROM artworks WHERE id=? AND user_id=? LIMIT 1');
        $owned->execute([$artworkId, $userId]);
        if (!$owned->fetchColumn()) throw new RuntimeException(t('Artwork not found.', 'Obra no encontrada.'));
        $sheet = $sheetService->sheetForArtwork($artworkId, $userId);
        $publication = $publicationService->findForSheet((int)$sheet['id'], $userId);
        if (!is_array($publication)) throw new RuntimeException(t('Publish the artwork page first.', 'Publicá primero la página de la obra.'));
        $distribution = new PublicationDistributionService($pdo, null, $publicationService);
        if ($distributionAction === 'distribute') {
            if ((string)($_POST['confirm'] ?? '') !== 'yes') {
                throw new RuntimeException(t('Confirm the send before publishing.', 'Confirmá el envío antes de publicar.'));
            }
            $medium = (string)($_POST['medium'] ?? 'video');
            $requestedDestination = (string)($_POST['destination'] ?? '');
            $pinAllowedBoardIds = [];
            if ($requestedDestination === 'pinterest') {
                Auth::start();
                $expectedPublishToken = (string)($_SESSION['pinterest_publish_tokens'][$artworkId] ?? '');
                $submittedPublishToken = (string)($_POST['pinterest_publish_token'] ?? '');
                unset($_SESSION['pinterest_publish_tokens'][$artworkId]);
                if ($expectedPublishToken === '' || $submittedPublishToken === '' || !hash_equals($expectedPublishToken, $submittedPublishToken)) {
                    throw new RuntimeException(t(
                        'This Pinterest publication was already submitted or expired. Reload the page before trying again.',
                        'Este envío a Pinterest ya fue enviado o venció. Recargá la página antes de volver a intentar.'
                    ));
                }
                foreach ((new PinterestIntegrationService($pdo))->publicationBoards($userId, 'artist') as $selectableBoard) {
                    $selectableBoardId = trim((string)($selectableBoard['id'] ?? ''));
                    if ($selectableBoardId !== '') $pinAllowedBoardIds[] = $selectableBoardId;
                }
            }
            $pinBoards = [];
            foreach ((array)($_POST['pin_boards'] ?? []) as $pinKey => $pinBoardId) {
                $pinKey = trim((string)$pinKey);
                $pinBoardId = trim((string)$pinBoardId);
                if (preg_match('/^[0-9]+$/', $pinKey) && $pinBoardId !== '') $pinBoards[$pinKey] = $pinBoardId;
            }
            $pinLink = trim((string)($_POST['pin_link'] ?? ''));
            $state = $distribution->publish(
                (int)$publication['id'],
                $userId,
                $requestedDestination,
                (string)($_POST['locale'] ?? ''),
                [
                    'board_ids' => $pinBoards,
                    'allowed_board_ids' => $pinAllowedBoardIds,
                    'link' => $pinLink,
                    'medium' => $medium,
                ]
            );
            $flag = $requestedDestination;
            if ($flag === 'tiktok' && $medium === 'carousel') $flag = 'tiktok_carousel';
        } elseif ($distributionAction === 'distribute_all') {
            if ((string)($_POST['confirm'] ?? '') !== 'yes') {
                throw new RuntimeException(t('Confirm the send before publishing.', 'Confirmá el envío antes de publicar.'));
            }
            $summary = $distribution->publishAllConnected((int)$publication['id'], $userId, (string)($_POST['locale'] ?? ''));
            Auth::start();
            $_SESSION['publication_distribute_all'] = $summary;
            $flag = 'all';
        } elseif ($distributionAction === 'tiktok_status') {
            $distribution->refreshTikTokStatus((int)$publication['id'], $userId, (string)($_POST['medium'] ?? 'video'));
            $flag = 'tiktok_status';
        } elseif ($distributionAction === 'series_part_now') {
            $distribution->publishSeriesPartNow(
                (int)$publication['id'],
                $userId,
                (string)($_POST['destination'] ?? ''),
                max(1, (int)($_POST['part'] ?? 0))
            );
            $flag = (string)($_POST['destination'] ?? '');
        } elseif ($distributionAction === 'distribution_settings') {
            $distribution->setSeriesGapHours($userId, (int)($_POST['gap_hours'] ?? 0));
            $flag = 'settings';
        } else {
            $distribution->markSaatchiUploaded((int)$publication['id'], $userId);
            $flag = 'saatchi';
        }
        header('Location: publication.php?id=' . rawurlencode((string)$artworkId) . '&dist=' . rawurlencode($flag) . '#pub-panel-distribution');
        exit;
    } catch (Throwable $e) {
        header('Location: publication.php?id=' . rawurlencode((string)$artworkId) . '&error=' . rawurlencode($e->getMessage()) . '#pub-panel-distribution');
        exit;
    }
}

// ————— Data: index or working document —————
$indexArtworks = [];
$doc = null;

if ($artworkId <= 0) {
    // Mismo orden que ArtWorks (root_album.php): las obras se agrupan por serie
    // —la mas reciente primero— y dentro de cada serie por numero de creacion
    // descendente. Antes esta pantalla ordenaba por updated_at, asi que la
    // misma coleccion aparecia en dos ordenes distintos segun donde la miraras.
    $stmt = $pdo->prepare("SELECT a.id, a.final_title, a.series, a.root_file
        FROM artworks a
        LEFT JOIN artwork_series s ON s.id = a.series_id AND s.user_id = a.user_id
        LEFT JOIN artwork_groups g ON g.id = a.artwork_group_id AND g.user_id = a.user_id
        WHERE a.user_id = :user_id AND a.status = 'done' AND a.root_file IS NOT NULL AND a.root_file <> ''
          AND (COALESCE(a.artwork_group_id, 0) = 0 OR EXISTS (
              SELECT 1 FROM artwork_groups g2 WHERE g2.id = a.artwork_group_id AND g2.canonical_artwork_id = a.id
          ))
        ORDER BY
            CASE WHEN a.series_id IS NULL THEN 1 ELSE 0 END ASC,
            CASE WHEN s.year_start IS NULL AND s.year_end IS NULL THEN 1 ELSE 0 END ASC,
            COALESCE(s.year_start, s.year_end) DESC,
            COALESCE(s.year_end, s.year_start) DESC,
            s.created_at DESC,
            s.id DESC,
            CASE WHEN a.series_creation_number IS NULL THEN 1 ELSE 0 END ASC,
            a.series_creation_number DESC,
            COALESCE(g.created_at, a.created_at) DESC,
            COALESCE(g.id, a.id) DESC");
    $stmt->execute(['user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pageStates = [];
    try {
        $pages = $pdo->prepare('SELECT sh.canonical_artwork_id aid, p.status, p.visibility
            FROM publications p
            INNER JOIN artwork_sheets sh ON sh.id = p.artwork_sheet_id AND sh.user_id = p.user_id
            WHERE p.user_id = ? ORDER BY p.id ASC');
        $pages->execute([$userId]);
        foreach ($pages->fetchAll(PDO::FETCH_ASSOC) as $page) {
            $pageStates[(int)$page['aid']] = $page;
        }
    } catch (Throwable) {
        $pageStates = [];
    }
    $videoStates = [];
    try {
        $videoRows = $pdo->prepare('SELECT artwork_id FROM artwork_video_publications WHERE user_id=?');
        $videoRows->execute([$userId]);
        foreach ($videoRows->fetchAll(PDO::FETCH_COLUMN) as $videoArtworkId) $videoStates[(int)$videoArtworkId] = true;
    } catch (Throwable) {
        $videoStates = [];
    }
    $saleStates = [];
    try {
        $saleRows = $pdo->prepare("SELECT artwork_id FROM artist_site_print_variants WHERE user_id=? AND status='active'");
        $saleRows->execute([$userId]);
        foreach ($saleRows->fetchAll(PDO::FETCH_COLUMN) as $saleArtworkId) $saleStates[(int)$saleArtworkId] = true;
    } catch (Throwable) {
        $saleStates = [];
    }

    foreach ($rows as $row) {
        $rowId = (int)$row['id'];
        $indexArtworks[] = [
            'id' => $rowId,
            'title' => trim((string)($row['final_title'] ?? '')) ?: t('Untitled', 'Sin título'),
            'series' => ArtworkSeries::display((string)($row['series'] ?? '')),
            'file' => basename((string)($row['root_file'] ?? '')),
            'resolved' => $pubEditorialResolved($rowId),
            'pageStatus' => (string)($pageStates[$rowId]['status'] ?? 'not_prepared'),
            'hasVideo' => isset($videoStates[$rowId]),
            'saleActive' => isset($saleStates[$rowId]),
        ];
    }
} else {
    $artworkStmt = $pdo->prepare('SELECT * FROM artworks WHERE id=? AND user_id=? LIMIT 1');
    $artworkStmt->execute([$artworkId, $userId]);
    $artwork = $artworkStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($artwork)) {
        header('Location: publication.php');
        exit;
    }
    $rootFile = basename((string)($artwork['root_file'] ?? ''));
    $sheet = $sheetService->sheetForArtwork($artworkId, $userId);
    $publication = $publicationService->findForSheet((int)$sheet['id'], $userId);
    $resolved = $pubEditorialResolved($artworkId);

    // Related mockups (same joins as the artwork file).
    $groupId = (int)($artwork['artwork_group_id'] ?? 0);
    if ($groupId > 0) {
        $mockupStmt = $pdo->prepare('SELECT * FROM mockups WHERE user_id=? AND artwork_group_id=? ORDER BY created_at DESC');
        $mockupStmt->execute([$userId, $groupId]);
    } else {
        $mockupStmt = $pdo->prepare('SELECT * FROM mockups WHERE user_id=? AND artwork_file=? ORDER BY created_at DESC');
        $mockupStmt->execute([$userId, $rootFile]);
    }
    $favoriteLookup = MockupFavorites::lookupForUser($userId);
    $mockupCards = [];
    foreach ($mockupStmt->fetchAll(PDO::FETCH_ASSOC) as $mockupRow) {
        $mockupFile = basename((string)($mockupRow['mockup_file'] ?? ''));
        if ($mockupFile === '') continue;
        $state = json_decode((string)($mockupRow['selector_state_json'] ?? ''), true);
        $combination = is_array($state) ? (array)($state['combination'] ?? []) : [];
        $label = trim((string)($combination['camera_slot_name'] ?? ''));
        if ($label === '') $label = trim((string)($mockupRow['title'] ?? ''));
        if ($label === '') $label = t('Mockup', 'Mockup') . ' ' . (int)$mockupRow['id'];
        $mockupCards[(int)$mockupRow['id']] = [
            'id' => (int)$mockupRow['id'],
            'file' => $mockupFile,
            'label' => $label,
            'favorite' => isset($favoriteLookup[(int)$mockupRow['id']]),
        ];
    }

    // Current explicit selection from publication_items (by mockup file).
    $currentSelection = [];
    foreach ((array)($publication['items'] ?? []) as $item) {
        $itemFile = basename((string)($item['mockup_file'] ?? ''));
        if ($itemFile === '') continue;
        $currentSelection[$itemFile] = (int)($item['position'] ?? 0);
    }
    $selectionOrder = [];
    if ($currentSelection !== []) {
        asort($currentSelection);
        foreach (array_keys($currentSelection) as $selectedFile) {
            foreach ($mockupCards as $card) {
                if ($card['file'] === $selectedFile) { $selectionOrder[] = $card['id']; break; }
            }
        }
    } else {
        foreach ($mockupCards as $card) {
            if ($card['favorite']) $selectionOrder[] = $card['id'];
        }
    }

    // La grilla se lee como se leera la pagina: primero lo incluido, en el
    // orden en que la galeria lo mostrara, y despues lo que quedo afuera. Con
    // las tarjetas en orden de creacion los numeros salian salteados (5, 6, 2,
    // 1…) y el orden real era invisible.
    $mediaGrid = [];
    foreach ($selectionOrder as $selectedId) {
        if (isset($mockupCards[$selectedId])) $mediaGrid[] = $mockupCards[$selectedId];
    }
    foreach ($mockupCards as $card) {
        if (!in_array($card['id'], $selectionOrder, true)) $mediaGrid[] = $card;
    }

    // La portada ya no se elige aparte: es la imagen 1 de la composición. Se
    // conserva solo para leer cuál es la vigente cuando todavía no hay
    // composición (galería en modo «mostrar todo»).
    $selectedCover = basename((string)($publication['header_file'] ?? ''));
    if ($selectedCover === '') $selectedCover = $rootFile;

    // Commercial data.
    $saleStmt = $pdo->prepare('SELECT * FROM artist_site_print_variants WHERE user_id=? AND artwork_id=? ORDER BY id LIMIT 1');
    $saleStmt->execute([$userId, $artworkId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $saleAvailable = $sale ? max(0, (int)$sale['stock_on_hand'] - (int)$sale['stock_reserved']) : 0;
    $storeCurrency = pub_store_currency($pdo, $userId);
    $constellationStmt = $pdo->prepare('SELECT country FROM artist_site_constellations WHERE user_id=? AND artwork_id=? AND enabled=1 LIMIT 1');
    $constellationStmt->execute([$userId, $artworkId]);
    $constellationCountry = trim((string)($constellationStmt->fetchColumn() ?: ''));

    // Final videos of this artwork + currently attached one.
    $finalVideos = [];
    try {
        $finalVideos = $videoRepository->finalVideosForArtwork($userId, $artworkId);
    } catch (Throwable) {
        $finalVideos = [];
    }
    $attachedVideoId = 0;
    foreach ($finalVideos as $finalVideo) {
        if (!empty($finalVideo['sitePublished'])) $attachedVideoId = (int)$finalVideo['id'];
    }

    // Product ENGINE (PUBLICACION_DISENO I.2, enmienda 2026-07-31): the frozen
    // projection regenerates SILENTLY whenever the page is published and the
    // sources changed — the artist never operates it. Every step below always
    // reads an up-to-date product.
    $distributionService = new PublicationDistributionService($pdo, null, $publicationService);
    $productPayload = null;
    $productFingerprint = '';
    $productGateMessage = '';
    if (is_array($publication) && (string)($publication['status'] ?? '') === 'published') {
        try {
            [$productPayload, $productFingerprint] = $distributionService->ensureCurrentProduct((int)$publication['id'], $userId);
        } catch (Throwable $productEngineError) {
            $productGateMessage = $productEngineError->getMessage();
        }
    }
    $distStates = is_array($publication) ? $distributionService->states((int)$publication['id'], $userId) : [];
    $seriesGapHours = $distributionService->seriesGapHours($userId);
    $workingLocale = $bilingualService->sourceLocale($userId);
    $adaptationLocale = $bilingualService->primaryAdaptationTarget($userId);

    $measurementUnit = (string)($artwork['unit'] ?? 'cm');
    $sizeText = trim((string)($artwork['width'] ?? '')) !== '' && trim((string)($artwork['height'] ?? '')) !== ''
        ? trim((string)$artwork['width'] . ' × ' . (string)$artwork['height'] . ((string)($artwork['depth'] ?? '') !== '' ? ' × ' . (string)$artwork['depth'] : '') . ' ' . $measurementUnit)
        : '';

    $doc = [
        'artwork' => $artwork,
        'title' => trim((string)($artwork['final_title'] ?? '')) ?: t('Untitled', 'Sin título'),
        'series' => ArtworkSeries::display((string)($artwork['series'] ?? '')),
        'medium' => trim((string)($artwork['medium'] ?? '')),
        'year' => trim((string)($artwork['artwork_year'] ?? '')),
        'sizeText' => $sizeText,
        'rootFile' => $rootFile,
        'resolved' => $resolved,
        'publication' => $publication,
        'pageStatus' => (string)($publication['status'] ?? 'not_prepared'),
        'visibility' => (string)($publication['visibility'] ?? 'public'),
        'saatchiUrl' => (string)($publication['saatchi_url'] ?? ''),
        'mockupCards' => $mockupCards,
        'mediaGrid' => $mediaGrid,
        'selectionOrder' => $selectionOrder,
        'selectedCover' => $selectedCover,
        'sale' => $sale,
        'saleAvailable' => $saleAvailable,
        'storeCurrency' => $storeCurrency,
        'constellationCountry' => $constellationCountry,
        'finalVideos' => $finalVideos,
        'attachedVideoId' => $attachedVideoId,
        'productPayload' => $productPayload,
        'productFingerprint' => $productFingerprint,
        'productGateMessage' => $productGateMessage,
        'workingLocale' => $workingLocale,
        'adaptationLocale' => $adaptationLocale,
        'distStates' => $distStates,
        'seriesGapHours' => $seriesGapHours,
    ];
}

/** Read-only projected field: kicker label + text. Empty values render a quiet dash. */
function pub_product_field(string $label, string $value): string
{
    $body = trim($value) !== '' ? nl2br(pub_h($value)) : '<span class="pub-product-empty">—</span>';
    return '<div class="pub-product-field"><span>' . pub_h($label) . '</span><p>' . $body . '</p></div>';
}

/** Per-destination distribution state chip — its own vocabulary, never a borrowed generic label. */
function pub_dist_chip(string $destination, array $state): array
{
    $status = (string)($state['status'] ?? '');
    if ($destination === 'pinterest' && !empty($state['requires_republish'])) {
        return ['pub-chip pub-chip--pending', t('SANDBOX PINS', 'PINS DE SANDBOX')];
    }
    return match ($destination) {
        'pinterest' => match ($status) {
            'published' => ['pub-chip pub-chip--live', t('PINS PUBLISHED', 'PINS PUBLICADOS')],
            'partial' => ['pub-chip pub-chip--pending', t('PARTIAL', 'PARCIAL')],
            'failed' => ['pub-chip pub-chip--pending', t('FAILED', 'FALLÓ')],
            default => ['pub-chip', t('No pins yet', 'Sin pins')],
        },
        'instagram', 'facebook' => match ($status) {
            'published' => ['pub-chip pub-chip--live', t('SERIES PUBLISHED', 'SERIE PUBLICADA')],
            'scheduled' => ['pub-chip pub-chip--ok', t('SERIES SCHEDULED', 'SERIE PROGRAMADA')],
            'partial' => ['pub-chip pub-chip--pending', t('PARTIAL', 'PARCIAL')],
            'failed' => ['pub-chip pub-chip--pending', t('FAILED', 'FALLÓ')],
            default => ['pub-chip', t('Not sent yet', 'Sin enviar')],
        },
        'tiktok' => match ($status) {
            'processing' => ['pub-chip pub-chip--ok', t('SENT · PROCESSING', 'ENVIADO · PROCESANDO')],
            'published' => ['pub-chip pub-chip--live', t('PUBLISHED', 'PUBLICADO')],
            'failed' => ['pub-chip pub-chip--pending', t('FAILED', 'FALLÓ')],
            default => ['pub-chip', t('Not sent yet', 'Sin enviar')],
        },
        // Creator's Draft never says "published" on our word: the carousel
        // waits in the artist's TikTok inbox until he finishes it there.
        'tiktok_carousel' => match ($status) {
            'inbox' => ['pub-chip pub-chip--ok', t('WAITING FOR YOU ON TIKTOK', 'TE ESPERA EN TIKTOK')],
            'processing' => ['pub-chip pub-chip--ok', t('SENT · PROCESSING', 'ENVIADO · PROCESANDO')],
            'published' => ['pub-chip pub-chip--live', t('PUBLISHED', 'PUBLICADO')],
            'failed' => ['pub-chip pub-chip--pending', t('FAILED', 'FALLÓ')],
            default => ['pub-chip', t('Not sent yet', 'Sin enviar')],
        },
        'x' => match ($status) {
            'published' => ['pub-chip pub-chip--live', t('PUBLISHED', 'PUBLICADO')],
            'failed' => ['pub-chip pub-chip--pending', t('FAILED', 'FALLÓ')],
            default => ['pub-chip', t('Not sent yet', 'Sin enviar')],
        },
        'saatchi' => match ($status) {
            'listed' => ['pub-chip pub-chip--live', t('LISTED', 'LISTADO')],
            'uploaded' => ['pub-chip pub-chip--ok', t('UPLOADED BY HAND', 'CARGADO A MANO')],
            default => ['pub-chip', t('PACKAGE READY', 'PAQUETE LISTO')],
        },
        default => ['pub-chip', '—'],
    };
}

/** @param list<string> $values */
function pub_product_chips(string $label, array $values): string
{
    $chips = '';
    foreach ($values as $value) {
        if (trim($value) === '') continue;
        $chips .= '<em class="pub-chip">' . pub_h($value) . '</em>';
    }
    if ($chips === '') $chips = '<span class="pub-product-empty">—</span>';
    return '<div class="pub-product-field"><span>' . pub_h($label) . '</span><div class="pub-product-keywords">' . $chips . '</div></div>';
}

function pub_page_chip(string $status): array
{
    return match ($status) {
        'published' => ['pub-chip pub-chip--live', t('Page published', 'Página publicada')],
        'draft' => ['pub-chip pub-chip--pending', t('Page not published', 'Página sin publicar')],
        default => ['pub-chip', t('Page not prepared', 'Página sin preparar')],
    };
}
?>
<!doctype html>
<html lang="<?= pub_h(Translator::locale($user)) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= pub_h(t('Publication - Artwork Mockups', 'Publicación - Artwork Mockups')) ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
    <link rel="stylesheet" href="publication.css?v=21">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= pub_h($user['email']) ?></a>
        </header>

        <div class="publishing-catalog">
            <?php if (isset($_GET['video_uploaded'])): ?>
                <div class="notice"><?= pub_h(t('Video attached to this artwork. Select it below to show it on the page — it also unlocks the TikTok step.', 'Video adjuntado a esta obra. Seleccionalo abajo para mostrarlo en la página — también habilita el paso de TikTok.')) ?></div>
            <?php endif; ?>
            <?php
            // Resumen honesto del acto único: qué salió, qué se salteó y por qué.
            Auth::start();
            $distributeAllSummary = $_SESSION['publication_distribute_all'] ?? null;
            unset($_SESSION['publication_distribute_all']);
            ?>
            <?php if (is_array($distributeAllSummary)): ?>
                <div class="notice <?= (int)($distributeAllSummary['failed'] ?? 0) > 0 ? 'error' : '' ?>">
                    <strong><?= (int)($distributeAllSummary['sent'] ?? 0) ?> <?= pub_h(t('sent', 'enviados')) ?> · <?= (int)($distributeAllSummary['skipped'] ?? 0) ?> <?= pub_h(t('skipped', 'salteados')) ?> · <?= (int)($distributeAllSummary['failed'] ?? 0) ?> <?= pub_h(t('failed', 'fallidos')) ?></strong>
                    <?php foreach ((array)($distributeAllSummary['results'] ?? []) as $resultDestination => $result): ?>
                        <br><?= pub_h(ucfirst((string)$resultDestination)) ?>: <?= pub_h(match ((string)$result['status']) {
                            'sent' => t('sent', 'enviado'),
                            'skipped' => t('skipped', 'salteado'),
                            default => t('failed', 'falló'),
                        }) ?><?= trim((string)$result['detail']) !== '' ? ' — ' . pub_h((string)$result['detail']) : '' ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['dist'])): ?>
                <div class="notice"><?= pub_h(match ((string)$_GET['dist']) {
                    'pinterest' => t('Pin series published on Pinterest.', 'Serie de pins publicada en Pinterest.'),
                    'instagram' => t('Instagram series started: part 1 published, the rest scheduled.', 'Serie de Instagram iniciada: parte 1 publicada, el resto programado.'),
                    'facebook' => t('Facebook series started: part 1 published, the rest scheduled.', 'Serie de Facebook iniciada: parte 1 publicada, el resto programado.'),
                    'tiktok' => t('Video sent to TikTok — processing.', 'Video enviado a TikTok — procesando.'),
                    'tiktok_carousel' => t('Carousel sent: it is waiting in your TikTok inbox — pick the music and publish it there.', 'Carrusel enviado: te espera en tu bandeja de TikTok — elegí la música y publicalo desde ahí.'),
                    'tiktok_status' => t('TikTok status refreshed.', 'Estado de TikTok actualizado.'),
                    'saatchi' => t('Saatchi marked as uploaded by hand.', 'Saatchi marcado como cargado a mano.'),
                    'settings' => t('Series gap saved.', 'Lapso de la serie guardado.'),
                    'all' => t('Distribution finished — the detail is above.', 'Distribución terminada — el detalle está arriba.'),
                    default => t('Distribution updated.', 'Distribución actualizada.'),
                }) ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['saved'])): ?>
                <div class="notice"><?= pub_h(t('Artwork', 'Obra')) ?> <?= pub_h((string)$_GET['saved']) ?>.<?php if ((int)($_GET['cascade_count'] ?? 0) > 0): ?> <?= pub_h(t('Approved text published;', 'Texto aprobado publicado;')) ?> <?= (int)$_GET['cascade_count'] ?> <?= pub_h(t('mockups refreshing in the background.', 'mockups actualizándose en segundo plano.')) ?><?php endif; ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['video_warning'])): ?>
                <div class="notice error"><?= pub_h(t('Video:', 'Video:')) ?> <?= pub_h((string)$_GET['video_warning']) ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="notice error"><?= pub_h((string)$_GET['error']) ?></div>
            <?php endif; ?>

            <?php if ($doc === null): ?>
                <header class="pub-heading">
                    <div>
                        <span class="pub-kicker"><?= pub_h(t('Publish', 'Publicar')) ?></span>
                        <h1><?= pub_h(t('Publication', 'Publicación')) ?></h1>
                        <p class="pub-heading-desc"><?= pub_h(t('Each resolved artwork goes through here: website first — commercial data and selected media — then distribution. One single door for publishing.', 'Cada obra resuelta pasa por acá: primero el sitio web — datos comerciales y media seleccionada — después la distribución. Una sola puerta para publicar.')) ?></p>
                    </div>
                </header>

                <?php if (!$indexArtworks): ?>
                    <div class="pub-index-empty"><?= pub_h(t('No artworks ready yet. Finish an artwork first (root image selected).', 'Todavía no hay obras listas. Terminá primero una obra (imagen raíz seleccionada).')) ?></div>
                <?php else: ?>
                    <div class="pub-index-grid">
                        <?php foreach ($indexArtworks as $entry): ?>
                            <?php [$pageChipClass, $pageChipLabel] = pub_page_chip($entry['pageStatus']); ?>
                            <a class="pub-index-card" href="publication.php?id=<?= (int)$entry['id'] ?>">
                                <img src="<?= pub_h(pub_media_url($entry['file'])) ?>" alt="" loading="lazy">
                                <span class="pub-index-card-body">
                                    <h2><?= pub_h($entry['title']) ?></h2>
                                    <?php if ($entry['series'] !== ''): ?><span><?= pub_h($entry['series']) ?></span><?php endif; ?>
                                    <span class="pub-index-states">
                                        <?php if ($entry['resolved']): ?>
                                            <em class="pub-chip pub-chip--ok"><?= pub_h(t('Artwork resolved', 'Obra resuelta')) ?></em>
                                        <?php else: ?>
                                            <em class="pub-chip pub-chip--pending"><?= pub_h(t('Editorial pending', 'Editorial pendiente')) ?></em>
                                        <?php endif; ?>
                                        <em class="<?= pub_h($pageChipClass) ?>"><?= pub_h($pageChipLabel) ?></em>
                                        <?php if ($entry['hasVideo']): ?><em class="pub-chip pub-chip--ok"><?= pub_h(t('Video on page', 'Video en página')) ?></em><?php endif; ?>
                                        <?php if ($entry['saleActive']): ?><em class="pub-chip pub-chip--live"><?= pub_h(t('For sale', 'A la venta')) ?></em><?php endif; ?>
                                    </span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <header class="pub-doc-header">
                    <img src="<?= pub_h(pub_media_url($doc['rootFile'])) ?>" alt="">
                    <div class="pub-doc-header-body">
                        <span class="pub-kicker"><?= pub_h(t('Publication · working document', 'Publicación · documento de trabajo')) ?></span>
                        <h1><?= pub_h($doc['title']) ?></h1>
                        <p>
                            <?php $meta = array_filter([$doc['series'], $doc['medium'], $doc['sizeText'], $doc['year']]); ?>
                            <?= pub_h(implode(' · ', $meta)) ?>
                        </p>
                    </div>
                    <div class="pub-doc-header-aside">
                        <a class="pub-doc-back" href="publication.php">← <?= pub_h(t('All artworks', 'Todas las obras')) ?></a>
                        <?php if ($doc['resolved']): ?>
                            <em class="pub-chip pub-chip--ok"><?= pub_h(t('Artwork resolved', 'Obra resuelta')) ?></em>
                        <?php else: ?>
                            <em class="pub-chip pub-chip--pending"><?= pub_h(t('Editorial pending', 'Editorial pendiente')) ?></em>
                        <?php endif; ?>
                        <?php [$pageChipClass, $pageChipLabel] = pub_page_chip($doc['pageStatus']); ?>
                        <em class="<?= pub_h($pageChipClass) ?>"><?= pub_h($pageChipLabel) ?></em>
                    </div>
                </header>

                <section class="pub-panel <?= $doc['resolved'] ? '' : 'pub-panel--inert' ?>" aria-labelledby="pub-panel-website">
                    <div class="pub-panel-heading">
                        <div>
                            <span class="pub-kicker"><?= pub_h(t('Step 1', 'Paso 1')) ?></span>
                            <h2 id="pub-panel-website"><?= pub_h(t('Website', 'Sitio web')) ?></h2>
                        </div>
                    </div>
                    <div class="pub-panel-body">
                        <?php if (!$doc['resolved']): ?>
                            <p class="pub-gate-note"><?= pub_h(t('This artwork is not resolved yet: generate its editorial content in the artwork file first. Publishing starts from a resolved artwork.', 'Esta obra todavía no está resuelta: generá primero su contenido editorial en la ficha de obra. La publicación arranca desde una obra resuelta.')) ?> <a href="artwork.php?id=<?= (int)$doc['artwork']['id'] ?>"><?= pub_h(t('Open artwork file', 'Abrir ficha de obra')) ?> →</a></p>
                        <?php else: ?>
                            <form method="post" data-publication-form>
                                <input type="hidden" name="action" value="save_website">
                                <input type="hidden" name="id" value="<?= (int)$doc['artwork']['id'] ?>">
                                <input type="hidden" name="csrf" value="<?= pub_h($csrf) ?>">
                                <input type="hidden" name="selected_mockups" value="<?= pub_h(implode(',', $doc['selectionOrder'])) ?>" data-selected-mockups>
                                <input type="hidden" name="video_export_id" value="<?= (int)$doc['attachedVideoId'] ?>" data-selected-video>

                                <p class="pub-panel-note"><?= pub_h(t('Texts, SEO and captions come from the resolved artwork — they are not edited here. Here you compose what the page shows and how it sells.', 'Los textos, el SEO y los pies vienen de la obra resuelta — acá no se editan. Acá se compone qué muestra la página y cómo vende.')) ?></p>

                                <?php
                                // Una sola función, una sola línea: estado, archivo y acción
                                // conviven en la misma fila, sin cajas que compitan.
                                $videoAttach = static function (string $lead): string {
                                    return '<div class="pub-video-attach">'
                                        . ($lead !== '' ? '<span class="pub-video-attach-lead">' . pub_h($lead) . '</span>' : '')
                                        . '<input type="file" id="pub-video-file" name="video" form="pub-video-upload" accept="video/mp4,video/quicktime,video/webm" required class="pub-visually-hidden" data-video-file>'
                                        . '<label class="pub-copy" for="pub-video-file">' . pub_h(t('Choose file', 'Elegir archivo')) . '</label>'
                                        . '<span data-video-file-name>' . pub_h(t('MP4, MOV or WebM · up to 500 MB', 'MP4, MOV o WebM · hasta 500 MB')) . '</span>'
                                        . '<button type="submit" form="pub-video-upload" class="pub-copy" data-video-upload-submit>' . pub_h(t('Upload', 'Subir')) . '</button>'
                                        . '</div>';
                                };
                                ?>
                                <h3 class="pub-section-title"><?= pub_h(t('Media composition', 'Composición de media')) ?></h3>
                                <p class="pub-section-hint"><?= pub_h(t('The video leads the page, then the included mockups in the order below — drag them to reorder. With none included, it shows all of them.', 'El video abre la página y despues van los mockups incluidos, en el orden de abajo — arrastralos para reordenar. Sin ninguno incluido, los muestra todos.')) ?></p>
                                <?php if (!$doc['finalVideos'] && !$doc['mediaGrid']): ?>
                                    <p class="pub-video-empty"><?= pub_h(t('This artwork has no video or mockups yet.', 'Esta obra todavía no tiene video ni mockups.')) ?></p>
                                <?php else: ?>
                                    <div class="pub-media-grid" data-media-grid
                                        data-label-cover="<?= pub_h(t('Cover', 'Portada')) ?>"
                                        data-label-lead="<?= pub_h(t('Opens post', 'Abre post')) ?>">
                                        <?php foreach ($doc['finalVideos'] as $finalVideo): ?>
                                            <?php $videoSelected = (int)$finalVideo['id'] === (int)$doc['attachedVideoId']; ?>
                                            <figure class="pub-video-card <?= $videoSelected ? 'is-selected' : '' ?>" data-video-card data-video-id="<?= (int)$finalVideo['id'] ?>">
                                                <?php $poster = (string)($finalVideo['thumbnailUrl'] ?? ''); ?>
                                                <?php if ($poster !== ''): ?><img src="<?= pub_h($poster) ?>" alt="" loading="lazy"><?php endif; ?>
                                                <figcaption><?= pub_h(trim((string)($finalVideo['displayTitle'] ?? '')) ?: t('Final video', 'Video final')) ?></figcaption>
                                                <small><?= pub_h((string)($finalVideo['aspectRatio'] ?? '')) ?></small>
                                                <button type="button" class="pub-media-toggle" data-video-toggle
                                                    data-label-add="<?= pub_h(t('Show on page', 'Mostrar en la página')) ?>"
                                                    data-label-remove="<?= pub_h(t('On page · remove', 'En la página · quitar')) ?>"><?= $videoSelected ? pub_h(t('On page · remove', 'En la página · quitar')) : pub_h(t('Show on page', 'Mostrar en la página')) ?></button>
                                            </figure>
                                        <?php endforeach; ?>
                                        <?php foreach ($doc['mediaGrid'] as $card): ?>
                                            <?php $isSelected = in_array($card['id'], $doc['selectionOrder'], true); ?>
                                            <figure class="pub-media-card <?= $isSelected ? 'is-selected' : '' ?>" data-media-card data-mockup-id="<?= (int)$card['id'] ?>">
                                                <span class="pub-media-order" data-media-order></span>
                                                <span class="pub-media-cover" data-media-cover></span>
                                                <img src="<?= pub_h(pub_media_url($card['file'])) ?>" alt="<?= pub_h($card['label']) ?>" loading="lazy">
                                                <figcaption><?= pub_h($card['label']) ?></figcaption>
                                                <button type="button" class="pub-media-toggle" data-media-toggle
                                                    data-label-add="<?= pub_h(t('Include on page', 'Incluir en la página')) ?>"
                                                    data-label-remove="<?= pub_h(t('Included · remove', 'Incluido · quitar')) ?>"><?= $isSelected ? pub_h(t('Included · remove', 'Incluido · quitar')) : pub_h(t('Include on page', 'Incluir en la página')) ?></button>
                                            </figure>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?= $videoAttach($doc['finalVideos'] ? '' : t('No final video linked to this artwork yet.', 'Todavía no hay video final vinculado a esta obra.')) ?>

                                <h3 class="pub-section-title"><?= pub_h(t('Price and availability', 'Precio y disponibilidad')) ?></h3>
                                <div class="pub-sale-grid">
                                    <div class="pub-field">
                                        <span><?= pub_h(t('Availability', 'Disponibilidad')) ?></span>
                                        <div class="pub-chip-group" role="radiogroup" aria-label="<?= pub_h(t('Availability', 'Disponibilidad')) ?>">
                                            <?php foreach (['draft' => t('Not for sale', 'No a la venta'), 'active' => t('Available', 'Disponible'), 'paused' => t('Paused', 'Pausada'), 'sold_out' => t('Sold', 'Vendida')] as $value => $label): ?>
                                                <label class="pub-chip-option">
                                                    <input type="radio" name="sale_status" value="<?= pub_h($value) ?>" <?= (string)($doc['sale']['status'] ?? 'draft') === $value ? 'checked' : '' ?>>
                                                    <span><?= pub_h($label) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <label class="pub-field"><?= pub_h(t('Available units', 'Unidades disponibles')) ?><input type="number" min="0" step="1" name="sale_stock" value="<?= (int)$doc['saleAvailable'] ?>"></label>
                                    <?php // La divisa manda en la tienda: acá sólo se nombra junto al precio, no se edita. ?>
                                    <label class="pub-field"><?= pub_h(t('Price', 'Precio')) ?> (<?= pub_h((string)$doc['storeCurrency']) ?>)<input inputmode="decimal" name="sale_price" value="<?= $doc['sale'] ? pub_h(number_format((int)$doc['sale']['price_minor'] / 100, 2, '.', '')) : '' ?>" placeholder="2500.00"></label>
                                    <label class="pub-field"><?= pub_h(t('Constellation country', 'País de constelación')) ?><input name="constellation_country" value="<?= pub_h($doc['constellationCountry']) ?>" placeholder="<?= pub_h(t('Optional', 'Opcional')) ?>"></label>
                                    <div class="pub-field">
                                        <span><?= pub_h(t('Visibility', 'Visibilidad')) ?></span>
                                        <div class="pub-chip-group" role="radiogroup" aria-label="<?= pub_h(t('Visibility', 'Visibilidad')) ?>">
                                            <?php foreach (['public' => t('Public', 'Pública'), 'unlisted' => t('Unlisted', 'No listada'), 'private' => t('Private', 'Privada')] as $value => $label): ?>
                                                <label class="pub-chip-option">
                                                    <input type="radio" name="visibility" value="<?= pub_h($value) ?>" <?= $doc['visibility'] === $value ? 'checked' : '' ?>>
                                                    <span><?= pub_h($label) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <label class="pub-field" style="margin-top:16px;">
                                    <?= pub_h(t('Saatchi Art listing link', 'Enlace del listing en Saatchi Art')) ?>
                                    <input type="url" name="saatchi_url" value="<?= pub_h($doc['saatchiUrl']) ?>" placeholder="https://www.saatchiart.com/art/...">
                                    <small><?= pub_h(t('When set, the public artwork page shows "Buy on Saatchi Art" as the primary action.', 'Si se completa, la ficha pública muestra "Comprar en Saatchi Art" como acción principal.')) ?></small>
                                </label>
                                <div class="pub-shipping">
                                    <span><?= pub_h(t('Shipping uses the editable rates by continent for the whole store.', 'El envío usa las tarifas editables por continente para toda la tienda.')) ?></span>
                                    <a href="../site-admin/?area=store&amp;section=shipping"><?= pub_h(t('Edit shipping rates', 'Editar tarifas de envío')) ?></a>
                                </div>

                                <div class="pub-actions">
                                    <button class="pub-decision pub-decision--save" type="submit" name="website_intent" value="save"><span><?= pub_h(t('Save', 'Guardar')) ?><br><?= pub_h(t('Website', 'Sitio Web')) ?></span></button>
                                    <?php if ($doc['pageStatus'] === 'published'): ?>
                                        <button class="pub-decision pub-decision--unpublish" type="submit" name="website_intent" value="unpublish"><span><?= pub_h(t('Unpublish', 'Despublicar')) ?><br><?= pub_h(t('Artwork', 'Obra')) ?></span></button>
                                    <?php else: ?>
                                        <button class="pub-decision pub-decision--publish" type="submit" name="website_intent" value="publish"><span><?= pub_h(t('Publish', 'Publicar')) ?><br><?= pub_h(t('Artwork', 'Obra')) ?></span></button>
                                    <?php endif; ?>
                                </div>
                            </form>
                            <form method="post" id="pub-video-upload" enctype="multipart/form-data" hidden>
                                <input type="hidden" name="action" value="upload_final_video">
                                <input type="hidden" name="id" value="<?= (int)$doc['artwork']['id'] ?>">
                                <input type="hidden" name="csrf" value="<?= pub_h($csrf) ?>">
                            </form>
                        <?php endif; ?>
                    </div>
                </section>

                <?php
                $productPayload = $doc['productPayload'];
                $stepReady = $productPayload !== null;
                $stepGateNote = $doc['pageStatus'] !== 'published'
                    ? t('Publish the artwork page first (Website step).', 'Publicá primero la página de la obra (paso Sitio web).')
                    : $doc['productGateMessage'];
                $productLocales = [];
                $destinationsPayload = [];
                $mediaItemsPayload = [];
                $distDefaultLocale = $doc['workingLocale'];
                if ($stepReady) {
                    $productLocales = array_values(array_unique(array_merge(
                        [(string)($productPayload['locales']['working'] ?? $doc['workingLocale'])],
                        (array)($productPayload['locales']['adaptations'] ?? [])
                    )));
                    $destinationsPayload = (array)($productPayload['destinations'] ?? []);
                    $mediaItemsPayload = (array)($productPayload['media']['items'] ?? []);
                    $distDefaultLocale = PublicationDistributionService::defaultLocale($productPayload);
                }
                $localeTag = static function (string $locale) use ($doc): string {
                    return strtoupper($locale) . ' · ' . ($locale === $doc['workingLocale'] ? t('source', 'fuente') : t('publication', 'publicación'));
                };
                $localeOptions = $productLocales !== [] ? $productLocales : [$doc['workingLocale']];
                // Los pasos van plegados para dar orden; el que acaba de actuar
                // se abre solo, para que el resultado nunca quede escondido.
                $openStep = match ((string)($_GET['dist'] ?? '')) {
                    'pinterest' => 'pinterest',
                    'instagram', 'facebook', 'instagram_video', 'facebook_video', 'x', 'x_video', 'settings' => 'social',
                    'tiktok', 'tiktok_carousel', 'tiktok_status' => 'tiktok',
                    'saatchi' => 'saatchi',
                    default => '',
                };
                ?>

                <details class="pub-panel <?= $stepReady ? '' : 'pub-panel--inert' ?>" id="pub-step-saatchi" <?= $openStep === 'saatchi' ? 'open' : '' ?>>
                    <summary class="pub-panel-heading">
                        <div>
                            <span class="pub-kicker"><?= pub_h(t('Step 2', 'Paso 2')) ?></span>
                            <h2><?= pub_h(t('Saatchi Art', 'Saatchi Art')) ?></h2>
                        </div>
                        <?php
                        $saatchiState = (array)($doc['distStates']['saatchi'] ?? []);
                        [$saatchiChipClass, $saatchiChipLabel] = pub_dist_chip('saatchi', $saatchiState);
                        ?>
                        <span class="pub-dist-chips">
                            <em class="pub-chip"><?= pub_h(t('Manual', 'Manual')) ?></em>
                            <em class="<?= pub_h($saatchiChipClass) ?>"><?= pub_h($saatchiChipLabel) ?></em>
                        </span>
                    </summary>
                    <div class="pub-panel-body">
                        <?php if (!$stepReady): ?>
                            <p class="pub-gate-note"><?= pub_h($stepGateNote) ?></p>
                        <?php else: ?>
                            <?php
                            // Una obra listada no consume su paquete: sigue siendo
                            // material de consulta para corregir o volver a subir.
                            $saatchiListed = ($saatchiState['status'] ?? '') === 'listed';
                            $saatchiPackage = (array)($destinationsPayload['saatchi'] ?? []);
                            $saatchiKeywords = (array)($saatchiPackage['keywords'] ?? []);
                            $saatchiDescriptionLocale = $doc['adaptationLocale'] !== '' ? $doc['adaptationLocale'] : $doc['workingLocale'];
                            $saatchiDescription = (string)($saatchiPackage['description'][$saatchiDescriptionLocale] ?? '');
                            $saatchiTitle = (string)($saatchiPackage['title'][$saatchiDescriptionLocale] ?? '');
                            if ($saatchiTitle === '') {
                                $saatchiTitle = (string)($saatchiPackage['title'][$doc['workingLocale']] ?? '');
                            }
                            ?>
                            <?php if ($saatchiListed): ?>
                                <p class="pub-product-meta"><a href="<?= pub_h((string)$saatchiState['external_url']) ?>" target="_blank" rel="noopener"><?= pub_h(t('View listing →', 'Ver listing →')) ?></a></p>
                                <details class="pub-dist-package-fold">
                                    <summary class="pub-copy"><?= pub_h(t('Show the package again', 'Ver el paquete de nuevo')) ?></summary>
                            <?php else: ?>
                                <p class="pub-panel-note"><?= pub_h(t('Download the package, upload it by hand on Saatchi, mark it here, then paste the listing link in the Website step.', 'Descargá el paquete, cargalo a mano en Saatchi, marcalo acá, y después pegá el enlace del listing en el paso Sitio web.')) ?></p>
                            <?php endif; ?>
                            <div class="pub-dist-package">
                                <?php if ($saatchiTitle !== ''): ?>
                                    <div class="pub-dist-package-row">
                                        <span class="pub-product-locale"><?= pub_h(t('Title', 'Título')) ?> (<?= (int)mb_strlen($saatchiTitle) ?>/65)</span>
                                        <button type="button" class="pub-copy" data-copy-text="<?= pub_h($saatchiTitle) ?>"><?= pub_h(t('Copy', 'Copiar')) ?></button>
                                    </div>
                                    <p class="pub-product-text"><?= pub_h($saatchiTitle) ?></p>
                                <?php endif; ?>
                                <div class="pub-dist-package-row">
                                    <span class="pub-product-locale"><?= pub_h(t('Keywords', 'Keywords')) ?> (<?= count($saatchiKeywords) ?>/12)</span>
                                    <button type="button" class="pub-copy" data-copy-text="<?= pub_h(implode(', ', array_map('strval', $saatchiKeywords))) ?>"><?= pub_h(t('Copy', 'Copiar')) ?></button>
                                </div>
                                <div class="pub-product-keywords"><?php foreach ($saatchiKeywords as $keyword): ?><em class="pub-chip"><?= pub_h((string)$keyword) ?></em><?php endforeach; ?></div>
                                <div class="pub-dist-package-row">
                                    <span class="pub-product-locale"><?= pub_h(t('Description', 'Descripción')) ?> (<?= pub_h(strtoupper($saatchiDescriptionLocale)) ?>)</span>
                                    <button type="button" class="pub-copy" data-copy-text="<?= pub_h($saatchiDescription) ?>"><?= pub_h(t('Copy', 'Copiar')) ?></button>
                                </div>
                                <p class="pub-dist-package-text"><?= nl2br(pub_h($saatchiDescription)) ?></p>
                                <div class="pub-dist-package-images">
                                    <?php foreach ((array)($saatchiPackage['images'] ?? []) as $packageImage): ?>
                                        <?php $imageCaption = (string)($packageImage['caption'][$saatchiDescriptionLocale] ?? ''); ?>
                                        <figure>
                                            <img src="<?= pub_h(pub_media_url((string)($packageImage['file'] ?? ''))) ?>" alt="" loading="lazy">
                                            <figcaption><?= pub_h($imageCaption) ?></figcaption>
                                            <button type="button" class="pub-copy" data-copy-text="<?= pub_h($imageCaption) ?>"><?= pub_h(t('Copy caption', 'Copiar pie')) ?></button>
                                        </figure>
                                    <?php endforeach; ?>
                                    <a class="pub-decision pub-decision--save pub-dist-download" href="publication_saatchi_package.php?id=<?= (int)$doc['artwork']['id'] ?>">
                                        <span><?= pub_h(t('Download', 'Descargar')) ?><br><?= pub_h(t('package .zip', 'paquete .zip')) ?></span>
                                    </a>
                                </div>
                                <?php if (count((array)($saatchiPackage['images'] ?? [])) < 4): ?>
                                    <p class="pub-product-meta"><small><?= pub_h(t('Saatchi recommends 4+ varied images: front, detail, mockups.', 'Saatchi recomienda 4+ imágenes variadas: frente, detalle, mockups.')) ?></small></p>
                                <?php endif; ?>
                            </div>
                            <?php if ($saatchiListed): ?></details><?php endif; ?>
                            <?php if (!$saatchiListed && ($saatchiState['status'] ?? '') !== 'uploaded'): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="saatchi_uploaded">
                                    <input type="hidden" name="id" value="<?= (int)$doc['artwork']['id'] ?>">
                                    <input type="hidden" name="csrf" value="<?= pub_h($csrf) ?>">
                                    <button type="submit" class="pub-media-toggle pub-product-regenerate"><?= pub_h(t('Mark as uploaded by hand', 'Marcar como cargado a mano')) ?></button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </details>

                <details class="pub-panel <?= $stepReady ? '' : 'pub-panel--inert' ?>" id="pub-step-pinterest" <?= $openStep === 'pinterest' ? 'open' : '' ?>>
                    <summary class="pub-panel-heading">
                        <div>
                            <span class="pub-kicker"><?= pub_h(t('Step 3', 'Paso 3')) ?></span>
                            <h2>Pinterest</h2>
                        </div>
                        <?php
                        $pinState = (array)($doc['distStates']['pinterest'] ?? []);
                        $pinConnected = ($pinState['connection'] ?? '') === 'connected';
                        [$pinChipClass, $pinChipLabel] = pub_dist_chip('pinterest', $pinState);
                        ?>
                        <span class="pub-dist-chips">
                            <?php if ($pinConnected): ?><em class="pub-chip pub-chip--ok"><?= pub_h(t('Connected', 'Conectado')) ?></em>
                            <?php else: ?><em class="pub-chip pub-chip--pending"><?= pub_h(t('Not connected', 'Sin conexión')) ?></em><?php endif; ?>
                            <em class="<?= pub_h($pinChipClass) ?>"><?= pub_h($pinChipLabel) ?></em>
                        </span>
                    </summary>
                    <div class="pub-panel-body">
                        <?php if (!$stepReady): ?>
                            <p class="pub-gate-note"><?= pub_h($stepGateNote) ?></p>
                        <?php else: ?>
                            <?php
                            $pinBoardName = trim((string)(((array)($destinationsPayload['pinterest'][$distDefaultLocale]['board_suggestions'] ?? []))[0] ?? '')) ?: t('Artworks', 'Obras');
                            $pinBoards = [];
                            $pinBoardsError = '';
                            $pinEnvironment = (string)($pinState['current_environment'] ?? '');
                            $pinRejectedBoardIds = array_map('strval', (array)($pinState['rejected_board_ids'] ?? []));
                            if ($pinConnected) {
                                try {
                                    $pinBoards = (new PinterestIntegrationService($pdo))->publicationBoards($userId, 'artist', $pinRejectedBoardIds);
                                } catch (Throwable $pinBoardsException) {
                                    $pinBoardsError = $pinBoardsException->getMessage();
                                }
                            }
                            $pinPreviousBoardIds = !empty($pinState['requires_republish']) ? [] : (array)($pinState['board_ids'] ?? []);
                            $pinItemErrors = (array)($pinState['item_errors'] ?? []);
                            $pinPublishedItemKeys = array_fill_keys(array_map('strval', (array)($pinState['published_item_keys'] ?? [])), true);
                            $pinAvailableBoards = $pinBoards;
                            $pinRecommendedBoardId = '';
                            foreach ($pinAvailableBoards as $pinBoardCandidate) {
                                if (strcasecmp(trim((string)($pinBoardCandidate['name'] ?? '')), $pinBoardName) === 0) {
                                    $pinRecommendedBoardId = (string)($pinBoardCandidate['id'] ?? '');
                                    break;
                                }
                            }
                            if ($pinRecommendedBoardId === '' && isset($pinAvailableBoards[0])) $pinRecommendedBoardId = (string)($pinAvailableBoards[0]['id'] ?? '');
                            $pinCanChooseBoards = $pinConnected && $pinBoardsError === '' && $pinAvailableBoards !== [];
                            $pinSeriesComplete = empty($pinState['requires_republish'])
                                && count($mediaItemsPayload) > 0
                                && (int)($pinState['published_count'] ?? 0) >= count($mediaItemsPayload);
                            $pinDefaultLink = rtrim(app_env('ARTIST_WEBSITE_CATALOG_URL', 'https://mauriziovalch.com/artworks'), '/')
                                . '/' . rawurlencode((string)($productPayload['sources']['page']['slug'] ?? '')) . '/';
                            $pinDestinationLink = !empty($pinState['requires_republish'])
                                ? $pinDefaultLink
                                : (trim((string)($pinState['destination_link'] ?? '')) ?: $pinDefaultLink);
                            ?>
                            <p class="pub-panel-note"><?= pub_h(t('Publishes', 'Publica')) ?> <strong><?= count($mediaItemsPayload) ?> <?= pub_h(t('pins', 'pins')) ?></strong> — <?= pub_h(t('one per composition image, each with its own editorial copy and destination board. Choose the board on every Pin; the editorial suggestion is', 'uno por imagen de la composición, cada uno con su copy editorial propio y su tablero de destino. Elegí el tablero en cada Pin; la sugerencia editorial es')) ?> «<?= pub_h($pinBoardName) ?>».</p>
                            <label class="pub-pin-link">
                                <span><?= pub_h(t('Destination link for all Pins', 'Enlace de destino para los Pins')) ?></span>
                                <input type="url" name="pin_link" value="<?= pub_h($pinDestinationLink) ?>" <?= $pinSeriesComplete ? 'readonly' : 'form="pinterestPublishForm" required' ?> inputmode="url" autocomplete="url">
                                <small><?= pub_h(t('The ten Pins lead to this artwork page.', 'Los diez Pins conducen a esta página de la obra.')) ?></small>
                            </label>
                            <?php if (!empty($pinState['requires_republish'])): ?>
                                <p class="pub-panel-note"><strong><?= pub_h(t('Production publication pending.', 'Publicación en Production pendiente.')) ?></strong> <?= pub_h(t('The visible result belongs to Sandbox and will not be reused as a Production Pin.', 'El resultado visible pertenece a Sandbox y no se reutilizará como Pin de Production.')) ?></p>
                            <?php endif; ?>
                            <?php if ($pinRejectedBoardIds !== []): ?>
                                <p class="pub-panel-note"><strong><?= pub_h(t('Sandbox boards removed.', 'Tableros Sandbox retirados.')) ?></strong> <?= pub_h(t('Pinterest rejected these destinations in Production; they are no longer offered in the selectors.', 'Pinterest rechazó esos destinos en Production; ya no aparecen en los selectores.')) ?></p>
                            <?php endif; ?>
                            <?php if (!$pinSeriesComplete): ?>
                                <p class="pub-panel-note"><strong><?= pub_h(t('Production boards.', 'Tableros de Production.')) ?></strong> <?= pub_h(t('The selectors exclude Pinterest system destinations and boards rejected as Sandbox.', 'Los selectores excluyen destinos internos de Pinterest y tableros rechazados como Sandbox.')) ?></p>
                            <?php endif; ?>
                            <div class="pub-pin-strip" aria-label="<?= pub_h(t('Pin series preview', 'Vista previa de la serie de pins')) ?>">
                                <?php foreach ($mediaItemsPayload as $pinItem): ?>
                                    <?php
                                    $pinItemKey = (string)((int)($pinItem['mockup_sheet_id'] ?? 0));
                                    $pinItemBoardId = trim((string)($pinPreviousBoardIds[$pinItemKey] ?? ''));
                                    if ($pinItemBoardId === '' || in_array($pinItemBoardId, $pinRejectedBoardIds, true)) $pinItemBoardId = $pinRecommendedBoardId;
                                    $pinItemError = trim((string)($pinItemErrors[$pinItemKey] ?? ''));
                                    $pinSandboxError = str_contains(strtolower($pinItemError), 'pinterest code 15') && str_contains(strtolower($pinItemError), 'sandbox board');
                                    $pinItemPublished = isset($pinPublishedItemKeys[$pinItemKey]);
                                    ?>
                                    <figure class="pub-pin-card <?= $pinSandboxError ? 'pub-pin-card--error' : '' ?>">
                                        <img src="<?= pub_h(pub_media_url((string)($pinItem['file'] ?? ''))) ?>" alt="" loading="lazy">
                                        <?php
                                        // El preview muestra lo que realmente va a salir: un
                                        // envío, un idioma — igual que los pasos 4 y 5.
                                        $pinCopy = (array)($pinItem['social'][$distDefaultLocale]['pinterest'] ?? []);
                                        ?>
                                        <div class="pub-pin-copy">
                                            <span class="pub-product-locale"><?= pub_h(strtoupper($distDefaultLocale)) ?></span>
                                            <strong><?= pub_h((string)($pinCopy['title'] ?? '')) ?></strong>
                                            <p><?= pub_h((string)($pinCopy['description'] ?? '')) ?></p>
                                        </div>
                                        <?php if ($pinCanChooseBoards): ?>
                                            <label class="pub-pin-board">
                                                <span><?= $pinItemPublished ? pub_h(t('Published board', 'Tablero publicado')) : pub_h(t('Board', 'Tablero')) ?></span>
                                                <select <?= $pinItemPublished ? 'disabled' : 'name="pin_boards[' . pub_h($pinItemKey) . ']" form="pinterestPublishForm" required' ?>>
                                                    <?php foreach ($pinAvailableBoards as $pinBoard): ?>
                                                        <?php $pinBoardId = (string)($pinBoard['id'] ?? ''); ?>
                                                        <option value="<?= pub_h($pinBoardId) ?>" <?= $pinBoardId === $pinItemBoardId ? 'selected' : '' ?>><?= pub_h((string)($pinBoard['name'] ?? t('Untitled', 'Sin título'))) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                        <?php endif; ?>
                                        <?php if ($pinSandboxError): ?>
                                            <small class="pub-pin-card-error"><?= pub_h(t('This Pin was assigned to a Sandbox board. Choose another destination.', 'Este Pin estaba asignado a un tablero Sandbox. Elegí otro destino.')) ?></small>
                                        <?php endif; ?>
                                    </figure>
                                <?php endforeach; ?>
                            </div>
                            <?php if ((int)($pinState['total_count'] ?? 0) > 0): ?>
                                <p class="pub-product-meta"><small><?= (int)$pinState['published_count'] ?>/<?= (int)$pinState['total_count'] ?> <?= pub_h(t('pins published', 'pins publicados')) ?><?= ($pinState['api_environment'] ?? '') !== '' ? ' · ' . pub_h(strtoupper((string)$pinState['api_environment'])) : '' ?><?= (array)($pinState['board_names'] ?? []) !== [] ? ' · ' . count((array)$pinState['board_names']) . ' ' . pub_h(t('boards', 'tableros')) : '' ?></small>
                                <?php if (($pinState['external_url'] ?? '') !== ''): ?> · <a href="<?= pub_h((string)$pinState['external_url']) ?>" target="_blank" rel="noopener"><?= pub_h(t('View pin →', 'Ver pin →')) ?></a><?php endif; ?></p>
                            <?php endif; ?>
                            <?php if (($pinState['status'] ?? '') === 'failed' || ($pinState['status'] ?? '') === 'partial'): ?>
                                <p class="pub-dist-error"><?= pub_h((string)$pinState['error']) ?></p>
                            <?php endif; ?>
                            <?php if (!$pinConnected): ?>
                                <p class="pub-product-meta"><a href="connections.php"><?= pub_h(t('Open Connections →', 'Abrir Conexiones →')) ?></a></p>
                            <?php elseif ($pinBoardsError !== ''): ?>
                                <p class="pub-dist-error"><?= pub_h($pinBoardsError) ?></p>
                            <?php elseif ($pinSeriesComplete): ?>
                                <p class="pub-panel-note"><strong><?= pub_h(t('Series complete.', 'Serie completa.')) ?></strong> <?= pub_h(t('Published Pins are locked and cannot be sent again from the normal workflow.', 'Los Pines publicados quedan bloqueados y no pueden volver a enviarse desde el flujo normal.')) ?></p>
                            <?php elseif ($pinAvailableBoards === []): ?>
                                <p class="pub-product-meta"><?= pub_h(t('No eligible Production boards are available. Create a public board on Pinterest or reconnect before publishing.', 'No hay tableros elegibles de Production. Creá un tablero público en Pinterest o reconectá antes de publicar.')) ?></p>
                            <?php else: ?>
                                <form method="post" class="pub-dist-form" id="pinterestPublishForm">
                                    <input type="hidden" name="action" value="distribute">
                                    <input type="hidden" name="id" value="<?= (int)$doc['artwork']['id'] ?>">
                                    <input type="hidden" name="csrf" value="<?= pub_h($csrf) ?>">
                                    <input type="hidden" name="destination" value="pinterest">
                                    <input type="hidden" name="pinterest_publish_token" value="<?= pub_h($pinterestPublishToken) ?>">
                                    <p class="pub-product-meta"><strong><?= pub_h(t('One board per Pin', 'Un tablero por Pin')) ?></strong> · <?= pub_h(strtoupper($pinEnvironment ?: 'production')) ?></p>
                                    <div class="pub-chip-group" role="radiogroup" aria-label="<?= pub_h(t('Send language', 'Idioma del envío')) ?>">
                                        <?php foreach ($localeOptions as $localeOption): ?>
                                            <label class="pub-chip-option">
                                                <input type="radio" name="locale" value="<?= pub_h($localeOption) ?>" <?= $localeOption === $distDefaultLocale ? 'checked' : '' ?>>
                                                <span><?= pub_h($localeTag($localeOption)) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <label class="pub-dist-confirm">
                                        <input type="checkbox" name="confirm" value="yes" required>
                                        <span><?= pub_h(t('I confirm publishing the pin series now', 'Confirmo publicar ahora la serie de pins')) ?></span>
                                    </label>
                                    <button type="submit" class="button-link"><?= !empty($pinState['requires_republish']) ? pub_h(t('Publish Pins in Production', 'Publicar Pins en Production')) : (($pinState['status'] ?? '') === 'partial' ? pub_h(t('Retry missing pins', 'Reintentar pins faltantes')) : pub_h(t('Publish Pins', 'Publicar Pins'))) ?></button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </details>

                <details class="pub-panel <?= $stepReady ? '' : 'pub-panel--inert' ?>" id="pub-step-social" <?= $openStep === 'social' ? 'open' : '' ?>>
                    <summary class="pub-panel-heading">
                        <div>
                            <span class="pub-kicker"><?= pub_h(t('Step 4', 'Paso 4')) ?></span>
                            <h2><?= pub_h(t('Social', 'Social')) ?> — Facebook · Instagram · X</h2>
                        </div>
                        <?php if ($stepReady): ?>
                            <form method="post" class="pub-gap-form">
                                <input type="hidden" name="action" value="distribution_settings">
                                <input type="hidden" name="id" value="<?= (int)$doc['artwork']['id'] ?>">
                                <input type="hidden" name="csrf" value="<?= pub_h($csrf) ?>">
                                <label><?= pub_h(t('Gap between posts', 'Lapso entre posts')) ?>
                                    <input type="number" name="gap_hours" min="1" max="168" value="<?= (int)$doc['seriesGapHours'] ?>"> hs
                                </label>
                                <button type="submit" class="pub-copy"><?= pub_h(t('Save', 'Guardar')) ?></button>
                            </form>
                        <?php endif; ?>
                    </summary>
                    <div class="pub-panel-body">
                        <?php if (!$stepReady): ?>
                            <p class="pub-gate-note"><?= pub_h($stepGateNote) ?></p>
                        <?php else: ?>
                            <p class="pub-panel-note"><?= pub_h(t('«Publish spaced series» is one act: part 1 goes out now, the rest is scheduled with the gap — each post carries the editorial copy of its lead image.', '«Publicar serie espaciada» es un solo acto: la parte 1 sale ahora, el resto queda programado con el lapso — cada post lleva el copy editorial de su imagen líder.')) ?></p>
                            <div class="pub-product-grid">
                                <?php foreach (['facebook', 'instagram', 'x'] as $socialKey): ?>
                                    <?php
                                    $socialState = (array)($doc['distStates'][$socialKey] ?? []);
                                    $socialConnected = ($socialState['connection'] ?? '') === 'connected';
                                    [$socialChipClass, $socialChipLabel] = pub_dist_chip($socialKey, $socialState);
                                    $socialSeries = (array)($destinationsPayload[$socialKey]['series'] ?? []);
                                    $partStates = [];
                                    foreach ((array)($socialState['parts'] ?? []) as $partState) {
                                        $partStates[(int)$partState['part']] = $partState;
                                    }
                                    ?>
                                    <article class="pub-product-card">
                                        <div class="pub-dist-head">
                                            <h3><?= $socialKey === 'facebook' ? 'Facebook' : ($socialKey === 'x' ? 'X' : 'Instagram') ?></h3>
                                            <span class="pub-dist-chips">
                                                <?php if ($socialConnected): ?><em class="pub-chip pub-chip--ok"><?= pub_h(t('Connected', 'Conectado')) ?></em>
                                                <?php else: ?><em class="pub-chip pub-chip--pending"><?= pub_h(t('Not connected', 'Sin conexión')) ?></em><?php endif; ?>
                                                <em class="<?= pub_h($socialChipClass) ?>"><?= pub_h($socialChipLabel) ?></em>
                                            </span>
                                        </div>
                                        <?php foreach ($socialSeries as $post): ?>
                                            <?php
                                            $postPart = (int)($post['part'] ?? 0);
                                            $partState = $partStates[$postPart] ?? null;
                                            $partStatus = (string)($partState['status'] ?? '');
                                            $postBlock = (array)($post[$distDefaultLocale] ?? []);
                                            $scheduledTs = $partState !== null ? strtotime((string)$partState['scheduled_at']) : false;
                                            ?>
                                            <div class="pub-series-post">
                                                <div class="pub-series-thumbs">
                                                    <?php foreach ((array)($post['images'] ?? []) as $postImage): ?>
                                                        <img src="<?= pub_h(pub_media_url((string)$postImage)) ?>" alt="" loading="lazy">
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="pub-series-copy">
                                                    <span class="pub-product-locale"><?= pub_h(t('Post', 'Post')) ?> <?= $postPart ?></span>
                                                    <p><?= pub_h($socialKey === 'x'
                                                        ? trim((string)($postBlock['title'] ?? '') . (trim((string)($postBlock['phrase'] ?? '')) !== '' ? ' — ' . (string)$postBlock['phrase'] : ''))
                                                        : (string)($postBlock['composed'] ?? ($postBlock['headline'] ?? ''))) ?></p>
                                                    <span class="pub-series-state">
                                                        <?php if ($partStatus === 'published'): ?>
                                                            <em class="pub-chip pub-chip--live"><?= pub_h(t('PUBLISHED', 'PUBLICADO')) ?></em>
                                                            <?php if (($partState['external_url'] ?? '') !== ''): ?><a href="<?= pub_h((string)$partState['external_url']) ?>" target="_blank" rel="noopener">→</a><?php endif; ?>
                                                        <?php elseif ($partStatus === 'scheduled' || $partStatus === 'publishing'): ?>
                                                            <em class="pub-chip pub-chip--ok"><?= pub_h(t('SCHEDULED', 'PROGRAMADO')) ?><?= $scheduledTs ? ' · ' . pub_h(t('goes out', 'sale')) . ' ' . pub_h(date('d/m H:i', $scheduledTs)) : '' ?></em>
                                                        <?php elseif ($partStatus === 'failed'): ?>
                                                            <em class="pub-chip pub-chip--pending"><?= pub_h(t('FAILED', 'FALLÓ')) ?></em>
                                                        <?php endif; ?>
                                                        <?php if (in_array($partStatus, ['scheduled', 'failed'], true)): ?>
                                                            <form method="post" class="pub-series-now">
                                                                <input type="hidden" name="action" value="series_part_now">
                                                                <input type="hidden" name="id" value="<?= (int)$doc['artwork']['id'] ?>">
                                                                <input type="hidden" name="csrf" value="<?= pub_h($csrf) ?>">
                                                                <input type="hidden" name="destination" value="<?= pub_h($socialKey) ?>">
                                                                <input type="hidden" name="part" value="<?= $postPart ?>">
                                                                <button type="submit" class="pub-copy"><?= $partStatus === 'failed' ? pub_h(t('Retry', 'Reintentar')) : pub_h(t('Send now', 'Enviar ahora')) ?></button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </span>
                                                    <?php if ($partStatus === 'failed' && ($partState['error'] ?? '') !== ''): ?>
                                                        <p class="pub-dist-error"><?= pub_h((string)$partState['error']) ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php
                                        // El reel es un acto aparte: se manda solo,
                                        // sin volver a tocar la serie ya publicada.
                                        $reelKey = $socialKey . '_video';
                                        $reelState = (array)($doc['distStates'][$reelKey] ?? []);
                                        $reelStatus = (string)($reelState['status'] ?? '');
                                        $pageHasVideo = (int)($productPayload['media']['video']['export_id'] ?? 0);
                                        // PublicationDistributionService::META_VIDEO_DESTINATIONS is the source of
                                        // truth for who has a video destination — this list has to match it, or
                                        // the button reappears for a network the backend does not recognize.
                                        $reelSupported = in_array($socialKey, ['facebook', 'instagram', 'x'], true);
                                        ?>
                                        <?php if ($reelSupported && $socialConnected && $pageHasVideo > 0): ?>
                                            <div class="pub-series-post">
                                                <div class="pub-series-copy">
                                                    <span class="pub-product-locale"><?= pub_h(t('Page video', 'Video de la página')) ?></span>
                                                    <p><?= pub_h(match ($socialKey) {
                                                        'facebook' => t('Sent as a video post, on its own — it does not touch the series.', 'Se manda como post de video, aparte — no toca la serie.'),
                                                        'x' => t('Sent as a post with the video attached, on its own — it does not touch the series.', 'Se manda como post con el video adjunto, aparte — no toca la serie.'),
                                                        default => t('Sent as a Reel, on its own — it does not touch the series.', 'Se manda como Reel, aparte — no toca la serie.'),
                                                    }) ?></p>
                                                    <?php if ($reelStatus === 'published'): ?>
                                                        <span class="pub-series-state">
                                                            <em class="pub-chip pub-chip--live"><?= pub_h(t('PUBLISHED', 'PUBLICADO')) ?></em>
                                                            <?php if (($reelState['external_url'] ?? '') !== ''): ?><a href="<?= pub_h((string)$reelState['external_url']) ?>" target="_blank" rel="noopener">→</a><?php endif; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <?php if ($reelStatus === 'failed' && ($reelState['error'] ?? '') !== ''): ?>
                                                            <p class="pub-dist-error"><?= pub_h((string)$reelState['error']) ?></p>
                                                        <?php endif; ?>
                                                        <form method="post" class="pub-dist-form">
                                                            <input type="hidden" name="action" value="distribute">
                                                            <input type="hidden" name="id" value="<?= (int)$doc['artwork']['id'] ?>">
                                                            <input type="hidden" name="csrf" value="<?= pub_h($csrf) ?>">
                                                            <input type="hidden" name="destination" value="<?= pub_h($reelKey) ?>">
                                                            <div class="pub-chip-group" role="radiogroup" aria-label="<?= pub_h(t('Send language', 'Idioma del envío')) ?>">
                                                                <?php foreach ($localeOptions as $localeOption): ?>
                                                                    <label class="pub-chip-option">
                                                                        <input type="radio" name="locale" value="<?= pub_h($localeOption) ?>" <?= $localeOption === $distDefaultLocale ? 'checked' : '' ?>>
                                                                        <span><?= pub_h($localeTag($localeOption)) ?></span>
                                                                    </label>
                                                                <?php endforeach; ?>
                                                            </div>
                                                            <label class="pub-dist-confirm">
                                                                <input type="checkbox" name="confirm" value="yes" required>
                                                                <span><?= pub_h(match ($socialKey) {
                                                                    'facebook' => t('I confirm sending the page video to Facebook', 'Confirmo enviar el video de la página a Facebook'),
                                                                    'x' => t('I confirm sending the page video to X', 'Confirmo enviar el video de la página a X'),
                                                                    default => t('I confirm sending the page video as a Reel', 'Confirmo enviar el video de la página como Reel'),
                                                                }) ?></span>
                                                            </label>
                                                            <button type="submit" class="button-link"><?= $reelStatus === 'failed' ? pub_h(t('Retry video', 'Reintentar video')) : pub_h(t('Send video only', 'Enviar solo el video')) ?></button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!$socialConnected): ?>
                                            <p class="pub-product-meta"><a href="connections.php"><?= pub_h(t('Open Connections →', 'Abrir Conexiones →')) ?></a></p>
                                        <?php elseif ((int)($socialState['total_count'] ?? 0) === 0): ?>
                                            <form method="post" class="pub-dist-form">
                                                <input type="hidden" name="action" value="distribute">
                                                <input type="hidden" name="id" value="<?= (int)$doc['artwork']['id'] ?>">
                                                <input type="hidden" name="csrf" value="<?= pub_h($csrf) ?>">
                                                <input type="hidden" name="destination" value="<?= pub_h($socialKey) ?>">
                                                <div class="pub-chip-group" role="radiogroup" aria-label="<?= pub_h(t('Send language', 'Idioma del envío')) ?>">
                                                    <?php foreach ($localeOptions as $localeOption): ?>
                                                        <label class="pub-chip-option">
                                                            <input type="radio" name="locale" value="<?= pub_h($localeOption) ?>" <?= $localeOption === $distDefaultLocale ? 'checked' : '' ?>>
                                                            <span><?= pub_h($localeTag($localeOption)) ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                                <label class="pub-dist-confirm">
                                                    <input type="checkbox" name="confirm" value="yes" required>
                                                    <span><?= pub_h(t('I confirm publishing the spaced series on', 'Confirmo publicar la serie espaciada en')) ?> <?= $socialKey === 'facebook' ? 'Facebook' : ($socialKey === 'x' ? 'X' : 'Instagram') ?></span>
                                                </label>
                                                <button type="submit" class="button-link"><?= pub_h(t('Publish spaced series', 'Publicar serie espaciada')) ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>

                            </div>
                        <?php endif; ?>
                    </div>
                </details>

                <details class="pub-panel <?= $stepReady ? '' : 'pub-panel--inert' ?>" id="pub-step-tiktok" <?= $openStep === 'tiktok' ? 'open' : '' ?>>
                    <summary class="pub-panel-heading">
                        <div>
                            <span class="pub-kicker"><?= pub_h(t('Step 5', 'Paso 5')) ?></span>
                            <h2>TikTok</h2>
                        </div>
                        <?php
                        $tiktokState = (array)($doc['distStates']['tiktok'] ?? []);
                        $tiktokConnected = ($tiktokState['connection'] ?? '') === 'connected';
                        ?>
                        <span class="pub-dist-chips">
                            <?php if ($tiktokConnected): ?><em class="pub-chip pub-chip--ok"><?= pub_h(t('Connected', 'Conectado')) ?></em>
                            <?php else: ?><em class="pub-chip pub-chip--pending"><?= pub_h(t('Not connected', 'Sin conexión')) ?></em><?php endif; ?>
                        </span>
                    </summary>
                    <div class="pub-panel-body">
                        <?php if (!$stepReady): ?>
                            <p class="pub-gate-note"><?= pub_h($stepGateNote) ?></p>
                        <?php else: ?>
                            <?php
                            $tiktokVideoId = (int)($destinationsPayload['tiktok']['video_export_id'] ?? 0);
                            $carouselState = (array)($doc['distStates']['tiktok_carousel'] ?? []);
                            $carouselImages = (array)($destinationsPayload['tiktok']['carousel_images'] ?? []);
                            $carouselCopy = (array)($destinationsPayload['tiktok'][$distDefaultLocale]['carousel'] ?? []);
                            ?>
                            <p class="pub-panel-note"><?= pub_h(t('Two media that coexist: the page video and an image carousel. Publishing one never replaces the other.', 'Dos medios que conviven: el video de la página y un carrusel de imágenes. Publicar uno jamás reemplaza al otro.')) ?></p>
                            <div class="pub-product-grid">
                                <?php foreach (['video', 'carousel'] as $tiktokMedium): ?>
                                    <?php
                                    $isCarouselCard = $tiktokMedium === 'carousel';
                                    $mediumState = $isCarouselCard ? $carouselState : $tiktokState;
                                    $mediumStatus = (string)($mediumState['status'] ?? '');
                                    [$mediumChipClass, $mediumChipLabel] = pub_dist_chip($isCarouselCard ? 'tiktok_carousel' : 'tiktok', $mediumState);
                                    $mediumAttempted = strtotime((string)($mediumState['attempted_at'] ?? ''));
                                    ?>
                                    <article class="pub-product-card">
                                        <div class="pub-dist-head">
                                            <h3><?= $isCarouselCard ? pub_h(t('Carousel', 'Carrusel')) : pub_h(t('Video', 'Video')) ?></h3>
                                            <span class="pub-dist-chips"><em class="<?= pub_h($mediumChipClass) ?>"><?= pub_h($mediumChipLabel) ?></em></span>
                                        </div>
                                        <?php if ($isCarouselCard): ?>
                                            <p class="pub-product-meta"><?= pub_h(t('Goes to your TikTok inbox: you pick the music and publish it from the app.', 'Va a tu bandeja de TikTok: vos elegís la música y lo publicás desde la app.')) ?></p>
                                        <?php endif; ?>
                                        <?php if ($mediumAttempted): ?>
                                            <p class="pub-product-meta"><small><?= pub_h(t('Last send:', 'Último envío:')) ?> <?= pub_h(date('d/m/Y · H:i', $mediumAttempted)) ?></small></p>
                                        <?php endif; ?>
                                        <?php if ($mediumStatus === 'failed' && ($mediumState['error'] ?? '') !== ''): ?>
                                            <p class="pub-dist-error"><?= pub_h((string)$mediumState['error']) ?></p>
                                        <?php endif; ?>

                                        <?php
                                        // El material se muestra SIEMPRE — igual que en los pasos
                                        // 3 y 4. La conexión gatea la acción, jamás el contenido.
                                        $mediumHasMaterial = $isCarouselCard ? $carouselImages !== [] : $tiktokVideoId > 0;
                                        ?>
                                        <?php if ($isCarouselCard): ?>
                                            <?php if ($carouselImages === []): ?>
                                                <p class="pub-product-meta"><?= pub_h(t('The composition has no images yet.', 'La composición todavía no tiene imágenes.')) ?></p>
                                            <?php else: ?>
                                                <div class="pub-series-thumbs pub-tiktok-thumbs">
                                                    <?php foreach ($carouselImages as $carouselImage): ?>
                                                        <img src="<?= pub_h(pub_media_url((string)$carouselImage)) ?>" alt="" loading="lazy">
                                                    <?php endforeach; ?>
                                                </div>
                                                <?= pub_product_field(t('Title', 'Título'), (string)($carouselCopy['title'] ?? '')) ?>
                                                <?= pub_product_field(t('Description', 'Descripción'), (string)($carouselCopy['description'] ?? '')) ?>
                                            <?php endif; ?>
                                        <?php elseif ($tiktokVideoId <= 0): ?>
                                            <p class="pub-product-meta"><?= pub_h(t('No video on the page — attach one in the Website step. The carousel stays available meanwhile.', 'Sin video en la página — adjuntá uno en el paso Sitio web. El carrusel sigue disponible mientras tanto.')) ?></p>
                                        <?php else: ?>
                                            <p class="pub-dist-package-text"><?= nl2br(pub_h((string)($destinationsPayload['tiktok'][$distDefaultLocale]['caption'] ?? ''))) ?></p>
                                        <?php endif; ?>

                                        <?php if (in_array($mediumStatus, ['processing', 'inbox'], true)): ?>
                                            <?php if ($mediumStatus === 'inbox'): ?>
                                                <p class="pub-product-meta"><?= pub_h(t('Finish it in the TikTok app; check back here once you published it.', 'Terminalo en la app de TikTok; volvé acá a consultar cuando lo hayas publicado.')) ?></p>
                                            <?php endif; ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="tiktok_status">
                                                <input type="hidden" name="id" value="<?= (int)$doc['artwork']['id'] ?>">
                                                <input type="hidden" name="csrf" value="<?= pub_h($csrf) ?>">
                                                <input type="hidden" name="medium" value="<?= pub_h($tiktokMedium) ?>">
                                                <button type="submit" class="pub-media-toggle pub-product-regenerate"><?= pub_h(t('Check TikTok status', 'Consultar estado en TikTok')) ?></button>
                                            </form>
                                        <?php elseif (!$tiktokConnected): ?>
                                            <p class="pub-product-meta"><a href="connections.php"><?= pub_h(t('Open Connections →', 'Abrir Conexiones →')) ?></a></p>
                                        <?php elseif ($mediumStatus !== 'published' && $mediumHasMaterial): ?>
                                            <form method="post" class="pub-dist-form">
                                                <input type="hidden" name="action" value="distribute">
                                                <input type="hidden" name="id" value="<?= (int)$doc['artwork']['id'] ?>">
                                                <input type="hidden" name="csrf" value="<?= pub_h($csrf) ?>">
                                                <input type="hidden" name="destination" value="tiktok">
                                                <input type="hidden" name="medium" value="<?= pub_h($tiktokMedium) ?>">
                                                <div class="pub-chip-group" role="radiogroup" aria-label="<?= pub_h(t('Send language', 'Idioma del envío')) ?>">
                                                    <?php foreach ($localeOptions as $localeOption): ?>
                                                        <label class="pub-chip-option">
                                                            <input type="radio" name="locale" value="<?= pub_h($localeOption) ?>" <?= $localeOption === $distDefaultLocale ? 'checked' : '' ?>>
                                                            <span><?= pub_h($localeTag($localeOption)) ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                                <label class="pub-dist-confirm">
                                                    <input type="checkbox" name="confirm" value="yes" required>
                                                    <span><?= $isCarouselCard
                                                        ? pub_h(t('I confirm sending the carousel to my TikTok inbox', 'Confirmo enviar el carrusel a mi bandeja de TikTok'))
                                                        : pub_h(t('I confirm sending the video to TikTok now', 'Confirmo enviar ahora el video a TikTok')) ?></span>
                                                </label>
                                                <button type="submit" class="button-link"><?= $isCarouselCard
                                                    ? pub_h(t('Send carousel', 'Enviar carrusel'))
                                                    : pub_h(t('Send video', 'Enviar video')) ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>

                <?php if ($stepReady): ?>
                    <?php
                    // El control por paso se conserva; esto es el acto único que
                    // dispara todo lo conectado, cada destino con su mecánica.
                    $allDestinations = ['pinterest' => 'Pinterest', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'x' => 'X'];
                    $connectedNames = [];
                    $disconnectedNames = [];
                    foreach ($allDestinations as $key => $name) {
                        if ((($doc['distStates'][$key]['connection'] ?? '')) === 'connected') $connectedNames[] = $name;
                        else $disconnectedNames[] = $name;
                    }
                    ?>
                    <section class="pub-panel pub-all" aria-labelledby="pub-all-title">
                        <div class="pub-panel-heading">
                            <div>
                                <span class="pub-kicker"><?= pub_h(t('One act', 'Un solo acto')) ?></span>
                                <h2 id="pub-all-title"><?= pub_h(t('Distribute to everything connected', 'Distribuir a todo lo conectado')) ?></h2>
                            </div>
                        </div>
                        <div class="pub-panel-body">
                            <?php if ($connectedNames === []): ?>
                                <p class="pub-gate-note"><?= pub_h(t('No destination is connected yet.', 'Todavía no hay ningún destino conectado.')) ?> <a href="connections.php"><?= pub_h(t('Open Connections →', 'Abrir Conexiones →')) ?></a></p>
                            <?php else: ?>
                                <p class="pub-panel-note"><?= pub_h(t('Fires every connected destination with its own mechanics: the pin series, part 1 of each social series with its cadence, and the TikTok post. Destinations already sent are skipped, and one failure never stops the rest.', 'Dispara cada destino conectado con su propia mecánica: la serie de pins, la parte 1 de cada serie social con su cadencia, y la publicación de TikTok. Los destinos ya enviados se saltean, y un fallo nunca detiene al resto.')) ?></p>
                                <p class="pub-product-meta">
                                    <?= pub_h(t('Goes out to:', 'Sale a:')) ?> <strong><?= pub_h(implode(' · ', $connectedNames)) ?></strong>
                                    <?php if ($disconnectedNames !== []): ?><br><small><?= pub_h(t('Skipped for lack of connection:', 'Se saltea por falta de conexión:')) ?> <?= pub_h(implode(', ', $disconnectedNames)) ?></small><?php endif; ?>
                                    <br><small><?= pub_h(t('Saatchi Art stays out: it has no usable API and is uploaded by hand from its own step.', 'Saatchi Art queda afuera: no tiene API usable y se carga a mano desde su propio paso.')) ?></small>
                                </p>
                                <form method="post" class="pub-dist-form">
                                    <input type="hidden" name="action" value="distribute_all">
                                    <input type="hidden" name="id" value="<?= (int)$doc['artwork']['id'] ?>">
                                    <input type="hidden" name="csrf" value="<?= pub_h($csrf) ?>">
                                    <div class="pub-chip-group" role="radiogroup" aria-label="<?= pub_h(t('Send language', 'Idioma del envío')) ?>">
                                        <?php foreach ($localeOptions as $localeOption): ?>
                                            <label class="pub-chip-option">
                                                <input type="radio" name="locale" value="<?= pub_h($localeOption) ?>" <?= $localeOption === $distDefaultLocale ? 'checked' : '' ?>>
                                                <span><?= pub_h($localeTag($localeOption)) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <label class="pub-dist-confirm">
                                        <input type="checkbox" name="confirm" value="yes" required>
                                        <span><?= pub_h(t('I confirm distributing to every connected destination now', 'Confirmo distribuir ahora a todos los destinos conectados')) ?></span>
                                    </label>
                                    <button type="submit" class="pub-decision pub-decision--publish"><span><?= pub_h(t('Distribute', 'Distribuir')) ?><br><?= pub_h(t('to everything', 'a todo')) ?></span></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>
<script>
// El aviso de un envío viaja en la URL, así que cada recarga repetía un error
// ya resuelto y no había forma de sacárselo de encima. Se muestra una vez y la
// dirección queda limpia: recargar deja de mentir sobre el estado actual.
(() => {
    const url = new URL(window.location.href);
    if (['error', 'saved', 'video_warning', 'dist', 'cascade_count'].some(key => url.searchParams.has(key))) {
        ['error', 'saved', 'video_warning', 'cascade_count'].forEach(key => url.searchParams.delete(key));
        window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    }
})();
(() => {
    const form = document.querySelector('[data-publication-form]');
    if (!form) return;

    // Ordered mockup selection: click order = page order.
    const selectedInput = form.querySelector('[data-selected-mockups]');
    const grid = form.querySelector('[data-media-grid]');
    const cards = [...form.querySelectorAll('[data-media-card]')];
    const idOf = card => parseInt(card.dataset.mockupId, 10);
    let order = (selectedInput.value || '').split(',').map(v => parseInt(v, 10)).filter(v => v > 0);
    const render = () => {
        selectedInput.value = order.join(',');
        cards.forEach(card => {
            const position = order.indexOf(idOf(card));
            const selected = position !== -1;
            card.classList.toggle('is-selected', selected);
            // Las cabeceras de publicacion son las posiciones 1, 4 y 7: la serie
            // parte la composicion de a tres y cada grupo lo abre su primera
            // imagen (lo que pase de 9 se suma al post 3, sin mover cabeceras).
            // La 1 manda en todo: portada de la pagina y tapa del carrusel.
            const lead = selected && (position === 0 || position === 3 || position === 6);
            card.classList.toggle('is-cover', position === 0);
            card.classList.toggle('is-lead', lead);
            const flag = card.querySelector('[data-media-cover]');
            if (flag) {
                flag.textContent = position === 0
                    ? (grid?.dataset.labelCover || '')
                    : (lead ? `${grid?.dataset.labelLead || ''} ${Math.floor(position / 3) + 1}` : '');
            }
            // Solo lo incluido se arrastra: lo que esta afuera no tiene orden.
            card.draggable = selected;
            const badge = card.querySelector('[data-media-order]');
            if (badge) badge.textContent = selected ? String(position + 1) : '';
            const toggle = card.querySelector('[data-media-toggle]');
            if (toggle) toggle.textContent = selected ? toggle.dataset.labelRemove : toggle.dataset.labelAdd;
        });
        // La grilla se reordena para leerse como la pagina: primero lo incluido
        // en su orden, despues lo que quedo afuera.
        if (grid) {
            const byId = new Map(cards.map(card => [idOf(card), card]));
            order.forEach(id => { const card = byId.get(id); if (card) grid.appendChild(card); });
            cards.forEach(card => { if (order.indexOf(idOf(card)) === -1) grid.appendChild(card); });
        }
    };
    let dragging = 0;
    cards.forEach(card => {
        const toggle = card.querySelector('[data-media-toggle]');
        if (toggle) {
            toggle.addEventListener('click', () => {
                const id = idOf(card);
                const position = order.indexOf(id);
                if (position === -1) order.push(id); else order.splice(position, 1);
                render();
            });
        }
        card.addEventListener('dragstart', event => {
            dragging = idOf(card);
            if (order.indexOf(dragging) === -1) { event.preventDefault(); dragging = 0; return; }
            card.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(dragging));
        });
        card.addEventListener('dragend', () => { card.classList.remove('is-dragging'); dragging = 0; });
        card.addEventListener('dragover', event => {
            if (!dragging || dragging === idOf(card) || order.indexOf(idOf(card)) === -1) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        });
        card.addEventListener('drop', event => {
            event.preventDefault();
            const from = order.indexOf(dragging);
            const to = order.indexOf(idOf(card));
            if (from === -1 || to === -1 || from === to) return;
            order.splice(from, 1);
            order.splice(to, 0, dragging);
            render();
        });
    });
    render();

    // Single video selection, removable.
    const videoInput = form.querySelector('[data-selected-video]');
    const videoCards = [...form.querySelectorAll('[data-video-card]')];
    const renderVideos = () => {
        const current = parseInt(videoInput.value, 10) || 0;
        videoCards.forEach(card => {
            const id = parseInt(card.dataset.videoId, 10);
            const selected = id === current;
            card.classList.toggle('is-selected', selected);
            const toggle = card.querySelector('[data-video-toggle]');
            if (toggle) toggle.textContent = selected ? toggle.dataset.labelRemove : toggle.dataset.labelAdd;
        });
    };
    videoCards.forEach(card => {
        const toggle = card.querySelector('[data-video-toggle]');
        if (!toggle) return;
        toggle.addEventListener('click', () => {
            const id = parseInt(card.dataset.videoId, 10);
            videoInput.value = (parseInt(videoInput.value, 10) || 0) === id ? '0' : String(id);
            renderVideos();
        });
    });
    renderVideos();

    // The native file button breaks the page's tone, so the input is hidden and
    // driven by a label; picking a file reveals its name and the upload action.
    const videoFile = document.querySelector('[data-video-file]');
    if (videoFile) {
        const fileName = document.querySelector('[data-video-file-name]');
        const uploadSubmit = document.querySelector('[data-video-upload-submit]');
        const idleLabel = fileName ? fileName.textContent : '';
        videoFile.addEventListener('change', () => {
            const picked = videoFile.files && videoFile.files[0];
            if (fileName) fileName.textContent = picked ? picked.name : idleLabel;
            if (uploadSubmit) uploadSubmit.hidden = !picked;
        });
    }

    // Copy-to-clipboard for the Saatchi manual package fields.
    document.querySelectorAll('.pub-copy').forEach(button => {
        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(button.dataset.copyText || '');
                const original = button.textContent;
                button.textContent = button.dataset.copiedLabel || 'OK';
                window.setTimeout(() => { button.textContent = original; }, 1200);
            } catch (copyError) {
                button.textContent = '×';
            }
        });
    });

})();
</script>
</body>
</html>
