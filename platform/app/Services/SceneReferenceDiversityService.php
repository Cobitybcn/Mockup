<?php
declare(strict_types=1);

final class SceneReferenceDiversityService
{
    // Calibrated to reject alternate views of the same room while still
    // allowing different compositions that share the scene family's palette.
    private const AUTO_SIMILARITY_THRESHOLD = 0.72;
    private const MAX_GROUP_LENGTH = 80;

    /** @var array<string,array<string,mixed>|null> */
    private array $profileCache = [];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    /**
     * Builds deterministic, card-specific pair options while coordinating the
     * first option across the complete camera board.
     *
     * @param array<int,array<string,mixed>> $pool
     * @return array{pool:array<int,array<string,mixed>>,pair_options:array<int,array<int,array<int,array<string,mixed>>>>}
     */
    public function buildPlan(array $pool, string $category, int $artworkId, int $combinationCount): array
    {
        $pool = array_values(array_map(fn (array $image): array => $this->enrichImage($image), $pool));
        $pool = $this->stablePool($pool, $category, $artworkId);
        $combinationCount = max(1, $combinationCount);

        if (!$pool) {
            return ['pool' => [], 'pair_options' => []];
        }
        if (count($pool) === 1) {
            $options = [];
            for ($index = 1; $index <= $combinationCount; $index++) {
                $options[$index] = [[$pool[0]]];
            }
            return ['pool' => $pool, 'pair_options' => $options];
        }

        $validPairs = [];
        $allPairs = [];
        $poolCount = count($pool);
        for ($left = 0; $left < $poolCount - 1; $left++) {
            for ($right = $left + 1; $right < $poolCount; $right++) {
                $similarity = $this->similarity($pool[$left], $pool[$right]);
                $candidate = [
                    'references' => [$pool[$left], $pool[$right]],
                    'key' => $this->pairKey([$pool[$left], $pool[$right]]),
                    'similarity' => $similarity['score'],
                    'too_similar' => $similarity['too_similar'],
                ];
                $allPairs[] = $candidate;
                if (!$candidate['too_similar']) {
                    $validPairs[] = $candidate;
                }
            }
        }

        // If the folder has no genuinely distinct pair, sending one useful
        // reference is safer than forcing two redundant images into Gemini.
        $catalog = $validPairs;
        if (!$catalog) {
            foreach ($pool as $image) {
                $catalog[] = [
                    'references' => [$image],
                    'key' => $this->pairKey([$image]),
                    'similarity' => 0.0,
                    'too_similar' => false,
                ];
            }
        }

        $baseSelections = [];
        $referenceUsage = [];
        $pairUsage = [];
        for ($combinationIndex = 1; $combinationIndex <= $combinationCount; $combinationIndex++) {
            $ranked = $catalog;
            usort($ranked, function (array $a, array $b) use ($referenceUsage, $pairUsage, $category, $artworkId, $combinationIndex): int {
                $aScore = $this->allocationScore($a, $referenceUsage, $pairUsage, $category, $artworkId, $combinationIndex);
                $bScore = $this->allocationScore($b, $referenceUsage, $pairUsage, $category, $artworkId, $combinationIndex);
                return $aScore <=> $bScore;
            });
            $selected = $ranked[0];
            $baseSelections[$combinationIndex] = $selected;
            $pairUsage[$selected['key']] = ($pairUsage[$selected['key']] ?? 0) + 1;
            foreach ($selected['references'] as $reference) {
                $path = (string)($reference['relative_path'] ?? '');
                $referenceUsage[$path] = ($referenceUsage[$path] ?? 0) + 1;
            }
        }

        $pairOptions = [];
        foreach ($baseSelections as $combinationIndex => $base) {
            $remaining = array_values(array_filter(
                $catalog,
                static fn (array $candidate): bool => $candidate['key'] !== $base['key']
            ));
            usort($remaining, function (array $a, array $b) use ($category, $artworkId, $combinationIndex): int {
                $similarityOrder = ((float)$a['similarity']) <=> ((float)$b['similarity']);
                if ($similarityOrder !== 0) {
                    return $similarityOrder;
                }
                return $this->stableCandidateKey($a, $category, $artworkId, $combinationIndex)
                    <=> $this->stableCandidateKey($b, $category, $artworkId, $combinationIndex);
            });
            $ordered = array_merge([$base], $remaining);
            $pairOptions[$combinationIndex] = array_map(
                static fn (array $candidate): array => array_values($candidate['references']),
                $ordered
            );
        }

        return ['pool' => $pool, 'pair_options' => $pairOptions];
    }

    /** @param array<int,array<string,mixed>> $images @return array<string,string> */
    public function manualGroupsForImages(array $images): array
    {
        $groups = [];
        foreach ($images as $image) {
            $key = $this->referenceKey($image);
            if ($key === '') {
                continue;
            }
            $profile = $this->profileByKey($key);
            $groups[$key] = trim((string)($profile['similarity_group'] ?? ''));
        }
        return $groups;
    }

    /**
     * @param array<int,array<string,mixed>> $images
     * @param array<string,string> $groupsByReferenceKey
     */
    public function updateSimilarityGroups(array $images, array $groupsByReferenceKey): int
    {
        if (!$this->pdo) {
            throw new RuntimeException('Scene diversity profiles require a database connection.');
        }

        $updated = 0;
        foreach ($images as $image) {
            $key = $this->referenceKey($image);
            if ($key === '' || !array_key_exists($key, $groupsByReferenceKey)) {
                continue;
            }
            $group = $this->normalizeGroup((string)$groupsByReferenceKey[$key]);
            $enriched = $this->enrichImage($image);
            $profile = $this->profileByKey($key) ?? [];
            $this->saveProfile($key, [
                'content_hash' => (string)($enriched['scene_content_hash'] ?? $profile['content_hash'] ?? ''),
                'file_size' => (int)($profile['file_size'] ?? 0),
                'file_mtime' => (int)($profile['file_mtime'] ?? 0),
                'descriptor_json' => (string)($profile['descriptor_json'] ?? ''),
                'similarity_group' => $group,
                'analyzed_at' => (string)($profile['analyzed_at'] ?? ''),
            ]);
            $updated++;
        }
        return $updated;
    }

    public function renameCategory(string $sourceSlug, string $targetSlug): void
    {
        if (!$this->pdo || $sourceSlug === '' || $targetSlug === '' || $sourceSlug === $targetSlug) {
            return;
        }
        $sourcePrefix = $sourceSlug . '/';
        $rows = $this->profilesWithPrefix($sourcePrefix);
        foreach ($rows as $row) {
            $oldKey = (string)$row['reference_key'];
            $newKey = $targetSlug . '/' . substr($oldKey, strlen($sourcePrefix));
            $this->saveProfile($newKey, $row);
            $stmt = $this->pdo->prepare('DELETE FROM scene_reference_profiles WHERE reference_key=:reference_key');
            $stmt->execute(['reference_key' => $oldKey]);
            unset($this->profileCache[$oldKey]);
        }
    }

    public function deleteCategory(string $slug): void
    {
        if (!$this->pdo || $slug === '') {
            return;
        }
        $stmt = $this->pdo->prepare('DELETE FROM scene_reference_profiles WHERE reference_key LIKE :prefix');
        $stmt->execute(['prefix' => $slug . '/%']);
        $this->profileCache = [];
    }

    /** @param array<string,mixed> $candidate @param array<string,int> $referenceUsage @param array<string,int> $pairUsage @return array<int,int|float> */
    private function allocationScore(array $candidate, array $referenceUsage, array $pairUsage, string $category, int $artworkId, int $combinationIndex): array
    {
        $overlapCount = 0;
        $totalUsage = 0;
        foreach ($candidate['references'] as $reference) {
            $usage = (int)($referenceUsage[(string)($reference['relative_path'] ?? '')] ?? 0);
            $totalUsage += $usage;
            if ($usage > 0) {
                $overlapCount++;
            }
        }
        return [
            (int)(($pairUsage[$candidate['key']] ?? 0) > 0),
            $overlapCount,
            $totalUsage,
            (float)$candidate['similarity'],
            $this->stableCandidateKey($candidate, $category, $artworkId, $combinationIndex),
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function stableCandidateKey(array $candidate, string $category, int $artworkId, int $combinationIndex): int
    {
        return (int)sprintf('%u', crc32($category . '|' . $artworkId . '|' . $combinationIndex . '|' . (string)$candidate['key']));
    }

    /** @param array<int,array<string,mixed>> $pool @return array<int,array<string,mixed>> */
    private function stablePool(array $pool, string $category, int $artworkId): array
    {
        usort($pool, static function (array $a, array $b) use ($category, $artworkId): int {
            $aPath = (string)($a['relative_path'] ?? '');
            $bPath = (string)($b['relative_path'] ?? '');
            $aKey = sprintf('%u', crc32($category . '|' . $artworkId . '|' . $aPath));
            $bKey = sprintf('%u', crc32($category . '|' . $artworkId . '|' . $bPath));
            return ($aKey <=> $bKey) ?: strcmp($aPath, $bPath);
        });
        foreach ($pool as $index => &$image) {
            $image['world_mother_random_rotation_index'] = $index + 1;
            $image['world_mother_random_rotation_count'] = count($pool);
        }
        unset($image);
        return $pool;
    }

    /** @param array<int,array<string,mixed>> $references */
    private function pairKey(array $references): string
    {
        $paths = array_map(static fn (array $image): string => (string)($image['relative_path'] ?? ''), $references);
        sort($paths, SORT_STRING);
        return implode('|', $paths);
    }

    /** @return array{score:float,too_similar:bool} */
    private function similarity(array $left, array $right): array
    {
        $leftGroup = trim((string)($left['scene_similarity_group'] ?? ''));
        $rightGroup = trim((string)($right['scene_similarity_group'] ?? ''));
        if ($leftGroup !== '' && $rightGroup !== '') {
            if (strcasecmp($leftGroup, $rightGroup) === 0) {
                return ['score' => 1.0, 'too_similar' => true];
            }
            return ['score' => 0.0, 'too_similar' => false];
        }

        $leftHash = (string)($left['scene_content_hash'] ?? '');
        $rightHash = (string)($right['scene_content_hash'] ?? '');
        if ($leftHash !== '' && $leftHash === $rightHash) {
            return ['score' => 1.0, 'too_similar' => true];
        }

        $leftDescriptor = (array)($left['scene_visual_descriptor'] ?? []);
        $rightDescriptor = (array)($right['scene_visual_descriptor'] ?? []);
        if (!$leftDescriptor || !$rightDescriptor) {
            return ['score' => 0.0, 'too_similar' => false];
        }

        $hashSimilarity = $this->hashSimilarity((string)($leftDescriptor['dhash'] ?? ''), (string)($rightDescriptor['dhash'] ?? ''));
        $luminanceSimilarity = $this->vectorSimilarity((array)($leftDescriptor['luminance'] ?? []), (array)($rightDescriptor['luminance'] ?? []), 255.0);
        $colorSimilarity = $this->vectorSimilarity((array)($leftDescriptor['color'] ?? []), (array)($rightDescriptor['color'] ?? []), 255.0);
        $score = max(0.0, min(1.0, $hashSimilarity * 0.50 + $luminanceSimilarity * 0.30 + $colorSimilarity * 0.20));
        return ['score' => $score, 'too_similar' => $score >= self::AUTO_SIMILARITY_THRESHOLD];
    }

    private function hashSimilarity(string $left, string $right): float
    {
        if (strlen($left) !== 16 || strlen($right) !== 16) {
            return 0.0;
        }
        static $bitCounts = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];
        $distance = 0;
        for ($i = 0; $i < 16; $i++) {
            $distance += $bitCounts[hexdec($left[$i]) ^ hexdec($right[$i])];
        }
        return 1.0 - ($distance / 64.0);
    }

    /** @param array<int,mixed> $left @param array<int,mixed> $right */
    private function vectorSimilarity(array $left, array $right, float $range): float
    {
        if (!$left || count($left) !== count($right)) {
            return 0.0;
        }
        $difference = 0.0;
        foreach ($left as $index => $value) {
            $difference += abs((float)$value - (float)$right[$index]);
        }
        return max(0.0, 1.0 - ($difference / (count($left) * $range)));
    }

    /** @param array<string,mixed> $image @return array<string,mixed> */
    private function enrichImage(array $image): array
    {
        $key = $this->referenceKey($image);
        $profile = $key !== '' ? $this->profileByKey($key) : null;
        $absolutePath = (string)($image['absolute_path'] ?? '');
        $relativePath = (string)($image['relative_path'] ?? '');
        if (
            $absolutePath !== ''
            && !is_file($absolutePath)
            && $relativePath !== ''
            && class_exists(StorageService::class)
            && StorageService::isGcsActive()
        ) {
            StorageService::downloadFile($relativePath, $absolutePath);
        }
        $descriptor = $profile ? json_decode((string)($profile['descriptor_json'] ?? ''), true) : null;
        if (!is_array($descriptor) || !$descriptor) {
            $descriptor = is_array($image['scene_visual_descriptor'] ?? null) ? $image['scene_visual_descriptor'] : null;
        }

        if ($absolutePath !== '' && is_file($absolutePath)) {
            $size = (int)(filesize($absolutePath) ?: 0);
            $mtime = (int)(filemtime($absolutePath) ?: 0);
            $cacheCurrent = $profile
                && (int)($profile['file_size'] ?? -1) === $size
                && (int)($profile['file_mtime'] ?? -1) === $mtime
                && is_array($descriptor)
                && $descriptor;
            if (!$cacheCurrent) {
                $binary = file_get_contents($absolutePath);
                if (is_string($binary) && $binary !== '') {
                    $contentHash = hash('sha256', $binary);
                    $descriptor = $this->extractDescriptor($binary);
                    $matching = $this->profileByContentHash($contentHash);
                    $group = trim((string)($profile['similarity_group'] ?? $matching['similarity_group'] ?? ''));
                    $profile = [
                        'reference_key' => $key,
                        'content_hash' => $contentHash,
                        'file_size' => $size,
                        'file_mtime' => $mtime,
                        'descriptor_json' => $descriptor ? (string)json_encode($descriptor, JSON_UNESCAPED_SLASHES) : '',
                        'similarity_group' => $group,
                        'analyzed_at' => date(DATE_ATOM),
                    ];
                    if ($key !== '') {
                        $this->saveProfile($key, $profile);
                    }
                }
            }
        }

        $image['scene_content_hash'] = (string)($profile['content_hash'] ?? $image['scene_content_hash'] ?? '');
        $image['scene_similarity_group'] = trim((string)($profile['similarity_group'] ?? $image['scene_similarity_group'] ?? ''));
        $image['scene_visual_descriptor'] = is_array($descriptor) ? $descriptor : [];
        return $image;
    }

    /** @return array<string,mixed> */
    private function extractDescriptor(string $binary): array
    {
        if (!function_exists('imagecreatefromstring')) {
            return [];
        }
        $source = @imagecreatefromstring($binary);
        if (!$source) {
            return [];
        }

        $hashCanvas = imagecreatetruecolor(9, 8);
        imagecopyresampled($hashCanvas, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source));
        $bits = '';
        $luminance = [];
        for ($y = 0; $y < 8; $y++) {
            $row = [];
            for ($x = 0; $x < 9; $x++) {
                $rgb = imagecolorat($hashCanvas, $x, $y);
                $value = (int)round(0.299 * (($rgb >> 16) & 255) + 0.587 * (($rgb >> 8) & 255) + 0.114 * ($rgb & 255));
                $row[] = $value;
                if ($x < 8) {
                    $luminance[] = $value;
                }
            }
            for ($x = 0; $x < 8; $x++) {
                $bits .= $row[$x] > $row[$x + 1] ? '1' : '0';
            }
        }
        $dhash = '';
        for ($offset = 0; $offset < 64; $offset += 4) {
            $dhash .= dechex(bindec(substr($bits, $offset, 4)));
        }

        $colorCanvas = imagecreatetruecolor(4, 4);
        imagecopyresampled($colorCanvas, $source, 0, 0, 0, 0, 4, 4, imagesx($source), imagesy($source));
        $color = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $rgb = imagecolorat($colorCanvas, $x, $y);
                $color[] = ($rgb >> 16) & 255;
                $color[] = ($rgb >> 8) & 255;
                $color[] = $rgb & 255;
            }
        }

        imagedestroy($colorCanvas);
        imagedestroy($hashCanvas);
        imagedestroy($source);
        return ['dhash' => $dhash, 'luminance' => $luminance, 'color' => $color];
    }

    /** @param array<string,mixed> $image */
    private function referenceKey(array $image): string
    {
        $key = trim((string)($image['world_mother_id'] ?? ''));
        if ($key !== '') {
            return substr(str_replace('\\', '/', $key), 0, 255);
        }
        $relativePath = trim(str_replace('\\', '/', (string)($image['relative_path'] ?? '')));
        if ($relativePath === '') {
            return '';
        }
        $parts = explode('/', $relativePath);
        $fileName = array_pop($parts);
        $category = array_pop($parts) ?: '';
        return substr($category . '/' . pathinfo((string)$fileName, PATHINFO_FILENAME), 0, 255);
    }

    private function normalizeGroup(string $group): string
    {
        $group = trim((string)preg_replace('/\s+/u', ' ', $group));
        return mb_substr($group, 0, self::MAX_GROUP_LENGTH);
    }

    /** @return array<string,mixed>|null */
    private function profileByKey(string $key): ?array
    {
        if (array_key_exists($key, $this->profileCache)) {
            return $this->profileCache[$key];
        }
        if (!$this->pdo) {
            return $this->profileCache[$key] = null;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM scene_reference_profiles WHERE reference_key=:reference_key LIMIT 1');
            $stmt->execute(['reference_key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $this->profileCache[$key] = is_array($row) ? $row : null;
        } catch (Throwable) {
            return $this->profileCache[$key] = null;
        }
    }

    /** @return array<string,mixed>|null */
    private function profileByContentHash(string $hash): ?array
    {
        if (!$this->pdo || $hash === '') {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM scene_reference_profiles WHERE content_hash=:content_hash ORDER BY CASE WHEN similarity_group IS NULL OR similarity_group='' THEN 1 ELSE 0 END, updated_at DESC LIMIT 1");
            $stmt->execute(['content_hash' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $profile */
    private function saveProfile(string $key, array $profile): void
    {
        if (!$this->pdo || $key === '') {
            return;
        }
        $now = date(DATE_ATOM);
        $values = [
            'reference_key' => $key,
            'content_hash' => (string)($profile['content_hash'] ?? ''),
            'file_size' => max(0, (int)($profile['file_size'] ?? 0)),
            'file_mtime' => max(0, (int)($profile['file_mtime'] ?? 0)),
            'descriptor_json' => (string)($profile['descriptor_json'] ?? ''),
            'similarity_group' => ($group = $this->normalizeGroup((string)($profile['similarity_group'] ?? ''))) !== '' ? $group : null,
            'analyzed_at' => ($analyzedAt = trim((string)($profile['analyzed_at'] ?? ''))) !== '' ? $analyzedAt : null,
            'updated_at' => $now,
        ];
        try {
            if (strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql') {
                $sql = 'INSERT INTO scene_reference_profiles (reference_key,content_hash,file_size,file_mtime,descriptor_json,similarity_group,analyzed_at,updated_at)
                    VALUES (:reference_key,:content_hash,:file_size,:file_mtime,:descriptor_json,:similarity_group,:analyzed_at,:updated_at)
                    ON DUPLICATE KEY UPDATE content_hash=VALUES(content_hash),file_size=VALUES(file_size),file_mtime=VALUES(file_mtime),descriptor_json=VALUES(descriptor_json),similarity_group=VALUES(similarity_group),analyzed_at=VALUES(analyzed_at),updated_at=VALUES(updated_at)';
            } else {
                $sql = 'INSERT INTO scene_reference_profiles (reference_key,content_hash,file_size,file_mtime,descriptor_json,similarity_group,analyzed_at,updated_at)
                    VALUES (:reference_key,:content_hash,:file_size,:file_mtime,:descriptor_json,:similarity_group,:analyzed_at,:updated_at)
                    ON CONFLICT(reference_key) DO UPDATE SET content_hash=excluded.content_hash,file_size=excluded.file_size,file_mtime=excluded.file_mtime,descriptor_json=excluded.descriptor_json,similarity_group=excluded.similarity_group,analyzed_at=excluded.analyzed_at,updated_at=excluded.updated_at';
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);
            $this->profileCache[$key] = array_merge($values, ['reference_key' => $key]);
        } catch (Throwable) {
            // Similarity analysis is an enhancement; selection remains usable if
            // the cache cannot be written during a transient database problem.
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function profilesWithPrefix(string $prefix): array
    {
        if (!$this->pdo) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT * FROM scene_reference_profiles WHERE reference_key LIKE :prefix');
        $stmt->execute(['prefix' => $prefix . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
