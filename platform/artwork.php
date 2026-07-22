<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Support/ArtworkAnalysisV2.php';
require_once __DIR__ . '/app/Support/ArtworkOriginalityChecker.php';
require_once __DIR__ . '/app/Support/DescriptionDiversityEngine.php';
require_once __DIR__ . '/app/Services/ArtworkAnalysisV2Service.php';
require_once __DIR__ . '/app/Video/VideoStudioRepository.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$pdo = Database::connection();
ArtworkSeries::ensureSchema($pdo);
$id = max(0, (int)($_GET['id'] ?? 0));
$bilingualExperiment = (string)($_GET['bilingual_experiment'] ?? '') === '1';
$metadataErrorMessage = trim((string)($_GET['metadata_error'] ?? ''));

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

function artwork_result_file_available(?string $file): bool
{
    $file = basename((string)$file);
    if ($file === '') {
        return false;
    }

    $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
    if (is_file($path)) {
        return true;
    }

    return StorageService::isGcsActive()
        && StorageService::downloadFile('results/' . $file, $path)
        && is_file($path);
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
    header('Location: root_album.php?artwork_error=' . rawurlencode('missing_artwork_id'));
    exit;
}

function artwork_v2_draft_for_image(string $imageFile): ?array
{
    $imageFile = basename($imageFile);
    if ($imageFile === '') return null;
    $matches = [];
    $files = array_merge(
        glob(__DIR__ . '/storage/artwork_analysis_v2_drafts/*.json') ?: [],
        glob(__DIR__ . '/tmp/drafts/*.json') ?: []
    );
    foreach ($files as $file) {
        if (str_ends_with($file, '.invalid.json')) continue;
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || ($data['schema_version'] ?? '') !== ArtworkAnalysisV2::SCHEMA_VERSION) continue;
        if (basename((string)($data['source']['image_file'] ?? '')) !== $imageFile) continue;
        $matches[] = ['file'=>$file, 'mtime'=>(int)@filemtime($file), 'data'=>$data];
    }
    if (!$matches) return null;
    usort($matches, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
    return $matches[0];
}

function artwork_apply_v2_metadata(PDO $pdo, int $artworkId, int $userId, array $draft): void
{
    (new ArtworkSheetService($pdo))->applyAnalysisV2Draft($artworkId, $userId, $draft);
}

if ($isAdmin) {
    $stmt = $pdo->prepare('
        SELECT *
        FROM artworks
        WHERE id = :id
        LIMIT 1
    ');
    $stmt->execute([
        'id' => $id,
    ]);
} else {
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
}
$artwork = $stmt->fetch();

if (!is_array($artwork)) {
    header('Location: root_album.php?artwork_error=' . rawurlencode('artwork_not_found'));
    exit;
}

$artworkOwnerId = (int)($artwork['user_id'] ?? 0);
if ($artworkOwnerId <= 0) {
    $artworkOwnerId = (int)$user['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'select_root_candidate') {
    $candidateId = max(0, (int)($_POST['candidate_id'] ?? 0));
    $candidateFile = basename((string)($_POST['candidate_file'] ?? ''));
    if (artwork_result_file_available($candidateFile)) {
        Database::withBusyRetry(function () use ($pdo, $id, $artworkOwnerId, $candidateId, $candidateFile): void {
            $pdo->prepare('UPDATE root_artwork_candidates SET is_selected = 0 WHERE artwork_id = :artwork_id')
                ->execute(['artwork_id' => $id]);
            if ($candidateId > 0) {
                $pdo->prepare('UPDATE root_artwork_candidates SET is_selected = 1 WHERE id = :id AND artwork_id = :artwork_id')
                    ->execute(['id' => $candidateId, 'artwork_id' => $id]);
            }
            $pdo->prepare('UPDATE artworks SET root_file = :root_file, updated_at = :updated_at WHERE id = :id AND user_id = :user_id')
                ->execute([
                    'root_file' => $candidateFile,
                    'updated_at' => date('c'),
                    'id' => $id,
                    'user_id' => $artworkOwnerId,
                ]);
        }, 12);
        if (ProviderSettings::isRealMode() && ProviderSettings::allowRealApi() && ProviderSettings::imageProvider() === 'gemini') {
            try {
                $artworkForV2=$artwork;$artworkForV2['root_file']=$candidateFile;
                $generated=(new ArtworkAnalysisV2Service(new GeminiImageClient()))->generateDraft($artworkForV2,ArtistProfile::findForUser($artworkOwnerId),RESULTS_DIR.DIRECTORY_SEPARATOR.$candidateFile,'Automatic v2 analysis after root selection.');
                artwork_apply_v2_metadata($pdo,$id,$artworkOwnerId,(array)$generated['draft']);
                header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&root_selected=1&v2_generated=1');exit;
            } catch (Throwable $v2Error) {
                header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&root_selected=1&metadata_error=' . rawurlencode($v2Error->getMessage()));exit;
            }
        }
    }

    header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&root_selected=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_series') {
    $rawSeriesId = trim((string)($_POST['series_id'] ?? ''));
    ArtworkSeries::assignArtwork($pdo, $artworkOwnerId, $id, $rawSeriesId === '' ? null : (int)$rawSeriesId);
    header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&series_updated=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_artwork_title') {
    try {
        (new ArtworkSheetService($pdo))->saveArtworkTitle(
            $id,
            $artworkOwnerId,
            (string)($_POST['title'] ?? '')
        );
        header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&title_saved=1');
    } catch (Throwable $e) {
        header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&title_error=' . rawurlencode($e->getMessage()));
    }
    exit;
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
        'user_id' => $artworkOwnerId,
    ]);

    header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&saved=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_artwork_metadata') {
    $sheetService = new ArtworkSheetService($pdo);
    $sheet = $sheetService->sheetForArtwork($id, $artworkOwnerId);
    $longTail = array_values(array_filter(array_map(
        static fn($item): string => trim((string)$item),
        preg_split('/[\n,;]+/', (string)($_POST['long_tail_terms'] ?? '')) ?: []
    )));
    $generatedJson = json_decode((string)($sheet['generated_json'] ?? ''), true);
    $generatedJson = is_array($generatedJson) ? $generatedJson : [];
    $generatedJson['long_tail_terms'] = $longTail;

    $pdo->prepare('
        UPDATE artwork_sheets
        SET title = :title,
            subtitle = :subtitle,
            description = :description,
            short_description = :short_description,
            keywords = :keywords,
            tags = :tags,
            alt_text = :alt_text,
            caption = :caption,
            generated_json = :generated_json,
            updated_at = :updated_at
        WHERE id = :id
        AND user_id = :user_id
    ')->execute([
        'title' => trim((string)($_POST['title'] ?? '')),
        'subtitle' => trim((string)($_POST['subtitle'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'short_description' => trim((string)($_POST['short_description'] ?? '')),
        'keywords' => trim((string)($_POST['keywords'] ?? '')),
        'tags' => trim((string)($_POST['tags'] ?? '')),
        'alt_text' => trim((string)($_POST['alt_text'] ?? '')),
        'caption' => trim((string)($_POST['caption'] ?? '')),
        'generated_json' => json_encode($generatedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'updated_at' => date('c'),
        'id' => (int)$sheet['id'],
        'user_id' => $artworkOwnerId,
    ]);

    $pdo->prepare('
        UPDATE artworks
        SET final_title = :final_title,
            subtitle = :subtitle,
            medium = :medium,
            artwork_year = :artwork_year,
            series = :series,
            updated_at = :updated_at
        WHERE id = :id
        AND user_id = :user_id
    ')->execute([
        'final_title' => trim((string)($_POST['title'] ?? '')),
        'subtitle' => trim((string)($_POST['subtitle'] ?? '')),
        'medium' => trim((string)($_POST['medium'] ?? '')),
        'artwork_year' => trim((string)($_POST['artwork_year'] ?? '')),
        'series' => trim((string)($_POST['series'] ?? '')),
        'updated_at' => date('c'),
        'id' => $id,
        'user_id' => $artworkOwnerId,
    ]);
    (new PublicationService($pdo))->syncInheritedFromSheet((int)$sheet['id'], $artworkOwnerId);

    header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&metadata_saved=1');
    exit;
}

$rootFile = basename((string)($artwork['root_file'] ?? ''));
$rootPath = $rootFile ? RESULTS_DIR . DIRECTORY_SEPARATOR . $rootFile : '';
$rootFileAvailable = artwork_result_file_available($rootFile);
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

if (!function_exists('artwork_latest_root_view_job_candidates')) {
    /**
     * @return array<int,array{file_name:string,view_type:string,job_id:string}>
     */
    function artwork_latest_root_view_job_candidates(int $artworkId): array
    {
        $jobDirs = glob(__DIR__ . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'complete_root_views_' . $artworkId . '_*', GLOB_ONLYDIR) ?: [];
        if (!$jobDirs) {
            return [];
        }

        usort($jobDirs, static function (string $a, string $b): int {
            $aStatus = $a . DIRECTORY_SEPARATOR . 'status.json';
            $bStatus = $b . DIRECTORY_SEPARATOR . 'status.json';
            return (filemtime($bStatus) ?: filemtime($b) ?: 0) <=> (filemtime($aStatus) ?: filemtime($a) ?: 0);
        });

        $viewMap = [
            1 => 'frontal',
            2 => 'three-quarter-left',
            3 => 'three-quarter-right',
        ];

        foreach ($jobDirs as $jobDir) {
            $statusPath = $jobDir . DIRECTORY_SEPARATOR . 'status.json';
            if (!is_file($statusPath)) {
                continue;
            }
            $status = json_decode((string)file_get_contents($statusPath), true);
            if (!is_array($status) || (int)($status['artwork_id'] ?? 0) !== $artworkId || (string)($status['status'] ?? '') !== 'done') {
                continue;
            }

            $candidates = [];
            foreach (array_values((array)($status['candidates'] ?? [])) as $index => $candidate) {
                $file = basename((string)$candidate);
                $version = $index + 1;
                if (preg_match('/_v(\d+)\.(?:png|jpe?g|webp)$/i', $file, $matches) === 1) {
                    $version = (int)$matches[1];
                }
                if ($file !== '' && isset($viewMap[$version]) && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
                    $candidates[] = [
                        'file_name' => $file,
                        'view_type' => $viewMap[$version],
                        'job_id' => basename($jobDir),
                    ];
                }
            }

            if ($candidates) {
                return $candidates;
            }
        }

        return [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_v2_artwork_draft') {
    try {
        $draftMatch = artwork_v2_draft_for_image((string)($artwork['root_file'] ?? ''));
        if (!$draftMatch) throw new RuntimeException('No valid v2 draft exists for the selected root artwork.');
        artwork_apply_v2_metadata($pdo, $id, $artworkOwnerId, (array)$draftMatch['data']);
        header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&v2_applied=1#artwork-metadata');
        exit;
    } catch (Throwable $e) {
        header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&metadata_error=' . rawurlencode($e->getMessage()) . '#artwork-metadata');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_v2_artwork_draft') {
    try {
        if (!ProviderSettings::isRealMode() || !ProviderSettings::allowRealApi() || ProviderSettings::imageProvider() !== 'gemini') throw new RuntimeException('Gemini real analysis is not enabled in this environment.');
        $imageFile = basename((string)($artwork['root_file'] ?? ''));
        $imagePath = $imageFile !== '' ? RESULTS_DIR . DIRECTORY_SEPARATOR . $imageFile : '';
        if ($imagePath === '' || !is_file($imagePath)) throw new RuntimeException('Selected root artwork image was not found.');
        $profileForV2 = ArtistProfile::findForUser($artworkOwnerId);
        $sheetForNotes = (new ArtworkSheetService($pdo))->sheetForArtwork($id, $artworkOwnerId);
        $v2Service = new ArtworkAnalysisV2Service(new GeminiImageClient());
        $generated=$v2Service->generateDraft($artwork, $profileForV2, $imagePath, (string)($sheetForNotes['user_notes']??''));
        artwork_apply_v2_metadata($pdo,$id,$artworkOwnerId,(array)$generated['draft']);
        header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&v2_applied=1#artwork-metadata');
        exit;
    } catch (Throwable $e) {
        header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&metadata_error=' . rawurlencode($e->getMessage()) . '#artwork-metadata');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_artwork_website_v2') {
    try {
        $sheet = (new ArtworkSheetService($pdo))->sheetForArtwork($id, $artworkOwnerId);
        if (!in_array((string)($sheet['status'] ?? ''), ['validated', 'approved'], true)) {
            throw new RuntimeException('Validate the artwork analysis before sending it to the website.');
        }
        $publicationId = (new PublicationService($pdo))->createForSheet((int)$sheet['id'], $artworkOwnerId);
        $website = new ArtworkWebsiteV2Service($pdo);
        $result = $website->send($website->buildContract($publicationId, $artworkOwnerId));
        header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&website_v2_synced=' . rawurlencode((string)($result['status'] ?? 'updated')));
        exit;
    } catch (Throwable $e) {
        header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&metadata_error=' . rawurlencode($e->getMessage()));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_artwork_website') {
    $websiteTransactionStarted = false;
    try {
        $websitePublicationService = new PublicationService($pdo);
        $websiteTransactionStarted = !$pdo->inTransaction();
        if ($websiteTransactionStarted) $pdo->beginTransaction();
        $sheet = (new ArtworkSheetService($pdo))->sheetForArtwork($id, $artworkOwnerId);
        $intent = (string)($_POST['website_intent'] ?? 'save');
        if (!in_array($intent, ['save', 'publish', 'unpublish'], true)) $intent = 'save';
        $savedWebsitePublication = $websitePublicationService->saveWebsiteSettings((int)$sheet['id'], $artworkOwnerId, $_POST, $intent);
        $headerFile = basename(trim((string)($_POST['header_file'] ?? '')));
        $allowedHeaders = array_filter([basename((string)($sheet['source_image_file'] ?? ''))]);
        $headerViews = $pdo->prepare('SELECT file_name FROM root_artwork_candidates WHERE artwork_id=?');
        $headerViews->execute([$id]);
        $allowedHeaders = array_merge($allowedHeaders, array_map('basename', $headerViews->fetchAll(PDO::FETCH_COLUMN)));
        $groupStmt = $pdo->prepare('SELECT COALESCE(artwork_group_id,0) FROM artworks WHERE id=? AND user_id=?');
        $groupStmt->execute([$id, $artworkOwnerId]);
        $groupId = (int)($groupStmt->fetchColumn() ?: 0);
        $headerMockups = $pdo->prepare('SELECT mockup_file FROM mockup_sheets WHERE user_id=? AND (artwork_id=? OR artwork_sheet_id=? OR (? > 0 AND artwork_group_id=?))');
        $headerMockups->execute([$artworkOwnerId, $id, (int)$sheet['id'], $groupId, $groupId]);
        $allowedHeaders = array_merge($allowedHeaders, array_map('basename', $headerMockups->fetchAll(PDO::FETCH_COLUMN)));
        if ($headerFile !== '' && !in_array($headerFile, $allowedHeaders, true)) throw new RuntimeException('The selected website cover does not belong to this artwork.');
        $pdo->prepare('UPDATE publications SET header_file=?,updated_at=? WHERE id=? AND user_id=?')
            ->execute([$headerFile, date('c'), (int)$savedWebsitePublication['id'], $artworkOwnerId]);
        $constellationCountry = trim((string)($_POST['constellation_country'] ?? ''));
        $constellation = $pdo->prepare('SELECT id FROM artist_site_constellations WHERE user_id=? AND artwork_id=? LIMIT 1');
        $constellation->execute([$artworkOwnerId, $id]);
        $constellationId = (int)($constellation->fetchColumn() ?: 0);
        if ($constellationId > 0) {
            $pdo->prepare("UPDATE artist_site_constellations SET enabled=?,country=?,region='',city='',postal_code='',latitude='',longitude='',privacy=?,public_note='',updated_at=? WHERE id=? AND user_id=?")
                ->execute([$constellationCountry === '' ? 0 : 1, $constellationCountry, $constellationCountry === '' ? 'private' : 'country', date('c'), $constellationId, $artworkOwnerId]);
        } elseif ($constellationCountry !== '') {
            $now = date('c');
            $pdo->prepare("INSERT INTO artist_site_constellations (user_id,artwork_id,enabled,country,region,city,postal_code,latitude,longitude,privacy,public_note,created_at,updated_at) VALUES (?,?,1,?,'','','','','','country','',?,?)")
                ->execute([$artworkOwnerId, $id, $constellationCountry, $now, $now]);
        }
        $saleVariant = $pdo->prepare('SELECT * FROM artist_site_print_variants WHERE user_id=? AND artwork_id=? ORDER BY id LIMIT 1');
        $saleVariant->execute([$artworkOwnerId, $id]);
        $sale = $saleVariant->fetch(PDO::FETCH_ASSOC) ?: null;
        $priceInput = trim((string)($_POST['sale_price'] ?? ''));
        if ($sale || $priceInput !== '') {
            $price = str_replace(',', '.', $priceInput === '' ? '0' : $priceInput);
            if (!is_numeric($price) || (float)$price < 0) throw new RuntimeException('Enter a valid artwork price.');
            $currency = strtoupper(trim((string)($_POST['sale_currency'] ?? 'EUR')));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) throw new RuntimeException('Currency must use a three-letter ISO code.');
            $saleStatus = (string)($_POST['sale_status'] ?? 'draft');
            if (!in_array($saleStatus, ['draft', 'active', 'paused', 'sold_out'], true)) $saleStatus = 'draft';
            $stock = max(0, (int)($_POST['sale_stock'] ?? 0));
            if ($saleStatus === 'active' && ((float)$price <= 0 || $stock <= 0)) {
                throw new RuntimeException('Available artworks need a price and at least one available unit.');
            }
            $now = date('c');
            if ($sale) {
                $stockOnHand = $stock + max(0, (int)($sale['stock_reserved'] ?? 0));
                $pdo->prepare('UPDATE artist_site_print_variants SET stock_on_hand=?,price_minor=?,currency=?,status=?,updated_at=? WHERE id=? AND user_id=? AND artwork_id=?')
                    ->execute([$stockOnHand, (int)round((float)$price * 100), $currency, $saleStatus, $now, (int)$sale['id'], $artworkOwnerId, $id]);
            } else {
                $pdo->prepare("INSERT INTO artist_site_print_variants (user_id,artwork_id,title,sku,size_label,support,finish,inventory_mode,edition_size,stock_on_hand,stock_reserved,price_minor,currency,status,created_at,updated_at) VALUES (?,?,?,?,'','','','in_stock',1,?,0,?,?,?, ?,?)")
                    ->execute([$artworkOwnerId, $id, trim((string)($sheet['title'] ?? '')), 'ART-' . $id, $stock, (int)round((float)$price * 100), $currency, $saleStatus, $now, $now]);
            }
        }
        if ($websiteTransactionStarted) $pdo->commit();
        $message = $intent === 'publish' ? 'published' : ($intent === 'unpublish' ? 'unpublished' : 'saved');
        header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&website_saved=' . rawurlencode($message) . '#website-publication');
        exit;
    } catch (Throwable $e) {
        if ($websiteTransactionStarted && $pdo->inTransaction()) $pdo->rollBack();
        header('Location: artwork.php?id=' . rawurlencode((string)$id) . '&website_error=' . rawurlencode($e->getMessage()) . '#website-publication');
        exit;
    }
}

if (!function_exists('artwork_adopt_root_view_candidates')) {
    /**
     * @param array<int,array{file_name:string,view_type:string,job_id?:string}> $candidates
     */
    function artwork_adopt_root_view_candidates(PDO $pdo, int $artworkId, int $userId, array $candidates, string $rootFile): void
    {
        if (!$candidates) {
            return;
        }

        Database::withBusyRetry(function () use ($pdo, $artworkId, $userId, $candidates, $rootFile): void {
            if (Database::isMysql()) {
                $columnRows = $pdo->query('SHOW COLUMNS FROM root_artwork_candidates')->fetchAll(PDO::FETCH_ASSOC);
                $columns = array_map(static fn(array $row): string => (string)$row['Field'], $columnRows);
            } else {
                $columnRows = $pdo->query('PRAGMA table_info(root_artwork_candidates)')->fetchAll(PDO::FETCH_ASSOC);
                $columns = array_map(static fn(array $row): string => (string)$row['name'], $columnRows);
            }
            $hasColumn = static fn(string $name): bool => in_array($name, $columns, true);
            $existsStmt = $pdo->prepare('
                SELECT COUNT(*)
                FROM root_artwork_candidates
                WHERE artwork_id = :artwork_id
                AND file_name = :file_name
            ');

            foreach ($candidates as $candidate) {
                $file = basename((string)($candidate['file_name'] ?? ''));
                $viewType = (string)($candidate['view_type'] ?? '');
                $jobId = basename((string)($candidate['job_id'] ?? ''));
                if ($file === '' || $viewType === '' || !is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
                    continue;
                }

                $existsStmt->execute([
                    'artwork_id' => $artworkId,
                    'file_name' => $file,
                ]);
                if ((int)$existsStmt->fetchColumn() > 0) {
                    continue;
                }

                $insertColumns = ['artwork_id', 'file_name', 'view_type', 'is_selected', 'created_at'];
                $params = [
                    'artwork_id' => $artworkId,
                    'file_name' => $file,
                    'view_type' => $viewType,
                    'is_selected' => $file === $rootFile ? 1 : 0,
                    'created_at' => date('c'),
                ];
                if ($hasColumn('user_id')) {
                    $insertColumns[] = 'user_id';
                    $params['user_id'] = $userId;
                }
                if ($hasColumn('job_id')) {
                    $insertColumns[] = 'job_id';
                    $params['job_id'] = $jobId;
                }
                if ($hasColumn('updated_at')) {
                    $insertColumns[] = 'updated_at';
                    $params['updated_at'] = date('c');
                }
                $placeholders = array_map(static fn(string $column): string => ':' . $column, $insertColumns);
                $sql = 'INSERT INTO root_artwork_candidates (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';
                $pdo->prepare($sql)->execute($params);
            }
        }, 12);
    }
}

artwork_adopt_root_view_candidates($pdo, $id, $artworkOwnerId, artwork_latest_root_view_job_candidates($id), $rootFile);

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

if (!function_exists('artwork_root_view_version_fallbacks')) {
    function artwork_root_view_version_fallbacks(array $artwork): array
    {
        $rootFile = basename((string)($artwork['root_file'] ?? ''));
        $prefix = '';
        if ($rootFile !== '' && preg_match('/^(.*)_v\d+\.(png|jpe?g|webp)$/i', $rootFile, $matches)) {
            $prefix = (string)$matches[1];
        }
        if ($prefix === '') {
            return [];
        }

        $map = [
            1 => 'frontal',
            2 => 'three-quarter-left',
            3 => 'three-quarter-right',
        ];
        $fallbacks = [];
        foreach ($map as $version => $viewType) {
            foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
                $candidateFile = $prefix . '_v' . $version . '.' . $ext;
                if (is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $candidateFile)) {
                    $fallbacks[] = [
                        'id'          => 0,
                        'file_name'   => $candidateFile,
                        'view_type'   => $viewType,
                        'is_selected' => $candidateFile === $rootFile,
                    ];
                    break;
                }
            }
        }

        return $fallbacks;
    }
}

$knownRootCandidateFiles = [];
foreach ($rootCandidatesList as $candidate) {
    $knownRootCandidateFiles[basename((string)($candidate['file_name'] ?? ''))] = true;
}
foreach (artwork_root_view_version_fallbacks($artwork) as $fallbackCandidate) {
    if (empty($knownRootCandidateFiles[$fallbackCandidate['file_name']])) {
        $rootCandidatesList[] = $fallbackCandidate;
        $knownRootCandidateFiles[$fallbackCandidate['file_name']] = true;
    }
}
$rootCandidateOrder = [
    'frontal'             => 1,
    'three-quarter-left'  => 2,
    'three-quarter-right' => 3,
];
usort($rootCandidatesList, static function (array $a, array $b) use ($rootCandidateOrder): int {
    $aOrder = $rootCandidateOrder[(string)($a['view_type'] ?? '')] ?? 99;
    $bOrder = $rootCandidateOrder[(string)($b['view_type'] ?? '')] ?? 99;
    return $aOrder <=> $bOrder;
});
$requiredRootViews = [
    'frontal' => 'Frontal',
    'three-quarter-left' => '3/4 Left',
    'three-quarter-right' => '3/4 Right',
];
$availableRootViews = [];
foreach ($rootCandidatesList as $candidate) {
    $candidateFile = basename((string)($candidate['file_name'] ?? ''));
    if (artwork_result_file_available($candidateFile)) {
        $availableRootViews[(string)($candidate['view_type'] ?? '')] = true;
    }
}
$missingRootViews = array_diff_key($requiredRootViews, $availableRootViews);

$artworkGroupId = (int)($artwork['artwork_group_id'] ?? 0);
if ($artworkGroupId > 0) {
    $mockupStmt = $pdo->prepare('
        SELECT *
        FROM mockups
        WHERE user_id = :user_id
        AND artwork_group_id = :artwork_group_id
        ORDER BY created_at DESC
    ');
    $mockupStmt->execute([
        'user_id' => $artworkOwnerId,
        'artwork_group_id' => $artworkGroupId,
    ]);
} else {
    $mockupStmt = $pdo->prepare('
        SELECT *
        FROM mockups
        WHERE user_id = :user_id
        AND artwork_file = :artwork_file
        ORDER BY created_at DESC
    ');
    $mockupStmt->execute([
        'user_id'      => $artworkOwnerId,
        'artwork_file' => $rootFile,
    ]);
}
$mockups = $mockupStmt->fetchAll();
$favoriteMockupLookup = MockupFavorites::lookupForUser($artworkOwnerId);
$relatedMockups = [];
foreach ($mockups ?: [] as $relatedMockup) {
    $relatedFile = basename((string)($relatedMockup['mockup_file'] ?? ''));
    if ($relatedFile === '') {
        continue;
    }
    if (!is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $relatedFile) && !StorageService::isGcsActive()) {
        continue;
    }
    $relatedState = json_decode((string)($relatedMockup['selector_state_json'] ?? ''), true);
    $relatedMockup['selector_state'] = is_array($relatedState) ? $relatedState : [];
    $relatedCombination = (array)($relatedMockup['selector_state']['combination'] ?? []);
    $relatedCameraName = trim((string)($relatedCombination['camera_slot_name'] ?? ''));
    $relatedMockup['is_close_view'] = preg_match('/\b(?:close[\s-]*(?:up|view)|detail|macro)\b/i', $relatedCameraName) === 1;
    $relatedMockup['variation_lab_available'] = MockupVariationEligibility::canUseVariationLab($relatedMockup);
    $relatedMockup['mockup_file_basename'] = $relatedFile;
    $relatedMockup['is_favorite'] = isset($favoriteMockupLookup[(int)$relatedMockup['id']]);
    $relatedMockups[] = $relatedMockup;
}
usort($relatedMockups, static function (array $a, array $b): int {
    $closeViewOrder = (!empty($a['is_close_view']) ? 1 : 0) <=> (!empty($b['is_close_view']) ? 1 : 0);
    if ($closeViewOrder !== 0) return $closeViewOrder;

    $favoriteOrder = (!empty($b['is_favorite']) ? 1 : 0) <=> (!empty($a['is_favorite']) ? 1 : 0);
    if ($favoriteOrder !== 0) return $favoriteOrder;

    $dateOrder = strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    return $dateOrder !== 0 ? $dateOrder : ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
});
$favoriteMockups = array_values(array_filter($relatedMockups, static fn (array $mockup): bool => !empty($mockup['is_favorite'])));

$measurement = $meta['measurements'] ?? [];
$unit = (string)($measurement['unit'] ?? $artwork['unit'] ?? 'cm');
$width = $measurement['width'] ?? $artwork['width'] ?? '';
$height = $measurement['height'] ?? $artwork['height'] ?? '';
$depth = $measurement['depth'] ?? $artwork['depth'] ?? '';
$sizeText = trim((string)$width) !== '' && trim((string)$height) !== ''
    ? trim((string)$width . ' x ' . (string)$height . ($depth !== '' && $depth !== null ? ' x ' . (string)$depth : '') . ' ' . $unit)
    : 'No dimensions specified';

$artistProfile = is_array($profile['_artist_profile'] ?? null) ? $profile['_artist_profile'] : ArtistProfile::findForUser($artworkOwnerId);
$artistName = trim((string)($artistProfile['artist_name'] ?? ''));
$artworkFinalVideos = [];
try {
    $artworkFinalVideos = (new VideoStudioRepository($pdo))->finalVideosForArtwork($artworkOwnerId, $id);
} catch (Throwable $videoLibraryError) {
    $artworkFinalVideos = [];
}
$orientation = $analysis['image']['orientation'] ?? '';
if ($orientation === '' && (float)$width > 0 && (float)$height > 0) {
    $orientation = (float)$width > (float)$height ? 'horizontal' : ((float)$height > (float)$width ? 'vertical' : 'square');
}
$orientation = $orientation ?: 'Not specified';
$sheetService = new ArtworkSheetService($pdo);
$artworkSheet = $sheetService->sheetForArtwork($id, $artworkOwnerId);
$publicationService = new PublicationService($pdo);
$websitePublication = $publicationService->findForSheet((int)$artworkSheet['id'], $artworkOwnerId);
$websiteStatus = (string)($websitePublication['status'] ?? 'not_prepared');
$websiteVisibility = (string)($websitePublication['visibility'] ?? 'public');
$constellationStmt = $pdo->prepare('SELECT country FROM artist_site_constellations WHERE user_id=? AND artwork_id=? AND enabled=1 LIMIT 1');
$constellationStmt->execute([$artworkOwnerId, $id]);
$websiteConstellationCountry = trim((string)($constellationStmt->fetchColumn() ?: ''));
$websiteSaleStmt = $pdo->prepare('SELECT * FROM artist_site_print_variants WHERE user_id=? AND artwork_id=? ORDER BY id LIMIT 1');
$websiteSaleStmt->execute([$artworkOwnerId, $id]);
$websiteSale = $websiteSaleStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$websiteSaleAvailable = $websiteSale ? max(0, (int)$websiteSale['stock_on_hand'] - (int)$websiteSale['stock_reserved']) : 0;
$websiteCurrencyStmt = $pdo->prepare('SELECT currency FROM artist_site_settings WHERE user_id=? LIMIT 1');
$websiteCurrencyStmt->execute([$artworkOwnerId]);
$websiteDefaultCurrency = strtoupper(trim((string)($websiteCurrencyStmt->fetchColumn() ?: 'EUR')));
$websiteCoverOptions = [];
$websiteCoverItems = [];
$addWebsiteCover = static function (array &$options, array &$items, string $file, string $label, string $type): void {
    $file = basename(trim($file));
    if ($file === '' || isset($options[$file])) return;
    $options[$file] = $label;
    $items[$file] = [
        'file' => $file,
        'label' => $label,
        'type' => $type,
    ];
};
$addWebsiteCover($websiteCoverOptions, $websiteCoverItems, $rootFile, 'Main artwork image', 'Artwork');
foreach ($rootCandidatesList as $candidate) {
    $addWebsiteCover(
        $websiteCoverOptions,
        $websiteCoverItems,
        (string)($candidate['file_name'] ?? ''),
        ucwords(str_replace('-', ' ', (string)($candidate['view_type'] ?? 'Artwork view'))),
        'Artwork view'
    );
}
foreach ($relatedMockups as $mockup) {
    $mockupState = (array)($mockup['selector_state'] ?? []);
    $mockupCombination = (array)($mockupState['combination'] ?? []);
    $mockupLabel = trim((string)($mockupCombination['camera_slot_name'] ?? ''));
    if ($mockupLabel === '') $mockupLabel = trim((string)($mockup['title'] ?? ''));
    if ($mockupLabel === '') $mockupLabel = trim((string)($mockup['context_id'] ?? ''));
    if ($mockupLabel === '') $mockupLabel = 'Mockup ' . (int)($mockup['id'] ?? 0);
    $addWebsiteCover($websiteCoverOptions, $websiteCoverItems, (string)($mockup['mockup_file'] ?? ''), $mockupLabel, 'Mockup');
}
$websiteSelectedCover = basename((string)($websitePublication['header_file'] ?? ''));
$websiteSelectedCover = isset($websiteCoverItems[$websiteSelectedCover]) ? $websiteSelectedCover : $rootFile;
$websiteSelectedCoverItem = $websiteCoverItems[$websiteSelectedCover] ?? reset($websiteCoverItems) ?: [
    'file' => $rootFile,
    'label' => 'Main artwork image',
    'type' => 'Artwork',
];
$artworkSheetGenerated = json_decode((string)($artworkSheet['generated_json'] ?? ''), true);
$artworkSheetGenerated = is_array($artworkSheetGenerated) ? $artworkSheetGenerated : [];
$v2DraftMatch = artwork_v2_draft_for_image($rootFile);
$v2Draft = is_array($v2DraftMatch['data'] ?? null)
    ? $v2DraftMatch['data']
    : (($artworkSheetGenerated['schema_version'] ?? '') === ArtworkAnalysisV2::SCHEMA_VERSION ? $artworkSheetGenerated : null);
if ($v2Draft && !in_array((string)($artworkSheet['status'] ?? ''), ['validated', 'approved'], true)) {
    try {
        artwork_apply_v2_metadata($pdo, $id, $artworkOwnerId, $v2Draft);
        $artwork = $sheetService->artwork($id, $artworkOwnerId);
        $artworkSheet = $sheetService->sheetForArtwork($id, $artworkOwnerId);
        $artworkSheetGenerated = json_decode((string)($artworkSheet['generated_json'] ?? ''), true);
        $artworkSheetGenerated = is_array($artworkSheetGenerated) ? $artworkSheetGenerated : [];
    } catch (Throwable $metadataApplyError) {
        // Keep the generated analysis visible and editable even if automatic persistence needs attention.
    }
}
$canonicalSheetTitle = trim((string)($artworkSheet['title'] ?? ''));
if (
    trim((string)($artwork['final_title'] ?? '')) === ''
    && $canonicalSheetTitle !== ''
    && in_array((string)($artworkSheet['status'] ?? ''), ['review', 'validated', 'approved'], true)
) {
    $sheetService->saveArtworkTitle($id, $artworkOwnerId, $canonicalSheetTitle);
    $artwork = $sheetService->artwork($id, $artworkOwnerId);
}
$packageAnalysis = $analysis;
if ($v2Draft && is_array($v2Draft['canonical_editorial'] ?? null)) {
    $v2CanonicalEditorial = (array)$v2Draft['canonical_editorial'];
    if (trim((string)($v2CanonicalEditorial['title'] ?? '')) !== '') {
        $packageAnalysis['suggested_titles'] = [[
            'title' => (string)$v2CanonicalEditorial['title'],
            'subtitle' => (string)($v2CanonicalEditorial['subtitle'] ?? ''),
            'description' => (string)($v2CanonicalEditorial['master_description'] ?? ''),
        ]];
    }
}
$package = build_artwork_package_v2($artwork, $packageAnalysis, $artistProfile);
$artworkSheetLongTail = is_array($artworkSheetGenerated['long_tail_terms'] ?? null)
    ? $artworkSheetGenerated['long_tail_terms']
    : (is_array($artworkSheetGenerated['long_tail_keywords'] ?? null) ? $artworkSheetGenerated['long_tail_keywords'] : []);
$artworkSheetHasMetadata = trim((string)($artworkSheet['title'] ?? '')) !== ''
    || trim((string)($artworkSheet['description'] ?? '')) !== ''
    || trim((string)($artworkSheet['alt_text'] ?? '')) !== ''
    || trim((string)($artworkSheet['keywords'] ?? '')) !== ''
    || !empty($artworkSheetLongTail);
$websiteMetadataComplete = trim((string)($artworkSheet['title'] ?? '')) !== ''
    && (trim((string)($artworkSheet['short_description'] ?? '')) !== '' || trim((string)($artworkSheet['description'] ?? '')) !== '')
    && trim((string)($artworkSheet['alt_text'] ?? '')) !== ''
    && trim((string)($artworkSheet['keywords'] ?? '')) !== ''
    && trim((string)($artworkSheet['tags'] ?? '')) !== '';
$artworkMetadataValidated = $artworkSheetHasMetadata
    && in_array((string)($artworkSheet['status'] ?? ''), ['review', 'validated', 'approved'], true);
$storedTitle = trim((string)($artwork['final_title'] ?? ''));
$storedSubtitle = trim((string)($artwork['subtitle'] ?? ''));
$suggestedInitialTitle = trim((string)($package['titles'][0] ?? ''));
$selectedTitle = $storedTitle !== ''
    ? $storedTitle
    : ($suggestedInitialTitle !== '' && strcasecmp($suggestedInitialTitle, 'Untitled') !== 0 ? $suggestedInitialTitle : 'Untitled');
$selectedSubtitle = $storedSubtitle;
$selectedPublicationDescription = '';
$displayTitle = trim((string)($artworkSheet['title'] ?? '')) !== '' ? trim((string)$artworkSheet['title']) : $selectedTitle;
$displaySubtitle = trim((string)($artworkSheet['subtitle'] ?? '')) !== '' ? trim((string)$artworkSheet['subtitle']) : $selectedSubtitle;
$displayDescription = trim((string)($artworkSheet['description'] ?? ''));
$artworkSeriesRows = ArtworkSeries::seriesList($pdo, $artworkOwnerId);
$artworkSeriesName = ArtworkSeries::display((string)($artwork['series'] ?? ''));
$publicationCopy = trim($selectedTitle . ($selectedSubtitle !== '' ? "\n" . $selectedSubtitle : '') . "\n\n" . $selectedPublicationDescription);

$copyIconSvg = '<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
$downloadIconSvg = '<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>';
$editIconSvg = '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="1.7" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"></path></svg>';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Permanent Artwork Sheet - Artwork Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .artwork-series-form { flex-shrink:0; margin:0; transition:opacity .18s ease; }
        .artwork-series-form.is-saving { opacity:.55; }
        .artwork-series-form label { margin:0; color:var(--muted); font-size:10px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .artwork-series-form label span { display:block; margin-bottom:5px; }
        .artwork-series-form select { width:auto; min-width:180px; min-height:auto; padding:8px 12px; }
        .artwork-series-controls { display:flex; justify-content:flex-end; align-items:flex-end; }
        .artwork-title-block { width:min(920px, 100%); }
        .artwork-page-header { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; }
        .artwork-studio-note-link {
            display:inline-flex;
            flex:0 0 140px;
            width:140px;
            height:140px;
            align-items:center;
            justify-content:center;
            padding:18px;
            border:1px solid #94a88f;
            border-radius:4px;
            background:#9fb198;
            color:#fffdf8;
            box-shadow:0 8px 18px rgba(68, 83, 63, .12);
            font-size:11px;
            font-weight:800;
            letter-spacing:.09em;
            line-height:1.35;
            text-align:center;
            text-transform:uppercase;
        }
        .artwork-studio-note-link:hover,
        .artwork-studio-note-link:focus-visible { border-color:#81987b; background:#8fa487; color:#fff; transform:translateY(-1px); }
        .artwork-studio-note-mobile { display:none; }
        .artwork-title-heading { display:flex; align-items:center; gap:10px; }
        .artwork-page-header h1 { display:inline-block; border-bottom:4px solid #b77f86; padding-bottom:10px; }
        .artwork-title-edit-button {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            flex:0 0 38px;
            width:38px;
            height:38px;
            min-height:38px;
            margin:0 0 4px;
            padding:0;
            border:1px solid transparent;
            border-radius:50%;
            background:transparent;
            color:var(--muted);
            box-shadow:none;
        }
        .artwork-title-edit-button:hover,
        .artwork-title-edit-button[aria-expanded="true"] {
            border-color:#d9b7bb;
            background:#f5e7e8;
            color:#8f5f66;
            box-shadow:none;
            transform:none;
        }
        .artwork-title-quick-edit { display:flex; align-items:center; gap:10px; margin-top:14px; }
        .artwork-title-quick-edit[hidden] { display:none; }
        .artwork-title-quick-edit input {
            flex:1 1 auto;
            min-width:0;
            padding:13px 15px;
            font-family:var(--font-serif);
            font-size:19px;
            line-height:1.35;
        }
        .artwork-title-quick-edit .sr-only {
            position:absolute;
            width:1px;
            height:1px;
            padding:0;
            margin:-1px;
            overflow:hidden;
            clip:rect(0, 0, 0, 0);
            white-space:nowrap;
            border:0;
        }
        .artwork-title-quick-edit button { flex:0 0 auto; width:auto; min-width:120px; min-height:48px; margin:0; padding:11px 18px; }
        .artwork-title-quick-edit .artwork-title-cancel { background:transparent; border-color:var(--line); color:var(--ink); box-shadow:none; }
        .v2-admin-panel { border-color:var(--accent); }
        .v2-admin-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; }
        .v2-admin-badge { padding:5px 9px; border-radius:999px; background:var(--surface-soft); font-size:10px; font-weight:700; text-transform:uppercase; white-space:nowrap; }
        .v2-admin-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-top:16px; }
        .v2-admin-card { border:1px solid var(--line); border-radius:var(--radius); padding:14px; background:var(--surface-soft); }
        .v2-admin-card h3 { margin:0 0 8px; font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }
        .v2-admin-card p { margin:0 0 8px; font-size:13px; line-height:1.55; }
        .v2-admin-card p:last-child { margin-bottom:0; }
        .v2-admin-description { white-space:pre-line; }
        .v2-admin-details { margin-top:14px; }
        .v2-admin-details summary { cursor:pointer; font-weight:600; }
        .v2-admin-actions { display:flex; align-items:center; gap:12px; margin-top:16px; }
        .v2-mobile-toggle { display:none; }
        .artwork-metadata-v2-form { max-width:1180px; margin:22px auto 0; padding:24px; }
        .artwork-metadata-v2-form .artwork-metadata-form-grid { gap:18px; }
        .artwork-metadata-v2-form .artwork-metadata-field { gap:8px; }
        .artwork-metadata-v2-form .artwork-metadata-field label { font-size:11px; letter-spacing:.07em; }
        .artwork-metadata-v2-form input,
        .artwork-metadata-v2-form textarea { padding:13px 14px; font-size:15px; line-height:1.55; }
        .artwork-metadata-v2-form textarea[name="description"] { min-height:210px; }
        .artwork-metadata-more { grid-column:1 / -1; margin-top:2px; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); }
        .artwork-metadata-more > summary { cursor:pointer; padding:14px 16px; font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .artwork-metadata-more-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; padding:4px 16px 16px; }
        .admin-analysis-details { max-width:1180px; margin:14px auto 0; }
        .artwork-metadata-finished { width:100%; max-width:none; box-sizing:border-box; margin:22px 0 0; padding:30px 34px; background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); }
        .artwork-metadata-finished h3 { margin:0; font:500 clamp(28px,3vw,42px)/1.08 var(--font-serif); color:var(--ink); }
        .artwork-metadata-finished .metadata-subtitle { margin:9px 0 0; font:400 20px/1.35 var(--font-serif); color:var(--accent); }
        .artwork-metadata-finished .metadata-description { margin:24px 0 0; width:100%; max-width:none; color:var(--ink); font-size:16px; line-height:1.75; white-space:pre-line; }
        .artwork-metadata-edit { width:100%; max-width:none; box-sizing:border-box; margin:12px 0 0; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface-soft); }
        .artwork-metadata-edit > summary { cursor:pointer; padding:13px 16px; color:var(--accent); font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .artwork-metadata-edit .artwork-metadata-v2-form { margin:0; max-width:none; border:0; border-top:1px solid var(--line); border-radius:0; box-shadow:none; }
        .artwork-page-header--bilingual .artwork-title-block { width:min(1120px,100%); }
        .artwork-page-header--bilingual .artwork-title-heading { padding-bottom:14px; border-bottom:1px solid var(--line); }
        .artwork-page-header--bilingual h1 { display:block; margin:0; padding:0; border:0; font:500 clamp(42px,4.5vw,58px)/1.05 var(--font-serif); letter-spacing:-.01em; }
        .artwork-page-header--bilingual .artwork-title-edit-button { display:none; }
        .artwork-title-universal-label { display:block; margin:0 0 15px; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .artwork-title-universal-memo { margin:15px 0 0; padding:0; border:0; color:var(--accent); font:italic 500 21px/1.5 var(--font-serif); }
        .bilingual-editorial-panel { width:100%; max-width:none; box-sizing:border-box; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); }
        .bilingual-editorial-panel > summary { display:flex; align-items:center; justify-content:space-between; gap:20px; padding:18px 20px; cursor:pointer; list-style:none; }
        .bilingual-editorial-panel > summary::-webkit-details-marker { display:none; }
        .bilingual-editorial-summary strong { display:block; color:var(--ink); font:500 23px/1.1 var(--font-serif); }
        .bilingual-editorial-summary span { display:block; margin-top:5px; color:var(--muted); font-size:12px; }
        .bilingual-editorial-state { color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; white-space:nowrap; }
        .bilingual-editorial-state::after { content:'+'; display:inline-block; margin-left:14px; color:var(--accent); font:500 22px/1 var(--font-serif); vertical-align:-2px; }
        .bilingual-editorial-panel[open] .bilingual-editorial-state::after { content:'−'; }
        .bilingual-editorial-spread { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); grid-template-rows:auto repeat(8,auto); column-gap:12px; row-gap:0; padding:14px; border-top:1px solid var(--line); }
        .bilingual-editorial-page { display:grid; grid-row:1 / span 9; grid-template-rows:subgrid; min-width:0; padding:18px; border:1px solid var(--line); border-top:3px solid #c89aa1; background:var(--surface-soft); }
        .bilingual-editorial-page--source { grid-column:1; }
        .bilingual-editorial-page--english { grid-column:2; border-top-color:#9fb19a; }
        .bilingual-editorial-language { display:block; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .bilingual-editorial-field { min-height:96px; margin-top:16px; padding-top:13px; border-top:1px solid var(--line); }
        .bilingual-editorial-field--description { min-height:230px; }
        .bilingual-editorial-field label { display:block; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; }
        .bilingual-editorial-copy { min-height:62px; margin-top:10px; color:var(--ink); font-size:14px; line-height:1.65; white-space:pre-wrap; }
        .bilingual-editorial-field--description .bilingual-editorial-copy { min-height:190px; }
        .bilingual-editorial-copy:empty::before { content:attr(data-placeholder); color:var(--muted); font-style:italic; }
        .bilingual-editorial-copy:focus { outline:0; }
        .bilingual-editorial-memo { margin:0 14px 14px; padding:14px 6px 2px; border-top:1px solid var(--line); }
        .bilingual-editorial-memo summary { cursor:pointer; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .bilingual-editorial-memo .bilingual-editorial-copy { min-height:82px; }
        .artwork-website-panel { margin:22px 0 0; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); }
        .artwork-website-panel > summary { display:flex; align-items:center; justify-content:space-between; gap:20px; padding:18px 20px; cursor:pointer; list-style:none; }
        .artwork-website-panel > summary::-webkit-details-marker { display:none; }
        .artwork-website-panel > summary::after { content:'+'; flex:0 0 auto; color:var(--accent); font:500 24px/1 var(--font-serif); }
        .artwork-website-panel[open] > summary::after { content:'−'; }
        .artwork-website-summary strong { display:block; font:500 23px/1.1 var(--font-serif); color:var(--ink); }
        .artwork-website-summary span { display:block; margin-top:5px; color:var(--muted); font-size:12px; }
        .artwork-website-state { border:1px solid rgba(160,92,71,.28); border-radius:999px; padding:7px 11px; background:rgba(224,104,76,.09); color:var(--ink); font-size:10px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; white-space:nowrap; }
        .artwork-website-form { padding:22px 20px 20px; border-top:1px solid var(--line); }
        .artwork-website-note { margin:0; padding:13px 14px; border-left:3px solid rgba(224,104,76,.52); background:var(--surface-soft); color:var(--muted); font-size:13px; line-height:1.5; }
        .artwork-website-sale { margin-top:18px; padding-top:18px; border-top:1px solid var(--line); }
        .artwork-website-sale h4 { margin:0 0 12px; font:500 20px/1.2 var(--font-serif); }
        .artwork-website-sale-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
        .artwork-website-sale-grid label { display:grid; gap:7px; color:var(--muted); font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .artwork-website-sale-grid input,.artwork-website-sale-grid select { width:100%; box-sizing:border-box; padding:12px 13px; border:1px solid var(--line); border-radius:var(--radius); background:#fff; color:var(--ink); font:400 15px/1.4 var(--font-sans); }
        .artwork-website-shipping { display:flex; align-items:center; justify-content:space-between; gap:18px; margin-top:12px; padding:13px 14px; background:var(--surface-soft); color:var(--muted); font-size:13px; }
        .artwork-website-shipping a { color:var(--accent); font-weight:700; white-space:nowrap; }
        .artwork-website-settings { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); align-items:end; gap:16px; margin-top:18px; }
        .artwork-website-settings label { display:grid; gap:7px; color:var(--muted); font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .artwork-website-settings input,.artwork-website-settings select { width:100%; box-sizing:border-box; padding:12px 13px; border:1px solid var(--line); border-radius:var(--radius); background:#fff; color:var(--ink); font:400 15px/1.4 var(--font-sans); }
        .artwork-website-cover { grid-column:1 / -1; display:grid; gap:7px; }
        .artwork-website-cover-label { color:var(--muted); font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .artwork-cover-picker { border:1px solid var(--line); border-radius:var(--radius); background:#fff; overflow:hidden; }
        .artwork-cover-picker > summary { min-height:112px; display:grid; grid-template-columns:86px minmax(0,1fr) auto; align-items:center; gap:16px; padding:10px; list-style:none; cursor:pointer; }
        .artwork-cover-picker > summary::-webkit-details-marker { display:none; }
        .artwork-cover-picker > summary:hover { background:var(--surface-soft); }
        .artwork-cover-current-image { width:86px; height:92px; display:block; border:1px solid var(--line-dark); padding:4px; background:var(--surface); object-fit:contain; }
        .artwork-cover-current-copy { min-width:0; display:grid; gap:4px; }
        .artwork-cover-current-copy strong { overflow:hidden; color:var(--ink); font:500 20px/1.15 var(--font-serif); text-overflow:ellipsis; white-space:nowrap; }
        .artwork-cover-current-copy span { color:var(--muted); font-size:12px; letter-spacing:.02em; }
        .artwork-cover-change { align-self:center; padding:8px 10px; color:var(--accent); font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .artwork-cover-change::after { content:' ↓'; font:500 16px/1 var(--font-serif); }
        .artwork-cover-picker[open] .artwork-cover-change::after { content:' ↑'; }
        .artwork-cover-options { display:flex; gap:12px; overflow-x:auto; padding:14px 12px 16px; border-top:1px solid var(--line); background:var(--surface-soft); scroll-snap-type:x proximity; scrollbar-color:var(--line-dark) transparent; }
        .artwork-cover-option { position:relative; flex:0 0 142px; display:grid; grid-template-rows:154px auto; gap:8px; padding:6px; border:1px solid var(--line); border-radius:2px; background:var(--surface); cursor:pointer; scroll-snap-align:start; text-transform:none; }
        .artwork-cover-option input { position:absolute; width:1px; height:1px; opacity:0; pointer-events:none; }
        .artwork-cover-option img { width:100%; height:154px; display:block; object-fit:contain; background:#efeee9; }
        .artwork-cover-option-copy { min-width:0; display:grid; gap:2px; padding:0 3px 3px; }
        .artwork-cover-option-copy strong { overflow:hidden; color:var(--ink); font:500 15px/1.15 var(--font-serif); text-overflow:ellipsis; white-space:nowrap; }
        .artwork-cover-option-copy small { color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .artwork-cover-option:hover { border-color:var(--line-dark); }
        .artwork-cover-option.is-selected { border-color:rgba(224,104,76,.72); box-shadow:inset 0 -3px rgba(224,104,76,.48); }
        .artwork-cover-option.is-selected .artwork-cover-option-copy small { color:#a65a47; }
        .artwork-website-actions { grid-column:1 / -1; display:flex; justify-content:flex-end; flex-wrap:wrap; gap:10px; }
        .artwork-website-actions button { min-height:44px; padding:10px 18px; }
        .artwork-website-actions .website-publish { border-color:#bdcdb8; background:#e8f0e5; color:#354633; font-weight:700; }
        @media (max-width:800px) {
            .v2-admin-grid,.bilingual-editorial-spread { grid-template-columns:1fr; grid-template-rows:none; }
            .bilingual-editorial-page { display:block; grid-column:auto; grid-row:auto; }
        }
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

        .beta-focus-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-top: 18px;
        }

        .beta-root-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 12px;
            box-shadow: var(--shadow);
        }

        .beta-root-card.selected {
            border: 2px solid var(--accent);
        }

        .beta-root-card img {
            width: 100%;
            aspect-ratio: 1 / 1;
            object-fit: contain;
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: 4px;
            display: block;
        }

        .beta-root-card h3 {
            margin: 10px 0 8px;
            font-size: 15px;
        }

        .beta-root-card button,
        .beta-root-card .button-link {
            width: 100%;
            justify-content: center;
            box-sizing: border-box;
        }

        .artwork-overview-grid {
            display: grid;
            grid-template-columns: minmax(700px, 1.65fr) minmax(420px, .95fr);
            gap: 14px;
            align-items: start;
        }

        .artwork-overview-main {
            display: grid;
            gap: 14px;
            min-width: 0;
            align-content: start;
        }

        .artwork-root-views-card {
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 14px;
        }

        .artwork-overview-main > .artwork-root-views-card {
            align-self: start;
        }

        .artwork-root-views-card h3 {
            margin: 0 0 10px;
            font-size: 16px;
        }

        .artwork-sheet-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 14px;
            box-shadow: var(--shadow);
        }

        .artwork-primary-metadata-card {
            grid-column: 3;
            grid-row: 1;
        }

        .artwork-primary-metadata-card,
        .artwork-metadata-secondary-row {
            display: none !important;
        }

        .artwork-sheet-card h3 {
            margin: 0;
            font-size: 18px;
            line-height: 1.15;
        }

        .artwork-sheet-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }

        .artwork-sheet-card-head p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.35;
        }

        .artwork-metadata-action-form {
            flex: 0 0 auto;
            margin: 0;
        }

        .artwork-metadata-layout-form {
            display: contents;
        }

        .artwork-metadata-action-form button {
            width: auto;
            min-height: 38px;
            margin: 0;
            padding: 0 18px;
            white-space: nowrap;
        }

        .artwork-metadata-editor {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface-soft);
            padding: 0;
        }

        .artwork-metadata-secondary-row {
            grid-column: 1 / -1;
            grid-row: 2;
        }

        .artwork-metadata-editor > summary {
            cursor: pointer;
            padding: 11px 12px;
            color: var(--ink);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .06em;
            list-style-position: inside;
            text-transform: uppercase;
        }

        .artwork-metadata-form {
            padding: 0 12px 12px;
        }

        .artwork-primary-metadata-card .artwork-metadata-form {
            padding: 12px 0 0;
        }

        .artwork-metadata-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px 14px;
        }

        .artwork-primary-metadata-card .artwork-metadata-form-grid {
            grid-template-columns: 1fr;
        }

        .artwork-metadata-field {
            display: grid;
            gap: 6px;
            align-content: start;
        }

        .artwork-metadata-field.full {
            grid-column: 1 / -1;
        }

        .artwork-metadata-field label {
            margin: 0;
        }

        .artwork-metadata-field input,
        .artwork-metadata-field textarea {
            background: var(--surface);
        }

        .artwork-metadata-sections {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .artwork-metadata-sections .details-panel {
            margin: 0 !important;
            padding: 10px !important;
            background: var(--surface);
        }

        .artwork-metadata-save {
            display: flex;
            justify-content: flex-end;
            margin-top: 12px;
        }

        .artwork-metadata-save button {
            width: auto;
            margin: 0;
            min-height: 38px;
            padding: 0 18px;
        }

        .artwork-sheet-subtitle {
            margin: 7px 0 0;
            font-family: var(--font-serif);
            color: var(--accent);
            font-size: 17px;
            line-height: 1.25;
        }

        .artwork-sheet-description {
            margin: 16px 0 0;
            color: var(--ink);
            font-size: 13px;
            line-height: 1.65;
        }

        .artwork-sheet-meta {
            display: grid;
            gap: 9px;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px dashed var(--line);
        }

        .artwork-sheet-meta-row {
            display: grid;
            grid-template-columns: 104px minmax(0, 1fr);
            gap: 10px;
            align-items: baseline;
            font-size: 12px;
        }

        .artwork-sheet-meta-row strong {
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .artwork-sheet-meta-row span {
            color: var(--ink);
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .overview-card {
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 14px;
        }

        .overview-card h3 {
            margin: 0 0 10px;
            font-size: 16px;
        }

        .root-version-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            align-items: stretch;
            justify-content: start;
            max-width: 100%;
        }

        .root-version-grid.has-final-video {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            grid-template-rows: repeat(2, minmax(0, 1fr));
            overflow: visible;
        }

        .root-version-grid.has-final-video > .root-version-card:nth-child(1) {
            grid-column: 1;
            grid-row: 1 / 3;
        }

        .root-version-grid.has-final-video > .root-version-card:nth-child(2) {
            grid-column: 2;
            grid-row: 1 / 3;
        }

        .root-version-grid.has-final-video > .root-version-card:nth-child(3) {
            grid-column: 3;
            grid-row: 1;
        }

        .root-version-grid.has-final-video > .root-version-card:nth-child(4) {
            grid-column: 3;
            grid-row: 2;
        }

        .root-version-grid.has-final-video > .root-version-card:nth-child(n + 3) {
            min-height: 0;
        }

        .root-version-grid.has-final-video > .root-version-card:nth-child(n + 3).root-version-missing button {
            gap: 7px;
            padding: 12px;
        }

        .root-version-grid.has-final-video > .root-version-card:nth-child(n + 3).root-version-missing svg {
            width: 30px;
            height: 30px;
        }

        .root-overview-media-grid {
            min-width: 0;
        }

        .mobile-root-artwork {
            display: none;
        }

        .artwork-metadata-slot {
            min-width: 0;
        }

        .favorite-mockups-panel {
            grid-column: 2;
            grid-row: 1;
            min-width: 0;
            height: auto;
            box-sizing: border-box;
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 14px;
            display: flex;
            flex-direction: column;
            align-self: start;
        }

        .favorite-mockups-panel h3 {
            margin: 0 0 10px;
            color: var(--muted);
            font-family: var(--font-sans);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .related-mockups-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 10px;
        }

        .related-mockups-title-row h3 {
            margin: 0;
        }

        .related-mockups-upload-link {
            flex: 0 0 auto;
            padding: 4px 7px;
            border: 1px solid var(--line);
            border-radius: 999px;
            color: var(--accent);
            background: var(--surface);
            font-size: 8px;
            font-weight: 700;
            letter-spacing: .04em;
            text-decoration: none;
            text-transform: uppercase;
        }

        .related-mockups-upload-link:hover {
            border-color: var(--accent);
        }

        .related-mockups-count {
            text-transform: none;
            font-weight: 400;
            letter-spacing: normal;
        }

        .related-mockups-sidebar-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
        }

        .related-mockups-sidebar-grid .related-mockup-card {
            padding: 5px;
            grid-template-rows: auto;
            gap: 0;
        }

        .related-mockups-sidebar-grid .related-mockup-card img {
            aspect-ratio: 4 / 5;
            object-fit: cover;
        }

        .related-mockups-sidebar-grid .related-mockup-actions {
            position: absolute;
            z-index: 3;
            right: 5px;
            bottom: 5px;
            left: 5px;
        }

        .related-mockups-sidebar-grid .related-mockup-actions a {
            min-height: 22px;
            padding: 0 4px;
            border: 1px solid rgba(255, 255, 255, .34);
            background: linear-gradient(180deg, rgba(83, 86, 87, .38), rgba(42, 45, 46, .48));
            color: rgba(255, 255, 255, .9);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .16), 0 3px 10px rgba(0, 0, 0, .12);
            backdrop-filter: blur(9px) saturate(.72);
            -webkit-backdrop-filter: blur(9px) saturate(.72);
            font-size: 8px;
            text-shadow: 0 1px 2px rgba(0, 0, 0, .3);
        }

        .related-mockups-sidebar-grid .related-mockup-actions a:hover,
        .related-mockups-sidebar-grid .related-mockup-actions a:focus-visible {
            border-color: rgba(255, 255, 255, .56);
            background: linear-gradient(180deg, rgba(91, 94, 95, .5), rgba(45, 48, 49, .6));
            color: #fff;
            outline: none;
        }

        .related-mockups-sidebar-grid .favorite-overlay-btn,
        .related-mockups-sidebar-grid .mockup-delete-overlay-btn {
            width: 16px;
            height: 16px;
            min-height: 16px;
            top: 4px;
            font-size: 9px;
        }

        .related-mockups-sidebar-grid .favorite-overlay-btn {
            left: 4px;
        }

        .related-mockups-sidebar-grid .mockup-delete-overlay-btn {
            right: 4px;
        }

        .favorite-empty {
            min-height: 150px;
            border: 1px dashed var(--line);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 18px 12px;
            color: var(--muted);
            font-size: 12px;
            text-align: center;
        }

        .related-mockups-empty {
            display: grid;
            grid-template-columns: minmax(150px, 210px) 118px;
            gap: 28px;
            min-height: 176px;
            padding: 18px 24px;
        }

        .related-mockups-empty p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.55;
            text-align: left;
        }

        .related-mockups-create-decision {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 118px;
            height: 118px;
            padding: 16px;
            border: 1px solid #ad747d;
            border-radius: 4px;
            background: #b77f86;
            color: #fffaf7;
            box-shadow: 0 8px 18px rgba(77, 51, 55, .12);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .08em;
            line-height: 1.3;
            text-align: center;
            text-decoration: none;
            text-transform: uppercase;
        }

        .related-mockups-create-decision:hover,
        .related-mockups-create-decision:focus-visible {
            border-color: #98636b;
            background: #a97078;
            color: #fff;
            transform: translateY(-1px);
        }

        .favorite-overlay-btn {
            position: absolute;
            top: 8px;
            left: 8px;
            z-index: 4;
            width: 28px;
            height: 28px;
            min-height: 28px;
            margin: 0;
            padding: 0;
            border: 1px solid rgba(255, 255, 255, .34);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(18, 17, 15, .16);
            color: rgba(255, 255, 255, .68);
            font-size: 14px;
            line-height: 1;
            cursor: pointer;
            opacity: .36;
            box-shadow: 0 6px 16px rgba(0, 0, 0, .12);
            backdrop-filter: blur(8px);
            transition: opacity .16s ease, background .16s ease, border-color .16s ease, color .16s ease, transform .16s ease;
        }

        .related-mockup-image:hover .favorite-overlay-btn,
        .related-mockup-image:focus-within .favorite-overlay-btn,
        .favorite-overlay-btn:hover,
        .favorite-overlay-btn:focus-visible,
        .favorite-overlay-btn.active {
            background: rgba(154, 123, 86, .72);
            border-color: rgba(255, 255, 255, .62);
            color: #fff;
            opacity: .94;
            outline: none;
        }

        .favorite-overlay-btn[disabled] {
            opacity: .55;
            cursor: wait;
        }

        .mockup-delete-overlay-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            z-index: 4;
            width: 28px;
            height: 28px;
            min-height: 28px;
            margin: 0;
            padding: 0;
            border: 1px solid rgba(255, 255, 255, .34);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(18, 17, 15, .16);
            color: rgba(255, 255, 255, .68);
            font-size: 15px;
            line-height: 1;
            cursor: pointer;
            opacity: .36;
            box-shadow: 0 6px 16px rgba(0, 0, 0, .12);
            backdrop-filter: blur(8px);
            transition: opacity .16s ease, background .16s ease, border-color .16s ease, color .16s ease, transform .16s ease;
        }

        .related-mockup-image:hover .mockup-delete-overlay-btn,
        .related-mockup-image:focus-within .mockup-delete-overlay-btn,
        .mockup-delete-overlay-btn:hover,
        .mockup-delete-overlay-btn:focus-visible {
            background: rgba(124, 43, 35, .72);
            border-color: rgba(255, 255, 255, .62);
            color: #fff;
            opacity: .94;
            outline: none;
        }

        .mockup-delete-overlay-btn[disabled] {
            opacity: .55;
            cursor: wait;
        }

        .root-version-card {
            position: relative;
            display: grid;
            min-width: 0;
            width: 100%;
            max-width: none;
            align-content: start;
            justify-self: start;
        }

        .root-version-card a {
            position: relative;
            display: block;
            width: 100%;
            max-width: none;
        }

        .root-version-card img {
            width: 100%;
            height: auto;
            max-width: 100%;
            object-fit: contain;
            background: transparent;
            border: 1px solid var(--line);
            border-radius: 3px;
            display: block;
        }

        .root-version-overlay {
            position: absolute;
            top: 8px;
            right: 8px;
            z-index: 2;
        }

        .root-version-select {
            width: 30px;
            height: 30px;
            min-height: 30px;
            margin: 0;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255, 255, 255, .62);
            border-radius: 999px;
            background: rgba(20, 20, 18, .48);
            color: #fff;
            font-size: 16px;
            line-height: 1;
            cursor: pointer;
            backdrop-filter: blur(8px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, .18);
        }

        .root-version-select:hover {
            background: rgba(154, 123, 86, .82);
        }

        .root-version-selected-pill {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 0 8px;
            border: 1px solid rgba(255, 255, 255, .72);
            border-radius: 999px;
            background: rgba(154, 123, 86, .78);
            color: #fff;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            backdrop-filter: blur(8px);
        }

        .root-version-card.is-selected img {
            border-color: var(--accent);
            box-shadow: 0 0 0 1px var(--accent);
        }

        .root-version-video-card {
            overflow: hidden;
        }

        .root-version-card .root-version-video-link {
            position: relative;
            width: 100%;
            min-height: 0;
            margin: 0;
            padding: 0;
            display: block;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 3px;
            background: #191714;
            box-shadow: none;
            cursor: pointer;
        }

        .root-version-video-link img,
        .root-version-video-link video {
            width: 100%;
            height: auto;
            max-height: none;
            display: block;
            object-fit: contain;
            border: 0;
            border-radius: 0;
            background: #191714;
            transition: opacity .18s ease, transform .22s ease;
        }

        .root-version-video-link:hover img,
        .root-version-video-link:hover video {
            opacity: .86;
            transform: scale(1.012);
        }

        .root-version-card .root-version-video-link:hover,
        .root-version-card .root-version-video-link:focus-visible {
            background: #191714;
            box-shadow: none;
            transform: none;
            outline: 1px solid var(--accent);
            outline-offset: 1px;
        }

        .root-version-card .root-version-video-download {
            position: absolute;
            z-index: 3;
            top: 8px;
            right: 8px;
            width: 27px;
            height: 27px;
            display: grid;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, .56);
            border-radius: 50%;
            background: rgba(24, 21, 18, .34);
            color: rgba(255, 255, 255, .88);
            box-shadow: 0 5px 14px rgba(0, 0, 0, .12);
            backdrop-filter: blur(5px);
            opacity: .72;
            transition: opacity .16s ease, background .16s ease;
        }

        .root-version-card .root-version-video-download:hover,
        .root-version-card .root-version-video-download:focus-visible {
            background: rgba(154, 123, 86, .78);
            opacity: 1;
            outline: none;
        }

        .root-version-video-download svg {
            width: 13px;
            height: 13px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.6;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .root-version-missing {
            min-height: 100%;
            border: 1px dashed rgba(154, 123, 86, .48);
            border-radius: 3px;
            background: rgba(154, 123, 86, .045);
        }

        .root-version-missing form,
        .root-version-missing button {
            width: 100%;
            height: 100%;
            min-height: 100%;
            margin: 0;
        }

        .root-version-missing button {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 22px;
            border: 0;
            background: transparent;
            color: var(--accent);
            box-shadow: none;
        }

        .root-version-missing button:hover,
        .root-version-missing button:focus-visible {
            background: rgba(154, 123, 86, .09);
            color: var(--ink);
            outline: none;
        }

        .root-version-missing svg {
            width: 42px;
            height: 42px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.4;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .root-version-missing strong {
            font-size: 11px;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .root-version-missing small {
            color: var(--muted);
            font-size: 10px;
            font-weight: 500;
        }

        .root-version-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            margin-top: 6px;
            color: var(--muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .06em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .root-version-label span:last-child {
            color: var(--accent);
            font-weight: 700;
        }

        .artwork-info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .artwork-info-item {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 10px;
            min-height: 52px;
        }

        .artwork-info-item strong {
            display: block;
            margin-bottom: 4px;
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .artwork-info-item span {
            color: var(--ink);
            font-size: 13px;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .beta-hidden-stage {
            display: none !important;
        }

        .related-mockups-panel {
            margin-top: 18px;
        }

        .related-mockups-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 14px;
        }

        .related-mockups-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
            gap: 14px;
        }

        .related-mockup-card {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface);
            padding: 10px;
            display: grid;
            grid-template-rows: auto 18px 36px;
            gap: 8px;
        }

        .related-mockup-card img {
            width: 100%;
            aspect-ratio: 4 / 3;
            object-fit: cover;
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: 3px;
        }

        .related-mockup-image {
            position: relative;
            display: block;
        }

        .related-mockup-meta {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 8px;
            min-height: 18px;
            color: var(--muted);
            font-size: 11px;
            min-width: 0;
        }

        .related-mockup-meta strong {
            color: var(--muted);
            font-size: 10px;
            font-weight: 500;
            line-height: 1.25;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            letter-spacing: .01em;
            min-width: 0;
        }

        .related-mockup-meta span {
            color: var(--muted);
            font-size: 10px;
            white-space: nowrap;
        }

        .related-mockup-actions {
            display: grid;
            align-self: end;
        }

        .related-mockup-actions a {
            display: flex;
            margin: 0;
            min-height: 36px;
            padding: 0 10px;
            font-size: 10px;
            text-align: center;
            justify-content: center;
            align-items: center;
            line-height: 1;
            letter-spacing: .08em;
            background: var(--surface);
            color: var(--accent);
            border-color: rgba(154, 123, 86, .42);
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

            .beta-focus-grid {
                grid-template-columns: 1fr;
            }

            .artwork-overview-grid,
            .artwork-info-grid {
                grid-template-columns: 1fr;
            }

            .artwork-overview-grid > .artwork-root-views-card,
            .artwork-primary-metadata-card,
            .artwork-metadata-slot,
            .favorite-mockups-panel,
            .artwork-metadata-secondary-row {
                grid-column: 1;
                grid-row: auto;
            }

            .favorite-mockups-panel {
                border: 1px solid var(--line);
                padding: 14px;
            }

            .artwork-metadata-sections {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .workspace {
                padding: 6px 10px 40px;
            }

            .artwork-website-panel > summary { align-items:flex-start; padding:16px; }
            .artwork-website-state { margin-left:auto; }
            .artwork-website-form { padding:18px 14px 14px; }
            .artwork-website-settings,.artwork-website-sale-grid { grid-template-columns:1fr; }
            .artwork-cover-picker > summary { min-height:98px; grid-template-columns:70px minmax(0,1fr); gap:12px; }
            .artwork-cover-current-image { width:70px; height:78px; }
            .artwork-cover-change { grid-column:2; padding:0; }
            .artwork-cover-option { flex-basis:126px; grid-template-rows:136px auto; }
            .artwork-cover-option img { height:136px; }
            .artwork-website-shipping { align-items:flex-start; flex-direction:column; }
            .artwork-website-actions { justify-content:stretch; }
            .artwork-website-actions button { flex:1 1 100%; width:100%; }

            .artwork-session-header,
            .artwork-page-header {
                display: none !important;
            }

            .artwork-page-actions,
            .related-mockup-page-actions,
            .alert-strip,
            .artwork-root-views-card > h3 {
                display: none !important;
            }

            .artwork-studio-note-mobile { display:flex; justify-content:flex-end; margin:0 0 12px; }
            .artwork-studio-note-mobile .artwork-studio-note-link { flex-basis:112px; width:112px; height:112px; padding:14px; }
            .related-mockups-empty { grid-template-columns:1fr 112px; gap:18px; padding:16px; }
            .related-mockups-create-decision { width:112px; height:112px; padding:14px; }

            .artwork-page-header {
                margin-bottom: 8px;
            }

            .artwork-page-header > div:first-child p {
                display: none;
            }

            .artwork-title-quick-edit { align-items:stretch; flex-direction:column; }

            .artwork-overview-panel {
                padding: 14px 10px;
            }

            .artwork-overview-panel > .section-heading {
                margin-bottom: 12px;
            }

            .artwork-overview-panel > .section-heading p {
                display: none;
            }

            .artwork-primary-metadata-card.metadata-unvalidated {
                display: none;
            }

            .artwork-metadata-secondary-row.metadata-unvalidated,
            .favorite-mockups-panel.mobile-defer-until-metadata {
                display: none;
            }

            .v2-admin-actions {
                align-items: stretch;
                flex-direction: column;
            }

            .v2-admin-actions form,
            .v2-admin-actions button {
                width: 100%;
            }

            .v2-admin-panel {
                padding: 0 10px;
                border: 0;
                background: #d8b7b5;
                box-shadow: none;
            }

            .v2-mobile-toggle {
                display: flex;
                width: 100%;
                align-items: center;
                justify-content: space-between;
                min-height: 0;
                padding: 10px 2px;
                border: 0;
                border-radius: 0;
                background: transparent;
                box-shadow: none;
                color: var(--accent);
                font-family: var(--font-serif);
                font-size: 20px;
                font-weight: 500;
                letter-spacing: 0;
                text-align: left;
                text-transform: none;
            }

            .v2-mobile-toggle:hover,
            .v2-mobile-toggle:focus {
                border: 0;
                background: transparent;
                box-shadow: none;
                color: var(--accent-dark, var(--accent));
            }

            .v2-mobile-toggle::after {
                content: '+';
                font-family: sans-serif;
                font-size: 24px;
                font-weight: 400;
            }

            .v2-admin-panel.is-open .v2-mobile-toggle::after {
                content: '−';
            }

            .v2-admin-panel:not(.is-open) > :not(.v2-mobile-toggle) {
                display: none !important;
            }

            .mobile-root-artwork {
                display: block;
                margin: 0 0 12px;
            }

            .mobile-root-artwork a,
            .mobile-root-artwork img {
                display: block;
                width: 100%;
            }

            .mobile-root-artwork img {
                height: auto;
                max-height: 72vh;
                object-fit: contain;
                background: transparent;
                border: 0;
                border-radius: 0;
            }

            .artwork-root-views-card {
                padding: 0;
                border: 0;
                background: transparent;
            }

            .artwork-overview-grid,
            .root-overview-media-grid {
                width: 100%;
            }

            .mobile-root-artwork-label {
                display: block;
                margin-top: 7px;
                color: var(--muted);
                font-size: 10px;
                font-weight: 700;
                letter-spacing: .08em;
                text-transform: uppercase;
            }

            .root-version-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .root-version-grid.has-final-video {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                grid-template-rows: none;
                grid-auto-flow: row;
            }

            .root-version-grid.has-final-video > .root-version-card:nth-child(n) {
                grid-column: auto;
                grid-row: auto;
            }

            .root-version-grid .root-version-card.is-selected {
                display: none;
            }

            .metadata-grid {
                grid-template-columns: 1fr;
            }

            .compact-title-main {
                align-items: flex-start;
                flex-direction: column;
            }

            .artwork-sheet-card-head {
                align-items: stretch;
                flex-direction: column;
            }

            .artwork-metadata-action-form,
            .artwork-metadata-action-form button,
            .artwork-metadata-form-grid,
            .artwork-metadata-save,
            .artwork-metadata-save button {
                width: 100%;
            }

            .artwork-metadata-form-grid {
                grid-template-columns: 1fr;
            }

        }
    </style>
    <link rel="stylesheet" href="media-controls.css?v=2">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header artwork-session-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="workspace">
            <div class="workspace-header artwork-page-header <?= $bilingualExperiment ? 'artwork-page-header--bilingual' : '' ?>">
                <div class="artwork-title-block">
                    <?php if ($bilingualExperiment): ?><span class="artwork-title-universal-label">Título universal</span><?php endif; ?>
                    <div class="artwork-title-heading">
                        <h1><?= h($displayTitle) ?></h1>
                        <button
                            class="artwork-title-edit-button"
                            type="button"
                            data-artwork-title-edit
                            aria-label="Edit artwork title"
                            title="Edit artwork title"
                            aria-expanded="false"
                            aria-controls="artwork-title-quick-edit"
                        ><?= $editIconSvg ?></button>
                    </div>
                    <form method="post" class="artwork-title-quick-edit" id="artwork-title-quick-edit" hidden>
                        <input type="hidden" name="action" value="save_artwork_title">
                        <label class="sr-only" for="artwork-title-input">Artwork title</label>
                        <input id="artwork-title-input" type="text" name="title" value="<?= h($displayTitle) ?>" maxlength="255" required autocomplete="off">
                        <button type="submit">Save title</button>
                        <button type="button" class="artwork-title-cancel" data-artwork-title-cancel>Cancel</button>
                    </form>
                    <?php if ($bilingualExperiment): ?><p class="artwork-title-universal-memo">STRATA X — LIMEN · STRATA XI — NUHRĀ (ܢܘܗܪܐ) · no traducir</p><?php endif; ?>
                </div>
                <a class="button-link artwork-studio-note-link" href="website_studio_notes.php?source=artwork:<?= (int)$id ?>#new-studio-note">Create Studio Note</a>
            </div>

            <?php if (isset($_GET['series_updated'])): ?>
                <div class="notice">Artwork series updated.</div>
            <?php endif; ?>
            <?php if (isset($_GET['saved'])): ?>
                <div class="notice">Artwork sheet saved.</div>
            <?php endif; ?>
            <?php if (isset($_GET['title_saved'])): ?>
                <div class="notice">Artwork title saved.</div>
            <?php endif; ?>
            <?php if (isset($_GET['title_error'])): ?>
                <div class="notice error"><?= h((string)$_GET['title_error']) ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['metadata_generated'])): ?>
                <div class="notice">Artwork metadata generated with the admin analysis prompt.</div>
            <?php endif; ?>
            <?php if (isset($_GET['metadata_saved'])): ?>
                <div class="notice">Artwork metadata saved.</div>
            <?php endif; ?>
            <?php if (isset($_GET['v2_applied'])): ?>
                <div class="notice">Analysis v2 validated as the artwork metadata. Nothing was published.</div>
            <?php endif; ?>
            <?php if (isset($_GET['v2_generated'])): ?>
                <div class="notice">Analysis v2 draft generated. Review it below; nothing was published or applied.</div>
            <?php endif; ?>
            <?php if (isset($_GET['website_v2_synced'])): ?>
                <div class="notice">Artwork sent to the Maurizio Valch catalogue. It remains hidden until you enable it in the website admin.</div>
            <?php endif; ?>
            <?php if (isset($_GET['website_saved'])): ?>
                <div class="notice">Website entry <?= h((string)$_GET['website_saved']) ?>.</div>
            <?php endif; ?>
            <?php if (isset($_GET['website_error'])): ?>
                <div class="notice error"><?= h((string)$_GET['website_error']) ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['metadata_error'])): ?>
                <div class="notice error"><?= h((string)$_GET['metadata_error']) ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['root_selected'])): ?>
                <div class="notice">Root artwork selected.</div>
            <?php endif; ?>
            <?php if (isset($_GET['root_views_completed'])): ?>
                <div class="notice">The two missing root views were added to this artwork.</div>
            <?php endif; ?>

            <div class="artwork-studio-note-mobile">
                <a class="button-link artwork-studio-note-link" href="website_studio_notes.php?source=artwork:<?= (int)$id ?>#new-studio-note">Create Studio Note</a>
            </div>

            <?php if ($analysisNeedsRefresh): ?>
                <div class="notice error">
                    This analysis does not match the current minimal schema. Recalculate the analysis before generating new mockups.
                </div>
            <?php endif; ?>

            <?php ob_start(); ?>
            <?php if ($bilingualExperiment): ?>
                <?php
                $bilingualEditorialFields = [
                    ['es' => 'Subtítulo', 'en' => 'Subtitle', 'value' => $displaySubtitle, 'description' => false, 'es_placeholder' => 'Subtítulo editorial en español…', 'en_placeholder' => 'No English subtitle is currently available.'],
                    ['es' => 'Descripción', 'en' => 'Description', 'value' => $displayDescription, 'description' => true, 'es_placeholder' => 'Escribí la descripción original en español…', 'en_placeholder' => 'No English description is currently available.'],
                    ['es' => 'Resumen breve', 'en' => 'Short description', 'value' => (string)($artworkSheet['short_description'] ?? ''), 'description' => false, 'es_placeholder' => 'Dos o tres frases para series, tarjetas y mockups…', 'en_placeholder' => 'No English short description is currently available.'],
                    ['es' => 'Palabras clave', 'en' => 'Keywords', 'value' => (string)($artworkSheet['keywords'] ?? ''), 'description' => false, 'es_placeholder' => 'Conceptos, temas y materiales…', 'en_placeholder' => 'No English keywords are currently available.'],
                    ['es' => 'Etiquetas', 'en' => 'Tags', 'value' => (string)($artworkSheet['tags'] ?? ''), 'description' => false, 'es_placeholder' => 'Etiquetas editoriales…', 'en_placeholder' => 'No English tags are currently available.'],
                    ['es' => 'Términos de búsqueda', 'en' => 'Long-tail terms', 'value' => implode("\n", array_map('strval', $artworkSheetLongTail)), 'description' => false, 'es_placeholder' => 'Frases de búsqueda en español…', 'en_placeholder' => 'No English search terms are currently available.'],
                    ['es' => 'Texto alternativo', 'en' => 'Alt text', 'value' => (string)($artworkSheet['alt_text'] ?? ''), 'description' => false, 'es_placeholder' => 'Descripción visual accesible…', 'en_placeholder' => 'No English alt text is currently available.'],
                    ['es' => 'Caption', 'en' => 'Caption', 'value' => (string)($artworkSheet['caption'] ?? ''), 'description' => false, 'es_placeholder' => 'Texto breve para publicación…', 'en_placeholder' => 'No English caption is currently available.'],
                ];
                ?>
                <details class="bilingual-editorial-panel" id="artwork-metadata">
                    <summary>
                        <span class="bilingual-editorial-summary">
                            <strong>Espacio editorial</strong>
                            <span>Contenido original en español y versión publicada en inglés.</span>
                        </span>
                        <span class="bilingual-editorial-state">Español + English</span>
                    </summary>
                    <div class="bilingual-editorial-spread">
                        <article class="bilingual-editorial-page bilingual-editorial-page--source">
                            <span class="bilingual-editorial-language">Español · fuente</span>
                            <?php foreach ($bilingualEditorialFields as $field): ?>
                                <section class="bilingual-editorial-field <?= $field['description'] ? 'bilingual-editorial-field--description' : '' ?>">
                                    <label><?= h($field['es']) ?></label>
                                    <div class="bilingual-editorial-copy" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="<?= h($field['es_placeholder']) ?>"></div>
                                </section>
                            <?php endforeach; ?>
                        </article>
                        <article class="bilingual-editorial-page bilingual-editorial-page--english">
                            <span class="bilingual-editorial-language">English · current version</span>
                            <?php foreach ($bilingualEditorialFields as $field): ?>
                                <section class="bilingual-editorial-field <?= $field['description'] ? 'bilingual-editorial-field--description' : '' ?>">
                                    <label><?= h($field['en']) ?></label>
                                    <div class="bilingual-editorial-copy" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="<?= h($field['en_placeholder']) ?>"><?= h($field['value']) ?></div>
                                </section>
                            <?php endforeach; ?>
                        </article>
                    </div>
                    <details class="bilingual-editorial-memo">
                        <summary>Memo privado de la obra</summary>
                        <div class="bilingual-editorial-copy" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Ideas, decisiones y recordatorios que no se publican…"></div>
                    </details>
                </details>
            <?php elseif (true): ?>
                <?php if ($v2Draft): ?>
                    <?php
                    $v2Editorial = (array)($v2Draft['canonical_editorial'] ?? []);
                    $v2Visual = (array)($v2Draft['visual_analysis'] ?? []);
                    $v2Interpretation = (array)($v2Draft['interpretation'] ?? []);
                    $v2Strategy = (array)($v2Draft['editorial_strategy'] ?? []);
                    $v2Originality = (array)($v2Draft['originality_check'] ?? []);
                    $v2Evidence = (array)($v2Draft['evidence_sources'] ?? []);
                    ?>
                    <section class="panel v2-admin-panel" id="artwork-metadata">
                        <button class="v2-mobile-toggle" type="button" aria-expanded="false">Artwork Metadata</button>
                        <div class="v2-admin-head">
                            <div class="section-heading" style="margin:0;">
                                <h2>Artwork Metadata</h2>
                                <p>Generated from the artwork analysis. Edit only what you need.</p>
                            </div>
                            <?php if ($isAdmin): ?><span class="v2-admin-badge"><?= ($v2Originality['passed'] ?? false) ? 'Analysis ready' : 'Review recommended' ?></span><?php endif; ?>
                        </div>

                        <article class="artwork-metadata-finished">
                            <h3><?= h($displayTitle) ?></h3>
                            <?php if ($displaySubtitle !== ''): ?><p class="metadata-subtitle"><?= h($displaySubtitle) ?></p><?php endif; ?>
                            <div class="metadata-description"><?= h($displayDescription) ?></div>
                        </article>

                        <details class="artwork-metadata-edit">
                            <summary>Edit metadata</summary>
                        <form method="post" class="v2-admin-card artwork-metadata-v2-form">
                            <input type="hidden" name="action" value="save_artwork_metadata">
                            <div class="artwork-metadata-form-grid">
                                <div class="artwork-metadata-field"><label>Title</label><input type="text" name="title" value="<?= h($displayTitle) ?>"></div>
                                <div class="artwork-metadata-field"><label>Subtitle</label><input type="text" name="subtitle" value="<?= h($displaySubtitle) ?>"></div>
                                <div class="artwork-metadata-field full"><label>Description</label><textarea name="description" rows="5"><?= h($displayDescription) ?></textarea></div>
                                <details class="artwork-metadata-more">
                                    <summary>More metadata</summary>
                                    <div class="artwork-metadata-more-grid">
                                        <div class="artwork-metadata-field full"><label>Short description</label><textarea name="short_description" rows="3"><?= h((string)($artworkSheet['short_description'] ?? '')) ?></textarea></div>
                                        <div class="artwork-metadata-field"><label>Keywords</label><textarea name="keywords" rows="4"><?= h((string)($artworkSheet['keywords'] ?? '')) ?></textarea></div>
                                        <div class="artwork-metadata-field"><label>Tags</label><textarea name="tags" rows="4"><?= h((string)($artworkSheet['tags'] ?? '')) ?></textarea></div>
                                        <div class="artwork-metadata-field"><label>Alt text</label><textarea name="alt_text" rows="4"><?= h((string)($artworkSheet['alt_text'] ?? '')) ?></textarea></div>
                                        <div class="artwork-metadata-field"><label>Caption</label><textarea name="caption" rows="4"><?= h((string)($artworkSheet['caption'] ?? '')) ?></textarea></div>
                                    </div>
                                </details>
                                <input type="hidden" name="long_tail_terms" value="<?= h(implode(', ', array_map('strval', $artworkSheetLongTail))) ?>">
                                <input type="hidden" name="medium" value="<?= h((string)($artwork['medium'] ?? '')) ?>">
                                <input type="hidden" name="artwork_year" value="<?= h((string)($artwork['artwork_year'] ?? '')) ?>">
                                <input type="hidden" name="series" value="<?= h((string)($artwork['series'] ?? '')) ?>">
                            </div>
                            <div class="artwork-metadata-save"><button type="submit">Save</button></div>
                        </form>
                        </details>

                        <?php if($isAdmin): ?><details class="details-panel admin-analysis-details"><summary>Analysis details</summary><div class="v2-admin-grid">
                            <div class="v2-admin-card">
                                <h3>Editorial strategy</h3>
                                <p>Opening: <strong><?= h($v2Strategy['description_opening_type'] ?? '') ?></strong></p>
                                <p>Rhythm: <?= h($v2Strategy['description_opening_rhythm'] ?? '') ?></p>
                                <p>Structure: <?= h($v2Strategy['description_structure_type'] ?? '') ?></p>
                            </div>
                            <div class="v2-admin-card">
                                <h3>Originality</h3>
                                <p>Title similarity: <?= h(number_format(((float)($v2Originality['title_similarity']??0))*100, 1)) ?>%</p>
                                <p>Description similarity: <?= h(number_format(((float)($v2Originality['description_similarity']??0))*100, 1)) ?>%</p>
                                <p><?= ($v2Originality['passed'] ?? false) ? 'No blockers.' : h(implode(' ', (array)($v2Originality['warnings']??[]))) ?></p>
                            </div>
                            <div class="v2-admin-card">
                                <h3>Evidence provenance</h3>
                                <p><?= count((array)($v2Evidence['artist_or_record_facts']??[])) ?> artist/record facts</p>
                                <p><?= count((array)($v2Evidence['visual_observations']??[])) ?> visual observations</p>
                                <p><?= count((array)($v2Evidence['interpretive_claims']??[])) ?> interpretive claims</p>
                            </div>
                        </div></details>

                        <details class="details-panel v2-admin-details">
                            <summary>Visual analysis, interpretation, and paragraph plan</summary>
                            <div class="v2-admin-grid">
                                <div class="v2-admin-card"><h3>Surface and movement</h3><p><?= h($v2Visual['surface_and_texture']??'') ?></p><p><?= h($v2Visual['rhythm_and_movement']??'') ?></p></div>
                                <div class="v2-admin-card"><h3>Central reading</h3><p><?= h($v2Interpretation['central_reading']??'') ?></p></div>
                                <div class="v2-admin-card"><h3>Paragraph functions</h3><ol style="margin:0;padding-left:18px;font-size:12px;line-height:1.55;"><?php foreach ((array)($v2Strategy['paragraph_functions']??[]) as $item): ?><li><?= h($item) ?></li><?php endforeach; ?></ol></div>
                            </div>
                        </details>

                        <details class="details-panel v2-admin-details">
                            <summary>Prompt used</summary>
                            <?php $v2PromptFile = (string)($v2DraftMatch['file'] ?? '') . '.prompt.txt'; ?>
                            <pre style="white-space:pre-wrap;max-height:360px;overflow:auto;font-size:10px;"><?= h(is_file($v2PromptFile) ? file_get_contents($v2PromptFile) : 'Prompt file is not available for this draft.') ?></pre>
                        </details>
                        <?php endif; ?>

                        <div class="v2-admin-actions">
                            <?php if ($isAdmin): ?>
                                <details class="details-panel"><summary>Admin tools</summary><form method="post" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Generating…';"><input type="hidden" name="action" value="generate_v2_artwork_draft"><button type="submit" class="secondary">Regenerate analysis</button></form></details>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php else: ?>
                    <section class="panel v2-admin-panel" id="artwork-metadata">
                        <button class="v2-mobile-toggle" type="button" aria-expanded="false">Artwork Metadata</button>
                        <div class="v2-admin-head">
                            <div class="section-heading" style="margin:0;"><h2>Artwork Metadata</h2><p><?= $metadataErrorMessage !== '' ? 'The analysis finished but did not pass validation.' : 'No metadata exists for this artwork yet.' ?></p></div>
                            <form method="post" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Generating v2…';">
                                <input type="hidden" name="action" value="generate_v2_artwork_draft">
                                <button type="submit">Generate Metadata</button>
                            </form>
                        </div>
                        <?php if ($metadataErrorMessage !== ''): ?><div class="notice error" style="margin:14px 0 0;"><?= h($metadataErrorMessage) ?></div><?php endif; ?>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
            <?php $artworkMetadataPanelHtml = ob_get_clean(); ?>

            <section class="panel artwork-overview-panel">
                <div class="section-heading">
                    <div>
                        <h2><?= $artworkSeriesName !== '' ? h($artworkSeriesName) . ' Series' : 'NO SERIE' ?></h2>
                        <p>Root views, basic information, and direct access to the mockup workflow.</p>
                    </div>
                    <div class="artwork-series-controls">
                        <form method="post" class="artwork-series-form">
                            <input type="hidden" name="action" value="assign_series">
                            <label>
                                <span>Series</span>
                                <select name="series_id" aria-label="Artwork series" onchange="this.form.classList.add('is-saving'); this.form.requestSubmit()">
                                    <option value="">NO SERIE</option>
                                    <?php foreach ($artworkSeriesRows as $seriesRow): ?>
                                        <option value="<?= (int)$seriesRow['id'] ?>" <?= (int)($artwork['series_id'] ?? 0) === (int)$seriesRow['id'] ? 'selected' : '' ?>><?= h($seriesRow['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </form>
                    </div>
                </div>
                <?php if ($rootFileAvailable): ?>
                    <form id="artwork-generate-metadata-form" class="artwork-metadata-action-form" method="post">
                        <input type="hidden" name="action" value="generate_v2_artwork_draft">
                    </form>
                    <div class="artwork-overview-grid">
                        <div class="artwork-overview-main">
                            <section class="artwork-root-views-card">
                            <h3>Root Views</h3>
                            <div class="root-overview-media-grid">
                                <div class="mobile-root-artwork">
                                    <a href="<?= h('viewer.php?file=' . rawurlencode($rootFile) . '&back=' . rawurlencode('artwork.php?id=' . (int)$id)) ?>">
                                        <img src="<?= h(media_url($rootFile)) ?>" alt="Selected root artwork">
                                    </a>
                                    <span class="mobile-root-artwork-label">Selected root artwork</span>
                                </div>
                                <?php if (!empty($rootCandidatesList) || !empty($missingRootViews) || !empty($artworkFinalVideos)): ?>
                                    <?php
                                    $viewLabels = [
                                        'frontal'             => 'Frontal',
                                        'three-quarter-left'  => '3/4 Left',
                                        'three-quarter-right' => '3/4 Right',
                                    ];
                                    $latestArtworkFinalVideo = is_array($artworkFinalVideos[0] ?? null) ? $artworkFinalVideos[0] : null;
                                    $rootVisualItems = [];
                                    foreach (array_slice($rootCandidatesList, 0, 3) as $candidate) {
                                        $candidateFile = basename((string)($candidate['file_name'] ?? ''));
                                        if (!artwork_result_file_available($candidateFile)) continue;
                                        $rootVisualItems[] = ['type' => 'image', 'candidate' => $candidate, 'file' => $candidateFile];
                                        if (count($rootVisualItems) === 1 && $latestArtworkFinalVideo) {
                                            $rootVisualItems[] = ['type' => 'video', 'video' => $latestArtworkFinalVideo];
                                        }
                                    }
                                    if ($rootVisualItems === [] && $latestArtworkFinalVideo) {
                                        $rootVisualItems[] = ['type' => 'video', 'video' => $latestArtworkFinalVideo];
                                    }
                                    ?>
                                    <div class="root-version-grid <?= $latestArtworkFinalVideo ? 'has-final-video' : '' ?>">
                                        <?php foreach ($rootVisualItems as $visualItem): ?>
                                            <?php if ($visualItem['type'] === 'video'): ?>
                                                <?php
                                                $finalVideo = (array)$visualItem['video'];
                                                $finalVideoUrl = (string)($finalVideo['previewUrl'] ?? '');
                                                $finalVideoPoster = (string)($finalVideo['thumbnailUrl'] ?? '');
                                                ?>
                                                <article class="root-version-card root-version-video-card">
                                                    <div class="root-version-video-link">
                                                        <video src="<?= h($finalVideoUrl) ?>"<?= $finalVideoPoster !== '' ? ' poster="' . h($finalVideoPoster) . '"' : '' ?> controls controlslist="noremoteplayback nodownload" disablepictureinpicture disableremoteplayback playsinline preload="metadata"></video>
                                                    </div>
                                                    <a class="root-version-video-download" href="<?= h($finalVideoUrl) ?>&amp;download=1" aria-label="Download final video" title="Download MP4">
                                                        <svg viewBox="0 0 20 20" aria-hidden="true">
                                                            <path d="M10 3v9m-3-3 3 3 3-3M4 15.5h12"></path>
                                                        </svg>
                                                    </a>
                                                </article>
                                            <?php else: ?>
                                                <?php
                                                $rca = (array)$visualItem['candidate'];
                                                $rcaFile = (string)$visualItem['file'];
                                                $rcaLabel = $viewLabels[$rca['view_type']] ?? $rca['view_type'];
                                                $rcaIsSelected = !empty($rca['is_selected']) || $rcaFile === $rootFile;
                                                ?>
                                                <article class="root-version-card <?= $rcaIsSelected ? 'is-selected' : '' ?>">
                                                    <a href="<?= h('viewer.php?file=' . rawurlencode($rcaFile) . '&back=' . rawurlencode('artwork.php?id=' . (int)$id)) ?>">
                                                        <img src="<?= h(media_url($rcaFile)) ?>" alt="<?= h($rcaLabel) ?>">
                                                    </a>
                                                    <div class="root-version-overlay">
                                                        <?php if ($rcaIsSelected): ?>
                                                            <span class="root-version-selected-pill">Selected</span>
                                                        <?php else: ?>
                                                            <form method="post">
                                                                <input type="hidden" name="action" value="select_root_candidate">
                                                                <input type="hidden" name="candidate_id" value="<?= (int)$rca['id'] ?>">
                                                                <input type="hidden" name="candidate_file" value="<?= h($rcaFile) ?>">
                                                                <button class="root-version-select" type="submit" title="Select root view" aria-label="Select root view">✓</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="root-version-label">
                                                        <span><?= h($rcaLabel) ?></span>
                                                    </div>
                                                </article>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php foreach ($missingRootViews as $missingViewType => $missingViewLabel): ?>
                                            <article class="root-version-card root-version-missing">
                                                <form method="post" action="complete_root_views.php" onsubmit="return confirm('Generate the missing root views from the current artwork?');">
                                                    <input type="hidden" name="artwork_id" value="<?= (int)$id ?>">
                                                    <button type="submit" title="Generate missing root views">
                                                        <svg viewBox="0 0 48 48" aria-hidden="true">
                                                            <rect x="7" y="10" width="34" height="28" rx="3"></rect>
                                                            <path d="M17 10l3-4h8l3 4M24 18v12M18 24h12"></path>
                                                        </svg>
                                                        <strong><?= h($missingViewLabel) ?></strong>
                                                        <small>Generate both missing views</small>
                                                    </button>
                                                </form>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="notice">Only the selected root image is available.</div>
                                <?php endif; ?>
                            </div>
                            </section>

                            <div class="artwork-metadata-slot"><?= $artworkMetadataPanelHtml ?></div>

                            <details class="artwork-website-panel" id="website-publication">
                                <summary>
                                    <span class="artwork-website-summary">
                                        <strong>Website</strong>
                                        <span>Publication, sale and delivery settings</span>
                                    </span>
                                    <span class="artwork-website-state"><?= h(str_replace('_', ' ', $websiteStatus)) ?></span>
                                </summary>
                                <form method="post" class="artwork-website-form">
                                    <input type="hidden" name="action" value="save_artwork_website">
                                    <p class="artwork-website-note">Title, descriptions, SEO keywords, tags, alt text and captions come directly from Artwork Metadata. <strong><?= $websiteMetadataComplete ? 'Content complete.' : 'Some metadata is still incomplete.' ?></strong></p>

                                    <section class="artwork-website-sale" aria-labelledby="website-sale-title">
                                        <h4 id="website-sale-title">Price and availability</h4>
                                        <div class="artwork-website-sale-grid">
                                            <label>Availability
                                                <select name="sale_status">
                                                    <?php foreach (['draft' => 'Not for sale', 'active' => 'Available', 'paused' => 'Temporarily unavailable', 'sold_out' => 'Sold'] as $value => $label): ?>
                                                        <option value="<?= h($value) ?>" <?= (string)($websiteSale['status'] ?? 'draft') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label>Available units<input type="number" min="0" step="1" name="sale_stock" value="<?= $websiteSaleAvailable ?>"></label>
                                            <label>Price<input inputmode="decimal" name="sale_price" value="<?= $websiteSale ? h(number_format((int)$websiteSale['price_minor'] / 100, 2, '.', '')) : '' ?>" placeholder="2500.00"></label>
                                            <label>Currency<input name="sale_currency" maxlength="3" value="<?= h((string)($websiteSale['currency'] ?? $websiteDefaultCurrency)) ?>"></label>
                                        </div>
                                        <div class="artwork-website-shipping">
                                            <span>Shipping uses the editable rates by continent for the whole store.</span>
                                            <a href="../site-admin/?area=store&amp;section=shipping">Edit shipping rates</a>
                                        </div>
                                    </section>

                                    <div class="artwork-website-settings">
                                        <label>Visibility
                                            <select name="visibility">
                                                <?php foreach (['public' => 'Public', 'unlisted' => 'Unlisted', 'private' => 'Private'] as $value => $label): ?>
                                                    <option value="<?= h($value) ?>" <?= $websiteVisibility === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>Constellation country<input name="constellation_country" value="<?= h($websiteConstellationCountry) ?>" placeholder="Optional"></label>
                                        <div class="artwork-website-cover">
                                            <span class="artwork-website-cover-label">Website cover</span>
                                            <details class="artwork-cover-picker" data-website-cover-picker>
                                                <summary>
                                                    <img
                                                        class="artwork-cover-current-image"
                                                        src="<?= h(media_url((string)$websiteSelectedCoverItem['file'])) ?>"
                                                        alt=""
                                                        data-cover-current-image
                                                    >
                                                    <span class="artwork-cover-current-copy">
                                                        <strong data-cover-current-label><?= h((string)$websiteSelectedCoverItem['label']) ?></strong>
                                                        <span data-cover-current-type><?= h((string)$websiteSelectedCoverItem['type']) ?> selected for the website</span>
                                                    </span>
                                                    <span class="artwork-cover-change">Change cover</span>
                                                </summary>
                                                <div class="artwork-cover-options" role="radiogroup" aria-label="Choose website cover">
                                                    <?php foreach ($websiteCoverItems as $file => $item): ?>
                                                        <?php $coverSelected = $websiteSelectedCover === $file; ?>
                                                        <label class="artwork-cover-option <?= $coverSelected ? 'is-selected' : '' ?>" data-cover-option>
                                                            <input
                                                                type="radio"
                                                                name="header_file"
                                                                value="<?= h($file) ?>"
                                                                data-cover-image="<?= h(media_url($file)) ?>"
                                                                data-cover-label="<?= h((string)$item['label']) ?>"
                                                                data-cover-type="<?= h((string)$item['type']) ?>"
                                                                <?= $coverSelected ? 'checked' : '' ?>
                                                            >
                                                            <img src="<?= h(media_url($file)) ?>" alt="<?= h((string)$item['label']) ?>" loading="lazy">
                                                            <span class="artwork-cover-option-copy">
                                                                <strong><?= h((string)$item['label']) ?></strong>
                                                                <small><?= $coverSelected ? 'Selected · ' : '' ?><?= h((string)$item['type']) ?></small>
                                                            </span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        </div>
                                        <div class="artwork-website-actions">
                                            <button type="submit" name="website_intent" value="save">Save website settings</button>
                                            <?php if ($websiteStatus === 'published'): ?>
                                                <button type="submit" name="website_intent" value="unpublish">Unpublish</button>
                                            <?php else: ?>
                                                <button class="website-publish" type="submit" name="website_intent" value="publish">Publish artwork</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </form>
                            </details>

                            <form class="artwork-metadata-layout-form" method="post">
                                <input type="hidden" name="action" value="save_artwork_metadata">
                                <section class="artwork-sheet-card artwork-primary-metadata-card <?= $artworkMetadataValidated ? 'metadata-validated' : 'metadata-unvalidated' ?>">
                                <div class="artwork-sheet-card-head">
                                    <div>
                                        <h3>Artwork Metadata</h3>
                                        <p>Title, subtitle, and description.</p>
                                    </div>
                                    <div class="topbar-actions">
                                        <button type="submit" form="artwork-generate-metadata-form" class="<?= $artworkSheetHasMetadata ? 'secondary' : '' ?>"><?= $v2Draft ? 'Regenerate v2 draft' : 'Generate v2 draft' ?></button>
                                    </div>
                                </div>

                                <div class="artwork-metadata-form">
                                    <div class="artwork-metadata-form-grid">
                                        <div class="artwork-metadata-field">
                                            <label>Title</label>
                                            <input type="text" name="title" value="<?= h($displayTitle) ?>">
                                        </div>

                                        <div class="artwork-metadata-field">
                                            <label>Subtitle</label>
                                            <input type="text" name="subtitle" value="<?= h($displaySubtitle) ?>">
                                        </div>

                                        <div class="artwork-metadata-field full">
                                            <label>Description</label>
                                            <textarea name="description" rows="4"><?= h($displayDescription) ?></textarea>
                                        </div>
                                    </div>

                                    <div class="artwork-metadata-save">
                                        <button type="submit">Save Metadata</button>
                                    </div>
                                </div>
                                </section>

                                <details class="artwork-metadata-editor artwork-metadata-secondary-row <?= $artworkMetadataValidated ? 'metadata-validated' : 'metadata-unvalidated' ?>">
                                <summary>More metadata</summary>
                                <div class="artwork-metadata-form">
                                    <div class="artwork-metadata-sections">
                                        <details class="details-panel">
                                            <summary style="font-size: 12px;">SEO Metadata</summary>
                                            <div class="artwork-metadata-field">
                                                <label>Short Description</label>
                                                <textarea name="short_description" rows="2"><?= h((string)($artworkSheet['short_description'] ?? '')) ?></textarea>
                                            </div>

                                            <div class="artwork-metadata-field">
                                                <label>Tags</label>
                                                <textarea name="tags" rows="2"><?= h((string)($artworkSheet['tags'] ?? '')) ?></textarea>
                                            </div>

                                            <div class="artwork-metadata-field">
                                                <label>Long Tail Terms</label>
                                                <textarea name="long_tail_terms" rows="2"><?= h(implode("\n", array_map('strval', $artworkSheetLongTail))) ?></textarea>
                                            </div>

                                            <div class="artwork-metadata-field">
                                                <label>Keywords</label>
                                                <textarea name="keywords" rows="2"><?= h((string)($artworkSheet['keywords'] ?? '')) ?></textarea>
                                            </div>

                                            <div class="artwork-metadata-field">
                                                <label>Alt Text</label>
                                                <textarea name="alt_text" rows="2"><?= h((string)($artworkSheet['alt_text'] ?? '')) ?></textarea>
                                            </div>

                                            <div class="artwork-metadata-field">
                                                <label>Caption</label>
                                                <textarea name="caption" rows="2"><?= h((string)($artworkSheet['caption'] ?? '')) ?></textarea>
                                            </div>
                                        </details>

                                        <details class="details-panel">
                                            <summary style="font-size: 12px;">Basic Information</summary>
                                            <div class="artwork-sheet-meta">
                                                <div class="artwork-sheet-meta-row">
                                                    <strong>Measurements</strong>
                                                    <span><?= h($sizeText) ?></span>
                                                </div>
                                            </div>
                                            <div class="artwork-metadata-field">
                                                <label>Technique / Support</label>
                                                <input type="text" name="medium" value="<?= h((string)($artwork['medium'] ?? '')) ?>">
                                            </div>
                                            <div class="artwork-metadata-field">
                                                <label>Year</label>
                                                <input type="text" name="artwork_year" value="<?= h((string)($artwork['artwork_year'] ?? '')) ?>">
                                            </div>
                                            <div class="artwork-metadata-field">
                                                <label>Series</label>
                                                <input type="text" name="series" value="<?= h((string)($artwork['series'] ?? '')) ?>">
                                            </div>
                                        </details>
                                    </div>

                                    <div class="artwork-metadata-save">
                                        <button type="submit">Save Metadata</button>
                                    </div>
                                </div>
                                </details>
                            </form>
                        </div>

                        <aside class="favorite-mockups-panel <?= $artworkMetadataValidated ? '' : 'mobile-defer-until-metadata' ?>">
                                <div class="related-mockups-title-row">
                                    <h3>Related Mockups <span class="related-mockups-count">· <?= count($relatedMockups) ?></span></h3>
                                    <a class="related-mockups-upload-link" href="mockup_upload.php?id=<?= (int)$id ?>">+ Import</a>
                                </div>
                                <?php if ($relatedMockups): ?>
                                    <div class="related-mockups-sidebar-grid">
                                        <?php foreach ($relatedMockups as $sidebarMockup): ?>
                                            <?php
                                            $sidebarFile = (string)$sidebarMockup['mockup_file_basename'];
                                            $sidebarState = (array)($sidebarMockup['selector_state'] ?? []);
                                            $sidebarCombo = (array)($sidebarState['combination'] ?? []);
                                            $sidebarLabel = trim((string)($sidebarCombo['camera_slot_name'] ?? $sidebarMockup['context_id'] ?? 'Mockup'));
                                            ?>
                                            <article class="related-mockup-card" data-mockup-card data-mockup-id="<?= (int)$sidebarMockup['id'] ?>">
                                                <div class="related-mockup-image">
                                                    <a href="<?= h('viewer.php?id=' . (int)$sidebarMockup['id'] . '&back=' . rawurlencode('artwork.php?id=' . (int)$id)) ?>">
                                                        <img src="<?= h('media.php?file=' . rawurlencode($sidebarFile)) ?>" alt="<?= h($sidebarLabel) ?>">
                                                    </a>
                                                    <button
                                                        class="favorite-overlay-btn media-icon-button media-icon-button--compact media-thumb-action media-thumb-action--left <?= !empty($sidebarMockup['is_favorite']) ? 'active' : '' ?>"
                                                        type="button"
                                                        title="<?= !empty($sidebarMockup['is_favorite']) ? 'Remove favorite' : 'Add favorite' ?>"
                                                        aria-label="<?= !empty($sidebarMockup['is_favorite']) ? 'Remove favorite' : 'Add favorite' ?>"
                                                        data-favorite-mockup
                                                        data-mockup-id="<?= (int)$sidebarMockup['id'] ?>"
                                                    ><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3.7 2.55 5.17 5.71.83-4.13 4.03.97 5.69L12 16.73l-5.1 2.69.97-5.69L3.74 9.7l5.71-.83L12 3.7Z"/></svg></button>
                                                    <button
                                                        class="mockup-delete-overlay-btn media-icon-button media-icon-button--compact media-thumb-action media-thumb-action--right is-danger"
                                                        type="button"
                                                        title="Delete mockup"
                                                        aria-label="Delete mockup"
                                                        data-delete-mockup
                                                        data-mockup-id="<?= (int)$sidebarMockup['id'] ?>"
                                                    ><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.5 8.5h9l-.65 10h-7.7l-.65-10Z"/><path d="M6 5.8h12M9.5 5.8V4h5v1.8M10.2 11.2v4.6M13.8 11.2v4.6"/></svg></button>
                                                    <?php if (!empty($sidebarMockup['variation_lab_available'])): ?>
                                                        <div class="related-mockup-actions">
                                                            <a class="button-link" href="mockup_variation_lab.php?mockup_id=<?= (int)$sidebarMockup['id'] ?>">Variation</a>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="favorite-empty related-mockups-empty">
                                        <p>No mockups have been created for this artwork yet.</p>
                                        <a class="related-mockups-create-decision" href="mockup_combinations_review.php?id=<?= (int)$id ?>">Create Mockups</a>
                                    </div>
                                <?php endif; ?>
                        </aside>
                    </div>
                <?php else: ?>
                    <div class="notice">No root artwork image is available yet.</div>
                <?php endif; ?>
            </section>

            <section class="artwork-sheet beta-hidden-stage">
                <aside class="root-panel">
                    <div style="background: var(--surface); border: 1px solid var(--line); padding: 16px; border-radius: var(--radius); box-shadow: var(--shadow); margin-bottom: 12px;">
                        <label style="margin-top: 0; font-size: 11px; text-transform: uppercase; font-weight: 600; color: var(--ink); letter-spacing: 0.05em; display: block; margin-bottom: 6px;">Slug / Filename Customizer</label>
                        <input type="text" id="seo_slug_input" class="form-control" value="<?= h($package['seo_slug']) ?>" style="width: 100%; box-sizing: border-box; padding: 8px 10px; font-size: 13px; font-family: monospace; border: 1px solid var(--line); border-radius: var(--radius);">
                        <small style="margin: 4px 0 0 0; color: var(--muted); font-size: 11px; line-height: 1.3; display: block;">Changes here will update all image download names dynamically.</small>
                    </div>

                    <div class="root-frame">
                        <?php if ($rootFileAvailable): ?>
                            <a href="<?= h('viewer.php?file=' . rawurlencode($rootFile) . '&back=' . rawurlencode('artwork.php?id=' . (int)$id)) ?>" title="Click to open full size">
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
                                        $rcaFile = artwork_result_file_available($rcaFile) ? $rcaFile : '';
                                        if ($rcaFile === '') continue;
                                        $rcaLabel = $viewLabels[$rca['view_type']] ?? $rca['view_type'];
                                        $rcaIsSelected = $rca['is_selected'];
                                    ?>
                                    <div style="position: relative; border: <?= $rcaIsSelected ? '2px solid var(--accent)' : '1px solid var(--line)' ?>; border-radius: var(--radius); overflow: hidden; background: var(--surface-soft);">
                                        <a href="<?= h('viewer.php?file=' . rawurlencode($rcaFile) . '&back=' . rawurlencode('artwork.php?id=' . (int)$id)) ?>">
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

                    <?php if ($rootFileAvailable): ?>
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
                         <summary style="font-weight: 600; cursor: pointer; color: var(--ink);">Curatorial Analysis</summary>
                         <div style="margin-top: 10px; line-height: 1.6; font-size: 13px; color: var(--ink);">
                             <p class="copy-block" style="margin: 0; font-style: italic;"><?= h($package['curatorial_reading']) ?></p>
                             <div style="margin-top: 8px; text-align: right;">
                            <button class="copy-button secondary" type="button" data-copy="<?= h($package['curatorial_reading']) ?>" aria-label="Copy Curatorial Reading" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                             </div>
                         </div>
                     </details>
                    <?php endif; ?>

                     <details class="details-panel" style="margin-top: 12px; padding: 12px 14px;" <?= isset($_GET['saved']) ? 'open' : '' ?>>
                        <summary style="font-weight: 600; cursor: pointer; color: var(--muted); font-size: 12px;">Basic Information</summary>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="action" value="save_sheet">
                            <input type="hidden" name="final_title" value="<?= h($selectedTitle) ?>">
                            <input type="hidden" name="subtitle" value="<?= h($selectedSubtitle) ?>">
                            <div class="pin-field" style="padding: 8px 10px; background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius); margin-bottom: 8px;">
                                <label style="font-size: 9px; text-transform: uppercase; color: var(--muted); display: block; margin-bottom: 2px; font-weight: 700; letter-spacing: 0.05em;">Measurements</label>
                                <span style="font-size: 12px; color: var(--ink);"><?= h($sizeText) ?></span>
                            </div>
                            <label style="font-size: 10px;">Technique / Support</label>
                            <input type="text" name="medium" value="<?= h($artwork['medium'] ?? '') ?>" placeholder="Acrylic on canvas">
                            <div class="row" style="grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px;">
                                <div>
                                    <label style="font-size: 10px;">Year</label>
                                    <input type="text" name="artwork_year" value="<?= h($artwork['artwork_year'] ?? '') ?>" placeholder="2026">
                                </div>
                                <div>
                                    <label style="font-size: 10px;">Series</label>
                                    <input type="text" name="series" value="<?= h($artwork['series'] ?? '') ?>" placeholder="Series name">
                                </div>
                            </div>
                            <button type="submit" style="margin-top: 10px; width: 100%; font-size: 10px; padding: 8px 10px;">Save Basic Information</button>
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
                                        <?php if ($rootFileAvailable): ?>
                                            <a href="<?= h('viewer.php?file=' . rawurlencode($rootFile) . '&back=' . rawurlencode('artwork.php?id=' . (int)$id)) ?>" title="Click to open full size">
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
                                                    $mViewerUrl = 'viewer.php?id=' . (int)$existingMockup['id'] . '&back=' . rawurlencode('artwork.php?id=' . (int)$id);
                                                ?>
                                                <a class="inline-thumb" href="<?= h($mViewerUrl) ?>" title="Open viewer" style="width: 100%;">
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
                                            <span class="selected-label" style="font-size: 8px; background: var(--accent-light); color: var(--accent);">Selected</span>
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
                                            <button type="submit" class="button" style="font-size: 10px; padding: 6px 10px; width: 100%;"><?= $t === $selectedTitle ? 'Selected' : 'Select' ?></button>
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
                                $descCopy = "[" . $label . " for: " . $selectedTitle . "]\n" . $desc;
                            ?>
                            <article class="premium-desc-block" style="background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius); padding: 18px; display: flex; flex-direction: column; gap: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <strong style="font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em;"><?= h($label) ?> — for "<?= h($selectedTitle) ?>"</strong>
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

    const artworkTitleEditButton = document.querySelector('[data-artwork-title-edit]');
    const artworkTitleEditForm = document.getElementById('artwork-title-quick-edit');
    const artworkTitleInput = document.getElementById('artwork-title-input');
    const closeArtworkTitleEditor = () => {
        if (!artworkTitleEditButton || !artworkTitleEditForm) return;
        artworkTitleEditForm.hidden = true;
        artworkTitleEditButton.setAttribute('aria-expanded', 'false');
        artworkTitleEditButton.focus();
    };
    artworkTitleEditButton?.addEventListener('click', () => {
        if (!artworkTitleEditForm) return;
        const willOpen = artworkTitleEditForm.hidden;
        artworkTitleEditForm.hidden = !willOpen;
        artworkTitleEditButton.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen && artworkTitleInput) {
            artworkTitleInput.focus();
            artworkTitleInput.select();
        }
    });
    document.querySelector('[data-artwork-title-cancel]')?.addEventListener('click', closeArtworkTitleEditor);
    artworkTitleInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeArtworkTitleEditor();
    });

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

    document.querySelectorAll('[data-favorite-mockup]').forEach((button) => {
        button.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            const formData = new FormData();
            formData.append('mockup_id', button.getAttribute('data-mockup-id') || '');
            button.disabled = true;
            try {
                const response = await fetch('toggle_mockup_favorite.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (!result.ok) {
                    throw new Error(result.error || 'Could not update favorite.');
                }
                button.classList.toggle('active', !!result.favorite);
                button.title = result.favorite ? 'Remove favorite' : 'Add favorite';
                button.setAttribute('aria-label', button.title);
            } catch (error) {
                alert(error.message);
            } finally {
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('[data-delete-mockup]').forEach((button) => {
        button.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (!confirm('Delete this mockup?')) {
                return;
            }
            const mockupId = button.getAttribute('data-mockup-id') || '';
            const formData = new FormData();
            formData.append('mockup_id', mockupId);
            button.disabled = true;
            try {
                const response = await fetch('delete_mockup_result.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (!result.ok) {
                    throw new Error(result.error || 'Could not delete mockup.');
                }
                document.querySelectorAll('[data-mockup-card]').forEach((card) => {
                    if (card.getAttribute('data-mockup-id') === mockupId) {
                        card.remove();
                    }
                });
            } catch (error) {
                alert(error.message);
                button.disabled = false;
            }
        });
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
                throw new Error(data.error || 'Could not generate mockup.');
            }

            // Successfully generated!
            card.classList.add('generated');
            
            // Update image preview
            const ctxName = card.querySelector('h3')?.textContent || 'Mockup';
            resultBox.innerHTML = `
                <a class="inline-thumb" href="${escapeAttribute((data.mockup_id || data.id) ? 'viewer.php?id=' + (data.mockup_id || data.id) + '&back=artwork.php?id=<?= (int)$id ?>' : data.image_url)}" title="Open viewer" style="width: 100%;">
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
                        formSubmitBtn.innerHTML = 'Generate Mockup';
                    }
                }
            } else {
                alert('Error: ' + (data.error || 'Could not delete mockup.'));
                deleteBtn.disabled = false;
            }
        } catch (err) {
            alert('Network error trying to delete.');
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
<script>
document.querySelectorAll('.v2-mobile-toggle').forEach((button) => {
    button.addEventListener('click', () => {
        const panel = button.closest('.v2-admin-panel');
        if (!panel) return;
        const open = panel.classList.toggle('is-open');
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
});
</script>
<script>
document.querySelectorAll('[data-website-cover-picker]').forEach((picker) => {
    const currentImage = picker.querySelector('[data-cover-current-image]');
    const currentLabel = picker.querySelector('[data-cover-current-label]');
    const currentType = picker.querySelector('[data-cover-current-type]');

    picker.querySelectorAll('input[name="header_file"]').forEach((input) => {
        input.addEventListener('change', () => {
            picker.querySelectorAll('[data-cover-option]').forEach((option) => {
                const optionInput = option.querySelector('input[name="header_file"]');
                const selected = optionInput === input;
                option.classList.toggle('is-selected', selected);
                const type = option.querySelector('small');
                if (type && optionInput) {
                    type.textContent = (selected ? 'Selected · ' : '') + (optionInput.dataset.coverType || 'Image');
                }
            });
            if (currentImage) currentImage.src = input.dataset.coverImage || '';
            if (currentLabel) currentLabel.textContent = input.dataset.coverLabel || 'Website cover';
            if (currentType) currentType.textContent = (input.dataset.coverType || 'Image') + ' selected for the website';
            window.setTimeout(() => { picker.open = false; }, 140);
        });
    });
});
</script>
</body>
</html>
