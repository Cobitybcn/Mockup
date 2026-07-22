<?php
declare(strict_types=1);

final class PublicArtistShowcase
{
    public const ARTIST_EMAIL = 'mauriziovalch@gmail.com';
    private const LAST_ARTWORK_COOKIE = 'artworkmockups_last_showcase_artwork';

    /**
     * @return array{id:int,url:string,alt:string,artwork_key?:string}
     */
    public static function background(PDO $pdo, string $fallbackUrl): array
    {
        try {
            $items = self::items($pdo, self::ARTIST_EMAIL);
            if ($items) {
                $previousArtworkKey = trim((string)($_COOKIE[self::LAST_ARTWORK_COOKIE] ?? ''));
                $selected = self::chooseDifferent($items, $previousArtworkKey);
                self::remember((string)$selected['artwork_key']);
                return $selected;
            }
        } catch (Throwable $error) {
            error_log('Public artist showcase unavailable: ' . $error->getMessage());
        }

        return [
            'id' => 0,
            'url' => $fallbackUrl,
            'alt' => 'Professional artwork mockup',
        ];
    }

    /**
     * @return array{primary:array<string,mixed>,secondary:?array<string,mixed>}
     */
    public static function composition(PDO $pdo, string $fallbackUrl): array
    {
        try {
            $items = self::items($pdo, self::ARTIST_EMAIL);
            if ($items) {
                $previousArtworkKey = trim((string)($_COOKIE[self::LAST_ARTWORK_COOKIE] ?? ''));
                $composition = self::chooseComposition($items, $previousArtworkKey);
                self::remember((string)$composition['primary']['artwork_key']);
                return $composition;
            }
        } catch (Throwable $error) {
            error_log('Public artist showcase composition unavailable: ' . $error->getMessage());
        }

        return [
            'primary' => [
                'id' => 0,
                'url' => $fallbackUrl,
                'alt' => 'Professional artwork mockup',
                'label' => 'Curatorial interior mockup',
            ],
            'secondary' => null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array{primary:array<string,mixed>,secondary:?array<string,mixed>}
     */
    public static function chooseComposition(array $items, string $previousArtworkKey): array
    {
        if (!$items) throw new InvalidArgumentException('At least one showcase mockup is required.');

        $contextual = array_values(array_filter(
            $items,
            static fn (array $item): bool => (string)($item['display_role'] ?? 'context') === 'context'
        ));
        $primary = self::chooseDifferent($contextual ?: $items, $previousArtworkKey);

        $differentArtwork = array_values(array_filter(
            $items,
            static fn (array $item): bool => !hash_equals(
                (string)$primary['artwork_key'],
                (string)$item['artwork_key']
            )
        ));
        $detail = array_values(array_filter(
            $differentArtwork,
            static fn (array $item): bool => (string)($item['display_role'] ?? '') === 'detail'
        ));
        $secondary = $detail
            ? $detail[random_int(0, count($detail) - 1)]
            : null;

        return ['primary' => $primary, 'secondary' => $secondary];
    }

    /**
     * @param array<int,array{id:int,url:string,alt:string,artwork_key:string}> $items
     * @return array{id:int,url:string,alt:string,artwork_key:string}
     */
    public static function chooseDifferent(array $items, string $previousArtworkKey): array
    {
        if (!$items) {
            throw new InvalidArgumentException('At least one showcase mockup is required.');
        }

        $candidates = array_values(array_filter(
            $items,
            static fn (array $item): bool => $previousArtworkKey === ''
                || !hash_equals($previousArtworkKey, (string)$item['artwork_key'])
        ));
        if (!$candidates) $candidates = array_values($items);

        return $candidates[random_int(0, count($candidates) - 1)];
    }

    /**
     * The complete mockup collection for the selected artist is available. The
     * optional ID list exists for isolated tests and deliberately narrows it.
     *
     * @param array<int,int>|null $mockupIds
     * @return array<int,array{id:int,url:string,alt:string,file:string,artwork_key:string,label:string,display_role:string}>
     */
    public static function items(PDO $pdo, string $artistEmail, ?array $mockupIds = null): array
    {
        $userStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1');
        $userStmt->execute([trim($artistEmail)]);
        $userId = (int)($userStmt->fetchColumn() ?: 0);
        if ($userId <= 0) return [];

        $requestedIds = $mockupIds === null ? null : array_values(array_unique(array_filter(
            array_map('intval', $mockupIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($requestedIds === []) return [];

        $whereIds = '';
        $parameters = [$userId];
        if ($requestedIds !== null) {
            $marks = implode(',', array_fill(0, count($requestedIds), '?'));
            $whereIds = " AND m.id IN ($marks)";
            $parameters = array_merge($parameters, $requestedIds);
        }

        $stmt = $pdo->prepare("SELECT m.id,m.mockup_file,m.source_artwork_id,m.artwork_file,m.context_id,
                COALESCE(NULLIF(ms.alt_text,''),NULLIF(ms.title,''),NULLIF(a.final_title,''),'Professional artwork mockup') alt_text,
                COALESCE(NULLIF(ms.title,''),NULLIF(a.final_title,''),'Curatorial interior mockup') display_label
            FROM mockups m
            LEFT JOIN artworks a ON a.id=m.source_artwork_id AND a.user_id=m.user_id
            LEFT JOIN mockup_sheets ms ON ms.id=(SELECT MAX(ms2.id) FROM mockup_sheets ms2 WHERE ms2.user_id=m.user_id AND ms2.mockup_file=m.mockup_file)
            WHERE m.user_id=?{$whereIds}
            ORDER BY m.created_at DESC,m.id DESC");
        $stmt->execute($parameters);

        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['id'];
            $file = basename((string)$row['mockup_file']);
            if ($id <= 0 || $file === '') continue;
            $artworkIdentity = (int)$row['source_artwork_id'] > 0
                ? 'id:' . (int)$row['source_artwork_id']
                : 'file:' . strtolower(basename((string)$row['artwork_file']));
            $classificationText = strtolower($file . ' ' . (string)$row['display_label'] . ' ' . (string)$row['context_id']);
            $displayRole = preg_match('/close[\s_-]*up|detail|corner|edge|macro|texture/', $classificationText)
                ? 'detail'
                : 'context';
            $byId[$id] = [
                'id' => $id,
                'url' => 'public_showcase_image.php?mockup_id=' . $id,
                'alt' => trim((string)$row['alt_text']) ?: 'Professional artwork mockup',
                'file' => $file,
                'artwork_key' => hash('sha256', $artworkIdentity),
                'label' => trim((string)$row['display_label']) ?: 'Curatorial interior mockup',
                'display_role' => $displayRole,
            ];
        }

        if ($requestedIds === null) return array_values($byId);
        $items = [];
        foreach ($requestedIds as $id) if (isset($byId[$id])) $items[] = $byId[$id];
        return $items;
    }

    public static function publicFile(PDO $pdo, int $mockupId): string
    {
        if ($mockupId <= 0) return '';
        $stmt = $pdo->prepare('SELECT m.mockup_file
            FROM mockups m JOIN users u ON u.id=m.user_id
            WHERE m.id=? AND LOWER(u.email)=LOWER(?) LIMIT 1');
        $stmt->execute([$mockupId, self::ARTIST_EMAIL]);
        $file = basename((string)($stmt->fetchColumn() ?: ''));
        if ($file === '') return '';

        $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path) && StorageService::isGcsActive()) {
            StorageService::downloadFile('results/' . $file, $path);
        }
        return is_file($path) ? $path : '';
    }

    private static function remember(string $artworkKey): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $artworkKey) || headers_sent() || PHP_SAPI === 'cli') return;
        setcookie(self::LAST_ARTWORK_COOKIE, $artworkKey, [
            'expires' => time() + 30 * 86400,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
