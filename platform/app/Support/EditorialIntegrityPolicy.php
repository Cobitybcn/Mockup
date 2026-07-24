<?php
declare(strict_types=1);

/**
 * Shared editorial guardrail for artwork and mockup generation.
 *
 * The prompt prevents unsupported prestige and investment claims. The quality
 * gate catches explicit violations before generated copy can be saved.
 */
final class EditorialIntegrityPolicy
{
    public static function promptRules(string $entityType): string
    {
        $lengthRules = $entityType === 'mockup'
            ? <<<'TEXT'
- Keep the main mockup or contextual description at 180 words or fewer.
- Keep the website description at 140 words or fewer, Pinterest description at 100 words or fewer, Instagram and Facebook copy at 180 words or fewer, alt text at 90 words or fewer, and captions at 50 words or fewer.
TEXT
            : <<<'TEXT'
- Keep the complete artwork description at 350 words or fewer, the short description at 70 words or fewer, alt text at 90 words or fewer, and captions at 50 words or fewer.
TEXT;

        return <<<TEXT
NON-NEGOTIABLE EDITORIAL INTEGRITY
- Do not present the artwork through unverifiable claims of prestige, hierarchy, investment value or historical importance.
- Never use or paraphrase claims such as "a pivotal work", "a masterpiece", "museum-quality", "gallery-quality", "an important work in the artist's career", "highly collectible", "investment artwork", or "one of the artist's most significant paintings".
- In Spanish, never use or paraphrase claims such as "obra maestra", "obra fundamental", "obra decisiva", "calidad de museo", "calidad de galería", "pieza de inversión", "altamente coleccionable", "una de las obras más importantes del artista", or "un punto de inflexión en su carrera".
- Do not claim that the artwork is among the artist's most important works, marks a career turning point, has museum or gallery quality, is an investment, will increase in value, is especially collectible, has critical recognition, or holds an exceptional or historical position in contemporary art.
- Do not invent awards, exhibitions, institutional validation, commercial demand, critical reception, rarity, exclusivity or historical relevance.
- Promotional language may describe confirmed technique, visible presence, atmosphere, composition, series membership and possible interest to collectors, but it must never attribute unverified value, prestige or importance.
- Use each visual or conceptual observation once. Do not inflate the text by repeating the same idea in different words.
{$lengthRules}
TEXT;
    }

    /**
     * @return list<string>
     */
    public static function issues(array|string $content, string $entityType): array
    {
        if (!in_array($entityType, ['artwork', 'mockup'], true)) {
            return [];
        }

        $issues = [];
        self::inspectValue($content, '', $entityType, $issues);
        return array_values(array_unique($issues));
    }

    /**
     * @param list<string> $issues
     */
    private static function inspectValue(mixed $value, string $path, string $entityType, array &$issues): void
    {
        if (preg_match('/(?:^|\.)(?:claims_to_avoid|warnings)$/i', $path) === 1) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                self::inspectValue(
                    $nested,
                    $path === '' ? (string)$key : $path . '.' . (string)$key,
                    $entityType,
                    $issues
                );
            }
            return;
        }
        if (!is_scalar($value) || trim((string)$value) === '') {
            return;
        }

        $text = trim((string)$value);
        $label = $path !== '' ? $path : 'content';
        foreach (self::forbiddenPatterns() as $pattern => $claim) {
            if (preg_match($pattern, $text) === 1) {
                $issues[] = "{$label}: unsupported {$claim} claim.";
            }
        }

        $limit = self::wordLimit($path, $entityType);
        if ($limit > 0 && self::wordCount($text) > $limit) {
            $issues[] = "{$label}: exceeds {$limit} words.";
        }
    }

    /**
     * @return array<string,string>
     */
    private static function forbiddenPatterns(): array
    {
        return [
            '/\b(?:a\s+)?pivotal\s+(?:work|artwork|painting|piece)\b/iu' => 'career-prestige',
            '/\bmasterpiece\b/iu' => 'masterpiece',
            '/\b(?:museum|gallery)[-\s]+quality\b/iu' => 'institutional-quality',
            '/\b(?:an?\s+)?important\s+work\s+in\s+the\s+artist[’\']?s\s+career\b/iu' => 'career-importance',
            '/\bhighly\s+collectible\b/iu' => 'collectibility',
            '/\binvestment\s+(?:artwork|art|painting|piece)\b/iu' => 'investment',
            '/\bone\s+of\s+the\s+artist[’\']?s\s+most\s+(?:important|significant)\b/iu' => 'career-importance',
            '/\b(?:will|is\s+expected\s+to|likely\s+to)\s+(?:increase|appreciate|rise)\s+in\s+value\b/iu' => 'future-value',
            '/\b(?:critically\s+acclaimed|critical\s+acclaim|critical\s+recognition)\b/iu' => 'critical-recognition',
            '/\b(?:award[-\s]+winning|institutionally\s+(?:recognized|validated))\b/iu' => 'institutional-validation',
            '/\b(?:rare|exclusive)\s+(?:investment|collecting|acquisition)\s+opportunity\b/iu' => 'rarity-or-exclusivity',
            '/\bobra\s+maestra\b/iu' => 'masterpiece',
            '/\bobra\s+(?:fundamental|decisiva)\b/iu' => 'career-importance',
            '/\bcalidad\s+de\s+(?:museo|galer[ií]a)\b/iu' => 'institutional-quality',
            '/\bpieza\s+de\s+inversi[oó]n\b/iu' => 'investment',
            '/\baltamente\s+coleccionable\b/iu' => 'collectibility',
            '/\buna\s+de\s+(?:las\s+)?obras\s+m[aá]s\s+(?:importantes|significativas)\s+del\s+artista\b/iu' => 'career-importance',
            '/\bpunto\s+de\s+inflexi[oó]n\s+en\s+(?:la|su)\s+carrera\b/iu' => 'career-importance',
            '/\b(?:aumentar[aá]|incrementar[aá]|subir[aá]|se\s+revalorizar[aá])\s+(?:de\s+valor|su\s+valor)\b/iu' => 'future-value',
            '/\b(?:aclamad[ao]\s+por\s+la\s+cr[ií]tica|reconocimiento\s+cr[ií]tico)\b/iu' => 'critical-recognition',
            '/\b(?:premiad[ao]|validaci[oó]n\s+institucional)\b/iu' => 'institutional-validation',
            '/\boportunidad\s+(?:rara|exclusiva)\s+de\s+(?:inversi[oó]n|adquisici[oó]n|coleccionismo)\b/iu' => 'rarity-or-exclusivity',
        ];
    }

    private static function wordLimit(string $path, string $entityType): int
    {
        $path = strtolower($path);
        if ($entityType === 'artwork') {
            return match (true) {
                str_ends_with($path, 'master_description'),
                $path === 'description' => 350,
                str_ends_with($path, 'short_description') => 70,
                str_ends_with($path, 'alt_text') => 90,
                str_ends_with($path, 'caption') => 50,
                default => 0,
            };
        }

        return match (true) {
            $path === 'description',
            str_ends_with($path, 'neutral.contextual_description') => 180,
            str_ends_with($path, 'website.description') => 140,
            str_ends_with($path, 'pinterest.description') => 100,
            str_ends_with($path, 'instagram.caption'),
            str_ends_with($path, 'facebook.post_text') => 180,
            str_ends_with($path, 'alt_text') => 90,
            str_ends_with($path, 'caption') => 50,
            default => 0,
        };
    }

    private static function wordCount(string $text): int
    {
        $words = preg_split('/[\s\p{Z}]+/u', trim(strip_tags($text)), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($words) ? count($words) : 0;
    }
}
