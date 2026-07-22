<?php
declare(strict_types=1);

/**
 * reanalyze.php — Punto #12
 * ---------------------------
 * Endpoint AJAX para re-analizar una obra raíz y generar nuevas propuestas contextuales.
 * Reemplaza los contextos anteriores de la obra con los nuevos generados por Gemini.
 *
 * Método: POST
 * Parámetros: image (nombre del archivo raíz)
 * Retorna: JSON {ok: true, redirect: "form2.php?image=..."} o {ok: false, error: "..."}
 */

ini_set('max_execution_time', '300');
ini_set('memory_limit', '512M');
set_time_limit(300);

require_once __DIR__ . '/app/bootstrap.php';

if (!defined('LEGACY_MOCKUP_FLOW_ENABLED') || !LEGACY_MOCKUP_FLOW_ENABLED) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Legacy mockup context analysis disabled. Use the direct world mother combination flow (mockup_combinations_review.php).'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$currentUser = Auth::requireUser();

// Liberar bloqueo de sesión para no bloquear otras peticiones del usuario
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');

function fail_json(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Sólo acepta POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail_json('Method not allowed.', 405);
}

$image = trim((string)($_POST['image'] ?? ''));

if ($image === '') {
    fail_json('Missing image parameter.');
}

$safeImage   = basename($image);
$imagePath   = RESULTS_DIR . DIRECTORY_SEPARATOR . $safeImage;
$imageBase   = pathinfo($safeImage, PATHINFO_FILENAME);
$metaPath    = RESULTS_DIR . DIRECTORY_SEPARATOR . $imageBase . '.meta.json';

if (!is_file($imagePath)) {
    fail_json('Root image not found: ' . $safeImage, 404);
}

// Verificar ownership
if (is_file($metaPath)) {
    $metaData = json_decode((string)file_get_contents($metaPath), true);
    if (is_array($metaData) && (int)($metaData['user_id'] ?? 0) !== (int)$currentUser['id']) {
        fail_json('You do not have access to this artwork.', 403);
    }
}

// Verificar que el modo real esté activo
if (!ProviderSettings::isRealMode() || !ProviderSettings::allowRealApi()) {
    fail_json('Re-analysis requires real API mode to be enabled (APP_MODE=gemini, ALLOW_REAL_API=true).');
}

// Cargar metadatos de la obra
$pdo     = Database::connection();
$artwork = null;

$stmtArtwork = $pdo->prepare("
    SELECT * FROM artworks
    WHERE root_file = :root_file AND user_id = :user_id
    LIMIT 1
");
$stmtArtwork->execute([
    'root_file' => $safeImage,
    'user_id'   => (int)$currentUser['id'],
]);
$artwork = $stmtArtwork->fetch();

if (!$artwork) {
    fail_json('Artwork was not found in the database.', 404);
}

$artworkId = (int)$artwork['id'];

// Cargar perfil del artista
$artistProfile       = ArtistProfile::findForUser((int)$currentUser['id']);
$artistProfilePrompt = ArtistProfile::hasContent($artistProfile)
    ? ArtistProfile::forPrompt($artistProfile)
    : '';

// Cargar dimensiones físicas de la obra desde BD o meta.json
$widthCm  = $artwork['width']  ?? null;
$heightCm = $artwork['height'] ?? null;
$depthCm  = $artwork['depth']  ?? null;

if ((!$widthCm || !$heightCm) && is_file($metaPath)) {
    $metaData = json_decode((string)file_get_contents($metaPath), true);
    if (is_array($metaData) && isset($metaData['measurements'])) {
        $widthCm  = $metaData['measurements']['width']  ?? $widthCm;
        $heightCm = $metaData['measurements']['height'] ?? $heightCm;
        $depthCm  = $metaData['measurements']['depth']  ?? $depthCm;
    }
}

$metadata = [
    'title'                 => $safeImage,
    'width_cm'              => $widthCm,
    'height_cm'             => $heightCm,
    'depth_cm'              => $depthCm,
    'artist_notes'          => '',
    'artist_profile'        => $artistProfile,
    'artist_profile_prompt' => $artistProfilePrompt,
    'target_market'         => trim((string)($artistProfile['target_audience'] ?? 'collectors')),
    'preferred_style'       => '',
    'region'                => trim((string)($artistProfile['preferred_regions'] ?? '')),
];

try {
    $engine = new MockupContextEngine();

    // Fase 1: re-análisis con Gemini
    Logger::log("Re-análisis iniciado para obra: $safeImage (user: {$currentUser['id']})", 'gemini');
    $analysisData = $engine->analyzeArtworkContext($imagePath, $metadata);

    // Punto #12: borrar análisis y contextos anteriores para esta obra antes de insertar los nuevos
    $pdo->beginTransaction();

    try {
        // Obtener IDs de análisis existentes para borrar en cascada
        $stmtOldAnalysis = $pdo->prepare("
            SELECT id FROM artwork_analysis WHERE artwork_id = :artwork_id
        ");
        $stmtOldAnalysis->execute(['artwork_id' => $artworkId]);
        $oldAnalysisIds = $stmtOldAnalysis->fetchAll(PDO::FETCH_COLUMN);

        if ($oldAnalysisIds) {
            $placeholders = implode(',', array_fill(0, count($oldAnalysisIds), '?'));
            $pdo->prepare("DELETE FROM mockup_contexts WHERE analysis_id IN ($placeholders)")
                ->execute($oldAnalysisIds);
        }

        $deletedByArtwork = $pdo->prepare("DELETE FROM mockup_contexts WHERE artwork_id = :artwork_id");
        $deletedByArtwork->execute(['artwork_id' => $artworkId]);
        Logger::log(
            'Re-analysis cleared old mockup_contexts for artwork_id=' . $artworkId . ', rows=' . $deletedByArtwork->rowCount(),
            'analysis_debug'
        );

        $pdo->prepare("DELETE FROM artwork_analysis WHERE artwork_id = :artwork_id")
            ->execute(['artwork_id' => $artworkId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Fase 2: generar nuevos prompts y guardar en BD
    $engine->generateMockupPrompts($artworkId, $analysisData, $metadata);

    Logger::log("Re-análisis completado para obra: $safeImage", 'gemini');

    echo json_encode([
        'ok'       => true,
        'message'  => 'Artwork re-analyzed successfully. New contexts are ready.',
        'redirect' => 'report.php?image=' . rawurlencode($safeImage),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    Logger::log("Error en re-análisis de $safeImage: " . $e->getMessage(), 'error');
    fail_json('Error during re-analysis: ' . $e->getMessage(), 500);
}
