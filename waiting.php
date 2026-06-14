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

if ($currentStatus === 'done') {
    header('Location: root_select.php?job=' . urlencode($job));
    exit;
}

$statusLabels = [
    'queued' => 'Analyzing artwork composition...',
    'processing' => 'Evaluating visual atmosphere...',
    'done' => 'Refining artwork presentation...',
    'error' => 'Error',
];

$publicStatus = $statusLabels[(string)$currentStatus] ?? (string)$currentStatus;
$publicMessage = match ((string)$currentStatus) {
    'queued' => 'Analyzing artwork composition and structure...',
    'processing' => 'Evaluating visual atmosphere and dominant palette...',
    default => 'The system is preparing the artwork presentation.',
};

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Preparing Root Image - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">

    <style>
        .process-card {
            max-width: 980px;
            background: var(--surface);
            border: 1px solid var(--line);
            padding: 32px;
            box-shadow: var(--shadow);
            border-radius: var(--radius);
        }

        .process-card h2 {
            margin-bottom: 8px;
            font-size: clamp(34px, 5vw, 58px);
            letter-spacing: -0.01em;
            font-family: var(--font-serif);
            font-weight: 500;
        }

        .status-box {
            font-size: 14px;
            background: var(--surface-soft);
            padding: 16px;
            border-left: 3px solid var(--accent);
            margin: 24px 0;
            border-radius: 0 var(--radius) var(--radius) 0;
        }

        .progress {
            width: 100%;
            height: 6px;
            overflow: hidden;
            background: var(--line);
            margin: 26px 0;
            position: relative;
            border-radius: 99px;
        }

        .progress-bar {
            width: 42%;
            height: 100%;
            background: var(--accent);
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
            background: var(--surface-soft);
            border: 12px solid var(--surface);
            box-shadow: var(--shadow);
            border-radius: var(--radius);
        }

        .error {
            color: var(--danger);
            background: #FFF5F5;
            border-left-color: var(--danger);
        }

        code {
            background: var(--surface-soft);
            padding: 2px 5px;
            font-family: monospace;
            font-size: 12px;
            border-radius: 2px;
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
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($currentUser['email']) ?></a>
        </header>

        <div class="alert-strip">
            Step 1 · Create Root Image: preparing a faithful, clean and proportional base image for future mockups.
        </div>

        <div class="workspace">
            <section class="process-card">
                <?php if ($currentStatus === 'done' && $resultUrl): ?>

                    <h2>Root Image Created</h2>
                    <p class="page-kicker">Review the base image before proceeding to curatorial direction.</p>

                    <div class="status-box">
                        Root image is ready. Please inspect the framing and alignment.
                    </div>

                    <img class="root-preview" src="<?= h($resultUrl) ?>" alt="Generated root artwork">

                    <div class="topbar-actions">
                        <a class="button-link" href="form2.php?image=<?= rawurlencode(basename($resultFile)) ?>">
                            Proceed to Step 2 · Curatorial Direction
                        </a>

                        <a class="button-link secondary" href="artwork_new.php">
                            Upload another artwork
                        </a>
                    </div>

                <?php elseif ($currentStatus === 'error'): ?>

                    <h2>Generation Error</h2>

                    <div class="status-box error">
                        <?= h($error ?: $message ?: 'An unknown error occurred.') ?>
                    </div>

                    <p>Job ID: <code><?= h($job) ?></code></p>

                    <a class="button-link" href="artwork_new.php">Go back</a>

                <?php else: ?>

                    <h2>Preparing Root Image</h2>
                    <p class="page-kicker">You can leave this window open. The system will update the progress automatically.</p>

                    <div class="status-box" id="statusBox">
                        Status: <strong id="statusText"><?= h($publicStatus) ?></strong><br>
                        <span id="messageText"><?= h($publicMessage) ?></span>
                    </div>

                    <div class="progress" aria-label="Generating image">
                        <div class="progress-bar"></div>
                    </div>

                    <p>Job ID: <code><?= h($job) ?></code></p>

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
                                    queued: 'Analyzing artwork composition...',
                                    processing: 'Evaluating visual atmosphere...',
                                    done: 'Refining artwork presentation...',
                                    error: 'Error'
                                };

                                const messages = {
                                    queued: 'Analyzing artwork composition and structure...',
                                    processing: 'Evaluating visual atmosphere and dominant palette...',
                                    done: 'Artwork presentation refined. Reviewing base image...',
                                    error: 'Generation could not be completed.'
                                };

                                document.getElementById('statusText').textContent = labels[data.status] || data.status || 'Pending';
                                document.getElementById('messageText').textContent = data.message || messages[data.status] || 'The system is preparing the image.';

                                if (data.status === 'done') {
                                    window.location.href = 'root_select.php?job=' + encodeURIComponent(job);
                                } else if (data.status === 'error') {
                                    window.location.reload();
                                }

                            } catch (e) {
                                console.log('Waiting for status update...', e);
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
