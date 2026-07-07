<?php
declare(strict_types=1);

foreach ([
    'storage/ficha_proposal.json',
    'storage/artwork_sheets_hints_20260703.json',
] as $path) {
    if (!is_file($path)) {
        echo $path . ": missing\n";
        continue;
    }

    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        echo $path . ": invalid json\n";
        continue;
    }

    $groups = $data['groups'] ?? $data;
    if (!is_array($groups)) {
        echo $path . ": no groups\n";
        continue;
    }

    $sizes = [];
    foreach ($groups as $group) {
        $ids = is_array($group) ? ($group['artwork_ids'] ?? $group['ids'] ?? []) : [];
        $sizes[] = is_array($ids) ? count($ids) : 0;
    }
    sort($sizes);

    echo $path . "\n";
    echo "groups=" . count($groups) . "\n";
    echo "sizes_min=" . implode(',', array_slice($sizes, 0, 10)) . "\n";
    echo "sizes_max=" . implode(',', array_slice($sizes, -10)) . "\n";
    echo "first=" . json_encode($groups[0] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
}
