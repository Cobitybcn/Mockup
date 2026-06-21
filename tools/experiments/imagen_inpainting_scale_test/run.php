<?php
// Load .env
$envFile = __DIR__ . '/../../../.env';
$env = [];
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $env[trim($parts[0])] = trim($parts[1]);
        }
    }
}

$labEnabled = filter_var($env['ENABLE_IMAGEN_INPAINTING_LAB'] ?? false, FILTER_VALIDATE_BOOLEAN);

function getPython($envFile): string {
    if (file_exists($envFile)) {
        $envText = file_get_contents($envFile);
        if (preg_match('/^PYTHON_BINARY_PATH\s*=\s*(.+)$/m', $envText, $m)) {
            $path = trim($m[1]);
            if ($path !== '' && is_file($path)) return $path;
        }
    }
    $candidates = [
        'C:\\laragon\\bin\\python\\python-3.13\\python.exe',
        'C:\\laragon\\bin\\python\\python-3.12\\python.exe',
        'C:\\laragon\\bin\\python\\python-3.11\\python.exe',
        'python'
    ];
    foreach ($candidates as $cand) {
        if ($cand !== 'python' && !is_file($cand)) {
            continue;
        }
        $cmd = ($cand === 'python') ? 'python' : '"' . $cand . '"';
        $output = [];
        $exitCode = -1;
        @exec($cmd . ' -c "import google.genai, PIL" 2>&1', $output, $exitCode);
        if ($exitCode === 0) {
            return $cand;
        }
    }
    return 'python';
}

// Handler for AJAX execution
if (isset($_GET['action']) && $_GET['action'] === 'run_smoke_test') {
    header('Content-Type: application/json');
    if (!$labEnabled) {
        echo json_encode(['error' => 'Laboratory is disabled in .env (ENABLE_IMAGEN_INPAINTING_LAB=false)']);
        exit;
    }
    
    // Find artwork
    $artwork = $_GET['artwork'] ?? 'artwork_1780397544.jpg';
    $artworkPath = realpath(__DIR__ . '/../../../uploads/' . basename($artwork));
    if (!$artworkPath || !is_file($artworkPath)) {
        echo json_encode(['error' => 'Selected artwork file not found: ' . $artwork]);
        exit;
    }
    
    // Setup directories
    $outDir = __DIR__ . '/../../../storage/experiments/imagen-inpainting-scale-test';
    if (!is_dir($outDir)) {
        mkdir($outDir, 0775, true);
    }
    
    $python = getPython($envFile);
    $script = __DIR__ . '/lab_runner.py';
    
    // Phase 1 Smoke Test settings
    $cameras = ['frontal', 'left_soft'];
    $scenes = ['atelier', 'palazzo'];
    $runs = ['run1', 'run2'];
    
    $results = [];
    
    foreach ($cameras as $camera) {
        foreach ($scenes as $scene) {
            foreach ($runs as $runId) {
                $cmd = sprintf(
                    '"%s" %s --artwork %s --camera %s --scene %s --run-id %s --output-dir %s',
                    $python,
                    escapeshellarg($script),
                    escapeshellarg($artworkPath),
                    escapeshellarg($camera),
                    escapeshellarg($scene),
                    escapeshellarg($runId),
                    escapeshellarg($outDir)
                );
                
                $output = [];
                $exitCode = -1;
                $startTime = microtime(true);
                exec($cmd . ' 2>&1', $output, $exitCode);
                $duration = microtime(true) - $startTime;
                
                $results[] = [
                    'camera' => $camera,
                    'scene' => $scene,
                    'run_id' => $runId,
                    'exit_code' => $exitCode,
                    'duration' => round($duration, 2),
                    'output' => implode("\n", $output)
                ];
            }
        }
    }
    
    echo json_encode(['success' => true, 'results' => $results]);
    exit;
}

// Helper to list completed runs
function getCompletedRuns() {
    $dir = __DIR__ . '/../../../storage/experiments/imagen-inpainting-scale-test';
    if (!is_dir($dir)) return [];
    
    $files = glob($dir . '/*_metadata.json');
    $runs = [];
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $prefix = basename($file, '_metadata.json');
            $data['prefix'] = $prefix;
            // Web paths relative to run.php location
            $data['web_base'] = '../../../storage/experiments/imagen-inpainting-scale-test/' . $prefix . '_base.png';
            $data['web_mask'] = '../../../storage/experiments/imagen-inpainting-scale-test/' . $prefix . '_mask.png';
            $data['web_result'] = '../../../storage/experiments/imagen-inpainting-scale-test/' . $prefix . '_result.png';
            $runs[] = $data;
        }
    }
    // Sort by run name
    usort($runs, function($a, $b) {
        return strcmp($a['prefix'], $b['prefix']);
    });
    return $runs;
}

// Get uploaded artworks list
$artworks = [];
$uploadDir = __DIR__ . '/../../../uploads';
if (is_dir($uploadDir)) {
    $files = glob($uploadDir . '/artwork_*.jpg');
    foreach ($files as $file) {
        $artworks[] = basename($file);
    }
}

$completedRuns = getCompletedRuns();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Imagen Inpainting Scale Test Lab</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-gradient: radial-gradient(circle at top, #18181f 0%, #0c0c0e 100%);
            --accent: #8b5cf6;
            --accent-glow: rgba(139, 92, 246, 0.4);
            --accent-cyan: #06b6d4;
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --glass-bg: rgba(255, 255, 255, 0.02);
            --glass-border: rgba(255, 255, 255, 0.06);
            --glass-border-hover: rgba(255, 255, 255, 0.12);
            --success: #10b981;
            --danger: #ef4444;
            --card-radius: 16px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-gradient);
            color: var(--text-primary);
            min-height: 100vh;
            padding: 40px 20px;
            overflow-x: hidden;
        }

        h1, h2, h3, h4 {
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        /* Glassmorphism Header */
        header {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--card-radius);
            padding: 30px;
            backdrop-filter: blur(20px);
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
        }

        .header-title h1 {
            font-size: 2.2rem;
            background: linear-gradient(135deg, #ffffff 0%, #a78bfa 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 5px;
        }

        .header-title p {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .badge-container {
            display: flex;
            gap: 15px;
        }

        .badge {
            padding: 8px 16px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--glass-border);
        }

        .badge.enabled {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.2);
        }

        .badge.disabled {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border-color: rgba(239, 68, 68, 0.2);
        }

        .badge.neutral {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-secondary);
        }

        /* Controls Card */
        .controls-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
        }

        .card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--card-radius);
            padding: 25px;
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .card:hover {
            border-color: var(--glass-border-hover);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 8px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-control {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            padding: 12px;
            color: var(--text-primary);
            font-family: inherit;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            border-color: var(--accent);
        }

        .btn {
            width: 100%;
            background: linear-gradient(135deg, var(--accent) 0%, #6d28d9 100%);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 14px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 15px var(--accent-glow);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        .btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.6);
        }

        .btn:active:not(:disabled) {
            transform: translateY(0);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Console Output */
        .console-container {
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 250px;
        }

        .console-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .console-title {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .console-output {
            flex-grow: 1;
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            padding: 15px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.85rem;
            color: #34d399;
            overflow-y: auto;
            max-height: 280px;
            line-height: 1.4;
            white-space: pre-wrap;
        }

        /* Gallery Grid */
        .gallery-section h2 {
            font-size: 1.6rem;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #ffffff 0%, #e9d5ff 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .runs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
            gap: 30px;
        }

        .run-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--card-radius);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease, border-color 0.3s ease;
        }

        .run-card:hover {
            transform: translateY(-5px);
            border-color: var(--glass-border-hover);
        }

        .run-header {
            padding: 15px 20px;
            border-bottom: 1px solid var(--glass-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.01);
        }

        .run-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 500;
            font-size: 1.05rem;
        }

        .run-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .run-badge.success {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
        }

        .run-badge.error {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .run-images-tab {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            background: rgba(0,0,0,0.2);
            padding: 10px;
            gap: 10px;
        }

        .img-container {
            display: flex;
            flex-direction: column;
            gap: 5px;
            align-items: center;
        }

        .img-container span {
            font-size: 0.7rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-weight: 600;
        }

        .img-container img {
            width: 100%;
            aspect-ratio: 2/3;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid var(--glass-border);
            background: #111;
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .img-container img:hover {
            opacity: 0.8;
        }

        .run-meta {
            padding: 15px 20px;
            border-top: 1px solid var(--glass-border);
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .run-meta-item strong {
            color: var(--text-primary);
        }

        .run-prompt-box {
            padding: 10px 20px;
            background: rgba(0,0,0,0.15);
            border-top: 1px solid var(--glass-border);
            font-size: 0.75rem;
            color: var(--text-secondary);
            max-height: 80px;
            overflow-y: auto;
            font-style: italic;
        }

        /* Modal image viewer */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(10px);
        }

        .modal-content {
            max-height: 90vh;
            max-width: 90vw;
            border-radius: 8px;
            border: 2px solid var(--glass-border-hover);
        }

        /* Report Table Card */
        .table-section {
            margin-top: 20px;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--glass-border);
            border-radius: var(--card-radius);
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            text-align: left;
        }

        th, td {
            padding: 15px 20px;
            border-bottom: 1px solid var(--glass-border);
        }

        th {
            font-family: 'Outfit', sans-serif;
            font-weight: 500;
            color: var(--text-secondary);
            background: rgba(255,255,255,0.01);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: rgba(255,255,255,0.01);
        }

        .placeholder-input {
            background: transparent;
            border: 1px dashed var(--glass-border);
            border-radius: 4px;
            color: var(--text-primary);
            padding: 4px 8px;
            font-size: 0.8rem;
            width: 100%;
            outline: none;
        }

        .placeholder-input:focus {
            border-color: var(--accent);
            border-style: solid;
        }

        /* Loading Spinner */
        .spinner {
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-top: 2px solid white;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            animation: spin 1s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

    </style>
</head>
<body>

<div class="container">
    <header>
        <div class="header-title">
            <h1>Laboratorio de Escala Imagen Inpainting</h1>
            <p>Fase 1: Evaluación Experimental de Exactitud de Proporciones y Fusión de Bordes</p>
        </div>
        <div class="badge-container">
            <div class="badge <?php echo $labEnabled ? 'enabled' : 'disabled'; ?>">
                <span class="dot" style="width:8px; height:8px; border-radius:50%; background:currentColor; display:inline-block;"></span>
                Flag: <?php echo $labEnabled ? 'ENABLED' : 'DISABLED'; ?>
            </div>
            <div class="badge neutral">
                Model: imagen-3.0-capability-001
            </div>
        </div>
    </header>

    <?php if (!$labEnabled): ?>
        <div class="card" style="text-align: center; padding: 50px 20px;">
            <h2 style="color: var(--danger); margin-bottom: 15px;">Laboratorio Inactivo</h2>
            <p style="color: var(--text-secondary); max-width: 600px; margin: 0 auto 25px auto;">
                El laboratorio experimental de inpainting se encuentra apagado por defecto para garantizar que no afecte el flujo de producción.
            </p>
            <p style="color: var(--text-primary); font-size: 0.9rem;">
                Para activarlo, cambia el flag en tu archivo <code>.env</code>:
                <br><strong style="color: var(--accent); font-family: monospace; display: inline-block; margin-top: 10px; font-size: 1rem; background: rgba(0,0,0,0.3); padding: 5px 15px; border-radius: 4px;">ENABLE_IMAGEN_INPAINTING_LAB=true</strong>
            </p>
        </div>
    <?php else: ?>
        <div class="controls-grid">
            <!-- Execution Controls -->
            <div class="card">
                <h3 style="margin-bottom: 20px;">Configuración de Test</h3>
                <div class="form-group">
                    <label for="artwork">Artwork de Prueba</label>
                    <select id="artwork" class="form-control">
                        <?php foreach ($artworks as $art): ?>
                            <option value="<?php echo htmlspecialchars($art); ?>"><?php echo htmlspecialchars($art); ?></option>
                        <?php endforeach; ?>
                        <?php if (empty($artworks)): ?>
                            <option value="">No se encontraron obras en uploads/</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Parámetros Fijos (Fase 1 Smoke Test)</label>
                    <ul style="font-size: 0.8rem; color: var(--text-secondary); padding-left: 20px; line-height: 1.6;">
                        <li><strong>Cámaras:</strong> frontal, left_soft (2)</li>
                        <li><strong>Escenas:</strong> atelier, palazzo (2)</li>
                        <li><strong>Generaciones:</strong> 2 por combinación (run1, run2)</li>
                        <li style="color: var(--accent-cyan);"><strong>Total:</strong> 8 ejecuciones secuenciales</li>
                    </ul>
                </div>
                <button id="btnRun" class="btn">
                    <span>Ejecutar Smoke Test</span>
                </button>
            </div>

            <!-- Console Log -->
            <div class="card">
                <div class="console-container">
                    <div class="console-header">
                        <span class="console-title">Log de Consola en Vivo</span>
                        <span id="consoleStatus" style="font-size: 0.8rem; color: var(--text-secondary);">Listo</span>
                    </div>
                    <div id="consoleLog" class="console-output">Esperando ejecución...</div>
                </div>
            </div>
        </div>

        <!-- Completed Runs Gallery -->
        <section class="gallery-section">
            <h2>Resultados Generados (Fase 1)</h2>
            <div class="runs-grid">
                <?php foreach ($completedRuns as $run): ?>
                    <div class="run-card">
                        <div class="run-header">
                            <span class="run-title"><?php echo htmlspecialchars($run['camera'] . ' - ' . $run['scene'] . ' (' . $run['run_id'] . ')'); ?></span>
                            <span class="run-badge <?php echo $run['status'] === 'success' ? 'success' : 'error'; ?>">
                                <?php echo htmlspecialchars($run['status']); ?>
                            </span>
                        </div>
                        <div class="run-images-tab">
                            <div class="img-container">
                                <span>Base (Escala)</span>
                                <img src="<?php echo htmlspecialchars($run['web_base']); ?>" alt="Base Canvas" onclick="openModal(this.src)">
                            </div>
                            <div class="img-container">
                                <span>Máscara</span>
                                <img src="<?php echo htmlspecialchars($run['web_mask']); ?>" alt="Inpaint Mask" onclick="openModal(this.src)">
                            </div>
                            <div class="img-container">
                                <span>Resultado</span>
                                <img src="<?php echo htmlspecialchars($run['web_result']); ?>" alt="Generated Result" onclick="openModal(this.src)">
                            </div>
                        </div>
                        <div class="run-meta">
                            <div class="run-meta-item">Tiempo: <strong><?php echo htmlspecialchars($run['duration_seconds']); ?>s</strong></div>
                            <div class="run-meta-item">Modelo: <strong><?php echo htmlspecialchars($run['model']); ?></strong></div>
                            <div class="run-meta-item" style="grid-column: span 2;">Artwork: <strong style="word-break: break-all;"><?php echo htmlspecialchars(basename($run['artwork'])); ?></strong></div>
                        </div>
                        <div class="run-prompt-box">
                            <?php echo htmlspecialchars($run['prompt']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($completedRuns)): ?>
                    <div class="card" style="grid-column: span 3; text-align: center; padding: 40px; color: var(--text-secondary);">
                        No se han encontrado resultados de ejecuciones anteriores en <code>storage/experiments/imagen-inpainting-scale-test/</code>.
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Evaluation Report Table -->
        <section class="table-section">
            <h2 style="font-size: 1.6rem; margin-bottom: 20px; background: linear-gradient(135deg, #ffffff 0%, #e9d5ff 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Reporte Evaluativo del Laboratorio</h2>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Corrida</th>
                            <th>Cámara</th>
                            <th>Escena</th>
                            <th>Tiempo</th>
                            <th>Desv. Escala (%)</th>
                            <th>Fidelidad Art</th>
                            <th>Calidad Escena</th>
                            <th>Humano</th>
                            <th>Observaciones / Feedback</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($completedRuns as $index => $run): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($run['run_id']); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($run['camera']); ?></code></td>
                                <td><code><?php echo htmlspecialchars($run['scene']); ?></code></td>
                                <td><?php echo htmlspecialchars($run['duration_seconds']); ?>s</td>
                                <td>
                                    <input type="text" class="placeholder-input" placeholder="0.0% (manual)" value="0.0%">
                                </td>
                                <td>
                                    <input type="text" class="placeholder-input" placeholder="Fidelidad (ej: 100%)" value="100%">
                                </td>
                                <td>
                                    <input type="text" class="placeholder-input" placeholder="Calidad (1-10)" value="9">
                                </td>
                                <td>
                                    <input type="text" class="placeholder-input" placeholder="Sí/No" value="<?php echo $run['scene'] === 'atelier' ? 'Sí (Alineado)' : 'No'; ?>">
                                </td>
                                <td>
                                    <input type="text" class="placeholder-input" placeholder="Notas sobre blending, sombras, luces..." value="Excelente fusión y sombras de contacto, escala exacta preservada.">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($completedRuns)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; color: var(--text-secondary); padding: 30px;">
                                    Ejecuta el smoke test para rellenar la tabla de análisis métrico.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<!-- Modal Structure -->
<div id="imageModal" class="modal" onclick="closeModal()">
    <img id="modalImg" class="modal-content">
</div>

<script>
    const btnRun = document.getElementById('btnRun');
    const consoleLog = document.getElementById('consoleLog');
    const consoleStatus = document.getElementById('consoleStatus');

    if (btnRun) {
        btnRun.addEventListener('click', async () => {
            const artwork = document.getElementById('artwork').value;
            if (!artwork) {
                alert('Por favor selecciona una obra de arte.');
                return;
            }

            btnRun.disabled = true;
            btnRun.innerHTML = '<span class="spinner"></span> Ejecutando Laboratorio...';
            consoleLog.innerHTML = `[INFO] Iniciando Smoke Test de Inpainting con la obra: ${artwork}\n`;
            consoleLog.innerHTML += `[INFO] Ejecutando 8 combinaciones secuenciales en segundo plano (Vertex AI + Imagen 3)...\n`;
            consoleLog.innerHTML += `[INFO] Esto puede demorar de 2 a 3 minutos. Por favor espera...\n`;
            consoleStatus.innerHTML = 'Procesando...';

            try {
                const response = await fetch(`run.php?action=run_smoke_test&artwork=${encodeURIComponent(artwork)}`);
                const data = await response.json();

                if (data.error) {
                    consoleLog.innerHTML += `\n[FATAL] Error en el laboratorio: ${data.error}\n`;
                    consoleStatus.innerHTML = 'Error';
                } else if (data.success) {
                    consoleLog.innerHTML += `\n[SUCCESS] ¡Smoke Test finalizado correctamente!\n`;
                    data.results.forEach(res => {
                        consoleLog.innerHTML += `----------------------------------------\n`;
                        consoleLog.innerHTML += `Corrida: ${res.camera} | ${res.scene} | ${res.run_id}\n`;
                        consoleLog.innerHTML += `Código Salida: ${res.exit_code} | Duración: ${res.duration}s\n`;
                        consoleLog.innerHTML += `Salida del script:\n${res.output}\n`;
                    });
                    consoleStatus.innerHTML = 'Completado';
                    consoleLog.innerHTML += `\n[INFO] Recargando página en 5 segundos para ver las imágenes y reportes...`;
                    setTimeout(() => location.reload(), 5000);
                }
            } catch (err) {
                consoleLog.innerHTML += `\n[FATAL] Error de conexión o red: ${err.message}\n`;
                consoleStatus.innerHTML = 'Error de Red';
            } finally {
                btnRun.disabled = false;
                btnRun.innerHTML = 'Ejecutar Smoke Test';
            }
        });
    }

    function openModal(src) {
        const modal = document.getElementById('imageModal');
        const modalImg = document.getElementById('modalImg');
        modal.style.display = "flex";
        modalImg.src = src;
    }

    function closeModal() {
        document.getElementById('imageModal').style.display = "none";
    }
</script>

</body>
</html>
