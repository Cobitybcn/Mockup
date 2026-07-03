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
$selectedSlotId = trim((string)($_POST['slot_id'] ?? $_GET['slot_id'] ?? ''));
$mode = trim((string)($_GET['mode'] ?? ''));

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

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_slot') {
            $saved = $studio->saveSlotFromForm($_POST);
            $selectedSlotId = (string)$saved['slot_id'];
            $notice = 'Cámara guardada: ' . $selectedSlotId . '.';
        } elseif ($action === 'disable_slot') {
            $result = $studio->disableSlot((string)($_POST['slot_id'] ?? ''));
            $selectedSlotId = (string)$result['slot_id'];
            $notice = 'Cámara desactivada por override: ' . $selectedSlotId . '.';
        } elseif ($action === 'delete_slot') {
            $result = $studio->deleteSlot((string)($_POST['slot_id'] ?? ''));
            $selectedSlotId = '';
            $notice = $result['mode'] === 'deleted'
                ? 'Cámara custom eliminada: ' . (string)$result['slot_id'] . '.'
                : 'La cámara base no se borra físicamente; quedó desactivada por override: ' . (string)$result['slot_id'] . '.';
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
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Admin de cámaras - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-grid { display:grid; grid-template-columns: 340px minmax(0, 1fr); gap:22px; align-items:start; }
        .panel-box { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:20px; }
        .field { display:grid; gap:6px; margin-bottom:14px; }
        .field label, .small-label { font-size:10px; text-transform:uppercase; color:var(--muted); font-weight:700; letter-spacing:.05em; }
        .field small { color:var(--muted); font-size:11px; line-height:1.35; }
        .field small span { opacity:.82; }
        textarea, input[type="text"], input[type="number"], select { width:100%; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface-soft); color:var(--ink); padding:10px; }
        textarea { resize:vertical; }
        .slot-list { display:grid; gap:8px; max-height:72vh; overflow:auto; padding-right:4px; }
        .slot-card { display:block; text-decoration:none; color:var(--ink); border:1px solid var(--line); border-radius:var(--radius); padding:10px; background:var(--surface-soft); }
        .slot-card.active { outline:2px solid var(--accent); }
        .slot-card code { font-size:11px; }
        .slot-card .meta { color:var(--muted); font-size:11px; margin-top:4px; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .wide { grid-column:1 / -1; }
        .actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .danger { background:#8f2f2f !important; }
        .code-box { min-height:300px; font-family:ui-monospace, SFMono-Regular, Consolas, monospace; font-size:12px; line-height:1.45; white-space:pre; overflow:auto; }
        .test-image { max-width:420px; width:100%; border:1px solid var(--line); border-radius:var(--radius); display:block; }
        @media (max-width:1100px) { .admin-grid, .form-grid { grid-template-columns:1fr; } .wide { grid-column:auto; } }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <div class="alert-strip">Admin de cámaras: alta, edición, baja, test rápido de prompt e imagen.</div>
        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Admin de cámaras</h1>
                    <p>Ficha completa de cada slot. La administración y los prompts se cargan en español.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="camera_studio.php?mode=new">Nueva cámara</a>
                    <a class="button-link secondary" href="world_mother_studio.php">World Mother Studio</a>
                </div>
            </div>

            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>

            <div class="admin-grid">
                <aside class="panel-box">
                    <h2>Cámaras</h2>
                    <div class="slot-list">
                        <?php foreach ($slots as $slotId => $listedSlot): ?>
                            <?php
                            $listedOrigin = isset($customSlots[$slotId]) ? (isset($baseSlots[$slotId]) ? 'override' : 'custom') : 'base';
                            $isEnabled = !empty($listedSlot['enabled']);
                            ?>
                            <a class="slot-card <?= $slotId === (string)$slot['slot_id'] ? 'active' : '' ?>" href="camera_studio.php?slot_id=<?= h($slotId) ?>">
                                <code><?= h($slotId) ?></code>
                                <div><?= h($listedSlot['slot_name'] ?? '') ?></div>
                                <div class="meta"><?= h($listedOrigin) ?> · <?= $isEnabled ? 'activa' : 'desactivada' ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>

                <section class="panel-box">
                    <h2>Ficha de cámara</h2>
                    <p style="color:var(--muted);">Origen: <strong><?= h($origin) ?></strong></p>
                    <form method="post">
                        <input type="hidden" name="action" value="save_slot">
                        <div class="form-grid">
                            <?php camera_field($fieldHelp, 'slot_id', $slot['slot_id']); ?>
                            <?php camera_field($fieldHelp, 'slot_name', $slot['slot_name']); ?>
                            <div class="field">
                                <label><input type="checkbox" name="enabled" value="1" <?= !empty($slot['enabled']) ? 'checked' : '' ?>> Activa</label>
                                <small>Si está apagada, no debería participar como cámara disponible.</small>
                            </div>
                            <?php camera_field($fieldHelp, 'fidelity_mode', $slot['fidelity_mode']); ?>
                            <?php camera_field($fieldHelp, 'size_classes_supported', csv_value($slot['size_classes_supported'])); ?>
                            <?php camera_field($fieldHelp, 'orientation_supported', csv_value($slot['orientation_supported'])); ?>
                            <?php camera_field($fieldHelp, 'camera_height_block', $slot['camera_height_block'], 3); ?>
                            <?php camera_field($fieldHelp, 'lens_block', $slot['lens_block'], 3); ?>
                            <?php camera_field($fieldHelp, 'vertical_tilt_block', $slot['vertical_tilt_block'], 3); ?>
                            <?php camera_field($fieldHelp, 'lateral_rotation_block', $slot['lateral_rotation_block'], 3); ?>
                            <div class="wide"><?php camera_field($fieldHelp, 'composition_block', $slot['composition_block'], 4); ?></div>
                            <div class="wide"><?php camera_field($fieldHelp, 'human_subject_block', $slot['human_subject_block'], 3); ?></div>
                            <div class="wide"><?php camera_field($fieldHelp, 'scale_block', $slot['scale_block'], 4); ?></div>
                            <div class="wide"><?php camera_field($fieldHelp, 'depth_of_field_block', $slot['depth_of_field_block'], 3); ?></div>
                            <?php camera_field($fieldHelp, 'scene_affinity', csv_value($slot['scene_affinity'])); ?>
                            <?php camera_field($fieldHelp, 'negative_directives', csv_value($slot['negative_directives'])); ?>
                            <div class="wide"><?php camera_field($fieldHelp, 'full_prompt_template', $slot['full_prompt_template'], 12); ?></div>
                            <div class="field wide">
                                <label>Sets donde participa</label>
                                <?php foreach ($sets as $setId => $set): ?>
                                    <label style="font-size:13px; color:var(--ink); text-transform:none; letter-spacing:0; font-weight:500;">
                                        <input type="checkbox" name="set_ids[]" value="<?= h($setId) ?>" <?= in_array($setId, $setMembership, true) ? 'checked' : '' ?>>
                                        <?= h(($set['set_name'] ?? $setId) . ' · ' . $setId) ?>
                                    </label>
                                <?php endforeach; ?>
                                <small>Una cámara puede estar en varios sets. Si editás una base, el membership extra se guarda como custom.</small>
                            </div>
                            <div class="field">
                                <label>ID de obra para test</label>
                                <input type="number" name="test_artwork_id" value="<?= h($_POST['test_artwork_id'] ?? '') ?>" placeholder="Opcional">
                                <small>Si lo dejás vacío, usa la última obra con imagen raíz existente.</small>
                            </div>
                        </div>
                        <div class="actions">
                            <button class="button-link" type="submit">Guardar cámara</button>
                            <button class="button-link secondary" type="submit" name="action" value="test_prompt">Test rápido de prompt</button>
                            <button class="button-link secondary" type="submit" name="action" value="test_image">Generar imagen de prueba</button>
                            <?php if ((string)$slot['slot_id'] !== ''): ?>
                                <button class="button-link secondary" type="submit" name="action" value="disable_slot">Desactivar</button>
                                <button class="button-link danger" type="submit" name="action" value="delete_slot" onclick="return confirm('¿Eliminar o desactivar esta cámara? Las cámaras base se desactivan por override.');">Eliminar</button>
                            <?php endif; ?>
                        </div>
                    </form>

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

            <section class="panel-box" style="margin-top:22px;">
                <h2>Borrador asistido</h2>
                <form method="post">
                    <input type="hidden" name="action" value="draft">
                    <div class="form-grid">
                        <div class="field wide">
                            <label>Idea de cámara</label>
                            <textarea name="brief" rows="4" placeholder="Ejemplo: cámara cenital desde arriba, obra apoyada en el piso, visible completa, contexto de living elegante alrededor"></textarea>
                            <small>El borrador se genera en español y luego podés editar cada campo en la ficha.</small>
                        </div>
                        <div class="field">
                            <label>Nombre / familia sugerida</label>
                            <input type="text" name="style" placeholder="vista_aerea_cenital_piso">
                        </div>
                        <div class="field">
                            <label>Riesgos a bloquear</label>
                            <input type="text" name="risk_notes" placeholder="sin obra deformada, sin escala mural, sin personas">
                        </div>
                    </div>
                    <button class="button-link secondary" type="submit">Generar borrador</button>
                </form>
                <?php if ($export !== ''): ?>
                    <textarea class="code-box" readonly style="margin-top:14px;"><?= h($export) ?></textarea>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
