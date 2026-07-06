<?php
declare(strict_types=1);

final class MockupFavorites
{
    /**
     * @return array<int,int>
     */
    public static function idsForUser(int $userId): array
    {
        $path = self::path($userId);
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $decoded),
            static fn (int $id): bool => $id > 0
        )));
    }

    /**
     * @return array<int,bool>
     */
    public static function lookupForUser(int $userId): array
    {
        return array_fill_keys(self::idsForUser($userId), true);
    }

    public static function removeForUser(int $userId, int $mockupId): void
    {
        if ($userId <= 0 || $mockupId <= 0) {
            return;
        }

        $favorites = array_values(array_filter(
            self::idsForUser($userId),
            static fn (int $id): bool => $id !== $mockupId
        ));

        self::write($userId, $favorites);
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

        $favorites = self::idsForUser($userId);
        $favorite = !in_array($mockupId, $favorites, true);
        if ($favorite) {
            array_unshift($favorites, $mockupId);
        } else {
            $favorites = array_values(array_filter($favorites, static fn (int $id): bool => $id !== $mockupId));
        }
        $favorites = array_values(array_unique(array_filter($favorites, static fn (int $id): bool => $id > 0)));

        self::write($userId, $favorites);

        return [
            'favorite' => $favorite,
            'favorites' => $favorites,
        ];
    }

    /**
     * @param array<int,int> $ids
     */
    private static function write(int $userId, array $ids): void
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'mockup_favorites';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create mockup favorites directory.');
        }

        file_put_contents(
            self::path($userId),
            json_encode(array_values($ids), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private static function path(int $userId): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'mockup_favorites' . DIRECTORY_SEPARATOR . 'user_' . $userId . '.json';
    }
}
