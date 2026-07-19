<?php
declare(strict_types=1);

final class SceneRankingService
{
    private const DEFAULT_EDITORIAL_SCORE = 50;
    private const POPULARITY_WINDOW_DAYS = 90;
    private string $storageBasePath;

    public function __construct(private PDO $pdo, ?string $storageBasePath = null)
    {
        $this->storageBasePath = $storageBasePath !== null
            ? rtrim($storageBasePath, DIRECTORY_SEPARATOR . '/\\')
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
    }

    /**
     * @param array<int,array<string,mixed>> $categories
     * @return array<int,array<string,mixed>>
     */
    public function enrich(array $categories): array
    {
        $categories = array_values(array_filter($categories, static fn (array $category): bool => trim((string)($category['category_slug'] ?? '')) !== ''));
        $this->syncProfiles($categories);
        $profiles = $this->profilesBySlug();
        $metrics = $this->usageMetrics($categories);

        $maxUsage = $this->maxMetric($metrics, 'usage_count');
        $maxPopularity = $this->maxMetric($metrics, 'popularity_raw');
        $maxArtworks = $this->maxMetric($metrics, 'distinct_artworks');
        $maxCameras = $this->maxMetric($metrics, 'distinct_cameras');
        $maxUsers = $this->maxMetric($metrics, 'distinct_users');

        foreach ($categories as &$category) {
            $slug = (string)$category['category_slug'];
            $profile = $profiles[$slug] ?? $this->defaultProfile($slug);
            $metric = $metrics[$slug] ?? $this->emptyMetrics();
            $usageCount = (int)$metric['usage_count'];
            $usageScore = $this->normalizedScore($usageCount, $maxUsage, true);
            $popularityScore = $this->normalizedScore((int)$metric['popularity_raw'], $maxPopularity, true);

            $breadthScore = 50 * $this->ratio((int)$metric['distinct_artworks'], $maxArtworks)
                + 30 * $this->ratio((int)$metric['distinct_cameras'], $maxCameras)
                + 20 * $this->ratio((int)$metric['distinct_users'], $maxUsers);
            $confidence = min(1.0, sqrt(max(0, $usageCount)) / 2);
            $versatilityScore = (int)round($breadthScore * $confidence);

            $featuredScore = $this->isFeaturedActive($profile)
                ? $this->clampScore((int)$profile['featured_score'])
                : 0;
            $editorialScore = $this->clampScore((int)$profile['editorial_score']);
            $recommendedScore = (int)round(
                $editorialScore * .30
                + $versatilityScore * .25
                + $popularityScore * .20
                + $featuredScore * .15
                + $usageScore * .10
            );

            $category = array_merge($category, $profile, $metric, [
                'featured_score_effective' => $featuredScore,
                'featured_active' => $featuredScore > 0,
                'usage_score' => $usageScore,
                'popularity_score' => $popularityScore,
                'versatility_score' => $versatilityScore,
                'recommended_score' => $recommendedScore,
            ]);
        }
        unset($category);

        return $categories;
    }

    /**
     * @param array<int,array<string,mixed>> $categories
     * @return array<int,array<string,mixed>>
     */
    public function sort(array $categories, string $mode = 'recommended'): array
    {
        $mode = in_array($mode, ['recommended', 'featured', 'editorial', 'popular', 'versatile', 'usage', 'newest', 'alpha'], true)
            ? $mode
            : 'recommended';

        usort($categories, static function (array $a, array $b) use ($mode): int {
            $nameOrder = strcasecmp((string)($a['category_name'] ?? $a['category_slug'] ?? ''), (string)($b['category_name'] ?? $b['category_slug'] ?? ''));
            return match ($mode) {
                'featured' => ((int)($b['featured_score_effective'] ?? 0) <=> (int)($a['featured_score_effective'] ?? 0))
                    ?: ((int)($b['recommended_score'] ?? 0) <=> (int)($a['recommended_score'] ?? 0))
                    ?: $nameOrder,
                'editorial' => ((int)($b['editorial_score'] ?? 0) <=> (int)($a['editorial_score'] ?? 0))
                    ?: ((int)($b['recommended_score'] ?? 0) <=> (int)($a['recommended_score'] ?? 0))
                    ?: $nameOrder,
                'popular' => ((int)($b['popularity_score'] ?? 0) <=> (int)($a['popularity_score'] ?? 0))
                    ?: ((int)($b['usage_count'] ?? 0) <=> (int)($a['usage_count'] ?? 0))
                    ?: $nameOrder,
                'versatile' => ((int)($b['versatility_score'] ?? 0) <=> (int)($a['versatility_score'] ?? 0))
                    ?: ((int)($b['recommended_score'] ?? 0) <=> (int)($a['recommended_score'] ?? 0))
                    ?: $nameOrder,
                'usage' => ((int)($b['usage_count'] ?? 0) <=> (int)($a['usage_count'] ?? 0))
                    ?: ((int)($b['recommended_score'] ?? 0) <=> (int)($a['recommended_score'] ?? 0))
                    ?: $nameOrder,
                'newest' => strcmp((string)($b['discovered_at'] ?? ''), (string)($a['discovered_at'] ?? '')) ?: $nameOrder,
                'alpha' => $nameOrder,
                default => ((int)($b['recommended_score'] ?? 0) <=> (int)($a['recommended_score'] ?? 0))
                    ?: ((int)($b['editorial_score'] ?? 0) <=> (int)($a['editorial_score'] ?? 0))
                    ?: $nameOrder,
            };
        });

        return $categories;
    }

    /** @return array<string,mixed> */
    public function updateProfile(string $slug, int $featuredScore, string $featuredUntil, int $editorialScore): array
    {
        $slug = $this->requireSlug($slug);
        $featuredScore = $this->clampScore($featuredScore);
        $editorialScore = $this->clampScore($editorialScore);
        $featuredUntil = trim($featuredUntil);
        if ($featuredUntil !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $featuredUntil) !== 1) {
            throw new InvalidArgumentException('Featured until must use YYYY-MM-DD.');
        }

        $now = date(DATE_ATOM);
        if ($this->isMysql()) {
            $stmt = $this->pdo->prepare('INSERT INTO scene_ranking_profiles
                (category_slug, featured_score, featured_until, editorial_score, discovered_at, updated_at)
                VALUES (:slug, :featured_score, :featured_until, :editorial_score, :discovered_at, :updated_at)
                ON DUPLICATE KEY UPDATE featured_score=VALUES(featured_score), featured_until=VALUES(featured_until),
                    editorial_score=VALUES(editorial_score), updated_at=VALUES(updated_at)');
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO scene_ranking_profiles
                (category_slug, featured_score, featured_until, editorial_score, discovered_at, updated_at)
                VALUES (:slug, :featured_score, :featured_until, :editorial_score, :discovered_at, :updated_at)
                ON CONFLICT(category_slug) DO UPDATE SET featured_score=excluded.featured_score,
                    featured_until=excluded.featured_until, editorial_score=excluded.editorial_score, updated_at=excluded.updated_at');
        }
        $stmt->execute([
            'slug' => $slug,
            'featured_score' => $featuredScore,
            'featured_until' => $featuredUntil !== '' ? $featuredUntil : null,
            'editorial_score' => $editorialScore,
            'discovered_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->profile($slug);
    }

    public function renameCategory(string $sourceSlug, string $targetSlug): void
    {
        $sourceSlug = $this->requireSlug($sourceSlug);
        $targetSlug = $this->requireSlug($targetSlug);
        if ($sourceSlug === $targetSlug) {
            return;
        }
        if ($this->profileExists($targetSlug)) {
            $this->mergeCategory($sourceSlug, $targetSlug);
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE scene_ranking_profiles SET category_slug=:target_slug, updated_at=:updated_at WHERE category_slug=:source_slug');
        $stmt->execute(['target_slug' => $targetSlug, 'source_slug' => $sourceSlug, 'updated_at' => date(DATE_ATOM)]);
    }

    public function mergeCategory(string $sourceSlug, string $targetSlug): void
    {
        $sourceSlug = $this->requireSlug($sourceSlug);
        $targetSlug = $this->requireSlug($targetSlug);
        if ($sourceSlug === $targetSlug || !$this->profileExists($sourceSlug)) {
            return;
        }
        $source = $this->profile($sourceSlug);
        $target = $this->profileExists($targetSlug) ? $this->profile($targetSlug) : $this->defaultProfile($targetSlug);
        $until = max((string)($source['featured_until'] ?? ''), (string)($target['featured_until'] ?? ''));
        $this->updateProfile(
            $targetSlug,
            max((int)$source['featured_score'], (int)$target['featured_score']),
            $until,
            max((int)$source['editorial_score'], (int)$target['editorial_score'])
        );
        $this->deleteCategory($sourceSlug);
    }

    public function deleteCategory(string $slug): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM scene_ranking_profiles WHERE category_slug=:slug');
        $stmt->execute(['slug' => $this->requireSlug($slug)]);
    }

    /** @return array<string,mixed> */
    public function profile(string $slug): array
    {
        $slug = $this->requireSlug($slug);
        $stmt = $this->pdo->prepare('SELECT * FROM scene_ranking_profiles WHERE category_slug=:slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : $this->defaultProfile($slug);
    }

    /** @param array<int,array<string,mixed>> $categories */
    private function syncProfiles(array $categories): void
    {
        $sql = $this->isMysql()
            ? 'INSERT IGNORE INTO scene_ranking_profiles (category_slug, featured_score, featured_until, editorial_score, discovered_at, updated_at) VALUES (:slug,0,NULL,:editorial_score,:now,:now)'
            : 'INSERT OR IGNORE INTO scene_ranking_profiles (category_slug, featured_score, featured_until, editorial_score, discovered_at, updated_at) VALUES (:slug,0,NULL,:editorial_score,:now,:now)';
        $stmt = $this->pdo->prepare($sql);
        $now = date(DATE_ATOM);
        foreach ($categories as $category) {
            $slug = $this->requireSlug((string)($category['category_slug'] ?? ''));
            $stmt->execute(['slug' => $slug, 'editorial_score' => self::DEFAULT_EDITORIAL_SCORE, 'now' => $now]);
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function profilesBySlug(): array
    {
        $profiles = [];
        foreach ($this->pdo->query('SELECT * FROM scene_ranking_profiles')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $profiles[(string)$row['category_slug']] = $row;
        }
        return $profiles;
    }

    /**
     * @param array<int,array<string,mixed>> $categories
     * @return array<string,array<string,mixed>>
     */
    private function usageMetrics(array $categories): array
    {
        $metrics = [];
        $slugLookup = [];
        foreach ($categories as $category) {
            $slug = (string)$category['category_slug'];
            $metrics[$slug] = $this->emptyMetrics();
            $slugLookup[$this->normalizeSlug($slug)] = $slug;
        }

        $categoryByMockupId = [];
        $sessions = [];
        $recentSessions = [];
        $artworks = [];
        $cameras = [];
        $users = [];
        $recentUsers = [];
        $cutoff = strtotime('-' . self::POPULARITY_WINDOW_DAYS . ' days');
        $rows = $this->pdo->query('SELECT id,user_id,source_artwork_id,artwork_file,selector_state_json,created_at FROM mockups')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $state = json_decode((string)($row['selector_state_json'] ?? ''), true);
            if (!is_array($state)) {
                continue;
            }
            $combination = is_array($state['combination'] ?? null) ? $state['combination'] : [];
            $rawCategory = (string)($state['world_mother_category'] ?? $combination['world_mother_category'] ?? '');
            $slug = $slugLookup[$this->normalizeSlug($rawCategory)] ?? '';
            if ($slug === '') {
                continue;
            }
            $mockupId = (int)($row['id'] ?? 0);
            $categoryByMockupId[$mockupId] = $slug;
            $userId = (int)($row['user_id'] ?? 0);
            $artworkRef = (int)($row['source_artwork_id'] ?? 0) > 0
                ? 'id:' . (int)$row['source_artwork_id']
                : 'file:' . basename((string)($row['artwork_file'] ?? ''));
            $board = (int)($state['scene_board_index'] ?? $combination['camera_slot_scene_board_index'] ?? 1);
            $createdAt = (string)($row['created_at'] ?? '');
            $day = ($timestamp = strtotime($createdAt)) !== false ? date('Y-m-d', $timestamp) : '';
            $sessionKey = $userId . '|' . $artworkRef . '|' . $slug . '|' . $board . '|' . $day;
            $sessions[$slug][$sessionKey] = true;
            $artworks[$slug][$artworkRef] = true;
            $users[$slug][$userId] = true;
            $camera = trim((string)($combination['selected_camera_slot_id'] ?? $combination['camera_slot_name'] ?? ''));
            if ($camera !== '') {
                $cameras[$slug][$camera] = true;
            }
            if ($timestamp !== false && $timestamp >= $cutoff) {
                $recentSessions[$slug][$sessionKey] = true;
                $recentUsers[$slug][$userId] = true;
            }
        }

        $sceneFavorites = $this->favoriteSlugs('world_mother_favorites', $slugLookup);
        $mockupFavorites = $this->favoriteMockupCategories($categoryByMockupId);
        foreach ($metrics as $slug => &$metric) {
            $metric['usage_count'] = count($sessions[$slug] ?? []);
            $metric['recent_usage_count'] = count($recentSessions[$slug] ?? []);
            $metric['distinct_artworks'] = count($artworks[$slug] ?? []);
            $metric['distinct_cameras'] = count($cameras[$slug] ?? []);
            $metric['distinct_users'] = count($users[$slug] ?? []);
            $metric['recent_unique_users'] = count($recentUsers[$slug] ?? []);
            $metric['scene_favorite_count'] = (int)($sceneFavorites[$slug] ?? 0);
            $metric['mockup_favorite_count'] = (int)($mockupFavorites[$slug] ?? 0);
            $metric['popularity_raw'] = $metric['recent_usage_count']
                + 2 * $metric['recent_unique_users']
                + 3 * $metric['scene_favorite_count']
                + 4 * $metric['mockup_favorite_count'];
        }
        unset($metric);

        return $metrics;
    }

    /** @param array<string,string> $slugLookup @return array<string,int> */
    private function favoriteSlugs(string $directory, array $slugLookup): array
    {
        $counts = [];
        $base = $this->storageBasePath . DIRECTORY_SEPARATOR . $directory;
        foreach (glob($base . DIRECTORY_SEPARATOR . 'user_*.json') ?: [] as $path) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach (array_unique(array_map('strval', $decoded)) as $rawSlug) {
                $slug = $slugLookup[$this->normalizeSlug($rawSlug)] ?? '';
                if ($slug !== '') {
                    $counts[$slug] = (int)($counts[$slug] ?? 0) + 1;
                }
            }
        }
        return $counts;
    }

    /** @param array<int,string> $categoryByMockupId @return array<string,int> */
    private function favoriteMockupCategories(array $categoryByMockupId): array
    {
        $counts = [];
        $base = $this->storageBasePath . DIRECTORY_SEPARATOR . 'mockup_favorites';
        foreach (glob($base . DIRECTORY_SEPARATOR . 'user_*.json') ?: [] as $path) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach (array_unique(array_map('intval', $decoded)) as $mockupId) {
                $slug = $categoryByMockupId[$mockupId] ?? '';
                if ($slug !== '') {
                    $counts[$slug] = (int)($counts[$slug] ?? 0) + 1;
                }
            }
        }
        return $counts;
    }

    /** @return array<string,int> */
    private function emptyMetrics(): array
    {
        return [
            'usage_count' => 0,
            'recent_usage_count' => 0,
            'distinct_artworks' => 0,
            'distinct_cameras' => 0,
            'distinct_users' => 0,
            'recent_unique_users' => 0,
            'scene_favorite_count' => 0,
            'mockup_favorite_count' => 0,
            'popularity_raw' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function defaultProfile(string $slug): array
    {
        return [
            'category_slug' => $slug,
            'featured_score' => 0,
            'featured_until' => null,
            'editorial_score' => self::DEFAULT_EDITORIAL_SCORE,
            'discovered_at' => date(DATE_ATOM),
            'updated_at' => date(DATE_ATOM),
        ];
    }

    /** @param array<string,mixed> $profile */
    private function isFeaturedActive(array $profile): bool
    {
        if ((int)($profile['featured_score'] ?? 0) <= 0) {
            return false;
        }
        $until = trim((string)($profile['featured_until'] ?? ''));
        return $until === '' || $until >= date('Y-m-d');
    }

    /** @param array<string,array<string,mixed>> $metrics */
    private function maxMetric(array $metrics, string $key): int
    {
        $values = array_map(static fn (array $metric): int => (int)($metric[$key] ?? 0), $metrics);
        return $values ? max($values) : 0;
    }

    private function normalizedScore(int $value, int $maximum, bool $logarithmic = false): int
    {
        if ($value <= 0 || $maximum <= 0) {
            return 0;
        }
        $ratio = $logarithmic ? log(1 + $value) / log(1 + $maximum) : $value / $maximum;
        return $this->clampScore((int)round($ratio * 100));
    }

    private function ratio(int $value, int $maximum): float
    {
        return $value > 0 && $maximum > 0 ? min(1, $value / $maximum) : 0.0;
    }

    private function clampScore(int $score): int
    {
        return max(0, min(100, $score));
    }

    private function profileExists(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM scene_ranking_profiles WHERE category_slug=:slug');
        $stmt->execute(['slug' => $slug]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function requireSlug(string $slug): string
    {
        $slug = trim(str_replace(['\\', '/'], '', $slug));
        if ($slug === '' || strlen($slug) > 80) {
            throw new InvalidArgumentException('Invalid scene category.');
        }
        return $slug;
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?: '';
        return trim($slug, '_');
    }

    private function isMysql(): bool
    {
        return strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
    }
}
