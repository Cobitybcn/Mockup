<?php
declare(strict_types=1);

final class AppPublishedStudioNotes
{
    public function __construct(private readonly PDO $pdo, private readonly string $artistEmail) {}

    public static function fromApp(string $appRoot, string $artistEmail): self
    {
        return new self(artist_site_database_connection($appRoot), $artistEmail);
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare("
            SELECT sc.* 
            FROM social_campaigns sc
            JOIN users u ON u.id = sc.user_id
            WHERE LOWER(u.email) = ? AND sc.status = 'published'
            ORDER BY sc.updated_at DESC, sc.id DESC
        ");
        $statement->execute([$this->artistEmail]);
        
        $notes = [];
        foreach ($statement as $row) {
            $payload = json_decode((string)$row['payload_json'], true);
            if (is_array($payload) && in_array('website_blog', array_map('strval', (array)($payload['channels'] ?? [])), true)) {
                // New Website Board notes may begin from a series, artwork or mockup.
                // Keep the historical mockup_ids path so previously published notes remain valid.
                $mediaFiles = [];
                foreach ((array)($payload['media'] ?? []) as $media) {
                    if (!is_array($media)) continue;
                    $file = basename((string)($media['file'] ?? ''));
                    if ($file !== '' && !in_array($file, $mediaFiles, true)) $mediaFiles[] = $file;
                }
                if (!$mediaFiles) {
                    $mockupIds = array_values(array_filter(array_map('intval', (array)($payload['mockup_ids'] ?? []))));
                    if ($mockupIds) {
                        $marks = implode(',', array_fill(0, count($mockupIds), '?'));
                        $mStmt = $this->pdo->prepare("SELECT mockup_file FROM mockups WHERE user_id = ? AND id IN ($marks)");
                        $mStmt->execute(array_merge([$row['user_id']], $mockupIds));
                        $mediaFiles = array_values(array_filter(array_map('basename', $mStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
                    }
                }

                $row['source'] = is_array($payload['source'] ?? null) ? $payload['source'] : null;
                $row['media_files'] = $mediaFiles;
                $objective = (string)$row['objective'];
                $row['has_embedded_image'] = stripos($objective, 'data:image/jpeg;base64,') !== false
                    || stripos($objective, 'data:image/png;base64,') !== false
                    || stripos($objective, 'data:image/webp;base64,') !== false;
                // The current public templates read mockup_files; expose the generic media list there too.
                $row['mockup_files'] = $mediaFiles;
                $slug = $this->slug($row['title']) . '-' . (int)$row['id'];
                $notes[$slug] = $row;
            }
        }
        return $notes;
    }

    private function slug(string $title): string
    {
        $slug = strtolower($title);
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug) ?: $slug;
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

}
