<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Publish & Export - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .coming-soon-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 60px 40px;
            text-align: center;
            max-width: 600px;
            margin: 60px auto;
        }
        .coming-soon-card h2 {
            font-family: var(--font-serif);
            font-size: 28px;
            color: var(--accent);
            margin-bottom: 16px;
        }
        .coming-soon-card p {
            color: var(--muted);
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .hub-features {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            text-align: left;
            margin-top: 40px;
            border-top: 1px dashed var(--line);
            padding-top: 30px;
        }
        .feature-item h4 {
            font-family: var(--font-serif);
            font-size: 16px;
            margin: 0 0 6px 0;
            color: var(--ink);
        }
        .feature-item p {
            font-size: 12px;
            margin: 0;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            Step 4 · Distribution Hub: Seamlessly export listings and publish mockups directly.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Publish & Export</h1>
                    <p>Distribute your curated artwork mockups and listings across marketplaces and social networks.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="dashboard.php">Dashboard</a>
                </div>
            </div>

            <div class="coming-soon-card">
                <h2>Publishing Hub coming in the next Beta</h2>
                <p>
                    We are currently building direct API integrations for Pinterest scheduling, Saatchi Art metadata auto-fill, and Instagram business publisher sync.
                </p>
                <a class="button-link" href="dashboard.php">Back to Dashboard</a>

                <div class="hub-features">
                    <div class="feature-item">
                        <h4>Pinterest Direct Pin</h4>
                        <p>Post selected mockups directly to your Pinterest boards with SEO titles, keywords and destination links.</p>
                    </div>
                    <div class="feature-item">
                        <h4>Saatchi Art Sync</h4>
                        <p>Automatically package and copy title, orientation, keywords and descriptions formatted for Saatchi Art uploading.</p>
                    </div>
                    <div class="feature-item">
                        <h4>Social Media Copy</h4>
                        <p>Generate optimized social captions (Instagram, LinkedIn) matching the mood and style of each mockup environment.</p>
                    </div>
                    <div class="feature-item">
                        <h4>Batch Export</h4>
                        <p>Download all mockups with SEO-optimized filenames alongside a CSV mapping all descriptions and keywords.</p>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>
