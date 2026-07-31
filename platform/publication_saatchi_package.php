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

    $slug = (string)($payload['sources']['page']['slug'] ?? 'obra');
    $keywordLocale = (string)($package['keywords_locale'] ?? 'en');
    $descriptionLocale = (string)(((array)($payload['locales']['adaptations'] ?? []))[0] ?? ($payload['locales']['working'] ?? 'es'));

    // The notes file travels with the images so nothing has to be copied from
    // the screen while filling in Saatchi's form.
    $lines = [];
    $lines[] = (string)($payload['artwork']['title'] ?? $artworkTitle);
    $lines[] = str_repeat('=', mb_strlen((string)($payload['artwork']['title'] ?? $artworkTitle)));
    $lines[] = '';
    $lines[] = 'KEYWORDS (' . strtoupper($keywordLocale) . ') — ' . count((array)($package['keywords'] ?? [])) . '/12';
    $lines[] = implode(', ', array_map('strval', (array)($package['keywords'] ?? [])));
    $lines[] = '';
    $lines[] = 'DESCRIPTION (' . strtoupper($descriptionLocale) . ')';
    $lines[] = (string)($package['description'][$descriptionLocale] ?? '');
    $lines[] = '';
    $lines[] = 'IMAGE CAPTIONS';

    $zipPath = tempnam(sys_get_temp_dir(), 'saatchi-');
    if ($zipPath === false) {
        throw new RuntimeException(t('Could not prepare the package.', 'No se pudo preparar el paquete.'));
    }
    $zip = new ZipPackage($zipPath);
    $position = 0;
    foreach ($images as $image) {
        $file = basename((string)($image['file'] ?? ''));
        if ($file === '') continue;
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
