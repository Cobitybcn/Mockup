<?php
declare(strict_types=1);

final class MockupFavorites
{
    /**
     * @return array<int,int>
     */
    public static function idsForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT mockup_id FROM mockup_favorites WHERE user_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$userId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @return array<int,bool>
     */
    public static function lookupForUser(int $userId): array
    {
        return array_fill_keys(self::idsForUser($userId), true);
    }

    /**
     * Favorites always lead an artwork's related mockups. Existing visual
     * grouping and recency rules are preserved inside each favorite bucket.
     *
     * @param array<int,array<string,mixed>> $mockups
     * @return array<int,array<string,mixed>>
     */
    public static function orderForArtworkDisplay(array $mockups): array
    {
        usort($mockups, static function (array $a, array $b): int {
            $favoriteOrder = (!empty($b['is_favorite']) ? 1 : 0) <=> (!empty($a['is_favorite']) ? 1 : 0);
            if ($favoriteOrder !== 0) {
                return $favoriteOrder;
            }

            $closeViewOrder = (!empty($a['is_close_view']) ? 1 : 0) <=> (!empty($b['is_close_view']) ? 1 : 0);
            if ($closeViewOrder !== 0) {
                return $closeViewOrder;
            }

            $dateOrder = strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
            return $dateOrder !== 0 ? $dateOrder : ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
        });

        return array_values($mockups);
    }

    public static function removeForUser(int $userId, int $mockupId): void
    {
        if ($userId <= 0 || $mockupId <= 0) {
            return;
        }

        Database::connection()
            ->prepare('DELETE FROM mockup_favorites WHERE user_id = ? AND mockup_id = ?')
            ->execute([$userId, $mockupId]);
    }

    /**
     * @return array{favorite:bool,favorites:array<int,int>}
     */
    public static function toggle(PDO $pdo, int $userId, int $mockupId): array
    {
        if ($mockupId <= 0) {
            throw new InvalidArgumentException('Missing mockup id.');
        }

        $stmt = $pdo->prepare('SELECT id FROM mockups WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute([
            'id' => $mockupId,
            'user_id' => $userId,
        ]);
        if (!$stmt->fetch()) {
            throw new RuntimeException('Mockup not found.');
        }

        $existing = $pdo->prepare('SELECT id FROM mockup_favorites WHERE user_id = ? AND mockup_id = ? LIMIT 1');
        $existing->execute([$userId, $mockupId]);
        $favorite = !$existing->fetch();

        if ($favorite) {
            $pdo->prepare('INSERT INTO mockup_favorites (user_id, mockup_id, created_at) VALUES (?, ?, ?)')
                ->execute([$userId, $mockupId, date(DATE_ATOM)]);
        } else {
            $pdo->prepare('DELETE FROM mockup_favorites WHERE user_id = ? AND mockup_id = ?')
                ->execute([$userId, $mockupId]);
        }

        return [
            'favorite' => $favorite,
            'favorites' => self::idsForUser($userId),
        ];
    }
}
