<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
$userId = (int)($argv[1] ?? 1);
$threshold = (int)($argv[2] ?? 14);

$rows = $pdo->prepare("
    SELECT a.id, a.artwork_group_id, a.root_file, a.main_file, a.width, a.height, a.unit
    FROM artworks a
    INNER JOIN artwork_groups g ON g.id = a.artwork_group_id
    WHERE a.user_id = ?
    AND g.status = 'active'
    AND COALESCE(a.root_file, a.main_file, '') <> ''
    ORDER BY a.id ASC
");
$rows->execute([$userId]);
$artworks = [];
foreach ($rows->fetchAll() as $row) {
    $file = basename((string)($row['root_file'] ?: $row['main_file']));
    $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        continue;
    }
    $hash = imageHash($path);
    if ($hash === null) {
        continue;
    }
    $artworks[] = [
        'id' => (int)$row['id'],
        'group_id' => (int)$row['artwork_group_id'],
        'file' => $file,
        'size' => trim((string)$row['width'] . 'x' . (string)$row['height'] . ' ' . (string)$row['unit']),
        'hash' => $hash,
    ];
}

$pairs = [];
$count = count($artworks);
for ($i = 0; $i < $count; $i++) {
    for ($j = $i + 1; $j < $count; $j++) {
        if ($artworks[$i]['group_id'] === $artworks[$j]['group_id']) {
            continue;
        }
        $distance = hamming($artworks[$i]['hash'], $artworks[$j]['hash']);
        if ($distance <= $threshold) {
            $pairs[] = [$distance, $artworks[$i], $artworks[$j]];
        }
    }
}

usort($pairs, fn(array $a, array $b): int => $a[0] <=> $b[0]);
foreach ($pairs as [$distance, $a, $b]) {
    echo 'distance=' . $distance
        . ' group=' . $a['group_id'] . ' artwork=' . $a['id'] . ' size=' . $a['size']
        . ' file=' . $a['file'] . "\n";
    echo '           group=' . $b['group_id'] . ' artwork=' . $b['id'] . ' size=' . $b['size']
        . ' file=' . $b['file'] . "\n";
}
echo 'pairs=' . count($pairs) . ' artworks=' . count($artworks) . "\n";

function imageHash(string $path): ?string
{
    $data = @file_get_contents($path);
    if ($data === false) {
        return null;
    }
    $source = @imagecreatefromstring($data);
    if (!$source) {
        return null;
    }

    $small = imagecreatetruecolor(9, 8);
    imagecopyresampled($small, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source));
    imagedestroy($source);

    $bits = '';
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $left = grayAt($small, $x, $y);
            $right = grayAt($small, $x + 1, $y);
            $bits .= $left > $right ? '1' : '0';
        }
    }
    imagedestroy($small);
    return $bits;
}

function grayAt(GdImage $image, int $x, int $y): int
{
    $rgb = imagecolorat($image, $x, $y);
    $r = ($rgb >> 16) & 0xFF;
    $g = ($rgb >> 8) & 0xFF;
    $b = $rgb & 0xFF;
    return (int)round(($r * 0.299) + ($g * 0.587) + ($b * 0.114));
}

function hamming(string $a, string $b): int
{
    $distance = 0;
    $length = min(strlen($a), strlen($b));
    for ($i = 0; $i < $length; $i++) {
        if ($a[$i] !== $b[$i]) {
            $distance++;
        }
    }
    return $distance + abs(strlen($a) - strlen($b));
}
