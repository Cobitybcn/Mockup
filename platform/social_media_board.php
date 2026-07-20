<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
FeatureAccess::requirePage($user, FeatureAccess::SOCIAL_MANAGE, 'Social Media Board');

$pdo = Database::connection();
$userId = (int)$user['id'];
Auth::start();
$_SESSION['social_media_board_csrf'] ??= bin2hex(random_bytes(32));
$pinterestIntegration = new PinterestIntegrationService($pdo);
$pinterestPurposes = [];
$pinterestEnvironments = [];
foreach ($isAdmin ? ['platform', 'artist'] : ['artist'] as $purpose) {
    $connection = $pinterestIntegration->connection($userId, $purpose);
    $environment = strtolower(trim(app_env('PINTEREST_API_ENVIRONMENT', 'production')));
    $pinterestEnvironments[$purpose] = $environment === 'sandbox' ? 'sandbox' : 'production';
    $pinterestPurposes[] = [
        'value' => $purpose,
        'label' => $purpose === 'platform' ? 'Artworks Mockups · @artworkmockups' : 'Cuenta Pinterest del artista',
        'connected' => $pinterestIntegration->isPublishingReady($userId, $purpose),
    ];
}
$defaultPinterestPurpose = 'artist';
if ($isAdmin) {
    foreach ($pinterestPurposes as $purposeOption) {
        if ($purposeOption['value'] === 'platform' && $purposeOption['connected']) {
            $defaultPinterestPurpose = 'platform';
            break;
        }
    }
}
$pinterestEnvironment = $pinterestEnvironments[$defaultPinterestPurpose] ?? 'production';
$pinterestSandbox = $pinterestEnvironment === 'sandbox';
$socialBoardConfig = [
    'csrf' => (string)$_SESSION['social_media_board_csrf'],
    'pinterest' => [
        'purpose' => $defaultPinterestPurpose,
        'purposes' => $pinterestPurposes,
        'environment' => $pinterestEnvironment,
        'environments' => $pinterestEnvironments,
    ],
    'destinations' => [
        'website' => rtrim(app_env('ARTIST_WEBSITE_CATALOG_URL', 'https://mauriziovalch.com/artworks'), '/'),
        'saatchi' => rtrim(app_env('SAATCHI_ARTIST_URL', 'https://www.saatchiart.com/mauriziovalch'), '/'),
    ],
];
$favoriteIds = MockupFavorites::idsForUser($userId);
$favoriteLookup = array_fill_keys($favoriteIds, true);
$favoritePosition = array_flip($favoriteIds);

function smb_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function smb_media_url(string $file, int $width = 720): string
{
    return 'media.php?file=' . rawurlencode(basename($file)) . '&thumb=1&w=' . max(320, min(1000, $width));
}

function smb_string_list(mixed $value): array
{
    if (is_array($value)) {
        $items = $value;
    } else {
        $decoded = json_decode((string)$value, true);
        $items = is_array($decoded) ? $decoded : (preg_split('/[,;|\n]+/', (string)$value) ?: []);
    }
    $items = array_values(array_filter(array_map(static fn (mixed $item): string => trim((string)$item), $items)));
    return array_values(array_unique($items));
}

$artworkStmt = $pdo->prepare("
    SELECT a.id,
           COALESCE(NULLIF(ag.title,''),NULLIF(a.final_title,''),CONCAT('Obra #',a.id)) AS display_title
    FROM artworks a
    LEFT JOIN artwork_groups ag
      ON ag.id=a.artwork_group_id
     AND ag.user_id=a.user_id
     AND ag.status='active'
    WHERE a.user_id=? AND a.status='done'
    ORDER BY display_title,a.id DESC
");
$artworkStmt->execute([$userId]);
$artworks = $artworkStmt->fetchAll(PDO::FETCH_ASSOC);

$seriesStmt = $pdo->prepare("SELECT id,title FROM artwork_series WHERE user_id=? AND status='active' ORDER BY title,id");
$seriesStmt->execute([$userId]);
$series = $seriesStmt->fetchAll(PDO::FETCH_ASSOC);

$sheetStmt = $pdo->prepare("
    SELECT ms.*
    FROM mockup_sheets ms
    INNER JOIN (
        SELECT MAX(id) AS id
        FROM mockup_sheets
        WHERE user_id=?
        GROUP BY mockup_file
    ) latest ON latest.id=ms.id
    WHERE ms.user_id=?
");
$sheetStmt->execute([$userId, $userId]);
$sheetByFile = [];
foreach ($sheetStmt->fetchAll(PDO::FETCH_ASSOC) as $sheet) {
    $sheetByFile[basename((string)$sheet['mockup_file'])] = $sheet;
}

$mockupStmt = $pdo->prepare("
    SELECT m.id,m.mockup_file,m.context_id,m.source_artwork_id,m.created_at,
           COALESCE(NULLIF(ag.title,''),NULLIF(a.final_title,''),CONCAT('Obra #',a.id)) AS artwork_title,
           COALESCE(m.series_id,a.series_id,0) AS series_id,
           COALESCE(NULLIF(s.title,''),NULLIF(a.series,''),'') AS series_title
    FROM mockups m
    LEFT JOIN artworks a ON a.id=m.source_artwork_id AND a.user_id=m.user_id
    LEFT JOIN artwork_groups ag ON ag.id=a.artwork_group_id AND ag.user_id=m.user_id AND ag.status='active'
    LEFT JOIN artwork_series s ON s.id=COALESCE(m.series_id,a.series_id) AND s.user_id=m.user_id
    WHERE m.user_id=?
    ORDER BY m.created_at DESC,m.id DESC
    LIMIT 160
");
$mockupStmt->execute([$userId]);
$mockups = $mockupStmt->fetchAll(PDO::FETCH_ASSOC);
usort($mockups, static function (array $left, array $right) use ($favoriteLookup, $favoritePosition): int {
    $leftId = (int)$left['id'];
    $rightId = (int)$right['id'];
    $leftFavorite = isset($favoriteLookup[$leftId]);
    $rightFavorite = isset($favoriteLookup[$rightId]);
    if ($leftFavorite !== $rightFavorite) return $leftFavorite ? -1 : 1;
    if ($leftFavorite && $rightFavorite) return ($favoritePosition[$leftId] ?? PHP_INT_MAX) <=> ($favoritePosition[$rightId] ?? PHP_INT_MAX);
    return strcmp((string)$right['created_at'], (string)$left['created_at']);
});

$mockupPayload = [];
foreach ($mockups as $mockup) {
    $id = (int)$mockup['id'];
    $file = basename((string)$mockup['mockup_file']);
    $sheet = $sheetByFile[$file] ?? [];
    $generated = json_decode((string)($sheet['generated_json'] ?? ''), true);
    $v2 = is_array($generated['mockup_analysis_v2'] ?? null) ? $generated['mockup_analysis_v2'] : [];
    $neutral = is_array($v2['neutral'] ?? null) ? $v2['neutral'] : [];
    $channels = is_array($v2['channels'] ?? null) ? $v2['channels'] : [];
    $pinterest = is_array($channels['pinterest'] ?? null) ? $channels['pinterest'] : [];
    $instagram = is_array($channels['instagram'] ?? null) ? $channels['instagram'] : [];
    $facebook = is_array($channels['facebook'] ?? null) ? $channels['facebook'] : [];
    $contextTitle = trim((string)($neutral['context_title'] ?? $sheet['title'] ?? ''));
    if ($contextTitle === '') $contextTitle = Display::contextTitle((string)$mockup['context_id']);
    $editorialTitle = trim((string)($sheet['title'] ?? ''));
    if ($editorialTitle === '') $editorialTitle = trim((string)$mockup['artwork_title']) . ' — ' . $contextTitle;
    $pinterestTitle = trim((string)($pinterest['title'] ?? ''));
    if ($pinterestTitle === '') $pinterestTitle = $editorialTitle;
    $mockupPayload[] = [
        'id' => $id,
        'image' => smb_media_url($file, 900),
        'artworkId' => (int)($mockup['source_artwork_id'] ?? 0),
        'artworkTitle' => (string)$mockup['artwork_title'],
        'seriesId' => (int)($mockup['series_id'] ?? 0),
        'seriesTitle' => (string)($mockup['series_title'] ?? ''),
        'contextTitle' => $contextTitle,
        'editorialTitle' => $editorialTitle,
        'favorite' => isset($favoriteLookup[$id]),
        'metadata' => [
            'description' => trim((string)($neutral['contextual_description'] ?? $sheet['description'] ?? '')),
            'caption' => trim((string)($neutral['caption'] ?? $sheet['caption'] ?? '')),
            'altText' => trim((string)($neutral['alt_text'] ?? $sheet['alt_text'] ?? '')),
            'keywords' => smb_string_list($neutral['keywords'] ?? $sheet['keywords'] ?? []),
            'tags' => smb_string_list($neutral['tags'] ?? $sheet['tags'] ?? []),
        ],
        'pinterest' => [
            'title' => $pinterestTitle,
            'description' => trim((string)($pinterest['description'] ?? $sheet['description'] ?? '')),
            'boards' => smb_string_list($pinterest['board_suggestions'] ?? []),
            'keywords' => smb_string_list($pinterest['keywords'] ?? $sheet['keywords'] ?? []),
        ],
        'instagram' => [
            'hook' => trim((string)($instagram['hook'] ?? '')),
            'caption' => trim((string)($instagram['caption'] ?? $sheet['caption'] ?? '')),
            'hashtags' => smb_string_list($instagram['hashtags'] ?? []),
            'cta' => trim((string)($instagram['cta'] ?? '')),
        ],
        'facebook' => [
            'headline' => trim((string)($facebook['headline'] ?? $editorialTitle)),
            'postText' => trim((string)($facebook['post_text'] ?? $sheet['caption'] ?? '')),
            'linkDescription' => trim((string)($facebook['link_description'] ?? '')),
            'cta' => trim((string)($facebook['cta'] ?? '')),
        ],
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Social Media Board - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="social_media_board.css?v=19">
    <link rel="stylesheet" href="media-controls.css?v=2">
</head>
<body data-social-board-user="<?= $userId ?>">
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= smb_h($user['email']) ?></a></header>
        <div class="smb-page">
            <section class="smb-catalog" aria-labelledby="smb-catalog-title">
                <div class="smb-catalog-head">
                    <div class="smb-catalog-copy">
                        <span class="smb-catalog-kicker">Mockup Catalog</span>
                        <h2 id="smb-catalog-title">Social Media Board</h2>
                        <div class="smb-filters">
                            <label class="smb-artwork-filter">
                                <span class="sr-only">Filter by artwork</span>
                                <select data-artwork-filter>
                                    <option value="">Filter by artwork</option>
                                    <?php foreach ($artworks as $artwork): ?>
                                        <option value="<?= (int)$artwork['id'] ?>"><?= smb_h((string)$artwork['display_title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="smb-series-filter">
                                <span class="sr-only">Filter by series</span>
                                <select data-series-filter>
                                    <option value="">Filter by series</option>
                                    <option value="none">No series</option>
                                    <?php foreach ($series as $item): ?>
                                        <option value="<?= (int)$item['id'] ?>"><?= smb_h((string)$item['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                    </div>
                    <div class="smb-publish-controls" aria-label="Publishing controls">
                        <button type="button" class="smb-confirm" data-confirm-schedule data-publish-now data-delivery-mode="now"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 3-7.6 18-3.2-7.2L3 10.6 21 3Z"/><path d="m10.2 13.8 4.2-4.2"/></svg><span>Publicar ahora</span></button>
                        <button type="button" class="smb-schedule-open" data-open-schedule>Schedule</button>
                    </div>
                    <button class="smb-focus-exit" type="button" data-exit-network-focus>Overview</button>
                </div>

                <div class="smb-catalog-rail-wrap">
                    <button class="smb-rail-arrow smb-rail-arrow--left" type="button" data-scroll-catalog="-1" aria-label="Previous mockups">‹</button>
                    <div class="smb-catalog-rail" data-catalog-rail>
                        <?php foreach ($mockupPayload as $mockup): ?>
                            <article
                                class="smb-catalog-card smb-sortable-item <?= $mockup['favorite'] ? 'is-favorite' : '' ?>"
                                data-catalog-card
                                data-mockup-id="<?= (int)$mockup['id'] ?>"
                                data-id="<?= (int)$mockup['id'] ?>"
                                data-artwork-id="<?= (int)$mockup['artworkId'] ?>"
                                data-series-id="<?= (int)$mockup['seriesId'] ?>"
                                data-inspect-mockup
                                tabindex="0"
                            >
                                <img src="<?= smb_h((string)$mockup['image']) ?>" alt="<?= smb_h((string)$mockup['artworkTitle']) ?>" loading="lazy" draggable="false">
                                <button
                                    class="smb-favorite media-icon-button media-icon-button--compact media-thumb-action media-thumb-action--right <?= $mockup['favorite'] ? 'active' : '' ?>"
                                    type="button"
                                    data-toggle-favorite
                                    aria-pressed="<?= $mockup['favorite'] ? 'true' : 'false' ?>"
                                    aria-label="<?= $mockup['favorite'] ? 'Quitar de favoritos' : 'Agregar a favoritos' ?>"
                                ><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3.7 2.55 5.17 5.71.83-4.13 4.03.97 5.69L12 16.73l-5.1 2.69.97-5.69L3.74 9.7l5.71-.83L12 3.7Z"/></svg></button>
                                <div class="smb-catalog-card-copy">
                                    <strong><?= smb_h((string)$mockup['editorialTitle']) ?></strong>
                                    <span><?= smb_h((string)$mockup['artworkTitle']) ?> · <?= smb_h((string)$mockup['contextTitle']) ?></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        <?php if (!$mockups): ?><div class="smb-empty-catalog">No mockups available yet.</div><?php endif; ?>
                    </div>
                    <button class="smb-rail-arrow smb-rail-arrow--right" type="button" data-scroll-catalog="1" aria-label="More mockups">›</button>
                </div>
            </section>

            <section class="smb-boards" aria-label="Publishing boards">
                <article class="smb-board smb-board--pinterest" data-board="pinterest">
                    <header class="smb-board-head">
                        <button class="smb-board-title" type="button" data-focus-network="pinterest" aria-label="Abrir el tablero de Pinterest en modo enfocado"><span class="smb-network-icon smb-network-icon--pinterest" aria-hidden="true"></span><h2>Pinterest</h2></button>
                        <div class="smb-board-head-actions">
                            <?php if (count($pinterestPurposes) > 1): ?>
                                <label class="smb-pinterest-purpose">
                                    <span class="sr-only">Identidad de Pinterest</span>
                                    <select data-pinterest-purpose aria-label="Identidad de Pinterest">
                                        <?php foreach ($pinterestPurposes as $purposeOption): ?>
                                            <option value="<?= smb_h($purposeOption['value']) ?>" <?= $purposeOption['value'] === $defaultPinterestPurpose ? 'selected' : '' ?>><?= smb_h($purposeOption['label']) ?><?= $purposeOption['connected'] ? '' : ' · no conectada' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            <?php endif; ?>
                            <span class="smb-board-count" data-board-count="pinterest">0 publications</span>
                        </div>
                    </header>
                    <p>Each mockup becomes an individual Pin.</p>
                    <div class="smb-pinterest-runtime-note" data-pinterest-sandbox-note <?= $pinterestSandbox ? '' : 'hidden' ?>><strong>Pinterest test mode</strong><span>While the app has Trial access, Pins can only be published to the Sandbox board and remain visible as tests.</span></div>
                    <div class="smb-pinterest-items" data-board-items="pinterest"></div>
                </article>

                <article class="smb-board smb-board--instagram" data-board="instagram">
                    <header class="smb-board-head">
                        <button class="smb-board-title" type="button" data-focus-network="instagram" aria-label="Abrir el tablero de Instagram en modo enfocado"><span class="smb-network-icon smb-network-icon--instagram" aria-hidden="true"></span><h2>Instagram</h2></button>
                        <div class="smb-board-head-actions"><span class="smb-board-count" data-board-count="instagram">0 publications</span></div>
                    </header>
                    <p>Single post or carousel.</p>
                    <div class="smb-publication-stack" data-publication-stack="instagram"></div>
                </article>

                <article class="smb-board smb-board--facebook" data-board="facebook">
                    <header class="smb-board-head">
                        <button class="smb-board-title" type="button" data-focus-network="facebook" aria-label="Abrir el tablero de Facebook en modo enfocado"><span class="smb-network-icon smb-network-icon--facebook" aria-hidden="true"></span><h2>Facebook</h2></button>
                        <div class="smb-board-head-actions"><span class="smb-board-count" data-board-count="facebook">0 publications</span></div>
                    </header>
                    <p>Post with up to 3 images.</p>
                    <div class="smb-publication-stack" data-publication-stack="facebook"></div>
                </article>
            </section>

            <section class="smb-scheduled is-collapsed" aria-labelledby="smb-scheduled-title" data-scheduled-panel>
                <header class="smb-scheduled-head">
                    <div>
                        <span>Publishing control</span>
                        <h2 id="smb-scheduled-title">Publishing history</h2>
                        <p>Review what was published, what remains queued, and what can be retried without creating duplicates.</p>
                    </div>
                    <div class="smb-scheduled-head-actions">
                        <button type="button" data-toggle-scheduled aria-expanded="false">View history</button>
                        <button type="button" data-refresh-scheduled hidden>Refresh</button>
                    </div>
                </header>
                <div class="smb-scheduled-list" data-scheduled-list aria-live="polite" hidden>
                    <div class="smb-scheduled-empty">Loading publishing history…</div>
                </div>
            </section>

            <div class="smb-toast" data-social-toast role="status" aria-live="polite"></div>
            <div class="smb-confirm-backdrop" data-schedule-backdrop hidden>
                <section class="smb-confirm-dialog smb-schedule-dialog" role="dialog" aria-modal="true" aria-labelledby="smb-schedule-title">
                    <span class="smb-confirm-kicker">Scheduling</span>
                    <h2 id="smb-schedule-title">Choose date and time</h2>
                    <div class="smb-schedule-controls" data-schedule-fields>
                        <label><span>Date</span><input type="date" data-schedule-date></label>
                        <label><span>Time</span><input type="time" data-schedule-time value="10:00"></label>
                        <button type="button" class="smb-schedule-network" data-schedule-by-network aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v3M17 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z"/></svg> Use different times for each publication</button>
                    </div>
                    <div class="smb-confirm-actions">
                        <button type="button" class="smb-confirm-cancel" data-close-schedule>Back to board</button>
                        <button type="button" class="smb-confirm-submit" data-confirm-schedule>Review schedule</button>
                    </div>
                </section>
            </div>
            <div class="smb-confirm-backdrop" data-confirm-backdrop hidden>
                <section class="smb-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="smb-confirm-title">
                    <span class="smb-confirm-kicker">Live action</span>
                    <h2 id="smb-confirm-title" data-confirm-title>Confirm immediate publishing</h2>
                    <div class="smb-confirm-delivery smb-confirm-delivery--now" data-confirm-delivery>Publishing now</div>
                    <div class="smb-confirm-summary" data-confirm-summary></div>
                    <p class="smb-confirm-warning" data-confirm-warning>After confirmation, these publications will enter the live queue immediately.</p>
                    <div class="smb-confirm-actions">
                        <button type="button" class="smb-confirm-cancel" data-cancel-publish>Back to board</button>
                        <button type="button" class="smb-confirm-submit" data-submit-publish data-submit-publish-label>Publicar ahora</button>
                    </div>
                </section>
            </div>
            <div class="smb-inspector-backdrop" data-inspector-backdrop hidden>
                <aside class="smb-inspector" data-inspector role="dialog" aria-modal="true" aria-labelledby="smb-inspector-title">
                    <header class="smb-inspector-head">
                        <div><span data-inspector-kicker>Publishing data</span><h2 id="smb-inspector-title" data-inspector-title>Mockup</h2></div>
                        <button type="button" data-close-inspector aria-label="Close">×</button>
                    </header>
                    <div class="smb-inspector-body" data-inspector-body></div>
                </aside>
            </div>
        </div>
    </main>
</div>
<script type="application/json" id="social-board-mockups"><?= json_encode($mockupPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script type="application/json" id="social-board-config"><?= json_encode($socialBoardConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="assets/vendor/sortablejs/Sortable.min.js?v=1.15.7"></script>
<script src="social_media_board.js?v=17"></script>
</body>
</html>
