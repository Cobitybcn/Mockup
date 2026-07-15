<?php
declare(strict_types=1);

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
            self::addColumnIfMissing($pdo, 'mockups', 'series_id', 'INT UNSIGNED NULL');
            self::addColumnIfMissing($pdo, 'artwork_series', 'subtitle', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
            self::addColumnIfMissing($pdo, 'artwork_series', 'long_description', 'MEDIUMTEXT NULL');
            self::addColumnIfMissing($pdo, 'artwork_series', 'keywords', 'TEXT NULL');
            self::addColumnIfMissing($pdo, 'artwork_series', 'tags', 'TEXT NULL');
            self::addColumnIfMissing($pdo, 'artwork_series', 'seo_description', 'VARCHAR(500) NOT NULL DEFAULT \'\'');
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
        self::addColumnIfMissing($pdo, 'mockups', 'series_id', 'INTEGER NULL');
        self::addColumnIfMissing($pdo, 'artwork_series', 'subtitle', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'artwork_series', 'long_description', 'TEXT');
        self::addColumnIfMissing($pdo, 'artwork_series', 'keywords', 'TEXT');
        self::addColumnIfMissing($pdo, 'artwork_series', 'tags', 'TEXT');
        self::addColumnIfMissing($pdo, 'artwork_series', 'seo_description', "TEXT NOT NULL DEFAULT ''");
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
        $slug = strtolower(self::normalizeTitle($title));
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug) ?: $slug;
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'serie';
    }

    public static function display(?string $title): string
    {
        return self::normalizeTitle((string)$title);
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
        $requestedSlug = trim((string)($fields['slug'] ?? ''));
        $slug = self::uniqueSlug($pdo, $userId, $requestedSlug !== '' ? $requestedSlug : $title, $seriesId);
        [$yearStart, $yearEnd] = self::normalizeYearRange($fields['year_start'] ?? '', $fields['year_end'] ?? '');
        $now = date('c');
        $stmt = $pdo->prepare('UPDATE artwork_series SET title=?, slug=?, subtitle=?, description=?, long_description=?, tags=?, keywords=?, seo_description=?, year_start=?, year_end=?, updated_at=? WHERE id=? AND user_id=?');
        $stmt->execute([
            $title, $slug,
            trim((string)($fields['subtitle'] ?? '')),
            trim((string)($fields['description'] ?? '')),
            trim((string)($fields['long_description'] ?? '')),
            trim((string)($fields['tags'] ?? '')),
            trim((string)($fields['keywords'] ?? '')),
            trim((string)($fields['seo_description'] ?? '')),
            $yearStart, $yearEnd,
            $now, $seriesId, $userId,
        ]);
        $stmt = $pdo->prepare('UPDATE artworks SET series = ? WHERE user_id = ? AND series_id = ?');
        $stmt->execute([$title, $userId, $seriesId]);
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

        $stmt = $pdo->prepare("UPDATE artworks SET series_id = NULL, series = '' WHERE user_id = ? AND UPPER(TRIM(series)) = 'NO SERIE'");
        $stmt->execute([$userId]);

        self::syncMockups($pdo, $userId);
    }

    public static function syncMockups(PDO $pdo, int $userId): void
    {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare('SELECT id, root_file, main_file, series_id FROM artworks WHERE user_id = ?');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $artwork) {
            $seriesId = $artwork['series_id'] !== null && $artwork['series_id'] !== '' ? (int)$artwork['series_id'] : null;
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
        if (trim((string)($series['description'] ?? '')) === '') $missing[] = 'short description';
        return $missing;
    }

    public static function setPublished(PDO $pdo, int $userId, int $seriesId, bool $published): void
    {
        if ($published) {
            $stmt = $pdo->prepare('SELECT * FROM artwork_series WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$seriesId, $userId]);
            $series = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$series) throw new RuntimeException('Series not found.');
            $missing = self::missingForPublish($series);
            if ($missing) throw new RuntimeException('Cannot publish. Missing: ' . implode(', ', $missing) . '.');
        }
        $pdo->prepare('UPDATE artwork_series SET published = ?, updated_at = ? WHERE id = ? AND user_id = ?')
            ->execute([$published ? 1 : 0, date('c'), $seriesId, $userId]);
    }

    public static function seriesList(PDO $pdo, int $userId): array
    {
        self::syncUser($pdo, $userId);
        $stmt = $pdo->prepare('
            SELECT s.*,
                   (SELECT COUNT(*) FROM artworks a WHERE a.user_id = s.user_id AND a.series_id = s.id) AS artwork_count,
                   (SELECT COUNT(*) FROM mockups m WHERE m.user_id = s.user_id AND m.series_id = s.id) AS mockup_count
            FROM artwork_series s
            WHERE s.user_id = ? AND s.status = ?
            ORDER BY s.title ASC
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
        $title = '';
        if ($seriesId !== null) {
            $stmt = $pdo->prepare('SELECT title FROM artwork_series WHERE id = ? AND user_id = ? AND status = ? LIMIT 1');
            $stmt->execute([$seriesId, $userId, 'active']);
            $title = (string)$stmt->fetchColumn();
            if ($title === '') {
                throw new RuntimeException('Series not found.');
            }
        }

        $stmt = $pdo->prepare('UPDATE artworks SET series_id = ?, series = ?, updated_at = ? WHERE id = ? AND user_id = ?');
        $stmt->bindValue(1, $seriesId, $seriesId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(2, $title);
        $stmt->bindValue(3, date('c'));
        $stmt->bindValue(4, $artworkId, PDO::PARAM_INT);
        $stmt->bindValue(5, $userId, PDO::PARAM_INT);
        $stmt->execute();

        if ($syncMockups) {
            self::syncMockups($pdo, $userId);
        }
    }

    public static function deleteSeries(PDO $pdo, int $userId, int $seriesId): void
    {
        $stmt = $pdo->prepare("UPDATE artworks SET series_id = NULL, series = '', updated_at = ? WHERE user_id = ? AND series_id = ?");
        $stmt->execute([date('c'), $userId, $seriesId]);
        $stmt = $pdo->prepare('UPDATE mockups SET series_id = NULL WHERE user_id = ? AND series_id = ?');
        $stmt->execute([$userId, $seriesId]);
        $stmt = $pdo->prepare('DELETE FROM artwork_series WHERE user_id = ? AND id = ?');
        $stmt->execute([$userId, $seriesId]);
    }
}
