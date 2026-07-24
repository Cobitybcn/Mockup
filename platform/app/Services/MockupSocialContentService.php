<?php
declare(strict_types=1);

/**
 * Read-only bridge between the bilingual mockup sheet and its social consumers.
 *
 * Spanish remains the editorial source. Social Media uses the reviewed
 * international English adaptation and falls back to legacy analysis only when
 * a historical mockup has no bilingual row yet.
 */
final class MockupSocialContentService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{content:array,status:string} */
    public function forMockup(int $userId, int $mockupId, string $locale = 'en'): array
    {
        return $this->forMockups($userId, [$mockupId], $locale)[$mockupId]
            ?? ['content' => [], 'status' => 'unprepared'];
    }

    /** @return array<int,array{content:array,status:string}> */
    public function forMockups(int $userId, array $mockupIds, string $locale = 'en'): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $mockupIds), static fn (int $id): bool => $id > 0)));
        if ($userId <= 0 || !$ids || !in_array($locale, ['es', 'en'], true)) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $this->pdo->prepare(
                "SELECT entity_id,content_json,status
                 FROM bilingual_editorial_content
                 WHERE user_id=? AND entity_type='mockup' AND locale=? AND entity_id IN ({$marks})"
            );
            $stmt->execute(array_merge([$userId, $locale], $ids));
        } catch (PDOException) {
            return [];
        }

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $content = json_decode((string)($row['content_json'] ?? ''), true);
            $result[(int)$row['entity_id']] = [
                'content' => is_array($content) ? $content : [],
                'status' => trim((string)($row['status'] ?? 'unprepared')),
            ];
        }
        return $result;
    }

    public static function text(mixed $preferred, mixed $fallback = ''): string
    {
        $value = trim((string)$preferred);
        return $value !== '' ? $value : trim((string)$fallback);
    }

    public static function list(mixed $preferred, mixed $fallback = []): array
    {
        $value = self::normalizeList($preferred);
        return $value ?: self::normalizeList($fallback);
    }

    private static function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $stringValue = trim((string)$value);
            if (str_starts_with($stringValue, '#') && preg_match_all('/#[\p{L}\p{N}_]+/u', $stringValue, $matches)) {
                $items = $matches[0];
                $stringValue = '';
            }
            $decoded = json_decode($stringValue, true);
            $items = is_array($decoded)
                ? $decoded
                : ($items ?? (preg_split('/[,;|\n]+/', $stringValue) ?: []));
        }
        $items = array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string)$item),
            $items
        )));
        return array_values(array_unique($items));
    }
}
