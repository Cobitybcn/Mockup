<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Support/ArtworkAnalysisV2.php';

$source = $argv[1] ?? '';
$output = $argv[2] ?? (__DIR__ . '/../tmp/drafts/artwork-analysis-v2-draft.json');
if ($source === '' || !is_file($source)) {
    fwrite(STDERR, "Usage: php artwork_analysis_v2_dry_run.php <legacy-analysis.json> [output.json]\n");
    exit(2);
}

$legacy = json_decode((string)file_get_contents($source), true);
if (!is_array($legacy)) {
    fwrite(STDERR, "Invalid source JSON.\n");
    exit(2);
}

$profile = is_array($legacy['artwork_analysis'] ?? null) ? $legacy['artwork_analysis'] : $legacy;
$publishing = is_array($profile['publishing_metadata'] ?? null) ? $profile['publishing_metadata'] : [];
$titles = is_array($legacy['suggested_titles'] ?? null) ? $legacy['suggested_titles'] : ($publishing['suggested_titles'] ?? []);
$first = is_array($titles[0] ?? null) ? $titles[0] : [];
$rootMeta = is_array($publishing['root_image_metadata'] ?? null) ? $publishing['root_image_metadata'] : [];
$description = trim((string)($first['description'] ?? $first['curatorial_description'] ?? $profile['one_line_curatorial_read'] ?? ''));

$draft = [
    'schema_version' => ArtworkAnalysisV2::SCHEMA_VERSION,
    'artwork_id' => (int)($legacy['artwork_id'] ?? 0),
    'analysis_language' => 'es',
    'source' => ['image_file'=>basename($source, '.analysis.json'),'artist_profile_version'=>'legacy','analysis_prompt_version'=>'legacy-import','analyzed_at'=>date(DATE_ATOM)],
    'confirmed_facts' => ['working_title'=>(string)($first['title']??''),'artist'=>'','year'=>null,'series'=>null,'medium'=>null,'materials'=>[],'width_cm'=>null,'height_cm'=>null,'depth_cm'=>null,'orientation'=>'','signature'=>null,'certificate_of_authenticity'=>null,'presentation'=>null,'shipping_notes'=>null],
    'evidence_sources' => ['artist_or_record_facts'=>[],'visual_observations'=>[],'interpretive_claims'=>[]],
    'editorial_strategy' => ['description_opening_type'=>'legacy_unknown','description_opening_rhythm'=>'legacy_unknown','description_structure_type'=>'legacy_unknown','paragraph_functions'=>[],'opening_paragraph'=>''],
    'visual_analysis' => [
        'dominant_colors'=>(array)($profile['dominant_colors']??[]),'secondary_colors'=>(array)($profile['secondary_colors']??[]),'color_temperature'=>(string)($profile['color_temperature']??''),'contrast'=>(string)($profile['contrast_level']??''),
        'composition'=>['type'=>(string)($profile['composition_type']??''),'organization'=>'','focal_areas'=>[],'balance'=>'','depth'=>''],'rhythm_and_movement'=>(string)($profile['rhythm']??''),'surface_and_texture'=>(string)($profile['surface']??''),
        'visible_elements'=>(array)($profile['visible_elements']??[]),'visible_marks_or_process'=>[],'spatial_presence'=>(string)($profile['spatial_presence']??''),'emotional_atmosphere'=>(array)($profile['emotional_energy']??[]),'distinctive_features'=>[]
    ],
    'interpretation' => ['central_reading'=>(string)($profile['one_line_curatorial_read']??''),'supporting_readings'=>[],'relationship_to_artist_profile'=>'','relationship_to_series'=>'','open_questions'=>[],'claims_to_avoid'=>[]],
    'canonical_editorial' => ['title'=>(string)($first['title']??''),'subtitle'=>(string)($first['subtitle']??''),'short_description'=>(string)($profile['one_line_curatorial_read']??''),'master_description'=>$description,'artist_vocabulary'=>[],'alt_text'=>(string)($rootMeta['alt_text']??''),'caption'=>(string)($rootMeta['caption']??'')],
    'search_metadata' => ['catalogue_tags'=>(array)($publishing['keywords']??[]),'search_terms'=>(array)($publishing['long_tail_keywords']??[]),'seo_title'=>'','seo_description'=>''],
    'originality_check' => ['catalogue_checked'=>false,'title_unique'=>null,'closest_title'=>null,'title_similarity'=>null,'closest_description_artwork_id'=>null,'description_similarity'=>null,'repeated_openings'=>[],'repeated_phrases'=>[],'structure_used'=>'legacy import','warnings'=>['Catalogue comparison has not been run.'],'passed'=>false],
    'review' => ['analysis_status'=>'draft','editorial_status'=>'draft','reviewed_by'=>null,'reviewed_at'=>null,'notes'=>'Imported locally from a legacy analysis; no content was published.'],
];

$errors = ArtworkAnalysisV2::validate($draft);
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
if (!is_dir(dirname($output))) mkdir(dirname($output), 0775, true);
file_put_contents($output, json_encode($draft, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL);
echo "VALID draft saved: {$output}\n";
echo "Nothing was published or overwritten.\n";
