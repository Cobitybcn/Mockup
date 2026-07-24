<?php
declare(strict_types=1);

return [
    'description' => 'Derive artwork publication slugs from canonical artwork titles and preserve old aliases',
    'up' => static function (PDO $pdo): void {
        $mysql = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        try {
            $pdo->exec($mysql
                ? "CREATE TABLE IF NOT EXISTS publication_slug_aliases (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    publication_id INT UNSIGNED NOT NULL,
                    slug VARCHAR(255) NOT NULL,
                    created_at VARCHAR(40) NOT NULL,
                    UNIQUE KEY publication_slug_aliases_user_slug_unique (user_id,slug),
                    KEY publication_slug_aliases_publication_idx (publication_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                : "CREATE TABLE IF NOT EXISTS publication_slug_aliases (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    publication_id INTEGER NOT NULL,
                    slug VARCHAR(255) NOT NULL,
                    created_at VARCHAR(40) NOT NULL
                )");
            if (!$mysql) {
                $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS publication_slug_aliases_user_slug_unique
                    ON publication_slug_aliases (user_id,slug)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS publication_slug_aliases_publication_idx
                    ON publication_slug_aliases (publication_id)');
            }
            $rows = $pdo->query("SELECT p.id,p.user_id,p.slug,
                    COALESCE(NULLIF(TRIM(a.final_title),''),NULLIF(TRIM(sh.title),''),NULLIF(TRIM(p.title),'')) canonical_name
                FROM publications p
                INNER JOIN artwork_sheets sh ON sh.id=p.artwork_sheet_id AND sh.user_id=p.user_id
                INNER JOIN artworks a ON a.id=sh.canonical_artwork_id AND a.user_id=sh.user_id
                ORDER BY p.user_id,p.id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return;
        }
        if ($rows === []) return;

        $normalize = static function (string $value): string {
            if (class_exists('PublicSlug')) return PublicSlug::normalize($value);
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($value));
            $value = is_string($ascii) && $ascii !== '' ? $ascii : $value;
            return trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $value)), '-');
        };
        $aliasExists = $pdo->prepare('SELECT 1 FROM publication_slug_aliases WHERE user_id=? AND slug=? LIMIT 1');
        $insertAlias = $pdo->prepare('INSERT INTO publication_slug_aliases
            (user_id,publication_id,slug,created_at) VALUES (?,?,?,?)');
        foreach ($rows as $row) {
            $oldSlug = trim((string)$row['slug']);
            if ($oldSlug === '') continue;
            $aliasExists->execute([(int)$row['user_id'], $oldSlug]);
            if (!$aliasExists->fetchColumn()) {
                $insertAlias->execute([(int)$row['user_id'], (int)$row['id'], $oldSlug, date('c')]);
            }
        }

        $temporary = $pdo->prepare('UPDATE publications SET slug=? WHERE id=?');
        foreach ($rows as $row) {
            $temporary->execute([
                'canonical-slug-migration-' . (int)$row['id'],
                (int)$row['id'],
            ]);
        }

        $used = [];
        $update = $pdo->prepare('UPDATE publications SET slug=? WHERE id=?');
        foreach ($rows as $row) {
            $userId = (int)$row['user_id'];
            $base = $normalize((string)$row['canonical_name']);
            if ($base === '') $base = 'obra-' . (int)$row['id'];
            $slug = $base;
            $suffix = 2;
            while (isset($used[$userId][$slug])) {
                $slug = $base . '-' . $suffix++;
            }
            $used[$userId][$slug] = true;
            $update->execute([$slug, (int)$row['id']]);
        }
    },
];
