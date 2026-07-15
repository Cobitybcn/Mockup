<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Services/ExternalMockupUploadService.php';

$user = Auth::requireUser();
$pdo = Database::connection();
ArtworkSeries::ensureSchema($pdo);
Auth::start();
$_SESSION['external_mockup_upload_csrf'] ??= bin2hex(random_bytes(32));

function emu_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function emu_media_url(string $file, int $width = 640): string
{
    return 'media.php?file=' . rawurlencode(basename($file)) . '&thumb=1&w=' . max(320, min(1000, $width));
}

$requestedArtworkId = max(0, (int)($_GET['id'] ?? 0));
$artworkStmt = $pdo->prepare('
    SELECT a.id, a.artwork_group_id, a.root_file, a.main_file, a.final_title,
           a.width, a.height, a.unit, a.series_id, a.updated_at,
           ag.canonical_artwork_id, ag.title AS group_title,
           s.title AS series_title
    FROM artworks a
    LEFT JOIN artwork_groups ag
      ON ag.id = a.artwork_group_id
     AND ag.user_id = a.user_id
     AND ag.status = :group_status
    LEFT JOIN artwork_series s
      ON s.id = a.series_id
     AND s.user_id = a.user_id
    WHERE a.user_id = :user_id
      AND a.status = :artwork_status
      AND a.root_file IS NOT NULL
      AND a.root_file != :empty_file
    ORDER BY a.updated_at DESC, a.id DESC
');
$artworkStmt->execute([
    'group_status' => 'active',
    'user_id' => (int)$user['id'],
    'artwork_status' => 'done',
    'empty_file' => '',
]);

$artworksByWork = [];
$requestedWorkKey = '';
foreach ($artworkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $artworkId = (int)$row['id'];
    $groupId = (int)($row['artwork_group_id'] ?? 0);
    $workKey = $groupId > 0 ? 'group-' . $groupId : 'artwork-' . $artworkId;
    if ($artworkId === $requestedArtworkId) {
        $requestedWorkKey = $workKey;
    }

    $canonicalId = (int)($row['canonical_artwork_id'] ?? 0);
    $isCanonical = $canonicalId <= 0 || $canonicalId === $artworkId;
    if (isset($artworksByWork[$workKey]) && !$isCanonical) {
        continue;
    }

    $title = trim((string)($row['group_title'] ?? ''));
    if ($title === '') {
        $title = trim((string)($row['final_title'] ?? ''));
    }
    if ($title === '') {
        $title = 'Obra #' . $artworkId;
    }

    $meta = [];
    $width = trim((string)($row['width'] ?? ''));
    $height = trim((string)($row['height'] ?? ''));
    if ($width !== '' && $height !== '') {
        $meta[] = $width . ' × ' . $height . ' ' . trim((string)($row['unit'] ?? 'cm'));
    }
    $seriesTitle = ArtworkSeries::display((string)($row['series_title'] ?? ''));
    if ($seriesTitle !== '') {
        $meta[] = $seriesTitle;
    }

    $file = basename((string)$row['root_file']);
    $artworksByWork[$workKey] = [
        'id' => $artworkId,
        'title' => $title,
        'meta' => $meta ? implode(' · ', $meta) : 'Obra de arte',
        'file' => $file,
        'image' => emu_media_url($file, 720),
        'search' => mb_strtolower(trim($title . ' ' . implode(' ', $meta))),
    ];
}

$artworks = array_values($artworksByWork);
usort($artworks, static fn (array $left, array $right): int => strnatcasecmp($left['title'], $right['title']));
$selectedArtworkId = $requestedWorkKey !== '' && isset($artworksByWork[$requestedWorkKey])
    ? (int)$artworksByWork[$requestedWorkKey]['id']
    : 0;

$uploadConfig = [
    'csrf' => (string)$_SESSION['external_mockup_upload_csrf'],
    'endpoint' => 'upload_external_mockup.php',
    'selectedArtworkId' => $selectedArtworkId,
    'maxFiles' => 80,
    'maxBytes' => ExternalMockupUploadService::MAX_FILE_BYTES,
    'concurrency' => 3,
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Importar Mockups - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="mockup_upload.css?v=1">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= emu_h($user['email']) ?></a></header>

        <div class="emu-page">
            <section class="emu-catalog" aria-labelledby="emu-catalog-title">
                <div class="emu-catalog-head">
                    <div>
                        <span class="emu-kicker">Archivo privado</span>
                        <h1 id="emu-catalog-title">Selecciona la obra</h1>
                        <p>Elige la obra a la que pertenecen los mockups de tu ordenador.</p>
                    </div>
                    <label class="emu-search">
                        <span class="sr-only">Buscar obra</span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="m16 16 4 4"></path></svg>
                        <input type="search" placeholder="Buscar por título o serie" data-artwork-search>
                    </label>
                </div>

                <div class="emu-rail-wrap">
                    <button class="emu-rail-arrow emu-rail-arrow--left" type="button" data-scroll-artworks="-1" aria-label="Ver obras anteriores">‹</button>
                    <div class="emu-artwork-rail" data-artwork-rail>
                        <?php foreach ($artworks as $artwork): ?>
                            <button
                                class="emu-artwork-card<?= (int)$artwork['id'] === $selectedArtworkId ? ' is-selected' : '' ?>"
                                type="button"
                                data-artwork-card
                                data-artwork-id="<?= (int)$artwork['id'] ?>"
                                data-artwork-title="<?= emu_h($artwork['title']) ?>"
                                data-artwork-meta="<?= emu_h($artwork['meta']) ?>"
                                data-artwork-image="<?= emu_h($artwork['image']) ?>"
                                data-artwork-search-value="<?= emu_h($artwork['search']) ?>"
                                aria-pressed="<?= (int)$artwork['id'] === $selectedArtworkId ? 'true' : 'false' ?>"
                            >
                                <img src="<?= emu_h($artwork['image']) ?>" alt="" loading="lazy" draggable="false">
                                <span class="emu-artwork-copy">
                                    <strong><?= emu_h($artwork['title']) ?></strong>
                                    <small><?= emu_h($artwork['meta']) ?></small>
                                </span>
                            </button>
                        <?php endforeach; ?>
                        <?php if (!$artworks): ?>
                            <div class="emu-no-artworks">No hay obras terminadas disponibles.</div>
                        <?php endif; ?>
                    </div>
                    <button class="emu-rail-arrow emu-rail-arrow--right" type="button" data-scroll-artworks="1" aria-label="Ver más obras">›</button>
                </div>
            </section>

            <section class="emu-board<?= $selectedArtworkId > 0 ? ' has-artwork' : '' ?>" data-upload-board aria-labelledby="emu-board-title">
                <header class="emu-board-head">
                    <div class="emu-board-title">
                        <span class="emu-board-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M12 16V4M7.5 8.5 12 4l4.5 4.5M5 14v5h14v-5"></path></svg>
                        </span>
                        <div>
                            <span>Biblioteca de mockups</span>
                            <h2 id="emu-board-title">Importar mockups</h2>
                        </div>
                    </div>
                    <span class="emu-board-count" data-board-count>0 archivos</span>
                </header>
                <p class="emu-board-intro">Carga únicamente mockups de la obra seleccionada. Podrás revisar y quitar archivos antes de guardarlos.</p>

                <div class="emu-board-empty" data-board-empty<?= $selectedArtworkId > 0 ? ' hidden' : '' ?>>
                    <span>01</span>
                    <strong>Selecciona una obra en el catálogo superior</strong>
                    <p>El tablero se abrirá automáticamente y quedará ligado a esa obra.</p>
                </div>

                <div class="emu-board-content" data-board-content<?= $selectedArtworkId > 0 ? '' : ' hidden' ?>>
                    <div class="emu-selected-artwork">
                        <img src="" alt="" data-selected-artwork-image>
                        <div>
                            <span>Obra seleccionada</span>
                            <strong data-selected-artwork-title></strong>
                            <small data-selected-artwork-meta></small>
                        </div>
                        <button type="button" data-change-artwork>Cambiar obra</button>
                    </div>

                    <div class="emu-dropzone" data-dropzone tabindex="0" role="button" aria-label="Elegir archivos de mockups">
                        <svg viewBox="0 0 48 48" aria-hidden="true"><rect x="6" y="8" width="36" height="31" rx="3"></rect><circle cx="17" cy="19" r="3"></circle><path d="m9 35 10-10 7 7 5-5 8 8M24 4v17M17.5 10.5 24 4l6.5 6.5"></path></svg>
                        <strong>Arrastra aquí tus mockups o una carpeta completa</strong>
                        <span>JPG, PNG o WebP · máximo 20 MB por imagen</span>
                        <div class="emu-picker-actions">
                            <button type="button" class="emu-picker-primary" data-pick-files>Elegir imágenes</button>
                            <button type="button" class="emu-picker-secondary" data-pick-folder>Elegir carpeta</button>
                        </div>
                    </div>

                    <input type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" multiple hidden data-file-input>
                    <input type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" multiple webkitdirectory directory hidden data-folder-input>

                    <div class="emu-upload-grid" data-upload-grid aria-live="polite"></div>
                    <div class="emu-upload-notice" data-upload-notice role="status" aria-live="polite"></div>
                    <div class="emu-upload-success" data-upload-success hidden>
                        <div><strong data-success-title>Mockups guardados</strong><span data-success-copy></span></div>
                        <div>
                            <a class="button-link secondary" href="mockups.php">Abrir Mockup Album</a>
                            <a class="button-link" href="#" data-view-artwork>Ver la obra</a>
                        </div>
                    </div>
                </div>
            </section>

            <footer class="emu-actions" data-upload-actions<?= $selectedArtworkId > 0 ? '' : ' hidden' ?>>
                <div class="emu-actions-summary">
                    <span class="emu-folder-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 6h6l2 2h10v11H3V6Z"></path></svg></span>
                    <div><strong data-upload-summary>Agrega los mockups de esta obra</strong><span data-upload-detail>Ningún archivo seleccionado</span></div>
                </div>
                <div class="emu-actions-buttons">
                    <button type="button" class="emu-clear" data-clear-files disabled>Quitar todos</button>
                    <button type="button" class="emu-confirm" data-upload-files disabled>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4M7.5 8.5 12 4l4.5 4.5M5 14v5h14v-5"></path></svg>
                        <span data-upload-label>Guardar en la obra</span>
                    </button>
                </div>
            </footer>
        </div>
    </main>
</div>
<script type="application/json" id="external-mockup-upload-config"><?= json_encode($uploadConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<script src="assets/vendor/sortablejs/Sortable.min.js?v=1.15.7"></script>
<script src="mockup_upload.js?v=1"></script>
</body>
</html>
