<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();
$isAdmin = Auth::isAdmin($currentUser);

$job = $_GET['job'] ?? '';
$job = basename($job);

if (!$job) {
    die('Falta job.');
}

$jobDir = __DIR__ . '/jobs/' . $job;
$statusFile = $jobDir . '/status.json';

if (!is_file($statusFile)) {
    die('No se encontro el trabajo.');
}

$status = json_decode((string)file_get_contents($statusFile), true);

if (!$status) {
    die('No se pudo leer el estado.');
}

if ((int)($status['user_id'] ?? 0) !== (int)$currentUser['id']) {
    http_response_code(403);
    die('No tienes acceso a este trabajo.');
}

$currentStatus = $status['status'] ?? 'unknown';
$message = $status['message'] ?? '';
$resultFile = $status['result_file'] ?? null;
$error = $status['error'] ?? null;

$resultUrl = null;

if ($resultFile && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . basename($resultFile))) {
    $resultUrl = 'media.php?file=' . rawurlencode(basename($resultFile));
}

$statusLabels = [
    'queued' => 'En cola',
    'processing' => 'Procesando',
    'done' => 'Completado',
    'error' => 'Error',
];

$publicStatus = $statusLabels[(string)$currentStatus] ?? (string)$currentStatus;
$publicMessage = match ((string)$currentStatus) {
    'queued' => 'Trabajo recibido. Preparando la generacion.',
    'processing' => 'Estamos creando una imagen raiz limpia y fiel.',
    default => 'El sistema esta preparando la imagen.',
};

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Generando imagen raiz</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">

    <style>
        .process-card {
            max-width: 980px;
            background: #fff;
            border: 1px solid #dfdfdc;
            padding: 32px;
            box-shadow: 0 18px 46px rgba(0,0,0,.08);
        }

        .process-card h2 {
            margin-bottom: 8px;
            font-size: clamp(34px, 5vw, 58px);
            letter-spacing: 0;
        }

        .status-box {
            font-size: 15px;
            background: #f1f1ef;
            padding: 16px;
            border-left: 4px solid #e51f3f;
            margin: 24px 0;
        }

        .progress {
            width: 100%;
            height: 12px;
            overflow: hidden;
            background: #e7e7e2;
            border: 1px solid #d8d8d2;
            margin: 26px 0;
            position: relative;
        }

        .progress-bar {
            width: 42%;
            height: 100%;
            background: #e51f3f;
            position: absolute;
            left: -42%;
            top: 0;
            animation: progressMove 1.6s ease-in-out infinite;
        }

        @keyframes progressMove {
            0% { left: -42%; }
            55% { left: 100%; }
            100% { left: 100%; }
        }

        .root-preview {
            width: 100%;
            max-height: 78vh;
            object-fit: contain;
            display: block;
            margin: 24px 0;
            background: #f1f1ef;
            border: 12px solid #fff;
            box-shadow: 0 16px 34px rgba(0,0,0,.12);
        }

        .error {
            color: #a00000;
            background: #fff1f1;
            border-left-color: #a00000;
        }

        code {
            background: #f1f1ef;
            padding: 2px 5px;
        }

        iframe {
            display: none;
            width: 0;
            height: 0;
            border: 0;
        }
    </style>
</head>

<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="sidebar-head">
            <a class="brand" href="dashboard.php">ARTMOCK <span class="brand-mark"></span></a>
        </div>

        <div class="sidebar-action">
            <a class="button-link" href="artwork_new.php">+ Nueva obra</a>
        </div>

        <ul class="nav">
            <li><a href="dashboard.php">Dashboard</a></li>
            <li><a class="active" href="artwork_new.php">Crear obra raiz</a></li>
            <li><a href="artist_profile.php">Perfil de artista</a></li>
            <?php if ($isAdmin): ?>
                <li><a href="admin_prompts.php">Admin prompts</a></li>
                <li><a href="admin_api_keys.php">API keys</a></li>
            <?php endif; ?>
            <li><a href="account.php">Cuenta y pagos</a></li>
        </ul>
    </aside>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($currentUser['email']) ?></a>
        </header>

        <div class="alert-strip">
            Formulario 1: estamos creando una imagen raiz fiel para los mockups posteriores.
        </div>

        <div class="workspace">
            <section class="process-card">
                <?php if ($currentStatus === 'done' && $resultUrl): ?>

                    <h2>Imagen raiz creada</h2>
                    <p class="page-kicker">Revisa la imagen antes de crear mockups curatoriales.</p>

                    <div class="status-box">
                        Imagen raiz lista. Revisala antes de crear mockups.
                    </div>

                    <img class="root-preview" src="<?= h($resultUrl) ?>" alt="Imagen raiz generada">

                    <div class="topbar-actions">
                        <a class="button-link" href="form2.php?image=<?= rawurlencode(basename($resultFile)) ?>">
                            Continuar al formulario 2
                        </a>

                        <a class="button-link secondary" href="artwork_new.php">
                            Crear otra imagen
                        </a>
                    </div>

                <?php elseif ($currentStatus === 'error'): ?>

                    <h2>Error en la generacion</h2>

                    <div class="status-box error">
                        <?= h($error ?: $message ?: 'Ocurrio un error desconocido.') ?>
                    </div>

                    <p>Trabajo: <code><?= h($job) ?></code></p>

                    <a class="button-link" href="artwork_new.php">Volver</a>

                <?php else: ?>

                    <h2>Preparando obra raiz</h2>
                    <p class="page-kicker">Puedes dejar esta pantalla abierta. El sistema actualizara el resultado automaticamente.</p>

                    <div class="status-box" id="statusBox">
                        Estado: <strong id="statusText"><?= h($publicStatus) ?></strong><br>
                        <span id="messageText"><?= h($publicMessage) ?></span>
                    </div>

                    <div class="progress" aria-label="Generando imagen">
                        <div class="progress-bar"></div>
                    </div>

                    <p>Trabajo: <code><?= h($job) ?></code></p>

                    <script>
                        const job = <?= json_encode($job) ?>;
                        const statusUrl = 'job_status.php?job=' + encodeURIComponent(job);

                        async function checkStatus() {
                            try {
                                const response = await fetch(statusUrl + '&t=' + Date.now(), {
                                    cache: 'no-store'
                                });

                                const data = await response.json();

                                const labels = {
                                    queued: 'En cola',
                                    processing: 'Procesando',
                                    done: 'Completado',
                                    error: 'Error'
                                };

                                const messages = {
                                    queued: 'Trabajo recibido. Preparando la generacion.',
                                    processing: 'Estamos creando una imagen raiz limpia y fiel.',
                                    done: 'Imagen raiz lista. Revisala antes de crear mockups.',
                                    error: 'No se pudo completar la generacion.'
                                };

                                document.getElementById('statusText').textContent = labels[data.status] || data.status || 'Pendiente';
                                document.getElementById('messageText').textContent = data.message || messages[data.status] || 'El sistema esta preparando la imagen.';

                                if (data.status === 'done' || data.status === 'error') {
                                    window.location.reload();
                                }

                            } catch (e) {
                                console.log('Esperando status...', e);
                            }
                        }

                        setInterval(checkStatus, 5000);
                        checkStatus();
                    </script>

                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
