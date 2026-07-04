<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();
$isAdmin = Auth::isAdmin($currentUser);

$job = $_GET['job'] ?? '';
$job = basename($job);

if (!$job) {
    die('Missing job.');
}

// 1. Cancel Action (safe abort)
if (isset($_GET['action']) && $_GET['action'] === 'cancel') {
    try {
        $pdo = Database::connection();
        // Verify ownership before deleting
        $stmtCheck = $pdo->prepare('SELECT user_id FROM artworks WHERE job_id = :job_id LIMIT 1');
        $stmtCheck->execute(['job_id' => $job]);
        $ownerId = $stmtCheck->fetchColumn();
        
        if ($ownerId !== false && (int)$ownerId === (int)$currentUser['id']) {
            $pdo->prepare('DELETE FROM artworks WHERE job_id = :job_id')->execute(['job_id' => $job]);
            
            // Delete folder
            $jobDir = __DIR__ . '/jobs/' . $job;
            if (is_dir($jobDir)) {
                $files = glob($jobDir . '/*') ?: [];
                foreach ($files as $file) {
                    if (is_file($file)) @unlink($file);
                }
                @rmdir($jobDir);
            }
        }
    } catch (Throwable $e) {
        // Fallback silently
    }
    header('Location: artwork_new.php');
    exit;
}

$jobDir = __DIR__ . '/jobs/' . $job;
$statusFile = $jobDir . '/status.json';

if (!is_file($statusFile)) {
    die('Job not found.');
}

$status = json_decode((string)file_get_contents($statusFile), true);

if (!$status) {
    die('Could not read job state.');
}

if ((int)($status['user_id'] ?? 0) !== (int)$currentUser['id']) {
    http_response_code(403);
    die('You do not have access to this job.');
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

$albumSlides = [];
try {
    $pdo = Database::connection();
    $randomOrder = Database::randomOrderSql();
    $stmt = $pdo->prepare("
        SELECT root_file
        FROM artworks
        WHERE user_id = :user_id
        AND root_file IS NOT NULL
        AND root_file != ''
        ORDER BY {$randomOrder}
        LIMIT 12
    ");
    $stmt->execute(['user_id' => (int)$currentUser['id']]);

    foreach ($stmt->fetchAll() as $row) {
        $file = basename((string)($row['root_file'] ?? ''));
        if ($file !== '' && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
            $albumSlides[$file] = 'media.php?file=' . rawurlencode($file);
        }
    }

    if (count($albumSlides) < 8) {
        $fallbackFiles = [];
        foreach (['*mockup*.jpg', '*mockup*.jpeg', '*mockup*.png', 'base_artwork*.png', 'base_artwork*.jpg'] as $pattern) {
            $fallbackFiles = array_merge($fallbackFiles, glob(RESULTS_DIR . DIRECTORY_SEPARATOR . $pattern) ?: []);
        }
        shuffle($fallbackFiles);
        foreach ($fallbackFiles as $path) {
            $file = basename($path);
            if (!isset($albumSlides[$file])) {
                $albumSlides[$file] = 'media.php?file=' . rawurlencode($file);
            }
            if (count($albumSlides) >= 12) {
                break;
            }
        }
    }
} catch (Throwable $e) {
    $albumSlides = [];
}

$albumSlides = array_values($albumSlides);
shuffle($albumSlides);

function read_admin_root_prompt(string $jobId, array $measurements = []): string
{
    $promptFile = __DIR__ . '/jobs/' . basename($jobId) . '/prompt.txt';

    if (is_file($promptFile)) {
        return trim((string)file_get_contents($promptFile));
    }

    return trim(PromptSettings::rootArtworkRules());
}

function admin_root_waiting_prompts(array $currentUser, string $currentJob): array
{
    $items = [];

    try {
        $pdo = Database::connection();
        $dateOrder = Database::dateOrderSql('created_at', 'DESC');
        $stmt = $pdo->prepare("
            SELECT job_id, main_file, status, width, height, depth, unit, created_at, updated_at
            FROM artworks
            WHERE user_id = :user_id
            AND status IN ('queued', 'processing')
            ORDER BY {$dateOrder}
            LIMIT 10
        ");
        $stmt->execute(['user_id' => (int)$currentUser['id']]);

        foreach ($stmt->fetchAll() as $row) {
            $jobId = basename((string)($row['job_id'] ?? ''));
            if ($jobId === '') {
                continue;
            }

            $row['prompt'] = read_admin_root_prompt($jobId, [
                'width' => $row['width'] ?? '',
                'height' => $row['height'] ?? '',
                'depth' => $row['depth'] ?? '',
                'unit' => $row['unit'] ?? 'cm',
            ]);
            $row['prompt_source'] = is_file(__DIR__ . '/jobs/' . $jobId . '/prompt.txt') ? 'prompt.txt' : 'current root rules fallback';
            $items[$jobId] = $row;
        }
    } catch (Throwable $e) {
        $items = [];
    }

    if ($currentJob !== '' && !isset($items[$currentJob])) {
        $items[$currentJob] = [
            'job_id' => $currentJob,
            'main_file' => basename((string)($GLOBALS['status']['main_file'] ?? '')),
            'status' => (string)($GLOBALS['currentStatus'] ?? 'unknown'),
            'width' => (string)($GLOBALS['status']['measurements']['width'] ?? ''),
            'height' => (string)($GLOBALS['status']['measurements']['height'] ?? ''),
            'depth' => (string)($GLOBALS['status']['measurements']['depth'] ?? ''),
            'unit' => (string)($GLOBALS['status']['measurements']['unit'] ?? 'cm'),
            'created_at' => (string)($GLOBALS['status']['created_at'] ?? ''),
            'updated_at' => (string)($GLOBALS['status']['updated_at'] ?? ''),
            'prompt' => read_admin_root_prompt($currentJob, (array)($GLOBALS['status']['measurements'] ?? [])),
            'prompt_source' => is_file(__DIR__ . '/jobs/' . $currentJob . '/prompt.txt') ? 'prompt.txt' : 'current root rules fallback',
        ];
    }

    return array_values($items);
}

$adminWaitingPrompts = $isAdmin ? admin_root_waiting_prompts($currentUser, $job) : [];

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
        body {
            background: var(--bg);
        }

        .main-area {
            background: var(--bg);
        }

        .album-wait {
            min-height: calc(100vh - 86px);
            box-sizing: border-box;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 24px;
        }

        .process-card {
            width: min(460px, calc(100vw - 48px));
            background: var(--surface);
            border: 1px solid var(--line);
            padding: 30px 28px;
            box-shadow: var(--shadow);
            border-radius: 8px;
            color: var(--ink);
            position: relative;
            z-index: 3;
            margin: 0;
            text-align: center;
        }

        .process-card h2 {
            margin: 0 0 8px;
            font-size: 24px;
            letter-spacing: -0.01em;
            font-family: var(--font-serif);
            font-weight: 500;
            color: var(--ink);
        }

        .process-card .page-kicker {
            color: var(--muted);
            margin-top: 0;
            font-size: 13px;
            margin-bottom: 20px;
        }

        .status-box {
            font-size: 13px;
            background: var(--surface-soft);
            padding: 12px 14px;
            border-left: 3px solid var(--accent);
            margin: 18px 0;
            border-radius: 0 var(--radius) var(--radius) 0;
            text-align: left;
        }

        .artist-wait-tip {
            min-height: 48px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
            text-align: left;
        }

        .artist-wait-tip strong {
            color: var(--accent);
            font-weight: 600;
        }

        .progress {
            width: 100%;
            height: 4px;
            overflow: hidden;
            background: var(--line);
            margin: 16px 0;
            position: relative;
            border-radius: 99px;
        }

        .progress-bar {
            width: 42%;
            height: 100%;
            background: linear-gradient(90deg, var(--accent), #e2c183);
            box-shadow: 0 0 18px rgba(214, 178, 122, 0.30);
            position: absolute;
            left: -42%;
            top: 0;
            animation: progressMove 1.6s ease-in-out infinite;
        }

        .process-card::before {
            content: "";
            display: block;
            width: 38px;
            height: 38px;
            margin: 0 auto 18px;
            border-radius: 50%;
            border: 2px solid var(--line);
            border-top-color: var(--accent);
            animation: simpleSpin 0.9s linear infinite;
        }

        @keyframes simpleSpin {
            to { transform: rotate(360deg); }
        }

        @keyframes progressMove {
            0% { left: -42%; }
            55% { left: 100%; }
            100% { left: 100%; }
        }

        .error {
            color: var(--danger);
            background: rgba(166, 60, 60, 0.1);
            border-left-color: var(--danger);
        }

        code {
            background: rgba(255, 255, 255, 0.12);
            padding: 2px 5px;
            font-family: monospace;
            font-size: 11px;
            border-radius: 2px;
        }

        /* Collapsible admin panel layout below card */
        .admin-prompts-details {
            margin-top: 24px;
            width: min(460px, calc(100vw - 48px));
            z-index: 3;
        }

        .admin-prompts-summary {
            cursor: pointer;
            font-weight: 600;
            color: rgba(247, 242, 234, 0.6);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            text-align: center;
            list-style: none;
            transition: all 0.2s ease;
        }

        .admin-prompts-summary:hover {
            color: rgba(247, 242, 234, 0.9);
            background: rgba(255, 255, 255, 0.07);
        }

        .admin-root-prompts {
            margin-top: 12px;
            padding: 16px;
            background: rgba(25, 24, 22, 0.95);
            border: 1px solid rgba(214, 178, 122, 0.2);
            border-radius: 6px;
            text-align: left;
            max-height: 400px;
            overflow-y: auto;
        }

        .admin-root-prompts h3 {
            margin: 0 0 4px;
            font-family: var(--font-serif);
            font-size: 18px;
            font-weight: 500;
            color: #f7f2ea;
        }

        .admin-root-prompts > p {
            margin: 0 0 14px;
            color: rgba(247, 242, 234, 0.6);
            font-size: 11px;
            line-height: 1.45;
        }

        .admin-prompt-job {
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            background: rgba(0, 0, 0, 0.2);
            margin-top: 10px;
            overflow: hidden;
        }

        .admin-prompt-job summary {
            cursor: pointer;
            padding: 8px 10px;
            color: #f7f2ea;
            font-size: 11px;
            font-weight: 600;
        }

        .admin-prompt-meta {
            display: grid;
            gap: 3px;
            padding: 0 10px 10px;
            color: rgba(247, 242, 234, 0.6);
            font-size: 10px;
            line-height: 1.35;
        }

        .admin-prompt-actions {
            display: flex;
            justify-content: flex-end;
            padding: 0 10px 10px;
        }

        .admin-prompt-actions button {
            width: auto;
            margin: 0;
            padding: 4px 8px;
            font-size: 10px;
        }

        .admin-prompt-text {
            display: block;
            width: calc(100% - 20px);
            min-height: 180px;
            margin: 0 10px 10px;
            padding: 8px;
            resize: vertical;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 4px;
            background: rgba(0, 0, 0, 0.3);
            color: #f7f2ea;
            font-family: Consolas, Monaco, monospace;
            font-size: 10px;
            line-height: 1.4;
            box-sizing: border-box;
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
            Step 1 · Create Base Image: preparing a faithful, clean and proportional base image for future mockups.
        </div>

        <div class="workspace album-wait">
            <section class="process-card">
                <?php if ($currentStatus === 'done' && $resultUrl): ?>

                    <h2>Root Image Created</h2>
                    <p class="page-kicker">Review the base image before proceeding to curatorial direction.</p>

                    <div class="status-box">
                        Root image is ready. Please inspect the framing and alignment.
                    </div>

                    <img class="root-preview" src="<?= h($resultUrl) ?>" alt="Generated root artwork">

                    <div class="topbar-actions">
                        <a class="button-link" href="report.php?image=<?= rawurlencode(basename($resultFile)) ?>">
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

                    <p style="font-size: 11px; margin-bottom: 20px;">Job ID: <code><?= h($job) ?></code></p>
                    
                    <div class="artist-wait-tip" id="artistWaitTip"></div>

                    <!-- Cancel upload option to avoid trapped users -->
                    <div style="margin-top: 24px;">
                        <a href="waiting.php?action=cancel&job=<?= urlencode($job) ?>" class="button secondary" style="font-size: 11px; padding: 10px 18px; text-decoration: none;">Cancel Upload</a>
                    </div>

                    <script>
                        const job = <?= json_encode($job) ?>;
                        const statusUrl = 'job_status.php?job=' + encodeURIComponent(job);
                        const waitTips = [
                            ['Artist Profile', 'A complete profile helps the system choose better spaces, atmosphere and market positioning.'],
                            ['If a result feels wrong', 'Try another root image or regenerate from a cleaner, more frontal base.'],
                            ['Best mockup signal', 'Look for faithful color, believable scale, wall contact and a context that does not compete with the artwork.'],
                            ['Publishing', 'Title, dimensions, technique and a short statement make the final artwork page stronger.']
                        ];
                        let waitTipIndex = 0;

                        function rotateWaitTip() {
                            const target = document.getElementById('artistWaitTip');
                            if (!target) return;
                            const tip = waitTips[waitTipIndex % waitTips.length];
                            target.innerHTML = '<strong>' + tip[0] + ':</strong> ' + tip[1];
                            waitTipIndex++;
                        }

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
                        setInterval(rotateWaitTip, 6200);
                        rotateWaitTip();
                        checkStatus();

                    </script>

                <?php endif; ?>
            </section>

            <!-- Collapsible Admin Console Section below the card -->
            <?php if ($isAdmin && !empty($adminWaitingPrompts)): ?>
                <details class="admin-prompts-details">
                    <summary class="admin-prompts-summary">Admin console - View Root Prompts</summary>
                    <aside class="admin-root-prompts" aria-label="Admin root prompts">
                        <h3>Admin - Root Prompts</h3>
                        <p>Complete prompts used for root images currently waiting. Only visible to administrators.</p>

                        <?php foreach ($adminWaitingPrompts as $index => $promptJob): ?>
                            <?php
                                $promptJobId = basename((string)($promptJob['job_id'] ?? ''));
                                $promptTextId = 'adminRootPrompt' . $index;
                                $dims = trim(
                                    (string)($promptJob['width'] ?? '') . ' x ' .
                                    (string)($promptJob['height'] ?? '') .
                                    (((string)($promptJob['depth'] ?? '') !== '') ? ' x ' . (string)$promptJob['depth'] : '') . ' ' .
                                    (string)($promptJob['unit'] ?? '')
                                );
                            ?>
                            <details class="admin-prompt-job" <?= $promptJobId === $job ? 'open' : '' ?>>
                                <summary><?= h($promptJobId) ?> - <?= h((string)($promptJob['status'] ?? 'unknown')) ?></summary>
                                <div class="admin-prompt-meta">
                                    <span>File: <?= h((string)($promptJob['main_file'] ?? '')) ?></span>
                                    <span>Measurements: <?= h($dims) ?></span>
                                    <span>Source: <?= h((string)($promptJob['prompt_source'] ?? '')) ?></span>
                                </div>
                                <div class="admin-prompt-actions">
                                    <button type="button" class="button secondary admin-copy-prompt" data-target="<?= h($promptTextId) ?>">Copy prompt</button>
                                </div>
                                <textarea id="<?= h($promptTextId) ?>" class="admin-prompt-text" readonly><?= h((string)($promptJob['prompt'] ?? '')) ?></textarea>
                            </details>
                        <?php endforeach; ?>
                    </aside>
                </details>

                <script>
                    document.querySelectorAll('.admin-copy-prompt').forEach((button) => {
                        button.addEventListener('click', async () => {
                            const target = document.getElementById(button.dataset.target);
                            if (!target) return;

                            target.select();
                            target.setSelectionRange(0, target.value.length);

                            try {
                                await navigator.clipboard.writeText(target.value);
                            } catch (e) {
                                document.execCommand('copy');
                            }

                            const originalText = button.textContent;
                            button.textContent = 'Copied';
                            setTimeout(() => {
                                button.textContent = originalText;
                            }, 1400);
                        });
                    });
                </script>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
