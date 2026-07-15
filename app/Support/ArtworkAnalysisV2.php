<?php
declare(strict_types=1);

final class ArtworkAnalysisV2
{
    public const SCHEMA_VERSION = 'artwork-analysis.v2';

    public static function prompt(): string
    {
        return <<<'PROMPT'
Analyze one artwork and return only one valid JSON object. Do not use markdown.

GOAL
Create one canonical, evidence-based artwork analysis. Produce one title, one subtitle and one master description. Do not generate alternatives, mockup scenes, Pinterest copy or marketplace-specific copy.

ARTIST PROFILE
{artist_profile_prompt}

CONFIRMED ARTWORK DATA
- Artwork ID: {artwork_id}
- Working title: {title}
- Artist: {artist}
- Year: {year}
- Series: {series}
- Medium: {medium}
- Materials: {materials}
- Dimensions: {width_cm} x {height_cm} x {depth_cm} cm
- Orientation: {orientation}
- Artist notes: {notes}

EXISTING CATALOGUE TITLES
{catalogue_title_constraints}

DESCRIPTION DIVERSITY STRATEGY
- Required opening type: {description_opening_type}
- Required sentence rhythm: {description_opening_rhythm}
- Required full-description structure: {description_structure_type}
- Recent opening types to avoid: {recent_opening_types_to_avoid}

SOURCE AUTHORITY
- Facts supplied in CONFIRMED ARTWORK DATA or the artist profile are confirmed artist/record facts.
- Visual observations come from the supplied image.
- Conceptual readings are interpretations, even when they align with the artist profile.
- Record the origin of important claims in evidence_sources.

RULES
1. Begin with visible evidence: composition, colors, contrast, rhythm, depth, surface, marks and distinctive elements.
2. Keep observation and interpretation separate.
3. Every conceptual or symbolic reading must cite visible evidence and use high, medium or low confidence.
4. Do not invent technique, material, symbol, intention, year, series, shipping information or artist biography. Technique or process explicitly declared by the artist is confirmed information and may guide visual analysis.
5. Unknown confirmed facts must be null, not inferred.
6. Use the artist profile only as context. Never copy it into public text.
7. The title must be unique in intention, sober, evocative, memorable and no longer than 65 characters.
   Compare it against EXISTING CATALOGUE TITLES. Do not reuse their central noun pair, reverse their word order, or make a minimal synonym variation.
8. Avoid generic titles, repeated artist vocabulary, marketplace filler, decorative claims, grandiloquence and closed interpretations.
9. The master description must focus on this exact artwork. Synthesize relationships between visual elements instead of inventorying the image band by band. Every paragraph must add information.
10. The master description is channel-neutral. Do not mention Saatchi, Pinterest, SEO, rooms, publishing or mockups.
11. Alt text must describe what is visible without interpretation or promotional language.
12. The application, not the model, performs catalogue similarity checks. Leave originality_check catalogue results unset.
13. Do not use scale terms such as large, oversized or monumental unless confirmed dimensions justify them.
14. Exclude decorative and mass-market terms such as wall art, gallery wall art, home decor, perfect for any room and statement decor.
15. Do not begin writing from a generic object label. Build the opening from the required conceptual entry point. The opening type controls the cognitive perspective, not a stock phrase.
16. Respect the required sentence rhythm naturally. Do not mention the strategy in public copy.
17. Follow the required full-description structure. Use three or four paragraphs with a distinct function for each paragraph.
18. Do not inventory the painting by walking from top to bottom, band to band, or color to color. Mention only details that support a relationship, tension, process or interpretation.
19. Do not repeat the same observation in the short description, opening paragraph and closing paragraph.
20. Fill editorial_strategy.paragraph_functions with one concise function label for every paragraph in master_description, in the same order.

Return exactly this structure:
{
  "schema_version": "artwork-analysis.v2",
  "artwork_id": 0,
  "analysis_language": "en",
  "source": {
    "image_file": "",
    "artist_profile_version": "",
    "analysis_prompt_version": "v2",
    "analyzed_at": ""
  },
  "confirmed_facts": {
    "working_title": "",
    "artist": "",
    "year": null,
    "series": null,
    "medium": null,
    "materials": [],
    "width_cm": null,
    "height_cm": null,
    "depth_cm": null,
    "orientation": "",
    "signature": null,
    "certificate_of_authenticity": null,
    "presentation": null,
    "shipping_notes": null
  },
  "evidence_sources": {
    "artist_or_record_facts": [],
    "visual_observations": [],
    "interpretive_claims": []
  },
  "editorial_strategy": {
    "description_opening_type": "",
    "description_opening_rhythm": "",
    "description_structure_type": "",
    "paragraph_functions": [],
    "opening_paragraph": ""
  },
  "visual_analysis": {
    "dominant_colors": [],
    "secondary_colors": [],
    "color_temperature": "",
    "contrast": "",
    "composition": {"type":"","organization":"","focal_areas":[],"balance":"","depth":""},
    "rhythm_and_movement": "",
    "surface_and_texture": "",
    "visible_elements": [],
    "visible_marks_or_process": [],
    "spatial_presence": "",
    "emotional_atmosphere": [],
    "distinctive_features": []
  },
  "interpretation": {
    "central_reading": "",
    "supporting_readings": [{"reading":"","visible_evidence":[],"confidence":"medium"}],
    "relationship_to_artist_profile": "",
    "relationship_to_series": "",
    "open_questions": [],
    "claims_to_avoid": []
  },
  "canonical_editorial": {
    "title": "",
    "subtitle": "",
    "short_description": "",
    "master_description": "",
    "artist_vocabulary": [],
    "buyer_facing_terms": [],
    "alt_text": "",
    "caption": ""
  },
  "search_metadata": {"core_keywords":[],"specific_keywords":[],"long_tail_terms":[]},
  "originality_check": {
    "catalogue_checked": false,
    "title_unique": null,
    "closest_title": null,
    "title_similarity": null,
    "closest_description_artwork_id": null,
    "description_similarity": null,
    "repeated_openings": [],
    "repeated_phrases": [],
    "structure_used": "",
    "warnings": [],
    "passed": false
  },
  "review": {"analysis_status":"draft","editorial_status":"draft","reviewed_by":null,"reviewed_at":null,"notes":""}
}
PROMPT;
    }

    public static function validate(array $data, bool $requireUncheckedOriginality = true): array
    {
        $errors = [];
        if (($data['schema_version'] ?? '') !== self::SCHEMA_VERSION) $errors[] = 'Invalid schema_version.';
        foreach (['confirmed_facts', 'evidence_sources', 'editorial_strategy', 'visual_analysis', 'interpretation', 'canonical_editorial', 'search_metadata', 'originality_check', 'review'] as $key) {
            if (!is_array($data[$key] ?? null)) $errors[] = "Missing object: {$key}.";
        }
        $editorial = is_array($data['canonical_editorial'] ?? null) ? $data['canonical_editorial'] : [];
        foreach (['title', 'subtitle', 'short_description', 'master_description', 'alt_text', 'caption'] as $key) {
            if (!array_key_exists($key, $editorial) || is_array($editorial[$key])) $errors[] = "canonical_editorial.{$key} must be a singular value.";
        }
        if (mb_strlen(trim((string)($editorial['title'] ?? ''))) > 65) $errors[] = 'Canonical title exceeds 65 characters.';
        $genericOpening = '/^(this (painting|artwork|work|composition|piece)|in this (painting|artwork|work|piece)|the (artist|painting)\b|an? (abstract|original abstract) (painting|artwork|work|composition))\b/i';
        foreach (['short_description', 'master_description'] as $field) {
            if (preg_match($genericOpening, trim((string)($editorial[$field] ?? '')))) $errors[] = "Generic AI opening detected in canonical_editorial.{$field}.";
        }
        $strategy = is_array($data['editorial_strategy'] ?? null) ? $data['editorial_strategy'] : [];
        $allowedOpeningTypes = ['composition','color','material','atmosphere','territory','symbol','movement','light','process','viewer','scale','contrast','detail','negative_space','architecture','legacy_unknown'];
        if (!in_array((string)($strategy['description_opening_type'] ?? ''), $allowedOpeningTypes, true)) $errors[] = 'Invalid description_opening_type.';
        if (trim((string)($strategy['opening_paragraph'] ?? '')) === '' && ($strategy['description_opening_type'] ?? '') !== 'legacy_unknown') $errors[] = 'Missing recorded opening paragraph.';
        $allowedStructures = ['visual_material_conceptual_spatial','atmosphere_composition_symbol_process','detail_expansion_interpretation','process_surface_depth_presence','contrast_tension_viewer','color_rhythm_territory_contemplation','legacy_unknown'];
        if (!in_array((string)($strategy['description_structure_type'] ?? ''), $allowedStructures, true)) $errors[] = 'Invalid description_structure_type.';
        if (($strategy['description_structure_type'] ?? '') !== 'legacy_unknown' && count((array)($strategy['paragraph_functions'] ?? [])) < 3) $errors[] = 'At least three paragraph functions must be recorded.';
        $terms = array_merge((array)($editorial['buyer_facing_terms'] ?? []), (array)($data['search_metadata']['core_keywords'] ?? []), (array)($data['search_metadata']['specific_keywords'] ?? []));
        $joined = mb_strtolower(implode(' | ', array_map('strval', $terms)));
        foreach (['gallery wall art', 'wall art', 'home decor', 'statement decor', 'perfect for any room'] as $forbidden) {
            if (str_contains($joined, $forbidden)) $errors[] = "Forbidden decorative term: {$forbidden}.";
        }
        $facts = is_array($data['confirmed_facts'] ?? null) ? $data['confirmed_facts'] : [];
        $hasDimensions = (float)($facts['width_cm'] ?? 0) > 0 && (float)($facts['height_cm'] ?? 0) > 0;
        if (!$hasDimensions && preg_match('/\b(large|large-scale|oversized|monumental)\b/i', $joined)) $errors[] = 'Scale language requires confirmed dimensions.';
        if ($requireUncheckedOriginality && ($data['originality_check']['catalogue_checked'] ?? null) !== false) $errors[] = 'Dry-run originality check must remain false.';
        if (($data['review']['analysis_status'] ?? '') !== 'draft' || ($data['review']['editorial_status'] ?? '') !== 'draft') $errors[] = 'Dry-run review status must remain draft.';
        return $errors;
    }
}
