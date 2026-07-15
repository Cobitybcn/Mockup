<?php
$funcs = <<<'EOD'

function admin_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace("/[^a-z0-9]+/", "-", $value) ?: "";
    return trim($value, "-");
}

function artist_profile_defaults(array $profile = []): array
{
    $defaults = [
        "name" => "Maurizio Valch",
        "tagline" => "Abstract painting / Territory and Thought",
        "intro" => "Maurizio Valch develops an abstract painting practice centered on territories where thought seems to emerge from the earth. His work explores horizons, strata, monolithic presences and incised lines as signs of formation, silence and emerging consciousness.",
        "portrait" => [
            "image" => "",
            "alt" => "Maurizio Valch portrait",
            "caption" => "",
        ],
        "biography" => "Maurizio Valch is a visual artist whose work moves between abstract painting, symbolic territory and structural silence. His practice develops through horizons, strata, monolithic presences and incised lines that behave as signs of formation and emerging consciousness.",
        "statement_excerpt" => "Rather than depicting landscapes, the paintings construct silent fields of appearance, where structure, matter and consciousness seem to arise together.",
        "statement_button_text" => "Read Artist Statement",
        "statement_button_url" => "/artist-statement",
        "statement_page" => [
            "title" => "Genesis of Metaphysical Territories",
            "intro" => "Maurizio Valch develops an abstract painting practice centered on territories where thought seems to emerge from the earth.",
            "body" => [
                "His work explores horizons, strata, monolithic presences and incised lines as signs of an inner and territorial formation. Rather than depicting landscapes, the paintings construct silent fields of appearance, where structure, matter and consciousness seem to arise together.",
                "The surface becomes a place of discovery. Fault lines, ground frequency and tectonic tension suggest a territory in formation, not as a fixed geography, but as a perceptual space where thought begins to organize itself before language.",
                "Valch\"s painting relates to the act of marking as an originary gesture: closer to the first human need to leave signs upon the world than to academic construction. Each work proposes a quiet encounter between matter, distance, silence and emerging consciousness.",
            ],
        ],
        "studio_images" => [],
        "genealogy" => [
            ["title" => "Inner Vortex", "description" => "The force before territory.", "url" => "/series/inner-vortex-series/"],
            ["title" => "Stratified Faces", "description" => "The face as divided territory.", "url" => "/series/stratified-faces/"],
            ["title" => "Structural Metaphysical Painting", "description" => "The territory becomes landscape, horizon and passage.", "url" => "/series/structural-metaphysical-painting/"],
            ["title" => "Strata", "description" => "The landscape is reduced to layers, fault lines and ground frequency.", "url" => "/series/strata-series-maurizio-valch/"],
        ],
        "links" => [
            ["text" => "Read Artist Statement", "url" => "/artist-statement"],
            ["text" => "View Exhibitions & Collections", "url" => "/exhibitions-collections"],
            ["text" => "Explore Painting Series", "url" => "/series"],
        ],
        "exhibitions" => [
            ["title" => "Reial Cercle Artistic de Barcelona", "description" => "Selected group exhibition context", "url" => ""],
            ["title" => "Gran Teatre del Liceu", "description" => "Work presented in Barcelona, 2017", "url" => ""],
            ["title" => "Private Collections", "description" => "Works acquired internationally through direct and marketplace channels", "url" => ""],
            ["title" => "Upcoming Solo Exhibition", "description" => "Valencia, Spain, 2026", "url" => ""],
        ],
        "seo" => [
            "title" => "Maurizio Valch | Artist",
            "description" => "Maurizio Valch is a visual artist developing abstract painting centered on territory, thought, horizons, strata and emerging consciousness.",
            "keywords" => "Maurizio Valch, abstract painting, territory and thought, contemporary artist",
            "og_image" => "",
        ],
    ];

    $profile = array_replace_recursive($defaults, $profile);
    $profile["studio_images"] = array_values($profile["studio_images"] ?? []);
    usort($profile["studio_images"], fn ($a, $b) => (int) ($a["sort"] ?? 0) <=> (int) ($b["sort"] ?? 0));
    $profile["genealogy"] = array_values($profile["genealogy"] ?? $defaults["genealogy"]);
    $profile["links"] = array_values($profile["links"] ?? $defaults["links"]);
    $profile["statement_page"]["body"] = array_values($profile["statement_page"]["body"] ?? $defaults["statement_page"]["body"]);
    return $profile;
}

function country_coordinates(string $country): ?array
{
    $key = strtolower(trim($country));
    $key = str_replace(
        ["á", "é", "í", "ó", "ú", "ñ", "Ã¡", "Ã©", "Ã­", "Ã³", "Ãº", "Ã±"],
        ["a", "e", "i", "o", "u", "n", "a", "e", "i", "o", "u", "n"],
        $key
    );
    $coordinates = [
        "argentina" => [-38.4161, -63.6167],
        "alemania" => [51.1657, 10.4515],
        "australia" => [-25.2744, 133.7751],
        "austria" => [47.5162, 14.5501],
        "belgium" => [50.5039, 4.4699],
        "belgica" => [50.5039, 4.4699],
        "bélgica" => [50.5039, 4.4699],
        "brasil" => [-14.2350, -51.9253],
        "brazil" => [-14.2350, -51.9253],
        "canada" => [56.1304, -106.3468],
        "chile" => [-35.6751, -71.5430],
        "colombia" => [4.5709, -74.2973],
        "bulgaria" => [42.7339, 25.4858],
        "denmark" => [56.2639, 9.5018],
        "france" => [46.2276, 2.2137],
        "francia" => [46.2276, 2.2137],
        "germany" => [51.1657, 10.4515],
        "greece" => [39.0742, 21.8243],
        "grecia" => [39.0742, 21.8243],
        "greek" => [39.0742, 21.8243],
        "italy" => [41.8719, 12.5674],
        "italia" => [41.8719, 12.5674],
        "mexico" => [23.6345, -102.5528],
        "netherlands" => [52.1326, 5.2913],
        "paises bajos" => [52.1326, 5.2913],
        "norway" => [60.4720, 8.4689],
        "portugal" => [39.3999, -8.2245],
        "spain" => [40.4637, -3.7492],
        "espana" => [40.4637, -3.7492],
        "sweden" => [60.1282, 18.6435],
        "switzerland" => [46.8182, 8.2275],
        "uk" => [55.3781, -3.4360],
        "united kingdom" => [55.3781, -3.4360],
        "reino unido" => [55.3781, -3.4360],
        "usa" => [37.0902, -95.7129],
        "united states" => [37.0902, -95.7129],
        "estados unidos" => [37.0902, -95.7129],
        "uruguay" => [-32.5228, -55.7658],
    ];

    return isset($coordinates[$key]) ? ["lat" => $coordinates[$key][0], "lng" => $coordinates[$key][1]] : null;
}

function map_location_coordinates(array $location): ?array
{
    $countryCoordinates = !empty($location["country"]) ? country_coordinates((string) $location["country"]) : null;

    if (isset($location["lat"], $location["lng"]) && is_numeric($location["lat"]) && is_numeric($location["lng"])) {
        $coordinates = ["lat" => (float) $location["lat"], "lng" => (float) $location["lng"]];
        if ($countryCoordinates) {
            $latDelta = abs($coordinates["lat"] - $countryCoordinates["lat"]);
            $lngDelta = abs($coordinates["lng"] - $countryCoordinates["lng"]);
            if ($latDelta > 12 || $lngDelta > 18) {
                return $countryCoordinates;
            }
        }
        return $coordinates;
    }

    if ($countryCoordinates) {
        return $countryCoordinates;
    }

    return null;
}

function map_project_coordinates(float $lat, float $lng): array
{
    $x = (($lng + 180) / 360) * 100;
    $y = ((90 - $lat) / 180) * 100;
    if ($lat >= 30 && $lat <= 72 && $lng >= -25 && $lng <= 45) {
        $x = ($x * .98) + 1.4;
        $y = ($y * 1.15) + 3.8;
    }

    return [
        "x" => max(0, min(100, $x)),
        "y" => max(0, min(100, $y)),
    ];
}

function series_representative_image(string $slug, array $item, array $artworks): string
{
    $seriesImage = $item["image"] ?? "";
    foreach ($artworks as $artwork) {
        if ($seriesImage === "" && ($artwork["series"] ?? "") === $slug && !empty($artwork["image"])) {
            $seriesImage = $artwork["image"];
            break;
        }
    }
    return $seriesImage;
}
EOD;

file_put_contents("inc/functions.php", $funcs, FILE_APPEND);
?>
