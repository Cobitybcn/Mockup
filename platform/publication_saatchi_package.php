<?php
declare(strict_types=1);

/**
 * Downloads everything needed to upload an artwork to Saatchi Art by hand:
 * the composition images at full resolution plus a text file with the
 * keywords, the description and each image's caption. Saatchi has no usable
 * API, so this package is the bridge between the frozen product and the manual
 * upload form.
 */
require_once __DIR__ . '/app/bootstrap.php';

/** Shortest side of every packaged image, per the artist's decision (2026-07-31). */
const SAATCHI_PACKAGE_SHORTEST_SIDE = 2200;

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::WEBSITE_MANAGE, 'Publication');
$pdo = Database::connection();
$userId = (int)$user['id'];
$artworkId = max(0, (int)($_GET['id'] ?? 0));

try {
    $owned = $pdo->prepare('SELECT final_title FROM artworks WHERE id=? AND user_id=? LIMIT 1');
    $owned->execute([$artworkId, $userId]);
    $artworkTitle = $owned->fetchColumn();
    if ($artworkTitle === false) {
        throw new RuntimeException(t('Artwork not found.', 'Obra no encontrada.'));
    }

    $publicationService = new PublicationService($pdo);
    $sheet = (new ArtworkSheetService($pdo))->sheetForArtwork($artworkId, $userId);
    $publication = $publicationService->findForSheet((int)$sheet['id'], $userId);
    if (!is_array($publication)) {
        throw new RuntimeException(t('Publish the artwork page first.', 'Publicá primero la página de la obra.'));
    }
    [$payload] = (new PublicationDistributionService($pdo, null, $publicationService))
        ->ensureCurrentProduct((int)$publication['id'], $userId);

    $package = (array)($payload['destinations']['saatchi'] ?? []);
    $images = (array)($package['images'] ?? []);
    if ($images === []) {
        throw new RuntimeException(t('The composition has no images yet.', 'La composición todavía no tiene imágenes.'));
    }

    $descriptionLocaleForExtras = (string)(((array)($payload['locales']['adaptations'] ?? []))[0] ?? ($payload['locales']['working'] ?? 'es'));

    // TODO EL MATERIAL: el canal es manual y el que elige cuales imagenes
    // suben es el artista. Las de la composicion van primero con su pie del
    // paquete; despues viaja el resto de los mockups de la obra, cada uno con
    // su pie propio si ya lo tiene, bajo su propia seccion en las notas.
    $enPaquete = [];
    foreach ($images as $image) {
        $enPaquete[basename((string)($image['file'] ?? ''))] = true;
    }
    try {
        $todosStmt = $pdo->prepare("SELECT m.id, m.mockup_file, COALESCE(MAX(s.saatchi_caption),'') AS sheet_caption
            FROM mockups m
            LEFT JOIN mockup_sheets s ON s.user_id=m.user_id AND (s.mockup_id=m.id OR s.mockup_file=m.mockup_file)
            WHERE m.user_id=? AND COALESCE(m.mockup_file,'')<>'' AND (
                m.source_artwork_id=? OR (COALESCE(m.artwork_group_id,0)>0
                    AND m.artwork_group_id=(SELECT COALESCE(artwork_group_id,0) FROM artworks WHERE id=? AND user_id=?))
            )
            GROUP BY m.id, m.mockup_file ORDER BY m.id");
        $todosStmt->execute([$userId, $artworkId, $artworkId, $userId]);
        $lectorEditorial = new BilingualEditorialService($pdo);
        foreach ($todosStmt->fetchAll(PDO::FETCH_ASSOC) as $extraRow) {
            $extraFile = basename((string)$extraRow['mockup_file']);
            if ($extraFile === '' || isset($enPaquete[$extraFile])) {
                continue;
            }
            $enPaquete[$extraFile] = true;
            $extraPie = trim((string)$extraRow['sheet_caption']);
            if ($extraPie === '') {
                try {
                    $extraContenido = (array)$lectorEditorial->get($userId, 'mockup', (int)$extraRow['id'], $descriptionLocaleForExtras)['content'];
                    $extraPie = trim((string)($extraContenido['saatchi_caption'] ?? ''));
                } catch (Throwable) {
                    $extraPie = '';
                }
            }
            $images[] = [
                'file' => $extraFile,
                'caption' => [$descriptionLocaleForExtras => $extraPie],
                'extra_view' => true,
            ];
        }
    } catch (Throwable) {
        // Sin extras el paquete sigue siendo el de la composicion.
    }

    $slug = (string)($payload['sources']['page']['slug'] ?? 'obra');
    $keywordLocale = (string)($package['keywords_locale'] ?? 'en');
    $descriptionLocale = (string)(((array)($payload['locales']['adaptations'] ?? []))[0] ?? ($payload['locales']['working'] ?? 'es'));

    // The notes file travels with the images so nothing has to be copied from
    // the screen while filling in Saatchi's form.
    $lines = [];
    $lines[] = (string)($payload['artwork']['title'] ?? $artworkTitle);
    $lines[] = str_repeat('=', mb_strlen((string)($payload['artwork']['title'] ?? $artworkTitle)));
    $lines[] = '';
    // Los topes de Saatchi se miden en caracteres y son duros: se informan al lado
    // de cada campo para que no haya que descubrirlo pegando en el formulario.
    $saatchiTitle = trim((string)($package['title'][$descriptionLocale] ?? ''));
    if ($saatchiTitle !== '') {
        $lines[] = 'TITLE (' . strtoupper($descriptionLocale) . ') — ' . mb_strlen($saatchiTitle) . '/65';
        $lines[] = $saatchiTitle;
        $lines[] = '';
    }
    $lines[] = 'KEYWORDS (' . strtoupper($keywordLocale) . ') — ' . count((array)($package['keywords'] ?? [])) . '/12';
    $lines[] = implode(', ', array_map('strval', (array)($package['keywords'] ?? [])));
    $lines[] = '';
    $saatchiDescription = (string)($package['description'][$descriptionLocale] ?? '');
    $lines[] = 'DESCRIPTION (' . strtoupper($descriptionLocale) . ') — ' . mb_strlen($saatchiDescription) . '/1000'
        . (mb_strlen($saatchiDescription) > 1000 ? '  ← EXCEDE: hay que acortarla antes de pegarla' : '');
    $lines[] = $saatchiDescription;
    $lines[] = '';
    $lines[] = 'IMAGE CAPTIONS';

    $zipPath = tempnam(sys_get_temp_dir(), 'saatchi-');
    if ($zipPath === false) {
        throw new RuntimeException(t('Could not prepare the package.', 'No se pudo preparar el paquete.'));
    }
    $zip = new ZipPackage($zipPath);
    $position = 0;
    $extrasAnunciados = false;
    foreach ($images as $image) {
        $file = basename((string)($image['file'] ?? ''));
        if ($file === '') continue;
        if (!empty($image['extra_view']) && !$extrasAnunciados) {
            $extrasAnunciados = true;
            $lines[] = '';
            $lines[] = 'ADDITIONAL VIEWS — el resto de los mockups de la obra. Saatchi acepta';
            $lines[] = 'pocas imagenes por listing: elegi libremente cuales subis.';
        }
        $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path) && StorageService::isGcsActive()) {
            StorageService::downloadFile('results/' . $file, $path);
        }
        if (!is_file($path)) continue;

        $position++;
        $entryName = sprintf('%02d-%s.jpg', $position, $slug);
        $sourceSize = @getimagesize($path);

        // Saatchi demands JPG with a 1200x1500 minimum; the stored mockups are
        // smaller, so the package ships a scaled copy (shortest side 2200) and
        // the app's own images stay untouched.
        $scaled = tempnam(sys_get_temp_dir(), 'saatchi-img-');
        if ($scaled !== false && ImageResizer::resizeCopy($path, $scaled, SAATCHI_PACKAGE_SHORTEST_SIDE)) {
            $zip->addFileFromPath($entryName, $scaled);
            $packagedSize = @getimagesize($scaled);
            @unlink($scaled);
        } else {
            if ($scaled !== false) @unlink($scaled);
            $zip->addFileFromPath($entryName, $path);
            $packagedSize = $sourceSize;
        }

        $caption = (string)($image['caption'][$descriptionLocale] ?? '');
        $lines[] = '';
        $lines[] = $entryName;
        $lines[] = '  ' . ($caption !== '' ? $caption : '—');
        if (is_array($packagedSize) && is_array($sourceSize)) {
            $lines[] = sprintf(
                '  %dx%d px (original %dx%d, ampliado para el mínimo de Saatchi)',
                (int)$packagedSize[0],
                (int)$packagedSize[1],
                (int)$sourceSize[0],
                (int)$sourceSize[1]
            );
        }
        if (is_array($packagedSize) && ((int)$packagedSize[0] < 1200 || (int)$packagedSize[1] < 1500)) {
            $lines[] = sprintf('  [!] %dx%d — Saatchi pide mínimo 1200x1500 px', (int)$packagedSize[0], (int)$packagedSize[1]);
        }
    }
    if ($position === 0) {
        @unlink($zipPath);
        throw new RuntimeException(t('The composition images are unavailable.', 'Las imágenes de la composición no están disponibles.'));
    }
    if ($position < 4) {
        $lines[] = '';
        $lines[] = '[!] Saatchi recomienda 4+ imágenes variadas: frente, detalle, mockups.';
    }
    $zip->addFromString('saatchi.txt', implode("\r\n", $lines) . "\r\n");
    $zip->finish();

    $downloadName = 'saatchi-' . preg_replace('/[^a-z0-9-]+/i', '-', $slug) . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string)filesize($zipPath));
    header('Cache-Control: private, no-store');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
} catch (Throwable $e) {
    header('Location: publication.php?id=' . rawurlencode((string)$artworkId) . '&error=' . rawurlencode($e->getMessage()) . '#pub-step-saatchi');
    exit;
}
