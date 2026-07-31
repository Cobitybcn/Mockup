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
        $lengthRules = $entityType === 'studio_note'
            ? <<<'TEXT'
- Keep the excerpt at 70 words or fewer, SEO description at 35 words or fewer, alt text at 90 words or fewer, and captions at 50 words or fewer.
TEXT
            : ($entityType === 'mockup'
            ? <<<'TEXT'
- Keep the main mockup or contextual description at 180 words or fewer.
- Keep the website description at 140 words or fewer, Pinterest description at 100 words or fewer, Instagram and Facebook copy at 180 words or fewer, alt text at 90 words or fewer, and captions at 50 words or fewer.
TEXT
            : <<<'TEXT'
- Keep the complete artwork description at 350 words or fewer, the short description at 70 words or fewer, alt text at 90 words or fewer, and captions at 50 words or fewer.
TEXT);

        return <<<TEXT
NON-NEGOTIABLE EDITORIAL INTEGRITY
- Do not present the artwork through unverifiable claims of prestige, hierarchy, investment value or historical importance.
- Never use or paraphrase claims such as "a pivotal work", "a masterpiece", "museum-quality", "gallery-quality", "an important work in the artist's career", "highly collectible", "investment artwork", or "one of the artist's most significant paintings".
- In Spanish, never use or paraphrase claims such as "obra maestra", "obra fundamental", "obra decisiva", "calidad de museo", "calidad de galería", "pieza de inversión", "altamente coleccionable", "una de las obras más importantes del artista", or "un punto de inflexión en su carrera".
- Do not claim that the artwork is among the artist's most important works, marks a career turning point, has museum or gallery quality, is an investment, will increase in value, is especially collectible, has critical recognition, or holds an exceptional or historical position in contemporary art.
- Do not invent awards, exhibitions, institutional validation, commercial demand, critical reception, rarity, exclusivity or historical relevance.
- Promotional language may describe confirmed technique, visible presence, atmosphere, composition, series membership and possible interest to collectors, but it must never attribute unverified value, prestige or importance.
- Use each visual or conceptual observation once. Do not inflate the text by repeating the same idea in different words.
- Never open any public description with a command to the reader: no "Discover", "Explore", "Acquire", "Buy", "Descubre", "Explora", "Adquiere" or "Compra". This applies to every channel, not only to the SEO description.
- No two public texts may open with the same phrase — not across the channels of one image, not against the artwork's own reading, and not against the other images of the same artwork. Readers see only the first line before the feed truncates it, so a repeated opening reads as a repeated text.
{$lengthRules}
TEXT;
    }

    /**
     * EDITORIAL_CORE Libro II Cap. 3 (enmienda 2026-07-31): the opening of every
     * public description is compared against the artwork's own reading and its
     * sibling mockups. Callers collect the reference with this helper.
     *
     * @return list<string>
     */
    public static function openings(array|string $content): array
    {
        $openings = [];
        self::collectOpenings($content, '', $openings);
        return array_values(array_unique(array_filter($openings)));
    }

    /**
     * @param list<string> $forbiddenOpenings openings already used by the artwork
     *                                        or by a sibling of this entity
     * @return list<string>
     */
    public static function issues(array|string $content, string $entityType, array $forbiddenOpenings = []): array
    {
        if (!in_array($entityType, ['artwork', 'mockup', 'studio_note'], true)) {
            return [];
        }

        $issues = [];
        $forbidden = [];
        foreach ($forbiddenOpenings as $opening) {
            $key = is_string($opening) ? trim($opening) : '';
            if ($key !== '') $forbidden[$key] = true;
        }
        $seen = [];
        self::inspectValue($content, '', $entityType, $issues, $forbidden, $seen);
        return array_values(array_unique($issues));
    }

    /**
     * @param list<string> $issues
     * @param array<string,bool> $forbiddenOpenings
     * @param array<string,string> $seenOpenings opening key => path that claimed it
     */
    private static function inspectValue(
        mixed $value,
        string $path,
        string $entityType,
        array &$issues,
        array $forbiddenOpenings = [],
        array &$seenOpenings = []
    ): void {
        if (preg_match('/(?:^|\.)(?:claims_to_avoid|warnings)$/i', $path) === 1) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                self::inspectValue(
                    $nested,
                    $path === '' ? (string)$key : $path . '.' . (string)$key,
                    $entityType,
                    $issues,
                    $forbiddenOpenings,
                    $seenOpenings
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

        // EDITORIAL_CORE Libro II Cap. 3 (enmienda 2026-07-29): las aperturas
        // genericas y los verbos de accion comercial se vigilan en TODA ruta
        // de generacion y adaptacion, en ambos idiomas — no solo en el
        // analisis ingles.
        // Enmienda 2026-07-31: el alcance dejo de ser `seo_description` y pasa a
        // ser TODA descripcion publica de TODO medio. Lo que se pide solo en el
        // prompt el modelo lo ignora — por eso se valida y se rechaza aqui.
        if (self::isPublicCopyPath($path)) {
            $genericOpening = '/^(?:this|in\s+this|esta|en\s+esta|este|en\s+este)\s+(?:contemporary\s+|original\s+|abstract\s+|striking\s+|vibrante\s+)*(?:painting|artwork|work|composition|piece|pintura|obra|cuadro|composici[oó]n|pieza|lienzo)\b/iu';
            if (preg_match($genericOpening, $text) === 1) {
                $issues[] = "{$label}: generic opening — begin with concrete visible evidence, not an object label.";
            }
            if (preg_match('/^(?:descubr[ae]|explor[ae]|discover|explore|adquier[ae]|adquiera|compr[eaá]|buy|acquire)\b/iu', $text) === 1) {
                $issues[] = "{$label}: generic action-verb opening — identify the work, do not command the reader.";
            }
            $opening = self::openingKey($text);
            if ($opening !== '') {
                if (isset($forbiddenOpenings[$opening])) {
                    $issues[] = "{$label}: opening already used by this artwork or by another of its images — open on a different observation.";
                } elseif (isset($seenOpenings[$opening])) {
                    $issues[] = "{$label}: opens exactly like {$seenOpenings[$opening]} — every public text needs its own first line.";
                } else {
                    $seenOpenings[$opening] = $label;
                }
            }
        }

        $limit = self::wordLimit($path, $entityType);
        if ($limit > 0 && self::wordCount($text) > $limit) {
            $issues[] = "{$label}: exceeds {$limit} words.";
        }
    }

    /**
     * Public prose that a reader or a search engine actually sees. Titles,
     * tags and keyword lists stay out: there the stable repetition of correct
     * commercial vocabulary is desirable (Libro III Cap. 3).
     */
    private static function isPublicCopyPath(string $path): bool
    {
        $path = strtolower($path);
        if ($path === '') return false;
        if (str_ends_with($path, 'description')) return true;
        foreach (['instagram.caption', 'facebook.post_text', 'tiktok.caption_seed'] as $suffix) {
            if (str_ends_with($path, $suffix)) return true;
        }
        return false;
    }

    /**
     * The comparable opening of a text: the first eight words, lowercased and
     * stripped of accents and punctuation. Two texts sharing it read as the
     * same text in a feed, which truncates everything after the first line.
     */
    public static function openingKey(string $text): string
    {
        $text = strtolower(trim(strip_tags($text)));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        if (is_string($ascii) && $ascii !== '') $text = strtolower($ascii);
        $text = preg_replace('/[^a-z0-9\s]+/', ' ', $text) ?? '';
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 4) return '';
        return implode(' ', array_slice($words, 0, 8));
    }

    /**
     * @param list<string> $openings
     */
    private static function collectOpenings(mixed $value, string $path, array &$openings): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                self::collectOpenings($nested, $path === '' ? (string)$key : $path . '.' . (string)$key, $openings);
            }
            return;
        }
        if (!is_scalar($value) || !self::isPublicCopyPath($path)) return;
        $openings[] = self::openingKey((string)$value);
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
            // EDITORIAL_CORE Libro II Cap. 3 (enmienda 2026-07-29): vocabulario
            // decorativo/masivo prohibido en TODO texto generado o adaptado —
            // antes solo lo vigilaba el analisis de obra y "wall art" se colo
            // por la via de adaptacion.
            '/\b(?:gallery\s+)?wall\s+art\b/iu' => 'decorative-mass-market',
            '/\bhome\s+decor\b/iu' => 'decorative-mass-market',
            '/\bstatement\s+decor\b/iu' => 'decorative-mass-market',
            '/\bperfect\s+for\s+any\s+room\b/iu' => 'decorative-mass-market',
            '/\belevate\s+your\s+space\b/iu' => 'decorative-mass-market',
            '/\bdecor\s+inspiration\b/iu' => 'decorative-mass-market',
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

        if ($entityType === 'studio_note') {
            return match (true) {
                $path === 'excerpt' => 70,
                $path === 'seo_description' => 35,
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
