<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
FeatureAccess::requirePage($user, FeatureAccess::SOCIAL_MANAGE, 'Social Media');

$pdo = Database::connection();
$userId = (int)$user['id'];
ArtworkSeries::ensureSchema($pdo);
ArtworkSeries::syncUser($pdo, $userId);

function sm_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function sm_media_url(?string $file, int $width = 360): string
{
    $file = basename((string)$file);
    return $file !== '' ? 'media.php?file=' . rawurlencode($file) . '&thumb=1&w=' . max(240, min(900, $width)) : '';
}
function sm_campaign_types(): array
{
    return [
        'artwork_launch' => ['title' => 'New Artwork Launch', 'objective' => 'Presentar una obra nueva con claridad editorial.'],
        'series_launch' => ['title' => 'New Series Launch', 'objective' => 'Presentar una serie completa y su lenguaje común.'],
        'symbolism' => ['title' => 'Symbolism / Concept', 'objective' => 'Explicar símbolos, composición, territorio, tensión o materia.'],
        'available_catalog' => ['title' => 'Available Catalog', 'objective' => 'Mostrar catálogo disponible con intención comercial.'],
        'sold_constellation' => ['title' => 'Sold Artwork / Constellation', 'objective' => 'Mostrar ubicación, destino e historia de obras vendidas.'],
        'studio_process' => ['title' => 'Studio Process', 'objective' => 'Mostrar proceso, decisiones visuales, técnica y contexto.'],
        'refresh' => ['title' => 'Repost / Refresh', 'objective' => 'Reactivar una obra o serie ya publicada después de un tiempo.'],
    ];
}
function sm_criteria(): array
{
    return [
        'series' => ['title' => 'Series', 'description' => 'Agrupar obras y mockups desde una serie.'],
        'artwork' => ['title' => 'Artwork', 'description' => 'Promocionar una obra puntual y sus mockups.'],
        'catalog' => ['title' => 'Catalog', 'description' => 'Promocionar catálogo disponible o selección amplia.'],
        'symbolism' => ['title' => 'Symbolism', 'description' => 'Trabajar una idea visual o conceptual.'],
        'sold_constellation' => ['title' => 'Sold / Constellation', 'description' => 'Comunicar obras vendidas, destino y mapa.'],
    ];
}
function sm_channels(): array
{
    return [
        'pinterest' => ['title' => 'Pinterest', 'description' => 'Boards, vertical crop and destination link.'],
        'meta_media' => ['title' => 'Meta Media', 'description' => 'Instagram and Facebook formats.'],
    ];
}
function sm_social_payload_channels(?array $payload): array
{
    if (!is_array($payload)) return [];
    return array_values(array_intersect(array_map('strval', (array)($payload['channels'] ?? [])), array_keys(sm_channels())));
}
function sm_ensure_campaign_table(PDO $pdo): void
{
    $id = Database::isMysql() ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = Database::isMysql() ? 'LONGTEXT' : 'TEXT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS social_campaigns (
        id {$id},
        user_id INTEGER NOT NULL,
        campaign_type VARCHAR(40) NOT NULL,
        title VARCHAR(255) NOT NULL,
        objective {$text} NOT NULL,
        source_type VARCHAR(40) NOT NULL DEFAULT '',
        source_id VARCHAR(80) NOT NULL DEFAULT '',
        source_label VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL DEFAULT 'draft',
        payload_json {$text} NOT NULL,
        created_at VARCHAR(40) NOT NULL,
        updated_at VARCHAR(40) NOT NULL
    )");
}
function sm_status_class(string $status): string
{
    return 'status-' . strtolower(str_replace(' ', '-', $status));
}
function sm_status_label(string $status): string
{
    return match ($status) {
        'draft' => 'Draft · not prepared',
        'in_progress' => 'Publication batch in review',
        'published' => 'Published',
        'needs_attention' => 'Needs attention',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}
function sm_channel_titles(array $channelKeys): array
{
    $available = sm_channels();
    $titles = [];
    foreach ($channelKeys as $key) {
        $key = (string)$key;
        if (isset($available[$key])) $titles[] = (string)$available[$key]['title'];
    }
    return $titles;
}
function sm_meta_destination_titles(?array $payload): array
{
    $destinations = [];
    foreach (sm_meta_batches($payload) as $batch) {
        $destinations = array_merge($destinations, $batch['destinations']);
    }
    $destinations = array_values(array_unique($destinations));
    return array_map(static fn (string $destination): string => ucfirst($destination), $destinations);
}
function sm_meta_batches(?array $payload): array
{
    if (!is_array($payload)) return [];
    $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
    $source = is_array($meta['batches'] ?? null) ? $meta['batches'] : [];
    if (!$source && (int)($meta['batch_id'] ?? 0) > 0) $source[] = $meta;
    $batches = [];
    foreach ($source as $batch) {
        if (!is_array($batch) || (int)($batch['batch_id'] ?? 0) <= 0) continue;
        $destinations = array_values(array_intersect(
            ['facebook', 'instagram'],
            array_unique(array_map(static fn ($value): string => strtolower(trim((string)$value)), (array)($batch['destinations'] ?? [])))
        ));
        if (!$destinations) continue;
        $batches[] = [
            'batch_id' => (int)$batch['batch_id'],
            'purpose' => (string)($batch['purpose'] ?? 'artist'),
            'destinations' => $destinations,
            'status' => (string)($batch['status'] ?? 'review'),
        ];
    }
    return $batches;
}
function sm_page_url(array $params): string
{
    return 'social_media_catalog.php' . ($params ? '?' . http_build_query($params) : '');
}
function sm_campaign(PDO $pdo, int $userId, int $campaignId): ?array
{
    if ($campaignId <= 0) return null;
    $stmt = $pdo->prepare('SELECT * FROM social_campaigns WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$campaignId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
function sm_campaign_mockups(PDO $pdo, int $userId, array $mockupIds): array
{
    $mockupIds = array_values(array_unique(array_filter(array_map('intval', $mockupIds))));
    if (!$mockupIds) return [];
    $placeholders = implode(',', array_fill(0, count($mockupIds), '?'));
    $stmt = $pdo->prepare("
        SELECT m.*, a.final_title AS artwork_title, s.title AS series_title
        FROM mockups m
        LEFT JOIN artworks a ON a.id = m.source_artwork_id
        LEFT JOIN artwork_series s ON s.id = m.series_id AND s.user_id = m.user_id
        WHERE m.user_id=? AND m.id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$userId], $mockupIds));
    $found = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $found[(int)$row['id']] = $row;
    }
    $ordered = [];
    foreach ($mockupIds as $id) {
        if (isset($found[$id])) $ordered[] = $found[$id];
    }
    return $ordered;
}

sm_ensure_campaign_table($pdo);
Auth::start();
if (empty($_SESSION['social_campaign_csrf'])) {
    $_SESSION['social_campaign_csrf'] = bin2hex(random_bytes(24));
}
if (empty($_SESSION['pinterest_batch_create_csrf'])) {
    $_SESSION['pinterest_batch_create_csrf'] = bin2hex(random_bytes(24));
}
if (empty($_SESSION['meta_batch_create_csrf'])) {
    $_SESSION['meta_batch_create_csrf'] = bin2hex(random_bytes(24));
}

$types = sm_campaign_types();
$criteria = sm_criteria();
$channels = sm_channels();
$favoriteIds = MockupFavorites::idsForUser($userId);
$favoriteLookup = array_fill_keys($favoriteIds, true);

$campaignType = (string)($_GET['campaign'] ?? '');
if (!isset($types[$campaignType])) $campaignType = '';
$criterion = (string)($_GET['criterion'] ?? '');
if (!isset($criteria[$criterion])) $criterion = '';
$sourceId = trim((string)($_GET['source_id'] ?? ''));

$notice = '';
$error = '';
if (!empty($_SESSION['social_campaign_notice'])) {
    $notice = (string)$_SESSION['social_campaign_notice'];
    unset($_SESSION['social_campaign_notice']);
}
if (!empty($_SESSION['social_campaign_error'])) {
    $error = (string)$_SESSION['social_campaign_error'];
    unset($_SESSION['social_campaign_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['social_campaign_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Invalid session token.');
        }

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'delete_campaign') {
            $campaignId = (int)($_POST['campaign_id'] ?? 0);
            $pdo->prepare("DELETE FROM social_campaigns WHERE id=? AND user_id=? AND status IN ('draft','in_progress')")->execute([$campaignId, $userId]);
            $_SESSION['social_campaign_notice'] = 'Campaign draft deleted.';
            header('Location: social_media_catalog.php');
            exit;
        } elseif ($action === 'create_campaign_selection') {
            $type = (string)($_POST['campaign_type'] ?? '');
            $chosenCriterion = (string)($_POST['criterion'] ?? '');
            if (!isset($types[$type])) throw new RuntimeException('Invalid campaign type.');
            if (!isset($criteria[$chosenCriterion])) throw new RuntimeException('Invalid campaign criterion.');

            $mockupIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['mockup_ids'] ?? [])))));
            $chosenChannels = array_values(array_intersect(array_map('strval', (array)($_POST['channels'] ?? [])), array_keys($channels)));
            if (!$mockupIds) throw new RuntimeException('Select at least one mockup.');
            if (!$chosenChannels) throw new RuntimeException('Select at least one social channel.');
            if (in_array('pinterest', $chosenChannels, true) && count($mockupIds) > 10) {
                throw new RuntimeException('Pinterest campaigns support up to 10 mockups per batch.');
            }

            $sourceType = trim((string)($_POST['source_type'] ?? $chosenCriterion));
            $sourceValue = trim((string)($_POST['source_id'] ?? ''));
            $sourceLabel = trim((string)($_POST['source_label'] ?? $criteria[$chosenCriterion]['title']));
            $base = $types[$type];
            $title = $base['title'] . ' — ' . $sourceLabel;
            $signatureMockups = $mockupIds;
            $signatureChannels = $chosenChannels;
            sort($signatureMockups);
            sort($signatureChannels);
            $draftSignature = hash('sha256', json_encode([
                'type' => $type,
                'criterion' => $chosenCriterion,
                'source_type' => $sourceType,
                'source_id' => $sourceValue,
                'mockups' => $signatureMockups,
                'channels' => $signatureChannels,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $payload = [
                'phase' => 'channel_planning',
                'criterion' => $chosenCriterion,
                'mockup_ids' => $mockupIds,
                'channels' => $chosenChannels,
                'channel_status' => array_fill_keys($chosenChannels, 'draft'),
                'draft_signature' => $draftSignature,
                'next_step' => 'choose boards/calendar, review copy, approve publication',
            ];
            $existing = $pdo->prepare("SELECT id,payload_json FROM social_campaigns WHERE user_id=? AND campaign_type=? AND source_type=? AND source_id=? AND status IN ('draft','in_progress') ORDER BY id DESC");
            $existing->execute([$userId, $type, $sourceType, $sourceValue]);
            foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $existingPayload = json_decode((string)$row['payload_json'], true);
                if (!is_array($existingPayload)) {
                    continue;
                }
                $existingMockups = array_values(array_unique(array_filter(array_map('intval', (array)($existingPayload['mockup_ids'] ?? [])))));
                $existingChannels = array_values(array_map('strval', (array)($existingPayload['channels'] ?? [])));
                sort($existingMockups);
                sort($existingChannels);
                $existingSignature = (string)($existingPayload['draft_signature'] ?? hash('sha256', json_encode([
                    'type' => $type,
                    'criterion' => (string)($existingPayload['criterion'] ?? ''),
                    'source_type' => $sourceType,
                    'source_id' => $sourceValue,
                    'mockups' => $existingMockups,
                    'channels' => $existingChannels,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
                if ($existingSignature === $draftSignature) {
                    $_SESSION['social_campaign_notice'] = 'Campaign draft already exists.';
                    header('Location: social_media_catalog.php?draft=' . (int)$row['id']);
                    exit;
                }
            }
            $now = date('c');
            $stmt = $pdo->prepare('INSERT INTO social_campaigns (user_id,campaign_type,title,objective,source_type,source_id,source_label,status,payload_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$userId, $type, $title, $base['objective'], $sourceType, $sourceValue, $sourceLabel, 'draft', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now]);
            $newCampaignId = (int)$pdo->lastInsertId();
            $_SESSION['social_campaign_notice'] = 'Campaign draft created with selected mockups and channels.';
            header('Location: social_media_catalog.php?draft=' . $newCampaignId);
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['social_campaign_error'] = $e->getMessage();
        header('Location: social_media_catalog.php');
        exit;
    }
}

$counts = ['artworks' => 0, 'series' => 0, 'mockups' => 0, 'favorites' => count($favoriteIds), 'campaigns' => 0];
$stmt = $pdo->prepare("SELECT COUNT(*) FROM artworks WHERE user_id=? AND status='done'");
$stmt->execute([$userId]);
$counts['artworks'] = (int)$stmt->fetchColumn();
$seriesRows = ArtworkSeries::seriesList($pdo, $userId);
$counts['series'] = count($seriesRows);
$stmt = $pdo->prepare('SELECT COUNT(*) FROM mockups WHERE user_id=?');
$stmt->execute([$userId]);
$counts['mockups'] = (int)$stmt->fetchColumn();
$campaignStmt = $pdo->prepare('SELECT * FROM social_campaigns WHERE user_id=? ORDER BY id DESC LIMIT 80');
$campaignStmt->execute([$userId]);
$campaigns = [];
foreach ($campaignStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $payload = json_decode((string)$row['payload_json'], true);
    if (sm_social_payload_channels(is_array($payload) ? $payload : null)) {
        $campaigns[] = $row;
    }
    if (count($campaigns) >= 24) break;
}
$activeCampaigns = array_values(array_filter($campaigns, static fn (array $campaign): bool => (string)$campaign['status'] !== 'published'));
$publishedCampaigns = array_values(array_filter($campaigns, static fn (array $campaign): bool => (string)$campaign['status'] === 'published'));
$counts['campaigns'] = count($activeCampaigns);

$openDraftId = (int)($_GET['draft'] ?? 0);
$openDraft = sm_campaign($pdo, $userId, $openDraftId);
$openDraftPayload = $openDraft ? json_decode((string)$openDraft['payload_json'], true) : null;
if ($openDraft && !sm_social_payload_channels(is_array($openDraftPayload) ? $openDraftPayload : null)) {
    $openDraft = null;
    $openDraftPayload = null;
}
$openDraftMockups = is_array($openDraftPayload) ? sm_campaign_mockups($pdo, $userId, (array)($openDraftPayload['mockup_ids'] ?? [])) : [];
$pinterestConnections = [];
$metaConnections = [];
$instagramConnections = [];
$pinterestDefaultDestination = app_env('APP_PUBLIC_URL', '');
if (!str_starts_with(strtolower($pinterestDefaultDestination), 'https://')) {
    $pinterestDefaultDestination = '';
}
if ($openDraft) {
    $pinterestService = new PinterestIntegrationService($pdo);
    $pinterestConnections['artist'] = $pinterestService->connection($userId, 'artist');
    if ($isAdmin) {
        $pinterestConnections['platform'] = $pinterestService->connection($userId, 'platform');
    }
    $metaService = new MetaIntegrationService($pdo);
    $metaConnections['artist'] = $metaService->connection($userId, 'artist');
    $instagramService = new InstagramIntegrationService($pdo);
    $instagramConnections['artist'] = $instagramService->connection($userId, 'artist');
    if ($isAdmin) {
        $metaConnections['platform'] = $metaService->connection($userId, 'platform');
        $instagramConnections['platform'] = $instagramService->connection($userId, 'platform');
    }
}

$artworkStmt = $pdo->prepare("
    SELECT a.id, a.final_title, a.root_file, a.main_file, a.series_id, a.series, s.title AS series_title
    FROM artworks a
    LEFT JOIN artwork_series s ON s.id = a.series_id AND s.user_id = a.user_id
    WHERE a.user_id=? AND a.status='done'
    ORDER BY a.updated_at DESC, a.id DESC
    LIMIT 60
");
$artworkStmt->execute([$userId]);
$artworkRows = $artworkStmt->fetchAll(PDO::FETCH_ASSOC);

$seriesPreview = [];
if ($seriesRows) {
    $previewStmt = $pdo->prepare("
        SELECT id, final_title, root_file, main_file, series_id
        FROM artworks
        WHERE user_id=? AND status='done' AND series_id IS NOT NULL
        ORDER BY updated_at DESC, id DESC
    ");
    $previewStmt->execute([$userId]);
    foreach ($previewStmt->fetchAll(PDO::FETCH_ASSOC) as $artwork) {
        $sid = (int)$artwork['series_id'];
        $seriesPreview[$sid] ??= [];
        if (count($seriesPreview[$sid]) < 4) {
            $seriesPreview[$sid][] = $artwork;
        }
    }
}

$sourceLabel = '';
$sourceMockups = [];
$sourceType = $criterion;
if ($campaignType !== '' && $criterion !== '' && $sourceId !== '') {
    if ($criterion === 'series') {
        $sid = (int)$sourceId;
        foreach ($seriesRows as $row) {
            if ((int)$row['id'] === $sid) {
                $sourceLabel = (string)$row['title'];
                break;
            }
        }
        if ($sourceLabel !== '') {
            $stmt = $pdo->prepare("
                SELECT m.*, a.final_title AS artwork_title, s.title AS series_title
                FROM mockups m
                LEFT JOIN artworks a ON a.id = m.source_artwork_id
                LEFT JOIN artwork_series s ON s.id = m.series_id AND s.user_id = m.user_id
                WHERE m.user_id=? AND m.series_id=?
                ORDER BY m.created_at DESC
                LIMIT 160
            ");
            $stmt->execute([$userId, $sid]);
            $sourceMockups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif (in_array($criterion, ['artwork', 'symbolism'], true)) {
        $aid = (int)$sourceId;
        $selectedArtwork = null;
        foreach ($artworkRows as $row) {
            if ((int)$row['id'] === $aid) {
                $selectedArtwork = $row;
                $sourceLabel = trim((string)$row['final_title']) ?: 'Untitled artwork';
                break;
            }
        }
        if ($selectedArtwork) {
            $stmt = $pdo->prepare("
                SELECT m.*, ? AS artwork_title, s.title AS series_title
                FROM mockups m
                LEFT JOIN artwork_series s ON s.id = m.series_id AND s.user_id = m.user_id
                WHERE m.user_id=? AND (m.source_artwork_id=? OR m.artwork_file=? OR m.artwork_file=?)
                ORDER BY m.created_at DESC
                LIMIT 120
            ");
            $stmt->execute([$sourceLabel, $userId, $aid, (string)$selectedArtwork['root_file'], (string)$selectedArtwork['main_file']]);
            $sourceMockups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($criterion === 'catalog') {
        $sourceType = 'catalog';
        $sourceLabel = 'Available Catalog';
        $stmt = $pdo->prepare("
            SELECT m.*, a.final_title AS artwork_title, s.title AS series_title
            FROM mockups m
            LEFT JOIN artworks a ON a.id = m.source_artwork_id
            LEFT JOIN artwork_series s ON s.id = m.series_id AND s.user_id = m.user_id
            WHERE m.user_id=?
            ORDER BY m.created_at DESC
            LIMIT 160
        ");
        $stmt->execute([$userId]);
        $sourceMockups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if ($sourceMockups) {
    usort($sourceMockups, static function (array $a, array $b) use ($favoriteLookup): int {
        $af = isset($favoriteLookup[(int)$a['id']]) ? 1 : 0;
        $bf = isset($favoriteLookup[(int)$b['id']]) ? 1 : 0;
        if ($af !== $bf) return $bf <=> $af;
        return strcmp((string)$b['created_at'], (string)$a['created_at']);
    });
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Social Media Campaigns - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css?v=social-square-buttons-lab-real-1">
    <style>
        .pinterest-campaign-bridge,.meta-campaign-bridge { margin-top:18px; padding:18px; border:1px solid rgba(189,8,28,.22); border-radius:8px; background:rgba(189,8,28,.035); }
        .meta-campaign-bridge { border-color:rgba(24,119,242,.22); background:rgba(24,119,242,.035); }
        .pinterest-campaign-bridge__head,.meta-campaign-bridge__head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:14px; }
        .pinterest-campaign-bridge__head h3,.meta-campaign-bridge__head h3 { margin:0 0 5px; }
        .pinterest-campaign-bridge__head p,.meta-campaign-bridge__head p { margin:0; }
        .pinterest-campaign-bridge__status { color:#8f0716; font-size:10px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; white-space:nowrap; }
        .meta-campaign-bridge__status { color:#0d57a8; font-size:10px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; white-space:nowrap; }
        .pinterest-campaign-bridge form,.meta-campaign-bridge form { display:grid; grid-template-columns:minmax(260px,1fr) minmax(180px,240px) auto; gap:10px; align-items:end; }
        .pinterest-campaign-bridge label,.meta-campaign-bridge label { display:grid; gap:6px; color:var(--muted); font-size:11px; }
        .pinterest-campaign-bridge input,.pinterest-campaign-bridge select,.meta-campaign-bridge input,.meta-campaign-bridge select { width:100%; }
        .pinterest-campaign-bridge__links,.meta-campaign-bridge__links { display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
        .current-campaign-tag{display:inline-flex;align-items:center;gap:7px;margin-bottom:8px;padding:6px 10px;border:1px solid rgba(166,128,83,.3);border-radius:999px;background:#faf5ed;color:#7f5d36;font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.current-campaign-tag::before{content:"";width:7px;height:7px;border-radius:50%;background:#bd8d55}
        .current-campaign-tag--published{border-color:rgba(70,139,91,.3);background:#edf7ef;color:#326744}.current-campaign-tag--published::before{background:#4f9a62}
        .meta-destinations { display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:10px;align-items:stretch;min-height:38px; }
        .meta-destination-option{display:grid!important;grid-template-columns:auto 1fr!important;gap:10px!important;align-items:center!important;padding:12px!important;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--text)!important;cursor:pointer}.meta-destination-option:has(input:checked){border-color:#6b96c7;background:#f3f8fe;box-shadow:0 0 0 1px rgba(24,119,242,.12)}.meta-destination-option input{width:auto!important;margin:0}.meta-destination-option strong,.meta-destination-option small{display:block}.meta-destination-option small{margin-top:3px;color:var(--muted);font-size:10px;line-height:1.35}
        .destination-summary{display:flex;gap:7px;flex-wrap:wrap;margin:10px 0}.destination-summary span{padding:6px 9px;border:1px solid var(--line);border-radius:999px;background:var(--surface);font-size:10px;font-weight:750;letter-spacing:.06em;text-transform:uppercase}
        .existing-meta-batches{display:grid;gap:8px;margin:10px 0 14px}.existing-meta-batch{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface)}.existing-meta-batch strong,.existing-meta-batch span{display:block}.existing-meta-batch span{margin-top:3px;color:var(--muted);font-size:10px}
        .campaign-history{margin-top:18px}.campaign-history .board-lane{background:#fafaf8}.campaign-history time{display:block;margin-top:8px;color:var(--muted);font-size:10px}
        @media(max-width:820px) { .pinterest-campaign-bridge form,.meta-campaign-bridge form { grid-template-columns:1fr; } }
        @media(max-width:620px){.meta-destinations{grid-template-columns:1fr}.existing-meta-batch{align-items:flex-start;flex-direction:column}}
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= sm_h($user['email']) ?></a></header>
        <div class="social-catalog">
            <div class="catalog-heading">
                <div>
                    <h1>Social Media</h1>
                    <p>Campaign → criterion → source → mockups → channels → draft.</p>
                </div>
            </div>

            <?php if ($notice): ?><div class="notice-card notice-ok"><?= sm_h($notice) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="notice-card notice-error"><?= sm_h($error) ?></div><?php endif; ?>

            <section class="catalog-panel catalog-panel--compact social-flow-panel">
                <div class="detail-heading">
                    <div>
                        <h2>1. Campaign Type</h2>
                        <p>Choose the intention first.</p>
                    </div>
                </div>
                <div class="social-square-grid">
                    <?php foreach ($types as $key => $type): ?>
                        <a class="social-square-button social-square-button--<?= sm_h($key) ?> <?= $campaignType === $key ? 'active' : '' ?>" href="<?= sm_h(sm_page_url(['campaign' => $key])) ?>">
                            <span><?= sm_h($type['title']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if ($campaignType !== ''): ?>
                <section class="catalog-panel catalog-panel--compact social-flow-panel">
                    <div class="detail-heading">
                        <div>
                            <h2>2. Criterion</h2>
                            <p>The selected campaign can start from any useful criterion.</p>
                        </div>
                    </div>
                    <div class="social-square-grid">
                        <?php foreach ($criteria as $key => $item): ?>
                            <a class="social-square-button social-square-button--<?= sm_h($key) ?> <?= $criterion === $key ? 'active' : '' ?>" href="<?= sm_h(sm_page_url(['campaign' => $campaignType, 'criterion' => $key])) ?>">
                                <span><?= sm_h($item['title']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($campaignType !== '' && $criterion !== ''): ?>
                <section class="catalog-panel catalog-panel--compact">
                    <div class="detail-heading">
                        <div>
                            <h2>3. Source</h2>
                            <p>Choose the concrete source for this campaign.</p>
                        </div>
                    </div>

                    <?php if ($criterion === 'series'): ?>
                        <?php if (!$seriesRows): ?>
                            <div class="empty-state">No series created yet.</div>
                        <?php else: ?>
                            <div class="series-overview-list social-source-series">
                                <?php foreach ($seriesRows as $series): $sid = (int)$series['id']; ?>
                                    <a class="series-overview-board social-source-card <?= $sourceId === (string)$sid ? 'active' : '' ?>" href="<?= sm_h(sm_page_url(['campaign' => $campaignType, 'criterion' => 'series', 'source_id' => $sid])) ?>">
                                        <div class="series-overview-head">
                                            <h3><?= sm_h($series['title']) ?></h3>
                                            <span><?= (int)$series['artwork_count'] ?> artworks</span>
                                        </div>
                                        <div class="series-overview-grid series-overview-grid--preview">
                                            <?php foreach (($seriesPreview[$sid] ?? []) as $artwork): $title = trim((string)$artwork['final_title']) ?: 'Untitled'; $file = (string)($artwork['root_file'] ?: $artwork['main_file']); ?>
                                                <span class="series-overview-card"><img src="<?= sm_h(sm_media_url($file, 360)) ?>" alt="<?= sm_h($title) ?>"><strong><?= sm_h($title) ?></strong></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php elseif (in_array($criterion, ['artwork', 'symbolism'], true)): ?>
                        <div class="catalog-thumbnail-grid">
                            <?php foreach ($artworkRows as $artwork): $title = trim((string)$artwork['final_title']) ?: 'Untitled artwork'; $file = (string)($artwork['root_file'] ?: $artwork['main_file']); ?>
                                <a class="website-card website-card-link <?= $sourceId === (string)$artwork['id'] ? 'active' : '' ?>" href="<?= sm_h(sm_page_url(['campaign' => $campaignType, 'criterion' => $criterion, 'source_id' => (int)$artwork['id']])) ?>">
                                    <div class="website-card__image"><img src="<?= sm_h(sm_media_url($file, 600)) ?>" alt="<?= sm_h($title) ?>"></div>
                                    <div class="website-card__summary">
                                        <h2><?= sm_h($title) ?></h2>
                                        <?php $seriesTitle = ArtworkSeries::display((string)($artwork['series_title'] ?: $artwork['series'])); if ($seriesTitle !== ''): ?><p class="website-card__subtitle"><?= sm_h($seriesTitle) ?></p><?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($criterion === 'catalog'): ?>
                        <a class="campaign-step-card active" href="<?= sm_h(sm_page_url(['campaign' => $campaignType, 'criterion' => 'catalog', 'source_id' => 'available_catalog'])) ?>">
                            <h3>Available Catalog</h3>
                            <p>Use the complete available mockup pool as campaign material.</p>
                        </a>
                    <?php else: ?>
                        <div class="empty-state">This criterion needs its source model next.</div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($campaignType !== '' && $criterion !== '' && $sourceId !== '' && $sourceLabel !== ''): ?>
                <section class="catalog-panel catalog-panel--compact">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= sm_h($_SESSION['social_campaign_csrf']) ?>">
                        <input type="hidden" name="action" value="create_campaign_selection">
                        <input type="hidden" name="campaign_type" value="<?= sm_h($campaignType) ?>">
                        <input type="hidden" name="criterion" value="<?= sm_h($criterion) ?>">
                        <input type="hidden" name="source_type" value="<?= sm_h($sourceType) ?>">
                        <input type="hidden" name="source_id" value="<?= sm_h($sourceId) ?>">
                        <input type="hidden" name="source_label" value="<?= sm_h($sourceLabel) ?>">

                        <div class="detail-heading social-channel-heading">
                            <div>
                                <h2>4. Destination</h2>
                                <p>Choose the publishing medium first; mockup selection depends on this.</p>
                            </div>
                        </div>
                        <div class="social-channel-grid">
                            <?php foreach ($channels as $key => $channel): ?>
                                <label class="social-channel-card">
                                    <input type="checkbox" name="channels[]" value="<?= sm_h($key) ?>">
                                    <strong><?= sm_h($channel['title']) ?></strong>
                                    <span><?= sm_h($channel['description']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="detail-heading">
                            <div>
                                <h2>5. Mockups</h2>
                                <p><?= sm_h($sourceLabel) ?> · choose the visual material after the destination. Pinterest batches accept up to 10 mockups.</p>
                            </div>
                        </div>

                        <?php if (!$sourceMockups): ?>
                            <div class="empty-state">No mockups found for this source.</div>
                        <?php else: ?>
                            <div class="social-mockup-select-grid">
                                <?php foreach ($sourceMockups as $mockup): ?>
                                    <?php $isFavorite = isset($favoriteLookup[(int)$mockup['id']]); $label = trim((string)($mockup['artwork_title'] ?? '')) ?: Display::contextTitle((string)$mockup['context_id']); ?>
                                    <label class="social-mockup-card <?= $isFavorite ? 'is-favorite' : '' ?>">
                                        <input type="checkbox" name="mockup_ids[]" value="<?= (int)$mockup['id'] ?>">
                                        <img src="<?= sm_h(sm_media_url((string)$mockup['mockup_file'], 520)) ?>" alt="<?= sm_h($label) ?>" loading="lazy">
                                        <strong><?= sm_h(Display::contextTitle((string)$mockup['context_id'])) ?></strong>
                                        <?php if ($isFavorite): ?><span>Favorite</span><?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="social-campaign-submit">
                            <button type="submit">Create Campaign Draft</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <?php if ($openDraft): ?>
                <?php
                $draftChannels = sm_social_payload_channels(is_array($openDraftPayload) ? $openDraftPayload : null);
                $draftChannelTitles = sm_channel_titles($draftChannels);
                $draftMockupIds = is_array($openDraftPayload) ? array_values(array_filter(array_map('intval', (array)($openDraftPayload['mockup_ids'] ?? [])))) : [];
                $hasPinterest = in_array('pinterest', $draftChannels, true);
                $hasMeta = in_array('meta_media', $draftChannels, true);
                $pinterestBatchId = is_array($openDraftPayload) ? max(0, (int)($openDraftPayload['pinterest']['batch_id'] ?? 0)) : 0;
                $pinterestStatus = is_array($openDraftPayload) ? (string)($openDraftPayload['pinterest']['status'] ?? 'draft') : 'draft';
                $metaBatches = sm_meta_batches(is_array($openDraftPayload) ? $openDraftPayload : null);
                $usedMetaDestinations = [];
                foreach ($metaBatches as $existingMetaBatch) $usedMetaDestinations = array_merge($usedMetaDestinations, $existingMetaBatch['destinations']);
                $usedMetaDestinations = array_values(array_unique($usedMetaDestinations));
                $availableMetaDestinations = array_values(array_diff(['facebook', 'instagram'], $usedMetaDestinations));
                $metaStatus = is_array($openDraftPayload) ? (string)($openDraftPayload['meta']['status'] ?? 'draft') : 'draft';
                $metaDestinations = sm_meta_destination_titles(is_array($openDraftPayload) ? $openDraftPayload : null);
                $campaignPublished = (string)$openDraft['status'] === 'published';
                $modifyUrl = sm_page_url([
                    'campaign' => (string)$openDraft['campaign_type'],
                    'criterion' => (string)($openDraftPayload['criterion'] ?? $openDraft['source_type']),
                    'source_id' => (string)$openDraft['source_id'],
                ]);
                ?>
                <section class="catalog-panel catalog-panel--compact">
                    <div class="detail-heading">
                        <div>
                            <span class="current-campaign-tag <?= $campaignPublished ? 'current-campaign-tag--published' : '' ?>"><?= $campaignPublished ? 'Published history' : 'Current draft · not a new publication yet' ?></span>
                            <h2><?= $campaignPublished ? 'Published Campaign' : 'Current Campaign Draft' ?></h2>
                            <p>Campaign #<?= (int)$openDraft['id'] ?> · <?= sm_h($openDraft['title']) ?></p>
                        </div>
                        <a class="button-link secondary" href="social_media_catalog.php">Close current campaign</a>
                    </div>
                    <div class="copy-grid">
                        <article class="copy-card"><h3>Status</h3><p><?= sm_h(sm_status_label((string)$openDraft['status'])) ?></p></article>
                        <article class="copy-card"><h3>Source</h3><p><?= sm_h($openDraft['source_label']) ?></p></article>
                        <article class="copy-card"><h3>Campaign channels</h3><p><?= sm_h(implode(', ', $draftChannelTitles)) ?></p></article>
                        <article class="copy-card"><h3>Mockups</h3><p><?= count($draftMockupIds) ?> selected</p></article>
                    </div>
                    <p><?= sm_h($openDraft['objective']) ?></p>
                    <?php if ($openDraftMockups): ?>
                        <div class="social-mockup-select-grid">
                            <?php foreach ($openDraftMockups as $mockup): $label = trim((string)($mockup['artwork_title'] ?? '')) ?: Display::contextTitle((string)$mockup['context_id']); ?>
                                <article class="social-mockup-card">
                                    <img src="<?= sm_h(sm_media_url((string)$mockup['mockup_file'], 520)) ?>" alt="<?= sm_h($label) ?>" loading="lazy">
                                    <strong><?= sm_h(Display::contextTitle((string)$mockup['context_id'])) ?></strong>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">No mockups selected.</div>
                    <?php endif; ?>
                    <?php if ($hasPinterest): ?>
                        <section class="pinterest-campaign-bridge">
                            <div class="pinterest-campaign-bridge__head">
                                <div>
                                    <h3>Pinterest campaign</h3>
                                    <p>Prepare the selected mockups, then review boards, copy, crop and destination before approving publication.</p>
                                </div>
                                <span class="pinterest-campaign-bridge__status"><?= sm_h($pinterestStatus) ?></span>
                            </div>
                            <?php if ($pinterestBatchId > 0): ?>
                                <a class="button-link primary" href="pinterest_batch_review.php?id=<?= $pinterestBatchId ?>">Review Pinterest batch</a>
                            <?php elseif (count($draftMockupIds) <= 10): ?>
                                <form method="post" action="pinterest_batch_create.php">
                                    <input type="hidden" name="csrf" value="<?= sm_h($_SESSION['pinterest_batch_create_csrf']) ?>">
                                    <input type="hidden" name="campaign_id" value="<?= (int)$openDraft['id'] ?>">
                                    <label>
                                        Destination link
                                        <input type="url" name="destination_url" value="<?= sm_h($pinterestDefaultDestination) ?>" placeholder="https://example.com/artwork" required>
                                    </label>
                                    <?php if ($isAdmin): ?>
                                        <label>
                                            Pinterest identity
                                            <select name="purpose">
                                                <option value="artist">Artist account<?= (($pinterestConnections['artist']['status'] ?? '') === 'connected') ? ' · connected' : '' ?></option>
                                                <option value="platform">Artwork Mockups<?= (($pinterestConnections['platform']['status'] ?? '') === 'connected') ? ' · connected' : '' ?></option>
                                            </select>
                                        </label>
                                    <?php else: ?>
                                        <input type="hidden" name="purpose" value="artist">
                                    <?php endif; ?>
                                    <button class="button-link primary" type="submit">Prepare Pinterest batch</button>
                                </form>
                            <?php else: ?>
                                <div class="notice-card notice-error">Pinterest accepts a maximum of 10 mockups. Modify this campaign before preparing the batch.</div>
                            <?php endif; ?>
                            <div class="pinterest-campaign-bridge__links">
                                <a class="button-link secondary" href="connections.php#pinterest">Manage connection</a>
                                <span><?= count($draftMockupIds) ?> mockups selected · nothing is published during preparation.</span>
                            </div>
                        </section>
                    <?php endif; ?>
                    <?php if ($hasMeta): ?>
                        <?php
                        $artistFacebookConnected = (($metaConnections['artist']['status'] ?? '') === 'connected');
                        $artistInstagramConnected = (($instagramConnections['artist']['status'] ?? '') === 'connected');
                        $artistInstagramUsername = trim((string)($instagramConnections['artist']['username'] ?? ''));
                        $platformFacebookConnected = (($metaConnections['platform']['status'] ?? '') === 'connected');
                        $platformInstagramConnected = (($instagramConnections['platform']['status'] ?? '') === 'connected');
                        ?>
                        <section class="meta-campaign-bridge">
                            <div class="meta-campaign-bridge__head">
                                <div>
                                    <h3>Facebook / Instagram publication</h3>
                                    <p><?= $metaBatches ? 'Completed and pending publications remain separate. You may prepare only a destination that has not been used yet.' : 'Choose Facebook, Instagram, or both explicitly. Nothing is selected automatically.' ?></p>
                                </div>
                                <span class="meta-campaign-bridge__status"><?= sm_h(sm_status_label($metaStatus)) ?></span>
                            </div>
                            <?php if ($metaBatches): ?>
                                <div class="existing-meta-batches" aria-label="Existing publication batches">
                                    <?php foreach ($metaBatches as $existingMetaBatch): ?>
                                        <div class="existing-meta-batch">
                                            <div>
                                                <strong><?= sm_h(implode(' + ', array_map('ucfirst', $existingMetaBatch['destinations']))) ?></strong>
                                                <span><?= sm_h(sm_status_label((string)$existingMetaBatch['status'])) ?> · Batch #<?= (int)$existingMetaBatch['batch_id'] ?></span>
                                            </div>
                                            <a class="button-link secondary" href="meta_batch_review.php?id=<?= (int)$existingMetaBatch['batch_id'] ?>"><?= (string)$existingMetaBatch['status'] === 'published' ? 'Open history' : 'Continue review' ?></a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($availableMetaDestinations && count($draftMockupIds) <= 10): ?>
                                <form method="post" action="meta_batch_create.php" data-meta-destination-form>
                                    <input type="hidden" name="csrf" value="<?= sm_h($_SESSION['meta_batch_create_csrf']) ?>">
                                    <input type="hidden" name="campaign_id" value="<?= (int)$openDraft['id'] ?>">
                                    <div>
                                        <span style="display:block;margin-bottom:6px;color:var(--muted);font-size:11px">Choose destinations for this new batch</span>
                                        <div class="meta-destinations">
                                            <?php if (in_array('facebook', $availableMetaDestinations, true)): ?><label class="meta-destination-option">
                                                <input type="checkbox" name="meta_channels[]" value="facebook">
                                                <span><strong>Facebook</strong><small>Artwork Mockups Page · <?= $artistFacebookConnected ? 'connected' : 'not connected' ?></small></span>
                                            </label><?php endif; ?>
                                            <?php if (in_array('instagram', $availableMetaDestinations, true)): ?><label class="meta-destination-option">
                                                <input type="checkbox" name="meta_channels[]" value="instagram">
                                                <span><strong>Instagram</strong><small><?= $artistInstagramUsername !== '' ? '@'.sm_h($artistInstagramUsername) : 'Artist account' ?> · <?= $artistInstagramConnected ? 'connected' : 'not connected' ?></small></span>
                                            </label><?php endif; ?>
                                        </div>
                                    </div>
                                    <label>
                                        Destination link · optional
                                        <input type="url" name="destination_url" value="<?= sm_h($pinterestDefaultDestination) ?>" placeholder="https://mauriziovalch.com/artwork/...">
                                    </label>
                                    <?php if ($isAdmin): ?>
                                        <label>
                                            Publishing identity
                                            <select name="purpose">
                                                <option value="artist">Maurizio Valch · artist connections</option>
                                                <option value="platform">Artwork Mockups platform · Facebook <?= $platformFacebookConnected ? 'connected' : 'not connected' ?> / Instagram <?= $platformInstagramConnected ? 'connected' : 'not connected' ?></option>
                                            </select>
                                        </label>
                                    <?php else: ?>
                                        <input type="hidden" name="purpose" value="artist">
                                    <?php endif; ?>
                                    <button class="button-link primary" type="submit" data-create-meta-batch>Prepare selected destination</button>
                                </form>
                            <?php elseif ($availableMetaDestinations && count($draftMockupIds) > 10): ?>
                                <div class="notice-card notice-error">Meta batches support a maximum of 10 mockups. Modify this campaign before preparing the batch.</div>
                            <?php elseif (!$availableMetaDestinations): ?>
                                <div class="notice-card">Facebook and Instagram already have publication batches for this campaign.</div>
                            <?php endif; ?>
                            <div class="meta-campaign-bridge__links">
                                <a class="button-link secondary" href="connections.php#facebook">Facebook connection</a>
                                <a class="button-link secondary" href="connections.php#instagram">Instagram connection</a>
                                <span><?= count($draftMockupIds) ?> mockups in this campaign · creating a batch never publishes.</span>
                            </div>
                        </section>
                    <?php endif; ?>
                    <?php if (!$campaignPublished): ?><div class="draft-actions">
                        <a class="button-link secondary" href="<?= sm_h($modifyUrl) ?>">Modify current draft</a>
                        <form method="post" onsubmit="return confirm('Delete this campaign draft?');">
                            <input type="hidden" name="csrf" value="<?= sm_h($_SESSION['social_campaign_csrf']) ?>">
                            <input type="hidden" name="campaign_id" value="<?= (int)$openDraft['id'] ?>">
                            <button class="button-link secondary" name="action" value="delete_campaign">Delete current draft</button>
                        </form>
                    </div><?php endif; ?>
                </section>
            <?php endif; ?>

            <?php
            $otherActiveCampaigns = array_values(array_filter($activeCampaigns, static fn (array $campaign): bool => (int)$campaign['id'] !== $openDraftId));
            $otherPublishedCampaigns = array_values(array_filter($publishedCampaigns, static fn (array $campaign): bool => (int)$campaign['id'] !== $openDraftId));
            ?>
            <section class="catalog-panel catalog-panel--compact">
                <div class="detail-heading">
                    <div>
                        <h2>Active Campaign Drafts</h2>
                        <p>Unpublished campaigns only. The current campaign shown above is not repeated here.</p>
                    </div>
                </div>
                <?php if (!$otherActiveCampaigns): ?>
                    <div class="empty-state"><?= $openDraft && !$campaignPublished ? 'No other active campaign drafts.' : 'No active campaign drafts.' ?></div>
                <?php else: ?>
                    <div class="board-lanes">
                        <?php foreach ($otherActiveCampaigns as $campaign): $payload = json_decode((string)$campaign['payload_json'], true); ?>
                            <article class="board-lane">
                                <h2><?= sm_h($campaign['title']) ?></h2>
                                <p><?= sm_h($campaign['objective']) ?></p>
                                <p><strong>Source:</strong> <?= sm_h($campaign['source_label']) ?></p>
                                <?php $campaignChannels = sm_social_payload_channels(is_array($payload) ? $payload : null); ?>
                                <?php $campaignMockups = is_array($payload) ? array_values(array_filter(array_map('intval', (array)($payload['mockup_ids'] ?? [])))) : []; ?>
                                <?php if ($campaignChannels): ?><p><strong>Channels:</strong> <?= sm_h(implode(', ', sm_channel_titles($campaignChannels))) ?></p><?php endif; ?>
                                <p><strong>Mockups:</strong> <?= count($campaignMockups) ?></p>
                                <span class="status-pill <?= sm_h(sm_status_class((string)$campaign['status'])) ?>"><?= sm_h(sm_status_label((string)$campaign['status'])) ?></span>
                                <?php
                                $modifyUrl = '#';
                                if (is_array($payload)) {
                                    $modifyUrl = sm_page_url([
                                        'campaign' => (string)$campaign['campaign_type'],
                                        'criterion' => (string)($payload['criterion'] ?? $campaign['source_type']),
                                        'source_id' => (string)$campaign['source_id'],
                                    ]);
                                }
                                ?>
                                <div class="draft-actions">
                                    <a class="button-link secondary" href="<?= sm_h(sm_page_url(['draft' => (int)$campaign['id']])) ?>">Open this draft</a>
                                    <a class="button-link secondary" href="<?= sm_h($modifyUrl) ?>">Modify this draft</a>
                                    <form method="post" onsubmit="return confirm('Delete this campaign draft?');">
                                        <input type="hidden" name="csrf" value="<?= sm_h($_SESSION['social_campaign_csrf']) ?>">
                                        <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
                                        <button class="button-link secondary" name="action" value="delete_campaign">Delete</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="catalog-panel catalog-panel--compact campaign-history">
                <div class="detail-heading">
                    <div>
                        <h2>Publication History</h2>
                        <p>Published destinations remain as history. Open a record when another destination is still available.</p>
                    </div>
                </div>
                <?php if (!$otherPublishedCampaigns): ?>
                    <div class="empty-state"><?= $openDraft && $campaignPublished ? 'The publication shown above is the current history record.' : 'No completed campaign publications yet.' ?></div>
                <?php else: ?>
                    <div class="board-lanes">
                        <?php foreach ($otherPublishedCampaigns as $campaign): $payload = json_decode((string)$campaign['payload_json'], true); $campaignChannels = sm_social_payload_channels(is_array($payload) ? $payload : null); $publishedDestinations = sm_meta_destination_titles(is_array($payload) ? $payload : null); $missingDestinations = array_values(array_diff(['Facebook', 'Instagram'], $publishedDestinations)); ?>
                            <article class="board-lane">
                                <span class="current-campaign-tag current-campaign-tag--published"><?= $missingDestinations ? sm_h(implode(' / ', $publishedDestinations)) . ' published · ' . sm_h(implode(' / ', $missingDestinations)) . ' available' : 'Published' ?></span>
                                <h2><?= sm_h($campaign['title']) ?></h2>
                                <p><strong>Source:</strong> <?= sm_h($campaign['source_label']) ?></p>
                                <?php if ($campaignChannels): ?><p><strong>Campaign channels:</strong> <?= sm_h(implode(', ', sm_channel_titles($campaignChannels))) ?></p><?php endif; ?>
                                <?php if ($publishedDestinations): ?><p><strong>Facebook / Instagram destinations:</strong> <?= sm_h(implode(', ', $publishedDestinations)) ?></p><?php endif; ?>
                                <time datetime="<?= sm_h((string)$campaign['updated_at']) ?>">Completed <?= sm_h((string)$campaign['updated_at']) ?></time>
                                <div class="draft-actions"><a class="button-link secondary" href="<?= sm_h(sm_page_url(['draft' => (int)$campaign['id']])) ?>"><?= $missingDestinations ? 'Continue with ' . sm_h(implode(' / ', $missingDestinations)) : 'Open publication history' ?></a></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
<script>
document.querySelectorAll('[data-meta-destination-form]').forEach(form=>{
    const destinations=[...form.querySelectorAll('input[name="meta_channels[]"]')];
    form.addEventListener('submit',event=>{
        if(!destinations.some(input=>input.checked)){
            event.preventDefault();
            alert('Choose Facebook, Instagram, or both for this new publication batch.');
        }
    });
});
</script>
</body>
</html>
