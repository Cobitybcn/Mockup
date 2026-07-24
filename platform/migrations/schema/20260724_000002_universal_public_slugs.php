<?php
declare(strict_types=1);

return [
    'description' => 'Derive universal series and artwork slugs from their canonical names',
    'up' => static function (PDO $pdo): void {
        $normalize = static function (string $value): string {
            if (class_exists('PublicSlug')) return PublicSlug::normalize($value);
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($value));
            $value = is_string($ascii) && $ascii !== '' ? $ascii : $value;
            return trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $value)), '-');
        };

        $backfill = static function (string $table, string $nameColumn, string $fallback) use ($pdo, $normalize): void {
            try {
                $rows = $pdo->query("SELECT id,user_id,{$nameColumn} canonical_name FROM {$table} ORDER BY user_id,id")
                    ->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException) {
                return;
            }
            if ($rows === []) return;

            $temporary = $pdo->prepare("UPDATE {$table} SET slug=? WHERE id=?");
            foreach ($rows as $row) {
                $temporary->execute([
                    'slug-migration-' . (int)$row['id'] . '-' . substr(hash('sha256', $table . ':' . (int)$row['id']), 0, 12),
                    (int)$row['id'],
                ]);
            }

            $used = [];
            $update = $pdo->prepare("UPDATE {$table} SET slug=? WHERE id=?");
            foreach ($rows as $row) {
                $userId = (int)$row['user_id'];
                $base = $normalize((string)$row['canonical_name']);
                if ($base === '') $base = $fallback . '-' . (int)$row['id'];
                $slug = $base;
                $suffix = 2;
                while (isset($used[$userId][$slug])) {
                    $slug = $base . '-' . $suffix++;
                }
                $used[$userId][$slug] = true;
                $update->execute([$slug, (int)$row['id']]);
            }
        };

        $backfill('artwork_series', 'title', 'serie');
        $backfill('publications', 'title', 'obra');
    },
];
