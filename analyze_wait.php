<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();
$image = basename(trim((string)($_GET['image'] ?? '')));

if ($image === '') {
    header('Location: dashboard.php');
    exit;
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Recalculating Analysis - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        html,
        body {
            height: 100%;
            zoom: 1;
        }

        .analysis-wait {
            min-height: calc(100vh - 66px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--bg);
        }

        .analysis-card {
            width: min(380px, calc(100vw - 48px));
            text-align: center;
            color: var(--ink);
        }

        .analysis-card::before {
            content: "";
            display: block;
            width: 34px;
            height: 34px;
            margin: 0 auto 18px;
            border-radius: 50%;
            border: 2px solid var(--line);
            border-top-color: var(--accent);
            animation: spin 0.9s linear infinite;
        }

        .analysis-card h1 {
            margin: 0 0 8px;
            font-size: 22px;
            color: var(--ink);
        }

        .analysis-card p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .analysis-error {
            margin-top: 18px;
            color: var(--danger);
            font-size: 13px;
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
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

        <div class="analysis-wait">
            <section class="analysis-card">
                <h1>Recalculating analysis</h1>
                <p>Preparing new curatorial directions for this artwork.</p>
                <div class="analysis-error" id="analysisError"></div>
            </section>
        </div>
    </main>
</div>

<script>
    const image = <?= json_encode($image, JSON_UNESCAPED_SLASHES) ?>;
    const errorBox = document.getElementById('analysisError');

    async function runAnalysis() {
        try {
            const response = await fetch('analyze.php?image=' + encodeURIComponent(image), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                cache: 'no-store'
            });
            const rawText = await response.text();
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (error) {
                throw new Error(rawText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() || 'Analysis returned an invalid response.');
            }

            if (!response.ok || data.ok === false) {
                throw new Error(data.error || 'Could not recalculate analysis.');
            }

            window.location.href = 'report.php?image=' + encodeURIComponent(image);
        } catch (error) {
            errorBox.style.display = 'block';
            errorBox.textContent = error.message;
        }
    }

    runAnalysis();
</script>
</body>
</html>
