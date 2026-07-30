<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Video/bootstrap.php';

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
    $pinterestEnvironments[$purpose] = $pinterestIntegration->apiEnvironment($userId, $purpose);
    $pinterestPurposes[] = [
        'value' => $purpose,
        'label' => $purpose === 'platform' ? 'Artwork Mockups · @artworkmockups' : t('Artist Pinterest account', 'Cuenta de Pinterest del artista'),
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
$socialDestinations = (new SocialBoardDestinationSettings($pdo))->forUser($userId);
$socialBoardConfig = [
    'csrf' => (string)$_SESSION['social_media_board_csrf'],
    'pinterest' => [
        'purpose' => $defaultPinterestPurpose,
        'purposes' => $pinterestPurposes,
        'environment' => $pinterestEnvironment,
        'environments' => $pinterestEnvironments,
    ],
    'destinations' => $socialDestinations,
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

function smb_social_locale_payload(
    array $editorialRow,
    array $legacyNeutral,
    array $legacyChannels,
    array $sheet,
    string $editorialTitle,
    bool $allowLegacy
): array {
    $content = is_array($editorialRow['content'] ?? null) ? $editorialRow['content'] : [];
    $hasEditorial = $content !== [];
    $social = is_array($content['social'] ?? null) ? $content['social'] : [];
    $legacy = $allowLegacy ? $legacyChannels : [];
    $pinterest = is_array($social['pinterest'] ?? null) ? $social['pinterest'] : [];
    $instagram = is_array($social['instagram'] ?? null) ? $social['instagram'] : [];
    $facebook = is_array($social['facebook'] ?? null) ? $social['facebook'] : [];
    $legacyPinterest = is_array($legacy['pinterest'] ?? null) ? $legacy['pinterest'] : [];
    $legacyInstagram = is_array($legacy['instagram'] ?? null) ? $legacy['instagram'] : [];
    $legacyFacebook = is_array($legacy['facebook'] ?? null) ? $legacy['facebook'] : [];
    $legacyNeutral = $allowLegacy ? $legacyNeutral : [];
    $pinterestTitle = MockupSocialContentService::text($pinterest['title'] ?? '', $legacyPinterest['title'] ?? '');
    if ($pinterestTitle === '') $pinterestTitle = $editorialTitle;

    $payload = [
        'available' => $hasEditorial || ($allowLegacy && ($legacyNeutral !== [] || $legacy !== [])),
        'status' => $hasEditorial ? (string)($editorialRow['status'] ?? 'unprepared') : 'legacy',
        'metadata' => [
            'description' => MockupSocialContentService::text($content['description'] ?? '', $legacyNeutral['contextual_description'] ?? $sheet['description'] ?? ''),
            'caption' => MockupSocialContentService::text($content['caption'] ?? '', $legacyNeutral['caption'] ?? $sheet['caption'] ?? ''),
            'altText' => MockupSocialContentService::text($content['alt_text'] ?? '', $legacyNeutral['alt_text'] ?? $sheet['alt_text'] ?? ''),
            'keywords' => MockupSocialContentService::list($content['search_terms'] ?? [], $legacyNeutral['keywords'] ?? $sheet['keywords'] ?? []),
            'tags' => MockupSocialContentService::list($content['tags'] ?? [], $legacyNeutral['tags'] ?? $sheet['tags'] ?? []),
        ],
        'pinterest' => [
            'title' => $pinterestTitle,
            'description' => MockupSocialContentService::text($pinterest['description'] ?? '', $legacyPinterest['description'] ?? $sheet['description'] ?? ''),
            'boards' => MockupSocialContentService::list($pinterest['board_suggestions'] ?? [], $legacyPinterest['board_suggestions'] ?? []),
            'keywords' => MockupSocialContentService::list($pinterest['keywords'] ?? [], $legacyPinterest['keywords'] ?? $sheet['keywords'] ?? []),
        ],
        'instagram' => [
            'hook' => MockupSocialContentService::text($instagram['hook'] ?? '', $legacyInstagram['hook'] ?? ''),
            'caption' => MockupSocialContentService::text($instagram['caption'] ?? '', $legacyInstagram['caption'] ?? $sheet['caption'] ?? ''),
            'hashtags' => MockupSocialContentService::list($instagram['hashtags'] ?? [], $legacyInstagram['hashtags'] ?? []),
            'cta' => MockupSocialContentService::text($instagram['cta'] ?? '', $legacyInstagram['cta'] ?? ''),
        ],
        'facebook' => [
            'headline' => MockupSocialContentService::text($facebook['headline'] ?? '', $legacyFacebook['headline'] ?? $editorialTitle),
            'postText' => MockupSocialContentService::text($facebook['post_text'] ?? '', $legacyFacebook['post_text'] ?? $sheet['caption'] ?? ''),
            'linkDescription' => MockupSocialContentService::text($facebook['link_description'] ?? '', $legacyFacebook['link_description'] ?? ''),
            'cta' => MockupSocialContentService::text($facebook['cta'] ?? '', $legacyFacebook['cta'] ?? ''),
        ],
    ];
    return $payload;
}

$artworkStmt = $pdo->prepare("
    SELECT a.id,
           COALESCE(NULLIF(ag.title,''),NULLIF(a.final_title,''),CONCAT('Artwork #',a.id)) AS display_title
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
           COALESCE(NULLIF(ag.title,''),NULLIF(a.final_title,''),CONCAT('Artwork #',a.id)) AS artwork_title,
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
$socialContent = new MockupSocialContentService($pdo);
$mockupIds = array_column($mockups, 'id');
$bilingualMockups = [
    'es' => $socialContent->forMockups($userId, $mockupIds, 'es'),
    'en' => $socialContent->forMockups($userId, $mockupIds, 'en'),
];
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
    $contextTitle = trim((string)($neutral['context_title'] ?? $sheet['title'] ?? ''));
    if ($contextTitle === '') $contextTitle = Display::contextTitle((string)$mockup['context_id']);
    $editorialTitle = trim((string)($sheet['title'] ?? ''));
    if ($editorialTitle === '') $editorialTitle = trim((string)$mockup['artwork_title']) . ' — ' . $contextTitle;
    $locales = [
        'es' => smb_social_locale_payload($bilingualMockups['es'][$id] ?? [], $neutral, $channels, $sheet, $editorialTitle, true),
        'en' => smb_social_locale_payload($bilingualMockups['en'][$id] ?? [], [], [], [], $editorialTitle, false),
    ];
    $defaultLocale = $locales['en']['available'] ? 'en' : 'es';
    $defaultContent = $locales[$defaultLocale];
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
        'defaultLocale' => $defaultLocale,
        'locales' => $locales,
        'editorialSource' => [
            'locale' => $defaultLocale,
            'status' => (string)$defaultContent['status'],
        ],
        'metadata' => $defaultContent['metadata'],
        'pinterest' => $defaultContent['pinterest'],
        'instagram' => $defaultContent['instagram'],
        'facebook' => $defaultContent['facebook'],
    ];
}

$mockupPayloadByArtworkId = [];
foreach ($mockupPayload as $entry) {
    $artworkId = (int)$entry['artworkId'];
    if ($artworkId > 0 && !isset($mockupPayloadByArtworkId[$artworkId])) {
        $mockupPayloadByArtworkId[$artworkId] = $entry;
    }
}

$emptyLocalePayload = ['available' => false, 'status' => 'unprepared', 'metadata' => [], 'pinterest' => [], 'instagram' => [], 'facebook' => []];
$videoPayload = [];
foreach ((new VideoStudioRepository($pdo))->finalVideos($userId) as $final) {
    if (!empty($final['associationMissing'])) continue;
    $artworkId = (int)($final['artworkId'] ?? 0);
    $sourceMockup = $mockupPayloadByArtworkId[$artworkId] ?? null;
    $displayTitle = (string)($final['displayTitle'] ?? '');
    $videoPayload[] = [
        'id' => 'v' . (int)$final['id'],
        'videoExportId' => (int)$final['id'],
        'isVideo' => true,
        'image' => (string)($final['thumbnailUrl'] ?: ''),
        'previewUrl' => (string)($final['previewUrl'] ?? ''),
        'artworkId' => $artworkId,
        'artworkTitle' => (string)($final['artworkTitle'] ?? ''),
        'seriesId' => 0,
        'seriesTitle' => '',
        'contextTitle' => t('Video', 'Video'),
        'editorialTitle' => $displayTitle !== '' ? $displayTitle : (string)($final['projectTitle'] ?? 'Video'),
        'favorite' => false,
        'defaultLocale' => $sourceMockup['defaultLocale'] ?? 'en',
        'locales' => $sourceMockup['locales'] ?? ['es' => $emptyLocalePayload, 'en' => $emptyLocalePayload],
        'editorialSource' => $sourceMockup['editorialSource'] ?? ['locale' => 'en', 'status' => 'unprepared'],
        'metadata' => $sourceMockup['metadata'] ?? [],
        'pinterest' => [],
        'instagram' => $sourceMockup['instagram'] ?? [],
        'facebook' => $sourceMockup['facebook'] ?? [],
    ];
}
$catalogPayload = array_merge($mockupPayload, $videoPayload);
?>
<!doctype html>
<html lang="<?= smb_h(Translator::locale($user)) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= smb_h(t('Social Media Board - Artwork Mockups', 'Tablero de Redes Sociales - Artwork Mockups')) ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="social_media_board.css?v=37">
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
                        <span class="smb-catalog-kicker"><?= smb_h(t('Mockup Catalog', 'Catálogo de Mockups')) ?></span>
                        <h2 id="smb-catalog-title"><?= smb_h(t('Social Media Board', 'Tablero de Redes Sociales')) ?></h2>
                        <div class="smb-filters">
                            <label class="smb-artwork-filter">
                                <span class="sr-only"><?= smb_h(t('Filter by artwork', 'Filtrar por obra')) ?></span>
                                <select data-artwork-filter>
                                    <option value=""><?= smb_h(t('Filter by artwork', 'Filtrar por obra')) ?></option>
                                    <?php foreach ($artworks as $artwork): ?>
                                        <option value="<?= (int)$artwork['id'] ?>"><?= smb_h((string)$artwork['display_title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="smb-series-filter">
                                <span class="sr-only"><?= smb_h(t('Filter by series', 'Filtrar por serie')) ?></span>
                                <select data-series-filter>
                                    <option value=""><?= smb_h(t('Filter by series', 'Filtrar por serie')) ?></option>
                                    <option value="none"><?= smb_h(t('No series', 'Sin serie')) ?></option>
                                    <?php foreach ($series as $item): ?>
                                        <option value="<?= (int)$item['id'] ?>"><?= smb_h((string)$item['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                    </div>
                    <div class="smb-publish-controls" aria-label="<?= smb_h(t('Publishing controls', 'Controles de publicación')) ?>">
                        <div class="smb-publish-secondary">
                            <button type="button" class="smb-icon-btn smb-schedule-open" data-open-schedule title="<?= smb_h(t('Schedule', 'Programar')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2.4"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/></svg><span class="sr-only"><?= smb_h(t('Schedule', 'Programar')) ?></span></button>
                            <button type="button" class="smb-icon-btn smb-destinations-open" data-toggle-destinations aria-expanded="false" title="<?= smb_h(t('Default links', 'Enlaces predeterminados')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 14.5 14.5 9.5"/><path d="M11.2 7.3 12.5 6a3.6 3.6 0 0 1 5.1 5.1l-1.3 1.3"/><path d="M12.8 16.7 11.5 18a3.6 3.6 0 0 1-5.1-5.1l1.3-1.3"/></svg><span class="sr-only"><?= smb_h(t('Default links', 'Enlaces predeterminados')) ?></span></button>
                        </div>
                        <button type="button" class="smb-confirm" data-confirm-schedule data-publish-now data-delivery-mode="now"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 3-7.6 18-3.2-7.2L3 10.6 21 3Z"/><path d="m10.2 13.8 4.2-4.2"/></svg><span><?= smb_h(t('Publish now', 'Publicar ahora')) ?></span></button>
                    </div>
                    <button class="smb-focus-exit" type="button" data-exit-network-focus><?= smb_h(t('Overview', 'Resumen')) ?></button>
                </div>

                <section class="smb-destinations" data-destinations-panel hidden aria-labelledby="smb-destinations-title">
                    <div class="smb-destinations-copy">
                        <span><?= smb_h(t('Publication destinations', 'Destinos de publicación')) ?></span>
                        <h3 id="smb-destinations-title"><?= smb_h(t('Default links', 'Enlaces predeterminados')) ?></h3>
                        <p><?= smb_h(t('New publications start with these links. You can still replace the exact URL inside an individual publication.', 'Las publicaciones nuevas empiezan con estos enlaces. Igual podés reemplazar la URL exacta dentro de cada publicación.')) ?></p>
                    </div>
                    <div class="smb-destinations-fields">
                        <label><span>Website</span><input type="url" data-default-destination="website" value="<?= smb_h($socialDestinations['website']) ?>" placeholder="https://…" autocomplete="url"></label>
                        <label><span>Saatchi Art</span><input type="url" data-default-destination="saatchi" value="<?= smb_h($socialDestinations['saatchi']) ?>" placeholder="https://…" autocomplete="url"></label>
                        <button type="button" data-save-destinations><?= smb_h(t('Save defaults', 'Guardar predeterminados')) ?></button>
                    </div>
                </section>

                <div class="smb-catalog-rails">
                    <div class="smb-catalog-rail-wrap smb-catalog-rail-wrap--mockups">
                        <button class="smb-rail-arrow smb-rail-arrow--left" type="button" data-scroll-catalog="-1" aria-label="<?= smb_h(t('Previous mockups', 'Mockups anteriores')) ?>">‹</button>
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
                                        aria-label="<?= $mockup['favorite'] ? smb_h(t('Remove from favorites', 'Quitar de favoritos')) : smb_h(t('Add to favorites', 'Agregar a favoritos')) ?>"
                                    ><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3.7 2.55 5.17 5.71.83-4.13 4.03.97 5.69L12 16.73l-5.1 2.69.97-5.69L3.74 9.7l5.71-.83L12 3.7Z"/></svg></button>
                                    <div class="smb-quick-add" role="group" aria-label="<?= smb_h(t('Add to a board', 'Agregar a un tablero')) ?>">
                                        <button type="button" class="smb-quick-add-btn smb-quick-add-btn--pinterest media-icon-button media-icon-button--compact" data-quick-add="pinterest" aria-label="<?= smb_h(t('Add to Pinterest', 'Agregar a Pinterest')) ?>"></button>
                                        <button type="button" class="smb-quick-add-btn smb-quick-add-btn--instagram media-icon-button media-icon-button--compact" data-quick-add="instagram" aria-label="<?= smb_h(t('Add to Instagram', 'Agregar a Instagram')) ?>"></button>
                                        <button type="button" class="smb-quick-add-btn smb-quick-add-btn--facebook media-icon-button media-icon-button--compact" data-quick-add="facebook" aria-label="<?= smb_h(t('Add to Facebook', 'Agregar a Facebook')) ?>"></button>
                                    </div>
                                    <div class="smb-catalog-card-copy">
                                        <strong><?= smb_h((string)$mockup['editorialTitle']) ?></strong>
                                        <span><?= smb_h((string)$mockup['artworkTitle']) ?> · <?= smb_h((string)$mockup['contextTitle']) ?></span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                            <?php if (!$mockups): ?><div class="smb-empty-catalog"><?= smb_h(t('No mockups available yet.', 'Todavía no hay mockups disponibles.')) ?></div><?php endif; ?>
                        </div>
                        <button class="smb-rail-arrow smb-rail-arrow--right" type="button" data-scroll-catalog="1" aria-label="<?= smb_h(t('More mockups', 'Más mockups')) ?>">›</button>
                    </div>

                    <div class="smb-catalog-rail-wrap smb-catalog-rail-wrap--videos">
                        <div class="smb-catalog-rail smb-catalog-rail--videos" data-video-rail>
                            <?php foreach ($videoPayload as $video): ?>
                                <article
                                    class="smb-catalog-card smb-catalog-card--video smb-sortable-item"
                                    data-catalog-card
                                    data-mockup-id="<?= smb_h((string)$video['id']) ?>"
                                    data-id="<?= smb_h((string)$video['id']) ?>"
                                    data-artwork-id="<?= (int)$video['artworkId'] ?>"
                                    data-inspect-mockup
                                    tabindex="0"
                                >
                                    <?php if ($video['image'] !== ''): ?>
                                        <img src="<?= smb_h((string)$video['image']) ?>" alt="<?= smb_h((string)$video['artworkTitle']) ?>" loading="lazy" draggable="false">
                                    <?php endif; ?>
                                    <span class="smb-video-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5-11-6.5Z"/></svg></span>
                                    <div class="smb-quick-add smb-quick-add--video" role="group" aria-label="<?= smb_h(t('Add to a board', 'Agregar a un tablero')) ?>">
                                        <button type="button" class="smb-quick-add-btn smb-quick-add-btn--instagram media-icon-button media-icon-button--compact" data-quick-add="instagram" aria-label="<?= smb_h(t('Add to Instagram', 'Agregar a Instagram')) ?>"></button>
                                        <button type="button" class="smb-quick-add-btn smb-quick-add-btn--facebook media-icon-button media-icon-button--compact" data-quick-add="facebook" aria-label="<?= smb_h(t('Add to Facebook', 'Agregar a Facebook')) ?>"></button>
                                    </div>
                                    <div class="smb-catalog-card-copy">
                                        <strong><?= smb_h((string)$video['editorialTitle']) ?></strong>
                                        <span><?= smb_h((string)$video['artworkTitle']) ?></span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                            <?php if (!$videoPayload): ?><div class="smb-empty-catalog smb-empty-catalog--videos"><?= smb_h(t('No finished videos yet.', 'Todavía no hay videos terminados.')) ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="smb-boards" aria-label="<?= smb_h(t('Publishing boards', 'Tableros de publicación')) ?>">
                <article class="smb-board smb-board--pinterest" data-board="pinterest">
                    <header class="smb-board-head">
                        <button class="smb-board-title" type="button" data-focus-network="pinterest" aria-label="<?= smb_h(t('Open the Pinterest board in focus mode', 'Abrir el tablero de Pinterest en modo foco')) ?>"><span class="smb-network-icon smb-network-icon--pinterest" aria-hidden="true"></span><h2>Pinterest</h2></button>
                        <div class="smb-board-head-actions">
                            <?php if (count($pinterestPurposes) > 1): ?>
                                <label class="smb-pinterest-purpose">
                                    <span class="sr-only"><?= smb_h(t('Pinterest identity', 'Identidad de Pinterest')) ?></span>
                                    <select data-pinterest-purpose aria-label="<?= smb_h(t('Pinterest identity', 'Identidad de Pinterest')) ?>">
                                        <?php foreach ($pinterestPurposes as $purposeOption): ?>
                                            <option value="<?= smb_h($purposeOption['value']) ?>" <?= $purposeOption['value'] === $defaultPinterestPurpose ? 'selected' : '' ?>><?= smb_h($purposeOption['label']) ?><?= $purposeOption['connected'] ? '' : ' · ' . smb_h(t('not connected', 'no conectada')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            <?php endif; ?>
                            <span class="smb-board-count" data-board-count="pinterest">0 <?= smb_h(t('publications', 'publicaciones')) ?></span>
                        </div>
                    </header>
                    <p><?= smb_h(t('Each mockup becomes an individual Pin.', 'Cada mockup se convierte en un Pin individual.')) ?></p>
                    <div class="smb-pinterest-runtime-note" data-pinterest-sandbox-note <?= $pinterestSandbox ? '' : 'hidden' ?>><strong><?= smb_h(t('Pinterest test mode', 'Modo de prueba de Pinterest')) ?></strong><span><?= smb_h(t('While the app has Trial access, Pins can only be published to the Sandbox board and remain visible as tests.', 'Mientras la app tenga acceso de prueba, los Pins solo se pueden publicar en el tablero Sandbox y quedan visibles como pruebas.')) ?></span></div>
                    <div class="smb-pinterest-items" data-board-items="pinterest"></div>
                </article>

                <article class="smb-board smb-board--instagram" data-board="instagram">
                    <header class="smb-board-head">
                        <button class="smb-board-title" type="button" data-focus-network="instagram" aria-label="<?= smb_h(t('Open the Instagram board in focus mode', 'Abrir el tablero de Instagram en modo foco')) ?>"><span class="smb-network-icon smb-network-icon--instagram" aria-hidden="true"></span><h2>Instagram</h2></button>
                        <div class="smb-board-head-actions"><span class="smb-board-count" data-board-count="instagram">0 <?= smb_h(t('publications', 'publicaciones')) ?></span></div>
                    </header>
                    <p><?= smb_h(t('Single post or carousel.', 'Publicación única o carrusel.')) ?></p>
                    <div class="smb-publication-stack" data-publication-stack="instagram"></div>
                </article>

                <article class="smb-board smb-board--facebook" data-board="facebook">
                    <header class="smb-board-head">
                        <button class="smb-board-title" type="button" data-focus-network="facebook" aria-label="<?= smb_h(t('Open the Facebook board in focus mode', 'Abrir el tablero de Facebook en modo foco')) ?>"><span class="smb-network-icon smb-network-icon--facebook" aria-hidden="true"></span><h2>Facebook</h2></button>
                        <div class="smb-board-head-actions"><span class="smb-board-count" data-board-count="facebook">0 <?= smb_h(t('publications', 'publicaciones')) ?></span></div>
                    </header>
                    <p><?= smb_h(t('Post with up to 10 images.', 'Publicación con hasta 10 imágenes.')) ?></p>
                    <div class="smb-publication-stack" data-publication-stack="facebook"></div>
                </article>
            </section>

            <section class="smb-scheduled is-collapsed" aria-labelledby="smb-scheduled-title" data-scheduled-panel>
                <header class="smb-scheduled-head">
                    <div>
                        <span><?= smb_h(t('Publishing control', 'Control de publicación')) ?></span>
                        <h2 id="smb-scheduled-title"><?= smb_h(t('Publishing history', 'Historial de publicación')) ?></h2>
                        <p><?= smb_h(t('Review what was published, what remains queued, and what can be retried without creating duplicates.', 'Revisá qué se publicó, qué sigue en cola y qué se puede reintentar sin crear duplicados.')) ?></p>
                    </div>
                    <div class="smb-scheduled-head-actions">
                        <button type="button" data-toggle-scheduled aria-expanded="false"><?= smb_h(t('View history', 'Ver historial')) ?></button>
                        <button type="button" data-refresh-scheduled hidden><?= smb_h(t('Refresh', 'Actualizar')) ?></button>
                    </div>
                </header>
                <div class="smb-scheduled-list" data-scheduled-list aria-live="polite" hidden>
                    <div class="smb-scheduled-empty"><?= smb_h(t('Loading publishing history…', 'Cargando historial de publicación…')) ?></div>
                </div>
            </section>

            <div class="smb-toast" data-social-toast role="status" aria-live="polite"></div>
            <div class="smb-confirm-backdrop" data-schedule-backdrop hidden>
                <section class="smb-confirm-dialog smb-schedule-dialog" role="dialog" aria-modal="true" aria-labelledby="smb-schedule-title">
                    <span class="smb-confirm-kicker"><?= smb_h(t('Scheduling', 'Programación')) ?></span>
                    <h2 id="smb-schedule-title"><?= smb_h(t('Choose date and time', 'Elegí fecha y hora')) ?></h2>
                    <div class="smb-schedule-controls" data-schedule-fields>
                        <label><span><?= smb_h(t('Date', 'Fecha')) ?></span><input type="date" data-schedule-date></label>
                        <label><span><?= smb_h(t('Time', 'Hora')) ?></span><input type="time" data-schedule-time value="10:00"></label>
                        <button type="button" class="smb-schedule-network" data-schedule-by-network aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v3M17 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z"/></svg> <?= smb_h(t('Use different times for each publication', 'Usar horarios distintos para cada publicación')) ?></button>
                    </div>
                    <div class="smb-confirm-actions">
                        <button type="button" class="smb-confirm-cancel" data-close-schedule><?= smb_h(t('Back to board', 'Volver al tablero')) ?></button>
                        <button type="button" class="smb-confirm-submit" data-confirm-schedule><?= smb_h(t('Review schedule', 'Revisar programación')) ?></button>
                    </div>
                </section>
            </div>
            <div class="smb-confirm-backdrop" data-confirm-backdrop hidden>
                <section class="smb-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="smb-confirm-title">
                    <span class="smb-confirm-kicker"><?= smb_h(t('Live action', 'Acción en vivo')) ?></span>
                    <h2 id="smb-confirm-title" data-confirm-title><?= smb_h(t('Confirm immediate publishing', 'Confirmar publicación inmediata')) ?></h2>
                    <div class="smb-confirm-delivery smb-confirm-delivery--now" data-confirm-delivery><?= smb_h(t('Publishing now', 'Publicando ahora')) ?></div>
                    <div class="smb-confirm-summary" data-confirm-summary></div>
                    <p class="smb-confirm-warning" data-confirm-warning><?= smb_h(t('After confirmation, these publications will enter the live queue immediately.', 'Al confirmar, estas publicaciones van a entrar de inmediato en la cola en vivo.')) ?></p>
                    <div class="smb-confirm-actions">
                        <button type="button" class="smb-confirm-cancel" data-cancel-publish><?= smb_h(t('Back to board', 'Volver al tablero')) ?></button>
                        <button type="button" class="smb-confirm-submit" data-submit-publish data-submit-publish-label><?= smb_h(t('Publish now', 'Publicar ahora')) ?></button>
                    </div>
                </section>
            </div>
            <div class="smb-inspector-backdrop" data-inspector-backdrop hidden>
                <aside class="smb-inspector" data-inspector role="dialog" aria-modal="true" aria-labelledby="smb-inspector-title">
                    <header class="smb-inspector-head">
                        <div><span data-inspector-kicker><?= smb_h(t('Publishing data', 'Datos de publicación')) ?></span><h2 id="smb-inspector-title" data-inspector-title><?= smb_h(t('Mockup', 'Mockup')) ?></h2></div>
                        <button type="button" data-close-inspector aria-label="<?= smb_h(t('Close', 'Cerrar')) ?>">×</button>
                    </header>
                    <div class="smb-inspector-body" data-inspector-body></div>
                </aside>
            </div>
        </div>
    </main>
</div>
<script type="application/json" id="social-board-mockups"><?= json_encode($catalogPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script type="application/json" id="social-board-config"><?= json_encode($socialBoardConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="assets/vendor/sortablejs/Sortable.min.js?v=1.15.7"></script>
<script src="social_media_board.js?v=37"></script>
</body>
</html>
