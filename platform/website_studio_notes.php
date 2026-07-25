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
$editorial = new BilingualEditorialService($pdo);
$studioWorkspace = new StudioNoteWorkspaceService($pdo);
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
                $title = $source ? trim((string)$source['label']) . ' — Nota de estudio' : 'Nueva nota de estudio';
            }
            $now = date('c');
            $payload = [
                'channels' => ['website_blog'],
                'destinations' => ['website_blog'],
                'editorial_sync_key' => 'studio-note-' . bin2hex(random_bytes(16)),
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
        
        if ($action === 'generate_bilingual') {
            $id = max(0, (int)($_POST['draft_id'] ?? 0));
            if (!$editorial->isEnabled($userId)) {
                throw new RuntimeException('El espacio editorial bilingüe no está habilitado para esta cuenta.');
            }
            $currentSpanish = wsn_post_content('es');
            if (trim((string)$currentSpanish['body_html']) !== '') {
                $currentSpanish['body_html'] = $websiteBoard->normalizeNoteBody(
                    $userId,
                    $id,
                    (string)$currentSpanish['body_html']
                );
            }
            $currentEnglish = wsn_post_content('en');
            if (wsn_has_content($currentSpanish)) {
                $editorial->save(
                    $userId,
                    'studio_note',
                    $id,
                    'es',
                    $currentSpanish,
                    trim((string)($_POST['private_memo_es'] ?? ''))
                );
            }
            if (wsn_has_content($currentEnglish)) {
                $editorial->save($userId, 'studio_note', $id, 'en', $currentEnglish);
            }
            $jobs = new BilingualEditorialJobService($pdo);
            $job = $jobs->createOrReuse($userId, 'studio_note', $id, 'prepare', [
                'current_spanish' => $currentSpanish,
                'private_memo' => trim((string)($_POST['private_memo_es'] ?? '')),
                'publish_spanish' => false,
            ]);
            $studioWorkspace->capture(
                $userId,
                $id,
                'version',
                'es',
                $currentSpanish,
                'Texto conservado antes de generar',
                (int)$job['id']
            );
            if ((string)$job['status'] === 'queued' && trim((string)$job['task_name']) === '') {
                if (CloudTasksService::isAvailable()) {
                    $jobs->attachTask(
                        (int)$job['id'],
                        $userId,
                        CloudTasksService::enqueueEditorialGeneration((int)$job['id'])
                    );
                } else {
                    (new BilingualEditorialGenerationWorker($pdo))->process((int)$job['id']);
                }
            }
            $_SESSION['wsn_notice'] = 'La propuesta se está preparando en la pizarra. Tu texto activo no será reemplazado.';
            header('Location: website_studio_notes.php?draft=' . $id);
            exit;
        }

        if (in_array($action, ['add_idea', 'upload_workspace_media', 'apply_workspace_item', 'remove_workspace_item'], true)) {
            $id = max(0, (int)($_POST['draft_id'] ?? 0));
            $spanish = wsn_post_content('es');
            $english = wsn_post_content('en');
            if (wsn_has_content($spanish)) {
                if (trim((string)$spanish['body_html']) !== '') {
                    $spanish['body_html'] = $websiteBoard->normalizeNoteBody(
                        $userId,
                        $id,
                        (string)$spanish['body_html']
                    );
                }
                $editorial->save(
                    $userId,
                    'studio_note',
                    $id,
                    'es',
                    $spanish,
                    trim((string)($_POST['private_memo_es'] ?? ''))
                );
            }
            if (wsn_has_content($english)) {
                $editorial->save($userId, 'studio_note', $id, 'en', $english);
            }

            if ($action === 'add_idea') {
                $studioWorkspace->addIdea(
                    $userId,
                    $id,
                    trim((string)($_POST['idea_title'] ?? '')),
                    trim((string)($_POST['idea_text'] ?? ''))
                );
                $_SESSION['wsn_notice'] = 'Idea añadida a la pizarra.';
            } elseif ($action === 'upload_workspace_media') {
                $uploadResult = $websiteBoard->addNoteUploads(
                    $userId,
                    $id,
                    (array)($_FILES['workspace_images'] ?? [])
                );
                $_SESSION['wsn_notice'] = (int)$uploadResult['count'] === 1
                    ? 'Imagen añadida a la mesa de material.'
                    : (int)$uploadResult['count'] . ' imágenes añadidas a la mesa de material.';
            } else {
                $itemId = max(0, (int)($_POST['workspace_item_id'] ?? 0));
                if ($action === 'remove_workspace_item') {
                    $studioWorkspace->remove($userId, $id, $itemId);
                    $_SESSION['wsn_notice'] = 'Elemento retirado de la pizarra.';
                } else {
                    $item = $studioWorkspace->item($userId, $id, $itemId);
                    $locale = (string)$item['locale'];
                    $current = $locale === 'es' ? $spanish : $english;
                    $studioWorkspace->capture(
                        $userId,
                        $id,
                        'version',
                        $locale,
                        $current,
                        $locale === 'es' ? 'Texto anterior a aplicar una tarjeta' : 'English text before applying a card'
                    );
                    $editorial->save(
                        $userId,
                        'studio_note',
                        $id,
                        $locale,
                        (array)$item['content'],
                        (string)$editorial->get($userId, 'studio_note', $id, $locale)['private_memo']
                    );
                    $_SESSION['wsn_notice'] = $locale === 'es'
                        ? 'La tarjeta se aplicó al editor español. El texto anterior quedó en Versiones.'
                        : 'The card was applied to the English editor. The previous text remains in Versions.';
                }
            }
            header('Location: website_studio_notes.php?draft=' . $id);
            exit;
        }

        if (in_array($action, ['save_draft', 'publish_draft'], true)) {
            $id = max(0, (int)($_POST['draft_id'] ?? 0));
            $spanish = wsn_post_content('es');
            $english = wsn_post_content('en');
            if (trim((string)$spanish['title']) === '') throw new RuntimeException('El título en español es obligatorio.');
            if (trim(strip_tags((string)$spanish['body_html'])) === '') throw new RuntimeException('Escribí el contenido español antes de guardar.');

            $spanish['body_html'] = $websiteBoard->normalizeNoteBody($userId, $id, (string)$spanish['body_html']);
            $editorial->save($userId, 'studio_note', $id, 'es', $spanish);
            if (wsn_has_content($english)) {
                $editorial->save($userId, 'studio_note', $id, 'en', $english);
            }

            if ($action === 'publish_draft') {
                if (!$editorial->isEnabled($userId)) {
                    throw new RuntimeException('El espacio editorial bilingüe no está habilitado para esta cuenta.');
                }
                $jobs = new BilingualEditorialJobService($pdo);
                $job = $jobs->createOrReuse($userId, 'studio_note', $id, 'publish', [
                    'current_spanish' => $spanish,
                    'current_english' => $english,
                    'source_hash' => hash(
                        'sha256',
                        json_encode(
                            $spanish,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                        )
                    ),
                ], true);
                if ((string)$job['status'] === 'queued' && trim((string)$job['task_name']) === '') {
                    if (CloudTasksService::isAvailable()) {
                        $jobs->attachTask(
                            (int)$job['id'],
                            $userId,
                            CloudTasksService::enqueueEditorialGeneration((int)$job['id'])
                        );
                    } else {
                        (new BilingualEditorialGenerationWorker($pdo))->process((int)$job['id']);
                    }
                }
                $_SESSION['wsn_notice'] = 'Analizando y publicando la nota completa en español e inglés.';
            } else {
                $_SESSION['wsn_notice'] = 'Borrador bilingüe guardado.';
            }
            header('Location: website_studio_notes.php?draft=' . $id);
            exit;
        }

        if ($action === 'unpublish_draft') {
            $id = max(0, (int)($_POST['draft_id'] ?? 0));
            $editorial->setPublished($userId, 'studio_note', $id, 'es', false);
            $editorial->setPublished($userId, 'studio_note', $id, 'en', false);
            $websiteBoard->noteAction($userId, $id, 'unpublish');
            $_SESSION['wsn_notice'] = 'La nota fue retirada del website.';
            header('Location: website_studio_notes.php?draft=' . $id);
            exit;
        }
        
        if ($action === 'delete_draft') {
            $id = max(0, (int)($_POST['draft_id'] ?? 0));
            $pdo->prepare('DELETE FROM studio_note_workspace_items WHERE user_id=? AND note_id=?')->execute([$userId, $id]);
            $pdo->prepare("DELETE FROM bilingual_editorial_jobs WHERE user_id=? AND entity_type='studio_note' AND entity_id=?")->execute([$userId, $id]);
            $pdo->prepare("DELETE FROM bilingual_editorial_content WHERE user_id=? AND entity_type='studio_note' AND entity_id=?")->execute([$userId, $id]);
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
function wsn_post_content(string $locale): array
{
    $imageMetadata = json_decode((string)($_POST['image_metadata_' . $locale] ?? '[]'), true);
    return [
        'title' => trim((string)($_POST['title_' . $locale] ?? '')),
        'excerpt' => trim((string)($_POST['excerpt_' . $locale] ?? '')),
        'body_html' => trim((string)($_POST['body_' . $locale] ?? '')),
        'slug' => wsn_slug((string)($_POST['slug_' . $locale] ?? $_POST['title_' . $locale] ?? '')),
        'seo_title' => trim((string)($_POST['seo_title_' . $locale] ?? '')),
        'seo_description' => trim((string)($_POST['seo_description_' . $locale] ?? '')),
        'alt_text' => trim((string)($_POST['alt_text_' . $locale] ?? '')),
        'caption' => trim((string)($_POST['caption_' . $locale] ?? '')),
        'tags' => trim((string)($_POST['tags_' . $locale] ?? '')),
        'search_terms' => trim((string)($_POST['search_terms_' . $locale] ?? '')),
        'image_metadata' => is_array($imageMetadata) ? array_values($imageMetadata) : [],
        'meta_source_hash' => trim((string)($_POST['meta_source_hash_' . $locale] ?? '')),
    ];
}
function wsn_has_content(array $content): bool
{
    foreach ($content as $value) {
        if (is_array($value) && $value !== []) return true;
        if (!is_array($value) && trim(strip_tags((string)$value)) !== '') return true;
    }
    return false;
}
function wsn_has_required_public_content(array $content): bool
{
    return trim((string)($content['title'] ?? '')) !== ''
        && trim(strip_tags((string)($content['body_html'] ?? ''))) !== '';
}
function wsn_slug(string $value): string
{
    $value = mb_strtolower(trim($value));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') $value = $ascii;
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', $value), '-');
}
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
$noteEditorialIndex = [];
$noteIds = array_values(array_map(static fn(array $draft): int => (int)$draft['id'], $websiteDrafts));
if ($noteIds) {
    $marks = implode(',', array_fill(0, count($noteIds), '?'));
    $localizedStmt = $pdo->prepare("SELECT entity_id,locale,content_json,status,is_published
        FROM bilingual_editorial_content
        WHERE user_id=? AND entity_type='studio_note' AND entity_id IN ($marks)");
    $localizedStmt->execute(array_merge([$userId], $noteIds));
    foreach ($localizedStmt as $localizedRow) {
        $content = json_decode((string)$localizedRow['content_json'], true);
        if (!is_array($content)) continue;
        $noteEditorialIndex[(int)$localizedRow['entity_id']][(string)$localizedRow['locale']] = [
            'content' => $content,
            'status' => (string)$localizedRow['status'],
            'is_published' => (bool)$localizedRow['is_published'],
        ];
    }
}

$openDraft = null;
foreach ($websiteDrafts as $draft) {
    if ((int)$draft['id'] === $draftId) {
        $openDraft = $draft;
        break;
    }
}
$noteShape = [
    'title' => '', 'excerpt' => '', 'body_html' => '', 'slug' => '',
    'seo_title' => '', 'seo_description' => '', 'alt_text' => '', 'caption' => '',
    'tags' => '', 'search_terms' => '', 'image_metadata' => [], 'meta_source_hash' => '',
];
$spanishState = ['content' => $noteShape, 'status' => 'unprepared', 'is_published' => false, 'has_unpublished_changes' => false];
$englishState = ['content' => $noteShape, 'status' => 'unprepared', 'is_published' => false, 'has_unpublished_changes' => false];
$activeEditorialJob = null;
$englishAdaptationActive = false;
if ($openDraft) {
    $legacyEnglish = trim(strip_tags((string)$openDraft['objective'])) !== ''
        ? [
            'title' => (string)$openDraft['title'],
            'excerpt' => '',
            'body_html' => (string)$openDraft['objective'],
            'slug' => wsn_slug((string)$openDraft['title']),
            'seo_title' => '',
            'seo_description' => '',
            'alt_text' => '',
            'caption' => '',
            'tags' => '',
            'search_terms' => '',
            'image_metadata' => [],
            'meta_source_hash' => '',
        ]
        : [];
    $spanishState = $editorial->get($userId, 'studio_note', (int)$openDraft['id'], 'es');
    $englishState = $editorial->get($userId, 'studio_note', (int)$openDraft['id'], 'en', $legacyEnglish);
    $spanishState['content'] = array_replace($noteShape, (array)$spanishState['content']);
    $englishState['content'] = array_replace($noteShape, (array)$englishState['content']);
    try {
        $studioWorkspace->syncHistoricalJobs($userId, (int)$openDraft['id']);
        $activeEditorialJob = (new BilingualEditorialJobService($pdo))->activeForEntity(
            $userId,
            'studio_note',
            (int)$openDraft['id']
        );
        $englishAdaptationActive = is_array($activeEditorialJob)
            && in_array((string)($activeEditorialJob['action'] ?? ''), ['adapt', 'publish'], true);
        $publicationActive = is_array($activeEditorialJob)
            && (string)($activeEditorialJob['action'] ?? '') === 'publish';
    } catch (Throwable) {
        $activeEditorialJob = null;
        $englishAdaptationActive = false;
        $publicationActive = false;
    }
}

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
<html lang="es">
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
        .studio-editor-workspace { width:100%; box-sizing:border-box; margin:0 auto; padding:28px 24px 32px; border:1px solid var(--line); border-radius:4px; background:var(--surface); }
        .studio-note-editor-top { display:flex; justify-content:flex-end; margin-bottom:8px; }
        .studio-note-editor-top a { color:#625b55; font-size:12px; font-weight:650; letter-spacing:.05em; text-decoration:none; text-transform:uppercase; }
        .studio-note-editor-shell { display:block; }
        .studio-note-writing-desk { min-width:0; width:100%; }
        .studio-bilingual-editors { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); align-items:start; gap:24px; }
        .studio-language-editor { position:relative; min-width:0; }
        .studio-notes-page input.studio-note-editor-title { width:100%; box-sizing:border-box; margin:0 0 18px; padding:5px 2px 14px; border:0; border-bottom:1px solid var(--line); border-radius:0; background:transparent; color:var(--ink); font-family:var(--font-serif); font-size:38px; font-weight:400; line-height:1.2; }
        .studio-bilingual-editors input.studio-note-editor-title { font-size:31px; }
        .studio-notes-page input.studio-note-editor-title:focus { outline:0; border-bottom-color:var(--accent); box-shadow:none; }
        .studio-notes-page .studio-note-editor.ql-container { height:520px !important; min-height:520px; border:1px solid var(--line); border-radius:0 0 4px 4px; background:#fff; color:var(--ink); font-family:var(--font-sans); font-size:19px; line-height:1.72; }
        .studio-notes-page .ql-toolbar.ql-snow { display:flex; flex-wrap:wrap; gap:2px; padding:10px 14px; border:1px solid var(--line); border-radius:4px 4px 0 0; background:#fbfaf7; }
        .studio-notes-page .ql-toolbar.ql-snow .ql-formats { margin-right:5px; }
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
        .studio-publication-state { align-self:center; color:#625b55; font-size:11px; letter-spacing:.04em; }
        .studio-note-actions__secondary { display:flex; gap:8px; margin-left:auto; }
        .studio-note-actions button { width:auto; min-width:148px; margin:0; }
        .studio-note-publish { border-color:#b8a4c0 !important; background:#b8a4c0 !important; color:#fffaf7 !important; }
        .studio-note-publish:hover { border-color:#a791b0 !important; background:#a791b0 !important; }
        .studio-note-secondary-action { min-width:92px !important; padding:9px 13px !important; border:1px solid #cfc7c0 !important; border-radius:3px !important; background:#fff !important; color:#625b55 !important; box-shadow:none !important; font-size:10px !important; }
        .studio-note-secondary-action:hover { border-color:#9f958d !important; background:#f7f4ef !important; transform:none !important; box-shadow:none !important; }
        .studio-note-secondary-action--danger { border-color:#d8bebe !important; color:#8b5151 !important; }
        .studio-language-heading { display:flex; align-items:center; justify-content:space-between; min-height:27px; gap:18px; margin:0 0 14px; }
        .studio-language-heading span { color:#625b55; font-size:12px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .studio-language-state { padding:5px 9px; border:1px solid #d2c6d5; border-radius:999px; background:#f3eef4; color:#5d5161 !important; letter-spacing:.04em !important; text-transform:none !important; }
        .studio-editorial-panel { margin-top:24px; border-top:1px solid var(--line); }
        .studio-editorial-panel > summary { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:17px 2px; cursor:pointer; color:var(--ink); font:400 22px/1.2 var(--font-serif); list-style:none; }
        .studio-editorial-panel > summary::-webkit-details-marker { display:none; }
        .studio-editorial-panel > summary small { color:#625b55; font:600 11px/1.3 var(--font-sans); letter-spacing:.04em; }
        .studio-editorial-panel__body { padding:4px 0 12px; }
        .studio-english-panel { position:relative; min-height:132px; }
        .studio-english-panel__lock { display:none; position:absolute; z-index:3; inset:4px 0 12px; align-items:center; justify-content:center; padding:28px; border:1px solid #c4d0c0; background:rgba(241,245,239,.94); color:#465342; text-align:center; }
        .studio-english-panel__lock strong { display:block; margin-bottom:7px; font:400 21px/1.2 var(--font-serif); }
        .studio-english-panel__lock span { display:block; max-width:520px; font-size:13px; line-height:1.5; }
        .studio-english-panel.is-adapting .studio-english-panel__lock { display:flex; }
        .studio-english-panel.is-adapting .studio-english-panel__controls { opacity:.28; }
        .studio-seo-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
        .studio-seo-column { padding:18px; border:1px solid var(--line); background:#fbfaf7; }
        .studio-seo-column.is-adapting { opacity:.42; }
        .studio-seo-column h3 { margin:0 0 16px; font:400 21px/1.2 var(--font-serif); }
        .studio-seo-field { display:block; margin:0 0 15px; color:#625b55; font-size:12px; font-weight:650; letter-spacing:.04em; }
        .studio-seo-field input,
        .studio-seo-field textarea { width:100%; box-sizing:border-box; margin-top:7px; padding:10px 0; border:0; border-bottom:1px solid var(--line); border-radius:0; background:transparent; color:var(--ink); font:400 16px/1.5 var(--font-sans); resize:vertical; }
        .studio-seo-field textarea { min-height:92px; }
        .studio-seo-field input:focus,
        .studio-seo-field textarea:focus { outline:0; border-bottom-color:#9b86a4; box-shadow:none; }
        @media (max-width:1100px) {
            .studio-bilingual-editors { gap:16px; }
            .studio-bilingual-editors input.studio-note-editor-title { font-size:27px; }
            .studio-notes-page .studio-note-editor .ql-editor { padding:26px 24px; font-size:17px; }
        }
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
            .studio-bilingual-editors { grid-template-columns:1fr; }
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
            .studio-note-actions__secondary { align-self:flex-end; margin-left:0; }
            .studio-seo-grid { grid-template-columns:1fr; }
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
                    <form class="studio-note-form" method="post" enctype="multipart/form-data"
                          data-note-id="<?= (int)$openDraft['id'] ?>"
                          data-editorial-csrf="<?= wsn_h(Auth::csrfToken('bilingual_editorial')) ?>"
                          data-active-job="<?= (int)($activeEditorialJob['id'] ?? 0) ?>"
                          data-active-job-action="<?= wsn_h((string)($activeEditorialJob['action'] ?? '')) ?>">
                        <input type="hidden" name="csrf" value="<?= wsn_h($_SESSION['studio_notes_csrf']) ?>">
                        <input type="hidden" name="draft_id" value="<?= (int)$openDraft['id'] ?>">
                        <div class="studio-note-editor-shell">
                            <div class="studio-note-writing-desk">
                                <div class="studio-bilingual-editors">
                                    <section class="studio-language-editor" aria-labelledby="studio-language-es">
                                        <div class="studio-language-heading">
                                            <span id="studio-language-es">Español · original</span>
                                            <span class="studio-language-state"><?= !empty($spanishState['is_published']) ? (!empty($spanishState['has_unpublished_changes']) ? 'Cambios sin publicar' : 'Publicado') : 'Borrador' ?></span>
                                        </div>
                                        <input class="studio-note-editor-title" type="text" name="title_es" id="studio-note-title-es"
                                               value="<?= wsn_h((string)$spanishState['content']['title']) ?>"
                                               placeholder="Título de la nota en español" aria-label="Título de la nota en español">
                                        <div class="studio-image-tools" id="studio-image-tools" hidden>
                                            <span class="studio-image-tools__label">Imagen</span>
                                            <div class="studio-image-tools__group" role="group" aria-label="Tamaño de imagen">
                                                <button type="button" data-image-size="small">Pequeña</button>
                                                <button type="button" data-image-size="medium">Mediana</button>
                                                <button type="button" data-image-size="large">Grande</button>
                                            </div>
                                            <div class="studio-image-tools__group" role="group" aria-label="Alineación de imagen">
                                                <button type="button" data-image-align="left">Izquierda</button>
                                                <button type="button" data-image-align="center">Centro</button>
                                                <button type="button" data-image-align="right">Derecha</button>
                                            </div>
                                            <button class="studio-image-tools__remove" type="button" data-image-remove>Quitar</button>
                                        </div>
                                        <div id="editor-container-es" class="studio-note-editor" aria-label="Contenido de la nota en español"></div>
                                        <input type="hidden" name="body_es" id="body-input-es">
                                    </section>

                                    <section class="studio-language-editor studio-english-panel<?= $englishAdaptationActive ? ' is-adapting' : '' ?>"
                                             data-english-panel aria-labelledby="studio-language-en"
                                             aria-busy="<?= $englishAdaptationActive ? 'true' : 'false' ?>">
                                        <div class="studio-language-heading">
                                            <span id="studio-language-en">English · adaptation</span>
                                        </div>
                                        <div class="studio-english-panel__lock" data-english-lock<?= $englishAdaptationActive ? '' : ' hidden' ?>>
                                            <div>
                                                <strong>Preparando publicación ES + EN</strong>
                                                <span>Analizando metadata y adaptando el original español.</span>
                                            </div>
                                        </div>
                                        <div class="studio-english-panel__controls" data-english-dependent<?= $englishAdaptationActive ? ' inert' : '' ?>>
                                            <input class="studio-note-editor-title" type="text" name="title_en" id="studio-note-title-en"
                                                   value="<?= wsn_h((string)$englishState['content']['title']) ?>"
                                                   placeholder="English title" aria-label="Studio Note title in English"
                                                   <?= $englishAdaptationActive ? 'readonly' : '' ?>>
                                            <div id="editor-container-en" class="studio-note-editor" aria-label="Studio Note content in English"></div>
                                            <input type="hidden" name="body_en" id="body-input-en">
                                        </div>
                                    </section>
                                </div>

                                <details class="studio-editorial-panel">
                                    <summary>
                                        <span>Edición de portada y SEO</span>
                                        <small>Metadatos por idioma</small>
                                    </summary>
                                    <div class="studio-editorial-panel__body studio-seo-grid">
                                        <?php foreach (['es' => ['Español', $spanishState['content']], 'en' => ['English', $englishState['content']]] as $locale => [$languageLabel, $localizedContent]): ?>
                                            <div class="studio-seo-column<?= $locale === 'en' && $englishAdaptationActive ? ' is-adapting' : '' ?>"
                                                 <?= $locale === 'en' ? 'data-english-dependent' : '' ?>
                                                 <?= $locale === 'en' && $englishAdaptationActive ? 'inert' : '' ?>>
                                                <h3><?= wsn_h($languageLabel) ?></h3>
                                                <label class="studio-seo-field">Entradilla
                                                    <textarea name="excerpt_<?= $locale ?>"><?= wsn_h((string)$localizedContent['excerpt']) ?></textarea>
                                                </label>
                                                <label class="studio-seo-field">Slug
                                                    <input name="slug_<?= $locale ?>" value="<?= wsn_h((string)$localizedContent['slug']) ?>">
                                                </label>
                                                <label class="studio-seo-field">SEO title
                                                    <input name="seo_title_<?= $locale ?>" value="<?= wsn_h((string)$localizedContent['seo_title']) ?>">
                                                </label>
                                                <label class="studio-seo-field">Meta description
                                                    <textarea name="seo_description_<?= $locale ?>"><?= wsn_h((string)$localizedContent['seo_description']) ?></textarea>
                                                </label>
                                                <label class="studio-seo-field">Texto alternativo de portada
                                                    <textarea name="alt_text_<?= $locale ?>"><?= wsn_h((string)$localizedContent['alt_text']) ?></textarea>
                                                </label>
                                                <label class="studio-seo-field">Caption editorial
                                                    <textarea name="caption_<?= $locale ?>"><?= wsn_h((string)($localizedContent['caption'] ?? '')) ?></textarea>
                                                </label>
                                                <label class="studio-seo-field">Tags
                                                    <textarea name="tags_<?= $locale ?>"><?= wsn_h((string)($localizedContent['tags'] ?? '')) ?></textarea>
                                                </label>
                                                <label class="studio-seo-field">Arquitectura de búsqueda
                                                    <textarea name="search_terms_<?= $locale ?>"><?= wsn_h((string)$localizedContent['search_terms']) ?></textarea>
                                                </label>
                                                <input type="hidden" name="image_metadata_<?= $locale ?>"
                                                       value="<?= wsn_h(json_encode((array)($localizedContent['image_metadata'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                                                <input type="hidden" name="meta_source_hash_<?= $locale ?>"
                                                       value="<?= wsn_h((string)($localizedContent['meta_source_hash'] ?? '')) ?>">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>

                                <div class="studio-note-actions">
                                    <div class="studio-note-actions__main">
                                        <button class="button-link primary studio-note-publish" name="action" value="publish_draft" type="submit"
                                                <?= $publicationActive ? 'disabled' : '' ?>>
                                            <?= $publicationActive ? 'Publicando…' : ((string)$openDraft['status'] === 'published' ? 'Actualizar publicación' : 'Publicar ES + EN') ?>
                                        </button>
                                        <button class="button-link secondary" name="action" value="save_draft" type="submit">Guardar borrador</button>
                                        <span class="studio-publication-state" data-publication-state>
                                            <?= $publicationActive ? 'Analizando ES · preparando EN' : '' ?>
                                        </span>
                                    </div>
                                    <div class="studio-note-actions__secondary">
                                        <?php if ((string)$openDraft['status'] === 'published'): ?>
                                            <button class="studio-note-secondary-action" name="action" value="unpublish_draft" type="submit">Retirar</button>
                                        <?php endif; ?>
                                        <button class="studio-note-secondary-action studio-note-secondary-action--danger" name="action" value="delete_draft" type="submit" onclick="return confirm('¿Eliminar esta Nota de estudio?')">Eliminar</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
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
                            $draftSpanish = (array)($noteEditorialIndex[(int)$draft['id']]['es']['content'] ?? []);
                            $draftEnglish = (array)($noteEditorialIndex[(int)$draft['id']]['en']['content'] ?? []);
                            $draftTitle = trim((string)($draftSpanish['title'] ?? ''))
                                ?: trim((string)($draftEnglish['title'] ?? ''))
                                ?: (string)$draft['title'];
                            $draftBody = trim((string)($draftSpanish['body_html'] ?? ''))
                                ?: trim((string)($draftEnglish['body_html'] ?? ''))
                                ?: (string)$draft['objective'];
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
                                $thumbUrl = first_html_image_src($draftBody);
                            }
                            
                            $snippet = trim(strip_tags($draftBody));
                            if (mb_strlen($snippet) > 180) {
                                $snippet = mb_substr($snippet, 0, 177) . '...';
                            }
                        ?>
                            <a class="studio-draft<?= $thumbUrl === '' ? ' studio-draft--text-only' : '' ?>" href="website_studio_notes.php?draft=<?= (int)$draft['id'] ?>" aria-label="Editar <?= wsn_h($draftTitle) ?>">
                                <?php if ($thumbUrl !== ''): ?>
                                    <div class="studio-draft__thumb">
                                        <img src="<?= wsn_h($thumbUrl) ?>" alt="">
                                    </div>
                                <?php endif; ?>
                                
                                <div class="studio-draft__body">
                                    <h3><?= wsn_h($draftTitle) ?></h3>
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
                    var toolbarOptions = [
                        [{ 'header': [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline'],
                        ['blockquote'],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        ['link', 'image'],
                        ['clean']
                    ];
                    var quillEs = new Quill('#editor-container-es', {
                        theme: 'snow',
                        modules: { toolbar: toolbarOptions }
                    });
                    var quillEn = new Quill('#editor-container-en', {
                        theme: 'snow',
                        modules: { toolbar: toolbarOptions }
                    });
                    var quill = quillEs;

                    quillEs.root.innerHTML = <?= json_encode((string)$spanishState['content']['body_html'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
                    quillEn.root.innerHTML = <?= json_encode((string)$englishState['content']['body_html'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
                    var form = document.querySelector('form.studio-note-form');
                    var activeJobAction = form ? form.getAttribute('data-active-job-action') : '';
                    var englishPanel = document.querySelector('[data-english-panel]');
                    var englishDependents = Array.prototype.slice.call(
                        document.querySelectorAll('[data-english-dependent]')
                    );
                    var englishLock = document.querySelector('[data-english-lock]');
                    var englishTitle = document.getElementById('studio-note-title-en');

                    function setEnglishAdaptationBusy(busy) {
                        if (englishPanel) {
                            englishPanel.classList.toggle('is-adapting', busy);
                            englishPanel.setAttribute('aria-busy', busy ? 'true' : 'false');
                        }
                        englishDependents.forEach(function(dependent) {
                            dependent.classList.toggle('is-adapting', busy);
                            if (busy) dependent.setAttribute('inert', '');
                            else dependent.removeAttribute('inert');
                        });
                        if (englishLock) englishLock.hidden = !busy;
                        if (englishTitle) englishTitle.readOnly = busy;
                        quillEn.enable(!busy);
                    }
                    setEnglishAdaptationBusy(['adapt', 'publish'].indexOf(activeJobAction) !== -1);

                    var imageTools = document.getElementById('studio-image-tools');
                    var toolbarModule = quill.getModule('toolbar');
                    if (imageTools && toolbarModule && toolbarModule.container) {
                        toolbarModule.container.insertAdjacentElement('afterend', imageTools);
                    }
                    var selectedImage = null;

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

                    quill.root.addEventListener('click', function(e) {
                        if (e.target && e.target.tagName === 'IMG') {
                            selectImage(e.target);
                        } else selectImage(null);
                    });
                    
                    if (form) {
                        form.addEventListener('submit', function() {
                            document.getElementById('body-input-es').value = quillEs.root.innerHTML;
                            document.getElementById('body-input-en').value = quillEn.root.innerHTML;
                        });
                    }

                    var activeJobId = Number(form ? form.getAttribute('data-active-job') : 0) || 0;
                    var editorialCsrf = form ? form.getAttribute('data-editorial-csrf') : '';
                    var publicationState = document.querySelector('[data-publication-state]');
                    function pollEditorialJob() {
                        if (!activeJobId || !editorialCsrf) return;
                        var body = new FormData();
                        body.append('csrf', editorialCsrf);
                        body.append('action', 'generation_status');
                        body.append('entity_type', 'studio_note');
                        body.append('entity_id', form.getAttribute('data-note-id'));
                        body.append('job_id', String(activeJobId));
                        fetch('bilingual_editorial.php', {method:'POST', body:body, headers:{'Accept':'application/json'}})
                            .then(function(response) { return response.json(); })
                            .then(function(result) {
                                if (!result.ok || !result.job) throw new Error(result.error || 'No se pudo consultar la adaptación.');
                                if (publicationState) publicationState.textContent = result.job.status === 'processing'
                                    ? 'Analizando ES · preparando EN'
                                    : result.job.status;
                                if (result.job.status === 'completed') {
                                    window.location.reload();
                                    return;
                                }
                                if (['failed', 'enqueue_failed'].indexOf(result.job.status) !== -1) {
                                    if (publicationState) publicationState.textContent = result.job.error || 'La publicación falló';
                                    setEnglishAdaptationBusy(false);
                                    return;
                                }
                                window.setTimeout(pollEditorialJob, 1200);
                            })
                            .catch(function(error) {
                                if (publicationState) publicationState.textContent = error.message || 'Publicación no disponible';
                            });
                    }
                    pollEditorialJob();
                });
            </script>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
