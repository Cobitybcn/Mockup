<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = Auth::requireUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$jobId = basename((string)($_POST['job'] ?? ''));
$filename = basename((string)($_POST['filename'] ?? ''));

if ($jobId === '' || $filename === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing job ID or filename.']);
    exit;
}

$jobDir = __DIR__ . '/jobs/' . $jobId;
$statusFile = $jobDir . '/status.json';

if (!is_file($statusFile)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Job status file not found.']);
    exit;
}

$status = json_decode((string)file_get_contents($statusFile), true);

if (!is_array($status)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not parse job status.']);
    exit;
}

// Check authorization
if ((int)($status['user_id'] ?? 0) !== (int)$currentUser['id']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied.']);
    exit;
}

// Verify the selected filename is a valid candidate for this job
$candidates = $status['candidates'] ?? [];
if (!in_array($filename, $candidates, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid candidate filename.']);
    exit;
}

try {
    $db = Database::connection();
    $stmtArtwork = $db->prepare("SELECT id FROM artworks WHERE job_id = :job_id LIMIT 1");
    $stmtArtwork->execute(['job_id' => $jobId]);
    $artworkRow = $stmtArtwork->fetch();
    $artworkId = $artworkRow ? (int)$artworkRow['id'] : null;

    if ($artworkId === null) {
        throw new RuntimeException('Artwork record not found in database.');
    }

    $selectedPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($selectedPath)) {
        throw new RuntimeException('Selected candidate file does not exist on disk.');
    }

    // --- RUN ANALYSIS AND CONTEXT GENERATION ON SELECTED IMAGE ---
    $measurements = $status['measurements'] ?? [];
    $artistProfile = ArtistProfile::findForUser((int)$status['user_id']);
    
    $metadata = [
        'title' => $status['title'] ?? 'Sin título',
        'artist_notes' => $status['artist_notes'] ?? '',
        'region' => '',
        'artist_profile' => $artistProfile,
        'artist_profile_prompt' => ArtistProfile::forPrompt($artistProfile),
        'width_cm' => $measurements['unit'] === 'cm' ? ($measurements['width'] ?? null) : null,
        'height_cm' => $measurements['unit'] === 'cm' ? ($measurements['height'] ?? null) : null,
        'depth_cm' => $measurements['unit'] === 'cm' ? ($measurements['depth'] ?? null) : null,
        'target_market' => $artistProfile['target_audience'] ?? 'collectors',
        'preferred_style' => $status['preferred_style'] ?? '',
    ];

    $appMode = ServiceFactory::appMode();

    if ($appMode === 'mock') {
        // --- MOCK MODE ---
        $analyzer = ServiceFactory::artworkAnalyzer();
        $analysisResponse = $analyzer->analyze($selectedPath, $metadata);

        if (!is_dir(ANALYSIS_DIR)) {
            mkdir(ANALYSIS_DIR, 0775, true);
        }
        $jsonName = pathinfo(basename($selectedPath), PATHINFO_FILENAME) . '.analysis.json';
        file_put_contents(
            ANALYSIS_DIR . DIRECTORY_SEPARATOR . $jsonName,
            json_encode($analysisResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $engine = new MockupContextEngine();
        $mockAnalysis = [
            'image_path' => $selectedPath,
            'artwork_analysis' => [
                'visual_language' => ['abstract', 'geometric'],
                'emotional_energy' => ['contemplative', 'calm'],
                'dominant_colors' => ['#eaeaea', '#333333'],
                'secondary_colors' => ['#9a7b56'],
                'color_temperature' => 'neutral',
                'contrast_level' => 'medium',
                'composition_type' => 'centered',
                'spatial_presence' => 'balanced',
                'artwork_function' => 'statement piece',
                'suggested_audience' => ['collectors', 'architects'],
                'commercial_positioning' => 'premium',
                'one_line_curatorial_read' => 'Estudio de geometría y calma mineral (Mock).',
                'style_summary' => 'Geometric abstract artwork (Mock).',
                'seasonal_strategy' => [
                    'primary_season' => 'neutral'
                ],
                'audience_profile' => [
                    'primary' => 'collectors'
                ]
            ],
            'recommended_number_of_contexts' => 5,
            'contextual_proposals' => [
                [
                    'context_name' => 'Silent Mineral Room (Mock)',
                    'context_role' => 'primary presentation',
                    'space_type' => 'minimal architectural interior',
                    'atmosphere' => 'silent, mineral',
                    'materials' => ['stone', 'plaster'],
                    'lighting' => 'soft day light',
                    'camera_angle' => 'three-quarter view',
                    'human_presence' => 'none',
                    'curatorial_reason' => 'El espacio mineral y silencioso resalta las formas de la obra.',
                    'commercial_reason' => 'Posiciona la obra para coleccionistas minimalistas.'
                ],
                [
                    'context_name' => 'Collector\'s Study (Mock)',
                    'context_role' => 'scale reference',
                    'space_type' => 'luxury office',
                    'atmosphere' => 'warm, professional',
                    'materials' => ['wood', 'leather'],
                    'lighting' => 'warm sunset light',
                    'camera_angle' => 'frontal view',
                    'human_presence' => 'optional standing male figure 1.80m',
                    'curatorial_reason' => 'La madera y el cuero aportan sofisticación y escala real.',
                    'commercial_reason' => 'Ideal para entornos corporativos o estudios privados.'
                ]
            ]
        ];
        $engine->generateMockupPrompts($artworkId, $mockAnalysis, $metadata);

    } else {
        // --- REAL API MODE (GEMINI MULTIMODAL) ---
        $engine = new MockupContextEngine();
        
        $contextAnalysis = $engine->analyzeArtworkContext($selectedPath, $metadata);
        $contextAnalysis['image_path'] = $selectedPath;
        
        if (!is_dir(ANALYSIS_DIR)) {
            mkdir(ANALYSIS_DIR, 0775, true);
        }
        $jsonName = pathinfo(basename($selectedPath), PATHINFO_FILENAME) . '.analysis.json';
        file_put_contents(
            ANALYSIS_DIR . DIRECTORY_SEPARATOR . $jsonName,
            json_encode($contextAnalysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $engine->generateMockupPrompts($artworkId, $contextAnalysis, $metadata);
    }

    // Save metadata json file
    $metaName = pathinfo($filename, PATHINFO_FILENAME) . '.meta.json';
    $metaPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $metaName;
    file_put_contents(
        $metaPath,
        json_encode([
            'source_job_id' => $jobId,
            'user_id' => (int)$status['user_id'],
            'root_file' => $filename,
            'measurements' => $measurements,
            'artist_notes' => $status['artist_notes'] ?? '',
            'provider_settings' => ProviderSettings::all(),
            'scale_text' => build_scale_text_for_meta_select($measurements),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    // Update status.json
    $status['result_file'] = $filename;
    file_put_contents(
        $statusFile,
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    // Update SQLite database to done and set root_file
    $stmt = $db->prepare('UPDATE artworks SET status = :status, root_file = :root_file, updated_at = :now WHERE id = :id');
    $stmt->execute([
        'status' => 'done',
        'root_file' => $filename,
        'now' => date('c'),
        'id' => $artworkId
    ]);

    echo json_encode([
        'ok' => true,
        'redirect' => 'form2.php?image=' . rawurlencode($filename)
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

function build_scale_text_for_meta_select(array $measurements): string
{
    $width = trim((string)($measurements['width'] ?? ''));
    $height = trim((string)($measurements['height'] ?? ''));
    $depth = trim((string)($measurements['depth'] ?? ''));
    $unit = trim((string)($measurements['unit'] ?? 'cm'));

    if ($width === '' || $height === '') {
        return 'No physical artwork size was provided. Keep scale plausible for the visible artwork proportions.';
    }

    $text = "The real physical artwork measures {$width} {$unit} wide x {$height} {$unit} high.";
    $text .= " These measurements refer only to the artwork, not to the photo, wall, furniture, background or surrounding objects.";
    $text .= " In mockups, scale the artwork realistically relative to architecture, furniture and human figures.";

    if ($depth !== '') {
        $text .= " Physical stretcher/support depth: {$depth} {$unit}.";
    }

    return $text;
}
