<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();
$isAdmin = Auth::isAdmin($currentUser);

$job = basename((string)($_GET['job'] ?? ''));

if ($job === '') {
    // Smart redirect: find the latest pending job for this user
    $db = Database::connection();
    $stmt = $db->prepare("SELECT job_id FROM artworks WHERE user_id = :user_id AND status = 'awaiting_selection' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute(['user_id' => $currentUser['id']]);
    $latestPending = $stmt->fetch();
    if ($latestPending && !empty($latestPending['job_id'])) {
        header('Location: root_select.php?job=' . urlencode((string)$latestPending['job_id']));
        exit;
    } else {
        // No pending selection, redirect to dashboard.php's pending section
        header('Location: dashboard.php#pendientes');
        exit;
    }
}

$jobDir = __DIR__ . '/jobs/' . $job;
$statusFile = $jobDir . '/status.json';

if (!is_file($statusFile)) {
    die('Job not found.');
}

$status = json_decode((string)file_get_contents($statusFile), true);

if (!is_array($status)) {
    die('Could not load job state.');
}

if ((int)($status['user_id'] ?? 0) !== (int)$currentUser['id']) {
    http_response_code(403);
    die('You do not have access to this job.');
}

if (($status['status'] ?? '') !== 'done') {
    header('Location: waiting.php?job=' . urlencode($job));
    exit;
}

$candidates = $status['candidates'] ?? [];
$originalFile = $status['main_file'] ?? '';
$originalUrl = 'jobs/' . h($job) . '/' . h($originalFile);

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Select Root Image - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            --font-serif: 'Cormorant Garamond', Georgia, serif;
            --font-sans: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --gal-bg: #FAF9F6;
            --gal-surface: #FFFFFF;
            --gal-surface-soft: #F4F3EE;
            --gal-border: #E5E3DD;
            --gal-ink: #141412;
            --gal-muted: #7A7872;
            --gal-accent: #9A7B56;
            --gal-accent-light: #F6F3EE;
            --gal-accent-hover: #7E6342;
            --gal-shadow: 0 4px 30px rgba(20, 20, 18, 0.02);
            --gal-shadow-hover: 0 20px 48px rgba(20, 20, 18, 0.05);
            --gal-radius: 4px;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: var(--font-sans);
            background: var(--gal-bg);
            color: var(--gal-ink);
            line-height: 1.6;
            zoom: 0.7;
        }

        .workspace {
            padding: 30px 40px 54px;
            max-width: 1440px;
            margin: 0 auto;
        }

        h1 {
            font-family: var(--font-serif);
            font-size: 38px;
            font-weight: 500;
            margin: 0 0 8px;
            letter-spacing: -0.01em;
        }

        .page-kicker {
            font-size: 15px;
            color: var(--gal-muted);
            margin: 0 0 32px;
            max-width: 800px;
            font-weight: 300;
        }

        /* Layout Grid */
        .selection-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 48px;
            align-items: start;
        }

        /* Original Reference */
        .reference-panel {
            background: var(--gal-surface);
            border: 1px solid var(--gal-border);
            padding: 20px;
            border-radius: var(--gal-radius);
            box-shadow: var(--gal-shadow);
        }

        .reference-panel h3 {
            font-family: var(--font-serif);
            font-size: 20px;
            margin: 0 0 16px;
            font-weight: 500;
            border-bottom: 1px solid var(--gal-border);
            padding-bottom: 10px;
        }

        .reference-img {
            width: 100%;
            height: auto;
            border-radius: 2px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            display: block;
        }

        /* Candidates Grid */
        .candidates-area h2 {
            font-family: var(--font-serif);
            font-size: 24px;
            font-weight: 500;
            margin: 0 0 20px;
            border-bottom: 1px solid var(--gal-border);
            padding-bottom: 12px;
        }

        .candidates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
        }

        .candidate-card {
            background: var(--gal-surface);
            border: 1px solid var(--gal-border);
            border-radius: var(--gal-radius);
            padding: 18px;
            display: flex;
            flex-direction: column;
            box-shadow: var(--gal-shadow);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            cursor: pointer;
        }

        .candidate-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--gal-shadow-hover);
            border-color: var(--gal-accent);
        }

        .candidate-card.selected-active {
            border-color: var(--gal-accent);
            background: var(--gal-accent-light);
        }

        .candidate-num {
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--gal-accent);
            margin-bottom: 8px;
        }

        .candidate-frame {
            width: 100%;
            aspect-ratio: 4 / 5;
            overflow: hidden;
            background: var(--gal-surface-soft);
            border: 1px solid var(--gal-border);
            border-radius: 2px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .candidate-frame img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
            transition: transform 0.3s ease;
        }

        .candidate-card:hover .candidate-frame img {
            transform: scale(1.02);
        }

        .select-btn {
            width: 100%;
            border: 1px solid var(--gal-accent);
            background: var(--gal-surface);
            color: var(--gal-accent);
            padding: 10px 16px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            cursor: pointer;
            border-radius: var(--gal-radius);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            margin-top: auto;
        }

        .candidate-card:hover .select-btn {
            background: var(--gal-accent);
            color: var(--gal-surface);
        }

        /* Overlay loader during analysis */
        .global-loader {
            position: fixed;
            inset: 0;
            background: rgba(250, 249, 246, 0.9);
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(4px);
        }

        .global-loader.active {
            display: flex;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--gal-border);
            border-top-color: var(--gal-accent);
            border-radius: 50%;
            animation: spin 0.85s linear infinite;
            margin-bottom: 20px;
        }

        .loader-text {
            font-family: var(--font-serif);
            font-size: 22px;
            color: var(--gal-ink);
            text-align: center;
        }

        .loader-sub {
            font-size: 13px;
            color: var(--gal-muted);
            margin-top: 6px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 900px) {
            .selection-layout {
                grid-template-columns: 1fr;
                gap: 32px;
            }
        }

        /* Lightbox styling */
        .zoom-trigger-btn:hover {
            background: var(--gal-accent) !important;
            border-color: var(--gal-accent) !important;
            transform: scale(1.1);
        }
        .zoom-trigger-btn:hover svg {
            stroke: var(--gal-surface) !important;
        }
        .lightbox-modal {
            position: fixed;
            inset: 0;
            background: rgba(20, 20, 18, 0.95);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            backdrop-filter: blur(8px);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .lightbox-modal.active {
            display: flex;
            opacity: 1;
        }
        .lightbox-content {
            position: relative;
            max-width: 90vw;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .lightbox-content img {
            max-width: 100%;
            max-height: 80vh;
            object-fit: contain;
            border: 4px solid var(--gal-surface);
            box-shadow: 0 10px 50px rgba(0,0,0,0.5);
            border-radius: 2px;
        }
        .lightbox-close {
            position: absolute;
            top: -45px;
            right: 0;
            background: transparent;
            border: none;
            color: var(--gal-surface);
            font-size: 36px;
            cursor: pointer;
            transition: color 0.2s;
            line-height: 1;
        }
        .lightbox-close:hover {
            color: var(--gal-accent);
        }
        .lightbox-caption {
            color: var(--gal-surface);
            font-family: var(--font-serif);
            font-size: 18px;
            margin-top: 15px;
            letter-spacing: 0.05em;
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
            Candidate Selection: Review the generated versions and choose the most frontal, clean and cropped image to act as the official root.
        </div>

        <div class="workspace">
            <h1>Select Root Image Version</h1>
            <p class="page-kicker">
                We generated 3 candidates of your root image to prevent rate/crop errors. Select the best one to proceed.
            </p>

            <div class="selection-layout">
                <!-- Left panel: Original reference image -->
                <aside class="reference-panel">
                    <h3>Original Upload</h3>
                    <img class="reference-img" src="<?= h($originalUrl) ?>" alt="Original uploaded image">
                </aside>

                <!-- Right area: Candidate selector -->
                <section class="candidates-area">
                    <h2>3 Candidates Generated</h2>

                    <div class="candidates-grid">
                        <?php foreach ($candidates as $idx => $candidate): ?>
                            <?php $candidateUrl = 'media.php?file=' . rawurlencode($candidate); ?>
                            <div class="candidate-card" id="card_<?= $idx ?>" onclick="selectCandidate('<?= h($candidate) ?>', <?= $idx ?>)">
                                <div class="candidate-num">Version <?= $idx + 1 ?></div>
                                <div class="candidate-frame-wrapper" style="position: relative; margin-bottom: 16px;">
                                    <div class="candidate-frame" style="margin-bottom: 0;">
                                        <img src="<?= h($candidateUrl) ?>" alt="Candidate Version <?= $idx + 1 ?>">
                                    </div>
                                    <button type="button" class="zoom-trigger-btn" onclick="openLightbox('<?= h($candidateUrl) ?>', 'Version <?= $idx + 1 ?>'); event.stopPropagation();" title="Zoom in / check details" style="position: absolute; right: 10px; top: 10px; background: rgba(255,255,255,0.95); border: 1px solid var(--gal-border); border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; box-shadow: var(--gal-shadow); z-index: 10;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gal-ink);"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="11" y1="8" x2="11" y2="14"></line><line x1="8" y1="11" x2="14" y2="11"></line></svg>
                                    </button>
                                </div>
                                <button type="button" class="select-btn" id="btn_<?= $idx ?>">
                                    Select Version <?= $idx + 1 ?>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<!-- Lightbox Modal -->
<div class="lightbox-modal" id="lightboxModal" onclick="closeLightbox()">
    <div class="lightbox-content" onclick="event.stopPropagation()">
        <button class="lightbox-close" onclick="closeLightbox()">&times;</button>
        <img id="lightboxImage" src="" alt="Zoomed candidate">
        <div id="lightboxCaption" class="lightbox-caption"></div>
    </div>
</div>

<!-- Loading overlay -->
<div class="global-loader" id="globalLoader">
    <div class="spinner"></div>
    <div class="loader-text">Analyzing Artwork Structure...</div>
    <div class="loader-sub">Generating curatorial contexts based on your selection.</div>
</div>

<script>
    function openLightbox(url, title) {
        const modal = document.getElementById('lightboxModal');
        const img = document.getElementById('lightboxImage');
        const cap = document.getElementById('lightboxCaption');
        
        img.src = url;
        cap.textContent = title;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        const modal = document.getElementById('lightboxModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    async function selectCandidate(filename, index) {
        // Highlight active card
        document.querySelectorAll('.candidate-card').forEach(c => c.classList.remove('selected-active'));
        document.getElementById('card_' + index).classList.add('selected-active');

        // Show loading overlay
        const loader = document.getElementById('globalLoader');
        loader.classList.add('active');

        try {
            const formData = new FormData();
            formData.append('job', <?= json_encode($job) ?>);
            formData.append('filename', filename);

            const response = await fetch('select_root.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Could not select root image.');
            }

            // Redirect to step 2 (Curatorial direction)
            window.location.href = data.redirect;

        } catch (err) {
            alert('Error: ' + err.message);
            loader.classList.remove('active');
        }
    }
</script>

</body>
</html>
