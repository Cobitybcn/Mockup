<?php
declare(strict_types=1);

final class ArtworkOriginalityChecker
{
    public static function catalogueTitles(string $analysisDirectory, string $excludeBase = ''): array
    {
        $titles = [];
        foreach (glob(rtrim($analysisDirectory, '/\\') . DIRECTORY_SEPARATOR . '*.analysis.json') ?: [] as $file) {
            if ($excludeBase !== '' && str_starts_with(basename($file), $excludeBase)) continue;
            $data = json_decode((string)file_get_contents($file), true);
            if (!is_array($data)) continue;
            foreach (self::editorialCandidates($data) as $candidate) {
                $title = trim((string)($candidate['title'] ?? ''));
                if ($title !== '') $titles[mb_strtolower($title)] = $title;
            }
        }
        natcasesort($titles);
        return array_values($titles);
    }

    public static function check(array $draft, string $analysisDirectory, string $excludeBase = ''): array
    {
        $title = (string)($draft['canonical_editorial']['title'] ?? '');
        $description = (string)($draft['canonical_editorial']['master_description'] ?? '');
        $bestTitle = ['score'=>0.0,'value'=>null,'file'=>null];
        $bestDescription = ['score'=>0.0,'file'=>null];
        $repeatedOpenings = [];
        $opening = self::opening($description);

        foreach (glob(rtrim($analysisDirectory, '/\\') . DIRECTORY_SEPARATOR . '*.analysis.json') ?: [] as $file) {
            if ($excludeBase !== '' && str_starts_with(basename($file), $excludeBase)) continue;
            $data = json_decode((string)file_get_contents($file), true);
            if (!is_array($data)) continue;
            foreach (self::editorialCandidates($data) as $candidate) {
                $candidateTitle = (string)($candidate['title'] ?? '');
                $candidateDescription = (string)($candidate['description'] ?? '');
                $titleScore = self::similarity($title, $candidateTitle);
                if ($titleScore > $bestTitle['score']) $bestTitle = ['score'=>$titleScore,'value'=>$candidateTitle,'file'=>basename($file)];
                $descriptionScore = self::similarity($description, $candidateDescription);
                if ($descriptionScore > $bestDescription['score']) $bestDescription = ['score'=>$descriptionScore,'file'=>basename($file)];
                if ($opening !== '' && $opening === self::opening($candidateDescription)) $repeatedOpenings[$opening] = true;
            }
        }

        $warnings = [];
        if ($bestTitle['score'] >= 0.72) $warnings[] = 'Title is too similar to an existing catalogue title.';
        if ($bestDescription['score'] >= 0.58) $warnings[] = 'Description is too similar to existing catalogue copy.';
        if ($repeatedOpenings) $warnings[] = 'Description opening already exists in the catalogue.';

        return [
            'catalogue_checked'=>true,
            'title_unique'=>$bestTitle['score'] < 0.72,
            'closest_title'=>$bestTitle['value'],
            'title_similarity'=>round($bestTitle['score'], 4),
            'closest_title_source'=>$bestTitle['file'],
            'closest_description_artwork_id'=>null,
            'closest_description_source'=>$bestDescription['file'],
            'description_similarity'=>round($bestDescription['score'], 4),
            'repeated_openings'=>array_keys($repeatedOpenings),
            'repeated_phrases'=>[],
            'structure_used'=>'artwork-analysis.v2',
            'warnings'=>$warnings,
            'passed'=>$warnings === [],
        ];
    }

    private static function editorialCandidates(array $data): array
    {
        $out = [];
        $walk = function (array $node) use (&$walk, &$out): void {
            if (isset($node['suggested_titles']) && is_array($node['suggested_titles'])) {
                foreach ($node['suggested_titles'] as $item) if (is_array($item)) $out[] = ['title'=>$item['title']??'','description'=>$item['description']??$item['curatorial_description']??$item['short_description']??''];
            }
            if (isset($node['canonical_editorial']) && is_array($node['canonical_editorial'])) $out[] = ['title'=>$node['canonical_editorial']['title']??'','description'=>$node['canonical_editorial']['master_description']??''];
            foreach ($node as $value) if (is_array($value)) $walk($value);
        };
        $walk($data);
        return $out;
    }

    private static function similarity(string $a, string $b): float
    {
        $a = self::tokens($a); $b = self::tokens($b);
        if (!$a || !$b) return 0.0;
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union ? $intersection / $union : 0.0;
    }

    private static function tokens(string $value): array
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '';
        return array_values(array_unique(array_filter(preg_split('/\s+/', trim($value)) ?: [], static fn(string $v): bool => mb_strlen($v) > 2)));
    }

    private static function opening(string $value): string
    {
        return implode(' ', array_slice(self::tokens($value), 0, 7));
    }
}
