<?php
declare(strict_types=1);

require_once __DIR__ . '/PublicSlug.php';

class ArtworkSeries
{
    public static function ensureSchema(PDO $pdo): void
    {
        if (Database::isMysql()) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS artwork_series (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    title VARCHAR(190) NOT NULL,
                    slug VARCHAR(210) NOT NULL,
                    description TEXT NULL,
                    status VARCHAR(30) NOT NULL DEFAULT 'active',
                    created_at VARCHAR(40) NOT NULL,
                    updated_at VARCHAR(40) NOT NULL,
                    UNIQUE KEY artwork_series_user_slug (user_id, slug),
                    KEY artwork_series_user_status (user_id, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            self::addColumnIfMissing($pdo, 'artworks', 'series_id', 'INT UNSIGNED NULL');
            self::addColumnIfMissing($pdo, 'artworks', 'series_creation_number', 'INT UNSIGNED NULL');
            self::addColumnIfMissing($pdo, 'mockups', 'series_id', 'INT UNSIGNED NULL');
            self::addColumnIfMissing($pdo, 'artwork_series', 'subtitle', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
            self::addColumnIfMissing($pdo, 'artwork_series', 'long_description', 'MEDIUMTEXT NULL');
            self::addColumnIfMissing($pdo, 'artwork_series', 'keywords', 'TEXT NULL');
            self::addColumnIfMissing($pdo, 'artwork_series', 'tags', 'TEXT NULL');
            self::addColumnIfMissing($pdo, 'artwork_series', 'seo_description', 'VARCHAR(500) NOT NULL DEFAULT \'\'');
            self::addColumnIfMissing($pdo, 'artwork_series', 'conceptual_core', 'MEDIUMTEXT NULL');
            self::addColumnIfMissing($pdo, 'artwork_series', 'interpretive_limits', 'MEDIUMTEXT NULL');
            self::addColumnIfMissing($pdo, 'artwork_series', 'year_start', 'SMALLINT UNSIGNED NULL');
            self::addColumnIfMissing($pdo, 'artwork_series', 'year_end', 'SMALLINT UNSIGNED NULL');
            self::addColumnIfMissing($pdo, 'artwork_series', 'header_file', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
            self::addColumnIfMissing($pdo, 'artwork_series', 'published', 'TINYINT(1) NOT NULL DEFAULT 0');
            self::addColumnIfMissing($pdo, 'artwork_series', 'header_focal_x', 'TINYINT UNSIGNED NOT NULL DEFAULT 50');
            self::addColumnIfMissing($pdo, 'artwork_series', 'header_focal_y', 'TINYINT UNSIGNED NOT NULL DEFAULT 50');
            self::addColumnIfMissing($pdo, 'artwork_series', 'header_zoom', 'SMALLINT UNSIGNED NOT NULL DEFAULT 115');
            self::dropColumnIfExists($pdo, 'artwork_series', 'alt_text');
            self::dropColumnIfExists($pdo, 'artwork_series', 'seo_title');
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS artwork_series (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                slug TEXT NOT NULL,
                description TEXT,
                status TEXT NOT NULL DEFAULT 'active',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(user_id, slug)
            )
        ");
        self::addColumnIfMissing($pdo, 'artworks', 'series_id', 'INTEGER NULL');
        self::addColumnIfMissing($pdo, 'artworks', 'series_creation_number', 'INTEGER NULL');
        self::addColumnIfMissing($pdo, 'mockups', 'series_id', 'INTEGER NULL');
        self::addColumnIfMissing($pdo, 'artwork_series', 'subtitle', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artwork_series', 'long_description', 'TEXT');
        self::addColumnIfMissing($pdo, 'artwork_series', 'keywords', 'TEXT');
        self::addColumnIfMissing($pdo, 'artwork_series', 'tags', 'TEXT');
        self::addColumnIfMissing($pdo, 'artwork_series', 'seo_description', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artwork_series', 'conceptual_core', 'TEXT');
        self::addColumnIfMissing($pdo, 'artwork_series', 'interpretive_limits', 'TEXT');
        self::addColumnIfMissing($pdo, 'artwork_series', 'year_start', 'INTEGER NULL');
        self::addColumnIfMissing($pdo, 'artwork_series', 'year_end', 'INTEGER NULL');
        self::addColumnIfMissing($pdo, 'artwork_series', 'header_file', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artwork_series', 'published', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumnIfMissing($pdo, 'artwork_series', 'header_focal_x', 'INTEGER NOT NULL DEFAULT 50');
        self::addColumnIfMissing($pdo, 'artwork_series', 'header_focal_y', 'INTEGER NOT NULL DEFAULT 50');
        self::addColumnIfMissing($pdo, 'artwork_series', 'header_zoom', 'INTEGER NOT NULL DEFAULT 115');
        // SQLite can't drop columns cheaply pre-3.35; alt_text/seo_title are left as unused legacy columns there.
    }

    public static function normalizeTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');
        return strtoupper($title) === 'NO SERIE' ? '' : $title;
    }

    public static function slug(string $title): string
    {
        $slug = PublicSlug::universal(self::normalizeTitle($title));
        return $slug !== '' ? $slug : 'serie';
    }

    public static function display(?string $title): string
    {
        return self::normalizeTitle((string)$title);
    }

    public static function creationPrefix(string $seriesTitle): string
    {
        $prefix = self::normalizeTitle($seriesTitle);
        $prefix = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $prefix) ?: $prefix;
        return strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '', $prefix) ?? '');
    }

    public static function creationIdentifier(string $seriesTitle, mixed $creationNumber): string
    {
        $number = (int)$creationNumber;
        $prefix = self::creationPrefix($seriesTitle);
        if ($prefix === '' || $number <= 0) {
            return '';
        }
        return $prefix . str_pad((string)$number, 3, '0', STR_PAD_LEFT);
    }

    public static function getOrCreate(PDO $pdo, int $userId, string $title, string $description = ''): ?int
    {
        $title = self::normalizeTitle($title);
        if ($title === '') {
            return null;
        }

        $slug = self::uniqueSlug($pdo, $userId, $title);
        $now = date('c');
        $stmt = $pdo->prepare('SELECT id FROM artwork_series WHERE user_id = ? AND LOWER(title) = LOWER(?) LIMIT 1');
        $stmt->execute([$userId, $title]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (int)$existing;
        }

        $stmt = $pdo->prepare('INSERT INTO artwork_series (user_id, title, slug, description, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $title, $slug, $description, 'active', $now, $now]);
        return (int)$pdo->lastInsertId();
    }

    /** Full editorial content for a series: title, subtitle, short/long description, tags, long-tail keywords, SEO meta description and URL slug. */
    public static function updateContent(PDO $pdo, int $userId, int $seriesId, array $fields): void
    {
        $title = self::normalizeTitle((string)($fields['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Series title is required.');
        }
        $slug = self::uniqueSlug($pdo, $userId, $title, $seriesId);
        [$yearStart, $yearEnd] = self::normalizeYearRange($fields['year_start'] ?? '', $fields['year_end'] ?? '');
        $now = date('c');
        $stmt = $pdo->prepare('UPDATE artwork_series SET title=?, slug=?, subtitle=?, description=?, long_description=?, tags=?, keywords=?, seo_description=?, conceptual_core=?, interpretive_limits=?, year_start=?, year_end=?, updated_at=? WHERE id=? AND user_id=?');
        $stmt->execute([
            $title, $slug,
            trim((string)($fields['subtitle'] ?? '')),
            trim((string)($fields['description'] ?? '')),
            trim((string)($fields['long_description'] ?? '')),
            trim((string)($fields['tags'] ?? '')),
            trim((string)($fields['keywords'] ?? '')),
            trim((string)($fields['seo_description'] ?? '')),
            trim((string)($fields['conceptual_core'] ?? '')),
            trim((string)($fields['interpretive_limits'] ?? '')),
            $yearStart, $yearEnd,
            $now, $seriesId, $userId,
        ]);
        $stmt = $pdo->prepare('UPDATE artworks SET series = ? WHERE user_id = ? AND series_id = ?');
        $stmt->execute([$title, $userId, $seriesId]);
    }

    public static function updateDirection(PDO $pdo, int $userId, int $seriesId, string $conceptualCore, string $interpretiveLimits): void
    {
        $conceptualCore = trim($conceptualCore);
        $interpretiveLimits = trim($interpretiveLimits);
        $current = $pdo->prepare('SELECT conceptual_core,interpretive_limits FROM artwork_series WHERE id=? AND user_id=? LIMIT 1');
        $current->execute([$seriesId, $userId]);
        $previous = $current->fetch(PDO::FETCH_ASSOC);
        if (!$previous) {
            throw new RuntimeException('Series not found.');
        }
        $stmt = $pdo->prepare('UPDATE artwork_series SET conceptual_core=?, interpretive_limits=?, updated_at=? WHERE id=? AND user_id=?');
        $stmt->execute([
            $conceptualCore,
            $interpretiveLimits,
            date('c'),
            $seriesId,
            $userId,
        ]);
    }

    public const YEAR_RANGE_START = 2010;

    /** @return array{0: ?int, 1: ?int} */
    private static function normalizeYearRange(mixed $start, mixed $end): array
    {
        $currentYear = (int)date('Y');
        $clamp = static function (mixed $value) use ($currentYear): ?int {
            $value = trim((string)$value);
            if ($value === '' || !ctype_digit($value)) return null;
            $year = (int)$value;
            return $year >= ArtworkSeries::YEAR_RANGE_START && $year <= $currentYear + 1 ? $year : null;
        };
        $yearStart = $clamp($start);
        $yearEnd = $clamp($end);
        if ($yearStart !== null && $yearEnd !== null && $yearStart > $yearEnd) {
            [$yearStart, $yearEnd] = [$yearEnd, $yearStart];
        }
        return [$yearStart, $yearEnd];
    }

    public static function syncUser(PDO $pdo, int $userId): void
    {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare("SELECT DISTINCT series FROM artworks WHERE user_id = ? AND TRIM(series) <> ''");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $legacyTitle) {
            $title = self::normalizeTitle((string)$legacyTitle);
            if ($title === '') {
                continue;
            }
            self::getOrCreate($pdo, $userId, $title);
        }

        $seriesStmt = $pdo->prepare('SELECT id, title FROM artwork_series WHERE user_id = ? AND status = ?');
        $seriesStmt->execute([$userId, 'active']);
        foreach ($seriesStmt->fetchAll(PDO::FETCH_ASSOC) as $series) {
            $stmt = $pdo->prepare('UPDATE artworks SET series_id = ?, series = ? WHERE user_id = ? AND (LOWER(TRIM(series)) = LOWER(?) OR series_id = ?)');
            $stmt->execute([(int)$series['id'], (string)$series['title'], $userId, (string)$series['title'], (int)$series['id']]);
        }

        $stmt = $pdo->prepare("UPDATE artworks SET series_id = NULL, series = '', series_creation_number = NULL WHERE user_id = ? AND UPPER(TRIM(series)) = 'NO SERIE'");
        $stmt->execute([$userId]);

        self::backfillCreationNumbers($pdo, $userId);
        self::syncMockups($pdo, $userId);
    }

    private static function backfillCreationNumbers(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare('
            SELECT id, series_id, series_creation_number
            FROM artworks
            WHERE user_id = ? AND series_id IS NOT NULL
            ORDER BY series_id ASC, created_at ASC, id ASC
        ');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $usedBySeries = [];
        foreach ($rows as $row) {
            $seriesId = (int)$row['series_id'];
            $number = (int)($row['series_creation_number'] ?? 0);
            if ($number > 0) {
                $usedBySeries[$seriesId][$number] = true;
            }
        }

        $nextBySeries = [];
        $update = $pdo->prepare('UPDATE artworks SET series_creation_number = ? WHERE id = ? AND user_id = ?');
        foreach ($rows as $row) {
            $seriesId = (int)$row['series_id'];
            $number = (int)($row['series_creation_number'] ?? 0);
            $next = $nextBySeries[$seriesId] ?? 10;
            if ($number > 0) {
                $nextBySeries[$seriesId] = max($next, ((int)floor($number / 10) + 1) * 10);
                continue;
            }
            while (isset($usedBySeries[$seriesId][$next])) {
                $next += 10;
            }
            $update->execute([$next, (int)$row['id'], $userId]);
            $usedBySeries[$seriesId][$next] = true;
            $nextBySeries[$seriesId] = $next + 10;
        }
    }

    public static function syncMockups(PDO $pdo, int $userId): void
    {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare("\n            SELECT a.id, a.root_file, a.main_file,\n                   CASE WHEN g.id IS NOT NULL THEN canonical.series_id ELSE a.series_id END AS effective_series_id\n            FROM artworks a\n            LEFT JOIN artwork_groups g\n                ON g.id = a.artwork_group_id\n                AND g.user_id = a.user_id\n                AND g.status = 'active'\n            LEFT JOIN artworks canonical\n                ON canonical.id = g.canonical_artwork_id\n                AND canonical.user_id = g.user_id\n            WHERE a.user_id = ?\n        ");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $artwork) {
            $seriesId = $artwork['effective_series_id'] !== null && $artwork['effective_series_id'] !== '' ? (int)$artwork['effective_series_id'] : null;
            $params = ['user_id' => $userId, 'source_artwork_id' => (int)$artwork['id']];
            $conditions = ['source_artwork_id = :source_artwork_id'];

            $rootFile = basename((string)($artwork['root_file'] ?? ''));
            if ($rootFile !== '') {
                $conditions[] = 'artwork_file = :root_file';
                $params['root_file'] = $rootFile;
            }

            $mainFile = basename((string)($artwork['main_file'] ?? ''));
            if ($mainFile !== '' && $mainFile !== $rootFile) {
                $conditions[] = 'artwork_file = :main_file';
                $params['main_file'] = $mainFile;
            }

            $sql = 'UPDATE mockups SET series_id = :series_id WHERE user_id = :user_id AND (' . implode(' OR ', $conditions) . ')';
            $update = $pdo->prepare($sql);
            $update->bindValue(':series_id', $seriesId, $seriesId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            foreach ($params as $key => $value) {
                $update->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $update->execute();
        }

        $stmt = $pdo->prepare("\n            SELECT g.id AS artwork_group_id, canonical.series_id\n            FROM artwork_groups g\n            INNER JOIN artworks canonical\n                ON canonical.id = g.canonical_artwork_id\n                AND canonical.user_id = g.user_id\n            WHERE g.user_id = ?\n            AND g.status = 'active'\n        ");
        $stmt->execute([$userId]);
        $updateGroup = $pdo->prepare('UPDATE mockups SET series_id = :series_id WHERE user_id = :user_id AND artwork_group_id = :artwork_group_id');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $group) {
            $seriesId = $group['series_id'] !== null && $group['series_id'] !== '' ? (int)$group['series_id'] : null;
            $updateGroup->bindValue(':series_id', $seriesId, $seriesId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $updateGroup->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $updateGroup->bindValue(':artwork_group_id', (int)$group['artwork_group_id'], PDO::PARAM_INT);
            $updateGroup->execute();
        }
    }

    /** Every artwork image the user owns, usable as a header-image candidate for any series. Artworks already in this series sort first. */
    public static function headerCandidates(PDO $pdo, int $userId, int $seriesId): array
    {
        $stmt = $pdo->prepare('SELECT id, final_title, root_file, main_file, (series_id = ?) AS in_series
            FROM artworks WHERE user_id = ? ORDER BY in_series DESC, updated_at DESC, id DESC');
        $stmt->execute([$seriesId, $userId]);
        $candidates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $artwork) {
            $file = basename((string)($artwork['root_file'] ?: $artwork['main_file']));
            if ($file === '') continue;
            $candidates[] = ['artwork_id' => (int)$artwork['id'], 'title' => (string)$artwork['final_title'], 'file' => $file, 'in_series' => (bool)$artwork['in_series']];
        }
        return $candidates;
    }

    /** Recent (or search-matching) mockups the user owns, usable as a header-image candidate. */
    public static function searchMockups(PDO $pdo, int $userId, string $query, int $limit = 48): array
    {
        $limit = max(1, min(96, $limit));
        $query = trim($query);
        $sql = 'SELECT m.mockup_file, MAX(ms.title) AS title, MAX(m.created_at) AS created_at
            FROM mockups m
            LEFT JOIN mockup_sheets ms ON ms.user_id = m.user_id AND ms.mockup_file = m.mockup_file
            WHERE m.user_id = ?';
        $params = [$userId];
        if ($query !== '') {
            $sql .= ' AND (ms.title LIKE ? OR ms.description LIKE ? OR m.context_id LIKE ?)';
            $like = '%' . $query . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' GROUP BY m.mockup_file ORDER BY created_at DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $file = basename((string)$row['mockup_file']);
            if ($file === '') continue;
            $results[] = ['file' => $file, 'title' => trim((string)($row['title'] ?: ''))];
        }
        return $results;
    }

    private static function fileOwnedByUser(PDO $pdo, int $userId, string $file): bool
    {
        if ($file === '') return false;
        $stmt = $pdo->prepare('SELECT 1 FROM artworks WHERE user_id = ? AND (root_file = ? OR main_file = ?) LIMIT 1');
        $stmt->execute([$userId, $file, $file]);
        if ($stmt->fetchColumn()) return true;
        $stmt = $pdo->prepare('SELECT 1 FROM mockups WHERE user_id = ? AND mockup_file = ? LIMIT 1');
        $stmt->execute([$userId, $file]);
        if ($stmt->fetchColumn()) return true;
        return is_file(self::headerUploadDir($userId) . DIRECTORY_SEPARATOR . $file);
    }

    public static function headerUploadDir(int $userId): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'series_headers' . DIRECTORY_SEPARATOR . $userId;
    }

    public static function setHeader(PDO $pdo, int $userId, int $seriesId, string $file): void
    {
        $file = basename($file);
        if ($file !== '' && !self::fileOwnedByUser($pdo, $userId, $file)) {
            throw new RuntimeException('That image is not available.');
        }
        $pdo->prepare('UPDATE artwork_series SET header_file = ?, header_focal_x = 50, header_focal_y = 50, header_zoom = 115, updated_at = ? WHERE id = ? AND user_id = ?')
            ->execute([$file, date('c'), $seriesId, $userId]);
    }

    /** @param array{tmp_name:string,error:int,size:int} $uploadedFile */
    public static function uploadHeader(PDO $pdo, int $userId, int $seriesId, array $uploadedFile): void
    {
        if ((int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed.');
        }
        $tmpPath = (string)($uploadedFile['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Invalid upload.');
        }
        if ((int)($uploadedFile['size'] ?? 0) > 15 * 1024 * 1024) {
            throw new RuntimeException('Image is larger than 15MB.');
        }
        $info = @getimagesize($tmpPath);
        $extension = match ($info[2] ?? null) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };
        if ($extension === null) {
            throw new RuntimeException('Only JPG, PNG or WEBP images are supported.');
        }
        $dir = self::headerUploadDir($userId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not prepare the upload folder.');
        }
        $filename = 'series-header-' . $seriesId . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        if (!move_uploaded_file($tmpPath, $dir . DIRECTORY_SEPARATOR . $filename)) {
            throw new RuntimeException('Could not save the uploaded image.');
        }
        $savedPath = $dir . DIRECTORY_SEPARATOR . $filename;
        if (class_exists('StorageService') && StorageService::isGcsActive()
            && !StorageService::uploadFile('results/' . $filename, $savedPath)) {
            @unlink($savedPath);
            throw new RuntimeException('Could not persist the uploaded image.');
        }
        $pdo->prepare('UPDATE artwork_series SET header_file = ?, header_focal_x = 50, header_focal_y = 50, header_zoom = 115, updated_at = ? WHERE id = ? AND user_id = ?')
            ->execute([$filename, date('c'), $seriesId, $userId]);
    }

    public static function setHeaderFraming(PDO $pdo, int $userId, int $seriesId, float $focalX, float $focalY, float $zoom): void
    {
        $focalX = (int)round(max(0, min(100, $focalX)));
        $focalY = (int)round(max(0, min(100, $focalY)));
        $zoom = (int)round(max(115, min(400, $zoom)));
        $pdo->prepare('UPDATE artwork_series SET header_focal_x = ?, header_focal_y = ?, header_zoom = ?, updated_at = ? WHERE id = ? AND user_id = ?')
            ->execute([$focalX, $focalY, $zoom, date('c'), $seriesId, $userId]);
    }

    /** @return string[] Missing-field labels; empty array means the series is ready to publish. */
    public static function missingForPublish(array $series): array
    {
        $missing = [];
        if (trim((string)($series['header_file'] ?? '')) === '') $missing[] = 'header image';
        if (trim((string)($series['description'] ?? '')) === '' && trim((string)($series['long_description'] ?? '')) === '') {
            $missing[] = 'description';
        }
        return $missing;
    }

    private static function shortDescriptionFromLong(string $longDescription): string
    {
        $paragraphs = preg_split('/\R\s*\R/u', trim($longDescription)) ?: [];
        $summary = trim((string)($paragraphs[0] ?? ''));
        return preg_replace('/\s+/u', ' ', $summary) ?? $summary;
    }

    public static function setPublished(PDO $pdo, int $userId, int $seriesId, bool $published): void
    {
        if ($published) {
            $stmt = $pdo->prepare('SELECT * FROM artwork_series WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$seriesId, $userId]);
            $series = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$series) throw new RuntimeException('Series not found.');
            if (trim((string)($series['description'] ?? '')) === '' && trim((string)($series['long_description'] ?? '')) !== '') {
                $series['description'] = self::shortDescriptionFromLong((string)$series['long_description']);
                $pdo->prepare('UPDATE artwork_series SET description = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                    ->execute([(string)$series['description'], date('c'), $seriesId, $userId]);
            }
            $missing = self::missingForPublish($series);
            if ($missing) throw new RuntimeException('Cannot publish. Missing: ' . implode(', ', $missing) . '.');
        } else {
            $publishedArtworkCount = self::publishedArtworkCountForSeries($pdo, $userId, $seriesId);
            if ($publishedArtworkCount > 0) {
                $noun = $publishedArtworkCount === 1 ? 'obra publicada' : 'obras publicadas';
                throw new RuntimeException('No se puede retirar la serie mientras tenga ' . $publishedArtworkCount . ' ' . $noun . '.');
            }
        }
        $pdo->prepare('UPDATE artwork_series SET published = ?, updated_at = ? WHERE id = ? AND user_id = ?')
            ->execute([$published ? 1 : 0, date('c'), $seriesId, $userId]);
    }

    private static function publishedArtworkCountForSeries(PDO $pdo, int $userId, int $seriesId): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT p.id)
            FROM publications p
            INNER JOIN artwork_sheets sh ON sh.id = p.artwork_sheet_id AND sh.user_id = p.user_id
            INNER JOIN artworks a ON a.id = sh.canonical_artwork_id AND a.user_id = sh.user_id
            WHERE p.user_id = ? AND a.series_id = ? AND p.status = 'published'");
        $stmt->execute([$userId, $seriesId]);
        return (int)$stmt->fetchColumn();
    }

    private static function artworkIsPublished(PDO $pdo, int $userId, int $artworkId): bool
    {
        $stmt = $pdo->prepare("SELECT 1
            FROM publications p
            INNER JOIN artwork_sheets sh ON sh.id = p.artwork_sheet_id AND sh.user_id = p.user_id
            WHERE p.user_id = ? AND sh.canonical_artwork_id = ? AND p.status = 'published'
            LIMIT 1");
        $stmt->execute([$userId, $artworkId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function seriesList(PDO $pdo, int $userId): array
    {
        self::syncUser($pdo, $userId);
        $stmt = $pdo->prepare('
            SELECT s.*,
                   (
                       SELECT COUNT(*)
                       FROM artworks a
                       WHERE a.user_id = s.user_id
                       AND a.series_id = s.id
                       AND (
                           a.artwork_group_id IS NULL
                           OR EXISTS (
                               SELECT 1
                               FROM artwork_groups g
                               WHERE g.id = a.artwork_group_id
                               AND g.user_id = a.user_id
                               AND g.canonical_artwork_id = a.id
                               AND g.status = \'active\'
                           )
                       )
                   ) AS artwork_count,
                   (SELECT COUNT(*) FROM mockups m WHERE m.user_id = s.user_id AND m.series_id = s.id) AS mockup_count
            FROM artwork_series s
            WHERE s.user_id = ? AND s.status = ?
            ORDER BY
                CASE WHEN s.year_start IS NULL AND s.year_end IS NULL THEN 1 ELSE 0 END ASC,
                COALESCE(s.year_start, s.year_end) DESC,
                COALESCE(s.year_end, s.year_start) DESC,
                s.created_at DESC,
                s.id DESC
        ');
        $stmt->execute([$userId, 'active']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (self::hasColumn($pdo, $table, $column)) {
            return;
        }
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private static function dropColumnIfExists(PDO $pdo, string $table, string $column): void
    {
        if (!self::hasColumn($pdo, $table, $column)) {
            return;
        }
        $pdo->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        if (Database::isMysql()) {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetch();
        }

        $stmt = $pdo->query("PRAGMA table_info({$table})");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string)$row['name'] === $column) {
                return true;
            }
        }
        return false;
    }

    private static function uniqueSlug(PDO $pdo, int $userId, string $title, ?int $ignoreId = null): string
    {
        $base = self::slug($title);
        $slug = $base;
        $suffix = 2;
        while (self::slugExists($pdo, $userId, $slug, $ignoreId)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }
        return $slug;
    }

    private static function slugExists(PDO $pdo, int $userId, string $slug, ?int $ignoreId): bool
    {
        $sql = 'SELECT id FROM artwork_series WHERE user_id = ? AND slug = ?';
        $params = [$userId, $slug];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    public static function assignArtwork(PDO $pdo, int $userId, int $artworkId, ?int $seriesId, bool $syncMockups = true): void
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare('SELECT series_id, series_creation_number FROM artworks WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$artworkId, $userId]);
        $currentArtwork = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$currentArtwork) {
            throw new RuntimeException('Artwork not found.');
        }

        $currentSeriesId = $currentArtwork['series_id'] !== null && $currentArtwork['series_id'] !== ''
            ? (int)$currentArtwork['series_id']
            : null;
        $creationNumber = (int)($currentArtwork['series_creation_number'] ?? 0);
        $title = '';
        if ($seriesId !== null) {
            $stmt = $pdo->prepare('SELECT title, published FROM artwork_series WHERE id = ? AND user_id = ? AND status = ? LIMIT 1');
            $stmt->execute([$seriesId, $userId, 'active']);
            $series = $stmt->fetch(PDO::FETCH_ASSOC);
            $title = trim((string)($series['title'] ?? ''));
            if (!$series || $title === '') {
                throw new RuntimeException('Series not found.');
            }
            if ((int)($series['published'] ?? 0) !== 1 && self::artworkIsPublished($pdo, $userId, $artworkId)) {
                throw new RuntimeException('Esta obra está publicada. Publica primero la serie de destino «' . $title . '».');
            }
            if ($currentSeriesId !== $seriesId || $creationNumber <= 0) {
                $creationNumber = self::nextCreationNumber($pdo, $userId, $seriesId);
            }
        } else {
            $creationNumber = 0;
        }

        $stmt = $pdo->prepare('UPDATE artworks SET series_id = ?, series = ?, series_creation_number = ?, updated_at = ? WHERE id = ? AND user_id = ?');
        $stmt->bindValue(1, $seriesId, $seriesId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(2, $title);
        $stmt->bindValue(3, $creationNumber > 0 ? $creationNumber : null, $creationNumber > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(4, date('c'));
        $stmt->bindValue(5, $artworkId, PDO::PARAM_INT);
        $stmt->bindValue(6, $userId, PDO::PARAM_INT);
        $stmt->execute();

        if ($syncMockups) {
            self::syncMockups($pdo, $userId);
        }
    }

    public static function setCreationNumber(PDO $pdo, int $userId, int $artworkId, int $creationNumber): void
    {
        self::ensureSchema($pdo);
        if ($creationNumber <= 0) {
            throw new RuntimeException('Creation ID must be a positive number.');
        }

        $stmt = $pdo->prepare('
            SELECT a.series_id, s.title AS series_title
            FROM artworks a
            LEFT JOIN artwork_series s ON s.id = a.series_id AND s.user_id = a.user_id
            WHERE a.id = ? AND a.user_id = ?
            LIMIT 1
        ');
        $stmt->execute([$artworkId, $userId]);
        $artwork = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$artwork) {
            throw new RuntimeException('Artwork not found.');
        }
        $seriesId = (int)($artwork['series_id'] ?? 0);
        if ($seriesId <= 0) {
            throw new RuntimeException('Assign the artwork to a series before setting its Creation ID.');
        }

        $duplicate = $pdo->prepare('
            SELECT id FROM artworks
            WHERE user_id = ? AND series_id = ? AND series_creation_number = ? AND id <> ?
            LIMIT 1
        ');
        $duplicate->execute([$userId, $seriesId, $creationNumber, $artworkId]);
        if ($duplicate->fetchColumn()) {
            $identifier = self::creationIdentifier((string)$artwork['series_title'], $creationNumber);
            throw new RuntimeException('Creation ID ' . $identifier . ' is already assigned to another artwork.');
        }

        $pdo->prepare('UPDATE artworks SET series_creation_number = ?, updated_at = ? WHERE id = ? AND user_id = ?')
            ->execute([$creationNumber, date('c'), $artworkId, $userId]);
    }

    /**
     * @param array<int,int> $orderedArtworkIds Canonical artworks in their desired visible order.
     * @return array<int,array{number:int,identifier:string,position:int}>
     */
    public static function reorderArtworks(PDO $pdo, int $userId, int $seriesId, array $orderedArtworkIds): array
    {
        self::ensureSchema($pdo);
        if ($seriesId <= 0) {
            throw new RuntimeException('Series is required.');
        }

        $seriesStmt = $pdo->prepare('SELECT title FROM artwork_series WHERE id = ? AND user_id = ? AND status = ? LIMIT 1');
        $seriesStmt->execute([$seriesId, $userId, 'active']);
        $seriesTitle = trim((string)$seriesStmt->fetchColumn());
        if ($seriesTitle === '') {
            throw new RuntimeException('Series not found.');
        }

        $orderedArtworkIds = array_values(array_unique(array_filter(
            array_map('intval', $orderedArtworkIds),
            static fn (int $artworkId): bool => $artworkId > 0
        )));
        if (!$orderedArtworkIds) {
            throw new RuntimeException('No artworks were supplied.');
        }

        $currentStmt = $pdo->prepare('
            SELECT a.id
            FROM artwork_groups g
            INNER JOIN artworks a ON a.id = g.canonical_artwork_id AND a.user_id = g.user_id
            WHERE g.user_id = ? AND g.status = ? AND a.status = ? AND a.series_id = ?
            ORDER BY
                CASE WHEN a.series_creation_number IS NULL THEN 1 ELSE 0 END ASC,
                a.series_creation_number DESC,
                g.created_at DESC,
                a.id DESC
        ');
        $currentStmt->execute([$userId, 'active', 'done', $seriesId]);
        $currentIds = array_map('intval', $currentStmt->fetchAll(PDO::FETCH_COLUMN));
        $currentLookup = array_fill_keys($currentIds, true);
        foreach ($orderedArtworkIds as $artworkId) {
            if (!isset($currentLookup[$artworkId])) {
                throw new RuntimeException('One artwork does not belong to this series.');
            }
        }

        $requestedLookup = array_fill_keys($orderedArtworkIds, true);
        foreach ($currentIds as $artworkId) {
            if (!isset($requestedLookup[$artworkId])) {
                $orderedArtworkIds[] = $artworkId;
            }
        }

        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $update = $pdo->prepare('UPDATE artworks SET series_creation_number = ? WHERE id = ? AND user_id = ? AND series_id = ?');
            $positions = [];
            $artworkCount = count($orderedArtworkIds);
            foreach ($orderedArtworkIds as $index => $artworkId) {
                $creationNumber = ($artworkCount - $index) * 10;
                $update->execute([$creationNumber, $artworkId, $userId, $seriesId]);
                $positions[$artworkId] = [
                    'number' => $creationNumber,
                    'identifier' => self::creationIdentifier($seriesTitle, $creationNumber),
                    'position' => $index + 1,
                ];
            }
            if ($startedTransaction) {
                $pdo->commit();
            }
            return $positions;
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function nextCreationNumber(PDO $pdo, int $userId, int $seriesId): int
    {
        $stmt = $pdo->prepare('SELECT MAX(series_creation_number) FROM artworks WHERE user_id = ? AND series_id = ?');
        $stmt->execute([$userId, $seriesId]);
        $maximum = max(0, (int)$stmt->fetchColumn());
        return $maximum === 0 ? 10 : ((int)floor($maximum / 10) + 1) * 10;
    }

    public static function deleteSeries(PDO $pdo, int $userId, int $seriesId): void
    {
        $stmt = $pdo->prepare("UPDATE artworks SET series_id = NULL, series = '', series_creation_number = NULL, updated_at = ? WHERE user_id = ? AND series_id = ?");
        $stmt->execute([date('c'), $userId, $seriesId]);
        $stmt = $pdo->prepare('UPDATE mockups SET series_id = NULL WHERE user_id = ? AND series_id = ?');
        $stmt->execute([$userId, $seriesId]);
        $stmt = $pdo->prepare('DELETE FROM artwork_series WHERE user_id = ? AND id = ?');
        $stmt->execute([$userId, $seriesId]);
    }
}
