<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Services/MockupCameraArchetypeResolver.php';

$artworkId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($artworkId <= 0) {
    fwrite(STDERR, "Usage: php scratch/audit_camera_archetypes.php <artwork_id>\n");
    exit(1);
}

$resolver = new MockupCameraArchetypeResolver(dirname(__DIR__));
$result = $resolver->resolveForArtwork($artworkId);
$dims = $result['dimensions'] ?? [];

echo "=== CAMERA ARCHETYPE AUDIT / artwork_id={$artworkId} ===\n\n";
echo "CORE JSON exists: " . (!empty($result['core_json_exists']) ? 'YES' : 'NO') . "\n";
echo "CORE JSON path: " . ($result['core_json_path'] ?? 'N/A') . "\n";
echo "Selection status: " . (($result['selection_status'] ?? null) ?: 'unknown') . "\n";
echo "Fallback used: " . (!empty($result['fallback_used']) ? 'YES' : 'NO') . "\n";
echo "Fallback reason: " . (($result['fallback_reason'] ?? null) ?: 'none') . "\n\n";

echo "Detected dimensions\n";
echo "- width_cm: " . valueOrNa($dims['width_cm'] ?? null) . "\n";
echo "- height_cm: " . valueOrNa($dims['height_cm'] ?? null) . "\n";
echo "- depth_cm: " . valueOrNa($dims['depth_cm'] ?? null) . "\n";
echo "- orientation_resolved: " . valueOrNa($dims['orientation_resolved'] ?? null) . "\n";
echo "- aspect_ratio_resolved: " . valueOrNa($dims['aspect_ratio_resolved'] ?? null) . "\n";
echo "- longest_side_cm: " . valueOrNa($dims['longest_side_cm'] ?? null) . "\n";
echo "- size_class: " . valueOrNa($dims['size_class'] ?? null) . "\n";
echo "- xl_class: " . valueOrNa($dims['xl_class'] ?? null) . "\n\n";

echo "Selected set: " . valueOrNa($result['selected_set_id'] ?? null) . "\n\n";

$set = $result['selected_set'] ?? null;
if (!is_array($set) || !is_array($set['slots'] ?? null)) {
    echo "No camera archetype set selected. Existing deterministic camera map remains active.\n";
    echo "Diagnostic: " . (($result['selection_status'] ?? null) ?: 'unknown') . "\n";
    exit(0);
}

foreach ($set['slots'] as $slot => $archetype) {
    echo "Slot {$slot}: " . ($archetype['camera_archetype_name'] ?? 'N/A') . "\n";
    echo "  archetype_id: " . valueOrNa($archetype['camera_archetype_id'] ?? null) . "\n";
    echo "  camera_group: " . valueOrNa($archetype['camera_group'] ?? null) . "\n";
    echo "  camera_view: " . valueOrNa($archetype['camera_view'] ?? null) . "\n";
    echo "  camera_distance: " . valueOrNa($archetype['camera_distance'] ?? null) . "\n";
    echo "  camera_angle_notes: " . valueOrNa($archetype['camera_angle_notes'] ?? null) . "\n";
    echo "\n";
}

function valueOrNa($value): string
{
    if ($value === null || $value === '') {
        return 'N/A';
    }
    if (is_float($value) || is_int($value)) {
        $formatted = number_format((float)$value, 4, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }
    return (string)$value;
}
