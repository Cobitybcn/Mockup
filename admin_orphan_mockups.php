<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
if (!Auth::isAdmin($user)) {
    http_response_code(403);
    exit('Admin only.');
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function orphan_image_url(string $file): string
{
    return 'admin_result_image.php?file=' . urlencode(basename($file));
}

function orphan_image_path(string $file): string
{
    $file = basename(str_replace('\\', '/', $file));
    $candidates = [
        RESULTS_DIR . DIRECTORY_SEPARATOR . $file,
        __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $file,
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return '';
}

function orphan_tokens(string $value): array
{
    $value = strtolower((string)preg_replace('/[^a-z0-9]+/i', ' ', $value));
    $parts = preg_split('/\s+/', trim($value)) ?: [];
    $stop = ['jpg', 'jpeg', 'png', 'webp', 'mockup', 'base', 'artwork', 'uploaded', 'root', 'gemini', 'job', 'v1'];
    $tokens = [];
    foreach ($parts as $part) {
        if (strlen($part) < 4 || in_array($part, $stop, true)) {
            continue;
        }
        $tokens[] = $part;
    }
    return array_values(array_unique($tokens));
}

function orphan_load_gd_image(string $path): mixed
{
    $mime = @mime_content_type($path) ?: '';
    return match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false,
    };
}

function orphan_visual_signature(string $path): ?array
{
    if ($path === '' || !is_file($path) || !extension_loaded('gd')) {
        return null;
    }
    $source = orphan_load_gd_image($path);
    if (!$source) {
        return null;
    }

    $width = imagesx($source);
    $height = imagesy($source);
    if ($width < 8 || $height < 8) {
        imagedestroy($source);
        return null;
    }

    $size = 48;
    $thumb = imagecreatetruecolor($size, $size);
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $size, $size, $width, $height);

    $hist = array_fill(0, 64, 0);
    $centerHist = array_fill(0, 64, 0);
    $avg = [0, 0, 0];
    $total = 0;
    $centerTotal = 0;

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $rgb = imagecolorat($thumb, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $bin = intdiv($r, 64) * 16 + intdiv($g, 64) * 4 + intdiv($b, 64);
            $hist[$bin]++;
            $avg[0] += $r;
            $avg[1] += $g;
            $avg[2] += $b;
            $total++;

            if ($x >= 12 && $x < 36 && $y >= 12 && $y < 36) {
                $centerHist[$bin]++;
                $centerTotal++;
            }
        }
    }

    imagedestroy($thumb);
    imagedestroy($source);

    if ($total <= 0) {
        return null;
    }

    foreach ($hist as $index => $value) {
        $hist[$index] = $value / $total;
    }
    foreach ($centerHist as $index => $value) {
        $centerHist[$index] = $centerTotal > 0 ? $value / $centerTotal : 0;
    }

    return [
        'hist' => $hist,
        'center_hist' => $centerHist,
        'avg' => [$avg[0] / $total, $avg[1] / $total, $avg[2] / $total],
    ];
}

function orphan_region_signature(mixed $image, int $x0, int $y0, int $width, int $height): ?array
{
    if ($width <= 0 || $height <= 0) {
        return null;
    }

    $hist = array_fill(0, 64, 0);
    $avg = [0, 0, 0];
    $total = 0;

    for ($y = $y0; $y < $y0 + $height; $y++) {
        for ($x = $x0; $x < $x0 + $width; $x++) {
            $rgb = imagecolorat($image, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $bin = intdiv($r, 64) * 16 + intdiv($g, 64) * 4 + intdiv($b, 64);
            $hist[$bin]++;
            $avg[0] += $r;
            $avg[1] += $g;
            $avg[2] += $b;
            $total++;
        }
    }

    if ($total <= 0) {
        return null;
    }

    foreach ($hist as $index => $value) {
        $hist[$index] = $value / $total;
    }

    return [
        'hist' => $hist,
        'center_hist' => $hist,
        'avg' => [$avg[0] / $total, $avg[1] / $total, $avg[2] / $total],
    ];
}

function orphan_hist_intersection(array $a, array $b): float
{
    $sum = 0.0;
    $max = min(count($a), count($b));
    for ($i = 0; $i < $max; $i++) {
        $sum += min((float)$a[$i], (float)$b[$i]);
    }
    return max(0.0, min(1.0, $sum));
}

function orphan_signature_similarity(array $candidate, array $target): float
{
    $whole = orphan_hist_intersection((array)$candidate['hist'], (array)$target['hist']);
    $center = orphan_hist_intersection((array)$candidate['center_hist'], (array)$target['center_hist']);
    $avgDistance = sqrt(
        (($candidate['avg'][0] - $target['avg'][0]) ** 2) +
        (($candidate['avg'][1] - $target['avg'][1]) ** 2) +
        (($candidate['avg'][2] - $target['avg'][2]) ** 2)
    );
    $avgScore = max(0.0, 1.0 - ($avgDistance / 441.7));
    return ($whole * 0.45) + ($center * 0.40) + ($avgScore * 0.15);
}

function orphan_visual_similarity(?array $candidate, array $targets): int
{
    if (!$candidate || !$targets) {
        return 0;
    }
    $best = 0.0;
    foreach ($targets as $target) {
        if (!$target) {
            continue;
        }
        $best = max($best, orphan_signature_similarity($candidate, $target));
    }
    return (int)round($best * 100);
}

function orphan_patch_visual_similarity(string $path, array $targets): int
{
    if ($path === '' || !$targets || !extension_loaded('gd')) {
        return 0;
    }
    $source = orphan_load_gd_image($path);
    if (!$source) {
        return 0;
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    if ($sourceWidth < 16 || $sourceHeight < 16) {
        imagedestroy($source);
        return 0;
    }

    $canvasSize = 72;
    $canvas = imagecreatetruecolor($canvasSize, $canvasSize);
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $canvasSize, $canvasSize, $sourceWidth, $sourceHeight);
    imagedestroy($source);

    $best = 0.0;
    $windowSizes = [28, 36, 46, 58, 72];
    foreach ($windowSizes as $windowSize) {
        $step = $windowSize >= 58 ? 7 : 6;
        for ($y = 0; $y <= $canvasSize - $windowSize; $y += $step) {
            for ($x = 0; $x <= $canvasSize - $windowSize; $x += $step) {
                $signature = orphan_region_signature($canvas, $x, $y, $windowSize, $windowSize);
                if (!$signature) {
                    continue;
                }
                foreach ($targets as $target) {
                    if (!$target) {
                        continue;
                    }
                    $best = max($best, orphan_signature_similarity($signature, $target));
                }
            }
        }
    }

    imagedestroy($canvas);
    return (int)round($best * 100);
}

function orphan_candidate_score(string $file, int $mtime, array $targetTokens, array $targetTimes, int $visualScore): array
{
    $name = strtolower(pathinfo($file, PATHINFO_FILENAME));
    $score = 0;
    $reasons = [];

    if ($visualScore >= 78) {
        $score += 70;
        $reasons[] = 'afinidad visual alta';
    } elseif ($visualScore >= 64) {
        $score += 48;
        $reasons[] = 'afinidad visual media';
    } elseif ($visualScore >= 52) {
        $score += 24;
        $reasons[] = 'afinidad visual leve';
    }

    foreach ($targetTokens as $token) {
        if ($token !== '' && str_contains($name, $token)) {
            $score += preg_match('/^\d+$/', $token) ? 35 : 18;
            $reasons[] = 'nombre: ' . $token;
        }
    }

    foreach ($targetTimes as $targetTime) {
        if ($targetTime <= 0 || $mtime <= 0) {
            continue;
        }
        $days = abs($mtime - $targetTime) / 86400;
        if ($days <= 1) {
            $score += 35;
            $reasons[] = 'misma fecha';
            break;
        }
        if ($days <= 7) {
            $score += 18;
            $reasons[] = 'fecha cercana';
            break;
        }
    }

    if (str_contains($name, 'mockup')) {
        $score += 8;
        $reasons[] = 'mockup';
    }
    if (str_contains($name, 'test')) {
        $score -= 8;
    }

    return [$score, array_values(array_unique($reasons))];
}

$pdo = Database::connection();
$service = new ArtworkSheetService($pdo);
$notice = (string)($_SESSION['orphan_mockups_notice'] ?? '');
$error = (string)($_SESSION['orphan_mockups_error'] ?? '');
unset($_SESSION['orphan_mockups_notice'], $_SESSION['orphan_mockups_error']);

$sheetId = max(0, (int)($_GET['sheet_id'] ?? $_POST['sheet_id'] ?? 0));
$query = trim((string)($_GET['q'] ?? ''));
$useVisual = false;
$scope = 'orphans';
$limit = (string)($_GET['limit'] ?? $_POST['limit'] ?? '500');
if (!in_array($limit, ['240', '500', '1000', 'all'], true)) {
    $limit = '500';
}
$page = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['attach', 'attach_probables'], true)) {
        $action = (string)($_POST['action'] ?? '');
        $files = $action === 'attach_probables'
            ? array_map('strval', (array)($_POST['probable_files'] ?? []))
            : array_map('strval', (array)($_POST['mockup_files'] ?? []));
        if ($sheetId <= 0) {
            throw new RuntimeException('Elegí una ficha de obra.');
        }
        if (!$files) {
            throw new RuntimeException($action === 'attach_probables' ? 'No hay probables visibles para anexar.' : 'Seleccioná al menos un mockup candidato.');
        }
        $attached = 0;
        foreach (array_values(array_unique($files)) as $file) {
            $service->attachMockupFile($sheetId, (int)$user['id'], $file, 'Anexado manualmente desde admin.');
            $attached++;
        }
        $_SESSION['orphan_mockups_notice'] = 'Mockups anexados: ' . $attached . '.';
        header('Location: admin_orphan_mockups.php?sheet_id=' . urlencode((string)$sheetId) . '&scope=' . urlencode($scope) . '&limit=' . urlencode($limit) . '&page=' . urlencode((string)$page) . '&q=' . urlencode($query));
        exit;
    }
} catch (Throwable $e) {
    $_SESSION['orphan_mockups_error'] = $e->getMessage();
    header('Location: admin_orphan_mockups.php?sheet_id=' . urlencode((string)$sheetId));
    exit;
}

$stmt = $pdo->prepare('
    SELECT s.id, s.canonical_artwork_id, s.related_artwork_ids, s.title, a.final_title, a.root_file, a.main_file, a.created_at
    FROM artwork_sheets s
    INNER JOIN artworks a ON a.id = s.canonical_artwork_id
    WHERE s.user_id = :user_id
    ORDER BY s.updated_at DESC, s.created_at DESC
');
$stmt->execute(['user_id' => (int)$user['id']]);
$sheets = $stmt->fetchAll();
if ($sheetId <= 0 && $sheets) {
    $sheetId = (int)$sheets[0]['id'];
}
$selectedSheet = null;
foreach ($sheets as $sheet) {
    if ((int)$sheet['id'] === $sheetId) {
        $selectedSheet = $sheet;
        break;
    }
}

$targetTokens = [];
$targetTimes = [];
$targetVisuals = [];
if ($selectedSheet) {
    foreach (['canonical_artwork_id', 'title', 'final_title', 'root_file', 'main_file'] as $key) {
        $targetTokens = array_merge($targetTokens, orphan_tokens((string)($selectedSheet[$key] ?? '')));
    }
    $targetTimes[] = strtotime((string)($selectedSheet['created_at'] ?? '')) ?: 0;
    foreach (['root_file', 'main_file'] as $key) {
        $targetPath = orphan_image_path((string)($selectedSheet[$key] ?? ''));
        $signature = orphan_visual_signature($targetPath);
        if ($signature) {
            $targetVisuals[] = $signature;
        }
    }
    $relatedIds = json_decode((string)($selectedSheet['related_artwork_ids'] ?? ''), true);
    $relatedIds = is_array($relatedIds) ? array_values(array_unique(array_filter(array_map('intval', $relatedIds)))) : [];
    if ($relatedIds) {
        $placeholders = implode(',', array_fill(0, count($relatedIds), '?'));
        $stmt = $pdo->prepare("SELECT id, root_file, main_file, created_at FROM artworks WHERE user_id = ? AND id IN ({$placeholders})");
        $stmt->execute(array_merge([(int)$user['id']], $relatedIds));
        foreach ($stmt->fetchAll() as $relatedArtwork) {
            $targetTokens = array_merge($targetTokens, orphan_tokens((string)$relatedArtwork['id']));
            $targetTokens = array_merge($targetTokens, orphan_tokens((string)($relatedArtwork['root_file'] ?? '')));
            $targetTokens = array_merge($targetTokens, orphan_tokens((string)($relatedArtwork['main_file'] ?? '')));
            $targetTimes[] = strtotime((string)($relatedArtwork['created_at'] ?? '')) ?: 0;
            foreach (['root_file', 'main_file'] as $key) {
                $targetPath = orphan_image_path((string)($relatedArtwork[$key] ?? ''));
                $signature = orphan_visual_signature($targetPath);
                if ($signature) {
                    $targetVisuals[] = $signature;
                }
            }
        }
    }
}
$targetTokens = array_values(array_unique($targetTokens));

$registered = [];
foreach (['mockup_generation_jobs', 'mockups', 'mockup_sheets'] as $table) {
    $stmt = $pdo->prepare("SELECT mockup_file FROM {$table} WHERE user_id = :user_id AND mockup_file IS NOT NULL AND mockup_file <> ''");
    $stmt->execute(['user_id' => (int)$user['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
        $registered[basename((string)$file)][] = $table;
    }
}

$extensions = ['jpg', 'jpeg', 'png', 'webp'];
$candidates = [];
$totalResultFiles = 0;
$hiddenRegisteredFiles = 0;
$hiddenByQueryFiles = 0;
foreach ($extensions as $ext) {
    foreach (glob(RESULTS_DIR . DIRECTORY_SEPARATOR . '*.' . $ext) ?: [] as $path) {
        $totalResultFiles++;
        $file = basename($path);
        $isRegistered = isset($registered[$file]);
        if ($scope === 'orphans' && $isRegistered) {
            $hiddenRegisteredFiles++;
            continue;
        }
        if ($query !== '' && stripos($file, $query) === false) {
            $hiddenByQueryFiles++;
            continue;
        }
        $candidates[] = [
            'file' => $file,
            'mtime' => filemtime($path) ?: 0,
            'size' => filesize($path) ?: 0,
            'registered' => $isRegistered,
            'registered_sources' => $registered[$file] ?? [],
        ];
    }
}
usort($candidates, static fn(array $a, array $b): int => (int)$b['mtime'] <=> (int)$a['mtime']);
$totalCandidates = count($candidates);
$totalPages = 1;
if ($limit !== 'all') {
    $perPage = max(1, (int)$limit);
    $totalPages = max(1, (int)ceil($totalCandidates / $perPage));
    $page = min($page, $totalPages);
    $candidates = array_slice($candidates, ($page - 1) * $perPage, $perPage);
}
$pageStart = $totalCandidates > 0 ? (($limit === 'all' ? 0 : (($page - 1) * (int)$limit)) + 1) : 0;
$pageEnd = $limit === 'all' ? $totalCandidates : min($totalCandidates, ($page - 1) * (int)$limit + count($candidates));

foreach ($candidates as &$candidate) {
    $visualScore = 0;
    $patchScore = 0;
    if ($useVisual) {
        $candidatePath = orphan_image_path((string)$candidate['file']);
        $candidateSignature = orphan_visual_signature($candidatePath);
        $visualScore = orphan_visual_similarity($candidateSignature, $targetVisuals);
        $patchScore = orphan_patch_visual_similarity($candidatePath, $targetVisuals);
        $visualScore = max($visualScore, $patchScore);
    }
    [$score, $reasons] = orphan_candidate_score((string)$candidate['file'], (int)$candidate['mtime'], $targetTokens, $targetTimes, $visualScore);
    $candidate['score'] = $score;
    $candidate['visual_score'] = $visualScore;
    $candidate['patch_score'] = $patchScore;
    $candidate['reasons'] = $reasons;
}
unset($candidate);

$probableCandidates = array_values(array_filter($candidates, static fn(array $candidate): bool => (int)$candidate['score'] >= 35));
$otherCandidates = array_values(array_filter($candidates, static fn(array $candidate): bool => (int)$candidate['score'] < 35));
usort($probableCandidates, static function (array $a, array $b): int {
    return ((int)$b['score'] <=> (int)$a['score']) ?: ((int)$b['mtime'] <=> (int)$a['mtime']);
});
usort($otherCandidates, static fn(array $a, array $b): int => (int)$b['mtime'] <=> (int)$a['mtime']);
$pageProbableCount = count($probableCandidates);
$pageOtherCount = count($otherCandidates);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Anexar mockups - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-panel { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:16px; margin-bottom:16px; }
        .workspace { padding-bottom:86px; }
        .section-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin:18px 0 10px; }
        .section-head h2 { margin:0; }
        .bulk-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .orphan-actions { position:fixed; right:24px; bottom:18px; z-index:40; display:flex; flex-wrap:wrap; gap:6px; align-items:center; justify-content:flex-end; max-width:700px; background:rgba(255,255,255,.94); border:1px solid var(--line); border-radius:999px; box-shadow:0 12px 34px rgba(0,0,0,.14); padding:8px 10px; backdrop-filter:blur(8px); }
        .orphan-actions .button-link,
        .orphan-pager .button-link { display:inline-flex; width:auto; flex:0 0 auto; padding:6px 9px; font-size:9px; min-height:0; line-height:1; border-radius:6px; box-shadow:none; }
        .orphan-pager { display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:flex-end; margin:0 0 12px; }
        .workflow-status { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:8px; margin:0 0 14px; }
        .status-pill { border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); padding:9px 10px; }
        .status-pill strong { display:block; font-size:16px; line-height:1.1; color:var(--ink); }
        .status-pill span { display:block; margin-top:3px; color:var(--muted); font-size:10px; text-transform:uppercase; letter-spacing:.04em; }
        .toolbar { display:grid; grid-template-columns:minmax(260px, 1fr) minmax(180px, 260px) auto; gap:10px; align-items:end; }
        .destination-preview { display:grid; grid-template-columns:96px minmax(0, 1fr); gap:12px; align-items:center; margin-top:14px; padding-top:14px; border-top:1px dashed var(--line); }
        .destination-preview img { width:96px; aspect-ratio:1; object-fit:cover; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface-soft); }
        .field { display:grid; gap:6px; }
        .field label { font-size:10px; text-transform:uppercase; color:var(--muted); font-weight:700; letter-spacing:.05em; }
        input[type="text"], select { width:100%; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface-soft); color:var(--ink); padding:10px; }
        .candidate-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr)); gap:14px; }
        .candidate { position:relative; background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; cursor:pointer; transition:border-color .15s ease, box-shadow .15s ease, transform .15s ease; }
        .candidate:hover { border-color:var(--accent); box-shadow:0 12px 28px rgba(0,0,0,.12); }
        .candidate.is-selected { border-color:var(--accent); box-shadow:0 0 0 2px var(--accent), var(--shadow); }
        .candidate img { width:100%; aspect-ratio:4 / 3; object-fit:cover; display:block; background:var(--surface-soft); border-bottom:1px solid var(--line); }
        .candidate-body { padding:10px; display:grid; gap:6px; }
        .candidate-check { position:absolute; top:8px; left:8px; width:22px; height:22px; display:grid; place-items:center; background:rgba(255,255,255,.42); border:1px solid rgba(255,255,255,.72); border-radius:50%; color:rgba(40,40,40,.7); pointer-events:none; backdrop-filter:blur(2px); }
        .candidate-check input { position:absolute; opacity:0; width:1px; height:1px; pointer-events:none; }
        .candidate-check::after { content:"+"; font-size:16px; line-height:1; transform:rotate(45deg); }
        .candidate.is-selected .candidate-check { background:rgba(163,126,85,.86); border-color:rgba(255,255,255,.85); color:white; }
        .candidate.is-selected .candidate-check::after { content:"✓"; font-size:14px; transform:none; }
        .score-pill { position:absolute; top:8px; right:8px; background:rgba(255,255,255,.92); border:1px solid var(--line); border-radius:999px; padding:5px 8px; font-size:11px; color:var(--ink); }
        .meta { color:var(--muted); font-size:12px; line-height:1.4; overflow-wrap:anywhere; }
        @media (max-width:900px) { .toolbar { grid-template-columns:1fr; } }
        @media (max-width:760px) { .orphan-actions { left:10px; right:10px; bottom:10px; border-radius:var(--radius); justify-content:center; } }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <div class="alert-strip">Recurso provisorio admin: anexar mockups huérfanos a una ficha de obra.</div>
        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Anexar mockups</h1>
                    <p>Escanea <code>results/</code> y muestra imágenes que no están registradas como mockups.</p>
                </div>
                <div class="orphan-actions" aria-label="Acciones de anexado">
                    <span class="meta" data-selection-count>0 seleccionados</span>
                    <button class="button-link" type="submit" form="orphan-attach-form" name="action" value="attach">Anexar</button>
                    <button class="button-link secondary" type="submit" form="orphan-attach-form" name="action" value="attach_probables">Anexar probables</button>
                    <button class="button-link secondary" type="button" data-check=".probable-check">Sel. probables</button>
                    <button class="button-link secondary" type="button" data-uncheck="1">Limpiar</button>
                    <?php if ($selectedSheet): ?>
                        <a class="button-link secondary" href="artwork_sheet.php?id=<?= h($selectedSheet['canonical_artwork_id']) ?>">Abrir ficha</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>

            <div class="workflow-status">
                <div class="status-pill"><strong><?= h($totalResultFiles) ?></strong><span>archivos en results</span></div>
                <div class="status-pill"><strong><?= h($hiddenRegisteredFiles) ?></strong><span>ya registrados ocultos</span></div>
                <div class="status-pill"><strong><?= h($hiddenByQueryFiles) ?></strong><span>fuera del filtro</span></div>
                <div class="status-pill"><strong><?= h($totalCandidates) ?></strong><span>disponibles para anexar</span></div>
                <div class="status-pill"><strong><?= h($pageStart) ?>-<?= h($pageEnd) ?></strong><span>rango de esta página</span></div>
            </div>

            <section class="admin-panel">
                <form method="get" class="toolbar">
                    <div class="field">
                        <label>Ficha destino</label>
                        <select name="sheet_id">
                            <?php foreach ($sheets as $sheet): ?>
                                <?php $label = trim((string)($sheet['title'] ?: $sheet['final_title'] ?: 'Obra sin título')); ?>
                                <option value="<?= h($sheet['id']) ?>" <?= (int)$sheet['id'] === $sheetId ? 'selected' : '' ?>>
                                    #<?= h($sheet['canonical_artwork_id']) ?> · <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Filtro por nombre</label>
                        <input type="text" name="q" value="<?= h($query) ?>" placeholder="blue, mockup, root...">
                    </div>
                    <div class="field">
                        <label>Cantidad</label>
                        <select name="limit">
                            <option value="500" <?= $limit === '500' ? 'selected' : '' ?>>500 por página</option>
                            <option value="1000" <?= $limit === '1000' ? 'selected' : '' ?>>1000 por página</option>
                            <option value="240" <?= $limit === '240' ? 'selected' : '' ?>>240 por página</option>
                            <option value="all" <?= $limit === 'all' ? 'selected' : '' ?>>Todo en una página</option>
                        </select>
                    </div>
                    <input type="hidden" name="page" value="1">
                    <input type="hidden" name="visual" value="<?= $useVisual ? '1' : '0' ?>">
                    <button class="button-link secondary" type="submit">Buscar</button>
                </form>
                <?php if ($selectedSheet): ?>
                    <?php
                    $sheetTitle = trim((string)($selectedSheet['title'] ?: $selectedSheet['final_title'] ?: 'Obra sin título'));
                    $sheetImage = basename((string)($selectedSheet['root_file'] ?? ''));
                    ?>
                    <div class="destination-preview">
                        <?php if ($sheetImage !== ''): ?>
                            <img src="<?= h(orphan_image_url($sheetImage)) ?>" alt="<?= h($sheetTitle) ?>">
                        <?php endif; ?>
                        <div>
                            <strong><?= h($sheetTitle) ?></strong>
                            <div class="meta">Ficha madre: obra #<?= h($selectedSheet['canonical_artwork_id']) ?> · <?= h($sheetImage) ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <form id="orphan-attach-form" method="post">
                <input type="hidden" name="sheet_id" value="<?= h($sheetId) ?>">
                <input type="hidden" name="scope" value="<?= h($scope) ?>">
                <input type="hidden" name="limit" value="<?= h($limit) ?>">
                <input type="hidden" name="page" value="<?= h($page) ?>">
                <?php foreach ($probableCandidates as $candidate): ?>
                    <input type="hidden" name="probable_files[]" value="<?= h($candidate['file']) ?>">
                <?php endforeach; ?>
                <p class="meta">Página actual: <?= h(count($candidates)) ?> cargados · <?= h($pageProbableCount) ?> probables · <?= h($pageOtherCount) ?> otros · origen: carpeta <code>results/</code> con registrados ocultos.</p>

                <?php if ($totalPages > 1): ?>
                    <div class="orphan-pager">
                        <?php
                        $prevUrl = 'admin_orphan_mockups.php?' . http_build_query([
                            'sheet_id' => $sheetId,
                            'q' => $query,
                            'limit' => $limit,
                            'page' => max(1, $page - 1),
                        ]);
                        $nextUrl = 'admin_orphan_mockups.php?' . http_build_query([
                            'sheet_id' => $sheetId,
                            'q' => $query,
                            'limit' => $limit,
                            'page' => min($totalPages, $page + 1),
                        ]);
                        ?>
                        <a class="button-link secondary" href="<?= h($prevUrl) ?>">Anterior</a>
                        <span class="meta">Página <?= h($page) ?> de <?= h($totalPages) ?></span>
                        <a class="button-link secondary" href="<?= h($nextUrl) ?>">Siguiente</a>
                    </div>
                <?php endif; ?>

                <div class="section-head">
                    <div>
                        <h2>Probables para esta ficha</h2>
                        <p class="meta"><?= h($pageProbableCount) ?> candidatos en esta página. <?= $useVisual ? 'Ordenados por afinidad visual interna, nombre, IDs relacionados y fecha cercana.' : 'Ordenados por nombre, IDs relacionados y fecha cercana. Activá afinidad visual solo si hace falta.' ?></p>
                    </div>
                </div>
                <div class="candidate-grid">
                    <?php foreach ($probableCandidates as $candidate): ?>
                        <article class="candidate">
                            <label class="candidate-check">
                                <input class="probable-check" type="checkbox" name="mockup_files[]" value="<?= h($candidate['file']) ?>">
                            </label>
                            <span class="score-pill"><?= $useVisual ? h($candidate['visual_score']) . '%' : h($candidate['score']) ?></span>
                            <img src="<?= h(orphan_image_url($candidate['file'])) ?>" alt="<?= h($candidate['file']) ?>" loading="lazy" decoding="async">
                            <div class="candidate-body">
                                <div class="meta"><code><?= h($candidate['file']) ?></code></div>
                                <?php if (!empty($candidate['registered'])): ?><div class="meta">Ya registrado: <?= h(implode(', ', (array)$candidate['registered_sources'])) ?></div><?php endif; ?>
                                <div class="meta"><?= $useVisual ? 'Visual interno ' . h($candidate['visual_score']) . '% · ' : '' ?><?= h(implode(', ', (array)$candidate['reasons'])) ?></div>
                                <div class="meta"><?= h(date('Y-m-d H:i', (int)$candidate['mtime'])) ?> · <?= h((string)round(((int)$candidate['size']) / 1024)) ?> KB</div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="section-head">
                    <div>
                        <h2>Otros huérfanos</h2>
                        <p class="meta"><?= h($pageOtherCount) ?> candidatos en esta página. Respaldo manual: archivos no registrados sin coincidencia fuerte con la ficha actual.</p>
                    </div>
                </div>
                <div class="candidate-grid">
                    <?php foreach ($otherCandidates as $candidate): ?>
                        <article class="candidate">
                            <label class="candidate-check">
                                <input type="checkbox" name="mockup_files[]" value="<?= h($candidate['file']) ?>">
                            </label>
                            <span class="score-pill"><?= $useVisual ? h($candidate['visual_score']) . '%' : h($candidate['score']) ?></span>
                            <img src="<?= h(orphan_image_url($candidate['file'])) ?>" alt="<?= h($candidate['file']) ?>" loading="lazy" decoding="async">
                            <div class="candidate-body">
                                <div class="meta"><code><?= h($candidate['file']) ?></code></div>
                                <?php if (!empty($candidate['registered'])): ?><div class="meta">Ya registrado: <?= h(implode(', ', (array)$candidate['registered_sources'])) ?></div><?php endif; ?>
                                <div class="meta"><?= $useVisual ? 'Visual interno ' . h($candidate['visual_score']) . '% · ' : '' ?><?= h(implode(', ', (array)$candidate['reasons'])) ?></div>
                                <div class="meta"><?= h(date('Y-m-d H:i', (int)$candidate['mtime'])) ?> · <?= h((string)round(((int)$candidate['size']) / 1024)) ?> KB</div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </form>
        </div>
    </main>
</div>
<script>
function updateMockupSelectionCount() {
    var count = document.querySelectorAll('input[name="mockup_files[]"]:checked').length;
    document.querySelectorAll('[data-selection-count]').forEach(function (node) {
        node.textContent = count + (count === 1 ? ' seleccionado' : ' seleccionados');
    });
}
document.querySelectorAll('[data-check]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.querySelectorAll(button.getAttribute('data-check')).forEach(function (box) {
            box.checked = true;
            var card = box.closest('.candidate');
            if (card) {
                card.classList.add('is-selected');
            }
        });
        updateMockupSelectionCount();
    });
});
document.querySelectorAll('[data-uncheck]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.querySelectorAll('input[name="mockup_files[]"]').forEach(function (box) {
            box.checked = false;
            var card = box.closest('.candidate');
            if (card) {
                card.classList.remove('is-selected');
            }
        });
        updateMockupSelectionCount();
    });
});
document.querySelectorAll('.candidate').forEach(function (card) {
    var box = card.querySelector('input[name="mockup_files[]"]');
    if (!box) {
        return;
    }
    if (box.checked) {
        card.classList.add('is-selected');
    }
    card.addEventListener('click', function () {
        box.checked = !box.checked;
        card.classList.toggle('is-selected', box.checked);
        updateMockupSelectionCount();
    });
});

var paintSelecting = false;
var paintValue = true;
document.querySelectorAll('.candidate').forEach(function (card) {
    var box = card.querySelector('input[name="mockup_files[]"]');
    if (!box) {
        return;
    }
    card.addEventListener('mousedown', function (event) {
        if (event.button !== 0) {
            return;
        }
        paintSelecting = true;
        paintValue = !box.checked;
        box.checked = paintValue;
        card.classList.toggle('is-selected', paintValue);
        updateMockupSelectionCount();
        event.preventDefault();
    });
    card.addEventListener('mouseenter', function () {
        if (!paintSelecting) {
            return;
        }
        box.checked = paintValue;
        card.classList.toggle('is-selected', paintValue);
        updateMockupSelectionCount();
    });
});
document.addEventListener('mouseup', function () {
    paintSelecting = false;
});
updateMockupSelectionCount();
</script>
</body>
</html>
