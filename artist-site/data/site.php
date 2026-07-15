<?php

$runtimeSiteUrl = trim((string)(getenv('ARTIST_SITE_PUBLIC_URL') ?: ''));

$site = [
    'name' => 'Maurizio Valch',
    'url' => $runtimeSiteUrl !== '' ? rtrim($runtimeSiteUrl, '/') : 'https://mauriziovalch.com',
    'tagline' => 'Abstract Painting / Territory and Thought',
    'description' => 'Large-scale structural metaphysical paintings exploring strata, fault lines, ground frequency, monoliths, horizons, and the sedimented time of territory.',
    'email' => 'studio@mauriziovalch.com',
    'social' => [
        'TikTok' => 'https://www.tiktok.com/@maurizio_valch',
        'Instagram' => 'https://www.instagram.com/mauriziovalch/',
        'Facebook' => 'https://www.facebook.com/MaurizioValch',
        'X / Twitter' => 'https://x.com/MauriValch',
        'Pinterest' => 'https://es.pinterest.com/maurizio_artista/',
        'Saatchi Art' => 'https://www.saatchiart.com/maurizioart',
    ],
];

$series = [
    'structural-metaphysical-painting' => [
        'title' => 'Structural Metaphysical Painting',
        'seo_title' => 'Structural Metaphysical Painting | Maurizio Valch',
        'description' => 'The current body of work: large-scale paintings where strata, fault lines, ground frequency, monolith, horizon, and structural silence hold sedimented time.',
        'keywords' => ['structural metaphysical painting', 'metaphysical abstraction', 'large abstract painting'],
    ],
    'strata' => [
        'title' => 'Strata',
        'seo_title' => 'Strata Paintings | Maurizio Valch',
        'description' => 'Capas, estratos, and sedimented time: paintings where the surface behaves like compressed territory.',
        'keywords' => ['strata painting', 'sedimented time', 'layered abstract painting'],
    ],
    'fault-lines' => [
        'title' => 'Fault Lines',
        'seo_title' => 'Fault Lines Paintings | Maurizio Valch',
        'description' => 'Silent rupture, tectonic tension, and structural discontinuity held inside the painted field.',
        'keywords' => ['fault lines painting', 'tectonic abstraction', 'structural rupture'],
    ],
    'ground-frequency' => [
        'title' => 'Ground Frequency',
        'seo_title' => 'Ground Frequency Paintings | Maurizio Valch',
        'description' => 'The frequency of the ground: vibration before language, before image becomes architecture.',
        'keywords' => ['ground frequency painting', 'metaphysical vibration', 'territory abstraction'],
    ],
    'earlier-symbolic-works' => [
        'title' => 'Earlier Symbolic Works',
        'seo_title' => 'Earlier Symbolic and Poetic Paintings by Maurizio Valch',
        'description' => 'Earlier symbolic works with portals, stairways, nocturnal fields, mystical faces, and poetic metaphors.',
        'keywords' => ['symbolic painting', 'mystical abstract painting', 'poetic abstraction'],
    ],
    'inner-vortex-series' => [
        'title' => 'Inner Vortex 2017',
        'seo_title' => 'Inner Vortex Series | Since 2017 | Maurizio Valch',
        'description' => 'Inner Vortex is one of Maurizio Valch’s earlier bodies of work, developed from 2017 onward. The series reveals the inner force before it becomes territory.',
        'image' => '/assets/uploads/2-20260608123054-513019.jpg',
        'keywords' => [
            'inner vortex',
            'spiral painting',
            'vortex painting',
            'abstract painting',
            'archetypal abstraction',
            'symbolic abstraction',
            'inner force',
            'psychological painting',
            'territory',
            'genesis',
            'Maurizio Valch',
        ],
    ],
    'stratified-faces' => [
        'title' => 'Stratified Faces 2019',
        'seo_title' => 'Stratified Faces | Since 2019 | Maurizio Valch',
        'description' => 'Stratified Faces is an earlier body of work by Maurizio Valch, developed from 2019 onward. In this series, the face appears as divided territory.',
        'image' => '/assets/uploads/4-20260608123313-5eca6f.webp',
        'keywords' => [
            'stratified faces',
            'face painting',
            'symbolic portrait',
            'abstract portrait',
            'fragmented face',
            'figurative abstraction',
            'layered painting',
            'identity',
            'Maurizio Valch',
        ],
    ],
];

$artworks = [
    'the-path-before-architecture' => [
        'title' => 'The Path Before Architecture',
        'year' => '2026',
        'series' => 'structural-metaphysical-painting',
        'status' => 'available',
        'medium' => 'Acrylic on canvas',
        'dimensions_cm' => '80 x 120 cm',
        'dimensions_in' => '31 x 47 in',
        'orientation' => 'Horizontal',
        'image' => '/assets/images/the-path-before-architecture.jpg',
        'price' => 'Inquire',
        'summary' => 'A large architectural abstract painting where a pale path enters a field of monolithic structures and suspended territory.',
        'concept' => 'The work positions architecture before culture: a primordial territory where structure appears to rise from the earth itself. The stairway is not decorative; it marks the emergence of vertical consciousness inside a silent system of mass and horizon.',
        'commercial_note' => 'Original hand-painted acrylic work on canvas, shipped from Spain with certificate of authenticity.',
    ],
    'metaphysical-tectonic-territory' => [
        'title' => 'Metaphysical Tectonic Territory',
        'year' => '2026',
        'series' => 'fault-lines',
        'status' => 'sold',
        'medium' => 'Acrylic on canvas',
        'dimensions_cm' => '80 x 120 cm',
        'dimensions_in' => '31 x 47 in',
        'orientation' => 'Horizontal',
        'image' => '/assets/images/metaphysical-tectonic-territory.jpg',
        'detail_image' => '/assets/images/metaphysical-tectonic-territory-detail.jpg',
        'sale_platform' => 'Private collection',
        'sale_result' => 'Placed',
        'summary' => 'A symbolic geological field structured through horizon lines, dark chromatic mass, and luminous monolithic fragments.',
        'concept' => 'The composition behaves like a map of interior terrain: tectonic planes, measured horizon, and chromatic interruptions create a silent territory where perception appears before narrative.',
        'commercial_note' => 'Placed in a private collection.',
    ],
    'the-geometry-of-consciousness' => [
        'title' => 'The Geometry of Consciousness',
        'year' => '2025',
        'series' => 'ground-frequency',
        'status' => 'sold',
        'medium' => 'Acrylic on canvas',
        'dimensions_cm' => '80 x 120 cm',
        'dimensions_in' => '31 x 47 in',
        'orientation' => 'Horizontal',
        'image' => '/assets/images/the-geometry-of-consciousness.jpg',
        'sale_platform' => 'Private collection',
        'sale_result' => 'Placed',
        'summary' => 'A contemplative horizontal structure built from deep fields, red-blue architectural fragments, and linear thresholds.',
        'concept' => 'Geometry becomes the trace of an inner architecture. The work avoids spectacle and instead constructs a disciplined field where proportion, distance, and silence carry the image.',
        'commercial_note' => 'Placed in a private collection.',
    ],
];

$sold_records = [
    [
        'title' => 'Metaphysical Tectonic Territory',
        'year' => '2026',
        'dimensions' => '80 x 120 cm',
        'platform' => 'Private Collection',
        'result' => 'Placed',
        'public_price' => 'Acquired',
        'cluster' => 'Metaphysical landscapes / tectonic territory',
        'url' => '#',
    ],
    [
        'title' => 'The Geometry of Consciousness',
        'year' => '2025',
        'dimensions' => '80 x 120 cm',
        'platform' => 'Private Collection',
        'result' => 'Placed',
        'public_price' => 'Acquired',
        'cluster' => 'Monoliths and horizons / structural abstraction',
        'url' => '#',
    ],
    [
        'title' => 'Echoes XXL',
        'year' => '2025',
        'dimensions' => '80 x 120 cm',
        'platform' => 'Private Collection',
        'result' => 'Placed',
        'public_price' => 'Acquired',
        'cluster' => 'Poetic architectural abstraction',
        'url' => '#',
    ],
    [
        'title' => 'Inner Passage',
        'year' => '2025',
        'dimensions' => '80 x 120 cm',
        'platform' => 'Private Collection',
        'result' => 'Placed',
        'public_price' => 'Acquired',
        'cluster' => 'Interior territories / horizontal strata',
        'url' => '#',
    ],
    [
        'title' => 'Ascendere - Geometric Abstraction',
        'year' => '2025',
        'dimensions' => '80 x 90 cm',
        'platform' => 'Private Collection',
        'result' => 'Placed',
        'public_price' => 'Acquired',
        'cluster' => 'Stairway symbol / geometric abstraction',
        'url' => '#',
    ],
];

$sold_locations = [
    /*
    Add one entry per placed work once the postal zone is known.
    Keep collector identity, street address, email, phone, and private notes out of this file.

    [
        'title' => 'Metaphysical Tectonic Territory',
        'postal_code' => '46001',
        'country' => 'Spain',
        'lat' => 39.4699,
        'lng' => -0.3763,
    ],
    */
];

$journal = [
    'architectural-abstract-painting' => [
        'title' => 'Architectural Abstract Painting: Structure, Horizon and Silence',
        'seo_title' => 'Architectural Abstract Painting | Structure, Horizon and Silence',
        'description' => 'An introduction to Maurizio Valch\'s use of architectural structure in contemporary abstract painting.',
        'body' => [
            'Architectural abstract painting does not need to depict buildings. In Maurizio Valch\'s work, architecture appears as a structural principle: mass, horizon, proportion, threshold, and void.',
            'This language allows the painting to operate as a spatial proposition rather than a decorative surface. The viewer enters a field where silence has weight and geometry carries psychological tension.',
        ],
    ],
    'metaphysical-landscape-painting' => [
        'title' => 'Metaphysical Landscape Painting and the Emergence of Presence',
        'seo_title' => 'Metaphysical Landscape Painting | Maurizio Valch',
        'description' => 'How territory, geological structure, and consciousness converge in metaphysical landscape painting.',
        'body' => [
            'Metaphysical landscape painting treats territory as a field of emergence. It is not scenery, and it is not topography in a documentary sense.',
            'In Valch\'s work, horizons organize perception, monoliths anchor presence, and incised stairways introduce the scale of the individual entering the territory.',
        ],
    ],
];

function load_json_folder(string $dir): array
{
    $data = [];
    if (is_dir($dir)) {
        $files = glob($dir . "/*.json");
        if ($files) {
            foreach ($files as $file) {
                $slug = basename($file, ".json");
                $itemContent = json_decode((string) file_get_contents($file), true);
                if ($itemContent) {
                    $data[$slug] = $itemContent;
                }
            }
        }
    }
    return $data;
}

$settingsFile = __DIR__ . "/settings.json";
if (is_file($settingsFile)) {
    $settings = json_decode((string) file_get_contents($settingsFile), true);
    if ($settings) {
        $site = array_replace_recursive($site, $settings);
    }
}

$soldRecordsFile = __DIR__ . "/sold-records.json";
if (is_file($soldRecordsFile)) {
    $sold_records = json_decode((string) file_get_contents($soldRecordsFile), true) ?? $sold_records;
}

$soldLocationsFile = __DIR__ . "/sold-locations.json";
if (is_file($soldLocationsFile)) {
    $sold_locations = json_decode((string) file_get_contents($soldLocationsFile), true) ?? $sold_locations;
}

$artworks = load_json_folder(__DIR__ . "/artworks") ?: $artworks;
$series = load_json_folder(__DIR__ . "/series") ?: $series;
$journal = load_json_folder(__DIR__ . "/studio-notes") ?: $journal;
$blog = load_json_folder(__DIR__ . "/blog");
