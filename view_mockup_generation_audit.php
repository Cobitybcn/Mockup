<?php
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo  = Database::connection();

$mockupId    = max(0, (int)($_GET['id'] ?? $_GET['mockup_id'] ?? 0));
$artworkId   = max(0, (int)($_GET['artwork_id'] ?? 0));
$rawFilename = trim((string)($_GET['file'] ?? ''));

if ($mockupId > 0 && ($artworkId <= 0 || $rawFilename === '')) {
    try {
        $stmtMockup = $pdo->prepare('SELECT artwork_file FROM mockups WHERE id = :id LIMIT 1');
        $stmtMockup->execute(['id' => $mockupId]);
        $artworkFile = $stmtMockup->fetchColumn();

        if ($artworkFile) {
            $stmtArtwork = $pdo->prepare('SELECT id FROM artworks WHERE root_file = :root_file LIMIT 1');
            $stmtArtwork->execute(['root_file' => basename($artworkFile)]);
            $artworkId = (int)$stmtArtwork->fetchColumn();
        }

        if ($artworkId > 0) {
            $dir = __DIR__ . '/analysis/mockup-generation-audit/' . $artworkId;
            if (is_dir($dir)) {
                $files = scandir($dir);
                if ($files !== false) {
                    foreach ($files as $file) {
                        if (str_ends_with($file, '.generation.json')) {
                            $content = file_get_contents($dir . '/' . $file);
                            if ($content !== false) {
                                $auditJson = json_decode($content, true);
                                if (is_array($auditJson) && isset($auditJson['queued_or_generated_mockup_id']) && (int)$auditJson['queued_or_generated_mockup_id'] === $mockupId) {
                                    $rawFilename = $file;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {}
}

// ── 1. Basic input validation ───────────────────────────────────────────────

if ($artworkId <= 0 || $rawFilename === '') {
    http_response_code(400);
    exit('Missing artwork_id or file parameter (or unable to resolve from mockup ID).');
}

// ── 2. Verify artwork ownership ─────────────────────────────────────────────

if (Auth::isAdmin($user)) {
    $stmtArtwork = $pdo->prepare('SELECT id FROM artworks WHERE id = :id LIMIT 1');
    $stmtArtwork->execute(['id' => $artworkId]);
} else {
    $stmtArtwork = $pdo->prepare('SELECT id FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
    $stmtArtwork->execute(['id' => $artworkId, 'user_id' => $user['id']]);
}
if (!$stmtArtwork->fetchColumn()) {
    http_response_code(403);
    exit('Access denied.');
}

// ── 3. Resolve and sanitise path ────────────────────────────────────────────
// Only allow plain filenames — no directory separators, no dots that traverse.

$safeFilename = basename($rawFilename);

// Only allow *.generation.json files from this endpoint.
if (!preg_match('/^[A-Za-z0-9._-]+\.generation\.json$/', $safeFilename)) {
    http_response_code(400);
    exit('Invalid audit filename format.');
}

$auditDir  = __DIR__ . '/analysis/mockup-generation-audit/' . $artworkId;
$auditPath = $auditDir . DIRECTORY_SEPARATOR . $safeFilename;

// ── 4. Strict path containment check ────────────────────────────────────────

$realDir  = realpath($auditDir);
$realPath = realpath($auditPath);

if ($realDir === false || $realPath === false) {
    http_response_code(404);
    exit('Audit file not found.');
}

// Ensure the resolved path is genuinely inside the artwork audit directory.
if (!str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)
    && $realPath !== $realDir) {
    http_response_code(403);
    exit('Path traversal detected.');
}

// ── 5. Read and validate JSON ────────────────────────────────────────────────

$raw = file_get_contents($realPath);
if ($raw === false) {
    http_response_code(500);
    exit('Could not read audit file.');
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(500);
    exit('Audit file contains invalid JSON.');
}

// ── 6. Render readable audit viewer ─────────────────────────────────────────

if (isset($_GET['raw']) && $_GET['raw'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: inline; filename="' . addslashes($safeFilename) . '"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper
function h_audit($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mockup Generation Audit — Artwork <?= (int)$artworkId ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .audit-panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }
        .audit-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .audit-item {
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 10px 14px;
        }
        .audit-item span {
            display: block;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--muted);
            letter-spacing: 0.05em;
            margin-bottom: 4px;
        }
        .audit-item code {
            font-family: monospace;
            font-size: 12px;
            color: var(--ink);
            word-break: break-all;
        }
        .prompt-box {
            width: 100%;
            min-height: 300px;
            font-family: monospace;
            font-size: 12px;
            line-height: 1.6;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface-soft);
            color: var(--ink);
            resize: vertical;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .badge-ok {
            display: inline-block;
            padding: 3px 9px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-radius: 3px;
            background: #E6FFFA;
            color: #234E52;
            border: 1px solid rgba(35,78,82,.2);
        }
        .badge-fail {
            background: #FFF5F5;
            color: var(--danger);
            border-color: rgba(166,60,60,.2);
        }
        .section-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--muted);
            letter-spacing: 0.05em;
            margin: 0 0 8px 0;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h_audit($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            Mockup Generation Audit — Read-only inspection of the Admin V7 composed prompt generation record.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Generation Audit</h1>
                    <p>Artwork #<?= (int)$artworkId ?> &mdash; <?= h_audit($safeFilename) ?></p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="mockup_prompt_drafts_review.php?id=<?= (int)$artworkId ?>">← Back to Prompt Review</a>
                    <a class="button-link secondary" href="view_mockup_generation_audit.php?artwork_id=<?= (int)$artworkId ?>&file=<?= urlencode($safeFilename) ?>&raw=1" target="_blank">View Raw JSON</a>
                </div>
            </div>

            <div class="audit-panel">
                <h2 style="margin-top:0; margin-bottom:16px; font-family: var(--font-serif); font-size:22px;">Generation Record</h2>

                <div class="audit-grid">
                    <div class="audit-item">
                        <span>Schema</span>
                        <code><?= h_audit($data['schema'] ?? 'N/A') ?></code>
                    </div>
                    <div class="audit-item">
                        <span>Generation Source</span>
                        <code><?= h_audit($data['generation_source'] ?? 'N/A') ?></code>
                    </div>
                    <div class="audit-item">
                        <span>Prompt Authority</span>
                        <code><?= h_audit($data['prompt_authority'] ?? 'N/A') ?></code>
                    </div>
                    <div class="audit-item">
                        <span>Admin Prompt Source</span>
                        <code><?= h_audit($data['admin_prompt_source'] ?? 'N/A') ?></code>
                    </div>
                    <div class="audit-item">
                        <span>Artwork ID</span>
                        <code><?= h_audit($data['artwork_id'] ?? 'N/A') ?></code>
                    </div>
                    <div class="audit-item">
                        <span>Context ID</span>
                        <code><?= h_audit($data['context_id'] ?? 'N/A') ?></code>
                    </div>
                    <div class="audit-item">
                        <span>Context Name</span>
                        <code><?= h_audit($data['context_name'] ?? 'N/A') ?></code>
                    </div>
                    <div class="audit-item">
                        <span>Proposal Index</span>
                        <code><?= h_audit($data['proposal_index'] ?? 'N/A') ?></code>
                    </div>
                    <div class="audit-item">
                        <span>Status</span>
                        <code class="<?= ($data['status'] ?? '') === 'generated' ? 'badge-ok' : '' ?>"><?= h_audit($data['status'] ?? 'N/A') ?></code>
                    </div>
                    <div class="audit-item">
                        <span>Prompt Exact Match</span>
                        <?php $match = $data['prompt_exact_match'] ?? null; ?>
                        <span class="<?= $match === true ? 'badge-ok' : 'badge-fail' ?>"><?= $match === true ? '✓ Yes' : ($match === false ? '✗ No' : 'N/A') ?></span>
                    </div>
                    <div class="audit-item">
                        <span>Generated Mockup ID</span>
                        <code><?= h_audit($data['queued_or_generated_mockup_id'] ?? 'N/A') ?></code>
                    </div>
                    <div class="audit-item">
                        <span>Generated Mockup File</span>
                        <code><?= h_audit($data['queued_or_generated_mockup_file'] ?? 'N/A') ?></code>
                    </div>
                    <div class="audit-item">
                        <span>Camera View</span>
                        <code><?= h_audit($data['camera_view'] ?? 'N/A') ?></code>
                    </div>
                    <div class="audit-item">
                        <span>Camera Distance</span>
                        <code><?= h_audit($data['camera_distance'] ?? 'N/A') ?></code>
                    </div>
                    <div class="audit-item">
                        <span>Generated At</span>
                        <code><?= h_audit($data['queued_or_generated_at'] ?? 'N/A') ?></code>
                    </div>
                    <div class="audit-item">
                        <span>Placeholder</span>
                        <code><?= h_audit($data['admin_prompt_placeholder'] ?? 'N/A') ?></code>
                    </div>
                </div>

                <?php if (!empty($data['warnings'])): ?>
                    <div class="notice error" style="margin-bottom:20px;">
                        <strong>Warnings:</strong>
                        <ul style="margin:5px 0 0 20px; padding:0;">
                            <?php foreach ((array)$data['warnings'] as $w): ?>
                                <li><?= h_audit($w) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($data['queued_or_generated_mockup_id'])): ?>
                    <div style="margin-bottom: 20px;">
                        <a class="button-link" style="background:#1a73e8;color:#fff;border-color:#1a73e8;font-weight:600;padding:8px 16px;border-radius:4px;display:inline-block;text-decoration:none;"
                           href="view_mockup_file.php?mockup_id=<?= (int)$data['queued_or_generated_mockup_id'] ?>" target="_blank">
                            View Generated Mockup Image
                        </a>
                    </div>
                <?php elseif (!empty($data['queued_or_generated_mockup_file'])): ?>
                    <div style="margin-bottom: 20px;">
                        <a class="button-link" style="background:#1a73e8;color:#fff;border-color:#1a73e8;font-weight:600;padding:8px 16px;border-radius:4px;display:inline-block;text-decoration:none;"
                           href="view_mockup_file.php?file=<?= urlencode((string)$data['queued_or_generated_mockup_file']) ?>" target="_blank">
                            View Generated Mockup Image
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="audit-panel">
                <h3 style="margin-top:0; margin-bottom:12px; font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--muted);">Context Block Inserted into {{MOCKUP_CONTEXT_PROPOSAL}}</h3>
                <textarea class="prompt-box" style="min-height:200px;" readonly onclick="this.select()"><?= h_audit($data['context_block_inserted'] ?? '') ?></textarea>
            </div>

            <div class="audit-panel" style="border-left:4px solid #1a73e8;">
                <h3 style="margin-top:0; margin-bottom:12px; font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#1a73e8;">Composed Final Admin Prompt (sent to Vertex/Gemini)</h3>
                <textarea class="prompt-box" style="min-height:400px;" readonly onclick="this.select()"><?= h_audit($data['composed_final_admin_prompt'] ?? '') ?></textarea>
            </div>
        </div>
    </main>
</div>
</body>
</html>
