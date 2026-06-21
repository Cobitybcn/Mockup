<?php
declare(strict_types=1);

/**
 * Isolated first-pass gap filler for timeline milestones.
 * It deliberately attaches the root artwork and every available neighbouring
 * anchor image on every call; text alone is never used to recreate artwork.
 */
class SocialVideoGapFillService
{
    public function generate(array $milestone, ?string $previousImage, ?string $nextImage, string $rootArtwork): string
    {
        if (!ProviderSettings::isRealMode() || !ProviderSettings::allowRealApi() || ProviderSettings::imageProvider() !== 'gemini') {
            throw new RuntimeException('Gap filling requires Gemini image generation in real API mode.');
        }
        if (!is_file($rootArtwork)) { throw new RuntimeException('La obra raíz no está disponible para proteger su fidelidad.'); }
        $narrative = trim((string)($milestone['narrative_text'] ?? ''));
        if ($narrative === '') { throw new RuntimeException('Escribe qué ocurre en este hito antes de generar su imagen.'); }

        $client = new GeminiImageClient();
        $parts = [$client->textPart(<<<TEXT
Create one vertical, cinematic still for a Social Video timeline milestone.
Milestone narrative: {$narrative}
The ROOT ARTWORK image is mandatory visual evidence. Preserve its exact composition, colors, proportions, marks, orientation and visible surface. Do not repaint, crop, mirror, redesign or substitute it.
Use the preceding and following anchors, when attached, only to bridge camera, lighting, human presence and spatial continuity. The result must be a plausible midpoint, never a generic new artwork.
TEXT), $client->imagePart($rootArtwork)];
        foreach ([$previousImage, $nextImage] as $path) {
            if ($path && is_file($path)) { $parts[] = $client->imagePart($path); }
        }
        $referenceImage = (string)($milestone['reference_image'] ?? '');
        $referencePath = RESULTS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($referenceImage, '/'));
        if ($referenceImage !== '' && is_file($referencePath)) { $parts[] = $client->imagePart($referencePath); }

        $bytes = base64_decode($client->generateImage($parts), true);
        if ($bytes === false || $bytes === '') { throw new RuntimeException('La generación de imagen intermedia no devolvió datos válidos.'); }
        $directory = RESULTS_DIR . DIRECTORY_SEPARATOR . 'social-video-timeline';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) { throw new RuntimeException('No se pudo crear el directorio de timeline.'); }
        $filename = 'gap_' . time() . '_' . bin2hex(random_bytes(5)) . '.png';
        if (file_put_contents($directory . DIRECTORY_SEPARATOR . $filename, $bytes) === false) { throw new RuntimeException('No se pudo guardar la imagen intermedia.'); }
        return 'social-video-timeline/' . $filename;
    }
}
