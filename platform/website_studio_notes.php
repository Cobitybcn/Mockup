<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::WEBSITE_MANAGE, 'Studio Notes');
$pdo = Database::connection();
$userId = (int)$user['id'];
$draftId = max(0, (int)($_GET['draft'] ?? 0));

$notice = '';
$error = '';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$websiteBoard = new WebsiteBoardService($pdo);
$editorial = new BilingualEditorialService($pdo);
$studioWorkspace = new StudioNoteWorkspaceService($pdo);
$studioSources = $draftId > 0 ? $websiteBoard->sources($userId) : [];
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
            if ($title === '') {
                $title = 'Nueva nota de estudio';
            }
            $now = date('c');
            $payload = [
                'channels' => ['website_blog'],
                'destinations' => ['website_blog'],
                'editorial_sync_key' => 'studio-note-' . bin2hex(random_bytes(16)),
                'mockup_ids' => [],
                'channel_status' => ['website_blog' => 'draft'],
            ];
            $stmt = $pdo->prepare('INSERT INTO social_campaigns (user_id, campaign_type, title, objective, source_type, source_id, source_label, status, payload_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $userId,
                'website_blog',
                $title,
                '',
                'custom',
                '',
                'Independent note',
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

        if (in_array($action, ['save_draft', 'update_english', 'publish_changes', 'publish_draft'], true)) {
            $id = max(0, (int)($_POST['draft_id'] ?? 0));
            $spanish = wsn_post_content('es');
            $english = wsn_post_content('en');
            if (trim((string)$spanish['title']) === '') throw new RuntimeException('El título en español es obligatorio.');
            if (trim(strip_tags((string)$spanish['body_html'])) === '') throw new RuntimeException('Escribí el contenido español antes de guardar.');

            $previousSpanishContent = (array)$editorial->get(
                $userId,
                'studio_note',
                $id,
                'es'
            )['content'];
            foreach (['excerpt', 'slug', 'seo_title', 'seo_description', 'alt_text', 'caption', 'tags', 'search_terms'] as $metadataField) {
                if (($spanish[$metadataField] ?? null) !== ($previousSpanishContent[$metadataField] ?? null)) {
                    $spanish['meta_source_hash'] = '';
                    break;
                }
            }
            $spanish['body_html'] = $websiteBoard->normalizeNoteBody($userId, $id, (string)$spanish['body_html']);
            $editorial->save($userId, 'studio_note', $id, 'es', $spanish);
            if (wsn_has_content($english)) {
                $editorial->save($userId, 'studio_note', $id, 'en', $english);
            }
            $savedSpanishState = $editorial->get($userId, 'studio_note', $id, 'es');
            $savedEnglishState = $editorial->get($userId, 'studio_note', $id, 'en');
            $changes = StudioNoteChangeClassifier::classify(
                (array)$savedSpanishState['content'],
                (array)$savedSpanishState['published_content'],
                (array)$savedEnglishState['content'],
                (array)$savedEnglishState['published_content'],
                (bool)$savedSpanishState['is_published'],
                (bool)$savedEnglishState['is_published']
            );
            if ($action === 'publish_draft') {
                $action = !empty($changes['needs_english_update'])
                    ? 'update_english'
                    : 'publish_changes';
            }

            if ($action === 'update_english') {
                if (!$editorial->isEnabled($userId)) {
                    throw new RuntimeException('El espacio editorial bilingüe no está habilitado para esta cuenta.');
                }
                if (empty($changes['needs_english_update'])) {
                    // Quill can normalize visual HTML after the page was
                    // classified. Trust the server and publish that visual
                    // change without creating an AI job.
                    $action = 'publish_changes';
                }
                if ($action === 'update_english') {
                    $jobs = new BilingualEditorialJobService($pdo);
                    $protectedSpanishFields = StudioNoteChangeClassifier::protectedSpanishSeoFields(
                        (array)$savedSpanishState['content'],
                        (array)$savedSpanishState['published_content']
                    );
                    $protectedSeoCount = count(array_intersect(
                        ['excerpt', 'slug', 'seo_title', 'seo_description', 'alt_text', 'caption', 'tags', 'search_terms'],
                        $protectedSpanishFields
                    ));
                    $job = $jobs->createOrReuse($userId, 'studio_note', $id, 'adapt', [
                        'current_spanish' => (array)$savedSpanishState['content'],
                        'current_english' => (array)$savedEnglishState['content'],
                        'protected_spanish_fields' => $protectedSpanishFields,
                        'review_spanish_metadata' => !empty($changes['media_requires_analysis'])
                            || $protectedSeoCount < 8,
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
                    $_SESSION['wsn_notice'] = 'Actualizando la versión inglesa y el SEO pendiente. Todavía no se publicará.';
                }
            }
            if ($action === 'publish_changes') {
                if (!empty($changes['needs_english_update'])) {
                    throw new RuntimeException('Actualizá la versión inglesa antes de publicar.');
                }
                $englishContent = (array)$savedEnglishState['content'];
                if (!wsn_has_content($englishContent)) {
                    throw new RuntimeException('Prepará la versión inglesa antes de publicar.');
                }
                $englishContent['body_html'] = StudioNoteMediaService::mirrorImagesWithPublishedFallback(
                    (string)($savedSpanishState['content']['body_html'] ?? ''),
                    (string)($englishContent['body_html'] ?? ''),
                    (string)($savedEnglishState['published_content']['body_html'] ?? '')
                );
                $editorial->save($userId, 'studio_note', $id, 'en', $englishContent);
                $editorial->setPublished($userId, 'studio_note', $id, 'es', true);
                $editorial->setPublished($userId, 'studio_note', $id, 'en', true);
                $websiteBoard->saveNote(
                    $userId,
                    $id,
                    (string)($englishContent['title'] ?? ''),
                    (string)($englishContent['body_html'] ?? ''),
                    (string)($savedSpanishState['content']['body_html'] ?? '')
                );
                $_SESSION['wsn_notice'] = 'Cambios publicados sin ejecutar IA.';
            } elseif ($action !== 'update_english') {
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
    $bodyHtml = trim((string)($_POST['body_' . $locale] ?? ''));
    return [
        'title' => trim((string)($_POST['title_' . $locale] ?? '')),
        'excerpt' => trim((string)($_POST['excerpt_' . $locale] ?? '')),
        'body_html' => $bodyHtml,
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
function wsn_note_media_url(int $noteId, ?string $file, int $width = 520): string
{
    return StudioNoteMediaService::deliveryUrl($noteId, basename((string)$file), $width);
}

function first_html_image_src(string $html): string
{
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
        // The rich-text body stores query separators as HTML entities. Decode
        // once before escaping the final <img> attribute, otherwise "&amp;"
        // becomes part of the actual request and the note media endpoint
        // receives no file parameter.
        return html_entity_decode((string)$matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
    $localizedStmt = $pdo->prepare("SELECT entity_id,locale,content_json,published_content_json,status,is_published
        FROM bilingual_editorial_content
        WHERE user_id=? AND entity_type='studio_note' AND entity_id IN ($marks)");
    $localizedStmt->execute(array_merge([$userId], $noteIds));
    foreach ($localizedStmt as $localizedRow) {
        $content = json_decode((string)$localizedRow['content_json'], true);
        if (!is_array($content)) continue;
        $publishedContent = json_decode((string)($localizedRow['published_content_json'] ?? ''), true);
        $noteEditorialIndex[(int)$localizedRow['entity_id']][(string)$localizedRow['locale']] = [
            'content' => $content,
            'published_content' => is_array($publishedContent) ? $publishedContent : [],
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
$editorialProcessActive = false;
$changeState = [
    'state' => 'english_pending',
    'primary_action' => 'update_english',
    'status_message' => 'La versión inglesa todavía no está preparada',
    'status_detail' => 'La adaptación inglesa y el SEO pendiente se actualizarán antes de publicar.',
    'english_status' => 'Pendiente de actualización',
    'has_unpublished_changes' => true,
    'needs_english_update' => true,
];
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
    $spanishState['content']['body_html'] = StudioNoteMediaService::rewriteDeliveryUrls(
        $userId,
        (int)$openDraft['id'],
        (string)$spanishState['content']['body_html']
    );
    $englishState['content']['body_html'] = StudioNoteMediaService::rewriteDeliveryUrls(
        $userId,
        (int)$openDraft['id'],
        (string)$englishState['content']['body_html']
    );
    $spanishState['content'] = StudioNoteMediaService::hydrateImageMetadata(
        (array)$spanishState['content'],
        $studioSources,
        'es'
    );
    $englishState['content'] = StudioNoteMediaService::hydrateImageMetadata(
        (array)$englishState['content'],
        $studioSources,
        'en'
    );
    try {
        $studioWorkspace->syncHistoricalJobs($userId, (int)$openDraft['id']);
        $activeEditorialJob = (new BilingualEditorialJobService($pdo))->activeForEntity(
            $userId,
            'studio_note',
            (int)$openDraft['id']
        );
        $englishAdaptationActive = is_array($activeEditorialJob)
            && in_array((string)($activeEditorialJob['action'] ?? ''), ['adapt', 'publish'], true);
        $editorialProcessActive = is_array($activeEditorialJob);
    } catch (Throwable) {
        $activeEditorialJob = null;
        $englishAdaptationActive = false;
        $editorialProcessActive = false;
    }
    $changeState = StudioNoteChangeClassifier::classify(
        (array)$spanishState['content'],
        (array)$spanishState['published_content'],
        (array)$englishState['content'],
        (array)$englishState['published_content'],
        (bool)$spanishState['is_published'],
        (bool)$englishState['is_published']
    );
    $englishState['content']['body_html'] = StudioNoteMediaService::mirrorImagesWithPublishedFallback(
        (string)$spanishState['content']['body_html'],
        (string)$englishState['content']['body_html'],
        StudioNoteMediaService::rewriteDeliveryUrls(
            $userId,
            (int)$openDraft['id'],
            (string)($englishState['published_content']['body_html'] ?? '')
        )
    );
}

$mockupSources = array_values(array_filter(
    $studioSources,
    static fn(array $source): bool => (string)($source['type'] ?? '') === 'mockup'
));
$mockupArtworkFilters = [];
$mockupSeriesFilters = [];
foreach ($mockupSources as $mockupSource) {
    $artworkId = (int)($mockupSource['artworkId'] ?? 0);
    $artworkTitle = trim((string)($mockupSource['artworkTitle'] ?? ''));
    if ($artworkId > 0 && $artworkTitle !== '') $mockupArtworkFilters[$artworkId] = $artworkTitle;
    $seriesId = (int)($mockupSource['seriesId'] ?? 0);
    $seriesTitle = trim((string)($mockupSource['seriesTitle'] ?? ''));
    if ($seriesId > 0 && $seriesTitle !== '') $mockupSeriesFilters[$seriesId] = $seriesTitle;
}
natcasesort($mockupArtworkFilters);
natcasesort($mockupSeriesFilters);
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
        .studio-create-decision { width:164px !important; min-width:164px !important; height:164px !important; min-height:164px !important; align-self:start; margin:0 !important; padding:18px !important; }
        .studio-create-decision__content { display:flex !important; align-items:center; flex-direction:column; justify-content:center; gap:10px; }
        .studio-create-decision .studio-create-decision__plus { display:block !important; font-size:48px !important; font-weight:300 !important; line-height:.72 !important; letter-spacing:0 !important; }
        .studio-create-decision .studio-create-decision__label { display:block !important; font-size:14px !important; line-height:1 !important; letter-spacing:.12em !important; }
        .studio-editor-workspace { width:100%; box-sizing:border-box; margin:0 auto; padding:28px 24px 32px; border:1px solid var(--line); border-radius:4px; background:var(--surface); }
        .studio-note-editor-top { display:flex; justify-content:flex-end; margin-bottom:8px; }
        .studio-note-editor-top a { color:#625b55; font-size:12px; font-weight:650; letter-spacing:.05em; text-decoration:none; text-transform:uppercase; }
        .studio-media-carousel { margin:0 0 26px; padding:14px 14px 10px; border:1px solid var(--line); border-radius:4px; background:#fbfaf7; }
        .studio-media-carousel__toolbar { display:flex; align-items:center; justify-content:space-between; gap:18px; margin-bottom:11px; }
        .studio-media-carousel__heading { display:flex; align-items:baseline; gap:10px; min-width:0; }
        .studio-media-carousel__heading h2 { margin:0; color:var(--ink); font:400 21px/1.2 var(--font-serif); }
        .studio-media-carousel__heading span { color:#6c645d; font-size:11px; line-height:1.35; }
        .studio-media-carousel__filters { display:flex; align-items:center; gap:8px; }
        .studio-media-carousel__filters select,
        .studio-media-carousel__filters input { width:auto; min-width:150px; height:36px; box-sizing:border-box; margin:0; padding:7px 10px; border:1px solid var(--line); border-radius:2px; background:#fff; color:var(--ink); font:400 13px/1.2 var(--font-sans); box-shadow:none; }
        .studio-media-carousel__filters input { min-width:220px; }
        .studio-media-carousel__rail { display:grid; grid-auto-flow:column; grid-auto-columns:clamp(148px,12vw,182px); gap:10px; overflow-x:auto; overflow-y:hidden; padding:1px 1px 9px; scroll-snap-type:x proximity; scrollbar-width:thin; scrollbar-color:#c8beb4 transparent; }
        .studio-media-carousel__rail::-webkit-scrollbar { height:6px; }
        .studio-media-carousel__rail::-webkit-scrollbar-thumb { border-radius:999px; background:#c8beb4; }
        .studio-media-card { position:relative; display:grid; align-content:start; min-width:0; margin:0 !important; padding:5px !important; border:1px solid #c8c2ba !important; border-radius:2px !important; background:#f4f1eb !important; color:var(--ink) !important; box-shadow:none !important; cursor:grab; scroll-snap-align:start; text-align:left; }
        .studio-media-card:hover,
        .studio-media-card:focus-visible { border-color:#a9949f !important; background:#f4f1eb !important; transform:none !important; box-shadow:0 0 0 2px rgba(185,163,194,.18) !important; }
        .studio-media-card[hidden] { display:none !important; }
        .studio-media-card.is-in-note { border-color:#a9949f !important; }
        .studio-media-card__image { position:relative; display:block; aspect-ratio:4/5; overflow:hidden; background:#e6e2dc; }
        .studio-media-card__image img { display:block; width:100%; height:100%; object-fit:cover; pointer-events:none; }
        .studio-media-card__state { display:none; position:absolute; top:7px; right:7px; padding:4px 6px; border-radius:999px; background:rgba(243,238,244,.92); color:#514951; font-size:9px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .studio-media-card.is-in-note .studio-media-card__state { display:block; }
        .studio-media-card__label { display:block; min-width:0; padding:7px 2px 2px; overflow:hidden; font:400 14px/1.15 var(--font-serif); text-overflow:ellipsis; white-space:nowrap; }
        .studio-media-carousel__empty { margin:4px 2px 8px; color:#6c645d; font-size:13px; }
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
        .studio-note-command-bar { position:sticky; top:74px; z-index:8; display:flex; align-items:center; justify-content:space-between; gap:22px; margin:0 0 22px; padding:14px 16px; border:1px solid #d7ccd9; background:#f5f0f5; box-shadow:0 5px 16px rgba(57,45,60,.06); }
        .studio-note-command-bar__state { min-width:0; }
        .studio-note-command-bar__state strong { display:block; color:#423b40; font:400 18px/1.25 var(--font-serif); }
        .studio-note-command-bar__state span { display:block; margin-top:4px; color:#70676d; font-size:12px; line-height:1.4; }
        .studio-note-command-bar__actions { display:flex; align-items:center; flex:0 0 auto; gap:10px; }
        .studio-note-command-bar__actions button { width:auto; min-width:150px; margin:0; }
        .studio-note-command-bar__actions [hidden] { display:none !important; }
        .studio-note-actions { display:flex; align-items:center; justify-content:space-between; gap:18px; margin-top:20px; }
        .studio-note-actions__main { display:flex; gap:10px; }
        .studio-publication-state { align-self:center; color:#625b55; font-size:11px; letter-spacing:.04em; }
        .studio-note-actions__secondary { display:flex; gap:8px; margin-left:auto; }
        .studio-note-actions button { width:auto; min-width:148px; margin:0; }
        .studio-note-publish { border-color:#b8a4c0 !important; background:#b8a4c0 !important; color:#fffaf7 !important; }
        .studio-note-publish:hover { border-color:#a791b0 !important; background:#a791b0 !important; }
        .studio-note-command-bar__actions .studio-note-publish--commit {
            width:116px;
            min-width:116px;
            height:116px;
            padding:14px;
            border-radius:2px;
            font-size:13px;
            line-height:1.2;
            letter-spacing:.08em;
            text-align:center;
        }
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
        .studio-drafts-list { display:flex; align-items:flex-start; flex-wrap:wrap; gap:18px; }
        .studio-draft { display:grid; grid-template-columns:138px minmax(0,1fr); width:min(100%,440px); min-height:188px; overflow:hidden; border:1px solid #cfc3d3; border-radius:4px; background:#eee8f0; color:var(--ink); text-decoration:none; box-shadow:0 8px 20px rgba(126,104,133,.10); transition:border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
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
            .studio-create-decision { width:126px !important; min-width:126px !important; height:126px !important; min-height:126px !important; padding:12px !important; font-size:11px !important; }
            .studio-media-carousel__toolbar { align-items:flex-start; flex-direction:column; }
            .studio-media-carousel__filters { width:100%; flex-wrap:wrap; }
            .studio-media-carousel__filters select,
            .studio-media-carousel__filters input { min-width:0; flex:1 1 150px; }
            .studio-media-carousel__rail { grid-auto-columns:minmax(146px,44vw); }
            .studio-bilingual-editors { grid-template-columns:1fr; }
            .studio-editor-workspace { padding:20px 16px 24px; }
            .studio-notes-page input.studio-note-editor-title { font-size:31px; }
            .studio-notes-page .studio-note-editor.ql-container { height:440px !important; min-height:440px; }
            .studio-notes-page .studio-note-editor .ql-editor { min-height:438px; padding:24px 18px; font-size:17px; line-height:1.68; }
            .studio-notes-page .studio-note-editor .ql-editor img { max-width:100%; max-height:320px; margin:18px auto; }
            .studio-image-tools { align-items:flex-start; flex-wrap:wrap; gap:8px; }
            .studio-image-tools__group { padding-left:8px; }
            .studio-note-command-bar { top:58px; align-items:stretch; flex-direction:column; }
            .studio-note-command-bar__actions { display:grid; grid-template-columns:1fr 1fr; }
            .studio-note-command-bar__actions button { width:100%; min-width:0; }
            .studio-note-actions { align-items:stretch; flex-direction:column; }
            .studio-note-actions__main { display:grid; grid-template-columns:1fr 1fr; }
            .studio-note-actions__main button { width:100%; min-width:0; }
            .studio-note-actions__secondary { align-self:flex-end; margin-left:0; }
            .studio-seo-grid { grid-template-columns:1fr; }
            .studio-draft { grid-template-columns:112px minmax(0,1fr); width:100%; min-height:164px; }
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



            <?php if ($openDraft): ?>
                <section class="studio-editor-workspace">
                    <div class="studio-note-editor-top"><a href="website_studio_notes.php">Close</a></div>
                    <?php if ($mockupSources): ?>
                        <section class="studio-media-carousel" aria-labelledby="studio-media-carousel-title">
                            <div class="studio-media-carousel__toolbar">
                                <div class="studio-media-carousel__heading">
                                    <h2 id="studio-media-carousel-title">Mockups para la nota</h2>
                                    <span>Arrastrá o pulsá una imagen para insertarla.</span>
                                </div>
                                <div class="studio-media-carousel__filters">
                                    <select data-mockup-artwork-filter aria-label="Filtrar mockups por obra">
                                        <option value="">Todas las obras</option>
                                        <?php foreach ($mockupArtworkFilters as $artworkId => $artworkTitle): ?>
                                            <option value="<?= (int)$artworkId ?>"><?= wsn_h($artworkTitle) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select data-mockup-series-filter aria-label="Filtrar mockups por serie">
                                        <option value="">Todas las series</option>
                                        <?php foreach ($mockupSeriesFilters as $seriesId => $seriesTitle): ?>
                                            <option value="<?= (int)$seriesId ?>"><?= wsn_h($seriesTitle) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="search" data-mockup-search placeholder="Buscar mockup" aria-label="Buscar mockup">
                                </div>
                            </div>
                            <div class="studio-media-carousel__rail" data-mockup-carousel>
                                <?php foreach ($mockupSources as $mockupSource): ?>
                                    <?php
                                        $guide = (array)($mockupSource['editorialGuide'] ?? []);
                                        $guideEn = (array)($mockupSource['editorialGuideEn'] ?? []);
                                        $mockupFile = basename((string)($mockupSource['file'] ?? ''));
                                        $mockupAlt = trim((string)($guide['altText'] ?? '')) ?: (string)$mockupSource['label'];
                                        $mockupSearch = implode(' ', array_filter([
                                            (string)($mockupSource['label'] ?? ''),
                                            (string)($mockupSource['artworkTitle'] ?? ''),
                                            (string)($mockupSource['seriesTitle'] ?? ''),
                                            (string)($mockupSource['searchTerms'] ?? ''),
                                        ]));
                                    ?>
                                    <button class="studio-media-card" type="button" draggable="true"
                                            data-mockup-source="<?= wsn_h((string)$mockupSource['key']) ?>"
                                            data-mockup-file="<?= wsn_h($mockupFile) ?>"
                                            data-mockup-url="<?= wsn_h(wsn_media_url($mockupFile, 900)) ?>"
                                            data-mockup-artwork="<?= (int)($mockupSource['artworkId'] ?? 0) ?>"
                                            data-mockup-series="<?= (int)($mockupSource['seriesId'] ?? 0) ?>"
                                            data-mockup-search-text="<?= wsn_h(mb_strtolower($mockupSearch)) ?>"
                                            data-mockup-guide="<?= wsn_h(json_encode($guide, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                            data-mockup-guide-en="<?= wsn_h(json_encode($guideEn, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                            aria-label="Insertar <?= wsn_h((string)$mockupSource['label']) ?>">
                                        <span class="studio-media-card__image">
                                            <img src="<?= wsn_h(wsn_media_url($mockupFile, 520)) ?>" alt="<?= wsn_h($mockupAlt) ?>" loading="lazy">
                                            <span class="studio-media-card__state">En la nota</span>
                                        </span>
                                        <span class="studio-media-card__label" title="<?= wsn_h((string)$mockupSource['label']) ?>"><?= wsn_h((string)$mockupSource['label']) ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <p class="studio-media-carousel__empty" data-mockup-empty hidden>No hay mockups para este filtro.</p>
                        </section>
                    <?php endif; ?>
                    <form class="studio-note-form" method="post" enctype="multipart/form-data"
                          data-note-id="<?= (int)$openDraft['id'] ?>"
                          data-editorial-csrf="<?= wsn_h(Auth::csrfToken('bilingual_editorial')) ?>"
                          data-active-job="<?= (int)($activeEditorialJob['id'] ?? 0) ?>"
                          data-active-job-action="<?= wsn_h((string)($activeEditorialJob['action'] ?? '')) ?>"
                          data-change-state="<?= wsn_h((string)$changeState['state']) ?>">
                        <input type="hidden" name="csrf" value="<?= wsn_h($_SESSION['studio_notes_csrf']) ?>">
                        <input type="hidden" name="draft_id" value="<?= (int)$openDraft['id'] ?>">
                        <div class="studio-note-command-bar" data-command-bar>
                            <div class="studio-note-command-bar__state">
                                <strong data-change-message><?= wsn_h((string)$changeState['status_message']) ?></strong>
                                <span data-change-detail><?= wsn_h((string)$changeState['status_detail']) ?></span>
                            </div>
                            <div class="studio-note-command-bar__actions">
                                <button class="button-link primary studio-note-publish<?= (string)$changeState['primary_action'] === 'publish_changes' ? ' studio-note-publish--commit' : '' ?>" name="action"
                                        value="<?= wsn_h((string)($changeState['primary_action'] ?: 'publish_changes')) ?>"
                                        type="submit" data-publish-action
                                        <?= $editorialProcessActive ? 'disabled' : '' ?>
                                        <?= !$editorialProcessActive && (string)$changeState['primary_action'] === '' ? 'hidden' : '' ?>>
                                    <?php if ($editorialProcessActive): ?>
                                        <?= $englishAdaptationActive ? 'Actualizando inglés…' : 'Procesando…' ?>
                                    <?php else: ?>
                                        <?= (string)$changeState['primary_action'] === 'update_english'
                                            ? 'Actualizar inglés'
                                            : 'PUBLICAR' ?>
                                    <?php endif; ?>
                                </button>
                                <button class="button-link secondary" name="action" value="save_draft" type="submit">Guardar borrador</button>
                                <span class="studio-publication-state" data-publication-state>
                                    <?= $editorialProcessActive ? ($englishAdaptationActive ? 'Actualizando inglés' : 'Procesando') : '' ?>
                                </span>
                            </div>
                        </div>
                        <div class="studio-note-editor-shell">
                            <div class="studio-note-writing-desk">
                                <div class="studio-bilingual-editors">
                                    <section class="studio-language-editor" aria-labelledby="studio-language-es">
                                        <div class="studio-language-heading">
                                            <span id="studio-language-es">Español · fuente</span>
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
                                            <span class="studio-language-state" data-english-state><?= wsn_h((string)$changeState['english_status']) ?></span>
                                        </div>
                                        <div class="studio-english-panel__lock" data-english-lock<?= $englishAdaptationActive ? '' : ' hidden' ?>>
                                            <div>
                                                <strong>Actualizando inglés</strong>
                                                <span>Revisando el SEO pendiente y adaptando el original español.</span>
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
                <div class="studio-drafts-list">
                    <form method="post" id="studio-note-create-form">
                        <input type="hidden" name="csrf" value="<?= wsn_h($_SESSION['studio_notes_csrf']) ?>">
                        <input type="hidden" name="action" value="create_draft">
                        <button class="social-square-button social-square-button--studio_process studio-create-decision" type="submit">
                            <span class="studio-create-decision__content">
                                <span class="studio-create-decision__plus">+</span>
                                <span class="studio-create-decision__label">NOTE</span>
                            </span>
                        </button>
                    </form>
                    <?php if (!$websiteDrafts): ?>
                        <div class="empty-state">Todavía no hay notas. Creá el primer borrador desde el bloque + Note.</div>
                    <?php else: ?>
                        <?php foreach ($websiteDrafts as $draft): 
                            $payload = (array)$draft['_payload']; 
                            $draftSpanish = (array)($noteEditorialIndex[(int)$draft['id']]['es']['content'] ?? []);
                            $draftEnglish = (array)($noteEditorialIndex[(int)$draft['id']]['en']['content'] ?? []);
                            $publishedSpanish = (array)($noteEditorialIndex[(int)$draft['id']]['es']['published_content'] ?? []);
                            $publishedEnglish = (array)($noteEditorialIndex[(int)$draft['id']]['en']['published_content'] ?? []);
                            $draftTitle = trim((string)($draftSpanish['title'] ?? ''))
                                ?: trim((string)($draftEnglish['title'] ?? ''))
                                ?: (string)$draft['title'];
                            $draftBody = trim((string)($draftSpanish['body_html'] ?? ''))
                                ?: trim((string)($draftEnglish['body_html'] ?? ''))
                                ?: (string)$draft['objective'];
                            $draftBody = StudioNoteMediaService::rewriteDeliveryUrls(
                                $userId,
                                (int)$draft['id'],
                                $draftBody
                            );
                            $publishedBody = trim((string)($publishedSpanish['body_html'] ?? ''))
                                ?: trim((string)($publishedEnglish['body_html'] ?? ''));
                            $publishedBody = StudioNoteMediaService::rewriteDeliveryUrls(
                                $userId,
                                (int)$draft['id'],
                                $publishedBody
                            );
                            $mockupIds = array_values(array_filter(array_map('intval', (array)($payload['mockup_ids'] ?? []))));
                            
                            $thumbUrl = first_html_image_src($draftBody)
                                ?: first_html_image_src($publishedBody);
                            $payloadSource = is_array($payload['source'] ?? null) ? $payload['source'] : [];
                            $sourceFile = basename((string)($payloadSource['file'] ?? ''));
                            if ($thumbUrl === '' && $sourceFile !== '') {
                                $thumbUrl = str_starts_with(basename($sourceFile), 'studio-note-' . $userId . '-' . (int)$draft['id'] . '-')
                                    ? wsn_note_media_url((int)$draft['id'], $sourceFile, 360)
                                    : wsn_media_url($sourceFile, 360);
                            }
                            if ($thumbUrl === '' && $mockupIds) {
                                $stmt = $pdo->prepare("SELECT mockup_file FROM mockups WHERE id = ? AND user_id = ? LIMIT 1");
                                $stmt->execute([$mockupIds[0], $userId]);
                                $mFile = $stmt->fetchColumn();
                                if ($mFile) {
                                    $thumbUrl = 'media.php?file=' . rawurlencode(basename($mFile));
                                }
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
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>
            <?php if ($openDraft): ?>
            <script>
                document.addEventListener("DOMContentLoaded", function() {
                    var toolbarOptions = [
                        [{ 'header': [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline'],
                        ['blockquote'],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        [{ 'align': [] }],
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
                    var noteId = Number(form ? form.getAttribute('data-note-id') : 0) || 0;
                    var formCsrf = form ? form.querySelector('input[name="csrf"]') : null;
                    var activeJobAction = form ? form.getAttribute('data-active-job-action') : '';
                    var publishAction = form ? form.querySelector('[data-publish-action]') : null;
                    var publishedSpanishContent = <?= json_encode(
                        (array)($spanishState['published_content'] ?? []),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ) ?>;
                    var publishedEnglishContent = <?= json_encode(
                        (array)($englishState['published_content'] ?? []),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ) ?>;
                    var hasPublishedPair = <?= !empty($spanishState['is_published'])
                        && !empty($englishState['is_published'])
                        && (array)($spanishState['published_content'] ?? []) !== []
                        && (array)($englishState['published_content'] ?? []) !== []
                            ? 'true'
                            : 'false' ?>;
                    var changeMessage = document.querySelector('[data-change-message]');
                    var changeDetail = document.querySelector('[data-change-detail]');
                    var englishStateLabel = document.querySelector('[data-english-state]');
                    var englishPanel = document.querySelector('[data-english-panel]');
                    var englishDependents = Array.prototype.slice.call(
                        document.querySelectorAll('[data-english-dependent]')
                    );
                    var englishLock = document.querySelector('[data-english-lock]');
                    var englishTitle = document.getElementById('studio-note-title-en');
                    var mockupCards = Array.prototype.slice.call(document.querySelectorAll('[data-mockup-source]'));
                    var mockupArtworkFilter = document.querySelector('[data-mockup-artwork-filter]');
                    var mockupSeriesFilter = document.querySelector('[data-mockup-series-filter]');
                    var mockupSearch = document.querySelector('[data-mockup-search]');
                    var mockupEmpty = document.querySelector('[data-mockup-empty]');
                    var imageMetadataInputs = {
                        es: form ? form.querySelector('input[name="image_metadata_es"]') : null,
                        en: form ? form.querySelector('input[name="image_metadata_en"]') : null
                    };
                    var imageMetadataByLocale = {es: {}, en: {}};

                    function normalizedText(value) {
                        return String(value || '').replace(/\s+/gu, ' ').trim();
                    }

                    function textFromHtml(html) {
                        var container = document.createElement('div');
                        container.innerHTML = String(html || '').replace(/<img\b[^>]*>/giu, '');
                        return normalizedText(container.textContent || '');
                    }

                    function contentSnapshot(content, html) {
                        return JSON.stringify({
                            title: normalizedText(content.title),
                            body_text: textFromHtml(html)
                        });
                    }

                    function mediaFilesFromHtml(html) {
                        var container = document.createElement('div');
                        container.innerHTML = String(html || '');
                        return Array.prototype.slice.call(container.querySelectorAll('img')).map(function(image) {
                            return imageFile(image.getAttribute('src'));
                        }).filter(Boolean);
                    }

                    function hasCompleteMediaMetadata(files, content) {
                        var metadata = {};
                        (Array.isArray(content.image_metadata) ? content.image_metadata : []).forEach(function(row) {
                            var file = String(row && row.file || '');
                            if (file) metadata[file] = !!String(row.alt_text || '').trim()
                                && !!String(row.caption || '').trim();
                        });
                        return files.every(function(file) { return metadata[file] === true; });
                    }

                    function currentField(name) {
                        var field = form ? form.querySelector('[name="' + name + '"]') : null;
                        return field ? field.value : '';
                    }

                    function currentLocalizedContent(locale, quillEditor) {
                        return {
                            title: currentField('title_' + locale),
                            body_html: quillEditor.root.innerHTML,
                            excerpt: currentField('excerpt_' + locale),
                            slug: currentField('slug_' + locale),
                            seo_title: currentField('seo_title_' + locale),
                            seo_description: currentField('seo_description_' + locale),
                            alt_text: currentField('alt_text_' + locale),
                            caption: currentField('caption_' + locale),
                            tags: currentField('tags_' + locale),
                            search_terms: currentField('search_terms_' + locale),
                            image_metadata: parseJson(currentField('image_metadata_' + locale), [])
                        };
                    }

                    function preparedForPublication(content) {
                        if (!normalizedText(content.title) || !textFromHtml(content.body_html)) return false;
                        return [
                            'excerpt',
                            'slug',
                            'seo_title',
                            'seo_description',
                            'alt_text',
                            'caption',
                            'tags',
                            'search_terms'
                        ].every(function(field) {
                            return !!normalizedText(content[field]);
                        });
                    }

                    var clientDirty = false;
                    function updatePublishActionMode() {
                        if (!form || !publishAction || publishAction.disabled || !clientDirty) return;
                        var currentSpanish = currentLocalizedContent('es', quillEs);
                        var currentEnglish = currentLocalizedContent('en', quillEn);
                        var spanishContentChanged = contentSnapshot(
                            currentSpanish,
                            currentSpanish.body_html
                        ) !== contentSnapshot(
                            publishedSpanishContent,
                            publishedSpanishContent.body_html || ''
                        );
                        var englishContentChanged = contentSnapshot(
                            currentEnglish,
                            currentEnglish.body_html
                        ) !== contentSnapshot(
                            publishedEnglishContent,
                            publishedEnglishContent.body_html || ''
                        );
                        var currentMediaFiles = mediaFilesFromHtml(currentSpanish.body_html);
                        var publishedMediaFiles = mediaFilesFromHtml(publishedSpanishContent.body_html || '');
                        var mediaChanged = JSON.stringify(currentMediaFiles) !== JSON.stringify(publishedMediaFiles);
                        var mediaRequiresAnalysis = mediaChanged
                            && (!hasCompleteMediaMetadata(currentMediaFiles, currentSpanish)
                                || !hasCompleteMediaMetadata(currentMediaFiles, currentEnglish));
                        var needsEnglish = (!hasPublishedPair
                                && (!preparedForPublication(currentSpanish)
                                    || !preparedForPublication(currentEnglish)))
                            || (spanishContentChanged && !englishContentChanged)
                            || mediaRequiresAnalysis;
                        form.setAttribute('data-change-state', needsEnglish ? 'english_pending' : 'ready_to_publish');
                        publishAction.hidden = false;
                        publishAction.value = needsEnglish ? 'update_english' : 'publish_changes';
                        publishAction.textContent = needsEnglish ? 'Actualizar inglés' : 'PUBLICAR';
                        publishAction.classList.toggle('studio-note-publish--commit', !needsEnglish);
                        if (changeMessage) changeMessage.textContent = needsEnglish
                            ? 'El contenido en español fue modificado'
                            : 'Cambios listos para publicar';
                        if (changeDetail) changeDetail.textContent = needsEnglish
                            ? 'La adaptación inglesa y el SEO pendiente se actualizarán antes de publicar.'
                            : 'La publicación utilizará las versiones guardadas sin ejecutar IA.';
                        if (englishStateLabel) englishStateLabel.textContent = needsEnglish
                            ? 'Pendiente de actualización'
                            : (englishContentChanged ? 'Editado manualmente' : 'Sincronizado');
                    }

                    function parseJson(value, fallback) {
                        try {
                            var parsed = JSON.parse(value || '');
                            return parsed && typeof parsed === 'object' ? parsed : fallback;
                        } catch (error) {
                            return fallback;
                        }
                    }

                    Object.keys(imageMetadataInputs).forEach(function(locale) {
                        var input = imageMetadataInputs[locale];
                        var rows = input ? parseJson(input.value, []) : [];
                        (Array.isArray(rows) ? rows : []).forEach(function(row) {
                            var file = String(row && row.file || '');
                            if (file) imageMetadataByLocale[locale][file] = row;
                        });
                    });

                    function imageFile(source) {
                        try {
                            return new URL(String(source || ''), window.location.href).searchParams.get('file') || '';
                        } catch (error) {
                            return '';
                        }
                    }

                    function metadataFromGuide(file, guide) {
                        guide = guide && typeof guide === 'object' ? guide : {};
                        return {
                            file: file,
                            alt_text: String(guide.altText || ''),
                            caption: String(guide.caption || '')
                        };
                    }

                    function registerMockupMetadata(card) {
                        var file = card ? String(card.getAttribute('data-mockup-file') || '') : '';
                        if (!file) return;
                        var spanishGuide = parseJson(card.getAttribute('data-mockup-guide'), {});
                        var englishGuide = parseJson(card.getAttribute('data-mockup-guide-en'), {});
                        var spanishMetadata = metadataFromGuide(file, spanishGuide);
                        var englishMetadata = metadataFromGuide(file, englishGuide);
                        if (spanishMetadata.alt_text && spanishMetadata.caption) {
                            imageMetadataByLocale.es[file] = spanishMetadata;
                        }
                        if (englishMetadata.alt_text && englishMetadata.caption) {
                            imageMetadataByLocale.en[file] = englishMetadata;
                        }
                    }

                    function syncImageMetadata() {
                        var orderedFiles = [];
                        quillEs.root.querySelectorAll('img').forEach(function(image) {
                            var file = imageFile(image.getAttribute('src'));
                            if (file && orderedFiles.indexOf(file) === -1) orderedFiles.push(file);
                        });
                        mockupCards.forEach(function(card) {
                            if (orderedFiles.indexOf(String(card.getAttribute('data-mockup-file') || '')) !== -1) {
                                registerMockupMetadata(card);
                            }
                        });
                        var changed = false;
                        Object.keys(imageMetadataInputs).forEach(function(locale) {
                            var input = imageMetadataInputs[locale];
                            if (!input) return;
                            var value = JSON.stringify(orderedFiles.map(function(file) {
                                return imageMetadataByLocale[locale][file] || null;
                            }).filter(Boolean));
                            if (input.value !== value) {
                                input.value = value;
                                changed = true;
                            }
                        });
                        mockupCards.forEach(function(card) {
                            card.classList.toggle(
                                'is-in-note',
                                orderedFiles.indexOf(String(card.getAttribute('data-mockup-file') || '')) !== -1
                            );
                        });
                        return changed;
                    }

                    function insertMockup(card, index) {
                        if (!card) return;
                        var url = String(card.getAttribute('data-mockup-url') || '');
                        var file = String(card.getAttribute('data-mockup-file') || '');
                        if (!url || !file) return;
                        registerMockupMetadata(card);
                        var range = typeof index === 'number'
                            ? {index: index}
                            : (quillEs.getSelection(true) || {index: Math.max(0, quillEs.getLength() - 1)});
                        quillEs.insertEmbed(range.index, 'image', url, 'user');
                        quillEs.setSelection(range.index + 1, 0, 'silent');
                        var inserted = Array.prototype.slice.call(quillEs.root.querySelectorAll('img')).reverse().find(function(image) {
                            return imageFile(image.getAttribute('src')) === file;
                        });
                        if (inserted) {
                            var guide = parseJson(card.getAttribute('data-mockup-guide'), {});
                            inserted.setAttribute('alt', String(guide.altText || card.getAttribute('aria-label') || ''));
                            prepareImage(inserted);
                        }
                        syncImageMetadata();
                        quillEs.focus();
                    }

                    function filterMockupCarousel() {
                        var artwork = mockupArtworkFilter ? mockupArtworkFilter.value : '';
                        var series = mockupSeriesFilter ? mockupSeriesFilter.value : '';
                        var query = mockupSearch ? mockupSearch.value.trim().toLocaleLowerCase('es') : '';
                        var visible = 0;
                        mockupCards.forEach(function(card) {
                            var matches = (!artwork || card.getAttribute('data-mockup-artwork') === artwork)
                                && (!series || card.getAttribute('data-mockup-series') === series)
                                && (!query || String(card.getAttribute('data-mockup-search-text') || '').indexOf(query) !== -1);
                            card.hidden = !matches;
                            if (matches) visible += 1;
                        });
                        if (mockupEmpty) mockupEmpty.hidden = visible !== 0;
                    }

                    [mockupArtworkFilter, mockupSeriesFilter, mockupSearch].forEach(function(control) {
                        if (control) control.addEventListener('input', filterMockupCarousel);
                    });
                    mockupCards.forEach(function(card) {
                        card.addEventListener('click', function() { insertMockup(card); });
                        card.addEventListener('dragstart', function(event) {
                            event.dataTransfer.effectAllowed = 'copy';
                            event.dataTransfer.setData('application/x-studio-note-mockup', card.getAttribute('data-mockup-source'));
                        });
                    });
                    if (form) {
                        form.querySelectorAll('.studio-note-editor-title, .studio-seo-field input, .studio-seo-field textarea').forEach(function(field) {
                            field.addEventListener('input', function() {
                                clientDirty = true;
                                updatePublishActionMode();
                            });
                        });
                    }

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

                    var imageUploadQueue = Promise.resolve();
                    var pendingImageUploads = 0;

                    function persistSpanishImage(file) {
                        var data = new FormData();
                        data.append('csrf', formCsrf.value);
                        data.append('note_id', String(noteId));
                        data.append('image', file);
                        var controller = typeof AbortController === 'function'
                            ? new AbortController()
                            : null;
                        var timeout = window.setTimeout(function() {
                            if (controller) controller.abort();
                        }, 30000);
                        return fetch('studio_note_inline_upload.php', {
                            method: 'POST',
                            body: data,
                            headers: {'Accept': 'application/json'},
                            signal: controller ? controller.signal : undefined
                        })
                            .then(function(response) { return response.json(); })
                            .then(function(result) {
                                if (!result.ok || !result.url) {
                                    throw new Error(result.error || 'No se pudo guardar la imagen.');
                                }
                                return result.url;
                            })
                            .catch(function(error) {
                                if (error && error.name === 'AbortError') {
                                    throw new Error('La carga de la imagen tardó demasiado. Volvé a intentarlo.');
                                }
                                throw error;
                            })
                            .finally(function() {
                                window.clearTimeout(timeout);
                            });
                    }

                    function insertSpanishImages(files) {
                        files = Array.prototype.slice.call(files || []).filter(function(file) {
                            return file && (
                                String(file.type || '').indexOf('image/') === 0
                                || file.type === 'application/octet-stream'
                                || file.type === ''
                            );
                        });
                        if (!files.length || !formCsrf || !noteId) return imageUploadQueue;
                        var range = quillEs.getSelection(true) || {index: Math.max(0, quillEs.getLength() - 1)};
                        var insertAt = range.index;
                        pendingImageUploads += files.length;
                        quillEs.enable(false);
                        imageUploadQueue = imageUploadQueue.then(function() {
                            return files.reduce(function(chain, file) {
                                return chain.then(function() {
                                    return persistSpanishImage(file).then(function(url) {
                                        quillEs.insertEmbed(insertAt, 'image', url, 'user');
                                        insertAt += 1;
                                    });
                                });
                            }, Promise.resolve());
                        }).then(function() {
                            quillEs.setSelection(insertAt, 0, 'silent');
                            quillEs.root.querySelectorAll('img').forEach(prepareImage);
                        }).catch(function(error) {
                            window.alert(error.message || 'No se pudo guardar la imagen.');
                        }).finally(function() {
                            pendingImageUploads = Math.max(0, pendingImageUploads - files.length);
                            quillEs.enable(true);
                            quillEs.focus();
                        });
                        return imageUploadQueue;
                    }

                    function uploadSpanishImage() {
                        var input = document.createElement('input');
                        input.type = 'file';
                        input.accept = 'image/jpeg,image/png,image/webp';
                        input.addEventListener('change', function() {
                            var file = input.files && input.files[0];
                            if (file) insertSpanishImages([file]);
                        });
                        input.click();
                    }

                    var spanishToolbar = quillEs.getModule('toolbar');
                    if (spanishToolbar) spanishToolbar.addHandler('image', uploadSpanishImage);
                    var englishToolbar = quillEn.getModule('toolbar');
                    if (englishToolbar) {
                        englishToolbar.addHandler('image', function() {
                            window.alert('Las imágenes pertenecen al original español y se reflejan automáticamente en inglés.');
                        });
                    }
                    quillEs.root.addEventListener('drop', function(event) {
                        var sourceKey = event.dataTransfer
                            ? event.dataTransfer.getData('application/x-studio-note-mockup')
                            : '';
                        if (sourceKey) {
                            event.preventDefault();
                            var sourceCard = mockupCards.find(function(card) {
                                return card.getAttribute('data-mockup-source') === sourceKey;
                            });
                            insertMockup(sourceCard);
                            return;
                        }
                        var files = event.dataTransfer ? event.dataTransfer.files : null;
                        if (!files || !files.length) return;
                        event.preventDefault();
                        insertSpanishImages(files);
                    });
                    quillEs.root.addEventListener('paste', function(event) {
                        var files = event.clipboardData ? event.clipboardData.files : null;
                        if (!files || !files.length) return;
                        event.preventDefault();
                        insertSpanishImages(files);
                    });

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
                                syncImageMetadata();
                                return;
                            }
                            clientDirty = true;
                            quill.update('user');
                            refreshImageTools();
                            updatePublishActionMode();
                        });
                    }

                    quill.root.addEventListener('click', function(e) {
                        if (e.target && e.target.tagName === 'IMG') {
                            selectImage(e.target);
                        } else selectImage(null);
                    });
                    quillEs.on('text-change', function() {
                        clientDirty = true;
                        window.requestAnimationFrame(syncImageMetadata);
                        window.requestAnimationFrame(updatePublishActionMode);
                    });
                    quillEn.on('text-change', function() {
                        clientDirty = true;
                        window.requestAnimationFrame(updatePublishActionMode);
                    });
                    if (syncImageMetadata()) {
                        clientDirty = true;
                        updatePublishActionMode();
                    }
                    
                    var queuedSubmitAllowed = false;
                    if (form) {
                        form.addEventListener('submit', function(event) {
                            if (!queuedSubmitAllowed && pendingImageUploads > 0) {
                                event.preventDefault();
                                var submitter = event.submitter || null;
                                if (publicationState) publicationState.textContent = 'Terminando la carga de imágenes';
                                imageUploadQueue.then(function() {
                                    queuedSubmitAllowed = true;
                                    form.requestSubmit(submitter);
                                }).catch(function(error) {
                                    if (publicationState) publicationState.textContent = '';
                                    window.alert(error.message || 'No se pudo completar la carga pendiente.');
                                });
                                return;
                            }
                            queuedSubmitAllowed = false;
                            syncImageMetadata();
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
                                    ? 'Actualizando inglés'
                                    : result.job.status;
                                if (result.job.status === 'completed') {
                                    window.location.reload();
                                    return;
                                }
                                if (['failed', 'enqueue_failed'].indexOf(result.job.status) !== -1) {
                                    if (publicationState) publicationState.textContent = result.job.error || 'La actualización inglesa falló';
                                    setEnglishAdaptationBusy(false);
                                    return;
                                }
                                window.setTimeout(pollEditorialJob, 3000);
                            })
                            .catch(function(error) {
                                if (publicationState) publicationState.textContent = error.message || 'Actualización no disponible';
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
