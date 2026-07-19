<?php
declare(strict_types=1);

final class StudioReferenceCatalog
{
    public static function references(): array
    {
        $references = [
            ['id' => 'arched-courtyard', 'title' => 'Arched Courtyard', 'category' => 'Architecture', 'motif' => 'architecture', 'colors' => ['#eee5d8', '#9f7560', '#d1b99d', '#6f574c']],
            ['id' => 'quiet-concrete', 'title' => 'Quiet Concrete', 'category' => 'Architecture', 'motif' => 'architecture', 'colors' => ['#e8e7e2', '#737872', '#b6b3aa', '#555953']],
            ['id' => 'late-window', 'title' => 'Late Window', 'category' => 'Light', 'motif' => 'light', 'colors' => ['#363937', '#787d72', '#cab485', '#e8cf91']],
            ['id' => 'soft-overcast', 'title' => 'Soft Overcast', 'category' => 'Atmosphere', 'motif' => 'atmosphere', 'colors' => ['#e9ece8', '#aab8af', '#cbd3cb', '#8b9a92']],
            ['id' => 'pigment-plaster', 'title' => 'Pigment Plaster', 'category' => 'Materials', 'motif' => 'materials', 'colors' => ['#f0e9df', '#b77f78', '#d4b59d', '#8a756a']],
            ['id' => 'aged-copper', 'title' => 'Aged Copper', 'category' => 'Materials', 'motif' => 'materials', 'colors' => ['#e8e3d8', '#9c745f', '#728b7e', '#c2a276']],
            ['id' => 'low-lounge', 'title' => 'Low Lounge', 'category' => 'Furniture', 'motif' => 'furniture', 'colors' => ['#ebe5dc', '#92766b', '#c1a491', '#655d55']],
            ['id' => 'still-presence', 'title' => 'Still Presence', 'category' => 'Characters', 'motif' => 'characters', 'colors' => ['#e5e0da', '#877178', '#bca29b', '#4f4a48']],
            ['id' => 'olive-shadow', 'title' => 'Olive Shadow', 'category' => 'Vegetation', 'motif' => 'vegetation', 'colors' => ['#e7e7dc', '#87977a', '#adb697', '#5f7058']],
            ['id' => 'worn-tile', 'title' => 'Worn Tile', 'category' => 'Textures', 'motif' => 'textures', 'colors' => ['#eee6d8', '#b98b74', '#d3b59e', '#718895'], 'variant' => 1],
            ['id' => 'off-axis-balance', 'title' => 'Off-axis Balance', 'category' => 'Composition', 'motif' => 'composition', 'colors' => ['#eee9df', '#9a7d75', '#c6b8a6', '#7b8d91']],
            ['id' => 'earth-pigments', 'title' => 'Earth Pigments', 'category' => 'Color', 'motif' => 'color', 'colors' => ['#eee7dc', '#a56f65', '#b99a73', '#748678']],
        ];

        foreach ($references as &$reference) {
            $reference['image'] = self::image(
                (string)$reference['motif'],
                (array)$reference['colors'],
                (int)($reference['variant'] ?? 0)
            );
        }
        unset($reference);

        return $references;
    }

    public static function map(): array
    {
        $map = [];
        foreach (self::references() as $reference) {
            $map[(string)$reference['id']] = $reference;
        }
        return $map;
    }

    public static function categories(): array
    {
        return ['Architecture', 'Light', 'Materials', 'Atmosphere', 'Furniture', 'Characters', 'Vegetation', 'Textures', 'Composition', 'Color'];
    }

    public static function starterSets(): array
    {
        return [
            ['name' => 'Mediterranean Silence', 'description' => 'Warm architecture, restrained daylight and quiet vegetation.', 'color' => 'ochre', 'references' => ['arched-courtyard', 'late-window', 'olive-shadow']],
            ['name' => 'Catalan Modernism', 'description' => 'Architectural rhythm, tactile plaster and an editorial composition.', 'color' => 'rose', 'references' => ['arched-courtyard', 'pigment-plaster', 'off-axis-balance']],
            ['name' => 'Industrial Silence', 'description' => 'Quiet concrete, muted atmosphere and aged material surfaces.', 'color' => 'blue', 'references' => ['quiet-concrete', 'soft-overcast', 'aged-copper']],
            ['name' => 'Luxury Collector', 'description' => 'Low furniture, softened light and controlled earthy color.', 'color' => 'lilac', 'references' => ['low-lounge', 'soft-overcast', 'earth-pigments']],
            ['name' => 'Autumn Interior', 'description' => 'Late light, aged copper and a warm pigment direction.', 'color' => 'sage', 'references' => ['late-window', 'aged-copper', 'earth-pigments']],
        ];
    }

    private static function image(string $motif, array $colors, int $variant = 0): string
    {
        [$background, $primary, $secondary, $accent] = array_pad($colors, 4, '#f4efe8');
        $shapes = '';

        if ($motif === 'architecture') {
            $shapes = '<rect x="28" y="28" width="264" height="164" rx="4" fill="' . $secondary . '"/>'
                . '<path d="M78 192V103a42 42 0 0 1 84 0v89M184 192V82a32 32 0 0 1 64 0v110" fill="' . $primary . '"/>'
                . '<rect x="52" y="192" width="216" height="18" fill="' . $accent . '"/>';
        } elseif ($motif === 'light') {
            $shapes = '<rect x="38" y="26" width="104" height="176" fill="' . $primary . '"/>'
                . '<rect x="50" y="40" width="80" height="122" fill="' . $accent . '"/>'
                . '<path d="M130 162L278 202H130Z" fill="' . $secondary . '"/>'
                . '<path d="M90 40V162M50 101H130" stroke="' . $background . '" stroke-width="6"/>';
        } elseif ($motif === 'materials') {
            $shapes = '<rect x="34" y="30" width="78" height="174" fill="' . $primary . '"/>'
                . '<rect x="121" y="30" width="78" height="174" fill="' . $secondary . '"/>'
                . '<rect x="208" y="30" width="78" height="174" fill="' . $accent . '"/>'
                . '<path d="M45 62h56M45 92h56M45 122h56M132 54l56 35M132 92l56 35M219 58h56M219 82h56M219 106h56M219 130h56" stroke="' . $background . '" stroke-width="5" opacity=".72"/>';
        } elseif ($motif === 'atmosphere') {
            $shapes = '<circle cx="92" cy="112" r="68" fill="' . $primary . '"/>'
                . '<circle cx="176" cy="85" r="54" fill="' . $secondary . '"/>'
                . '<circle cx="230" cy="150" r="74" fill="' . $accent . '"/>'
                . '<rect x="24" y="176" width="272" height="34" fill="' . $primary . '" opacity=".64"/>';
        } elseif ($motif === 'furniture') {
            $shapes = '<rect x="62" y="76" width="196" height="76" rx="18" fill="' . $primary . '"/>'
                . '<rect x="48" y="132" width="224" height="42" rx="8" fill="' . $secondary . '"/>'
                . '<path d="M76 174v34M244 174v34M93 76V48M227 76V48" stroke="' . $accent . '" stroke-width="12"/>'
                . '<circle cx="160" cy="115" r="22" fill="' . $accent . '"/>';
        } elseif ($motif === 'characters') {
            $shapes = '<circle cx="160" cy="60" r="32" fill="' . $accent . '"/>'
                . '<path d="M112 198c2-69 17-106 48-106s46 37 48 106Z" fill="' . $primary . '"/>'
                . '<path d="M76 200c4-50 15-77 37-83M244 200c-4-50-15-77-37-83" stroke="' . $secondary . '" stroke-width="18" stroke-linecap="round"/>';
        } elseif ($motif === 'vegetation') {
            $shapes = '<path d="M160 210V42" stroke="' . $accent . '" stroke-width="8"/>'
                . '<ellipse cx="112" cy="80" rx="52" ry="25" transform="rotate(24 112 80)" fill="' . $primary . '"/>'
                . '<ellipse cx="208" cy="104" rx="52" ry="25" transform="rotate(-28 208 104)" fill="' . $secondary . '"/>'
                . '<ellipse cx="116" cy="150" rx="58" ry="27" transform="rotate(20 116 150)" fill="' . $secondary . '"/>'
                . '<ellipse cx="208" cy="174" rx="52" ry="24" transform="rotate(-22 208 174)" fill="' . $primary . '"/>';
        } elseif ($motif === 'textures') {
            for ($row = 0; $row < 5; $row++) {
                for ($column = 0; $column < 7; $column++) {
                    $fill = (($row + $column + $variant) % 3 === 0) ? $accent : ((($row + $column) % 2 === 0) ? $primary : $secondary);
                    $shapes .= '<rect x="' . (22 + $column * 41) . '" y="' . (22 + $row * 40) . '" width="34" height="32" rx="3" fill="' . $fill . '"/>';
                }
            }
        } elseif ($motif === 'composition') {
            $shapes = '<rect x="34" y="28" width="252" height="176" fill="' . $secondary . '"/>'
                . '<rect x="56" y="48" width="86" height="136" fill="' . $primary . '"/>'
                . '<rect x="154" y="48" width="110" height="62" fill="' . $accent . '"/>'
                . '<circle cx="210" cy="154" r="38" fill="' . $background . '"/>';
        } else {
            $shapes = '<rect x="28" y="26" width="74" height="182" fill="' . $primary . '"/>'
                . '<rect x="111" y="26" width="74" height="182" fill="' . $secondary . '"/>'
                . '<rect x="194" y="26" width="98" height="182" fill="' . $accent . '"/>';
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 232" role="img">'
            . '<rect width="320" height="232" fill="' . $background . '"/>'
            . $shapes
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}

