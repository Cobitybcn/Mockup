<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$studio = new CameraSlotStudio();
$error = '';
$notice = '';
$testPrompt = '';
$testImage = null;
$draft = null;
$export = '';

$config = $studio->cameraConfig();
$baseSlots = $studio->baseSlots();
$customSlots = $studio->customSlots();
$slots = $studio->existingSlots();
$sets = (array)($config['sets'] ?? []);
$boardGroups = $studio->sceneBoardGroups();
$studioSlots = array_filter($slots, static function (array $listedSlot): bool {
    return empty($listedSlot['deleted_from_studio']);
});
$selectedSlotId = trim((string)($_POST['slot_id'] ?? $_GET['slot_id'] ?? ''));
$mode = trim((string)($_GET['mode'] ?? ''));
$activeBoardIndex = max(1, min(3, (int)($_POST['board_index'] ?? $_GET['board'] ?? 1)));

$emptySlot = [
    'slot_id' => '',
    'slot_name' => '',
    'enabled' => true,
    'fidelity_mode' => 'adaptacion_camara_world_mother',
    'size_classes_supported' => ['small', 'medium', 'large', 'xl_or_oversize', 'unknown'],
    'orientation_supported' => ['horizontal', 'landscape', 'vertical', 'portrait', 'square', 'unknown'],
    'camera_height_block' => '',
    'lens_block' => '',
    'vertical_tilt_block' => '',
    'lateral_rotation_block' => '',
    'composition_block' => '',
    'human_subject_block' => '',
    'scale_block' => '',
    'depth_of_field_block' => '',
    'scene_affinity' => [],
    'negative_directives' => [],
    'full_prompt_template' => '',
    'primary_scene_set' => false,
    'group_id' => '',
    'group_name' => '',
    'group_order' => 0,
    'variant_label' => '',
    'variant_order' => 0,
];

$slot = $emptySlot;
if ($selectedSlotId !== '' && isset($slots[$selectedSlotId]) && is_array($slots[$selectedSlotId])) {
    $slot = array_merge($emptySlot, $slots[$selectedSlotId]);
    $slot['slot_id'] = (string)($slot['slot_id'] ?? $selectedSlotId);
}

$setMembership = [];
foreach ($sets as $setId => $set) {
    if (is_string($setId) && in_array((string)$slot['slot_id'], array_map('strval', (array)($set['slots'] ?? [])), true)) {
        $setMembership[] = $setId;
    }
}

$fieldHelp = [
    'slot_id' => ['ID técnico', 'Identificador único en minúsculas, sin espacios. Si editás una cámara base, se guardará como override administrado.', 'vista_aerea_obra_piso_cenital'],
    'slot_name' => ['Nombre visible', 'Nombre humano de la cámara en el admin.', 'Vista aérea cenital de obra sobre el piso'],
    'fidelity_mode' => ['Modo de fidelidad', 'Etiqueta técnica para agrupar comportamiento de fidelidad o estrategia.', 'adaptacion_camara_world_mother'],
    'size_classes_supported' => ['Tamaños soportados', 'Lista separada por comas. Usá los nombres que entiende el motor.', 'small, medium, large, xl_or_oversize, unknown'],
    'orientation_supported' => ['Orientaciones soportadas', 'Lista separada por comas.', 'horizontal, landscape, vertical, portrait, square, unknown'],
    'camera_height_block' => ['Altura de cámara', 'Define desde qué altura física mira la cámara.', 'La cámara está directamente encima de la obra, mirando hacia el piso en vista cenital.'],
    'lens_block' => ['Lente', 'Define tipo de lente, compresión, distorsión y distancia visual.', 'Usar una lente gran angular moderada, sin distorsión extrema.'],
    'vertical_tilt_block' => ['Inclinación vertical', 'Define si la cámara mira hacia arriba, hacia abajo o frontal.', 'Inclinación vertical de 85 a 90 grados hacia abajo, casi perpendicular al piso.'],
    'lateral_rotation_block' => ['Rotación lateral', 'Define el ángulo lateral u orbital respecto de la obra.', 'Rotación lateral de 0 grados, cámara nivelada y equilibrada desde arriba.'],
    'composition_block' => ['Composición', 'Define ubicación de la obra, crop, jerarquía visual y contexto.', 'La obra está centrada, completamente visible, ocupando 30-50% del encuadre, con piso visible alrededor.'],
    'human_subject_block' => ['Política sobre humanos', 'Define si pueden aparecer personas, manos, cuerpos o siluetas.', 'Sin personas, sin manos, sin presencia humana. Interior vacío.'],
    'scale_block' => ['Política de escala', 'Protege el tamaño físico real de la obra contra muebles, piso, puertas y arquitectura.', 'La obra conserva sus dimensiones reales y no debe verse como mural, billboard ni miniatura.'],
    'depth_of_field_block' => ['Profundidad de campo', 'Define foco, nitidez y zonas que pueden suavizarse.', 'La obra y sus bordes deben estar nítidos; el fondo puede suavizarse levemente.'],
    'scene_affinity' => ['Afinidad de escena', 'Tags separados por comas para orientar familias ambientales.', 'interior moderno, piso pulido, galeria, living minimalista'],
    'negative_directives' => ['Bloqueos / prompt negativo', 'Errores que esta cámara debe evitar. Separá por comas.', 'sin obra deformada, sin pintura inventada, sin escala mural, sin texto visible'],
    'full_prompt_template' => ['Prompt completo', 'Prompt fuente de esta cámara. Escribilo en español y usá placeholders cuando haga falta.', 'Generar un mockup fotográfico premium usando únicamente este slot de cámara...'],
];

function csv_value($value): string
{
    return implode(', ', array_map('strval', (array)$value));
}

function camera_field(array $help, string $name, $value, int $rows = 0): void
{
    [$label, $hint, $example] = $help[$name];
    echo '<div class="field">';
    echo '<label for="' . h($name) . '">' . h($label) . '</label>';
    if ($rows > 0) {
        echo '<textarea id="' . h($name) . '" name="' . h($name) . '" rows="' . $rows . '">' . h($value) . '</textarea>';
    } else {
        echo '<input id="' . h($name) . '" type="text" name="' . h($name) . '" value="' . h($value) . '">';
    }
    echo '<small>' . h($hint) . '<br><span>Ejemplo: ' . h($example) . '</span></small>';
    echo '</div>';
}

function camera_board_label(array $slot): string
{
    $name = trim((string)($slot['slot_name'] ?? ''));
    return $name !== '' ? $name : (string)($slot['slot_id'] ?? '');
}

function camera_origin_label(string $slotId, array $baseSlots, array $customSlots): string
{
    if (isset($customSlots[$slotId])) {
        return isset($baseSlots[$slotId]) ? 'Base con override custom' : 'Custom';
    }
    return isset($baseSlots[$slotId]) ? 'Base' : 'Nueva';
}

function render_camera_board_card(string $slotId, array $slot, bool $selected = false): void
{
    $name = camera_board_label($slot);
    $classes = 'cmb-camera-card cmb-sortable-item' . ($selected ? ' is-selected' : '');
    echo '<article class="' . h($classes) . '" data-camera-card data-camera-id="' . h($slotId) . '" tabindex="0">';
    echo '<button class="cmb-card-drag" type="button" data-drag-handle aria-label="Arrastrar cámara" title="Arrastrar cámara">⋮⋮</button>';
    echo '<button class="cmb-card-edit" type="button" data-edit-camera aria-label="Editar ' . h($name) . '" title="Editar cámara">Editar</button>';
    echo '<div class="cmb-card-visual" aria-hidden="true">';
    echo '<span class="cmb-viewfinder"><span></span></span>';
    echo '<span class="cmb-camera-mark">CAM</span>';
    echo '</div>';
    echo '<div class="cmb-card-copy"><strong>' . h($name) . '</strong><code>' . h($slotId) . '</code></div>';
    echo '</article>';
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_slot') {
            $saved = $studio->saveSlotFromForm($_POST);
            $selectedSlotId = (string)$saved['slot_id'];
            if (isset($_POST['board_slots_by_board']) && is_array($_POST['board_slots_by_board'])) {
                $studio->saveSceneBoards((array)$_POST['board_slots_by_board']);
            }
            $notice = 'Cámara guardada: ' . $selectedSlotId . '.';
        } elseif ($action === 'save_scene_quick') {
            $saved = $studio->saveSceneQuick($_POST);
            $selectedSlotId = (string)$saved['slot_id'];
            if (isset($_POST['board_slots_by_board']) && is_array($_POST['board_slots_by_board'])) {
                $studio->saveSceneBoards((array)$_POST['board_slots_by_board']);
            }
            $notice = 'Cámara guardada: ' . $selectedSlotId . '.';
        } elseif ($action === 'disable_slot') {
            $result = $studio->disableSlot((string)($_POST['slot_id'] ?? ''));
            $selectedSlotId = (string)$result['slot_id'];
            $notice = 'Cámara desactivada por override: ' . $selectedSlotId . '.';
        } elseif ($action === 'set_slot_enabled') {
            $enabled = !empty($_POST['enabled']);
            $result = $studio->setSlotEnabled((string)($_POST['slot_id'] ?? ''), $enabled);
            $selectedSlotId = (string)$result['slot_id'];
            $notice = ($enabled ? 'Cámara activada: ' : 'Cámara desactivada: ') . $selectedSlotId . '.';
        } elseif ($action === 'delete_slot') {
            $result = $studio->deleteSlot((string)($_POST['slot_id'] ?? ''));
            $selectedSlotId = '';
            $notice = $result['mode'] === 'deleted'
                ? 'Cámara custom eliminada: ' . (string)$result['slot_id'] . '.'
                : 'La cámara base no se borra físicamente; quedó desactivada por override: ' . (string)$result['slot_id'] . '.';
        } elseif ($action === 'save_scene_board') {
            $result = $studio->saveSceneBoards((array)($_POST['board_slots_by_board'] ?? []));
            $selectedSlotId = trim((string)($_POST['selected_slot_id'] ?? $selectedSlotId));
            $notice = 'Tableros guardados: ' . (int)$result['assigned_count'] . ' ubicaciones organizadas.';
        } elseif ($action === 'purge_inactive_slots') {
            $result = $studio->purgeInactiveSlots();
            $selectedSlotId = '';
            $notice = 'Cámaras inactivas eliminadas/ocultas: ' . (int)$result['total'] . '.';
        } elseif ($action === 'test_prompt') {
            $saved = $studio->saveSlotFromForm($_POST);
            $selectedSlotId = (string)$saved['slot_id'];
            $testPrompt = $studio->quickTestPrompt($selectedSlotId, (int)($_POST['test_artwork_id'] ?? 0));
            $notice = 'Cámara guardada y test rápido de prompt listo.';
        } elseif ($action === 'test_image') {
            $saved = $studio->saveSlotFromForm($_POST);
            $selectedSlotId = (string)$saved['slot_id'];
            $testImage = $studio->generateQuickTestImage($selectedSlotId, (int)($_POST['test_artwork_id'] ?? 0));
            $testPrompt = (string)($testImage['prompt'] ?? '');
            $notice = 'Cámara guardada e imagen de prueba generada.';
        } elseif (in_array($action, ['draft', 'draft_publish'], true)) {
            $brief = trim((string)($_POST['brief'] ?? ''));
            $draft = $studio->draftSlot($brief, [
                'style' => trim((string)($_POST['style'] ?? '')),
                'risk_notes' => trim((string)($_POST['risk_notes'] ?? '')),
            ]);
            if ($action === 'draft_publish') {
                $published = $studio->publishSlot($draft, (string)($_POST['publish_set_id'] ?? 'phase_2_6_experimental_v1'));
                $selectedSlotId = (string)$published['slot_id'];
                $notice = 'Borrador generado y publicado: ' . $selectedSlotId . '.';
            } else {
                $slot = array_merge($emptySlot, $draft);
                $selectedSlotId = (string)($slot['slot_id'] ?? '');
                $notice = 'Borrador generado. Revisalo y guardalo desde la ficha.';
            }
            $export = $studio->exportPhpArray($draft);
        }

        $config = $studio->cameraConfig();
        $baseSlots = $studio->baseSlots();
        $customSlots = $studio->customSlots();
        $slots = $studio->existingSlots();
        $sets = (array)($config['sets'] ?? []);
        $boardGroups = $studio->sceneBoardGroups();
        $studioSlots = array_filter($slots, static function (array $listedSlot): bool {
            return empty($listedSlot['deleted_from_studio']);
        });
        if ($selectedSlotId !== '' && isset($slots[$selectedSlotId]) && is_array($slots[$selectedSlotId])) {
            $slot = array_merge($emptySlot, $slots[$selectedSlotId]);
            $slot['slot_id'] = (string)($slot['slot_id'] ?? $selectedSlotId);
        }
        $setMembership = [];
        foreach ($sets as $setId => $set) {
            if (is_string($setId) && in_array((string)$slot['slot_id'], array_map('strval', (array)($set['slots'] ?? [])), true)) {
                $setMembership[] = $setId;
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$origin = 'Nueva';
if ((string)$slot['slot_id'] !== '') {
    $origin = isset($customSlots[(string)$slot['slot_id']])
        ? (isset($baseSlots[(string)$slot['slot_id']]) ? 'Base con override custom' : 'Custom')
        : 'Base';
}
$enabledSlotCount = 0;
foreach ($studioSlots as $countedSlot) {
    if (!empty($countedSlot['enabled'])) {
        $enabledSlotCount++;
    }
}
$disabledSlotCount = max(0, count($slots) - count($studioSlots));
$customCameraConfig = $studio->customCameraConfig();
$sceneBoards = is_array($customCameraConfig['scene_boards'] ?? null) ? $customCameraConfig['scene_boards'] : [];
if (!isset($sceneBoards['1']) || !is_array($sceneBoards['1'])) {
    $sceneBoards['1'] = [
        'label' => 'Tablero 1',
        'slots' => (array)($customCameraConfig['scene_board']['slots'] ?? []),
    ];
}
$boardSlotsByBoard = [];
$assignedBoardLookup = [];
$boardOneFallback = [];
if (empty($sceneBoards['1']['slots'])) {
    $boardAssignments = [];
    foreach ($studioSlots as $slotId => $listedSlot) {
        if ((int)($listedSlot['board_order'] ?? 0) <= 0 && empty($listedSlot['primary_scene_set'])) {
            continue;
        }
        $boardAssignments[(string)$slotId] = $listedSlot;
    }
    uasort($boardAssignments, static function (array $a, array $b): int {
        $aOrder = (int)($a['board_order'] ?? 0);
        $bOrder = (int)($b['board_order'] ?? 0);
        return (($aOrder > 0 ? $aOrder : 9999) <=> ($bOrder > 0 ? $bOrder : 9999))
            ?: strcmp((string)($a['slot_name'] ?? ''), (string)($b['slot_name'] ?? ''));
    });
    $boardOneFallback = array_keys($boardAssignments);
}
for ($boardNumber = 1; $boardNumber <= 3; $boardNumber++) {
    $storedBoardSlots = array_values(array_filter(array_map(
        'strval',
        (array)($sceneBoards[(string)$boardNumber]['slots'] ?? ($boardNumber === 1 ? $boardOneFallback : []))
    )));
    $boardSlotsByBoard[$boardNumber] = [];
    foreach ($storedBoardSlots as $storedSlotId) {
        if (!isset($studioSlots[$storedSlotId]) || in_array($storedSlotId, $boardSlotsByBoard[$boardNumber], true)) {
            continue;
        }
        $boardSlotsByBoard[$boardNumber][] = $storedSlotId;
        $assignedBoardLookup[$storedSlotId] = true;
    }
}
$boardSlotCount = count($assignedBoardLookup);
$availablePoolCount = 0;
foreach ($studioSlots as $slotId => $_listedSlot) {
    if (!isset($assignedBoardLookup[(string)$slotId])) {
        $availablePoolCount++;
    }
}
$cameraEditorPayload = [];
foreach ($studioSlots as $editorSlotId => $editorSlot) {
    $editorSlotId = (string)$editorSlotId;
    $cameraEditorPayload[$editorSlotId] = [
        'id' => $editorSlotId,
        'name' => camera_board_label($editorSlot),
        'prompt' => $studio->scenePromptForEdit($editorSlot),
        'origin' => camera_origin_label($editorSlotId, $baseSlots, $customSlots),
        'enabled' => !empty($editorSlot['enabled']),
    ];
}
$cameraBoardClientConfig = [
    'selectedCameraId' => $selectedSlotId,
    'mode' => $mode,
    'maxPerBoard' => 12,
];
define('CAMERA_STUDIO_VIEW', true);
require __DIR__ . '/camera_studio_view.php';
exit;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cámaras - Artwork Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .workspace-header { align-items:flex-start; gap:18px; padding-bottom:14px; margin-bottom:18px; }
        .workspace-header .topbar-actions { display:flex; align-items:center; justify-content:flex-end; gap:8px; max-width:none; }
        .workspace-header .topbar-actions form { display:inline-flex; margin:0; }
        .workspace-header .topbar-actions .button-link,
        .workspace-header .topbar-actions button.button-link {
            display:inline-flex !important;
            align-items:center;
            justify-content:center;
            width:auto !important;
            min-width:0 !important;
            height:36px !important;
            min-height:0 !important;
            padding:0 14px !important;
            margin:0 !important;
            border-radius:4px;
            font-size:10px !important;
            line-height:1 !important;
            letter-spacing:.08em;
            box-shadow:none !important;
            white-space:nowrap;
        }
        .camera-admin-toolbar { margin-bottom:12px; padding:12px 14px; }
        .camera-admin-toolbar-row { display:grid; grid-template-columns:minmax(0, 1fr) auto; gap:14px; align-items:start; }
        .camera-admin-toolbar h2 { margin-bottom:4px; }
        .camera-admin-toolbar p { margin:4px 0 0; color:var(--muted); font-size:12px; line-height:1.35; }
        .camera-workbench { display:grid; grid-template-columns:minmax(760px, 1fr) minmax(330px, 420px); gap:16px; align-items:start; }
        .panel-box { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:20px; }
        .camera-board { min-width:0; padding:10px; }
        .boards-wall { display:grid; grid-template-columns:repeat(3, minmax(240px, 1fr)); gap:10px; }
        .board-column { border:1px solid var(--line); border-radius:var(--radius); background:var(--surface-soft); padding:9px; display:grid; gap:8px; }
        .board-column h3 { margin:0; font-family:var(--font-sans); font-size:11px; letter-spacing:.07em; text-transform:uppercase; color:var(--ink); }
        .board-column-head { display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .camera-board-head { display:grid; grid-template-columns:minmax(0, 1fr) auto; gap:12px; align-items:start; margin-bottom:10px; }
        .camera-board-head h2,
        .instruction-panel h2 { margin-bottom:4px; }
        .camera-board-head p { margin:4px 0 0; color:var(--muted); font-size:12px; line-height:1.35; }
        .camera-board-layout { display:block; }
        .camera-board-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:6px; }
        .camera-board-cell { min-height:82px; border:1px dashed rgba(154, 123, 86, .42); border-radius:var(--radius); background:rgba(255,255,255,.46); padding:6px; display:grid; gap:5px; align-content:start; transition:border-color .14s ease, background .14s ease; }
        .camera-board-cell.drag-over { border-color:var(--accent); background:var(--accent-light); }
        .camera-board-cell-label { color:var(--muted); font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; }
        .camera-token { display:grid; gap:3px; border:1px solid var(--line); border-left:4px solid #49745f; border-radius:var(--radius); background:var(--surface); color:var(--ink); padding:6px 7px; cursor:grab; box-shadow:0 4px 12px rgba(20,20,18,.05); transition:border-color .14s ease, box-shadow .14s ease, transform .14s ease; user-select:none; }
        .camera-token:hover { border-color:rgba(154,123,86,.48); box-shadow:0 6px 16px rgba(20,20,18,.08); transform:translateY(-1px); }
        .camera-token:active { cursor:grabbing; }
        .camera-token.is-selected { outline:2px solid var(--accent); outline-offset:1px; }
        .camera-token strong { font-size:11px; line-height:1.18; }
        .camera-token code { color:var(--muted); font-size:9px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .camera-token-meta { display:flex; gap:5px; flex-wrap:wrap; align-items:center; }
        .camera-token-placeholder { color:var(--muted); font-size:12px; line-height:1.35; }
        .board-pool { border:1px solid var(--line); border-radius:var(--radius); background:var(--surface-soft); padding:8px; margin:10px 0 0; overflow-x:auto; overflow-y:hidden; }
        .board-pool.is-empty { padding:7px 9px; }
        .board-pool.drag-over { border-color:var(--accent); background:var(--accent-light); }
        .board-pool-head { display:flex; justify-content:space-between; gap:10px; align-items:center; margin-bottom:7px; }
        .board-pool.is-empty .board-pool-head { margin-bottom:0; }
        .board-pool h3 { margin:0; font-family:var(--font-sans); font-size:10px; text-transform:uppercase; color:var(--muted); letter-spacing:.06em; }
        .board-pool-list { display:flex; gap:8px; min-height:54px; }
        .board-pool.is-empty .board-pool-list { display:none; }
        .board-pool-list .camera-token { flex:0 0 185px; }
        .board-pool-empty { color:var(--muted); font-size:11px; align-self:center; padding:6px 0; }
        .board-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; align-items:center; }
        .board-switcher { display:inline-flex; gap:4px; align-items:center; border:1px solid var(--line); border-radius:999px; padding:3px; background:var(--surface-soft); }
        .board-switcher a { display:inline-flex; align-items:center; justify-content:center; min-width:78px; height:28px; padding:0 10px; border-radius:999px; color:var(--muted); text-decoration:none; font-size:9px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; }
        .board-switcher a.active { background:var(--surface); color:var(--accent); box-shadow:0 2px 8px rgba(20,20,18,.08); }
        .board-actions .button-link,
        .actions .button-link {
            display:inline-flex !important;
            align-items:center;
            justify-content:center;
            width:auto !important;
            min-width:0 !important;
            height:34px !important;
            min-height:0 !important;
            padding:0 14px !important;
            margin:0 !important;
            border-radius:4px;
            font-size:10px !important;
            line-height:1 !important;
            letter-spacing:.07em;
            box-shadow:none !important;
            white-space:nowrap;
        }
        .tiny-action-row { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-top:7px; }
        .tiny-action-row form { margin:0; }
        .tiny-action {
            border:1px solid var(--line);
            border-radius:999px;
            background:rgba(255,255,255,.74);
            color:var(--muted);
            padding:5px 8px;
            font-size:9px;
            font-weight:800;
            letter-spacing:.05em;
            text-transform:uppercase;
            cursor:pointer;
        }
        .tiny-action:hover { color:var(--accent); border-color:rgba(154,123,86,.4); }
        .tiny-action.danger:hover { color:#8f2f2f; border-color:rgba(143,47,47,.35); }
        .field { display:grid; gap:5px; margin-bottom:0; }
        .field label, .small-label { font-size:10px; text-transform:uppercase; color:var(--muted); font-weight:700; letter-spacing:.05em; }
        .field small { color:var(--muted); font-size:10px; line-height:1.3; }
        .field small span { opacity:.82; }
        textarea, input[type="text"], input[type="number"], select { width:100%; border:1px solid var(--line); border-radius:4px; background:var(--surface-soft); color:var(--ink); padding:8px 9px; font-size:12px; }
        textarea { resize:vertical; }
        .status-pill {
            align-self:start;
            border:1px solid var(--line);
            border-radius:999px;
            padding:4px 7px;
            font-size:9px;
            font-weight:800;
            letter-spacing:.07em;
            line-height:1;
            text-transform:uppercase;
            white-space:nowrap;
        }
        .status-pill.is-enabled { background:#e7f4ec; border-color:rgba(73, 116, 95, .3); color:#315d46; }
        .status-pill.is-disabled { background:#f8e9e6; border-color:rgba(155, 74, 63, .28); color:#8b3d33; }
        .status-toggle {
            border:1px solid var(--line);
            border-radius:4px;
            padding:8px 9px;
            background:var(--surface-soft);
            align-self:start;
        }
        .status-toggle label {
            display:flex;
            align-items:center;
            gap:10px;
            margin:0;
            color:var(--ink);
            font-size:11px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.06em;
        }
        .status-toggle input {
            width:16px;
            height:16px;
            accent-color:#49745f;
        }
        .status-toggle .status-pill { margin-left:auto; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .wide { grid-column:1 / -1; }
        .instruction-panel { position:sticky; top:12px; max-height:calc(100vh - 24px); overflow:auto; padding:14px; scrollbar-width:thin; }
        .instruction-panel > p { margin-top:0; font-size:12px; }
        .instruction-editor { display:grid; gap:8px; }
        .editor-section {
            border:1px solid var(--line);
            border-radius:4px;
            background:var(--surface-soft);
            padding:9px 10px;
        }
        .editor-section summary {
            margin:0;
            font-family:var(--font-sans);
            font-size:11px;
            font-weight:800;
            color:var(--muted);
            letter-spacing:.06em;
            text-transform:uppercase;
        }
        .editor-section summary { cursor:pointer; list-style:none; }
        .editor-section summary::-webkit-details-marker { display:none; }
        .editor-section summary::after { content:"+"; float:right; color:var(--accent); }
        .editor-section[open] summary { margin-bottom:8px; }
        .editor-section[open] summary::after { content:"-"; }
        .compact-field-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:9px; }
        .single-field-grid { display:grid; gap:9px; }
        .identity-grid { grid-template-columns:minmax(0, 1fr) minmax(150px, auto); align-items:start; }
        .scene-editor-top { display:grid; grid-template-columns:minmax(0, 1fr); gap:10px; margin-bottom:10px; }
        .scene-prompt-details textarea { min-height:360px; font-family:ui-monospace, SFMono-Regular, Consolas, monospace; font-size:12px; line-height:1.45; }
        .empty-editor-note { border:1px dashed var(--line); border-radius:4px; padding:14px; color:var(--muted); background:var(--surface-soft); }
        .actions { position:sticky; bottom:-14px; z-index:5; display:flex; flex-wrap:wrap; gap:7px; align-items:center; margin-top:10px; padding:10px 0 0; background:linear-gradient(to bottom, rgba(251,250,247,0), var(--surface) 28%); }
        .danger { background:#8f2f2f !important; }
        .code-box { min-height:300px; font-family:ui-monospace, SFMono-Regular, Consolas, monospace; font-size:12px; line-height:1.45; white-space:pre; overflow:auto; }
        .test-image { max-width:420px; width:100%; border:1px solid var(--line); border-radius:var(--radius); display:block; }
        @media (max-width:1450px) { .camera-workbench { grid-template-columns:minmax(680px, 1fr) minmax(320px, 390px); } .boards-wall { grid-template-columns:1fr; } }
        @media (max-width:1100px) { .camera-workbench, .form-grid, .compact-field-grid, .boards-wall { grid-template-columns:1fr; } .instruction-panel { position:static; max-height:none; } .wide { grid-column:auto; } }
        @media (max-width:720px) { .workspace-header .topbar-actions, .camera-board-head, .camera-admin-toolbar-row { display:block; } .workspace-header .topbar-actions .button-link, .workspace-header .topbar-actions button.button-link { margin:6px 6px 0 0 !important; } .board-actions { justify-content:flex-start; margin-top:12px; } .camera-board-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <div class="alert-strip">Cámaras: tableros, nombres visibles, activación y prompt completo.</div>
        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Cámaras</h1>
                    <p>Tableros 4 × 3 para ordenar, activar y revisar cámaras sin abrir fichas gigantes.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="camera_studio.php?mode=new">Nueva cámara</a>
                    <form method="post">
                        <input type="hidden" name="action" value="purge_inactive_slots">
                        <button class="button-link secondary" type="submit" onclick="return confirm('¿Eliminar u ocultar todas las cámaras inactivas?');">Eliminar inactivas</button>
                    </form>
                    <a class="button-link secondary" href="world_mother_studio.php">Scene Studio</a>
                </div>
            </div>

            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>

            <section class="panel-box camera-admin-toolbar">
                <div class="camera-admin-toolbar-row">
                    <div>
                        <h2>Tableros de cámaras</h2>
                        <p>Arrastrá cámaras entre tableros o hacia la biblioteca. Hacé clic para editar el nombre y el prompt.</p>
                    </div>
                    <div class="board-actions">
                        <span class="status-pill is-enabled" data-board-total-count><?= $boardSlotCount ?> en tableros</span>
                        <button class="button-link" type="submit" form="camera-board-form">Guardar tableros</button>
                    </div>
                </div>

                <aside class="board-pool <?= $availablePoolCount === 0 ? 'is-empty' : '' ?>" data-board-pool>
                    <div class="board-pool-head">
                        <h3>Biblioteca de cámaras</h3>
                        <span class="status-pill is-enabled" data-pool-count><?= $availablePoolCount ?> en espera</span>
                    </div>
                    <div class="board-pool-list">
                        <?php $poolCount = 0; ?>
                        <?php foreach ($studioSlots as $slotId => $listedSlot): ?>
                            <?php if (isset($assignedBoardLookup[(string)$slotId])) { continue; } ?>
                            <?php $poolCount++; ?>
                            <div
                                class="camera-token <?= $slotId === (string)$slot['slot_id'] ? 'is-selected' : '' ?>"
                                draggable="true"
                                data-camera-token
                                data-slot-id="<?= h((string)$slotId) ?>"
                                title="Hacé clic para editar. Arrastrá para ordenar."
                            >
                                <strong><?= h(camera_board_label($listedSlot)) ?></strong>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($poolCount === 0): ?>
                            <div class="board-pool-empty">Todas las cámaras activas están ubicadas en los tableros.</div>
                        <?php endif; ?>
                    </div>
                </aside>
            </section>

            <div class="camera-workbench">
                <section class="panel-box camera-board">
                    <form method="post" id="camera-board-form">
                        <input type="hidden" name="action" value="save_scene_board">
                        <input type="hidden" name="selected_slot_id" value="<?= h((string)$slot['slot_id']) ?>">

                        <div class="camera-board-layout">
                            <div class="boards-wall">
                                <?php for ($boardNumber = 1; $boardNumber <= 3; $boardNumber++): ?>
                                    <section class="board-column">
                                        <div class="board-column-head">
                                            <h3>Tablero <?= $boardNumber ?></h3>
                                            <span class="status-pill is-enabled" data-board-count><?= count($boardSlotsByBoard[$boardNumber] ?? []) ?>/12</span>
                                        </div>
                                        <div class="camera-board-grid">
                                            <?php for ($position = 1; $position <= 12; $position++): ?>
                                                <?php
                                                $assignedSlotId = (string)($boardSlotsByBoard[$boardNumber][$position - 1] ?? '');
                                                $assignedSlot = $assignedSlotId !== '' && isset($studioSlots[$assignedSlotId]) ? $studioSlots[$assignedSlotId] : null;
                                                ?>
                                                <div class="camera-board-cell" data-board-cell>
                                                    <input
                                                        type="hidden"
                                                        name="board_slots_by_board[<?= $boardNumber ?>][]"
                                                        value="<?= h($assignedSlotId) ?>"
                                                        data-board-input
                                                    >
                                                    <div class="camera-board-cell-label"><?= $position ?></div>
                                                    <?php if (is_array($assignedSlot)): ?>
                                                        <div
                                                            class="camera-token <?= $assignedSlotId === (string)$slot['slot_id'] ? 'is-selected' : '' ?>"
                                                            draggable="true"
                                                            data-camera-token
                                                            data-slot-id="<?= h($assignedSlotId) ?>"
                                                            title="Hacé clic para editar. Arrastrá para ordenar."
                                                        >
                                                            <strong><?= h(camera_board_label($assignedSlot)) ?></strong>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="camera-token-placeholder" data-empty-placeholder>Soltar</div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                    </section>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </form>
                </section>

                <section class="panel-box instruction-panel">
                    <h2>Editor de cámara</h2>
                    <p style="color:var(--muted);">Editá solo lo que importa. Origen: <strong><?= h($origin) ?></strong></p>
                    <?php if ((string)$slot['slot_id'] === ''): ?>
                        <p class="empty-editor-note">Seleccioná una cámara de un tablero para editarla.</p>
                    <?php else: ?>
                        <form method="post" class="camera-quick-form">
                            <input type="hidden" name="action" value="save_scene_quick">
                            <input type="hidden" name="board_index" value="<?= (int)$activeBoardIndex ?>">
                            <input type="hidden" name="slot_id" value="<?= h((string)$slot['slot_id']) ?>">
                            <div class="scene-editor-top">
                                <div class="field">
                                    <label>Nombre visible</label>
                                    <input type="text" name="slot_name" value="<?= h((string)$slot['slot_name']) ?>">
                                    <small>La disponibilidad se controla moviendo esta cámara dentro o fuera de los tableros.</small>
                                </div>
                            </div>

                            <details class="editor-section scene-prompt-details">
                                <summary>Prompt completo</summary>
                                <textarea name="full_prompt_template" rows="18"><?= h($studio->scenePromptForEdit($slot)) ?></textarea>
                            </details>

                            <div class="actions">
                                <button class="button-link" type="submit">Guardar cámara</button>
                                <button class="button-link secondary" type="submit" form="camera-board-form">Guardar tableros</button>
                                <button class="button-link danger" type="submit" name="action" value="delete_slot" onclick="return confirm('¿Eliminar o desactivar esta cámara? Las cámaras base se desactivan por override.');">Eliminar</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($testImage && is_file((string)($testImage['path'] ?? ''))): ?>
                        <hr style="border:0; border-top:1px dashed var(--line); margin:24px 0;">
                        <h2>Imagen de prueba</h2>
                        <img class="test-image" src="<?= h('results/' . basename((string)$testImage['path'])) ?>" alt="Imagen de prueba de cámara">
                        <p style="color:var(--muted);"><code><?= h((string)$testImage['path']) ?></code></p>
                    <?php endif; ?>

                    <?php if ($testPrompt !== ''): ?>
                        <hr style="border:0; border-top:1px dashed var(--line); margin:24px 0;">
                        <h2>Prompt final del test</h2>
                        <textarea class="code-box" readonly><?= h($testPrompt) ?></textarea>
                    <?php endif; ?>
                </section>
            </div>

        </div>
    </main>
</div>
<script>
let draggedCameraToken = null;
let suppressTokenClick = false;

function updateBoardState() {
    const pool = document.querySelector('[data-board-pool]');
    if (pool) {
        const list = pool.querySelector('.board-pool-list');
        const poolTokens = list ? list.querySelectorAll('[data-camera-token]') : [];
        const poolCount = poolTokens.length;
        pool.classList.toggle('is-empty', poolCount === 0);

        const emptyMessage = list ? list.querySelector('.board-pool-empty') : null;
        if (poolCount > 0 && emptyMessage) {
            emptyMessage.remove();
        }

        const poolCounter = pool.querySelector('[data-pool-count]');
        if (poolCounter) {
            poolCounter.textContent = poolCount + ' en espera';
        }
    }

    let assignedCount = 0;
    document.querySelectorAll('.board-column').forEach(board => {
        const count = board.querySelectorAll('[data-board-cell] [data-camera-token]').length;
        assignedCount += count;
        const counter = board.querySelector('[data-board-count]');
        if (counter) {
            counter.textContent = count + '/12';
        }
    });

    const totalCounter = document.querySelector('[data-board-total-count]');
    if (totalCounter) {
        totalCounter.textContent = assignedCount + ' en tableros';
    }
}

function clearBoardCell(cell) {
    if (!cell) return;
    const input = cell.querySelector('[data-board-input]');
    if (input) input.value = '';
    cell.querySelectorAll('[data-camera-token]').forEach(token => token.remove());
    if (!cell.querySelector('[data-empty-placeholder]')) {
        const placeholder = document.createElement('div');
        placeholder.className = 'camera-token-placeholder';
        placeholder.setAttribute('data-empty-placeholder', '');
        placeholder.textContent = 'Soltar';
        cell.appendChild(placeholder);
    }
}

function setCellToken(cell, token) {
    if (!cell || !token) return;
    const sourceCell = token.closest('[data-board-cell]');
    if (sourceCell && sourceCell !== cell) {
        clearBoardCell(sourceCell);
    }
    const existing = cell.querySelector('[data-camera-token]');
    if (existing && existing !== token) {
        const poolList = document.querySelector('[data-board-pool] .board-pool-list');
        if (poolList) poolList.appendChild(existing);
    }
    cell.querySelectorAll('[data-empty-placeholder]').forEach(item => item.remove());
    cell.appendChild(token);
    const input = cell.querySelector('[data-board-input]');
    if (input) input.value = token.getAttribute('data-slot-id') || '';
    updateBoardState();
}

document.querySelectorAll('[data-camera-token]').forEach(token => {
    token.addEventListener('dragstart', event => {
        draggedCameraToken = token;
        suppressTokenClick = true;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', token.getAttribute('data-slot-id') || '');
    });
    token.addEventListener('dragend', () => {
        window.setTimeout(() => {
            suppressTokenClick = false;
            draggedCameraToken = null;
        }, 120);
    });
    token.addEventListener('click', () => {
        if (suppressTokenClick) return;
        const slotId = token.getAttribute('data-slot-id') || '';
        if (slotId !== '') {
            window.location.href = 'camera_studio.php?board=<?= (int)$activeBoardIndex ?>&slot_id=' + encodeURIComponent(slotId);
        }
    });
});

document.querySelectorAll('[data-board-cell]').forEach(cell => {
    cell.addEventListener('dragover', event => {
        event.preventDefault();
        cell.classList.add('drag-over');
    });
    cell.addEventListener('dragleave', () => cell.classList.remove('drag-over'));
    cell.addEventListener('drop', event => {
        event.preventDefault();
        cell.classList.remove('drag-over');
        if (draggedCameraToken) {
            setCellToken(cell, draggedCameraToken);
        }
    });
});

const boardPool = document.querySelector('[data-board-pool]');
if (boardPool) {
    boardPool.addEventListener('dragover', event => {
        event.preventDefault();
        boardPool.classList.add('drag-over');
    });
    boardPool.addEventListener('dragleave', () => boardPool.classList.remove('drag-over'));
    boardPool.addEventListener('drop', event => {
        event.preventDefault();
        boardPool.classList.remove('drag-over');
        if (!draggedCameraToken) return;
        const sourceCell = draggedCameraToken.closest('[data-board-cell]');
        const poolList = boardPool.querySelector('.board-pool-list');
        if (poolList) {
            poolList.appendChild(draggedCameraToken);
        }
        if (sourceCell) {
            clearBoardCell(sourceCell);
        }
        updateBoardState();
    });
}

updateBoardState();
</script>
</body>
</html>
