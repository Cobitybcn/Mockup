<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$mockupId = max(0, (int)($_GET['mockup_id'] ?? 0));
$file = basename(str_replace('\\', '/', trim((string)($_GET['file'] ?? ''))));
$labDir = __DIR__ . '/storage/experiments/mockup-variation-lab';

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if ($mockupId <= 0 || $file === '' || preg_match('/^[A-Za-z0-9._-]+$/', $file) !== 1) {
    http_response_code(400);
    exit('Invalid LAB image.');
}

$runs = [];
foreach (glob($labDir . DIRECTORY_SEPARATOR . '*.audit.json') ?: [] as $auditPath) {
    $audit = json_decode((string)file_get_contents($auditPath), true);
    if (!is_array($audit) || (int)($audit['mockup_id'] ?? 0) !== $mockupId) {
        continue;
    }
    if (!Auth::isAdmin($user) && (int)($audit['requested_by_user_id'] ?? 0) !== (int)$user['id']) {
        continue;
    }
    $output = basename((string)($audit['output_file'] ?? ''));
    if ($output === '' || !is_file($labDir . DIRECTORY_SEPARATOR . $output)) {
        continue;
    }
    $runs[] = [
        'file' => $output,
        'started_at' => (string)($audit['started_at'] ?? ''),
        'variation_type' => (string)($audit['variation_type'] ?? 'LAB test'),
        'registered_mockup_id' => (int)($audit['registered_mockup_id'] ?? 0),
        'registered_mockup_file' => basename((string)($audit['registered_mockup_file'] ?? '')),
    ];
}
usort($runs, static fn(array $a, array $b): int => strcmp($b['started_at'], $a['started_at']));

$currentIndex = -1;
foreach ($runs as $index => $run) {
    if ($run['file'] === $file) {
        $currentIndex = $index;
        break;
    }
}
if ($currentIndex < 0) {
    http_response_code(404);
    exit('LAB image not found.');
}

$prev = $runs[$currentIndex + 1]['file'] ?? '';
$next = $runs[$currentIndex - 1]['file'] ?? '';
$current = $runs[$currentIndex];
$backUrl = 'mockup_variation_lab.php?mockup_id=' . $mockupId;

$pdo = Database::connection();
$favoriteMockupId = (int)($current['registered_mockup_id'] ?? 0);
if ($favoriteMockupId <= 0 && (string)($current['registered_mockup_file'] ?? '') !== '') {
    $registeredStmt = $pdo->prepare('
        SELECT id
        FROM mockups
        WHERE user_id = :user_id
        AND mockup_file = :mockup_file
        LIMIT 1
    ');
    $registeredStmt->execute([
        'user_id' => (int)$user['id'],
        'mockup_file' => (string)$current['registered_mockup_file'],
    ]);
    $favoriteMockupId = (int)($registeredStmt->fetchColumn() ?: 0);
}
$favoriteLookup = $favoriteMockupId > 0 ? MockupFavorites::lookupForUser((int)$user['id']) : [];
$isFavorite = $favoriteMockupId > 0 && isset($favoriteLookup[$favoriteMockupId]);
$viewerImageUrl = (string)($current['registered_mockup_file'] ?? '') !== ''
    ? 'media.php?file=' . rawurlencode((string)$current['registered_mockup_file'])
    : 'mockup_variation_lab_file.php?file=' . rawurlencode($file);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mockup Lab Viewer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #111; color: #f8f5ef; font-family: Arial, sans-serif; }
        .viewer-top {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 5;
            height: 62px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 22px;
            background: rgba(248, 245, 239, .94);
            color: #15130f;
            border-bottom: 1px solid rgba(0, 0, 0, .12);
        }
        .viewer-top a {
            color: inherit;
            text-decoration: none;
        }
        .viewer-title {
            font-size: 14px;
            letter-spacing: .02em;
        }
        .viewer-action {
            width: 42px;
            height: 42px;
            border: 1px solid rgba(0, 0, 0, .14);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, .58);
            font-size: 26px;
        }
        .viewer-stage {
            min-height: 100vh;
            padding: 82px 72px 34px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .viewer-favorite-btn {
            width: 42px;
            height: 42px;
            border: 1px solid rgba(183, 127, 134, .72);
            border-radius: 999px;
            background: rgba(255, 250, 247, .88);
            color: #b77f86;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            line-height: 1;
            cursor: pointer;
            box-shadow: 0 10px 28px rgba(20, 17, 15, .14);
        }
        .viewer-favorite-btn:hover,
        .viewer-favorite-btn:focus-visible,
        .viewer-favorite-btn.active {
            background: #b77f86;
            border-color: #b77f86;
            color: #fffaf7;
            outline: none;
        }
        .viewer-favorite-btn[disabled] {
            cursor: wait;
            opacity: .62;
        }
        .viewer-stage img {
            max-width: 100%;
            max-height: calc(100vh - 116px);
            object-fit: contain;
            box-shadow: 0 18px 60px rgba(0, 0, 0, .42);
        }
        .viewer-nav {
            position: fixed;
            top: 50%;
            transform: translateY(-50%);
            width: 54px;
            height: 76px;
            border: 1px solid rgba(255, 255, 255, .24);
            background: rgba(255, 255, 255, .12);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 46px;
            backdrop-filter: blur(8px);
        }
        .viewer-nav.prev { left: 22px; }
        .viewer-nav.next { right: 22px; }
        .viewer-nav.disabled { opacity: .18; pointer-events: none; }
    </style>
</head>
<body>
    <header class="viewer-top">
        <a class="viewer-action" href="<?= h($backUrl) ?>" title="Back to Mockup Lab" aria-label="Back to Mockup Lab">‹</a>
        <div class="viewer-title">Mockup Lab <?= (int)($currentIndex + 1) ?> / <?= count($runs) ?></div>
        <?php if ($favoriteMockupId > 0): ?>
            <button
                class="viewer-favorite-btn <?= $isFavorite ? 'active' : '' ?>"
                type="button"
                title="<?= $isFavorite ? 'Remove favorite' : 'Add favorite' ?>"
                aria-label="<?= $isFavorite ? 'Remove favorite' : 'Add favorite' ?>"
                data-favorite-mockup
                data-mockup-id="<?= (int)$favoriteMockupId ?>"
            >★</button>
        <?php endif; ?>
        <a class="viewer-action" href="mockup_variation_lab_file.php?file=<?= rawurlencode($file) ?>" download title="Download" aria-label="Download">↓</a>
    </header>
    <main class="viewer-stage">
        <img src="<?= h($viewerImageUrl) ?>" alt="">
    </main>
    <a class="viewer-nav prev <?= $prev === '' ? 'disabled' : '' ?>" href="<?= $prev !== '' ? 'mockup_variation_lab_viewer.php?mockup_id=' . $mockupId . '&file=' . rawurlencode($prev) : '#' ?>" aria-label="Previous">‹</a>
    <a class="viewer-nav next <?= $next === '' ? 'disabled' : '' ?>" href="<?= $next !== '' ? 'mockup_variation_lab_viewer.php?mockup_id=' . $mockupId . '&file=' . rawurlencode($next) : '#' ?>" aria-label="Next">›</a>
    <script>
        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-favorite-mockup]');
            if (!button) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const body = new FormData();
            body.append('mockup_id', button.getAttribute('data-mockup-id') || '');
            button.disabled = true;

            fetch('toggle_mockup_favorite.php', { method: 'POST', body })
                .then(response => response.json().then(payload => ({ ok: response.ok, payload })))
                .then(result => {
                    if (!result.ok || !result.payload.ok) {
                        throw new Error(result.payload.error || 'Could not update favorite.');
                    }

                    button.classList.toggle('active', !!result.payload.favorite);
                    button.title = result.payload.favorite ? 'Remove favorite' : 'Add favorite';
                    button.setAttribute('aria-label', button.title);
                })
                .catch(error => alert(error.message || 'Could not update favorite.'))
                .finally(() => {
                    button.disabled = false;
                });
        });
    </script>
</body>
</html>
