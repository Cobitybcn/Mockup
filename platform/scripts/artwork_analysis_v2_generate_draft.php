<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Support/ArtworkAnalysisV2.php';
require_once __DIR__ . '/../app/Support/ArtworkOriginalityChecker.php';
require_once __DIR__ . '/../app/Support/DescriptionDiversityEngine.php';

$image = $argv[1] ?? '';
$legacyFile = $argv[2] ?? '';
$output = $argv[3] ?? (__DIR__ . '/../tmp/drafts/artwork-analysis-v2-generated-draft.json');
$userId = max(1, (int)($argv[4] ?? 1));

if (!is_file($image) || !is_file($legacyFile)) {
    fwrite(STDERR, "Usage: php artwork_analysis_v2_generate_draft.php <image> <legacy-analysis.json> [output.json] [user-id]\n");
    exit(2);
}

$legacy = json_decode((string)file_get_contents($legacyFile), true);
if (!is_array($legacy)) {
    fwrite(STDERR, "Invalid legacy analysis JSON.\n");
    exit(2);
}

$profile = is_array($legacy['artwork_analysis'] ?? null) ? $legacy['artwork_analysis'] : $legacy;
$publishing = is_array($profile['publishing_metadata'] ?? null) ? $profile['publishing_metadata'] : [];
$titles = is_array($legacy['suggested_titles'] ?? null) ? $legacy['suggested_titles'] : ($publishing['suggested_titles'] ?? []);
$first = is_array($titles[0] ?? null) ? $titles[0] : [];
$artistProfile = ArtistProfile::findForUser($userId);
$excludeBase = pathinfo($image, PATHINFO_FILENAME);
$diversityContext = array_merge($profile, ['artist_profile'=>$artistProfile]);
$strategy = DescriptionDiversityEngine::select($diversityContext, [__DIR__ . '/../analysis', __DIR__ . '/../tmp/drafts'], basename($image));
$catalogueTitles = ArtworkOriginalityChecker::catalogueTitles(__DIR__ . '/../analysis', $excludeBase);
$catalogueConstraint = $catalogueTitles
    ? implode("\n", array_map(static fn(string $title): string => '- ' . $title, $catalogueTitles))
    : '- No existing titles were available.';

$prompt = strtr(ArtworkAnalysisV2::prompt(), [
    '{analysis_language_instruction}' => 'Think, analyze and formulate the editorial reading directly in natural Spanish. Do not draft in English and translate afterward.',
    '{analysis_language_name}' => 'Spanish',
    '{analysis_language}' => 'es',
    '{artist_profile_prompt}' => ArtistProfile::hasContent($artistProfile) ? ArtistProfile::forPrompt($artistProfile) : '',
    '{catalogue_title_constraints}' => $catalogueConstraint,
    '{description_opening_type}' => (string)$strategy['description_opening_type'],
    '{description_opening_rhythm}' => (string)$strategy['description_opening_rhythm'],
    '{description_structure_type}' => (string)$strategy['description_structure_type'],
    '{recent_opening_types_to_avoid}' => implode(', ', (array)$strategy['recent_opening_types_to_avoid']) ?: 'none recorded',
    '{artwork_id}' => (string)($legacy['artwork_id'] ?? 0),
    '{title}' => trim((string)($first['title'] ?? '')),
    '{artist}' => trim((string)($artistProfile['artist_name'] ?? '')),
    '{year}' => '', '{series}' => '', '{medium}' => '', '{materials}' => '',
    '{width_cm}' => '', '{height_cm}' => '', '{depth_cm}' => '',
    '{orientation}' => '', '{notes}' => '',
]);

$decodeResponse = static function (string $raw): ?array {
    $clean = trim($raw);
    $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
    $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
    $start = strpos($clean, '{'); $end = strrpos($clean, '}');
    if ($start !== false && $end !== false && $end >= $start) $clean = substr($clean, $start, $end - $start + 1);
    $decoded = json_decode($clean, true);
    return is_array($decoded) ? $decoded : null;
};
$client = new GeminiImageClient();
$draft = $decodeResponse($client->generateText([$client->textPart($prompt), $client->imagePart($image)], 'gemini-2.5-flash'));
if (!is_array($draft)) { fwrite(STDERR, "The model did not return valid JSON. No draft was saved.\n"); exit(1); }

$draft['schema_version'] = ArtworkAnalysisV2::SCHEMA_VERSION;
$draft['source']['image_file'] = basename($image);
$draft['source']['analysis_prompt_version'] = 'v2';
$draft['source']['analyzed_at'] = date(DATE_ATOM);
$draft['originality_check'] = ArtworkOriginalityChecker::check($draft, __DIR__ . '/../analysis', $excludeBase);
$attempts = 1;
if (($draft['originality_check']['title_unique'] ?? false) !== true) {
    $closest = (string)($draft['originality_check']['closest_title'] ?? '');
    $rejected = (string)($draft['canonical_editorial']['title'] ?? '');
    $revisionPrompt = $prompt . "\n\nTITLE REVISION REQUIRED\nThe first title '{$rejected}' was rejected because it is too close to '{$closest}'. Generate the complete JSON again. Keep the evidence-based analysis, but create a genuinely different title from another visual or conceptual entry point. Do not merely reverse words or substitute synonyms.";
    $retry = $decodeResponse($client->generateText([$client->textPart($revisionPrompt), $client->imagePart($image)], 'gemini-2.5-flash'));
    if (is_array($retry)) {
        $draft = $retry;
        $draft['originality_check'] = ArtworkOriginalityChecker::check($draft, __DIR__ . '/../analysis', $excludeBase);
        $prompt = $revisionPrompt;
        $attempts = 2;
    }
}
$draft['schema_version'] = ArtworkAnalysisV2::SCHEMA_VERSION;
$draft['source']['image_file'] = basename($image);
$draft['source']['analysis_prompt_version'] = 'v2';
$draft['source']['analyzed_at'] = date(DATE_ATOM);
$masterDescription = trim((string)($draft['canonical_editorial']['master_description'] ?? ''));
$openingParts = preg_split('/\R\s*\R/', $masterDescription) ?: [];
$generatedParagraphFunctions = (array)($draft['editorial_strategy']['paragraph_functions'] ?? []);
$draft['editorial_strategy'] = $strategy;
$draft['editorial_strategy']['paragraph_functions'] = $generatedParagraphFunctions;
$draft['editorial_strategy']['opening_paragraph'] = trim((string)($openingParts[0] ?? ''));
$draft['review']['analysis_status'] = 'draft';
$draft['review']['editorial_status'] = 'draft';
$draft['review']['reviewed_by'] = null;
$draft['review']['reviewed_at'] = null;
$draft['review']['notes'] = trim((string)($draft['review']['notes'] ?? '') . " Generation attempts: {$attempts}.");

$errors = ArtworkAnalysisV2::validate($draft, false);
if ($errors && $attempts < 2) {
    $revisionPrompt = $prompt . "\n\nEDITORIAL VALIDATION REVISION REQUIRED\nThe previous JSON was rejected for these reasons:\n- " . implode("\n- ", $errors) . "\nGenerate the complete JSON again, preserving accurate analysis while correcting every listed issue.";
    $retry = $decodeResponse($client->generateText([$client->textPart($revisionPrompt), $client->imagePart($image)], 'gemini-2.5-flash'));
    if (is_array($retry)) {
        $draft = $retry;
        $draft['schema_version'] = ArtworkAnalysisV2::SCHEMA_VERSION;
        $draft['source']['image_file'] = basename($image);
        $draft['source']['analysis_prompt_version'] = 'v2';
        $draft['source']['analyzed_at'] = date(DATE_ATOM);
        $draft['originality_check'] = ArtworkOriginalityChecker::check($draft, __DIR__ . '/../analysis', $excludeBase);
        $masterDescription = trim((string)($draft['canonical_editorial']['master_description'] ?? ''));
        $openingParts = preg_split('/\R\s*\R/', $masterDescription) ?: [];
        $generatedParagraphFunctions = (array)($draft['editorial_strategy']['paragraph_functions'] ?? []);
        $draft['editorial_strategy'] = $strategy;
        $draft['editorial_strategy']['paragraph_functions'] = $generatedParagraphFunctions;
        $draft['editorial_strategy']['opening_paragraph'] = trim((string)($openingParts[0] ?? ''));
        $draft['review']['analysis_status'] = 'draft';
        $draft['review']['editorial_status'] = 'draft';
        $draft['review']['reviewed_by'] = null;
        $draft['review']['reviewed_at'] = null;
        $draft['review']['notes'] = 'Generation attempts: 2. Editorial validation retry applied.';
        $prompt = $revisionPrompt;
        $attempts = 2;
        $errors = ArtworkAnalysisV2::validate($draft, false);
    }
}
if ($errors) {
    $errorOutput = $output . '.invalid.json';
    if (!is_dir(dirname($errorOutput))) mkdir(dirname($errorOutput), 0775, true);
    file_put_contents($errorOutput, json_encode(['validation_errors'=>$errors,'draft'=>$draft], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    fwrite(STDERR, "Generated JSON failed validation. Saved only as invalid draft: {$errorOutput}\n" . implode("\n", $errors) . "\n");
    exit(1);
}

if (!is_dir(dirname($output))) mkdir(dirname($output), 0775, true);
file_put_contents($output, json_encode($draft, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL);
file_put_contents($output . '.prompt.txt', $prompt);
echo "VALID generated draft saved: {$output}\n";
echo "Nothing was published or written to application data.\n";
