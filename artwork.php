<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$pdo = Database::connection();
$id = max(0, (int)($_GET['id'] ?? 0));

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function media_url(?string $file, bool $download = false): string
{
    if (!$file) {
        return '';
    }

    $url = 'media.php?file=' . rawurlencode(basename($file));

    return $download ? $url . '&download=1' : $url;
}

function read_json_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $data = json_decode((string)file_get_contents($path), true);

    return is_array($data) ? $data : [];
}

function words_from($value): array
{
    if (is_array($value)) {
        $items = [];
        foreach ($value as $item) {
            $items = array_merge($items, words_from($item));
        }

        return $items;
    }

    $parts = preg_split('/[,;|\/\n]+/', strtolower((string)$value));

    return array_values(array_filter(array_map(
        fn($part) => trim(preg_replace('/\s+/', ' ', (string)$part)),
        $parts ?: []
    )));
}

function unique_limited(array $items, int $limit, array $fallback = []): array
{
    $out = [];

    foreach (array_merge($items, $fallback) as $item) {
        $item = trim(preg_replace('/\s+/', ' ', (string)$item));

        if ($item === '') {
            continue;
        }

        $key = strtolower($item);
        if (!isset($out[$key])) {
            $out[$key] = $item;
        }

        if (count($out) >= $limit) {
            break;
        }
    }

    return array_values($out);
}

function sentence_from(array $items, string $fallback): string
{
    $items = unique_limited($items, 4);

    return $items ? implode(', ', $items) : $fallback;
}

function labelize_term(string $value): string
{
    $value = str_replace(
        ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ', 'á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
        ['A', 'E', 'I', 'O', 'U', 'U', 'N', 'a', 'e', 'i', 'o', 'u', 'u', 'n'],
        $value
    );
    $value = strtolower(trim(preg_replace('/[_-]+/', ' ', $value)));
    $map = [
        'abstracto' => 'abstract',
        'contemporaneo' => 'contemporary',
        'contemporánea' => 'contemporary',
        'contemporanea' => 'contemporary',
        'material' => 'material',
        'geometrico' => 'geometric',
        'geométrico' => 'geometric',
        'arquitectonico' => 'architectural',
        'arquitectónico' => 'architectural',
        'organico' => 'organic',
        'orgánico' => 'organic',
        'minimalista' => 'minimal',
        'estructural' => 'structural',
        'surreal' => 'surreal',
        'figurativo' => 'figurative',
        'expresivo' => 'expressive',
        'coleccionismo' => 'collecting',
        'coleccionistas' => 'collectors',
        'galeria' => 'gallery',
        'galería' => 'gallery',
        'interiorismo de autor' => 'designer interiors',
        'contexto premium' => 'premium context',
        'intensidad silenciosa' => 'quiet intensity',
        'contemplativo' => 'contemplative',
        'equilibrado' => 'balanced',
        'calido' => 'warm',
        'cálido' => 'warm',
        'frio' => 'cool',
        'frío' => 'cool',
        'neutral' => 'neutral',
        'alta' => 'high',
        'media' => 'medium',
        'baja' => 'low',
        'sutil' => 'subtle',
        'silencio' => 'silence',
        'territorio' => 'territory',
        'austeridad' => 'austerity',
        'monolitos' => 'monoliths',
        'monolito' => 'monolith',
        'simbolico' => 'symbolic',
        'simbólico' => 'symbolic',
        'metafisico' => 'metaphysical',
        'metafísico' => 'metaphysical',
        'campos de color' => 'color fields',
        'campo interior' => 'inner field',
    ];

    return $map[$value] ?? $value;
}

function labelize_terms(array $items): array
{
    return array_values(array_filter(array_map(
        fn($item) => labelize_term((string)$item),
        $items
    )));
}

function concise_terms(array $items, array $fallback): array
{
    $terms = [];

    foreach (labelize_terms($items) as $item) {
        if (str_word_count($item) <= 4 && strlen($item) <= 42) {
            $terms[] = $item;
        }
    }

    return unique_limited($terms, 12, $fallback);
}

function looks_spanish(string $value): bool
{
    return (bool)preg_match('/\b(obra|artista|coleccion|coleccionistas|galerias|galerias|arquitectos|interioristas|decoradores|compradores|personas|lenguaje|visual|construye|partir|silenciosas|simbolicas|metafisicas|territorio|austeridad)\b/i', $value);
}

function english_or_default(string $value, string $fallback): string
{
    $value = trim($value);

    if ($value === '' || looks_spanish($value)) {
        return $fallback;
    }

    return $value;
}

function title_case_soft(string $value): string
{
    $small = ['and', 'or', 'of', 'in', 'the', 'a', 'an', 'with', 'for'];
    $words = preg_split('/\s+/', strtolower(trim($value))) ?: [];
    $words = array_map(function (string $word) use ($small): string {
        return in_array($word, $small, true) ? $word : ucfirst($word);
    }, $words);

    if ($words) {
        $words[0] = ucfirst($words[0]);
    }

    return implode(' ', $words);
}

function slugify(string $value): string
{
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $value), '-'));

    return $slug !== '' ? $slug : 'artwork';
}

function first_sentence(string $value): string
{
    $value = trim((string)preg_replace('/\s+/', ' ', $value));
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(.+?[.!?])\s+/', $value . ' ', $match)) {
        return trim($match[1]);
    }
    return $value;
}

function public_copy_tone(array $artistProfile): string
{
    $tone = trim((string)($artistProfile['statement'] ?? ''));
    return $tone !== '' ? $tone : 'Clear, poetic, sober, elegant, human, public-facing, not academic, not overly curatorial, not decorative, not generic.';
}

function forbidden_language(array $artistProfile): array
{
    return unique_limited(array_merge([
        'This artwork is presented as',
        'This version positions the piece',
        'collector-grade silence',
        'curatorial narrative',
        'commercial presentation',
        'publication-ready',
        'for galleries, curators and interior designers',
        'overly academic language',
        'generic marketplace filler text',
    ], words_from($artistProfile['commercial_positioning'] ?? '')), 40);
}

function clean_public_copy(string $copy, array $artistProfile): string
{
    foreach (forbidden_language($artistProfile) as $phrase) {
        $copy = str_ireplace((string)$phrase, '', $copy);
    }
    $copy = preg_replace('/\s+([,.])/', '$1', $copy);
    $copy = preg_replace('/\s{2,}/', ' ', (string)$copy);
    return trim((string)$copy);
}

function build_artwork_package_v2(array $artwork, array $analysis, array $artistProfile): array
{
    $artist = trim((string)($artistProfile['artist_name'] ?? ''));
    $rootSuggestedTitles = is_array($analysis['suggested_titles'] ?? null) ? $analysis['suggested_titles'] : [];
    $rootContextualProposals = is_array($analysis['contextual_proposals'] ?? null) ? $analysis['contextual_proposals'] : [];
    $isNewSchema = array_key_exists('suggested_titles', $analysis) || array_key_exists('contextual_proposals', $analysis);
    $profile = $analysis['artwork_analysis'] ?? $analysis['artwork_profile'] ?? [];

    $titles = [];
    $titleSubtitles = [];
    $titleDescriptions = [];

    $legacySuggestedTitles = is_array($profile['publishing_metadata']['suggested_titles'] ?? null)
        ? $profile['publishing_metadata']['suggested_titles']
        : [];
    if (isset($rootSuggestedTitles['title'])) {
        $rootSuggestedTitles = [$rootSuggestedTitles];
    }
    if (isset($legacySuggestedTitles['title'])) {
        $legacySuggestedTitles = [$legacySuggestedTitles];
    }
    $rawSuggestedTitles = $rootSuggestedTitles;

    if (!$rawSuggestedTitles && $legacySuggestedTitles) {
        $rawSuggestedTitles = $legacySuggestedTitles;
    }

    $countRoot = count($rootSuggestedTitles);
    $countLegacy = count($legacySuggestedTitles);

    if (is_array($rawSuggestedTitles)) {
        foreach ($rawSuggestedTitles as $idx => $tObj) {
            if (is_array($tObj)) {
                $title = trim((string)($tObj['title'] ?? ''));
                $sub = trim((string)($tObj['subtitle'] ?? ''));
                $desc = trim((string)($tObj['description'] ?? ''));
                $usedFallback = false;
                $descriptionSource = $desc !== '' ? ($isNewSchema ? 'suggested_titles.description' : 'legacy.description') : 'none';

                if (!$isNewSchema) {
                    foreach ([
                        'curatorial_description',
                        'commercial_description',
                        'short_description',
                        'description',
                    ] as $legacyDescriptionKey) {
                        $candidate = trim((string)($tObj[$legacyDescriptionKey] ?? ''));
                        if ($candidate !== '') {
                            $desc = $candidate;
                            $usedFallback = $legacyDescriptionKey !== 'description';
                            $descriptionSource = 'legacy.' . $legacyDescriptionKey;
                            break;
                        }
                    }
                }

                if ($title !== '') {
                    $titles[] = $title;
                    $titleSubtitles[$title] = $sub;
                    $titleDescriptions[$title] = $desc;

                    error_log(sprintf(
                        "Artwork package title %d: description_source=%s, fallback_description_used=%s",
                        $idx,
                        $descriptionSource,
                        $usedFallback ? 'yes' : 'no'
                    ));
                }
            } elseif (is_string($tObj)) {
                $title = trim((string)$tObj);
                if ($title !== '') {
                    $titles[] = $title;
                    $titleSubtitles[$title] = '';
                    $titleDescriptions[$title] = '';
                }
            }
        }
    }

    if (empty($titles)) {
        $titles = ['Untitled'];
        $titleSubtitles = ['Untitled' => ''];
        $titleDescriptions = ['Untitled' => ''];
    }

    error_log(sprintf(
        "Analysis Schema: %s, Root titles: %d, Legacy titles: %d",
        $isNewSchema ? "new_schema" : "legacy_schema",
        $countRoot,
        $countLegacy
    ));

    $storedTitle = trim((string)($artwork['final_title'] ?? ''));
    $storedSubtitle = trim((string)($artwork['subtitle'] ?? ''));
    $titleForCopy = ($storedTitle !== '' && !looks_spanish($storedTitle)) ? $storedTitle : $titles[0];
    
    $suggestedSubtitle = $titleSubtitles[$titleForCopy] ?? '';
    $subtitle = ($storedSubtitle !== '' && !looks_spanish($storedSubtitle)) ? $storedSubtitle : $suggestedSubtitle;
    $titleLine = $titleForCopy . ($subtitle !== '' ? ': ' . $subtitle : '');
    $fileSlug = slugify(($artist !== '' ? $artist . '-' : '') . $titleForCopy);

    $description = '';
    if (!empty($titleDescriptions[$titleForCopy])) {
        $description = clean_public_copy($titleDescriptions[$titleForCopy], $artistProfile);
    } elseif (!empty($titles[0]) && !empty($titleDescriptions[$titles[0]])) {
        $description = clean_public_copy($titleDescriptions[$titles[0]], $artistProfile);
    }

    $premiumDescriptions = [];
    foreach ($titles as $index => $titleOption) {
        $copy = $titleDescriptions[$titleOption] ?? '';
        if ($copy === '' && !$isNewSchema) {
            $copy = $description;
        }
        $premiumDescriptions[$titleOption] = trim(clean_public_copy($copy, $artistProfile));
    }

    return [
        'root_alt' => 'Clean root image of ' . $titleForCopy,
        'root_caption' => $titleLine,
        'titles' => $titles,
        'title_subtitles' => $titleSubtitles,
        'premium_descriptions' => $premiumDescriptions,
        'suggested_subtitle' => $suggestedSubtitle,
        'description' => $description,
        'curatorial_reading' => first_sentence($description),
        'seo_slug' => $fileSlug,
        'file_names' => [
            $fileSlug . '-root-artwork.jpg',
        ],
    ];
}

function normalize_artwork_contexts(array $contexts): array
{
    $normalized = [];
    foreach ($contexts as $index => $context) {
        if (!is_array($context)) {
            continue;
        }

        $normalized[] = [
            'id' => (string)($context['id'] ?? $context['context_id'] ?? ('ctx_' . ($index + 1))),
            'name' => (string)($context['name'] ?? $context['context_name'] ?? $context['title'] ?? ('Context ' . ($index + 1))),
            'why' => (string)($context['why'] ?? $context['curatorial_reason'] ?? $context['commercial_reason'] ?? ''),
            'camera_group' => (string)($context['camera_group'] ?? $context['camera_view'] ?? ''),
            'time_of_day' => (string)($context['time_of_day'] ?? ''),
            'prompt' => (string)($context['prompt'] ?? $context['mockup_prompt'] ?? ''),
        ];
    }

    return $normalized;
}

if ($id <= 0) {
    http_response_code(404);
    die('Missing artwork.');
}

$stmt = $pdo->prepare('
    SELECT *
    FROM artworks
    WHERE id = :id
    AND user_id = :user_id
    LIMIT 1
');
$stmt->execute([
    'id' => $id,
    'user_id' => (int)$user['id'],
]);
$artwork = $stmt->fetch();

if (!is_array($artwork)) {
    http_response_code(404);
    die('Artwork not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_sheet') {
    $update = $pdo->prepare('
        UPDATE artworks
        SET final_title = :final_title,
            subtitle = :subtitle,
            medium = :medium,
            artwork_year = :artwork_year,
            series = :series,
            updated_at = :updated_at
        WHERE id = :id
        AND user_id = :user_id
    ');
    $update->execute([
        'final_title' => trim((string)($_POST['final_title'] ?? '')),
        'subtitle' => trim((string)($_POST['subtitle'] ?? '')),
        'medium' => trim((string)($_POST['medium'] ?? '')),
        'artwork_year' => trim((string)($_POST['artwork_year'] ?? '')),
        'series' => trim((string)($_POST['series'] ?? '')),
        'updated_at' => date('c'),
        'id' => $id,
        'user_id' => (int)$user['id'],
    ]);

    header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&saved=1');
    exit;
}

$rootFile = basename((string)($artwork['root_file'] ?? ''));
$rootPath = $rootFile ? RESULTS_DIR . DIRECTORY_SEPARATOR . $rootFile : '';
$rootBase = $rootFile ? pathinfo($rootFile, PATHINFO_FILENAME) : '';
$meta = $rootBase ? read_json_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $rootBase . '.meta.json') : [];
$analysis = $rootBase ? read_json_file(ANALYSIS_DIR . DIRECTORY_SEPARATOR . $rootBase . '.analysis.json') : [];

$analysisStmt = $pdo->prepare('
    SELECT *
    FROM artwork_analysis
    WHERE artwork_id = :artwork_id
    ORDER BY id DESC
    LIMIT 1
');
$analysisStmt->execute(['artwork_id' => $id]);
$dbAnalysis = $analysisStmt->fetch();

if (!$analysis && is_array($dbAnalysis)) {
    $analysisData = json_decode((string)$dbAnalysis['analysis_json'], true);
    if (is_array($analysisData)) {
        $analysis = array_key_exists('suggested_titles', $analysisData) || array_key_exists('contextual_proposals', $analysisData)
            ? $analysisData
            : ['artwork_profile' => $analysisData];
    }
}

$profile = is_array($analysis['artwork_profile'] ?? null) ? $analysis['artwork_profile'] : [];
if (!$profile && is_array($analysis['artwork_analysis'] ?? null)) {
    $profile = $analysis['artwork_analysis'];
}
$rootContextualProposals = is_array($analysis['contextual_proposals'] ?? null) ? $analysis['contextual_proposals'] : [];
$legacyRecommendedContexts = is_array($analysis['recommended_contexts'] ?? null) ? $analysis['recommended_contexts'] : [];
$contexts = normalize_artwork_contexts($rootContextualProposals ?: $legacyRecommendedContexts);

error_log(sprintf(
    'Artwork package contexts: contextual_proposals_found=%d, source=%s',
    count($rootContextualProposals),
    $rootContextualProposals ? 'contextual_proposals' : ($legacyRecommendedContexts ? 'recommended_contexts' : 'none')
));

if (!$contexts && !$rootContextualProposals) {
    $contextStmt = $pdo->prepare('
        SELECT *
        FROM mockup_contexts
        WHERE artwork_id = :artwork_id
        ORDER BY id ASC
    ');
    $contextStmt->execute(['artwork_id' => $id]);
    foreach ($contextStmt->fetchAll() as $contextRow) {
        $contextJson = json_decode((string)$contextRow['context_json'], true);
        $contextJson = is_array($contextJson) ? $contextJson : [];
        $contexts[] = [
            'id' => (string)$contextRow['id'],
            'name' => $contextRow['context_name'],
            'why' => $contextJson['curatorial_reason'] ?? '',
            'camera_group' => $contextJson['camera_group'] ?? '',
            'time_of_day' => $contextJson['time_of_day'] ?? '',
            'prompt' => $contextRow['prompt'],
        ];
    }
}

$hasValidNewSchema = is_array($analysis['suggested_titles'] ?? null)
    && is_array($analysis['contextual_proposals'] ?? null)
    && count(array_filter($analysis['suggested_titles'], static function ($titleOption): bool {
        return is_array($titleOption)
            && trim((string)($titleOption['title'] ?? '')) !== ''
            && trim((string)($titleOption['subtitle'] ?? '')) !== ''
            && trim((string)($titleOption['description'] ?? '')) !== '';
    })) === count((array)$analysis['suggested_titles']);
$analysisNeedsRefresh = !$hasValidNewSchema && !empty($contexts);

// Load root artwork candidates (frontal, 3/4 left, 3/4 right)
$rootCandidates = [];
try {
    $candidateStmt = $pdo->prepare('
        SELECT id, file_name, view_type, is_selected
        FROM root_artwork_candidates
        WHERE artwork_id = :artwork_id
        ORDER BY id ASC
    ');
    $candidateStmt->execute(['artwork_id' => $id]);
    foreach ($candidateStmt->fetchAll() as $candidate) {
        $viewType = (string)($candidate['view_type'] ?? 'frontal');
        $rootCandidates[$viewType][] = [
            'id'          => (int)$candidate['id'],
            'file_name'   => basename((string)($candidate['file_name'] ?? '')),
            'view_type'   => $viewType,
            'is_selected' => (bool)$candidate['is_selected'],
        ];
    }
} catch (Throwable $e) {
    $rootCandidates = [];
}
$rootCandidatesList = array_merge(
    $rootCandidates['frontal'] ?? [],
    $rootCandidates['three-quarter-left'] ?? [],
    $rootCandidates['three-quarter-right'] ?? []
);

$mockupStmt = $pdo->prepare('
    SELECT *
    FROM mockups
    WHERE user_id = :user_id
    AND artwork_file = :artwork_file
    ORDER BY created_at DESC
');
$mockupStmt->execute([
    'user_id'      => (int)$user['id'],
    'artwork_file' => $rootFile,
]);
$mockups = $mockupStmt->fetchAll();

$measurement = $meta['measurements'] ?? [];
$unit = (string)($measurement['unit'] ?? $artwork['unit'] ?? 'cm');
$width = $measurement['width'] ?? $artwork['width'] ?? '';
$height = $measurement['height'] ?? $artwork['height'] ?? '';
$depth = $measurement['depth'] ?? $artwork['depth'] ?? '';
$sizeText = trim((string)$width) !== '' && trim((string)$height) !== ''
    ? trim((string)$width . ' x ' . (string)$height . ($depth !== '' && $depth !== null ? ' x ' . (string)$depth : '') . ' ' . $unit)
    : 'No dimensions specified';

$artistProfile = is_array($profile['_artist_profile'] ?? null) ? $profile['_artist_profile'] : ArtistProfile::findForUser((int)$user['id']);
$artistName = trim((string)($artistProfile['artist_name'] ?? ''));
$orientation = $analysis['image']['orientation'] ?? '';
if ($orientation === '' && (float)$width > 0 && (float)$height > 0) {
    $orientation = (float)$width > (float)$height ? 'horizontal' : ((float)$height > (float)$width ? 'vertical' : 'square');
}
$orientation = $orientation ?: 'Not specified';
$package = build_artwork_package_v2($artwork, $analysis, $artistProfile);
$storedTitle = trim((string)($artwork['final_title'] ?? ''));
$storedSubtitle = trim((string)($artwork['subtitle'] ?? ''));
$selectedTitle = ($storedTitle !== '' && !looks_spanish($storedTitle)) ? $storedTitle : $package['titles'][0];
$selectedSubtitle = ($storedSubtitle !== '' && !looks_spanish($storedSubtitle)) ? $storedSubtitle : $package['suggested_subtitle'];
$selectedPublicationDescription = $package['premium_descriptions'][$selectedTitle] ?? trim($package['description']);
$publicationCopy = trim($selectedTitle . ($selectedSubtitle !== '' ? "\n" . $selectedSubtitle : '') . "\n\n" . $selectedPublicationDescription);

$copyIconSvg = '<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
$downloadIconSvg = '<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Permanent Artwork Sheet - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .artwork-sheet {
            display: grid;
            grid-template-columns: minmax(280px, 420px) 1fr;
            gap: 34px;
            align-items: start;
        }

        .root-panel {
            position: sticky;
            top: 92px;
        }

        .root-frame {
            background: var(--surface);
            border: 1px solid var(--line);
            padding: 16px;
            box-shadow: var(--shadow);
            border-radius: var(--radius);
        }

        .root-frame img {
            width: 100%;
            height: auto;
            display: block;
            background: var(--surface-soft);
            border-radius: 2px;
        }

        .sheet-stack {
            display: grid;
            gap: 22px;
        }

        .subtitle-line {
            margin: 0;
            font-family: var(--font-serif);
            font-size: 20px;
            color: var(--accent);
        }

        .title-grid,
        .publishing-grid,
        .marketplace-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .title-card,
        .spec-card {
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 16px;
        }

        .title-card.selected {
            background: #fbf7ef;
            border-color: var(--accent);
        }

        .title-card h3 {
            font-size: 23px;
            margin-bottom: 12px;
        }

        .compact-title-list {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 10px;
        }

        .compact-title-row {
            display: flex;
            flex-direction: column;
            gap: 12px;
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 16px;
            min-height: 100%;
        }

        .compact-title-row.selected {
            background: #fbf7ef;
            border-color: var(--accent);
        }

        .compact-title-main {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            min-width: 0;
        }

        .compact-title-main h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 500;
            overflow-wrap: anywhere;
        }

        .title-option-subtitle {
            margin: -6px 0 0;
            font-family: var(--font-serif);
            color: var(--accent);
            font-size: 16px;
            line-height: 1.25;
        }

        .title-option-description {
            flex: 1;
            margin: 0;
            font-size: 13px;
            line-height: 1.55;
        }

        .selected-label {
            flex: 0 0 auto;
            color: var(--accent);
            border: 1px solid rgba(154, 123, 86, 0.32);
            border-radius: var(--radius);
            padding: 3px 6px;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .mini-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .mini-actions button {
            width: auto;
            margin: 0;
            padding: 8px 10px;
            font-size: 10px;
        }

        .copy-button {
            width: auto;
            margin: 0;
            padding: 8px 10px;
            font-size: 10px;
        }

        .metadata-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .spec-card strong {
            display: block;
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .copy-block {
            white-space: pre-wrap;
            color: var(--ink);
            line-height: 1.7;
        }

        .keyword-wrap,
        .tag-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .keyword-chip,
        .tag-chip {
            display: inline-flex;
            align-items: center;
            border: 1px solid var(--line);
            background: var(--surface);
            color: var(--ink);
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
        }

        .pinterest-panel {
            background: linear-gradient(180deg, var(--surface) 0%, #fbf8f2 100%);
            border-color: rgba(154, 123, 86, 0.32);
        }

        .pinterest-intro {
            max-width: 840px;
            margin: -4px 0 24px;
            color: var(--muted);
        }

        .pin-stack {
            display: grid;
            gap: 18px;
        }

        .pin-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 22px;
            box-shadow: var(--shadow);
        }

        .pin-card-header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 18px;
            padding-bottom: 14px;
            margin-bottom: 18px;
            border-bottom: 1px dashed var(--line);
        }

        .pin-card-header h3 {
            font-size: 25px;
        }

        .pin-fields {
            display: grid;
            grid-template-columns: minmax(220px, .8fr) minmax(0, 1.2fr);
            gap: 16px;
        }

        .pin-field {
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 14px;
        }

        .pin-field.full {
            grid-column: 1 / -1;
        }

        .pin-field label {
            margin-top: 0;
        }

        .pin-field p {
            margin: 0 0 12px;
            color: var(--ink);
        }

        .pin-field textarea {
            min-height: 110px;
            background: var(--surface);
        }

        .pin-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .details-panel {
            border: 1px solid var(--line);
            background: var(--surface);
            border-radius: var(--radius);
            padding: 16px 18px;
            margin-top: 12px;
        }

        .details-panel summary {
            cursor: pointer;
            font-weight: 700;
            color: var(--ink);
        }

        .detail-list {
            display: grid;
            gap: 10px;
            margin-top: 16px;
        }

        .detail-list textarea,
        .prompt-preview {
            min-height: 130px;
            font-family: Consolas, monospace;
            font-size: 12px;
            background: var(--surface-soft);
        }

        .context-list {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
            margin-top: 16px;
        }

        .copy-card {
            display: flex;
            flex-direction: column;
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 16px;
        }

        .copy-card.generated .inline-result {
            border-style: solid;
            border-width: 1px;
            padding: 10px;
            display: block;
            aspect-ratio: auto;
            background: var(--surface-soft);
        }

        .copy-card h3 {
            margin: 0 0 8px;
            font-size: 18px;
        }

        .copy-card p {
            margin: 0 0 12px;
            font-size: 13px;
            line-height: 1.5;
        }

        .copy-card form {
            margin-top: auto;
            width: 100%;
        }

        .copy-card button {
            width: 100%;
            border: 1px solid var(--accent);
            background: var(--accent);
            color: var(--surface);
            padding: 10px 16px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            cursor: pointer;
            border-radius: var(--radius);
            transition: all 0.3s ease;
        }

        .copy-card button:hover {
            background: var(--accent-hover);
            border-color: var(--accent-hover);
        }

        .copy-card button:disabled {
            background: var(--line);
            border-color: var(--line);
            color: var(--muted);
            cursor: not-allowed;
        }

        .inline-result {
            margin: 14px 0;
            background: var(--surface-soft);
            border: 1.5px dashed var(--line);
            border-radius: var(--radius);
            aspect-ratio: 4 / 3;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .copy-card:not(.generated) .inline-result:hover {
            border-color: var(--accent);
            background: var(--accent-light);
        }

        .copy-card:not(.generated) .inline-result svg {
            transition: all 0.3s ease;
        }

        .copy-card:not(.generated) .inline-result:hover svg {
            transform: scale(1.1);
            stroke: var(--accent);
        }

        .inline-result img {
            width: 100%;
            height: auto;
            max-height: 500px;
            object-fit: contain;
            display: block;
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: 2px;
        }

        .inline-loader {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
        }

        .spinner {
            width: 28px;
            height: 28px;
            border: 3px solid var(--line);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin .85s linear infinite;
        }

        .download-icon {
            width: 12px;
            height: 12px;
            border-bottom: 2px solid currentColor;
            display: inline-block;
            position: relative;
        }

        .download-icon::before {
            content: "";
            position: absolute;
            left: 5px;
            top: 1px;
            width: 2px;
            height: 7px;
            background: currentColor;
        }

        .download-icon::after {
            content: "";
            position: absolute;
            left: 2px;
            top: 5px;
            width: 5px;
            height: 5px;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(45deg);
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 1100px) {
            .artwork-sheet,
            .title-grid,
            .compact-title-list,
            .publishing-grid,
            .marketplace-grid,
            .pin-fields {
                grid-template-columns: 1fr;
            }

            .root-panel {
                position: static;
            }

            .metadata-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 700px) {
            .metadata-grid {
                grid-template-columns: 1fr;
            }

            .compact-title-main {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            Permanent artwork sheet: root image, title options, premium descriptions, contextual proposals and generated mockups.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Artwork Details</h1>
                    <p><?= h($selectedSubtitle) ?></p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="core_review.php?id=<?= (int)$id ?>">View Artwork Core</a>
                    <?php if ($rootFile): ?>
                        <a class="button-link" href="mockup_prompt_drafts_review.php?id=<?= (int)$id ?>">Review Final Mockup Prompts</a>
                        <?php if (Auth::isAdmin($user) && defined('LEGACY_MOCKUP_FLOW_ENABLED') && LEGACY_MOCKUP_FLOW_ENABLED): ?>
                            <a class="button-link secondary" href="curated_mockups.php?image=<?= rawurlencode($rootFile) ?>&id=<?= (int)$id ?>&legacy=1">Curated Mockups (Legacy)</a>
                        <?php endif; ?>
                        <a class="button-link secondary" href="analyze_wait.php?image=<?= rawurlencode($rootFile) ?>">Recalculate Analysis</a>
                        <a class="button-link secondary" href="<?= h(media_url($rootFile, true)) ?>">Download Root</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice">Artwork sheet saved.</div>
            <?php endif; ?>

            <?php if ($analysisNeedsRefresh): ?>
                <div class="notice error">
                    This analysis does not match the current minimal schema. Recalculate the analysis before generating new mockups.
                </div>
            <?php endif; ?>

            <section class="artwork-sheet">
                <aside class="root-panel">
                    <div style="background: var(--surface); border: 1px solid var(--line); padding: 16px; border-radius: var(--radius); box-shadow: var(--shadow); margin-bottom: 12px;">
                        <label style="margin-top: 0; font-size: 11px; text-transform: uppercase; font-weight: 600; color: var(--ink); letter-spacing: 0.05em; display: block; margin-bottom: 6px;">Slug / Filename Customizer</label>
                        <input type="text" id="seo_slug_input" class="form-control" value="<?= h($package['seo_slug']) ?>" style="width: 100%; box-sizing: border-box; padding: 8px 10px; font-size: 13px; font-family: monospace; border: 1px solid var(--line); border-radius: var(--radius);">
                        <small style="margin: 4px 0 0 0; color: var(--muted); font-size: 11px; line-height: 1.3; display: block;">Changes here will update all image download names dynamically.</small>
                    </div>

                    <div class="root-frame">
                        <?php if ($rootFile && is_file($rootPath)): ?>
                            <a href="<?= h(media_url($rootFile)) ?>" target="_blank" title="Click to open full size">
                                <img src="<?= h(media_url($rootFile)) ?>" alt="<?= h($package['root_alt']) ?>">
                            </a>
                        <?php else: ?>
                            <div class="empty-state">This artwork does not have a completed root image yet.</div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($rootCandidatesList)): ?>
                        <div style="margin-top: 14px;">
                            <label style="margin-top: 0; font-size: 11px; text-transform: uppercase; font-weight: 600; color: var(--ink); letter-spacing: 0.05em; display: block; margin-bottom: 8px;">Root Artwork Versions</label>
                            <div style="display: grid; grid-template-columns: repeat(<?= min(count($rootCandidatesList), 3) ?>, 1fr); gap: 8px;">
                                <?php
                                $viewLabels = [
                                    'frontal'             => 'Frontal',
                                    'three-quarter-left'  => '3/4 Izq.',
                                    'three-quarter-right' => '3/4 Der.',
                                ];
                                foreach ($rootCandidatesList as $rca): ?>
                                    <?php
                                        $rcaUrl  = media_url($rca['file_name']);
                                        $rcaFile = $rca['file_name'];
                                        $rcaFile = $rcaFile !== '' && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $rcaFile) ? $rcaFile : '';
                                        if ($rcaFile === '') continue;
                                        $rcaLabel = $viewLabels[$rca['view_type']] ?? $rca['view_type'];
                                        $rcaIsSelected = $rca['is_selected'];
                                    ?>
                                    <div style="position: relative; border: <?= $rcaIsSelected ? '2px solid var(--accent)' : '1px solid var(--line)' ?>; border-radius: var(--radius); overflow: hidden; background: var(--surface-soft);">
                                        <a href="<?= h(media_url($rcaFile)) ?>" target="_blank">
                                            <img src="<?= h(media_url($rcaFile)) ?>" alt="<?= h($rcaLabel) ?>" style="width: 100%; display: block; aspect-ratio: 1; object-fit: contain; background: var(--surface-soft);">
                                        </a>
                                        <div style="padding: 4px 6px; font-size: 10px; text-align: center; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; background: var(--surface);">
                                            <?= h($rcaLabel) ?>
                                            <?php if ($rcaIsSelected): ?>
                                                <span style="color: var(--accent);"> ✓</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($rootFile && is_file($rootPath)): ?>
                        <div style="margin-top: 10px; display: flex; justify-content: space-between; align-items: center; padding: 0 4px;">
                            <a id="download_root_link" data-base-file="<?= h($rootFile) ?>" href="<?= h(media_url($rootFile, true)) ?>" title="Download Root Image" style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--accent); text-decoration: none; font-weight: 500;">
                                <span class="download-icon" aria-hidden="true"></span>
                                <span style="margin-left: 6px;">Download Root</span>
                            </a>
                        </div>

                        <details class="details-panel" style="margin-top: 12px; padding: 12px 14px; font-size: 12px;">
                            <summary style="font-weight: 600; font-size: 12px; color: var(--ink);">Caption & Alt</summary>
                            <div style="margin-top: 8px; display: flex; flex-direction: column; gap: 10px;">
                                <div>
                                    <label style="font-size: 9px; text-transform: uppercase; color: var(--muted); display: block; margin-bottom: 2px; font-weight: 700; letter-spacing: 0.05em;">Caption</label>
                                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                        <span style="line-height: 1.4;"><?= h($package['root_caption']) ?></span>
                                        <button class="copy-button secondary" type="button" data-copy="<?= h($package['root_caption']) ?>" aria-label="Copy Caption" style="padding: 4px; display: inline-flex; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                    </div>
                                </div>
                                <div>
                                    <label style="font-size: 9px; text-transform: uppercase; color: var(--muted); display: block; margin-bottom: 2px; font-weight: 700; letter-spacing: 0.05em;">Alt Text</label>
                                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                        <span style="line-height: 1.4;"><?= h($package['root_alt']) ?></span>
                                        <button class="copy-button secondary" type="button" data-copy="<?= h($package['root_alt']) ?>" aria-label="Copy Alt Text" style="padding: 4px; display: inline-flex; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                    </div>
                                </div>
                                <div>
                                    <label style="font-size: 9px; text-transform: uppercase; color: var(--muted); display: block; margin-bottom: 2px; font-weight: 700; letter-spacing: 0.05em;">Suggested Filename</label>
                                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                        <span id="suggested_filename_display" style="font-family: monospace; word-break: break-all; font-size: 11px;"><?= h($package['file_names'][0]) ?></span>
                                        <button class="copy-button secondary" type="button" data-copy="<?= h($package['file_names'][0]) ?>" aria-label="Copy Filename" style="padding: 4px; display: inline-flex; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                    </div>
                                </div>
                            </div>
                        </details>
                     <details class="details-panel" style="margin-top: 12px; padding: 14px 16px;" open>
                         <summary style="font-weight: 600; cursor: pointer; color: var(--ink);">Lectura Curatorial</summary>
                         <div style="margin-top: 10px; line-height: 1.6; font-size: 13px; color: var(--ink);">
                             <p class="copy-block" style="margin: 0; font-style: italic;"><?= h($package['curatorial_reading']) ?></p>
                             <div style="margin-top: 8px; text-align: right;">
                            <button class="copy-button secondary" type="button" data-copy="<?= h($package['curatorial_reading']) ?>" aria-label="Copy Curatorial Reading" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                             </div>
                         </div>
                     </details>
                    <?php endif; ?>

                     <details class="details-panel" style="margin-top: 12px; padding: 14px 16px;" <?= isset($_GET['saved']) ? 'open' : '' ?>>
                        <summary style="font-weight: 600; cursor: pointer; color: var(--ink);">Sheet Metadata</summary>
                        <form method="post" style="margin-top: 12px;">
                            <input type="hidden" name="action" value="save_sheet">
                            <label style="margin-top: 0;">Artwork Title</label>
                            <input type="text" name="final_title" value="<?= h($selectedTitle) ?>">
                            <label>Suggested Subtitle</label>
                            <input type="text" name="subtitle" value="<?= h($selectedSubtitle) ?>">
                            <label>Medium / Technique</label>
                            <input type="text" name="medium" value="<?= h($artwork['medium'] ?? '') ?>" placeholder="Acrylic on canvas">
                            <div class="row" style="grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px;">
                                <div>
                                    <label>Year</label>
                                    <input type="text" name="artwork_year" value="<?= h($artwork['artwork_year'] ?? '') ?>" placeholder="2026">
                                </div>
                                <div>
                                    <label>Series</label>
                                    <input type="text" name="series" value="<?= h($artwork['series'] ?? '') ?>" placeholder="Series name">
                                </div>
                            </div>
                            <label>Description Style Preference</label>
                            <select name="description_style_preference">
                                <option>Balanced</option>
                                <option>More poetic</option>
                                <option>More direct</option>
                                <option>More commercial</option>
                                <option>More minimal</option>
                                <option>More emotional</option>
                                <option>More concise</option>
                            </select>
                            <button type="submit" style="margin-top: 12px; width: 100%;">Save Artwork Sheet</button>
                        </form>
                    </details>
                </aside>
                     <div class="sheet-stack">
                    <!-- SECCIÓN 0: MOCKUPS POR DEFECTO Y OBRA ROOT -->
                    <section class="panel">
                        <div class="section-heading" style="display: flex; justify-content: space-between; align-items: center; width: 100%; gap: 12px;">
                            <div>
                                <h2>Section 0: Default Mockups and Root Artwork</h2>
                                <p>Root image and mockup metadata for filenames and simple captions</p>
                            </div>
                            <button class="copy-button secondary" type="button" id="copy_section_0" aria-label="Copy Section 0" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr; gap: 24px; margin-top: 16px;">
                            <!-- Obra Root -->
                            <article class="pin-card" style="display: grid; grid-template-columns: 180px 1fr; gap: 20px; align-items: start; padding: 20px; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface);">
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                                    <div class="root-frame" style="padding: 6px; width: 100%; border: 1px solid var(--line); border-radius: 4px; background: var(--surface-soft);">
                                        <?php if ($rootFile && is_file($rootPath)): ?>
                                            <a href="<?= h(media_url($rootFile)) ?>" target="_blank" title="Click to open full size">
                                                <img src="<?= h(media_url($rootFile)) ?>" alt="<?= h($package['root_alt']) ?>" style="width: 100%; height: auto; display: block; border-radius: 2px;">
                                            </a>
                                        <?php else: ?>
                                            <div class="empty-state" style="font-size: 11px; padding: 20px 0;">No image</div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div style="display: flex; flex-direction: column; gap: 10px; width: 100%;">
                                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px; border-bottom: 1px dashed var(--line); padding-bottom: 4px;">
                                        <h3 style="font-size: 16px; margin: 0;">Root Artwork</h3>
                                        <span style="font-size: 10px; text-transform: uppercase; color: var(--muted); font-weight: 700; letter-spacing: 0.05em;">Main Image</span>
                                    </div>

                                    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 12px;">
                                        <div class="pin-field" style="padding: 8px 10px; background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius);">
                                            <label style="font-size: 9px; font-weight: 700; text-transform: uppercase; color: var(--muted); margin-bottom: 2px; display: block;">Filename</label>
                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                                <span class="seo-root-filename" style="font-family: monospace; font-size: 11px; font-weight: 500;"><?= h($package['file_names'][0]) ?></span>
                                                <button class="copy-button secondary" type="button" data-copy="<?= h($package['file_names'][0]) ?>" id="copy_root_filename" style="padding: 4px; display: inline-flex; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                            </div>
                                        </div>

                                        <div class="pin-field" style="padding: 8px 10px; background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius);">
                                            <label style="font-size: 9px; font-weight: 700; text-transform: uppercase; color: var(--muted); margin-bottom: 2px; display: block;">Alt Text</label>
                                            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                                <span class="seo-root-alt" style="line-height: 1.4;"><?= h($package['root_alt']) ?></span>
                                                <button class="copy-button secondary" type="button" data-copy="<?= h($package['root_alt']) ?>" style="padding: 4px; display: inline-flex; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                            </div>
                                        </div>

                                        <div class="pin-field" style="padding: 8px 10px; background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius);">
                                            <label style="font-size: 9px; font-weight: 700; text-transform: uppercase; color: var(--muted); margin-bottom: 2px; display: block;">Caption</label>
                                            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                                <span class="seo-root-caption" style="line-height: 1.4;"><?= h($package['root_caption']) ?></span>
                                                <button class="copy-button secondary" type="button" data-copy="<?= h($package['root_caption']) ?>" style="padding: 4px; display: inline-flex; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </article>

                            <!-- 4 Mockups por defecto -->
                            <?php foreach ($contexts as $i => $ctx): ?>
                                <?php
                                    $ctxId = $ctx['id'] ?? ('ctx_' . ($i + 1));
                                    $ctxSlug = slugify($ctx['name']);
                                    
                                    // Find mockup
                                    $existingMockup = null;
                                    foreach ($mockups as $m) {
                                        if ((string)$m['context_id'] === (string)$ctxId || (string)$m['context_id'] === (string)($i + 1)) {
                                            $existingMockup = $m;
                                            break;
                                        }
                                    }

                                    $mAlt = 'Mockup of the artwork "' . $selectedTitle . '" presented in a ' . strtolower($ctx['name']) . ' environment.';
                                    $mCaption = '"' . $selectedTitle . '" mockup in ' . $ctx['name'] . '.';

                                    $expectedFilename = $package['seo_slug'] . '-mockup-' . $ctxSlug . '.jpg';
                                ?>
                                <article class="pin-card mockup-card-container <?= $existingMockup ? 'generated' : '' ?>" id="mockup-card-<?= h($ctxId) ?>" data-context-name="<?= h($ctx['name']) ?>" data-context-id="<?= h($ctxId) ?>" style="display: grid; grid-template-columns: 180px 1fr; gap: 20px; align-items: start; padding: 20px; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface);">
                                    <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                                        <div class="root-frame inline-result-box" style="padding: 6px; width: 100%; border: 1px solid var(--line); border-radius: 4px; background: var(--surface-soft); aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center;">
                                            <?php if ($existingMockup): ?>
                                                <?php
                                                    $mFile = basename((string)$existingMockup['mockup_file']);
                                                    $mUrl = 'media.php?file=' . rawurlencode($mFile);
                                                ?>
                                                <a class="inline-thumb" href="<?= h($mUrl) ?>" target="_blank" title="Click to open full size" style="width: 100%;">
                                                    <img src="<?= h($mUrl) ?>" alt="<?= h($ctx['name']) ?>" style="width: 100%; height: auto; display: block; border-radius: 2px;">
                                                </a>
                                            <?php else: ?>
                                                <svg viewBox="0 0 24 24" width="32" height="32" stroke="var(--muted)" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.5;">
                                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                                    <polyline points="21 15 16 10 5 21"></polyline>
                                                </svg>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="mockup-action-container" style="display: flex; flex-direction: column; gap: 6px; width: 100%; margin-top: 4px;">
                                            <!-- Actions for generated state -->
                                            <div class="generated-actions" style="<?= $existingMockup ? '' : 'display: none;' ?>">
                                                <a href="<?= $existingMockup ? 'media.php?file=' . rawurlencode(basename((string)$existingMockup['mockup_file'])) . '&download=1' : '#' ?>" class="download-mockup-link button secondary" data-base-file="<?= $existingMockup ? h(basename((string)$existingMockup['mockup_file'])) : '' ?>" data-context="<?= h($ctxSlug) ?>" style="font-size: 11px; text-align: center; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; width: 100%; box-sizing: border-box; margin-bottom: 6px;">
                                                    <?= $downloadIconSvg ?> Download
                                                </a>
                                                <button type="button" class="btn-delete-mockup button secondary danger" data-mockup-id="<?= $existingMockup ? h($existingMockup['id']) : '' ?>" style="font-size: 11px; margin: 0; padding: 6px 10px; width: 100%;">
                                                    Delete
                                                </button>
                                            </div>
                                            
                                            <!-- Form for ungenerated state -->
                                            <div class="ungenerated-form" style="<?= $existingMockup ? 'display: none;' : '' ?>">
                                                 <span style="font-size: 11px; color: var(--muted); display: block; text-align: center; padding: 6px 0;">Use "Review Final Mockup Prompts" at the top to generate</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div style="display: flex; flex-direction: column; gap: 10px; width: 100%;">
                                        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px; border-bottom: 1px dashed var(--line); padding-bottom: 4px;">
                                            <h3 style="font-size: 16px; margin: 0;"><?= h($ctx['name']) ?></h3>
                                            <span style="font-size: 10px; text-transform: uppercase; color: var(--muted); font-weight: 700; letter-spacing: 0.05em;">Mockup <?= $i + 1 ?></span>
                                        </div>

                                        <div style="display: flex; flex-direction: column; gap: 8px; font-size: 12px;">
                                            <div class="pin-field" style="padding: 8px 10px; background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius);">
                                                <label style="font-size: 9px; font-weight: 700; text-transform: uppercase; color: var(--muted); margin-bottom: 2px; display: block;">Filename</label>
                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                                    <span class="seo-mockup-filename" data-context-slug="<?= h($ctxSlug) ?>" style="font-family: monospace; font-size: 11px; font-weight: 500;"><?= h($expectedFilename) ?></span>
                                                    <button class="copy-button secondary copy-mockup-filename-btn" type="button" data-copy="<?= h($expectedFilename) ?>" style="padding: 4px; display: inline-flex; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                                </div>
                                            </div>

                                            <div class="pin-field" style="padding: 8px 10px; background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius);">
                                                <label style="font-size: 9px; font-weight: 700; text-transform: uppercase; color: var(--muted); margin-bottom: 2px; display: block;">Alt Text</label>
                                                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                                    <span class="mockup-alt-text" style="line-height: 1.4;"><?= h($mAlt) ?></span>
                                                    <button class="copy-button secondary" type="button" data-copy="<?= h($mAlt) ?>" style="padding: 4px; display: inline-flex; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                                </div>
                                            </div>

                                            <div class="pin-field" style="padding: 8px 10px; background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius);">
                                                <label style="font-size: 9px; font-weight: 700; text-transform: uppercase; color: var(--muted); margin-bottom: 2px; display: block;">Caption</label>
                                                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                                    <span class="mockup-caption-text" style="line-height: 1.4;"><?= h($mCaption) ?></span>
                                                    <button class="copy-button secondary" type="button" data-copy="<?= h($mCaption) ?>" style="padding: 4px; display: inline-flex; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <!-- SECCIÓN 1: TÍTULOS Y SUBTÍTULOS SUGERIDOS -->
                    <section class="panel">
                        <div class="section-heading" style="display: flex; justify-content: space-between; align-items: center; width: 100%; gap: 12px;">
                            <div>
                                <h2>Section 1: Suggested Titles and Subtitles</h2>
                                <p>Curatorial and commercial proposals</p>
                            </div>
                            <button class="copy-button secondary" type="button" id="copy_section_1" aria-label="Copy Section 1" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-top: 16px;" class="title-grid-unified">
                            <?php foreach ($package['titles'] as $idx => $t): ?>
                                <?php
                                    $sub = $package['title_subtitles'][$t] ?? '';
                                    $label = 'Option ' . ($idx + 1);
                                    $titleCopy = "Title: " . $t . ($sub !== '' ? "\nSubtitle: " . $sub : '');
                                ?>
                                <article class="compact-title-row <?= $t === $selectedTitle ? 'selected' : '' ?>" style="display: flex; flex-direction: column; gap: 10px; background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius); padding: 16px;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                                        <span class="selected-label" style="font-size: 8px; background: var(--surface); color: var(--accent);"><?= h($label) ?></span>
                                        <?php if ($t === $selectedTitle): ?>
                                            <span class="selected-label" style="font-size: 8px; background: var(--accent-light); color: var(--accent);">Seleccionado</span>
                                        <?php endif; ?>
                                    </div>
                                    <h3 style="font-size: 18px; font-weight: 600; margin: 4px 0 0;"><?= h($t) ?></h3>
                                    <?php if ($sub !== ''): ?>
                                        <p class="title-option-subtitle" style="font-size: 14px; color: var(--accent); margin: 0;"><?= h($sub) ?></p>
                                    <?php endif; ?>
                                    
                                    <div style="margin-top: auto; display: flex; gap: 8px; align-items: center; padding-top: 10px; border-top: 1px dashed var(--line);">
                                        <button class="copy-button secondary" type="button" data-copy="<?= h($titleCopy) ?>" aria-label="Copy" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                        <form method="post" style="margin: 0; flex: 1;">
                                            <input type="hidden" name="action" value="save_sheet">
                                            <input type="hidden" name="final_title" value="<?= h($t) ?>">
                                            <input type="hidden" name="subtitle" value="<?= h($sub) ?>">
                                            <input type="hidden" name="medium" value="<?= h($artwork['medium'] ?? '') ?>">
                                            <input type="hidden" name="artwork_year" value="<?= h($artwork['artwork_year'] ?? '') ?>">
                                            <input type="hidden" name="series" value="<?= h($artwork['series'] ?? '') ?>">
                                            <button type="submit" class="button" style="font-size: 10px; padding: 6px 10px; width: 100%;"><?= $t === $selectedTitle ? 'Seleccionado' : 'Seleccionar' ?></button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <!-- SECCIÓN 2: DESCRIPCIONES PREMIUM -->
                    <section class="panel">
                        <div class="section-heading" style="display: flex; justify-content: space-between; align-items: center; width: 100%; gap: 12px;">
                            <div>
                                <h2>Section 2: Premium Descriptions</h2>
                                <p>Artistic and curatorial descriptions associated with each title</p>
                            </div>
                            <button class="copy-button secondary" type="button" id="copy_section_2" aria-label="Copy Section 2" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 20px; margin-top: 16px;">
                            <?php
                                $idx = array_search($selectedTitle, $package['titles']);
                                if ($idx === false) {
                                    $idx = 0;
                                }
                                $desc = $package['premium_descriptions'][$selectedTitle] ?? '';
                                $label = 'Description Option ' . ($idx + 1);
                                $descCopy = "[" . $label . " para: " . $selectedTitle . "]\n" . $desc;
                            ?>
                            <article class="premium-desc-block" style="background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius); padding: 18px; display: flex; flex-direction: column; gap: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <strong style="font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em;"><?= h($label) ?> — para "<?= h($selectedTitle) ?>"</strong>
                                    <button class="copy-button secondary" type="button" data-copy="<?= h($descCopy) ?>" aria-label="Copy" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                </div>
                                <p class="copy-block" style="font-size: 14px; line-height: 1.6; margin: 0; color: var(--ink);"><?= h($desc) ?></p>
                            </article>
                        </div>
                    </section>

                    <!-- Raw AI Analysis Panel -->
                    <?php
                    $rawAnalysisJson = '';
                    if (is_array($dbAnalysis) && !empty($dbAnalysis['analysis_json'])) {
                        $rawAnalysisJson = (string)$dbAnalysis['analysis_json'];
                    } elseif ($rootBase && is_file(ANALYSIS_DIR . DIRECTORY_SEPARATOR . $rootBase . '.analysis.json')) {
                        $rawAnalysisJson = (string)file_get_contents(ANALYSIS_DIR . DIRECTORY_SEPARATOR . $rootBase . '.analysis.json');
                    }
                    if ($rawAnalysisJson !== ''):
                        $decodedJson = json_decode($rawAnalysisJson, true);
                        if (is_array($decodedJson)) {
                            $rawAnalysisJson = json_encode($decodedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                    ?>
                        <details class="details-panel" style="margin-top: 16px;">
                            <summary style="font-weight: 600; cursor: pointer; color: var(--ink);">View Raw AI Analysis (JSON)</summary>
                            <div style="margin-top: 12px;">
                                <pre style="background: var(--surface-soft); border: 1px solid var(--line); padding: 12px; border-radius: var(--radius); overflow-x: auto; font-family: monospace; font-size: 11px; margin: 0; max-height: 400px; color: var(--ink);"><code class="json"><?= h($rawAnalysisJson) ?></code></pre>
                            </div>
                        </details>
                    <?php endif; ?>
             <script>
    const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;

    const seoInput = document.getElementById('seo_slug_input');
    
    function updateDownloadLinks() {
        if (!seoInput) return;
        const slug = seoInput.value.trim().toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/-+/g, '-');
        
        const rootLink = document.getElementById('download_root_link');
        if (rootLink) {
            const baseFile = rootLink.getAttribute('data-base-file');
            rootLink.href = `media.php?file=${encodeURIComponent(baseFile)}&download=1&name=${encodeURIComponent(slug + '-root-artwork')}`;
        }
        
        document.querySelectorAll('.download-mockup-link').forEach((link) => {
            const baseFile = link.getAttribute('data-base-file');
            const context = link.getAttribute('data-context');
            if (baseFile) {
                link.href = `media.php?file=${encodeURIComponent(baseFile)}&download=1&name=${encodeURIComponent(slug + '-mockup-' + context)}`;
            }
        });

        const filenameDisplay = document.getElementById('suggested_filename_display');
        if (filenameDisplay) {
            filenameDisplay.textContent = slug + '-root-artwork.jpg';
        }

        const seoRootFilename = document.querySelector('.seo-root-filename');
        if (seoRootFilename) {
            seoRootFilename.textContent = slug + '-root-artwork.jpg';
        }

        document.querySelectorAll('.seo-mockup-filename').forEach((span) => {
            const ctxSlug = span.getAttribute('data-context-slug');
            span.textContent = slug + '-mockup-' + ctxSlug + '.jpg';
        });
    }
    
    if (seoInput) {
        seoInput.addEventListener('input', updateDownloadLinks);
        updateDownloadLinks();
    }

    // Individual copy buttons
    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            const original = button.innerHTML;
            try {
                await navigator.clipboard.writeText(button.dataset.copy || '');
                button.innerHTML = 'Copied';
                setTimeout(() => button.innerHTML = original, 1200);
            } catch (error) {
                button.innerHTML = 'Copy failed';
                setTimeout(() => button.innerHTML = original, 1200);
            }
        });
    });

    // Filenames copy buttons (retrieve sibling span text dynamically)
    document.querySelectorAll('.copy-mockup-filename-btn').forEach((button) => {
        button.addEventListener('click', async (e) => {
            e.stopPropagation();
            const span = button.previousElementSibling;
            if (span) {
                const original = button.innerHTML;
                try {
                    await navigator.clipboard.writeText(span.textContent.trim());
                    button.innerHTML = 'Copied';
                    setTimeout(() => button.innerHTML = original, 1200);
                } catch (error) {
                    button.innerHTML = 'Copy failed';
                    setTimeout(() => button.innerHTML = original, 1200);
                }
            }
        });
    });

    document.getElementById('copy_root_filename')?.addEventListener('click', async function(e) {
        e.stopPropagation();
        const span = this.previousElementSibling;
        if (span) {
            const original = this.innerHTML;
            try {
                await navigator.clipboard.writeText(span.textContent.trim());
                this.innerHTML = 'Copied';
                setTimeout(() => this.innerHTML = original, 1200);
            } catch (error) {
                this.innerHTML = 'Copy failed';
                setTimeout(() => this.innerHTML = original, 1200);
            }
        }
    });

    // Unified report sections copy functions
    function getSection0Text() {
        let text = `[Root Artwork]\n`;
        text += `File: ${document.querySelector('.seo-root-filename')?.textContent || ''}\n`;
        text += `Alt: ${document.querySelector('.seo-root-alt')?.textContent || ''}\n`;
        text += `Caption: ${document.querySelector('.seo-root-caption')?.textContent || ''}\n\n`;

        document.querySelectorAll('.mockup-card-container').forEach((card, idx) => {
            const name = card.getAttribute('data-context-name') || `Mockup ${idx + 1}`;
            text += `[Mockup ${idx + 1}: ${name}]\n`;
            text += `File: ${card.querySelector('.seo-mockup-filename')?.textContent || ''}\n`;
            text += `Alt: ${card.querySelector('.mockup-alt-text')?.textContent || ''}\n`;
            text += `Caption: ${card.querySelector('.mockup-caption-text')?.textContent || ''}\n\n`;
        });
        return text.trim();
    }

    function getSection1Text() {
        let text = '';
        document.querySelectorAll('.title-grid-unified article').forEach((card, idx) => {
            const labels = card.querySelectorAll('.selected-label');
            const label = labels[0]?.textContent || `Option ${idx + 1}`;
            const title = card.querySelector('h3')?.textContent || '';
            const sub = card.querySelector('.title-option-subtitle')?.textContent || '';
            text += `${label}:\n`;
            text += `Title: ${title}\n`;
            if (sub) {
                text += `Subtitle: ${sub}\n`;
            }
            text += `\n`;
        });
        return text.trim();
    }

    function getSection2Text() {
        let text = '';
        document.querySelectorAll('.premium-desc-block').forEach((card) => {
            const titleLabel = card.querySelector('strong')?.textContent || '';
            const desc = card.querySelector('.copy-block')?.textContent || '';
            text += `${titleLabel}\n${desc}\n\n`;
        });
        return text.trim();
    }

    const copySection = async (button, getTextFn) => {
        const original = button.innerHTML;
        try {
            await navigator.clipboard.writeText(getTextFn());
            button.innerHTML = 'Copied';
            setTimeout(() => button.innerHTML = original, 1200);
        } catch (error) {
            button.innerHTML = 'Copy failed';
            setTimeout(() => button.innerHTML = original, 1200);
        }
    };

    document.getElementById('copy_section_0')?.addEventListener('click', function() {
        copySection(this, getSection0Text);
    });
    document.getElementById('copy_section_1')?.addEventListener('click', function() {
        copySection(this, getSection1Text);
    });
    document.getElementById('copy_section_2')?.addEventListener('click', function() {
        copySection(this, getSection2Text);
    });

    // AJAX mockup generation listener
    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('.inline-mockup-form');
        if (!form) return;
        event.preventDefault();

        const card = form.closest('.mockup-card-container');
        if (!card) return;

        const resultBox = card.querySelector('.inline-result-box');
        const button = form.querySelector('button[type="submit"]');
        const originalHtml = button.innerHTML;

        card.classList.remove('generated');
        resultBox.innerHTML = `
            <div class="inline-loader" style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;">
                <div class="spinner" style="width: 24px; height: 24px;" aria-hidden="true"></div>
            </div>
        `;
        button.disabled = true;
        button.innerHTML = 'Generating...';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const rawText = await response.text();
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseError) {
                const readable = rawText
                    .replace(/<br\s*\/?>/gi, '\n')
                    .replace(/<[^>]+>/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim();
                throw new Error(readable || 'The server returned an invalid response.');
            }

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'No se pudo generar el mockup.');
            }

            // Successfully generated!
            card.classList.add('generated');
            
            // Update image preview
            const ctxName = card.querySelector('h3')?.textContent || 'Mockup';
            resultBox.innerHTML = `
                <a class="inline-thumb" href="${escapeAttribute(data.image_url)}" target="_blank" title="Click to open full size" style="width: 100%;">
                    <img src="${escapeAttribute(data.image_url)}" alt="${escapeAttribute(ctxName)}" style="width: 100%; height: auto; display: block; border-radius: 2px;">
                </a>
            `;

            // Update action containers
            const genActions = card.querySelector('.generated-actions');
            const ungenForm = card.querySelector('.ungenerated-form');
            if (genActions) genActions.style.display = 'block';
            if (ungenForm) ungenForm.style.display = 'none';

            // Update download & delete button attributes
            const downloadLink = card.querySelector('.download-mockup-link');
            if (downloadLink) {
                downloadLink.href = data.download_url;
                downloadLink.setAttribute('data-base-file', data.mockup_file);
            }
            const deleteBtn = card.querySelector('.btn-delete-mockup');
            if (deleteBtn) {
                deleteBtn.setAttribute('data-mockup-id', data.mockup_id || data.id);
            }

            // Update filenames display
            updateDownloadLinks();

        } catch (error) {
            resultBox.innerHTML = `<div class="inline-status" style="color: var(--danger); font-size: 11px; padding: 10px; text-align: center;">Error: ${escapeHtml(error.message)}</div>`;
            button.innerHTML = originalHtml;
            button.disabled = false;
        } finally {
            button.disabled = false;
        }
    });

    // AJAX mockup deletion listener
    document.addEventListener('click', async (event) => {
        const deleteBtn = event.target.closest('.btn-delete-mockup');
        if (!deleteBtn) return;

        if (!confirm('Are you sure you want to delete this mockup?')) {
            return;
        }

        const mockupId = deleteBtn.getAttribute('data-mockup-id');
        const card = deleteBtn.closest('.mockup-card-container');
        
        deleteBtn.disabled = true;

        try {
            const response = await fetch('delete_mockup.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'mockup_id=' + encodeURIComponent(mockupId)
            });

            const data = await response.json();
            if (data.ok) {
                if (card) {
                    card.classList.remove('generated');
                    
                    const resultBox = card.querySelector('.inline-result-box');
                    if (resultBox) {
                        resultBox.innerHTML = `
                            <svg viewBox="0 0 24 24" width="32" height="32" stroke="var(--muted)" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.5;">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                <polyline points="21 15 16 10 5 21"></polyline>
                            </svg>
                        `;
                    }

                    const genActions = card.querySelector('.generated-actions');
                    const ungenForm = card.querySelector('.ungenerated-form');
                    if (genActions) genActions.style.display = 'none';
                    if (ungenForm) ungenForm.style.display = 'block';

                    const formSubmitBtn = card.querySelector('.inline-mockup-form button[type="submit"]');
                    if (formSubmitBtn) {
                        formSubmitBtn.disabled = false;
                        formSubmitBtn.innerHTML = 'Generar Mockup';
                    }
                }
            } else {
                alert('Error: ' + (data.error || 'No se pudo eliminar el mockup.'));
                deleteBtn.disabled = false;
            }
        } catch (err) {
            alert('Error de red al intentar eliminar.');
            deleteBtn.disabled = false;
        }
    });

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function escapeAttribute(value) {
        return escapeHtml(value);
    }
</script>
</body>
</html>
