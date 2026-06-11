<?php
declare(strict_types=1);

class ArtistProfile
{
    public static function findForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT *
            FROM artist_profiles
            WHERE user_id = :user_id
            LIMIT 1
        ');
        $stmt->execute(['user_id' => $userId]);

        $profile = $stmt->fetch();

        return is_array($profile) ? $profile : self::empty($userId);
    }

    public static function saveForUser(int $userId, array $input): void
    {
        $now = date('c');
        $profile = self::sanitize($input);
        $profile['user_id'] = $userId;
        $profile['updated_at'] = $now;

        $existing = self::findForUser($userId);

        if (!empty($existing['id'])) {
            $profile['id'] = (int)$existing['id'];
            $stmt = Database::connection()->prepare('
                UPDATE artist_profiles
                SET artist_name = :artist_name,
                    short_bio = :short_bio,
                    statement = :statement,
                    visual_language = :visual_language,
                    materials = :materials,
                    recurring_themes = :recurring_themes,
                    palette_notes = :palette_notes,
                    target_audience = :target_audience,
                    preferred_regions = :preferred_regions,
                    preferred_contexts = :preferred_contexts,
                    forbidden_contexts = :forbidden_contexts,
                    commercial_positioning = :commercial_positioning,
                    updated_at = :updated_at
                WHERE id = :id
                AND user_id = :user_id
            ');
            $stmt->execute($profile);
            return;
        }

        $profile['created_at'] = $now;
        $stmt = Database::connection()->prepare('
            INSERT INTO artist_profiles (
                user_id, artist_name, short_bio, statement, visual_language, materials,
                recurring_themes, palette_notes, target_audience, preferred_regions,
                preferred_contexts, forbidden_contexts, commercial_positioning, created_at, updated_at
            ) VALUES (
                :user_id, :artist_name, :short_bio, :statement, :visual_language, :materials,
                :recurring_themes, :palette_notes, :target_audience, :preferred_regions,
                :preferred_contexts, :forbidden_contexts, :commercial_positioning, :created_at, :updated_at
            )
        ');
        $stmt->execute($profile);
    }

    public static function forPrompt(array $profile): string
    {
        $parts = [];
        $fields = [
            'artist_name' => 'Artist name',
            'short_bio' => 'Short bio',
            'statement' => 'Statement',
            'visual_language' => 'Visual language',
            'materials' => 'Materials and process',
            'recurring_themes' => 'Recurring themes',
            'palette_notes' => 'Palette notes',
            'target_audience' => 'Target audience',
            'preferred_regions' => 'Preferred regions',
            'preferred_contexts' => 'Preferred contexts',
            'forbidden_contexts' => 'Forbidden contexts',
            'commercial_positioning' => 'Commercial positioning',
        ];

        foreach ($fields as $key => $label) {
            $value = trim((string)($profile[$key] ?? ''));

            if ($value !== '') {
                $parts[] = $label . ': ' . $value;
            }
        }

        return implode("\n", $parts);
    }

    public static function hasContent(array $profile): bool
    {
        foreach (self::fields() as $field) {
            if (trim((string)($profile[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    public static function fields(): array
    {
        return [
            'artist_name',
            'short_bio',
            'statement',
            'visual_language',
            'materials',
            'recurring_themes',
            'palette_notes',
            'target_audience',
            'preferred_regions',
            'preferred_contexts',
            'forbidden_contexts',
            'commercial_positioning',
        ];
    }

    private static function sanitize(array $input): array
    {
        $profile = [];

        foreach (self::fields() as $field) {
            $profile[$field] = trim((string)($input[$field] ?? ''));
        }

        return $profile;
    }

    private static function empty(int $userId): array
    {
        $profile = [
            'id' => null,
            'user_id' => $userId,
            'created_at' => '',
            'updated_at' => '',
        ];

        foreach (self::fields() as $field) {
            $profile[$field] = '';
        }

        return $profile;
    }
}
