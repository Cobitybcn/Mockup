<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();
$userId = (int)$user['id'];

$notice = '';
$error = '';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');
        
        if ($action === 'create_draft') {
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') $title = 'Nueva Nota de Estudio';
            $now = date('c');
            $payload = [
                'channels' => ['website_blog'],
                'mockup_ids' => [],
                'channel_status' => ['website_blog' => 'draft']
            ];
            $stmt = $pdo->prepare('INSERT INTO social_campaigns (user_id, campaign_type, title, objective, source_type, source_id, source_label, status, payload_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$userId, 'website_blog', $title, '', 'custom', '', 'Custom Entry', 'draft', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now, $now]);
            $newId = $pdo->lastInsertId();
            $_SESSION['wsn_notice'] = 'Borrador creado correctamente.';
            header('Location: website_studio_notes.php?draft=' . $newId);
            exit;
        }
        
        if ($action === 'save_draft' || $action === 'publish_draft') {
            $id = max(0, (int)($_POST['draft_id'] ?? 0));
            $title = trim((string)($_POST['title'] ?? ''));
            $objective = trim((string)($_POST['objective'] ?? ''));
            if ($title === '') throw new RuntimeException('El título es obligatorio.');
            
            $stmt = $pdo->prepare('SELECT * FROM social_campaigns WHERE id=? AND user_id=? LIMIT 1');
            $stmt->execute([$id, $userId]);
            $draft = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$draft) throw new RuntimeException('Borrador no encontrado.');
            
            $status = $action === 'publish_draft' ? 'published' : 'draft';
            $payload = json_decode((string)$draft['payload_json'], true);
            if (!is_array($payload)) $payload = [];
            $payload['channel_status']['website_blog'] = $status;
            
            $stmt = $pdo->prepare('UPDATE social_campaigns SET title=?, objective=?, status=?, payload_json=?, updated_at=? WHERE id=? AND user_id=?');
            $stmt->execute([$title, $objective, $status, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), date('c'), $id, $userId]);
            
            $_SESSION['wsn_notice'] = $action === 'publish_draft' ? 'Nota de estudio publicada con éxito.' : 'Borrador guardado con éxito.';
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
function wsn_campaign_mockups(PDO $pdo, int $userId, array $mockupIds): array
{
    $mockupIds = array_values(array_unique(array_filter(array_map('intval', $mockupIds))));
    if (!$mockupIds) return [];
    $marks = implode(',', array_fill(0, count($mockupIds), '?'));
    $stmt = $pdo->prepare("
        SELECT m.id,m.mockup_file,m.context_id,a.final_title AS artwork_title,s.title AS series_title
        FROM mockups m
        LEFT JOIN artworks a ON a.id=m.source_artwork_id
        LEFT JOIN artwork_series s ON s.id=m.series_id AND s.user_id=m.user_id
        WHERE m.user_id=? AND m.id IN ($marks)
    ");
    $stmt->execute(array_merge([$userId], $mockupIds));
    $found = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $found[(int)$row['id']] = $row;
    $ordered = [];
    foreach ($mockupIds as $id) if (isset($found[$id])) $ordered[] = $found[$id];
    return $ordered;
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
$openMockups = $openDraft ? wsn_campaign_mockups($pdo, $userId, (array)($openPayload['mockup_ids'] ?? [])) : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Website Studio Notes - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
    <style>
        .studio-notes-page { padding: 28px 24px 80px; }
        .studio-note-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
        .studio-note-editor-shell {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(320px, .8fr);
            gap: 18px;
        }
        .studio-note-textarea {
            width: 100%;
            min-height: 220px;
            resize: vertical;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--surface);
            padding: 14px;
            color: var(--ink);
            font: inherit;
            line-height: 1.55;
        }
        @media (max-width: 860px) {
            .studio-notes-page { padding: 18px 14px 60px; }
            .studio-note-editor-shell { grid-template-columns: 1fr; }
        }
    </style>
    <!-- Quill WYSIWYG Editor Assets -->
    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
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
                    <h1>Website Studio Notes</h1>
                    <p>Prepare essays, reflections and study notes for the artist website.</p>
                </div>
                <a class="button-link secondary" href="../artist-site/blog" target="_blank" rel="noopener">Open Website Blog</a>
            </div>

            <?php if ($notice): ?>
                <div class="notice" style="background:#e6f6ec; color:#116639; padding:12px; border-radius:6px; border:1px solid #c7ebdb; margin-bottom:20px;"><?= wsn_h($notice) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="notice error" style="background:#ffebe9; color:#cc2511; padding:12px; border-radius:6px; border:1px solid #ffd3d0; margin-bottom:20px;"><?= wsn_h($error) ?></div>
            <?php endif; ?>



            <!-- New dedicated Create Essay section -->
            <section class="catalog-panel catalog-panel--compact" style="background: var(--surface); border: 1px dashed var(--line); margin-bottom: 24px; padding: 20px; border-radius: 8px;">
                <h3 style="margin-top:0; margin-bottom:8px; font-size:16px;">Escribir un nuevo ensayo o reflexión</h3>
                <form method="post" style="display: flex; gap: 12px; align-items: center; max-width: 800px; flex-wrap: wrap;">
                    <input type="hidden" name="action" value="create_draft">
                    <input type="text" name="title" placeholder="Introduce el título de tu nuevo ensayo..." required style="flex: 1; min-width: 260px; padding: 10px 14px; border: 1px solid var(--line); border-radius: 6px; font-size: 15px; background: var(--background); color: var(--ink);">
                    <button class="button" type="submit" style="padding: 10px 20px;">Crear Ensayo</button>
                </form>
            </section>

            <?php if ($openDraft): ?>
                <section class="catalog-panel catalog-panel--compact">
                    <div class="detail-heading">
                        <div>
                            <h2><?= wsn_h($openDraft['title']) ?></h2>
                            <p>Modifying note draft.</p>
                        </div>
                        <a class="button-link secondary" href="website_studio_notes.php">Close</a>
                    </div>
                    <div class="studio-note-editor-shell">
                        <div>
                            <form class="studio-note-form" method="post">
                                <input type="hidden" name="draft_id" value="<?= (int)$openDraft['id'] ?>">
                                
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label class="field-label" for="studio-note-title" style="font-size: 14px; font-weight: bold;">Título del Ensayo</label>
                                    <input type="text" name="title" id="studio-note-title" value="<?= wsn_h($openDraft['title']) ?>" placeholder="Escribe el título de tu ensayo..." style="width:100%; padding:12px 16px; border:2px solid var(--line); border-radius:8px; font-size:22px; font-weight:bold; background:var(--surface); color:var(--ink); line-height: 1.4;">
                                </div>
                                
                                <label class="field-label" for="editor-container" style="font-size: 14px; font-weight: bold;">Contenido del Ensayo</label>
                                <div id="editor-container" style="height: 420px; background: white; border: 1px solid var(--line); border-radius: 6px 6px 0 0; color: black; font-family: sans-serif; font-size: 16px;"></div>
                                <input type="hidden" name="objective" id="objective-input">
                                <small style="display:block; margin-top:6px; color:var(--gray-text); font-style:italic;">Tip: Haz clic sobre cualquier imagen en el editor para cambiar su tamaño (25%, 50%, 75% o 100%).</small>
                                
                                <div class="studio-note-actions" style="margin-top: 18px; display:flex; justify-content: space-between; align-items:center;">
                                    <div style="display:flex; gap:10px;">
                                        <button class="button-link primary" name="action" value="publish_draft" type="submit">Publicar en la Web</button>
                                        <button class="button-link secondary" name="action" value="save_draft" type="submit">Guardar Borrador</button>
                                    </div>
                                    <button class="button-link danger" name="action" value="delete_draft" type="submit" onclick="return confirm('¿Estás seguro de que deseas eliminar este ensayo?')" style="background:#ffebe9; color:#cc2511; border-color:#ffd3d0;">Eliminar</button>
                                </div>
                            </form>
                        </div>
                        <div>
                            <div class="copy-grid">
                                <article class="copy-card"><h3>Source</h3><p><?= wsn_h($openDraft['source_label']) ?></p></article>
                                <article class="copy-card"><h3>Mockups</h3><p><?= count($openMockups) ?> selected</p></article>
                            </div>
                            <?php if ($openMockups): ?>
                                <div class="social-mockup-select-grid">
                                    <?php foreach ($openMockups as $mockup): ?>
                                        <article class="social-mockup-card">
                                            <img src="<?= wsn_h(wsn_media_url((string)$mockup['mockup_file'], 520)) ?>" alt="<?= wsn_h($mockup['artwork_title'] ?: Display::contextTitle((string)$mockup['context_id'])) ?>" loading="lazy">
                                            <strong><?= wsn_h(Display::contextTitle((string)$mockup['context_id'])) ?></strong>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">No mockups selected in this website draft.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="catalog-panel catalog-panel--compact">
                <div class="detail-heading">
                    <div>
                        <h2>Studio Notes Drafts</h2>
                        <p>Website material separated from Pinterest and Meta.</p>
                    </div>
                </div>
                <?php if (!$websiteDrafts): ?>
                    <div class="empty-state">No website drafts yet. Use the panel above to write your first essay.</div>
                <?php else: ?>
                    <div class="drafts-list" style="display: flex; flex-direction: column; gap: 16px;">
                        <?php foreach ($websiteDrafts as $draft): 
                            $payload = (array)$draft['_payload']; 
                            $mockupIds = array_values(array_filter(array_map('intval', (array)($payload['mockup_ids'] ?? []))));
                            
                            $thumbUrl = '';
                            if ($mockupIds) {
                                $stmt = $pdo->prepare("SELECT mockup_file FROM mockups WHERE id = ? LIMIT 1");
                                $stmt->execute([$mockupIds[0]]);
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
                            if ($snippet === '') {
                                $snippet = 'Sin contenido aún. Comienza a escribir en el editor.';
                            }
                        ?>
                            <article class="draft-item-card" style="display: flex; gap: 16px; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 16px; align-items: center; flex-wrap: wrap;">
                                <?php if ($thumbUrl !== ''): ?>
                                    <div class="draft-thumb-wrapper" style="width: 100px; height: 100px; border-radius: 6px; overflow: hidden; flex-shrink: 0; border: 1px solid var(--line); background:#f9f9f9; display:flex; align-items:center; justify-content:center;">
                                        <img src="<?= wsn_h($thumbUrl) ?>" alt="Thumb" style="max-width: 100%; max-height: 100%; object-fit: cover;">
                                    </div>
                                <?php else: ?>
                                    <div class="draft-thumb-wrapper" style="width: 100px; height: 100px; border-radius: 6px; overflow: hidden; flex-shrink: 0; border: 1px dashed var(--line); background:#fafafa; display: flex; align-items: center; justify-content: center; color: var(--gray-text); font-size: 11px; text-align:center; padding: 4px;">
                                        Sin imagen
                                    </div>
                                <?php endif; ?>
                                
                                <div class="draft-info" style="flex: 1; min-width: 280px;">
                                    <h3 style="margin: 0 0 6px 0; font-size: 18px; color: var(--ink);"><?= wsn_h($draft['title']) ?></h3>
                                    <p style="margin: 0 0 8px 0; font-size: 14px; color: var(--gray-text); line-height: 1.4; word-wrap: break-word; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;"><?= wsn_h($snippet) ?></p>
                                    <div style="display: flex; gap: 12px; align-items: center; font-size: 12px; color: var(--gray-text);">
                                        <span><strong>Origen:</strong> <?= wsn_h($draft['source_label']) ?></span>
                                        <span>•</span>
                                        <span class="status-pill status-<?= $draft['status'] === 'published' ? 'published' : 'draft' ?>" style="padding: 2px 8px; border-radius: 12px; font-size: 11px; text-transform: uppercase; font-weight: bold; background: <?= $draft['status'] === 'published' ? '#e6f6ec; color:#116639;' : '#f1f1f1; color:#555;' ?>"><?= wsn_h($draft['status']) ?></span>
                                    </div>
                                </div>
                                
                                <div class="draft-actions" style="flex-shrink: 0;">
                                    <a class="button-link secondary" href="website_studio_notes.php?draft=<?= (int)$draft['id'] ?>" style="padding: 8px 16px;">Editar Ensayo</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
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
                    
                    // Click en cualquier imagen dentro del editor para rotar su ancho
                    quill.root.addEventListener('click', function(e) {
                        if (e.target && e.target.tagName === 'IMG') {
                            var img = e.target;
                            var widths = ['25%', '50%', '75%', '100%'];
                            var currentWidth = img.style.width || '100%';
                            var cleanWidth = currentWidth;
                            if (widths.indexOf(cleanWidth) === -1) {
                                cleanWidth = '100%';
                            }
                            var nextIndex = (widths.indexOf(cleanWidth) + 1) % widths.length;
                            var newWidth = widths[nextIndex];
                            img.style.width = newWidth;
                            img.setAttribute('width', newWidth);
                        }
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
