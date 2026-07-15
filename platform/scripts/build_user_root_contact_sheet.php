<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
$userId = (int)($argv[1] ?? 1);
$out = __DIR__ . '/../storage/tmp/user_' . $userId . '_root_contact_sheet.jpg';
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0775, true);
}

$stmt = $pdo->prepare("
    SELECT g.id AS group_id, g.canonical_artwork_id, a.id, a.root_file, a.main_file, a.width, a.height, a.unit, a.root_view_status
    FROM artwork_groups g
    LEFT JOIN artworks a ON a.artwork_group_id = g.id
    WHERE g.user_id = ?
    AND g.status = 'active'
    ORDER BY g.id ASC, a.root_view_status = 'official' DESC, a.id ASC
");
$stmt->execute([$userId]);
$byGroup = [];
foreach ($stmt->fetchAll() as $row) {
    $groupId = (int)$row['group_id'];
    if (!isset($byGroup[$groupId])) {
        $byGroup[$groupId] = [];
    }
    if (!empty($row['id'])) {
        $byGroup[$groupId][] = $row;
    }
}

$cellW = 190;
$cellH = 230;
$cols = 5;
$rows = (int)ceil(max(1, count($byGroup)) / $cols);
$sheet = imagecreatetruecolor($cellW * $cols, $cellH * $rows);
$bg = imagecolorallocate($sheet, 246, 243, 238);
$text = imagecolorallocate($sheet, 28, 24, 20);
$muted = imagecolorallocate($sheet, 120, 104, 88);
$border = imagecolorallocate($sheet, 210, 198, 181);
imagefill($sheet, 0, 0, $bg);

$index = 0;
foreach ($byGroup as $groupId => $artworks) {
    $x = ($index % $cols) * $cellW;
    $y = intdiv($index, $cols) * $cellH;
    imagerectangle($sheet, $x + 6, $y + 6, $x + $cellW - 6, $y + $cellH - 6, $border);
    imagestring($sheet, 3, $x + 12, $y + 12, 'G' . $groupId . ' (' . count($artworks) . ')', $text);
    $previewed = array_slice($artworks, 0, 3);
    foreach ($previewed as $i => $artwork) {
        $file = basename((string)($artwork['root_file'] ?: $artwork['main_file']));
        $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
        $img = is_file($path) ? @imagecreatefromstring((string)file_get_contents($path)) : false;
        if (!$img) {
            continue;
        }
        $thumbW = $i === 0 ? 128 : 56;
        $thumbH = $i === 0 ? 128 : 56;
        $tx = $x + 12 + ($i === 0 ? 0 : 134);
        $ty = $y + 34 + ($i <= 1 ? 0 : 62);
        fitImage($sheet, $img, $tx, $ty, $thumbW, $thumbH);
        imagedestroy($img);
    }
    $first = $artworks[0] ?? null;
    if ($first) {
        imagestring($sheet, 2, $x + 12, $y + 168, '#' . (int)$first['id'] . ' ' . trim((string)$first['width'] . 'x' . (string)$first['height'] . ' ' . (string)$first['unit']), $muted);
        imagestring($sheet, 1, $x + 12, $y + 188, substr(basename((string)($first['root_file'] ?: $first['main_file'])), 0, 32), $muted);
    }
    $index++;
}

imagejpeg($sheet, $out, 90);
imagedestroy($sheet);
echo $out . PHP_EOL;

function fitImage(GdImage $dest, GdImage $src, int $x, int $y, int $w, int $h): void
{
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $scale = min($w / $srcW, $h / $srcH);
    $newW = max(1, (int)round($srcW * $scale));
    $newH = max(1, (int)round($srcH * $scale));
    $dx = $x + intdiv($w - $newW, 2);
    $dy = $y + intdiv($h - $newH, 2);
    imagecopyresampled($dest, $src, $dx, $dy, 0, 0, $newW, $newH, $srcW, $srcH);
}
