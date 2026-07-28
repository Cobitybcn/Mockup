<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$job = basename((string)($_GET['job'] ?? ''));

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

if ($job === '') {
    http_response_code(404);
    die('Missing job.');
}

$statusFile = __DIR__ . '/jobs/' . $job . '/status.json';
if (!is_file($statusFile)) {
    $jobDir = dirname($statusFile);
    if (!is_dir($jobDir)) {
        mkdir($jobDir, 0775, true);
    }
    if (!StorageService::isGcsActive() || !StorageService::downloadFile('jobs/' . $job . '/status.json', $statusFile) || !is_file($statusFile)) {
        http_response_code(404);
        die('Job not found.');
    }
}

$status = json_decode((string)file_get_contents($statusFile), true);
if (!is_array($status) || (int)($status['user_id'] ?? 0) !== (int)$user['id']) {
    http_response_code(403);
    die('Access denied.');
}
?>
<!doctype html>
<html lang="<?= h(Translator::locale($user)) ?>">
<head>
    <meta charset="utf-8">
    <title><?= h(t('Creating Scenes - Artwork Mockups', 'Creando escenas - Artwork Mockups')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            display: grid;
            place-items: center;
            padding: 18px;
        }
        .scene-wait {
            width: min(520px, 100%);
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: clamp(24px, 7vw, 44px);
            text-align: center;
            box-shadow: var(--shadow);
        }
        .scene-spinner {
            width: 48px;
            height: 48px;
            border: 3px solid var(--line);
            border-top-color: var(--accent);
            border-radius: 50%;
            margin: 0 auto 22px;
            animation: spin 1s linear infinite;
        }
        .scene-wait h1 {
            margin: 0;
            font-family: var(--font-serif);
            font-size: clamp(34px, 8vw, 54px);
            font-weight: 500;
            line-height: 0.95;
        }
        .scene-wait p {
            margin: 14px auto 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.5;
        }
        .scene-wait small {
            display: block;
            margin-top: 18px;
            color: var(--muted);
            font-size: 12px;
        }
        .scene-error-details {
            margin-top: 16px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--surface-soft);
            color: var(--muted);
            text-align: left;
            font-size: 11px;
            line-height: 1.5;
        }
        .scene-error-details summary {
            cursor: pointer;
            color: var(--ink);
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .scene-error-details code {
            display: block;
            margin-top: 8px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            font: 11px/1.5 ui-monospace, SFMono-Regular, Consolas, monospace;
        }
        .scene-steps {
            display: grid;
            gap: 10px;
            margin-top: 24px;
            text-align: left;
        }
        .scene-step {
            display: grid;
            grid-template-columns: 18px minmax(0, 1fr);
            gap: 10px;
            align-items: center;
            color: var(--muted);
            font-size: 13px;
        }
        .scene-step-dot {
            width: 10px;
            height: 10px;
            border: 1px solid var(--line);
            border-radius: 50%;
            justify-self: center;
            background: var(--surface);
        }
        .scene-step.is-active { color: var(--ink); }
        .scene-step.is-active .scene-step-dot {
            border-color: var(--accent);
            border-top-color: transparent;
            width: 16px;
            height: 16px;
            animation: spin 1s linear infinite;
        }
        .scene-step.is-done .scene-step-dot {
            background: var(--accent);
            border-color: var(--accent);
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<main class="scene-wait">
    <div class="scene-spinner" aria-hidden="true"></div>
    <h1 id="sceneWaitTitle"><?= h(t('Preparing artwork', 'Preparando obra')) ?></h1>
    <p id="sceneWaitCopy"><?= h(t('We are preparing your artwork before creating the first scene views.', 'Estamos preparando tu obra antes de crear las primeras vistas de escena.')) ?></p>
    <div class="scene-steps" aria-label="<?= h(t('Generation progress', 'Progreso de generación')) ?>">
        <div class="scene-step is-active" data-scene-step="root"><span class="scene-step-dot" aria-hidden="true"></span><span><?= h(t('Preparing artwork', 'Preparando obra')) ?></span></div>
        <div class="scene-step" data-scene-step="scenes"><span class="scene-step-dot" aria-hidden="true"></span><span><?= h(t('Creating 4 scenes', 'Creando 4 escenas')) ?></span></div>
    </div>
    <small id="sceneWaitStatus"><?= h(t('This can take a moment.', 'Esto puede tardar un momento.')) ?></small>
    <details class="scene-error-details" id="sceneErrorDetails" hidden>
        <summary id="sceneErrorCode"><?= h(t('Technical details', 'Detalles técnicos')) ?></summary>
        <code id="sceneErrorMessage"></code>
    </details>
</main>

<script>
const job = <?= json_encode($job) ?>;
const i18n = {
    creatingScenes: <?= json_encode(t('Creating 4 scenes', 'Creando 4 escenas')) ?>,
    readyOpeningGenerator: <?= json_encode(t('Your artwork is ready. We are opening the scene generator.', 'Tu obra está lista. Estamos abriendo el generador de escenas.')) ?>,
    preparingArtwork: <?= json_encode(t('Preparing artwork', 'Preparando obra')) ?>,
    preparingCopy: <?= json_encode(t('We are preparing your artwork before creating the first scene views.', 'Estamos preparando tu obra antes de crear las primeras vistas de escena.')) ?>,
    preparationStopped: <?= json_encode(t('Artwork preparation stopped', 'La preparación de la obra se detuvo')) ?>,
    couldNotPrepare: <?= json_encode(t('We could not prepare this image for scene generation.', 'No pudimos preparar esta imagen para generar escenas.')) ?>,
    tryAgain: <?= json_encode(t('Please try again with a clearer, well-lit photo.', 'Volvé a intentarlo con una foto más clara y bien iluminada.')) ?>,
    errorCode: <?= json_encode(t('Error code:', 'Código de error:')) ?>,
    noTechnicalDetail: <?= json_encode(t('No additional technical detail was returned.', 'No se devolvió ningún detalle técnico adicional.')) ?>,
    preparingForGeneration: <?= json_encode(t('Preparing the artwork for scene generation.', 'Preparando la obra para generar escenas.')) ?>,
    startingPreparation: <?= json_encode(t('Starting artwork preparation.', 'Iniciando la preparación de la obra.')) ?>,
    stillWorking: <?= json_encode(t('Still working...', 'Seguimos trabajando...')) ?>,
};
const statusLabel = document.getElementById('sceneWaitStatus');
const title = document.getElementById('sceneWaitTitle');
const copy = document.getElementById('sceneWaitCopy');
const rootStep = document.querySelector('[data-scene-step="root"]');
const scenesStep = document.querySelector('[data-scene-step="scenes"]');
const errorDetails = document.getElementById('sceneErrorDetails');
const errorCode = document.getElementById('sceneErrorCode');
const errorMessage = document.getElementById('sceneErrorMessage');

function setStage(stage) {
    if (stage === 'scenes') {
        title.textContent = i18n.creatingScenes;
        copy.textContent = i18n.readyOpeningGenerator;
        rootStep.classList.remove('is-active');
        rootStep.classList.add('is-done');
        scenesStep.classList.add('is-active');
        return;
    }
    title.textContent = i18n.preparingArtwork;
    copy.textContent = i18n.preparingCopy;
    rootStep.classList.add('is-active');
    scenesStep.classList.remove('is-active');
}

async function pollSceneFlow() {
    try {
        const response = await fetch('job_status.php?job=' + encodeURIComponent(job) + '&t=' + Date.now(), { cache: 'no-store' });
        const data = await response.json();

        if (data.scene_redirect) {
            setStage('scenes');
            window.location.href = data.scene_redirect;
            return;
        }

        if (data.status === 'error') {
            rootStep.classList.remove('is-active');
            title.textContent = i18n.preparationStopped;
            copy.textContent = i18n.couldNotPrepare;
            statusLabel.textContent = data.user_message || i18n.tryAgain;
            errorCode.textContent = i18n.errorCode + ' ' + (data.error_code || 'ARTWORK_PREPARATION_FAILED');
            errorMessage.textContent = data.debug_error || i18n.noTechnicalDetail;
            errorDetails.hidden = false;
            return;
        }

        errorDetails.hidden = true;
        setStage('root');
        statusLabel.textContent = data.status === 'processing'
            ? i18n.preparingForGeneration
            : i18n.startingPreparation;
    } catch (err) {
        statusLabel.textContent = i18n.stillWorking;
    }
}

setInterval(pollSceneFlow, 3500);
pollSceneFlow();
</script>
</body>
</html>
