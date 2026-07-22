<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::WEBSITE_MANAGE, 'Studio Notes');
$pdo = Database::connection();
$userId = (int)$user['id'];

$notice = '';
$error = '';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$websiteBoard = new WebsiteBoardService($pdo);
$studioSources = $websiteBoard->sources($userId);
$studioSourceLookup = [];
foreach ($studioSources as $studioSource) {
    $studioSourceLookup[(string)$studioSource['key']] = $studioSource;
}
if (empty($_SESSION['studio_notes_csrf'])) {
    $_SESSION['studio_notes_csrf'] = bin2hex(random_bytes(24));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['studio_notes_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Invalid session token.');
        }
        $action = (string)($_POST['action'] ?? '');
        
        if ($action === 'create_draft') {
            $title = trim((string)($_POST['title'] ?? ''));
            $sourceKey = trim((string)($_POST['source_key'] ?? ''));
            $source = $sourceKey !== '' ? ($studioSourceLookup[$sourceKey] ?? null) : null;
            if ($sourceKey !== '' && !is_array($source)) {
                throw new RuntimeException('The selected source is not available.');
            }
            if ($title === '') {
                $title = $source ? trim((string)$source['label']) . ' — Studio Note' : 'New Studio Note';
            }
            $now = date('c');
            $payload = [
                'channels' => ['website_blog'],
                'destinations' => ['website_blog'],
                'mockup_ids' => $source && (string)$source['type'] === 'mockup' ? [(int)$source['id']] : [],
                'channel_status' => ['website_blog' => 'draft'],
            ];
            if ($source) {
                $payload['source'] = $source;
                $payload['media'] = [$source];
            }
            $stmt = $pdo->prepare('INSERT INTO social_campaigns (user_id, campaign_type, title, objective, source_type, source_id, source_label, status, payload_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $userId,
                'website_blog',
                $title,
                '',
                $source ? (string)$source['type'] : 'custom',
                $source ? (string)$source['id'] : '',
                $source ? (string)$source['label'] : 'Independent note',
                'draft',
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $now,
                $now,
            ]);
            $newId = $pdo->lastInsertId();
            $_SESSION['wsn_notice'] = 'Borrador creado correctamente.';
            header('Location: website_studio_notes.php?draft=' . $newId);
            exit;
        }
        
        if ($action === 'save_draft' || $action === 'publish_draft') {
            $id = max(0, (int)($_POST['draft_id'] ?? 0));
            $title = trim((string)($_POST['title'] ?? ''));
            $objective = trim((string)($_POST['objective'] ?? ''));
            if ($title === '') throw new RuntimeException('The title is required.');
            $saved = $websiteBoard->saveNote($userId, $id, $title, $objective);
            $currentStatus = (string)($saved['status'] ?? 'draft');
            if ($action === 'publish_draft' && $currentStatus !== 'published') {
                $websiteBoard->noteAction($userId, $id, 'publish');
            } elseif ($action === 'save_draft' && $currentStatus === 'published') {
                $websiteBoard->noteAction($userId, $id, 'unpublish');
            }
            
            $_SESSION['wsn_notice'] = $action === 'publish_draft' ? 'Studio Note published successfully.' : 'Draft saved successfully.';
            header('Location: website_studio_notes.php?draft=' . $id);
            exit;
        }
        
        if ($action === 'delete_draft') {
            $id = max(0, (int)($_POST['draft_id'] ?? 0));
            $stmt = $pdo->prepare('DELETE FROM social_campaigns WHERE id=? AND user_id=?');
            $stmt->execute([$id, $userId]);
            $_SESSION['wsn_notice'] = 'Nota de estudio eliminada.';
            header('Location: website_studio_notes.php');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (!empty($_SESSION['wsn_notice'])) {
    $notice = $_SESSION['wsn_notice'];
    unset($_SESSION['wsn_notice']);
}

function wsn_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function wsn_media_url(?string $file, int $width = 520): string
{
    $file = basename((string)$file);
    return $file !== '' ? 'media.php?file=' . rawurlencode($file) . '&thumb=1&w=' . max(240, min(900, $width)) : '';
}

function first_html_image_src(string $html): string
{
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
        return $matches[1];
    }
    return '';
}
function wsn_ensure_campaign_table(PDO $pdo): void
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
function wsn_website_payload(?array $payload): bool
{
    return is_array($payload) && in_array('website_blog', array_map('strval', (array)($payload['channels'] ?? [])), true);
}
wsn_ensure_campaign_table($pdo);

$draftId = max(0, (int)($_GET['draft'] ?? 0));
$allStmt = $pdo->prepare("SELECT * FROM social_campaigns WHERE user_id=? ORDER BY id DESC LIMIT 80");
$allStmt->execute([$userId]);
$websiteDrafts = [];
foreach ($allStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $payload = json_decode((string)$row['payload_json'], true);
    if (wsn_website_payload(is_array($payload) ? $payload : null)) {
        $row['_payload'] = $payload;
        $websiteDrafts[] = $row;
    }
}

$openDraft = null;
foreach ($websiteDrafts as $draft) {
    if ((int)$draft['id'] === $draftId) {
        $openDraft = $draft;
        break;
    }
}
$openPayload = $openDraft ? (array)$openDraft['_payload'] : [];
$openSource = null;
if ($openDraft) {
    $payloadSource = is_array($openPayload['source'] ?? null) ? $openPayload['source'] : [];
    $payloadSourceKey = trim((string)($payloadSource['key'] ?? ''));
    if ($payloadSourceKey !== '' && isset($studioSourceLookup[$payloadSourceKey])) {
        $openSource = $studioSourceLookup[$payloadSourceKey];
    } else {
        $fallbackKey = trim((string)$openDraft['source_type']) . ':' . trim((string)$openDraft['source_id']);
        if (isset($studioSourceLookup[$fallbackKey])) $openSource = $studioSourceLookup[$fallbackKey];
    }
}

$openMedia = [];
if ($openSource) {
    $seenMediaFiles = [];
    $appendMedia = static function (array $source) use (&$openMedia, &$seenMediaFiles): void {
        $file = basename((string)($source['file'] ?? ''));
        if ($file === '' || isset($seenMediaFiles[$file]) || count($openMedia) >= 200) return;
        $seenMediaFiles[$file] = true;
        $openMedia[] = $source;
    };
    $appendMedia($openSource);

    $openType = (string)($openSource['type'] ?? '');
    $openArtworkId = (int)($openSource['artworkId'] ?? ($openType === 'artwork' ? ($openSource['id'] ?? 0) : 0));
    $openSeriesId = (int)($openSource['seriesId'] ?? ($openType === 'series' ? ($openSource['id'] ?? 0) : 0));
    foreach ($studioSources as $candidate) {
        $candidateKey = (string)($candidate['key'] ?? '');
        if ($candidateKey === (string)($openSource['key'] ?? '')) continue;
        $candidateType = (string)($candidate['type'] ?? '');
        $related = false;
        if ($openType === 'series' && $openSeriesId > 0) {
            $related = (int)($candidate['seriesId'] ?? 0) === $openSeriesId && $candidateType !== 'series';
        } elseif ($openArtworkId > 0) {
            $related = (int)($candidate['artworkId'] ?? ($candidateType === 'artwork' ? ($candidate['id'] ?? 0) : 0)) === $openArtworkId;
        }
        if ($related) $appendMedia($candidate);
    }
} else {
    $seenMediaFiles = [];
    foreach ($studioSources as $candidate) {
        $file = basename((string)($candidate['file'] ?? ''));
        if ($file === '' || isset($seenMediaFiles[$file])) continue;
        $seenMediaFiles[$file] = true;
        $openMedia[] = $candidate;
        if (count($openMedia) >= 24) break;
    }
}

$relatedMediaKeys = [];
foreach ($openMedia as $media) {
    $relatedMediaKeys[(string)($media['key'] ?? '')] = true;
}
$mediaLibrary = [];
$mediaLibraryFiles = [];
foreach (array_merge($openMedia, $studioSources) as $media) {
    $file = basename((string)($media['file'] ?? ''));
    if ($file === '' || isset($mediaLibraryFiles[$file])) continue;
    $mediaLibraryFiles[$file] = true;
    $mediaLibrary[] = $media;
    if (count($mediaLibrary) >= 400) break;
}
$mediaDefaultFilter = $openSource ? 'related' : 'all';

$requestedSourceKey = trim((string)($_GET['source'] ?? ''));
if ($requestedSourceKey !== '' && !isset($studioSourceLookup[$requestedSourceKey])) {
    $requestedSourceKey = '';
}
$sourcesByType = ['artwork' => [], 'series' => [], 'mockup' => []];
foreach ($studioSources as $studioSource) {
    $type = (string)($studioSource['type'] ?? '');
    if (isset($sourcesByType[$type])) $sourcesByType[$type][] = $studioSource;
}
$initialSourceType = $requestedSourceKey !== ''
    ? (string)($studioSourceLookup[$requestedSourceKey]['type'] ?? 'artwork')
    : 'artwork';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Studio Notes - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
    <style>
        .studio-notes-page { padding:28px 24px 80px; }
        .studio-notes-page .catalog-heading { margin-bottom:18px; }
        .studio-source-stage { padding:0 0 22px; border-bottom:1px solid var(--line); }
        .studio-source-toolbar { display:flex; align-items:center; justify-content:space-between; gap:18px; margin-bottom:12px; }
        .studio-source-tabs { display:flex; align-items:center; gap:22px; overflow-x:auto; }
        .studio-source-tab { width:auto !important; min-height:auto !important; margin:0 !important; padding:7px 0 9px !important; border:0 !important; border-bottom:2px solid transparent !important; border-radius:0 !important; background:transparent !important; color:#554d46 !important; box-shadow:none !important; font-size:15px !important; font-weight:600 !important; white-space:nowrap; }
        .studio-source-tab.is-active { border-bottom-color:rgba(224,104,76,.65) !important; color:var(--ink) !important; }
        .studio-source-workline { display:grid; grid-template-columns:minmax(0,1fr) 164px; align-items:start; gap:40px; }
        .studio-source-panels { min-width:0; }
        .studio-source-panel[hidden] { display:none; }
        .studio-source-rail { display:grid; grid-auto-flow:column; grid-auto-columns:clamp(150px,13vw,188px); gap:12px; overflow-x:auto; overflow-y:hidden; padding:1px 1px 11px; scroll-snap-type:x proximity; scrollbar-width:thin; scrollbar-color:#c8beb4 transparent; }
        .studio-source-rail::-webkit-scrollbar { height:7px; }
        .studio-source-rail::-webkit-scrollbar-thumb { border-radius:999px; background:#c8beb4; }
        .studio-source-card { position:relative; display:grid; align-content:start; min-width:0; margin:0; border:0; background:transparent; cursor:pointer; scroll-snap-align:start; }
        .studio-source-card > input { position:absolute; width:1px; height:1px; opacity:0; pointer-events:none; }
        .studio-source-card__image { aspect-ratio:4/5; padding:5px; border:1px solid #bbb7b1; background:#fff; }
        .studio-source-card__image img { display:block; width:100%; height:100%; object-fit:cover; }
        .studio-source-card__label { display:block; min-width:0; padding:8px 1px 1px; overflow:hidden; color:var(--ink); font:400 17px/1.15 var(--font-serif); text-overflow:ellipsis; white-space:nowrap; }
        .studio-source-card > input:checked + .studio-source-card__image { border-color:#ae7258; box-shadow:0 0 0 2px rgba(224,104,76,.22); }
        .studio-create-decision { width:164px !important; min-width:164px !important; height:164px !important; min-height:164px !important; align-self:start; margin-top:clamp(12px, calc(8.125vw - 82px), 36px) !important; padding:18px !important; }
        .studio-create-decision__content { display:flex !important; align-items:center; flex-direction:column; justify-content:center; gap:10px; }
        .studio-create-decision .studio-create-decision__plus { display:block !important; font-size:48px !important; font-weight:300 !important; line-height:.72 !important; letter-spacing:0 !important; }
        .studio-create-decision .studio-create-decision__label { display:block !important; font-size:14px !important; line-height:1 !important; letter-spacing:.12em !important; }
        .studio-editor-workspace { max-width:1500px; margin:0 auto; padding:28px 30px 32px; border:1px solid var(--line); border-radius:4px; background:var(--surface); }
        .studio-note-editor-top { display:flex; justify-content:flex-end; margin-bottom:8px; }
        .studio-note-editor-top a { color:#625b55; font-size:12px; font-weight:650; letter-spacing:.05em; text-decoration:none; text-transform:uppercase; }
        .studio-note-editor-shell { display:grid; grid-template-columns:minmax(0,960px) minmax(320px,420px); justify-content:center; gap:24px; }
        .studio-note-editor-shell--text-only { grid-template-columns:minmax(0,1020px); }
        .studio-notes-page input.studio-note-editor-title { width:100%; box-sizing:border-box; margin:0 0 18px; padding:5px 2px 14px; border:0; border-bottom:1px solid var(--line); border-radius:0; background:transparent; color:var(--ink); font-family:var(--font-serif); font-size:38px; font-weight:400; line-height:1.2; }
        .studio-notes-page input.studio-note-editor-title:focus { outline:0; border-bottom-color:var(--accent); box-shadow:none; }
        .studio-notes-page .studio-note-editor.ql-container { height:520px !important; min-height:520px; border:1px solid var(--line); border-radius:0 0 4px 4px; background:#fff; color:var(--ink); font-family:var(--font-sans); font-size:19px; line-height:1.72; }
        .studio-notes-page .ql-toolbar.ql-snow { padding:10px 14px; border:1px solid var(--line); border-radius:4px 4px 0 0; background:#fbfaf7; }
        .studio-notes-page .studio-note-editor .ql-editor { min-height:518px; padding:36px 44px; color:var(--ink); font-family:var(--font-sans); font-size:19px; line-height:1.72; }
        .studio-notes-page .studio-note-editor .ql-editor p { margin:0 0 1em; }
        .studio-notes-page .studio-note-editor .ql-editor img { display:block; width:auto; max-width:min(100%, 520px); max-height:380px; margin:22px auto 22px 0; object-fit:contain; cursor:pointer; transition:outline-color .14s ease; }
        .studio-notes-page .studio-note-editor .ql-editor img[data-editor-size="small"] { max-width:min(100%, 300px); max-height:260px; }
        .studio-notes-page .studio-note-editor .ql-editor img[data-editor-size="medium"] { max-width:min(100%, 520px); max-height:380px; }
        .studio-notes-page .studio-note-editor .ql-editor img[data-editor-size="large"] { max-width:min(100%, 760px); max-height:520px; }
        .studio-notes-page .studio-note-editor .ql-editor img[data-editor-align="left"] { margin-left:0; margin-right:auto; }
        .studio-notes-page .studio-note-editor .ql-editor img[data-editor-align="center"] { margin-left:auto; margin-right:auto; }
        .studio-notes-page .studio-note-editor .ql-editor img[data-editor-align="right"] { margin-left:auto; margin-right:0; }
        .studio-notes-page .studio-note-editor .ql-editor img.is-selected { outline:2px solid #aa96b1; outline-offset:4px; }
        .studio-notes-page .studio-note-editor.ql-container.is-media-drop-target { border-color:#9b86a4; background:#faf7fb; box-shadow:inset 0 0 0 3px rgba(184,164,192,.24); }
        .studio-image-tools { display:flex; align-items:center; gap:12px; padding:9px 12px; border:1px solid var(--line); border-top:0; background:#f3eef4; }
        .studio-image-tools[hidden] { display:none; }
        .studio-image-tools__label { color:#514951; font:400 16px/1 var(--font-serif); }
        .studio-image-tools__group { display:flex; align-items:center; gap:4px; padding-left:12px; border-left:1px solid #d5cad8; }
        .studio-image-tools button { width:auto !important; min-width:0 !important; margin:0 !important; padding:7px 10px !important; border:1px solid transparent !important; border-radius:2px !important; background:transparent !important; color:#514951 !important; box-shadow:none !important; font-size:11px !important; letter-spacing:.04em !important; }
        .studio-image-tools button:hover,
        .studio-image-tools button.is-active { border-color:#c2b2c7 !important; background:#dfd3e2 !important; transform:none !important; box-shadow:none !important; }
        .studio-image-tools .studio-image-tools__remove { margin-left:auto !important; color:#966161 !important; }
        .studio-note-actions { display:flex; align-items:center; justify-content:space-between; gap:18px; margin-top:20px; }
        .studio-note-actions__main { display:flex; gap:10px; }
        .studio-note-actions button { width:auto; min-width:148px; margin:0; }
        .studio-note-publish { border-color:#b8a4c0 !important; background:#b8a4c0 !important; color:#fffaf7 !important; }
        .studio-note-publish:hover { border-color:#a791b0 !important; background:#a791b0 !important; }
        .studio-note-delete { min-width:0 !important; margin:0 0 0 auto !important; padding:8px 3px !important; border:0 !important; background:transparent !important; color:#966161 !important; box-shadow:none !important; font-size:10px !important; }
        .studio-note-delete:hover { background:transparent !important; color:#753f3f !important; text-decoration:underline; transform:none !important; box-shadow:none !important; }
        .studio-note-media-library { position:sticky; top:18px; padding:16px; border:1px solid var(--line); border-radius:4px; background:#f8f6f2; }
        .studio-note-media-library h2 { margin:0 0 4px; font:400 23px/1.15 var(--font-serif); }
        .studio-note-media-library > p { margin:0 0 14px; color:#625b55; font-size:14px; line-height:1.4; }
        .studio-note-media-filters { display:flex; align-items:center; gap:14px; overflow-x:auto; padding-bottom:2px; }
        .studio-note-media-filters button { width:auto !important; min-width:0 !important; margin:0 !important; padding:7px 0 8px !important; border:0 !important; border-bottom:2px solid transparent !important; border-radius:0 !important; background:transparent !important; color:#554d46 !important; box-shadow:none !important; font-size:13px !important; font-weight:650 !important; letter-spacing:.03em !important; text-transform:none !important; white-space:nowrap; }
        .studio-note-media-filters button:hover,
        .studio-note-media-filters button.is-active { border-bottom-color:rgba(224,104,76,.65) !important; background:transparent !important; color:var(--ink) !important; transform:none !important; box-shadow:none !important; }
        .studio-note-media-library input.studio-note-media-search { width:100%; box-sizing:border-box; margin:10px 0 14px; padding:11px 12px; border:1px solid var(--line); border-radius:3px; background:#fff; color:var(--ink); font-family:var(--font-sans); font-size:15px; line-height:1.3; }
        .studio-note-media-library input.studio-note-media-search:focus { outline:0; border-color:#aa96b1; box-shadow:0 0 0 2px rgba(184,164,192,.18); }
        .studio-note-media-grid { display:grid; grid-template-columns:minmax(0,1fr); gap:14px; max-height:610px; overflow-y:auto; padding:1px 6px 1px 1px; scrollbar-width:thin; scrollbar-color:#c8beb4 transparent; }
        .studio-note-media[hidden] { display:none !important; }
        .studio-note-media { position:relative; width:100% !important; min-width:0 !important; aspect-ratio:4/5; margin:0 !important; padding:5px !important; overflow:hidden; border:1px solid #c7c1ba !important; border-radius:2px !important; background:#fff !important; box-shadow:none !important; cursor:grab; }
        .studio-note-media:active { cursor:grabbing; }
        .studio-note-media.is-dragging { opacity:.52; }
        .studio-note-media:hover { border-color:#a791b0 !important; background:#fff !important; transform:none !important; box-shadow:none !important; }
        .studio-note-media img { display:block; width:100%; height:100%; object-fit:contain; background:#eeece7; }
        .studio-note-media.is-inserted { border-color:#8b7a92 !important; }
        .studio-note-media-empty { margin:14px 0 0 !important; color:#625b55; font-size:14px; }
        .studio-drafts { margin-top:36px; padding:0; }
        .studio-drafts-list { display:grid; grid-template-columns:repeat(auto-fill,minmax(360px,440px)); justify-content:start; gap:18px; }
        .studio-draft { display:grid; grid-template-columns:138px minmax(0,1fr); min-height:188px; overflow:hidden; border:1px solid #cfc3d3; border-radius:4px; background:#eee8f0; color:var(--ink); text-decoration:none; box-shadow:0 8px 20px rgba(126,104,133,.10); transition:border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
        .studio-draft:hover { border-color:#aa96b1; box-shadow:0 11px 24px rgba(126,104,133,.16); transform:translateY(-1px); }
        .studio-draft:focus-visible { outline:2px solid #aa96b1; outline-offset:3px; }
        .studio-draft--text-only { grid-template-columns:minmax(0,1fr); }
        .studio-draft__thumb { width:138px; height:100%; min-height:188px; overflow:hidden; background:#ddd4df; }
        .studio-draft__thumb img { display:block; width:100%; height:100%; object-fit:cover; }
        .studio-draft__body { display:flex; min-width:0; padding:20px; flex-direction:column; }
        .studio-draft__body h3 { display:-webkit-box; overflow:hidden; margin:0 0 9px; font:400 23px/1.18 var(--font-serif); -webkit-box-orient:vertical; -webkit-line-clamp:2; }
        .studio-draft__body p { display:-webkit-box; overflow:hidden; margin:0; color:#5d555f; font-size:14px; line-height:1.5; -webkit-box-orient:vertical; -webkit-line-clamp:3; }
        .studio-draft__state { display:block; margin-top:auto; padding-top:14px; color:#5d555f; font-size:11px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; }
        @media (max-width:860px) {
            .studio-notes-page { padding:18px 14px 60px; }
            .studio-source-toolbar { align-items:flex-start; flex-direction:column; gap:8px; }
            .studio-source-tabs { width:100%; }
            .studio-source-workline { grid-template-columns:minmax(0,1fr) 126px; gap:20px; }
            .studio-source-rail { grid-auto-columns:minmax(146px,44vw); }
            .studio-create-decision { width:126px !important; min-width:126px !important; height:126px !important; min-height:126px !important; margin-top:clamp(28px, calc(27.5vw - 63px), 48px) !important; padding:12px !important; font-size:11px !important; }
            .studio-note-editor-shell { grid-template-columns:1fr; }
            .studio-editor-workspace { padding:20px 16px 24px; }
            .studio-notes-page input.studio-note-editor-title { font-size:31px; }
            .studio-notes-page .studio-note-editor.ql-container { height:440px !important; min-height:440px; }
            .studio-notes-page .studio-note-editor .ql-editor { min-height:438px; padding:24px 18px; font-size:17px; line-height:1.68; }
            .studio-notes-page .studio-note-editor .ql-editor img { max-width:100%; max-height:320px; margin:18px auto; }
            .studio-image-tools { align-items:flex-start; flex-wrap:wrap; gap:8px; }
            .studio-image-tools__group { padding-left:8px; }
            .studio-note-actions { align-items:stretch; flex-direction:column; }
            .studio-note-actions__main { display:grid; grid-template-columns:1fr 1fr; }
            .studio-note-actions__main button { width:100%; min-width:0; }
            .studio-note-delete { align-self:flex-end; width:auto !important; }
            .studio-note-media-library { position:static; }
            .studio-note-media-grid { grid-template-columns:repeat(2,minmax(0,1fr)); max-height:none; overflow:visible; }
            .studio-drafts-list { grid-template-columns:1fr; }
            .studio-draft { grid-template-columns:112px minmax(0,1fr); min-height:164px; }
            .studio-draft--text-only { grid-template-columns:minmax(0,1fr); }
            .studio-draft__thumb { width:112px; min-height:164px; }
            .studio-draft__body { padding:16px; }
            .studio-draft__body h3 { font-size:21px; }
            .studio-draft__body p { font-size:14px; }
        }
    </style>
    <!-- Quill WYSIWYG Editor Assets -->
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= wsn_h($user['email']) ?></a></header>
        <div class="studio-notes-page">
            <div class="catalog-heading">
                <div>
                    <h1>Studio Notes</h1>
                </div>
            </div>

            <?php if ($notice): ?>
                <div class="notice" style="background:#e6f6ec; color:#116639; padding:12px; border-radius:6px; border:1px solid #c7ebdb; margin-bottom:20px;"><?= wsn_h($notice) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="notice error" style="background:#ffebe9; color:#cc2511; padding:12px; border-radius:6px; border:1px solid #ffd3d0; margin-bottom:20px;"><?= wsn_h($error) ?></div>
            <?php endif; ?>



            <?php if (!$openDraft): ?>
            <section class="studio-source-stage" id="new-studio-note">
                <form method="post" id="studio-note-create-form">
                    <input type="hidden" name="csrf" value="<?= wsn_h($_SESSION['studio_notes_csrf']) ?>">
                    <input type="hidden" name="action" value="create_draft">

                    <div class="studio-source-toolbar">
                        <div class="studio-source-tabs" role="tablist" aria-label="Studio Note source type">
                            <?php foreach (['artwork' => 'Artworks', 'series' => 'Series', 'mockup' => 'Mockups'] as $type => $label): ?>
                                <button class="studio-source-tab<?= $initialSourceType === $type ? ' is-active' : '' ?>" type="button" role="tab" aria-selected="<?= $initialSourceType === $type ? 'true' : 'false' ?>" data-source-tab="<?= wsn_h($type) ?>"><?= wsn_h($label) ?></button>
                            <?php endforeach; ?>
                            <button class="studio-source-tab" type="button" role="tab" aria-selected="false" data-source-tab="none" data-clear-source>No source</button>
                        </div>
                    </div>

                    <div class="studio-source-workline">
                        <div class="studio-source-panels">
                            <?php foreach ($sourcesByType as $type => $sources): ?>
                                <div class="studio-source-panel" data-source-panel="<?= wsn_h($type) ?>"<?= $initialSourceType !== $type ? ' hidden' : '' ?>>
                                    <?php if (!$sources): ?>
                                        <div class="empty-state">No <?= wsn_h($type) ?> sources yet.</div>
                                    <?php else: ?>
                                        <div class="studio-source-rail">
                                            <?php foreach ($sources as $source): ?>
                                                <?php $sourceKey = (string)$source['key']; ?>
                                                <label class="studio-source-card">
                                                    <input type="radio" name="source_key" value="<?= wsn_h($sourceKey) ?>"<?= $requestedSourceKey === $sourceKey ? ' checked' : '' ?>>
                                                    <span class="studio-source-card__image"><img src="<?= wsn_h(wsn_media_url((string)$source['file'], 520)) ?>" alt="<?= wsn_h((string)$source['label']) ?>" loading="lazy"></span>
                                                    <span class="studio-source-card__label" title="<?= wsn_h((string)$source['label']) ?>"><?= wsn_h((string)$source['label']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="studio-source-panel" data-source-panel="none" hidden></div>
                        </div>
                        <button class="social-square-button social-square-button--studio_process studio-create-decision" type="submit">
                            <span class="studio-create-decision__content">
                                <span class="studio-create-decision__plus">+</span>
                                <span class="studio-create-decision__label">NOTE</span>
                            </span>
                        </button>
                    </div>
                </form>
            </section>
            <?php endif; ?>

            <?php if ($openDraft): ?>
                <section class="studio-editor-workspace">
                    <div class="studio-note-editor-top"><a href="website_studio_notes.php">Close</a></div>
                    <div class="studio-note-editor-shell<?= $mediaLibrary ? '' : ' studio-note-editor-shell--text-only' ?>">
                        <div>
                            <form class="studio-note-form" method="post">
                                <input type="hidden" name="csrf" value="<?= wsn_h($_SESSION['studio_notes_csrf']) ?>">
                                <input type="hidden" name="draft_id" value="<?= (int)$openDraft['id'] ?>">
                                
                                <input class="studio-note-editor-title" type="text" name="title" id="studio-note-title" value="<?= wsn_h($openDraft['title']) ?>" placeholder="Studio Note title" aria-label="Studio Note title">
                                <div class="studio-image-tools" id="studio-image-tools" hidden>
                                    <span class="studio-image-tools__label">Image</span>
                                    <div class="studio-image-tools__group" role="group" aria-label="Image size">
                                        <button type="button" data-image-size="small">Small</button>
                                        <button type="button" data-image-size="medium">Medium</button>
                                        <button type="button" data-image-size="large">Large</button>
                                    </div>
                                    <div class="studio-image-tools__group" role="group" aria-label="Image alignment">
                                        <button type="button" data-image-align="left">Left</button>
                                        <button type="button" data-image-align="center">Center</button>
                                        <button type="button" data-image-align="right">Right</button>
                                    </div>
                                    <button class="studio-image-tools__remove" type="button" data-image-remove>Remove</button>
                                </div>
                                <div id="editor-container" class="studio-note-editor" aria-label="Studio Note content"></div>
                                <input type="hidden" name="objective" id="objective-input">
                                
                                <div class="studio-note-actions">
                                    <div class="studio-note-actions__main">
                                        <button class="button-link primary studio-note-publish" name="action" value="publish_draft" type="submit">Publish to Website</button>
                                        <button class="button-link secondary" name="action" value="save_draft" type="submit">Save Draft</button>
                                    </div>
                                    <button class="studio-note-delete" name="action" value="delete_draft" type="submit" onclick="return confirm('Delete this Studio Note?')">Delete</button>
                                </div>
                            </form>
                        </div>
                        <?php if ($mediaLibrary): ?>
                            <div class="studio-note-media-library" data-media-library data-default-media-filter="<?= wsn_h($mediaDefaultFilter) ?>">
                                <h2>Insert image</h2>
                                <p><?= $openSource ? 'Related material first. Filter or search for anything else.' : 'Search all visual material, then drag or click to insert.' ?></p>
                                <div class="studio-note-media-filters" role="tablist" aria-label="Image source">
                                    <?php if ($openSource): ?><button type="button" data-media-filter="related">Related</button><?php endif; ?>
                                    <button type="button" data-media-filter="all">All</button>
                                    <button type="button" data-media-filter="artwork">Artworks</button>
                                    <button type="button" data-media-filter="mockup">Mockups</button>
                                    <button type="button" data-media-filter="series">Series</button>
                                </div>
                                <input class="studio-note-media-search" type="search" data-media-search placeholder="Search artwork, series or camera" aria-label="Search visual material">
                                <div class="studio-note-media-grid">
                                    <?php foreach ($mediaLibrary as $media): ?>
                                        <?php
                                            $mediaKey = (string)($media['key'] ?? '');
                                            $mediaType = (string)($media['type'] ?? '');
                                            $mediaSearch = implode(' ', array_filter([
                                                (string)($media['label'] ?? ''),
                                                (string)($media['artworkTitle'] ?? ''),
                                                (string)($media['seriesTitle'] ?? ''),
                                                (string)($media['contextTitle'] ?? ''),
                                                (string)($media['searchTerms'] ?? ''),
                                                $mediaType,
                                            ]));
                                        ?>
                                        <button class="studio-note-media" type="button" draggable="true"
                                                data-insert-image="<?= wsn_h(wsn_media_url((string)$media['file'], 900)) ?>"
                                                data-insert-alt="<?= wsn_h((string)$media['label']) ?>"
                                                data-media-type="<?= wsn_h($mediaType) ?>"
                                                data-media-related="<?= isset($relatedMediaKeys[$mediaKey]) ? '1' : '0' ?>"
                                                data-media-search-text="<?= wsn_h($mediaSearch) ?>"
                                                aria-label="Insert <?= wsn_h((string)$media['label']) ?>"
                                                title="Insert <?= wsn_h((string)$media['label']) ?>">
                                            <img src="<?= wsn_h(wsn_media_url((string)$media['file'], 520)) ?>" alt="" loading="lazy" draggable="true">
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <p class="studio-note-media-empty" data-media-empty hidden>No images found.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!$openDraft): ?>
            <section class="studio-drafts">
                <?php if (!$websiteDrafts): ?>
                    <div class="empty-state">No website drafts yet. Use the panel above to write your first essay.</div>
                <?php else: ?>
                    <div class="studio-drafts-list">
                        <?php foreach ($websiteDrafts as $draft): 
                            $payload = (array)$draft['_payload']; 
                            $mockupIds = array_values(array_filter(array_map('intval', (array)($payload['mockup_ids'] ?? []))));
                            
                            $thumbUrl = '';
                            $payloadSource = is_array($payload['source'] ?? null) ? $payload['source'] : [];
                            $payloadSourceKey = trim((string)($payloadSource['key'] ?? ''));
                            if ($payloadSourceKey !== '' && isset($studioSourceLookup[$payloadSourceKey])) {
                                $thumbUrl = wsn_media_url((string)$studioSourceLookup[$payloadSourceKey]['file'], 360);
                            }
                            if ($thumbUrl === '' && $mockupIds) {
                                $stmt = $pdo->prepare("SELECT mockup_file FROM mockups WHERE id = ? AND user_id = ? LIMIT 1");
                                $stmt->execute([$mockupIds[0], $userId]);
                                $mFile = $stmt->fetchColumn();
                                if ($mFile) {
                                    $thumbUrl = 'media.php?file=' . rawurlencode(basename($mFile));
                                }
                            }
                            if ($thumbUrl === '') {
                                $thumbUrl = first_html_image_src((string)$draft['objective']);
                            }
                            
                            $snippet = trim(strip_tags((string)$draft['objective']));
                            if (mb_strlen($snippet) > 180) {
                                $snippet = mb_substr($snippet, 0, 177) . '...';
                            }
                        ?>
                            <a class="studio-draft<?= $thumbUrl === '' ? ' studio-draft--text-only' : '' ?>" href="website_studio_notes.php?draft=<?= (int)$draft['id'] ?>" aria-label="Edit <?= wsn_h($draft['title']) ?>">
                                <?php if ($thumbUrl !== ''): ?>
                                    <div class="studio-draft__thumb">
                                        <img src="<?= wsn_h($thumbUrl) ?>" alt="">
                                    </div>
                                <?php endif; ?>
                                
                                <div class="studio-draft__body">
                                    <h3><?= wsn_h($draft['title']) ?></h3>
                                    <?php if ($snippet !== ''): ?><p><?= wsn_h($snippet) ?></p><?php endif; ?>
                                    <span class="studio-draft__state"><?= wsn_h($draft['status']) ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>
            <script>
                (function () {
                    var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-source-tab]'));
                    var panels = Array.prototype.slice.call(document.querySelectorAll('[data-source-panel]'));

                    function activate(type) {
                        tabs.forEach(function (tab) {
                            var active = tab.getAttribute('data-source-tab') === type;
                            tab.classList.toggle('is-active', active);
                            tab.setAttribute('aria-selected', active ? 'true' : 'false');
                        });
                        panels.forEach(function (panel) {
                            panel.hidden = panel.getAttribute('data-source-panel') !== type;
                        });
                    }

                    tabs.forEach(function (tab) {
                        tab.addEventListener('click', function () {
                            activate(tab.getAttribute('data-source-tab'));
                            if (tab.hasAttribute('data-clear-source')) {
                                document.querySelectorAll('input[name="source_key"]').forEach(function (radio) { radio.checked = false; });
                            }
                        });
                    });

                })();
            </script>
            <?php if ($openDraft): ?>
            <script>
                document.addEventListener("DOMContentLoaded", function() {
                    var quill = new Quill('#editor-container', {
                        theme: 'snow',
                        modules: {
                            toolbar: [
                                [{ 'header': [1, 2, 3, false] }],
                                ['bold', 'italic', 'underline', 'strike'],
                                ['blockquote', 'code-block'],
                                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                                ['link', 'image', 'video'],
                                ['clean']
                            ]
                        }
                    });
                    
                    // Cargar el contenido inicial en HTML
                    quill.root.innerHTML = <?= json_encode($openDraft['objective']) ?>;

                    var imageTools = document.getElementById('studio-image-tools');
                    var toolbarModule = quill.getModule('toolbar');
                    if (imageTools && toolbarModule && toolbarModule.container) {
                        toolbarModule.container.insertAdjacentElement('afterend', imageTools);
                    }
                    var selectedImage = null;
                    var studioMediaType = 'application/x-studio-note-media';

                    function prepareImage(image) {
                        if (!image || image.tagName !== 'IMG') return;
                        if (!image.hasAttribute('data-editor-size')) {
                            image.style.removeProperty('width');
                            image.removeAttribute('width');
                            image.setAttribute('data-editor-size', 'medium');
                        }
                        if (!image.hasAttribute('data-editor-align')) {
                            image.setAttribute('data-editor-align', 'left');
                        }
                    }

                    function refreshImageTools() {
                        if (!imageTools) return;
                        imageTools.hidden = !selectedImage;
                        imageTools.querySelectorAll('[data-image-size]').forEach(function(button) {
                            button.classList.toggle('is-active', !!selectedImage && button.getAttribute('data-image-size') === selectedImage.getAttribute('data-editor-size'));
                        });
                        imageTools.querySelectorAll('[data-image-align]').forEach(function(button) {
                            button.classList.toggle('is-active', !!selectedImage && button.getAttribute('data-image-align') === selectedImage.getAttribute('data-editor-align'));
                        });
                    }

                    function selectImage(image) {
                        if (selectedImage) selectedImage.classList.remove('is-selected');
                        selectedImage = image || null;
                        if (selectedImage) {
                            prepareImage(selectedImage);
                            selectedImage.classList.add('is-selected');
                        }
                        refreshImageTools();
                    }

                    function insertStudioImage(url, alt, index) {
                        var safeIndex = Math.max(0, Math.min(Number(index) || 0, Math.max(0, quill.getLength() - 1)));
                        quill.insertEmbed(safeIndex, 'image', url, 'user');
                        quill.insertText(safeIndex + 1, '\n', 'user');
                        var insertedLeaf = quill.getLeaf(safeIndex);
                        var insertedImage = insertedLeaf && insertedLeaf[0] && insertedLeaf[0].domNode && insertedLeaf[0].domNode.tagName === 'IMG'
                            ? insertedLeaf[0].domNode
                            : null;
                        if (insertedImage) {
                            insertedImage.setAttribute('alt', alt || '');
                            prepareImage(insertedImage);
                            selectImage(insertedImage);
                        }
                        quill.setSelection(safeIndex + 2, 0, 'silent');
                        quill.focus();
                    }

                    function editorIndexAtPoint(event) {
                        try {
                            var nativeRange = document.caretRangeFromPoint
                                ? document.caretRangeFromPoint(event.clientX, event.clientY)
                                : null;
                            if (nativeRange) {
                                var node = nativeRange.startContainer;
                                var blot = Quill.find(node, true) || (node.parentNode ? Quill.find(node.parentNode, true) : null);
                                if (blot) {
                                    var index = quill.getIndex(blot);
                                    if (node.nodeType === Node.TEXT_NODE) index += nativeRange.startOffset;
                                    return index;
                                }
                            }
                        } catch (error) {}
                        var range = quill.getSelection();
                        return range ? range.index : Math.max(0, quill.getLength() - 1);
                    }

                    quill.root.querySelectorAll('img').forEach(prepareImage);

                    if (imageTools) {
                        imageTools.addEventListener('click', function(event) {
                            var button = event.target.closest('button');
                            if (!button || !selectedImage) return;
                            if (button.hasAttribute('data-image-size')) {
                                selectedImage.setAttribute('data-editor-size', button.getAttribute('data-image-size'));
                            } else if (button.hasAttribute('data-image-align')) {
                                selectedImage.setAttribute('data-editor-align', button.getAttribute('data-image-align'));
                            } else if (button.hasAttribute('data-image-remove')) {
                                var imageBlot = Quill.find(selectedImage);
                                if (imageBlot) quill.deleteText(quill.getIndex(imageBlot), 1, 'user');
                                selectImage(null);
                                return;
                            }
                            quill.update('user');
                            refreshImageTools();
                        });
                    }

                    document.querySelectorAll('[data-media-library]').forEach(function(library) {
                        var filterButtons = Array.from(library.querySelectorAll('[data-media-filter]'));
                        var searchInput = library.querySelector('[data-media-search]');
                        var mediaCards = Array.from(library.querySelectorAll('[data-insert-image]'));
                        var emptyState = library.querySelector('[data-media-empty]');
                        var activeFilter = library.getAttribute('data-default-media-filter') || 'all';

                        function normalizeMediaSearch(value) {
                            var normalized = String(value || '').toLocaleLowerCase();
                            return typeof normalized.normalize === 'function'
                                ? normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                                : normalized;
                        }

                        function applyMediaFilters() {
                            var query = searchInput ? normalizeMediaSearch(searchInput.value.trim()) : '';
                            var visibleCount = 0;
                            mediaCards.forEach(function(card) {
                                var typeMatches = activeFilter === 'all'
                                    || (activeFilter === 'related' && card.getAttribute('data-media-related') === '1')
                                    || card.getAttribute('data-media-type') === activeFilter;
                                var searchMatches = !query || normalizeMediaSearch(card.getAttribute('data-media-search-text')).indexOf(query) !== -1;
                                card.hidden = !(typeMatches && searchMatches);
                                if (!card.hidden) visibleCount += 1;
                            });
                            filterButtons.forEach(function(button) {
                                var active = button.getAttribute('data-media-filter') === activeFilter;
                                button.classList.toggle('is-active', active);
                                button.setAttribute('aria-selected', active ? 'true' : 'false');
                            });
                            if (emptyState) emptyState.hidden = visibleCount > 0;
                        }

                        filterButtons.forEach(function(button) {
                            button.addEventListener('click', function() {
                                activeFilter = button.getAttribute('data-media-filter') || 'all';
                                applyMediaFilters();
                            });
                        });
                        if (searchInput) searchInput.addEventListener('input', applyMediaFilters);
                        applyMediaFilters();
                    });

                    document.querySelectorAll('[data-insert-image]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            var range = quill.getSelection(true);
                            var index = range ? range.index : Math.max(0, quill.getLength() - 1);
                            insertStudioImage(button.getAttribute('data-insert-image'), button.getAttribute('data-insert-alt'), index);
                            button.classList.add('is-inserted');
                            window.setTimeout(function() { button.classList.remove('is-inserted'); }, 700);
                        });
                        button.addEventListener('dragstart', function(event) {
                            if (!event.dataTransfer) return;
                            var payload = JSON.stringify({
                                url: button.getAttribute('data-insert-image'),
                                alt: button.getAttribute('data-insert-alt') || ''
                            });
                            event.dataTransfer.effectAllowed = 'copy';
                            event.dataTransfer.setData(studioMediaType, payload);
                            event.dataTransfer.setData('text/plain', button.getAttribute('data-insert-image'));
                            event.dataTransfer.setData('text/uri-list', button.getAttribute('data-insert-image'));
                            button.classList.add('is-dragging');
                        });
                        button.addEventListener('dragend', function() {
                            button.classList.remove('is-dragging');
                            quill.container.classList.remove('is-media-drop-target');
                        });
                    });

                    quill.container.addEventListener('dragover', function(event) {
                        if (!event.dataTransfer || Array.from(event.dataTransfer.types || []).indexOf(studioMediaType) === -1) return;
                        event.preventDefault();
                        event.stopPropagation();
                        event.dataTransfer.dropEffect = 'copy';
                        quill.container.classList.add('is-media-drop-target');
                    }, true);
                    quill.container.addEventListener('dragleave', function(event) {
                        if (!quill.container.contains(event.relatedTarget)) quill.container.classList.remove('is-media-drop-target');
                    }, true);
                    quill.container.addEventListener('drop', function(event) {
                        if (!event.dataTransfer) return;
                        var rawPayload = event.dataTransfer.getData(studioMediaType);
                        if (!rawPayload) return;
                        event.preventDefault();
                        event.stopPropagation();
                        quill.container.classList.remove('is-media-drop-target');
                        try {
                            var payload = JSON.parse(rawPayload);
                            insertStudioImage(String(payload.url || ''), String(payload.alt || ''), editorIndexAtPoint(event));
                        } catch (error) {}
                    }, true);

                    quill.root.addEventListener('click', function(e) {
                        if (e.target && e.target.tagName === 'IMG') {
                            selectImage(e.target);
                        } else selectImage(null);
                    });
                    
                    // Sincronizar el contenido antes de enviar el formulario
                    var form = document.querySelector('form.studio-note-form');
                    if (form) {
                        form.addEventListener('submit', function() {
                            var input = document.getElementById('objective-input');
                            input.value = quill.root.innerHTML;
                        });
                    }
                });
            </script>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
