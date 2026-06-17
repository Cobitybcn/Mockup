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

function visual_analysis_from(array $artwork, array $profile, array $artistProfile, string $sizeText): array
{
    $source = is_array($profile['artwork_analysis'] ?? null) ? $profile['artwork_analysis'] : $profile;
    $dominantColors = concise_terms(words_from($source['dominant_colors'] ?? $source['palette'] ?? []), ['balanced tones']);
    $secondaryColors = concise_terms(words_from($source['secondary_colors'] ?? $source['palette_family'] ?? []), ['subtle secondary tones']);
    $style = concise_terms(words_from($source['visual_language'] ?? $source['style_tags'] ?? $artistProfile['visual_language'] ?? []), ['contemporary artwork']);
    $composition = labelize_term((string)($source['composition_type'] ?? sentence_from(words_from($source['structure_tags'] ?? []), 'balanced composition')));
    $contrast = labelize_term((string)($source['contrast_level'] ?? $source['contrast'] ?? 'balanced contrast'));
    $surface = labelize_term((string)($source['surface'] ?? $source['texture_visibility'] ?? sentence_from(words_from($artistProfile['materials'] ?? ''), 'visible surface')));
    $rhythm = labelize_term((string)($source['rhythm'] ?? sentence_from(words_from($source['structure_tags'] ?? []), 'measured rhythm')));
    $symbols = concise_terms(words_from($source['visible_symbols'] ?? $source['visible_elements'] ?? $artistProfile['recurring_themes'] ?? []), ['forms', 'marks', 'fields']);
    $emotions = concise_terms(words_from($source['emotional_energy'] ?? $source['mood_tags'] ?? $artistProfile['palette_notes'] ?? []), ['quiet', 'human', 'contemplative']);
    $audience = english_or_default((string)($source['audience_profile']['primary'] ?? $artistProfile['target_audience'] ?? ''), 'collectors and thoughtful buyers');

    return [
        'dominant_colors' => $dominantColors,
        'secondary_colors' => $secondaryColors,
        'style' => $style,
        'composition' => $composition,
        'contrast' => $contrast,
        'surface' => $surface,
        'rhythm' => $rhythm,
        'symbols' => $symbols,
        'emotions' => $emotions,
        'atmosphere' => sentence_from($emotions, 'quiet emotional atmosphere'),
        'audience' => $audience,
        'medium' => trim((string)($artwork['medium'] ?? '')) ?: 'original artwork',
        'series' => trim((string)($artwork['series'] ?? '')),
        'size' => $sizeText,
        'context' => sentence_from(words_from($artistProfile['preferred_contexts'] ?? ''), 'a calm interior, gallery wall, or collector space'),
        'tone' => public_copy_tone($artistProfile),
    ];
}

function build_artwork_package_v2(array $artwork, array $profile, array $artistProfile, array $mockups, string $sizeText, array $contexts = []): array
{
    $artist = trim((string)($artistProfile['artist_name'] ?? ''));
    $medium = trim((string)($artwork['medium'] ?? ''));
    $series = trim((string)($artwork['series'] ?? ''));
    $year = trim((string)($artwork['artwork_year'] ?? ''));
    $visual = visual_analysis_from($artwork, $profile, $artistProfile, $sizeText);
    $style = $visual['style'];
    $mood = $visual['emotions'];
    $palette = $visual['dominant_colors'];
    $themes = $visual['symbols'];
    $structure = concise_terms([$visual['composition'], $visual['rhythm'], $visual['surface']], ['composition', 'surface', 'spatial presence']);
    $materials = concise_terms(words_from($artistProfile['materials'] ?? ''), ['mixed media', 'surface work']);
    $artistLanguage = concise_terms(words_from($artistProfile['visual_language'] ?? ''), ['contemporary visual language']);
    $commercial = concise_terms(words_from(($profile['commercial_fit'] ?? []) ?: $visual['audience']), ['collectors', 'buyers', 'interiors']);

    // Parse dynamic AI metadata if present
    $aiMeta = $profile['publishing_metadata'] ?? [];
    $titles = [];
    $titleSubtitles = [];
    $titleDescriptions = [];
    $titleLabels = [];

    $rawSuggestedTitles = $aiMeta['suggested_titles'] ?? [];
    if (is_array($rawSuggestedTitles) && isset($rawSuggestedTitles[0])) {
        // New array of objects form
        foreach ($rawSuggestedTitles as $idx => $tObj) {
            if (is_array($tObj)) {
                $title = trim((string)($tObj['title'] ?? ''));
                $sub = trim((string)($tObj['subtitle'] ?? ''));
                $desc = trim((string)($tObj['description'] ?? ''));
                if ($title !== '') {
                    $titles[] = $title;
                    $titleSubtitles[$title] = $sub;
                    $titleDescriptions[$title] = $desc;
                    $label = match($idx) {
                        0 => 'Poetic',
                        1 => 'Descriptive',
                        2 => 'Marketplace-friendly',
                        default => 'Option ' . ($idx + 1)
                    };
                    $titleLabels[$title] = $label;
                }
            }
        }
    } elseif (is_array($rawSuggestedTitles)) {
        // Old key-value object form
        foreach ($rawSuggestedTitles as $label => $titleVal) {
            $title = trim((string)$titleVal);
            if ($title !== '') {
                $titles[] = $title;
                $titleSubtitles[$title] = '';
                $oldDescKey = match($label) {
                    'poetic' => 'poetic_focus',
                    'descriptive' => 'formal_focus',
                    'marketplace_friendly' => 'commercial_focus',
                    default => ''
                };
                $titleDescriptions[$title] = $aiMeta['descriptions'][$oldDescKey] ?? $aiMeta['descriptions'][$label] ?? '';
                $titleLabels[$title] = ucfirst(str_replace('_', ' ', $label));
            }
        }
    }

    if (empty($titles) || count($titles) < 3) {
        $baseTitleTerms = unique_limited(array_merge($palette, $mood, $style, $structure, $themes), 8, ['quiet', 'field', 'surface']);
        $titleSeedA = $baseTitleTerms[0] ?? 'quiet';
        $titleSeedB = $baseTitleTerms[1] ?? 'field';
        $seriesPrefix = $series !== '' ? $series . ': ' : '';
        $titles = unique_limited([
            $seriesPrefix . title_case_soft($titleSeedA . ' and ' . $titleSeedB),
            title_case_soft(sentence_from([$visual['composition'], $titleSeedA, $medium], 'Abstract Composition')),
            title_case_soft(($medium !== '' ? $medium : 'Original Artwork') . ' in ' . $titleSeedA),
        ], 3, ['Quiet Field', 'Abstract Composition in Balanced Tones', 'Original Artwork in Sober Color']);

        $titleLabels = [
            $titles[0] => 'Poetic',
            $titles[1] => 'Descriptive',
            $titles[2] => 'Marketplace-friendly',
        ];
    }

    $storedTitle = trim((string)($artwork['final_title'] ?? ''));
    $storedSubtitle = trim((string)($artwork['subtitle'] ?? ''));
    $titleForCopy = ($storedTitle !== '' && !looks_spanish($storedTitle)) ? $storedTitle : $titles[0];
    
    // Subtitle fallback or stored
    $suggestedSubtitle = $titleSubtitles[$titleForCopy] ?? '';
    if ($suggestedSubtitle === '') {
        $suggestedSubtitle = title_case_soft(($medium !== '' ? $medium : 'original artwork') . ' with ' . sentence_from([$visual['composition'], $visual['atmosphere']], 'visual presence'));
    }
    $subtitle = ($storedSubtitle !== '' && !looks_spanish($storedSubtitle)) ? $storedSubtitle : $suggestedSubtitle;
    $titleLine = $titleForCopy . ($subtitle !== '' ? ': ' . $subtitle : '');
    $specLine = trim(implode(', ', array_filter([$medium, $sizeText !== 'No dimensions specified' ? $sizeText : '', $year])));
    $fileSlug = slugify(($artist !== '' ? $artist . '-' : '') . $titleForCopy);

    $description = !empty($titleDescriptions[$titleForCopy]) 
        ? clean_public_copy($titleDescriptions[$titleForCopy], $artistProfile)
        : null;

    if (empty($description)) {
        $styleSummary = clean_public_copy(english_or_default((string)($profile['style_summary'] ?? ''), ''), $artistProfile);
        $curatorialRead = clean_public_copy(english_or_default((string)($profile['one_line_curatorial_read'] ?? ''), ''), $artistProfile);
        $descriptionParts = [
            'The work draws the eye first through ' . sentence_from($palette, 'its color field') . ', where ' . $visual['contrast'] . ' and ' . $visual['surface'] . ' give the image its physical presence.',
            'Its ' . $visual['composition'] . ' composition creates a ' . $visual['rhythm'] . ' rhythm, allowing ' . sentence_from($themes, 'visible forms and marks') . ' to carry the viewer across the surface without forcing a single reading.',
            'The atmosphere is ' . $visual['atmosphere'] . ', direct enough to meet a room clearly and open enough to reward a slower look.',
        ];
        if ($styleSummary !== '') {
            $descriptionParts[] = first_sentence($styleSummary);
        } elseif ($curatorialRead !== '') {
            $descriptionParts[] = first_sentence($curatorialRead);
        }
        if ($artist !== '') {
            $descriptionParts[] = 'Seen within ' . $artist . "'s practice, it connects to " . sentence_from(array_merge($artistLanguage, $materials), 'a material and contemporary language') . ' while remaining led by what is visible in the artwork itself.';
        }
        $description = clean_public_copy(implode(' ', $descriptionParts), $artistProfile);
    }

    $shortDescription = !empty($description) 
        ? clean_public_copy(first_sentence($description) . ' A ' . strtolower($medium !== '' ? $medium : 'work') . ' with ' . sentence_from($mood, 'a quiet atmosphere') . '.', $artistProfile)
        : '';

    $technicalDetailsForTitle = function (string $titleOption) use ($artist, $specLine): string {
        return 'Technical details: ' . trim(($artist !== '' ? $artist . ', ' : '') . $titleOption . ($specLine !== '' ? ', ' . $specLine : '')) . '.';
    };

    $premiumDescriptions = [];
    $publicationDescriptions = [];
    foreach ($titles as $index => $titleOption) {
        $copy = $titleDescriptions[$titleOption] ?? '';
        if ($copy === '') {
            if ($index === 0) {
                $copy = $description;
            } elseif ($index === 1) {
                $copy = 'Under the title "' . $titleOption . '", the work is described through what can be seen: ' . sentence_from($palette, 'balanced color') . ', ' . $visual['composition'] . ' structure, and ' . $visual['surface'] . '. It suits viewers who want a clear visual entry point before moving into the quieter emotional layers of the piece.';
            } else {
                $copy = '"' . $titleOption . '" gives buyers a direct way to understand the piece: an original ' . strtolower($medium !== '' ? $medium : 'artwork') . ' with ' . sentence_from($palette, 'a balanced palette') . ', ' . sentence_from($mood, 'a quiet mood') . ', and a strong visual presence for ' . $visual['audience'] . '.';
            }
        }
        $cleanedCopy = trim(clean_public_copy($copy, $artistProfile));
        $premiumDescriptions[$titleOption] = $cleanedCopy;
        $publicationDescriptions[$titleOption] = $cleanedCopy . "\n\n" . $technicalDetailsForTitle($titleOption);
    }

    $mainKeywords = [];
    if (!empty($aiMeta['keywords']) && is_array($aiMeta['keywords'])) {
        $mainKeywords = unique_limited($aiMeta['keywords'], 15);
    } elseif (!empty($aiMeta['seo_keywords']) && is_array($aiMeta['seo_keywords'])) {
        $mainKeywords = unique_limited($aiMeta['seo_keywords'], 20);
    } else {
        $mainKeywords = unique_limited(array_merge([
            $artist ? $artist . ' artwork' : '',
            $titles[0],
            'contemporary art',
            'original artwork',
            'art for collectors',
            $medium,
            $series,
        ], $style, $palette, $themes, $mood, $materials, $commercial), 15, [
            'abstract art', 'contemporary artwork', 'artist-made artwork', 'art for interiors', 'symbolic art'
        ]);
    }

    $longTailKeywords = [];
    if (!empty($aiMeta['long_tail_keywords']) && is_array($aiMeta['long_tail_keywords'])) {
        $longTailKeywords = unique_limited($aiMeta['long_tail_keywords'], 15);
    } else {
        $longTailKeywords = unique_limited([
            ($artist ? $artist . ' original artwork' : 'original contemporary artwork') . ' for collectors',
            sentence_from($style, 'abstract contemporary') . ' artwork for interiors',
            sentence_from($palette, 'sober palette') . ' artwork for collectors',
            'artwork ready to publish on Catawiki',
            'artwork ready to publish on Saatchi Art',
            'contemporary art for homes and collections',
            'original artwork with clear description and SEO',
            'Pinterest-ready artwork with title description and keywords',
            sentence_from($mood, 'quiet contemplative') . ' artwork for private collections',
            'artist-made artwork for sophisticated spaces',
            'online listing for contemporary artwork',
            'mockups for selling art online',
            'art description for international marketplace listing',
            'artwork for collectors of contemporary abstract art',
            'complete artwork sheet for online art sales',
        ], 15);
    }

    if (!empty($aiMeta['seo_tags'])) {
        $tags = unique_limited($aiMeta['seo_tags'], 16);
    } else {
        $tags = unique_limited(array_merge($style, $mood, $palette, $structure, $themes, $commercial), 16, ['contemporary art', 'interiors']);
    }

    if (!empty($aiDescriptions['commercial_focus'])) {
        $marketplaceDescription = clean_public_copy($aiDescriptions['commercial_focus'], $artistProfile) . "\n\n" . 'Technical details: ' . ($artist !== '' ? $artist . ', ' : '') . $titleForCopy . ($specLine !== '' ? ', ' . $specLine : '') . '.';
    } else {
        $marketplaceDescription = clean_public_copy(
            ($artist !== ''
                ? $titleForCopy . ' by ' . $artist
                : $titleForCopy
            ) . ' is an original artwork with ' . sentence_from($palette, 'a balanced palette') . ' and ' . $visual['composition'] . ' composition. The visible surface, rhythm, and emotional tone make it suitable for collectors, private interiors, and online art platforms looking for a distinctive contemporary piece. Main visual features: ' . sentence_from(array_merge($palette, $structure, $themes), 'color, structure, and surface') . '.',
            $artistProfile
        ) . "\n\n" . 'Technical details: ' . ($artist !== '' ? $artist . ', ' : '') . $titleForCopy . ($specLine !== '' ? ', ' . $specLine : '') . '.';
    }

    $boardSuggestion = in_array('architectural', $style, true) || in_array('structural', $style, true)
        ? 'Architectural Minimalism'
        : (in_array('minimal', $style, true) ? 'Minimalist Abstract Painting' : 'Contemporary Abstract Art');
    $pinterestKeywords = unique_limited(array_merge([
        'contemporary abstract art',
        'original painting for sale',
        'artwork for collectors',
        'abstract painting for interiors',
        'large wall art',
        'statement painting',
    ], $longTailKeywords, $mainKeywords), 14);
    $hashtags = array_map(
        fn($tag) => '#' . preg_replace('/[^a-z0-9]/', '', strtolower($tag)),
        unique_limited(array_merge(['contemporary art', 'abstract painting', 'original artwork', 'art collectors', 'interior design art', 'statement art'], $style), 8)
    );

    $pinSources = $mockups ?: [[
        'context_id' => 'root artwork',
        'mockup_file' => $artwork['root_file'] ?? '',
    ]];
    $pinterestPins = [];
    foreach ($pinSources as $index => $mockup) {
        $contextId = (string)($mockup['context_id'] ?? '');
        $matchingContext = null;
        
        foreach ($contexts as $ctx) {
            if ((string)($ctx['id'] ?? '') === $contextId || (string)($ctx['name'] ?? '') === $contextId) {
                $matchingContext = $ctx;
                break;
            }
        }
        
        $aiPinterest = $matchingContext['pinterest_marketing'] ?? [];
        $contextTitle = Display::contextTitle($mockup['context_id'] ?? ('Pin ' . ($index + 1)));
        
        $keyword = title_case_soft(($style[0] ?? 'Abstract') . ' ' . ($medium !== '' ? $medium : 'Painting'));
        if (stripos($keyword, 'painting') === false && stripos($keyword, 'artwork') === false) {
            $keyword .= ' Painting';
        }
        
        // Calculate fallback title/description
        $fallbackPinTitle = $keyword . ' - ' . title_case_soft(($mood[0] ?? 'Quiet') . ' ' . ($themes[0] ?? 'Composition')) . ' (' . $titleForCopy . ')';
        $fallbackPinDescription = '"' . $titleForCopy . '"' . ($artist !== '' ? ' by ' . $artist : '') . '. ' . first_sentence($description) . ' Searchable details: ' . sentence_from(array_merge($palette, $style, $mood), 'contemporary artwork') . ', ' . strtolower($medium !== '' ? $medium : 'original art') . ', artwork for collectors and interiors.';
        
        $board = !empty($aiPinterest['board_suggestion']) ? $aiPinterest['board_suggestion'] : $boardSuggestion;
        
        $pTitle = !empty($aiPinterest['pin_title']) ? $aiPinterest['pin_title'] : $fallbackPinTitle;
        if (strlen($pTitle) > 100) {
            $pTitle = substr($pTitle, 0, 97) . '...';
        }
        
        $pDesc = !empty($aiPinterest['pin_description']) ? $aiPinterest['pin_description'] : $fallbackPinDescription;
        if (strlen($pDesc) > 500) {
            $pDesc = substr($pDesc, 0, 497) . '...';
        }
        
        $contextText = strtolower($contextTitle);
        $pAlt = !empty($aiPinterest['alt_text']) 
            ? $aiPinterest['alt_text'] 
            : ('Artwork titled ' . $titleForCopy . ' shown in a ' . $contextText . ' setting, with visible wall placement, scale, colors, and surrounding interior.');

        $pinterestPins[] = [
            'label' => $contextTitle,
            'board' => $board,
            'title' => $pTitle,
            'description' => clean_public_copy($pDesc, $artistProfile),
            'alt' => $pAlt,
            'destination' => '',
            'keywords' => $pinterestKeywords,
            'hashtags' => $hashtags,
            'mockup_file' => $mockup['mockup_file'] ?? '',
        ];
    }

    $rootAlt = $aiMeta['root_image_metadata']['alt_text'] ?? '';
    $rootCaption = $aiMeta['root_image_metadata']['caption'] ?? '';
    if (trim($rootAlt) === '') {
        $rootAlt = 'Clean root image of ' . $titleForCopy . ', an original artwork with ' . sentence_from($palette, 'balanced') . ' colors and ' . $visual['composition'] . ' composition.';
    }
    if (trim($rootCaption) === '') {
        $rootCaption = $titleLine . ' - ' . sentence_from($style, 'contemporary artwork') . ' with ' . sentence_from($mood, 'quiet presence') . '.';
    }

    return [
        'root_alt' => $rootAlt,
        'root_caption' => $rootCaption,
        'visual_analysis' => $visual,
        'titles' => $titles,
        'title_labels' => $titleLabels,
        'title_subtitles' => $titleSubtitles,
        'premium_descriptions' => $premiumDescriptions,
        'suggested_subtitle' => $suggestedSubtitle,
        'description' => $description,
        'short_description' => $shortDescription,
        'publication_descriptions' => $publicationDescriptions,
        'technical_details' => $technicalDetailsForTitle($titleForCopy),
        'curatorial_reading' => $curatorialRead !== '' ? $curatorialRead : $descriptionParts[1],
        'seo_slug' => $fileSlug,
        'main_keywords' => $mainKeywords,
        'long_tail_keywords' => $longTailKeywords,
        'tags' => $tags,
        'captions' => [
            $titleLine . ' - ' . sentence_from($style, 'contemporary artwork') . ' with ' . sentence_from($mood, 'quiet presence') . '.',
            '"' . $titleForCopy . '" holds the room through color, surface, and a quiet emotional charge.',
            ($artist !== '' ? $artist . ', ' : '') . $titleForCopy . ($specLine !== '' ? ' (' . $specLine . ')' : '') . '.',
        ],
        'alt_texts' => [
            'Clean root image of ' . $titleForCopy . ', an original artwork with ' . sentence_from($palette, 'balanced') . ' colors and ' . $visual['composition'] . ' composition.',
            $titleForCopy . ' with visible ' . sentence_from($themes, 'forms and marks') . ', ' . $visual['surface'] . ', and ' . sentence_from($mood, 'quiet atmosphere') . '.',
            'Original artwork titled ' . $titleForCopy . ' shown clearly for online listing and accessible image description.',
        ],
        'file_names' => [
            $fileSlug . '-root-artwork.jpg',
            $fileSlug . '-mockup-01.jpg',
            $fileSlug . '-marketplace-listing.jpg',
        ],
        'social' => [
            'Instagram' => $titleLine . "\n\n" . sentence_from($palette, 'Color') . ', ' . $visual['surface'] . ', and ' . sentence_from($mood, 'a quiet emotional pull') . ".\n\n#" . implode(' #', array_map(fn($tag) => str_replace(' ', '', $tag), unique_limited($tags, 8))),
            'Facebook' => $titleLine . "\n\n" . $shortDescription,
            'X' => $titleLine . ' - original contemporary artwork prepared for collectors, interiors, and art platforms.',
            'TikTok' => 'Show the clean root image, close details of color and surface, then the final mockup for "' . $titleForCopy . '".',
        ],
        'pinterest_pins' => $pinterestPins,
        'marketplace' => [
            'titles' => $titles,
            'description' => $marketplaceDescription,
            'Catawiki' => 'Suggested emphasis for Catawiki: authenticity, condition, dimensions, medium, year, series, provenance notes, and secure shipping.',
            'Saatchi Art' => 'Suggested positioning for Saatchi Art: ' . $visual['audience'] . '.',
            'Similar Platforms' => 'Use the title, description, keywords, tags, alt text, and mockup gallery as a coherent artwork listing.',
        ],
    ];
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
    $analysis = is_array($analysisData) ? ['artwork_profile' => $analysisData] : [];
}

$profile = is_array($analysis['artwork_profile'] ?? null) ? $analysis['artwork_profile'] : [];
if (!$profile && is_array($analysis['artwork_analysis'] ?? null)) {
    $profile = $analysis['artwork_analysis'];
}
$contexts = is_array($analysis['recommended_contexts'] ?? null) ? $analysis['recommended_contexts'] : [];

if (!$contexts) {
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
            'pinterest_marketing' => $contextJson['pinterest_marketing'] ?? [],
            'mockup_metadata' => $contextJson['mockup_metadata'] ?? [],
        ];
    }
}

$firstPrompt = (string)($contexts[0]['prompt'] ?? '');
$analysisNeedsRefresh = $firstPrompt !== '' && (
    str_contains($firstPrompt, 'shoe lengths') ||
    str_contains($firstPrompt, 'adult male shoe') ||
    !str_contains($firstPrompt, 'PROMPT_RULESET_VERSION: admin_editable_v1')
);

$mockupStmt = $pdo->prepare('
    SELECT *
    FROM mockups
    WHERE user_id = :user_id
    AND artwork_file = :artwork_file
    ORDER BY created_at DESC
');
$mockupStmt->execute([
    'user_id' => (int)$user['id'],
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
$package = build_artwork_package_v2($artwork, $profile, $artistProfile, $mockups, $sizeText, $contexts);
$storedTitle = trim((string)($artwork['final_title'] ?? ''));
$storedSubtitle = trim((string)($artwork['subtitle'] ?? ''));
$selectedTitle = ($storedTitle !== '' && !looks_spanish($storedTitle)) ? $storedTitle : $package['titles'][0];
$selectedSubtitle = ($storedSubtitle !== '' && !looks_spanish($storedSubtitle)) ? $storedSubtitle : $package['suggested_subtitle'];
$selectedPublicationDescription = $package['publication_descriptions'][$selectedTitle] ?? trim($package['description'] . "\n\n" . ($package['technical_details'] ?? ''));
$publicationCopy = trim($selectedTitle . ($selectedSubtitle !== '' ? "\n" . $selectedSubtitle : '') . "\n\n" . $selectedPublicationDescription);

$copyIconSvg = '<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
$downloadIconSvg = '<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>';
$hyphenatedLongTails = array_map(fn($kw) => slugify((string)$kw), $package['long_tail_keywords']);
$publicationPackageCopy = $publicationCopy . "\n\nKeywords: " . implode(', ', $package['main_keywords']) . "\nLong-tail Keywords: " . implode(', ', $package['long_tail_keywords']);
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
            aspect-ratio: 4 / 3;
            object-fit: cover;
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
            Permanent artwork sheet: root image, visual reading, public descriptions, SEO, social content and marketplace text.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Artwork Details</h1>
                    <p><?= h($selectedSubtitle) ?></p>
                </div>
                <div class="topbar-actions">
                    <?php if ($rootFile): ?>
                        <a class="button-link" href="report.php?image=<?= rawurlencode($rootFile) ?>">Curatorial Direction</a>
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
                    This analysis was generated with an older prompt ruleset. Recalculate the analysis before generating new mockups.
                </div>
            <?php endif; ?>

            <section class="artwork-sheet">
                <aside class="root-panel">
                    <div style="background: var(--surface); border: 1px solid var(--line); padding: 16px; border-radius: var(--radius); box-shadow: var(--shadow); margin-bottom: 12px;">
                        <label style="margin-top: 0; font-size: 11px; text-transform: uppercase; font-weight: 600; color: var(--ink); letter-spacing: 0.05em; display: block; margin-bottom: 6px;">SEO Slug / Filename Customizer</label>
                        <input type="text" id="seo_slug_input" class="form-control" value="<?= h($package['seo_slug']) ?>" style="width: 100%; box-sizing: border-box; padding: 8px 10px; font-size: 13px; font-family: monospace; border: 1px solid var(--line); border-radius: var(--radius);">
                        <small style="margin: 4px 0 0 0; color: var(--muted); font-size: 11px; line-height: 1.3; display: block;">Changes here will update all image download names dynamically.</small>
                    </div>

                    <div class="root-frame">
                        <?php if ($rootFile && is_file($rootPath)): ?>
                            <a href="<?= h(media_url($rootFile)) ?>" target="_blank" title="Click to open full size">
                                <img src="<?= h(media_url($rootFile)) ?>" alt="<?= h($package['alt_texts'][0]) ?>">
                            </a>
                        <?php else: ?>
                            <div class="empty-state">This artwork does not have a completed root image yet.</div>
                        <?php endif; ?>
                    </div>

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
                                        <span style="line-height: 1.4;"><?= h($package['captions'][0]) ?></span>
                                        <button class="copy-button secondary" type="button" data-copy="<?= h($package['captions'][0]) ?>" aria-label="Copy Caption" style="padding: 4px; display: inline-flex; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                    </div>
                                </div>
                                <div>
                                    <label style="font-size: 9px; text-transform: uppercase; color: var(--muted); display: block; margin-bottom: 2px; font-weight: 700; letter-spacing: 0.05em;">Alt Text</label>
                                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                        <span style="line-height: 1.4;"><?= h($package['alt_texts'][0]) ?></span>
                                        <button class="copy-button secondary" type="button" data-copy="<?= h($package['alt_texts'][0]) ?>" aria-label="Copy Alt Text" style="padding: 4px; display: inline-flex; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
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
                                 <button class="copy-button secondary" type="button" data-copy="<?= h($package['curatorial_reading']) ?>" aria-label="Copiar Lectura Curatorial" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
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
                                <option>More SEO-focused</option>
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
                                <h2>Sección 0: Mockups por defecto y Obra Root</h2>
                                <p>Imágenes y metadatos SEO (nombres de archivo, alts y captions)</p>
                            </div>
                            <button class="copy-button secondary" type="button" id="copy_section_0" aria-label="Copiar Sección 0" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr; gap: 24px; margin-top: 16px;">
                            <!-- Obra Root -->
                            <article class="pin-card" style="display: grid; grid-template-columns: 180px 1fr; gap: 20px; align-items: start; padding: 20px; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface);">
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                                    <div class="root-frame" style="padding: 6px; width: 100%; border: 1px solid var(--line); border-radius: 4px; background: var(--surface-soft);">
                                        <?php if ($rootFile && is_file($rootPath)): ?>
                                            <a href="<?= h(media_url($rootFile)) ?>" target="_blank" title="Click to open full size">
                                                <img src="<?= h(media_url($rootFile)) ?>" alt="Obra Root" style="width: 100%; height: auto; display: block; border-radius: 2px;">
                                            </a>
                                        <?php else: ?>
                                            <div class="empty-state" style="font-size: 11px; padding: 20px 0;">Sin imagen</div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div style="display: flex; flex-direction: column; gap: 10px; width: 100%;">
                                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px; border-bottom: 1px dashed var(--line); padding-bottom: 4px;">
                                        <h3 style="font-size: 16px; margin: 0;">Obra Root</h3>
                                        <span style="font-size: 10px; text-transform: uppercase; color: var(--muted); font-weight: 700; letter-spacing: 0.05em;">Imagen Principal</span>
                                    </div>

                                    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 12px;">
                                        <div class="pin-field" style="padding: 8px 10px; background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius);">
                                            <label style="font-size: 9px; font-weight: 700; text-transform: uppercase; color: var(--muted); margin-bottom: 2px; display: block;">Nombre de Archivo SEO</label>
                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                                <span class="seo-root-filename" style="font-family: monospace; font-size: 11px; font-weight: 500;"><?= h($package['file_names'][0]) ?></span>
                                                <button class="copy-button secondary" type="button" data-copy="<?= h($package['file_names'][0]) ?>" id="copy_root_filename" style="padding: 4px; display: inline-flex; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                            </div>
                                        </div>

                                        <div class="pin-field" style="padding: 8px 10px; background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius);">
                                            <label style="font-size: 9px; font-weight: 700; text-transform: uppercase; color: var(--muted); margin-bottom: 2px; display: block;">Texto Alt</label>
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

                                    // Alt & Caption fallback/custom
                                    $mAlt = $ctx['mockup_metadata']['alt_text'] ?? '';
                                    if (trim($mAlt) === '') {
                                        $mAlt = 'Mockup of the artwork "' . $selectedTitle . '" presented in a ' . strtolower($ctx['name']) . ' environment.';
                                    }
                                    $mCaption = $ctx['mockup_metadata']['caption'] ?? '';
                                    if (trim($mCaption) === '') {
                                        $mCaption = '“' . $selectedTitle . '” mockup in ' . $ctx['name'] . '.';
                                    }

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
                                                    <?= $downloadIconSvg ?> Descargar
                                                </a>
                                                <button type="button" class="btn-delete-mockup button secondary danger" data-mockup-id="<?= $existingMockup ? h($existingMockup['id']) : '' ?>" style="font-size: 11px; margin: 0; padding: 6px 10px; width: 100%;">
                                                    Eliminar
                                                </button>
                                            </div>
                                            
                                            <!-- Form for ungenerated state -->
                                            <div class="ungenerated-form" style="<?= $existingMockup ? 'display: none;' : '' ?>">
                                                <?php if ($rootFile && !$analysisNeedsRefresh): ?>
                                                    <form class="inline-mockup-form" action="generate_mockup.php" method="post" style="margin: 0; width: 100%;">
                                                        <input type="hidden" name="image" value="<?= h($rootFile) ?>">
                                                        <input type="hidden" name="json" value="<?= h($rootBase . '.analysis.json') ?>">
                                                        <input type="hidden" name="context_id" value="<?= h($ctxId) ?>">
                                                        <input type="hidden" name="prompt" value="<?= h($ctx['prompt'] ?? '') ?>">
                                                        <input type="hidden" name="ajax" value="1">
                                                        <button type="submit" class="button" style="font-size: 11px; width: 100%; padding: 6px 10px;">Generar Mockup</button>
                                                    </form>
                                                <?php endif; ?>
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
                                                <label style="font-size: 9px; font-weight: 700; text-transform: uppercase; color: var(--muted); margin-bottom: 2px; display: block;">Nombre de Archivo SEO</label>
                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                                    <span class="seo-mockup-filename" data-context-slug="<?= h($ctxSlug) ?>" style="font-family: monospace; font-size: 11px; font-weight: 500;"><?= h($expectedFilename) ?></span>
                                                    <button class="copy-button secondary copy-mockup-filename-btn" type="button" data-copy="<?= h($expectedFilename) ?>" style="padding: 4px; display: inline-flex; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                                </div>
                                            </div>

                                            <div class="pin-field" style="padding: 8px 10px; background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius);">
                                                <label style="font-size: 9px; font-weight: 700; text-transform: uppercase; color: var(--muted); margin-bottom: 2px; display: block;">Texto Alt</label>
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
                                <h2>Sección 1: Títulos y Subtítulos Sugeridos</h2>
                                <p>Propuestas curatoriales y comerciales</p>
                            </div>
                            <button class="copy-button secondary" type="button" id="copy_section_1" aria-label="Copiar Sección 1" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-top: 16px;" class="title-grid-unified">
                            <?php foreach ($package['titles'] as $idx => $t): ?>
                                <?php
                                    $sub = $package['title_subtitles'][$t] ?? '';
                                    $label = match($idx) {
                                        0 => 'Enfoque Poético',
                                        1 => 'Enfoque Descriptivo',
                                        2 => 'Enfoque Comercial',
                                        default => 'Opción ' . ($idx + 1)
                                    };
                                    $titleCopy = "Título: " . $t . ($sub !== '' ? "\nSubtítulo: " . $sub : '');
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
                                        <button class="copy-button secondary" type="button" data-copy="<?= h($titleCopy) ?>" aria-label="Copiar" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
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
                                <h2>Sección 2: Descripciones Premium</h2>
                                <p>Descripciones artísticas y curatoriales asociadas a cada título</p>
                            </div>
                            <button class="copy-button secondary" type="button" id="copy_section_2" aria-label="Copiar Sección 2" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 20px; margin-top: 16px;">
                            <?php
                                $idx = array_search($selectedTitle, $package['titles']);
                                if ($idx === false) {
                                    $idx = 0;
                                }
                                $desc = $package['premium_descriptions'][$selectedTitle] ?? '';
                                $label = match($idx) {
                                    0 => 'Descripción Poética',
                                    1 => 'Descripción Descriptiva',
                                    2 => 'Descripción Comercial',
                                    default => 'Descripción Opción ' . ($idx + 1)
                                };
                                $descCopy = "[" . $label . " para: " . $selectedTitle . "]\n" . $desc;
                            ?>
                            <article class="premium-desc-block" style="background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius); padding: 18px; display: flex; flex-direction: column; gap: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <strong style="font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em;"><?= h($label) ?> — para "<?= h($selectedTitle) ?>"</strong>
                                    <button class="copy-button secondary" type="button" data-copy="<?= h($descCopy) ?>" aria-label="Copiar" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                </div>
                                <p class="copy-block" style="font-size: 14px; line-height: 1.6; margin: 0; color: var(--ink);"><?= h($desc) ?></p>
                            </article>
                        </div>
                    </section>

                    <!-- SECCIÓN 3: 15 KEYWORDS -->
                    <section class="panel">
                        <div class="section-heading" style="display: flex; justify-content: space-between; align-items: center; width: 100%; gap: 12px;">
                            <div>
                                <h2>Sección 3: 15 Keywords</h2>
                                <p>Palabras clave principales de búsqueda separadas por comas</p>
                            </div>
                            <button class="copy-button secondary" type="button" id="copy_section_3" aria-label="Copiar Sección 3" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                        </div>

                        <div style="background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius); padding: 16px; margin-top: 16px;">
                            <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px;">
                                <?php foreach ($package['main_keywords'] as $kw): ?>
                                    <span class="keyword-chip" style="background: var(--surface); border: 1px solid var(--line); padding: 4px 10px; border-radius: 999px; font-size: 12px;"><?= h($kw) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <textarea id="section_3_raw_copy" style="width: 100%; min-height: 60px; font-size: 13px; font-family: monospace; padding: 10px; box-sizing: border-box; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface);" readonly><?= h(implode(', ', $package['main_keywords'])) ?></textarea>
                        </div>
                    </section>

                    <!-- SECCIÓN 4: 15 LONG-TAIL KEYWORDS -->
                    <section class="panel">
                        <div class="section-heading" style="display: flex; justify-content: space-between; align-items: center; width: 100%; gap: 12px;">
                            <div>
                                <h2>Sección 4: 15 Long-tail Keywords</h2>
                                <p>Keywords de cola larga relevantes para SEO y posicionamiento</p>
                            </div>
                            <button class="copy-button secondary" type="button" id="copy_section_4" aria-label="Copiar Sección 4" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                        </div>

                        <div style="background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius); padding: 16px; margin-top: 16px;">
                            <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px;">
                                <?php foreach ($package['long_tail_keywords'] as $kw): ?>
                                    <span class="keyword-chip" style="background: var(--surface); border: 1px solid var(--line); padding: 4px 10px; border-radius: 999px; font-size: 12px;"><?= h($kw) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <textarea id="section_4_raw_copy" style="width: 100%; min-height: 60px; font-size: 13px; font-family: monospace; padding: 10px; box-sizing: border-box; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface);" readonly><?= h(implode(', ', $package['long_tail_keywords'])) ?></textarea>
                        </div>
                    </section>

                    <!-- SECCIÓN 5: 15 LONG-TAIL (CON GUIONES MEDIOS) -->
                    <section class="panel">
                        <div class="section-heading" style="display: flex; justify-content: space-between; align-items: center; width: 100%; gap: 12px;">
                            <div>
                                <h2>Sección 5: 15 Long-tail (con guiones medios)</h2>
                                <p>Keywords de cola larga unidas con guón medio</p>
                            </div>
                            <button class="copy-button secondary" type="button" id="copy_section_5" aria-label="Copiar Sección 5" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                        </div>

                        <div style="background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius); padding: 16px; margin-top: 16px;">
                            <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px;">
                                <?php foreach ($hyphenatedLongTails as $kw): ?>
                                    <span class="keyword-chip" style="background: var(--surface); border: 1px solid var(--line); padding: 4px 10px; border-radius: 999px; font-size: 12px; font-family: monospace;"><?= h($kw) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <textarea id="section_5_raw_copy" style="width: 100%; min-height: 60px; font-size: 13px; font-family: monospace; padding: 10px; box-sizing: border-box; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface);" readonly><?= h(implode(', ', $hyphenatedLongTails)) ?></textarea>
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
                            <summary style="font-weight: 600; cursor: pointer; color: var(--ink);">Ver Análisis de IA Crudo (JSON)</summary>
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
        let text = `[Obra Root]\n`;
        text += `Archivo SEO: ${document.querySelector('.seo-root-filename')?.textContent || ''}\n`;
        text += `Alt: ${document.querySelector('.seo-root-alt')?.textContent || ''}\n`;
        text += `Caption: ${document.querySelector('.seo-root-caption')?.textContent || ''}\n\n`;

        document.querySelectorAll('.mockup-card-container').forEach((card, idx) => {
            const name = card.getAttribute('data-context-name') || `Mockup ${idx + 1}`;
            text += `[Mockup ${idx + 1}: ${name}]\n`;
            text += `Archivo SEO: ${card.querySelector('.seo-mockup-filename')?.textContent || ''}\n`;
            text += `Alt: ${card.querySelector('.mockup-alt-text')?.textContent || ''}\n`;
            text += `Caption: ${card.querySelector('.mockup-caption-text')?.textContent || ''}\n\n`;
        });
        return text.trim();
    }

    function getSection1Text() {
        let text = '';
        document.querySelectorAll('.title-grid-unified article').forEach((card, idx) => {
            const labels = card.querySelectorAll('.selected-label');
            const label = labels[0]?.textContent || `Opción ${idx + 1}`;
            const title = card.querySelector('h3')?.textContent || '';
            const sub = card.querySelector('.title-option-subtitle')?.textContent || '';
            text += `${label}:\n`;
            text += `Título: ${title}\n`;
            if (sub) {
                text += `Subtítulo: ${sub}\n`;
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
    document.getElementById('copy_section_3')?.addEventListener('click', function() {
        copySection(this, () => document.getElementById('section_3_raw_copy')?.value || '');
    });
    document.getElementById('copy_section_4')?.addEventListener('click', function() {
        copySection(this, () => document.getElementById('section_4_raw_copy')?.value || '');
    });
    document.getElementById('copy_section_5')?.addEventListener('click', function() {
        copySection(this, () => document.getElementById('section_5_raw_copy')?.value || '');
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
        button.innerHTML = 'Generando...';

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
                throw new Error(readable || 'El servidor devolvió una respuesta inválida.');
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

        if (!confirm('¿Seguro que desea eliminar este mockup?')) {
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
