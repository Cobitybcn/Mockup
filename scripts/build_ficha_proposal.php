<?php
declare(strict_types=1);

/**
 * Construye la propuesta de fichas (storage/ficha_proposal.json) para el asistente
 * fichas_reconcile.php.
 *
 * Pipeline:
 *   1. Grupos base: agrupaciones confirmadas (o pistas de los gestores de prueba) + duplicados
 *      exactos por sha1.
 *   2. Pares de grupos candidatos a ser la misma pintura: similitud de centroides de
 *      embeddings >= CANDIDATE_THRESHOLD.
 *   3. Veredicto de Gemini vision por par (SAME/DIFFERENT), en orden descendente de similitud
 *      con poda transitiva (si dos grupos ya quedaron unidos, no se consulta).
 *
 * Uso: php scripts/build_ficha_proposal.php [user_id]
 */

require_once __DIR__ . '/../app/bootstrap.php';

const CANDIDATE_THRESHOLD = 0.80;
const MAX_GEMINI_CHECKS = 400;

$userId = max(1, (int)($argv[1] ?? 1));
$pdo = Database::connection();
$embeddingService = new ArtworkEmbeddingService($pdo);
$client = new GeminiImageClient();

$stmt = $pdo->prepare('SELECT id, root_file, main_file FROM artworks WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
$artworksById = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $artworksById[(int)$row['id']] = $row;
}
$ids = array_keys($artworksById);
$index = array_flip($ids);
$parent = range(0, count($ids) - 1);
$find = function (int $i) use (&$parent, &$find): int {
    return $parent[$i] === $i ? $i : $parent[$i] = $find($parent[$i]);
};
$union = function (int $a, int $b) use (&$parent, $find): void {
    $parent[$find($a)] = $find($b);
};

// 1a) agrupaciones confirmadas o pistas
$confirmedPath = __DIR__ . '/../storage/ficha_confirmed_groups.json';
$hintsPath = __DIR__ . '/../storage/artwork_sheets_hints_20260703.json';
$confirmed = json_decode((string)@file_get_contents($confirmedPath), true);
if (is_array($confirmed) && (int)($confirmed['user_id'] ?? 0) === $userId) {
    $seedGroups = array_map(fn($g) => (array)($g['artwork_ids'] ?? []), (array)$confirmed['groups']);
    echo "semilla: agrupaciones confirmadas (" . count($seedGroups) . ")\n";
} else {
    $hints = json_decode((string)@file_get_contents($hintsPath), true);
    $seedGroups = [];
    foreach ((array)($hints['sheets'] ?? []) as $sheet) {
        if ((int)($sheet['user_id'] ?? 0) === $userId) {
            $seedGroups[] = (array)($sheet['artwork_ids'] ?? []);
        }
    }
    echo "semilla: pistas de gestores de prueba (" . count($seedGroups) . ")\n";
}
foreach ($seedGroups as $members) {
    $members = array_values(array_filter(array_map('intval', $members), fn($id) => isset($index[$id])));
    for ($i = 1; $i < count($members); $i++) {
        $union($index[$members[0]], $index[$members[$i]]);
    }
}

// 1b) duplicados exactos
$byHash = [];
foreach ($artworksById as $id => $artwork) {
    $path = $embeddingService->resolveArtworkImagePath($artwork);
    if ($path !== null) {
        $byHash[hash_file('sha1', $path)][] = (int)$id;
    }
}
foreach ($byHash as $members) {
    for ($i = 1; $i < count($members); $i++) {
        $union($index[$members[0]], $index[$members[$i]]);
    }
}

$clusters = [];
foreach ($ids as $id) {
    $clusters[$find($index[$id])][] = $id;
}
$clusters = array_values($clusters);
echo "grupos base: " . count($clusters) . "\n";

// 2) candidatos por centroide de embeddings
$vectors = $embeddingService->loadVectors($userId);
foreach ($vectors as &$vector) {
    $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $vector)));
    if ($norm > 0) {
        foreach ($vector as $k => $x) {
            $vector[$k] = $x / $norm;
        }
    }
}
unset($vector);

$centroids = [];
foreach ($clusters as $ci => $members) {
    $sum = null;
    foreach ($members as $id) {
        if (!isset($vectors[$id])) {
            continue;
        }
        if ($sum === null) {
            $sum = $vectors[$id];
            continue;
        }
        foreach ($vectors[$id] as $k => $x) {
            $sum[$k] += $x;
        }
    }
    if ($sum !== null) {
        $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $sum)));
        if ($norm > 0) {
            foreach ($sum as $k => $x) {
                $sum[$k] = $x / $norm;
            }
            $centroids[$ci] = $sum;
        }
    }
}

$candidates = [];
$clusterCount = count($clusters);
for ($i = 0; $i < $clusterCount; $i++) {
    if (!isset($centroids[$i])) {
        continue;
    }
    for ($j = $i + 1; $j < $clusterCount; $j++) {
        if (!isset($centroids[$j])) {
            continue;
        }
        $sim = 0.0;
        foreach ($centroids[$i] as $k => $x) {
            $sim += $x * $centroids[$j][$k];
        }
        if ($sim >= CANDIDATE_THRESHOLD) {
            $candidates[] = [$i, $j, $sim];
        }
    }
}
usort($candidates, fn($a, $b) => $b[2] <=> $a[2]);
echo "pares candidatos (centroide >= " . CANDIDATE_THRESHOLD . "): " . count($candidates) . "\n";

// linaje para elegir representante (obra con más mockups)
$mockupCounts = [];
$stmt = $pdo->prepare("SELECT artwork_file, COUNT(*) c FROM mockups WHERE user_id = ? GROUP BY artwork_file");
$stmt->execute([$userId]);
$fileToArtwork = [];
foreach ($artworksById as $id => $artwork) {
    foreach (['root_file', 'main_file'] as $key) {
        $file = basename(str_replace('\\', '/', (string)($artwork[$key] ?? '')));
        if ($file !== '' && !isset($fileToArtwork[$file])) {
            $fileToArtwork[$file] = (int)$id;
        }
    }
}
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $file = basename(str_replace('\\', '/', (string)$row['artwork_file']));
    if (isset($fileToArtwork[$file])) {
        $mockupCounts[$fileToArtwork[$file]] = ($mockupCounts[$fileToArtwork[$file]] ?? 0) + (int)$row['c'];
    }
}

$thumbDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ficha_thumbs';
if (!is_dir($thumbDir)) {
    mkdir($thumbDir, 0775, true);
}
$thumbnail = function (string $path) use ($thumbDir): string {
    $thumbPath = $thumbDir . DIRECTORY_SEPARATOR . md5($path) . '.jpg';
    if (is_file($thumbPath)) {
        return $thumbPath;
    }
    $image = @imagecreatefromstring((string)@file_get_contents($path));
    if (!$image) {
        return $path;
    }
    $w = imagesx($image);
    $h = imagesy($image);
    $scale = min(1.0, 512 / max($w, $h));
    $tw = max(1, (int)round($w * $scale));
    $th = max(1, (int)round($h * $scale));
    $thumb = imagecreatetruecolor($tw, $th);
    imagecopyresampled($thumb, $image, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagejpeg($thumb, $thumbPath, 88);
    imagedestroy($image);
    imagedestroy($thumb);
    return $thumbPath;
};

$representative = function (array $members) use ($mockupCounts, $embeddingService, $artworksById, $thumbnail): ?string {
    $best = null;
    $bestCount = -1;
    foreach ($members as $id) {
        $count = (int)($mockupCounts[$id] ?? 0);
        if ($count > $bestCount) {
            $path = $embeddingService->resolveArtworkImagePath($artworksById[$id]);
            if ($path !== null) {
                $best = $path;
                $bestCount = $count;
            }
        }
    }
    if ($best === null) {
        foreach ($members as $id) {
            $path = $embeddingService->resolveArtworkImagePath($artworksById[$id]);
            if ($path !== null) {
                $best = $path;
                break;
            }
        }
    }
    return $best === null ? null : $thumbnail($best);
};

// 3) veredicto Gemini con poda transitiva (union-find a nivel de clusters)
$clusterParent = range(0, $clusterCount - 1);
$findCluster = function (int $i) use (&$clusterParent, &$findCluster): int {
    return $clusterParent[$i] === $i ? $i : $clusterParent[$i] = $findCluster($clusterParent[$i]);
};

$prompt = "You see two photos of paintings. Ignore framing, wall, lighting and crop differences. "
    . "Decide if both photos show THE SAME painting (same composition and same painted elements) "
    . "or two DIFFERENT paintings (even if same artist/style). "
    . "Answer with exactly one word: SAME or DIFFERENT.";

$python = $client->getPythonExecutable();
$bridge = __DIR__ . '/../app/Services/vertex_bridge.py';
$parallelism = 8;

$checked = 0;
$merged = 0;
$skipped = 0;
$errors = 0;
$queue = $candidates;
while ($queue && $checked < MAX_GEMINI_CHECKS) {
    // Armar el próximo lote saltando pares ya unidos por transitividad.
    $batch = [];
    while ($queue && count($batch) < $parallelism) {
        [$i, $j, $sim] = array_shift($queue);
        if ($findCluster($i) === $findCluster($j)) {
            $skipped++;
            continue;
        }
        $pathA = $representative($clusters[$i]);
        $pathB = $representative($clusters[$j]);
        if ($pathA === null || $pathB === null) {
            continue;
        }
        $batch[] = ['i' => $i, 'j' => $j, 'sim' => $sim, 'a' => $pathA, 'b' => $pathB];
    }
    if (!$batch) {
        continue;
    }

    $cmds = [];
    $prompts = [];
    foreach ($batch as $item) {
        $cmds[] = '"' . $python . '" ' . escapeshellarg($bridge)
            . ' generate-text --model gemini-2.5-flash'
            . ' --image ' . escapeshellarg($item['a'])
            . ' --image ' . escapeshellarg($item['b']);
        $prompts[] = $prompt;
    }

    try {
        $results = $client->runCommandsParallel($cmds, $prompts, 240);
    } catch (Throwable $e) {
        $errors += count($batch);
        fwrite(STDERR, 'lote fallido: ' . substr($e->getMessage(), 0, 160) . "\n");
        continue;
    }

    foreach ($batch as $index => $item) {
        $result = $results[$index] ?? null;
        if (!$result || (int)$result['exit_code'] !== 0) {
            $errors++;
            fwrite(STDERR, "error par ({$item['i']},{$item['j']}): " . substr((string)($result['stderr'] ?? 'sin resultado'), 0, 120) . "\n");
            continue;
        }
        $checked++;
        $answer = strtoupper(trim((string)$result['stdout']));
        if (str_contains($answer, 'SAME')) {
            $clusterParent[$findCluster($item['i'])] = $findCluster($item['j']);
            $merged++;
        }
        printf("[%d] sim=%.3f -> %s (fusiones: %d, podados: %d, cola: %d)\n", $checked, $item['sim'], str_contains($answer, 'SAME') ? 'SAME' : 'DIFFERENT', $merged, $skipped, count($queue));
    }
}

// grupos finales
$finalClusters = [];
foreach ($clusters as $ci => $members) {
    $finalClusters[$findCluster($ci)] = array_merge($finalClusters[$findCluster($ci)] ?? [], $members);
}
$finalClusters = array_values($finalClusters);
usort($finalClusters, fn($a, $b) => count($b) - count($a));

$groups = [];
foreach ($finalClusters as $n => $members) {
    sort($members);
    $canonical = $members[0];
    $bestCount = -1;
    foreach ($members as $id) {
        $count = (int)($mockupCounts[$id] ?? 0);
        if ($count > $bestCount) {
            $bestCount = $count;
            $canonical = $id;
        }
    }
    $groups[] = ['key' => 'g' . ($n + 1), 'canonical' => $canonical, 'artwork_ids' => $members];
}

$proposal = [
    'generated_at' => date('c'),
    'user_id' => $userId,
    'threshold' => CANDIDATE_THRESHOLD,
    'method' => 'gemini_pairwise',
    'gemini_checks' => $checked,
    'groups' => $groups,
];
file_put_contents(__DIR__ . '/../storage/ficha_proposal.json', json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$sizes = array_map(fn($g) => count($g['artwork_ids']), $groups);
echo "\nRESULTADO: " . count($groups) . " fichas propuestas | consultas Gemini: $checked | fusiones: $merged | errores: $errors\n";
echo "tamanos: " . implode(',', array_slice($sizes, 0, 40)) . "\n";
echo "guardado en storage/ficha_proposal.json\n";
