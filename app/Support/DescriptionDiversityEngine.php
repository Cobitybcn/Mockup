<?php
declare(strict_types=1);

final class DescriptionDiversityEngine
{
    private const TYPES = ['composition','color','material','atmosphere','territory','symbol','movement','light','process','viewer','scale','contrast','detail','negative_space','architecture'];
    private const RHYTHMS = ['short_then_long','one_long_sentence','two_medium_sentences','long_then_short','direct_observation'];
    private const STRUCTURES = ['visual_material_conceptual_spatial','atmosphere_composition_symbol_process','detail_expansion_interpretation','process_surface_depth_presence','contrast_tension_viewer','color_rhythm_territory_contemplation'];

    public static function select(array $context, array $historyDirectories, string $seed): array
    {
        $history = self::history($historyDirectories, $seed);
        $typeCounts = array_fill_keys(self::TYPES, 0);
        $rhythmCounts = array_fill_keys(self::RHYTHMS, 0);
        $structureCounts = array_fill_keys(self::STRUCTURES, 0);
        foreach (array_slice($history, -50) as $item) {
            if (isset($typeCounts[$item['type']])) $typeCounts[$item['type']]++;
            if (isset($rhythmCounts[$item['rhythm']])) $rhythmCounts[$item['rhythm']]++;
            if (isset($structureCounts[$item['structure']])) $structureCounts[$item['structure']]++;
        }

        $eligible = self::eligibleTypes($context);
        usort($eligible, static function (string $a, string $b) use ($typeCounts, $seed): int {
            $count = $typeCounts[$a] <=> $typeCounts[$b];
            return $count !== 0 ? $count : strcmp(hash('sha256', $seed . ':' . $a), hash('sha256', $seed . ':' . $b));
        });
        $rhythms = self::RHYTHMS;
        usort($rhythms, static function (string $a, string $b) use ($rhythmCounts, $seed): int {
            $count = $rhythmCounts[$a] <=> $rhythmCounts[$b];
            return $count !== 0 ? $count : strcmp(hash('sha256', $seed . ':rhythm:' . $a), hash('sha256', $seed . ':rhythm:' . $b));
        });
        $structures = self::eligibleStructures($context);
        usort($structures, static function (string $a, string $b) use ($structureCounts, $seed): int {
            $count = $structureCounts[$a] <=> $structureCounts[$b];
            return $count !== 0 ? $count : strcmp(hash('sha256', $seed . ':structure:' . $a), hash('sha256', $seed . ':structure:' . $b));
        });

        return [
            'description_opening_type' => $eligible[0] ?? 'composition',
            'description_opening_rhythm' => $rhythms[0] ?? 'direct_observation',
            'description_structure_type' => $structures[0] ?? 'visual_material_conceptual_spatial',
            'recent_opening_types_to_avoid' => self::mostUsed($typeCounts, 4),
            'selection_basis' => ['history_window'=>min(50, count($history)),'eligible_types'=>$eligible,'eligible_structures'=>$structures,'type_usage'=>$typeCounts,'rhythm_usage'=>$rhythmCounts,'structure_usage'=>$structureCounts],
        ];
    }

    private static function eligibleStructures(array $context): array
    {
        $out = ['visual_material_conceptual_spatial','detail_expansion_interpretation','contrast_tension_viewer'];
        if (self::has($context, ['surface','visible_marks_or_process','materials','technique','medium'])) $out[] = 'process_surface_depth_presence';
        if (self::has($context, ['visible_symbols','visible_elements','symbols'])) $out[] = 'atmosphere_composition_symbol_process';
        if (self::has($context, ['territory','landscape','horizon','ground','composition_type'])) $out[] = 'color_rhythm_territory_contemplation';
        return array_values(array_unique($out));
    }

    private static function eligibleTypes(array $context): array
    {
        $eligible = ['composition','color','atmosphere','movement','light','viewer','contrast','detail','negative_space','architecture'];
        if (self::has($context, ['surface','surface_and_texture','visible_marks_or_process','materials','technique','medium'])) $eligible = array_merge($eligible, ['material','process']);
        if (self::has($context, ['visible_symbols','visible_elements','symbols'])) $eligible[] = 'symbol';
        if (self::has($context, ['territory','landscape','horizon','ground','composition_type'])) $eligible[] = 'territory';
        if ((float)($context['width_cm'] ?? 0) > 0 && (float)($context['height_cm'] ?? 0) > 0) $eligible[] = 'scale';
        return array_values(array_unique($eligible));
    }

    private static function has(array $context, array $keys): bool
    {
        $flat = mb_strtolower(json_encode($context, JSON_UNESCAPED_UNICODE) ?: '');
        foreach ($keys as $key) if (str_contains($flat, mb_strtolower($key))) return true;
        return false;
    }

    private static function history(array $directories, string $excludeSeed): array
    {
        $out = [];
        foreach ($directories as $directory) {
            foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
                $data = json_decode((string)file_get_contents($file), true);
                if (!is_array($data) || (string)($data['source']['image_file'] ?? '') === $excludeSeed) continue;
                $strategy = $data['editorial_strategy'] ?? null;
                if (!is_array($strategy)) continue;
                $out[] = ['type'=>(string)($strategy['description_opening_type']??''),'rhythm'=>(string)($strategy['description_opening_rhythm']??''),'structure'=>(string)($strategy['description_structure_type']??''),'time'=>(string)($data['source']['analyzed_at']??filemtime($file))];
            }
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['time'], $b['time']));
        return $out;
    }

    private static function mostUsed(array $counts, int $limit): array
    {
        arsort($counts);
        return array_slice(array_keys(array_filter($counts, static fn(int $count): bool => $count > 0)), 0, $limit);
    }
}
