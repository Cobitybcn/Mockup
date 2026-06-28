<?php
declare(strict_types=1);

/**
 * MockupContextIdentity — canonical world identity for a mockup context.
 *
 * Phase 2.9. Production helper (no DB, no master prompts). It collapses the two
 * parallel naming systems that live inside context_json into a single canonical
 * world identity, additively, for NEW contexts:
 *
 *   - assigned_*             : world actually used by the world-isolated flow.
 *   - context_* / scene_*    : passive metadata recomputed per index at save time.
 *
 * The canonical resolution favours the world that actually drove generation
 * (assigned_*), then any explicit selected_*, then the passive context_*.
 * Existing rows are never rewritten; this only enriches what we save going
 * forward, and is fully backward compatible with old context_json.
 */
final class MockupContextIdentity
{
    /**
     * @param array<string,mixed> $source A proposal ($prop) or a context_json array.
     * @return array{selected_world_id:string,selected_family_id:string,selected_variant_id:string,identity_source:string}
     */
    public static function resolve(array $source): array
    {
        $assignedWorld   = self::str($source, 'assigned_world_id');
        $assignedFamily  = self::str($source, 'assigned_family_id');
        $assignedVariant = self::str($source, 'assigned_variant_id');

        $selWorld   = self::str($source, 'selected_world_id');
        $selFamily  = self::str($source, 'selected_family_id');
        $selVariant = self::str($source, 'selected_variant_id');

        $contextWorld   = self::str($source, 'context_world_id');
        $contextFamily  = self::str($source, 'context_family_id');
        $contextVariant = self::str($source, 'scene_variant_id');

        $world   = self::coalesce([$assignedWorld, $selWorld, $contextWorld]);
        $family  = self::coalesce([$assignedFamily, $selFamily, $contextFamily]);
        $variant = self::coalesce([$assignedVariant, $selVariant, $contextVariant]);

        $source_label = 'none';
        if ($assignedWorld !== '') {
            $source_label = 'assigned_world_isolated';
        } elseif ($selWorld !== '') {
            $source_label = 'persisted_selected';
        } elseif ($contextWorld !== '') {
            $source_label = 'passive_context_only';
        }

        return [
            'selected_world_id'   => $world,
            'selected_family_id'  => $family,
            'selected_variant_id' => $variant,
            'identity_source'     => $source_label,
        ];
    }

    /** @param array<string,mixed> $arr */
    private static function str(array $arr, string $key): string
    {
        $v = $arr[$key] ?? '';
        if (is_array($v) || is_object($v)) {
            return '';
        }
        return trim((string)$v);
    }

    /** @param array<int,string> $candidates */
    private static function coalesce(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }
}
