<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function sheet_image_url(?string $file): string
{
    $file = trim((string)$file);
    if ($file === '') {
        return '';
    }
    $base = basename(str_replace('\\', '/', $file));
    if (is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $base)) {
        return 'media.php?file=' . urlencode($base);
    }
    if (is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $base)) {
        return 'uploads/' . rawurlencode($base);
    }
    return '';
}

function sheet_text_field(string $name, string $label, string $value, string $example): void
{
    echo '<div class="field">';
    echo '<label for="' . h($name) . '">' . h($label) . '</label>';
    echo '<input id="' . h($name) . '" type="text" name="' . h($name) . '" value="' . h($value) . '">';
    echo '<small>Ejemplo: ' . h($example) . '</small>';
    echo '</div>';
}

function sheet_textarea(string $name, string $label, string $value, string $example, int $rows = 4): void
{
    echo '<div class="field wide">';
    echo '<label for="' . h($name) . '">' . h($label) . '</label>';
    echo '<textarea id="' . h($name) . '" name="' . h($name) . '" rows="' . $rows . '">' . h($value) . '</textarea>';
    echo '<small>Ejemplo: ' . h($example) . '</small>';
    echo '</div>';
}

$artworkId = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
if ($artworkId <= 0) {
    header('Location: artwork_sheets.php');
    exit;
}

$service = new ArtworkSheetService();
$notice = (string)($_SESSION['artwork_sheet_notice'] ?? '');
$error = (string)($_SESSION['artwork_sheet_error'] ?? '');
unset($_SESSION['artwork_sheet_notice'], $_SESSION['artwork_sheet_error']);

try {
    $artwork = $service->artwork($artworkId, (int)$user['id']);
    $sheet = $service->sheetForArtwork($artworkId, (int)$user['id']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'delete_artwork_sheet') {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT id FROM artworks WHERE user_id = ? AND id = ?');
                $stmt->execute([(int)$user['id'], $artworkId]);
                if (!$stmt->fetchColumn()) {
                    throw new RuntimeException('No se encontró una obra propia para eliminar.');
                }

                $stmt = $pdo->prepare('SELECT id, canonical_artwork_id, related_artwork_ids FROM artwork_sheets WHERE user_id = ?');
                $stmt->execute([(int)$user['id']]);
                foreach ($stmt->fetchAll() as $sheetRow) {
                    $sheetId = (int)$sheetRow['id'];
                    $canonicalId = (int)$sheetRow['canonical_artwork_id'];
                    $decoded = json_decode((string)($sheetRow['related_artwork_ids'] ?? ''), true);
                    $relatedIds = is_array($decoded) ? $decoded : [];
                    $relatedIds = array_values(array_diff(array_unique(array_filter(array_map('intval', $relatedIds))), [$artworkId]));

                    if ($canonicalId === $artworkId) {
                        $pdo->prepare('DELETE FROM mockup_sheets WHERE user_id = ? AND artwork_sheet_id = ?')->execute([(int)$user['id'], $sheetId]);
                        $pdo->prepare('DELETE FROM artwork_sheets WHERE id = ? AND user_id = ?')->execute([$sheetId, (int)$user['id']]);
                    } else {
                        $pdo->prepare('UPDATE artwork_sheets SET related_artwork_ids = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                            ->execute([json_encode($relatedIds, JSON_UNESCAPED_SLASHES), date('c'), $sheetId, (int)$user['id']]);
                    }
                }

                $pdo->prepare('DELETE FROM mockup_sheets WHERE user_id = ? AND artwork_id = ?')->execute([(int)$user['id'], $artworkId]);
                $pdo->prepare('DELETE FROM artworks WHERE user_id = ? AND id = ?')->execute([(int)$user['id'], $artworkId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $_SESSION['artwork_sheets_notice'] = 'Ficha y obra #' . $artworkId . ' eliminadas. Los archivos de imagen no se borraron del disco.';
            header('Location: artwork_sheets.php');
            exit;
        } elseif ($action === 'detach_related_artwork') {
            $detachedId = max(0, (int)($_POST['detached_artwork_id'] ?? 0));
            if ($detachedId <= 0 || $detachedId === $artworkId) {
                throw new RuntimeException('Elegí una obra acoplada para separar.');
            }

            $pdo = Database::connection();
            $decoded = json_decode((string)($sheet['related_artwork_ids'] ?? ''), true);
            $relatedIds = is_array($decoded) ? $decoded : preg_split('/[,\s]+/', (string)($sheet['related_artwork_ids'] ?? ''));
            $relatedIds = array_values(array_unique(array_filter(array_map('intval', (array)$relatedIds))));
            $relatedIds = array_values(array_diff($relatedIds, [$detachedId]));
            if (!in_array($artworkId, $relatedIds, true)) {
                array_unshift($relatedIds, $artworkId);
            }

            $service->artwork($detachedId, (int)$user['id']);
            $service->sheetForArtwork($detachedId, (int)$user['id']);
            $pdo->prepare('UPDATE artwork_sheets SET related_artwork_ids = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                ->execute([json_encode($relatedIds, JSON_UNESCAPED_SLASHES), date('c'), (int)$sheet['id'], (int)$user['id']]);
            $_SESSION['artwork_sheet_notice'] = 'Obra #' . $detachedId . ' desacoplada. Ahora tiene su ficha independiente.';
        } elseif ($action === 'detach_all_related_artworks') {
            $pdo = Database::connection();
            $decoded = json_decode((string)($sheet['related_artwork_ids'] ?? ''), true);
            $relatedIds = is_array($decoded) ? $decoded : preg_split('/[,\s]+/', (string)($sheet['related_artwork_ids'] ?? ''));
            $relatedIds = array_values(array_unique(array_filter(array_map('intval', (array)$relatedIds))));
            $detachedIds = array_values(array_diff($relatedIds, [$artworkId]));

            foreach ($detachedIds as $detachedId) {
                $service->artwork((int)$detachedId, (int)$user['id']);
                $service->sheetForArtwork((int)$detachedId, (int)$user['id']);
            }

            $pdo->prepare('UPDATE artwork_sheets SET related_artwork_ids = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                ->execute([json_encode([$artworkId], JSON_UNESCAPED_SLASHES), date('c'), (int)$sheet['id'], (int)$user['id']]);
            $_SESSION['artwork_sheet_notice'] = $detachedIds
                ? 'Obras desacopladas: ' . implode(', ', $detachedIds) . '.'
                : 'Esta ficha no tenía obras acopladas para separar.';
        } elseif ($action === 'save_artwork_sheet') {
            $service->saveArtworkSheet((int)$sheet['id'], (int)$user['id'], $_POST);
            $_SESSION['artwork_sheet_notice'] = 'Ficha de obra guardada.';
        } elseif ($action === 'generate_artwork_sheet') {
            $service->saveArtworkSheet((int)$sheet['id'], (int)$user['id'], $_POST);
            $service->generateArtworkSheet((int)$sheet['id'], (int)$user['id']);
            $_SESSION['artwork_sheet_notice'] = 'Ficha de obra generada desde la imagen fuente.';
        } elseif ($action === 'save_mockup_sheet') {
            $service->saveMockupSheet((int)($_POST['mockup_sheet_id'] ?? 0), (int)$user['id'], $_POST);
            $_SESSION['artwork_sheet_notice'] = 'Ficha de mockup guardada.';
        } elseif ($action === 'generate_mockup_sheet') {
            $service->generateMockupSheet(
                (int)$sheet['id'],
                (int)($_POST['mockup_artwork_id'] ?? $artworkId),
                (string)($_POST['mockup_file'] ?? ''),
                (int)$user['id'],
                trim((string)($_POST['user_notes'] ?? ''))
            );
            $_SESSION['artwork_sheet_notice'] = 'Ficha de mockup generada desde su imagen.';
        }

        header('Location: artwork_sheet.php?id=' . urlencode((string)$artworkId));
        exit;
    }

    $mockups = $service->associatedMockups($sheet, (int)$user['id']);
} catch (Throwable $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['artwork_sheet_error'] = $e->getMessage();
        header('Location: artwork_sheet.php?id=' . urlencode((string)$artworkId));
        exit;
    }
    $error = $e->getMessage();
    $artwork = [];
    $sheet = [];
    $mockups = [];
}

$sourceImage = sheet_image_url((string)($sheet['source_image_file'] ?? ($artwork['root_file'] ?? $artwork['main_file'] ?? '')));
$relatedArtworkIds = [];
$relatedArtworks = [];
if ($sheet) {
    $decoded = json_decode((string)($sheet['related_artwork_ids'] ?? ''), true);
    $relatedArtworkIds = is_array($decoded) ? $decoded : preg_split('/[,\s]+/', (string)($sheet['related_artwork_ids'] ?? ''));
    $relatedArtworkIds = array_values(array_unique(array_filter(array_map('intval', (array)$relatedArtworkIds))));
    if (!in_array($artworkId, $relatedArtworkIds, true)) {
        array_unshift($relatedArtworkIds, $artworkId);
    }
    if ($relatedArtworkIds) {
        $pdo = Database::connection();
        $placeholders = implode(',', array_fill(0, count($relatedArtworkIds), '?'));
        $stmt = $pdo->prepare("SELECT id, final_title, root_file, main_file FROM artworks WHERE user_id = ? AND id IN ({$placeholders})");
        $stmt->execute(array_merge([(int)$user['id']], $relatedArtworkIds));
        foreach ($stmt->fetchAll() as $row) {
            $relatedArtworks[(int)$row['id']] = $row;
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ficha de obra - Mockup Lab</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .sheet-layout { display:grid; grid-template-columns:320px minmax(0, 1fr); gap:20px; align-items:start; }
        .panel-box { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:18px; }
        .source-image { width:100%; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface-soft); display:block; }
        .field { display:grid; gap:6px; margin-bottom:14px; }
        .field label { font-size:10px; text-transform:uppercase; color:var(--muted); font-weight:700; letter-spacing:.05em; }
        .field small { color:var(--muted); font-size:11px; line-height:1.35; }
        input[type="text"], select, textarea { width:100%; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface-soft); color:var(--ink); padding:10px; }
        textarea { resize:vertical; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .wide { grid-column:1 / -1; }
        .actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .topbar-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:flex-end; }
        .inline-action-form { margin:0; }
        .danger-action { background:transparent !important; color:#8f2f2f !important; border:1px solid rgba(143,47,47,.35) !important; }
        .related-strip { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 16px; align-items:center; }
        .related-chip { display:inline-flex; gap:8px; align-items:center; border:1px solid var(--line); border-radius:999px; background:var(--surface); padding:6px 8px 6px 10px; box-shadow:var(--shadow); }
        .related-chip.is-current { background:var(--surface-soft); color:var(--muted); }
        .chip-title { max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:12px; }
        .chip-remove { border:0; background:transparent; color:#8f2f2f; cursor:pointer; font-size:14px; line-height:1; padding:1px 3px; }
        .mockup-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:16px; }
        .mockup-card { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; }
        .mockup-card img { width:100%; aspect-ratio:4 / 3; object-fit:cover; background:var(--surface-soft); border-bottom:1px solid var(--line); }
        .mockup-card-body { padding:14px; }
        .meta { color:var(--muted); font-size:12px; line-height:1.45; }
        @media (max-width:1100px) { .sheet-layout, .form-grid { grid-template-columns:1fr; } .wide { grid-column:auto; } }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <div class="alert-strip">Ficha completa de obra y fichas individuales de mockups.</div>
        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Ficha de obra</h1>
                    <p>Obra #<?= h($artworkId) ?>. Podés escribir notas en español; la metadata generada sale en inglés.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="artwork_sheets.php">Todas las fichas</a>
                    <a class="button-link secondary" href="artwork.php?id=<?= h($artworkId) ?>">Ver obra</a>
                    <form class="inline-action-form" method="post">
                        <input type="hidden" name="id" value="<?= h($artworkId) ?>">
                        <button class="button-link secondary danger-action" type="submit" name="action" value="delete_artwork_sheet" onclick="return confirm('Esto eliminará la ficha y el registro de obra #<?= h($artworkId) ?> de la base. No borra archivos de imagen del disco. ¿Continuar?');">Eliminar</button>
                    </form>
                </div>
            </div>

            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>

            <?php if ($sheet): ?>
                <div class="related-strip" aria-label="Obras acopladas a esta ficha">
                    <span class="meta">Obras acopladas:</span>
                    <?php foreach ($relatedArtworkIds as $relatedId): ?>
                        <?php
                        $related = $relatedArtworks[$relatedId] ?? [];
                        $relatedTitle = trim((string)($related['final_title'] ?? ''));
                        $relatedLabel = '#' . $relatedId . ($relatedTitle !== '' ? ' · ' . $relatedTitle : '');
                        ?>
                        <?php if ($relatedId === $artworkId): ?>
                            <span class="related-chip is-current"><strong>#<?= h($relatedId) ?></strong><span class="chip-title">madre</span></span>
                        <?php else: ?>
                            <form class="related-chip" method="post">
                                <input type="hidden" name="id" value="<?= h($artworkId) ?>">
                                <input type="hidden" name="detached_artwork_id" value="<?= h($relatedId) ?>">
                                <span class="chip-title" title="<?= h($relatedLabel) ?>"><?= h($relatedLabel) ?></span>
                                <button class="chip-remove" type="submit" name="action" value="detach_related_artwork" title="Desacoplar obra #<?= h($relatedId) ?>" onclick="return confirm('Separar obra #<?= h($relatedId) ?> de esta ficha?');">×</button>
                            </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (count($relatedArtworkIds) > 1): ?>
                        <form class="inline-action-form" method="post">
                            <input type="hidden" name="id" value="<?= h($artworkId) ?>">
                            <button class="button-link secondary danger-action" type="submit" name="action" value="detach_all_related_artworks" onclick="return confirm('Desacoplar todas las obras y dejar solo #<?= h($artworkId) ?> como ficha madre?');">Desacoplar todas</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="sheet-layout">
                    <aside class="panel-box">
                        <?php if ($sourceImage !== ''): ?>
                            <img class="source-image" src="<?= h($sourceImage) ?>" alt="<?= h($sheet['title'] ?: 'Obra fuente') ?>">
                        <?php else: ?>
                            <div class="source-image" style="aspect-ratio:4 / 3; display:grid; place-items:center; color:var(--muted);">Sin imagen fuente</div>
                        <?php endif; ?>
                        <p class="meta">
                            Imagen fuente: <code><?= h($sheet['source_image_file'] ?: ($artwork['root_file'] ?? '')) ?></code><br>
                            Obra principal: <?= h($artwork['final_title'] ?? 'Sin título') ?>
                        </p>
                    </aside>

                    <section class="panel-box">
                        <h2>Datos curatoriales de la obra</h2>
                        <form method="post">
                            <input type="hidden" name="id" value="<?= h($artworkId) ?>">
                            <div class="form-grid">
                                <?php sheet_text_field('related_artwork_ids', 'IDs relacionados', (string)$sheet['related_artwork_ids'], '123, 124, 178. Útil para unir pruebas de la misma obra con otros IDs.'); ?>
                                <?php sheet_text_field('source_image_file', 'Imagen fuente', (string)$sheet['source_image_file'], 'base_artwork_gemini_job_...png o el archivo raíz que querés usar.'); ?>
                                <?php sheet_text_field('title', 'Título', (string)$sheet['title'], 'Blue Architecture of Silence'); ?>
                                <?php sheet_text_field('subtitle', 'Subtítulo', (string)$sheet['subtitle'], 'A spatial study of line, pause, and chromatic tension'); ?>
                                <?php sheet_textarea('user_notes', 'Notas curatoriales del usuario', (string)$sheet['user_notes'], 'Podés escribir notas en español; la generación las convertirá a metadata final en inglés.', 5); ?>
                                <?php sheet_textarea('description', 'Descripción', (string)$sheet['description'], 'A 2 to 4 paragraph curatorial description of the artwork itself, not the mockup.', 7); ?>
                                <?php sheet_textarea('short_description', 'Descripción breve', (string)$sheet['short_description'], 'A concise 140 to 220 character summary for cards, catalogs, or previews.', 3); ?>
                                <?php sheet_textarea('keywords', 'Keywords', (string)$sheet['keywords'], 'deep blue, contemporary abstraction, white linework, red accents', 3); ?>
                                <?php sheet_textarea('tags', 'Tags', (string)$sheet['tags'], 'abstract, blue, contemporary-art, red-accents', 3); ?>
                                <?php sheet_textarea('alt_text', 'Alt text', (string)$sheet['alt_text'], 'Abstract blue painting with fine white lines and vivid red accents across the surface.', 3); ?>
                                <?php sheet_textarea('caption', 'Caption', (string)$sheet['caption'], 'Artwork title, year, medium, and a short contextual note if needed.', 3); ?>
                                <div class="field">
                                    <label for="status">Estado</label>
                                    <select id="status" name="status">
                                        <?php foreach (['draft' => 'Borrador', 'reviewed' => 'Revisada', 'published' => 'Publicable'] as $value => $label): ?>
                                            <option value="<?= h($value) ?>" <?= (string)$sheet['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small>Ejemplo: dejá “Revisada” cuando ya corregiste la curaduría.</small>
                                </div>
                            </div>
                            <div class="actions">
                                <button class="button-link" type="submit" name="action" value="save_artwork_sheet">Guardar ficha</button>
                                <button class="button-link secondary" type="submit" name="action" value="generate_artwork_sheet">Generar desde imagen</button>
                            </div>
                        </form>
                    </section>
                </div>

                <section style="margin-top:24px;">
                    <div class="workspace-header" style="padding:0;">
                        <div>
                            <h2>Fichas de mockups</h2>
                            <p>Se detectaron <?= h(count($mockups)) ?> mockups asociados a esta obra o a sus IDs relacionados.</p>
                        </div>
                    </div>
                    <div class="mockup-grid">
                        <?php foreach ($mockups as $mockup): ?>
                            <?php
                            $mockupSheet = (array)($mockup['sheet'] ?? []);
                            $mockupFile = (string)($mockup['mockup_file'] ?? '');
                            $mockupImage = sheet_image_url($mockupFile);
                            ?>
                            <article class="mockup-card">
                                <?php if ($mockupImage !== ''): ?>
                                    <img src="<?= h($mockupImage) ?>" alt="<?= h($mockupSheet['alt_text'] ?: 'Mockup de obra') ?>">
                                <?php endif; ?>
                                <div class="mockup-card-body">
                                    <p class="meta">Mockup: <code><?= h($mockupFile) ?></code></p>
                                    <form method="post">
                                        <input type="hidden" name="id" value="<?= h($artworkId) ?>">
                                        <input type="hidden" name="mockup_sheet_id" value="<?= h($mockupSheet['id'] ?? 0) ?>">
                                        <input type="hidden" name="mockup_artwork_id" value="<?= h($mockup['artwork_id'] ?? $artworkId) ?>">
                                        <input type="hidden" name="mockup_file" value="<?= h($mockupFile) ?>">
                                        <?php sheet_text_field('title', 'Título del mockup', (string)($mockupSheet['title'] ?? ''), 'Blue abstract artwork in an overhead living room mockup'); ?>
                                        <?php sheet_textarea('user_notes', 'Notas para este mockup', (string)($mockupSheet['user_notes'] ?? ''), 'Podés escribir notas en español; la generación las convertirá a metadata final en inglés.', 3); ?>
                                        <?php sheet_textarea('description', 'Descripción del mockup', (string)($mockupSheet['description'] ?? ''), 'A specific English description of the generated image and its visual context.', 4); ?>
                                        <?php sheet_textarea('keywords', 'Keywords', (string)($mockupSheet['keywords'] ?? ''), 'artwork mockup, contemporary living room, overhead view, blue painting', 2); ?>
                                        <?php sheet_textarea('tags', 'Tags', (string)($mockupSheet['tags'] ?? ''), 'mockup, interior-design, aerial-view, artwork-context', 2); ?>
                                        <?php sheet_textarea('alt_text', 'Alt text', (string)($mockupSheet['alt_text'] ?? ''), 'Mockup of a blue abstract painting placed on the floor of a contemporary interior.', 2); ?>
                                        <?php sheet_textarea('caption', 'Caption', (string)($mockupSheet['caption'] ?? ''), 'Contextual interior view of the artwork.', 2); ?>
                                        <div class="field">
                                            <label for="status_<?= h($mockupSheet['id'] ?? 0) ?>">Estado</label>
                                            <select id="status_<?= h($mockupSheet['id'] ?? 0) ?>" name="status">
                                                <?php foreach (['draft' => 'Borrador', 'reviewed' => 'Revisada', 'published' => 'Publicable'] as $value => $label): ?>
                                                    <option value="<?= h($value) ?>" <?= (string)($mockupSheet['status'] ?? 'draft') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small>Ejemplo: “Publicable” cuando el texto está listo para tienda o portfolio.</small>
                                        </div>
                                        <div class="actions">
                                            <button class="button-link secondary" type="submit" name="action" value="save_mockup_sheet">Guardar</button>
                                            <button class="button-link" type="submit" name="action" value="generate_mockup_sheet">Generar desde mockup</button>
                                        </div>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
