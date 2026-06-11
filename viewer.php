<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();

$id = (int)($_GET['id'] ?? 0);
$file = basename((string)($_GET['file'] ?? ''));

if ($id > 0) {
    $stmt = $pdo->prepare('
        SELECT *
        FROM mockups
        WHERE user_id = :user_id
        AND id = :id
        LIMIT 1
    ');
    $stmt->execute([
        'user_id' => (int)$user['id'],
        'id' => $id,
    ]);
} else {
    $stmt = $pdo->prepare('
        SELECT *
        FROM mockups
        WHERE user_id = :user_id
        AND mockup_file = :file
        LIMIT 1
    ');
    $stmt->execute([
        'user_id' => (int)$user['id'],
        'file' => $file,
    ]);
}

$mockup = $stmt->fetch();

if (!$mockup) {
    http_response_code(404);
    exit('Mockup no encontrado.');
}

$backUrl = 'mockups.php';
$artworkStmt = $pdo->prepare('
    SELECT id
    FROM artworks
    WHERE user_id = :user_id
    AND (root_file = :artwork_file OR main_file = :artwork_file)
    LIMIT 1
');
$artworkStmt->execute([
    'user_id' => (int)$user['id'],
    'artwork_file' => (string)$mockup['artwork_file'],
]);
$artworkId = $artworkStmt->fetchColumn();

if ($artworkId) {
    $backUrl = 'artwork.php?id=' . rawurlencode((string)$artworkId);
}

$prevStmt = $pdo->prepare('
    SELECT id
    FROM mockups
    WHERE user_id = :user_id
    AND (
        created_at > :created_at
        OR (created_at = :created_at AND id > :id)
    )
    ORDER BY created_at ASC, id ASC
    LIMIT 1
');
$prevStmt->execute([
    'user_id' => (int)$user['id'],
    'created_at' => (string)$mockup['created_at'],
    'id' => (int)$mockup['id'],
]);
$prevId = $prevStmt->fetchColumn();

$nextStmt = $pdo->prepare('
    SELECT id
    FROM mockups
    WHERE user_id = :user_id
    AND (
        created_at < :created_at
        OR (created_at = :created_at AND id < :id)
    )
    ORDER BY created_at DESC, id DESC
    LIMIT 1
');
$nextStmt->execute([
    'user_id' => (int)$user['id'],
    'created_at' => (string)$mockup['created_at'],
    'id' => (int)$mockup['id'],
]);
$nextId = $nextStmt->fetchColumn();

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function media_url(?string $file): string
{
    return $file ? 'media.php?file=' . rawurlencode(basename($file)) : '';
}

function download_url(?string $file): string
{
    return $file ? 'media.php?file=' . rawurlencode(basename($file)) . '&download=1' : '';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Visor de mockup</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background: #0f0f0f;
            color: #fff;
            font-family: Arial, Helvetica, sans-serif;
            overflow: hidden;
        }

        .viewer-top {
            position: fixed;
            z-index: 5;
            top: 0;
            left: 0;
            right: 0;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 0 22px;
            background: linear-gradient(180deg, rgba(0,0,0,.82), rgba(0,0,0,0));
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: #fff;
            font-size: 22px;
            letter-spacing: .28em;
            text-decoration: none;
            text-transform: uppercase;
        }

        .viewer-left {
            display: inline-flex;
            align-items: center;
            gap: 22px;
        }

        .icon-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            color: #fff;
            text-decoration: none;
            opacity: .84;
            border: 0;
            background: transparent;
            cursor: pointer;
        }

        .icon-link.back::before {
            content: '';
            width: 12px;
            height: 12px;
            border-left: 3px solid currentColor;
            border-bottom: 3px solid currentColor;
            transform: rotate(45deg);
            margin-left: 4px;
        }

        .icon-link.download::before {
            content: '';
            width: 3px;
            height: 18px;
            background: currentColor;
            margin-top: -4px;
        }

        .icon-link.download::after {
            content: '';
            position: absolute;
            width: 11px;
            height: 11px;
            border-left: 3px solid currentColor;
            border-bottom: 3px solid currentColor;
            transform: rotate(-45deg);
            margin-top: 8px;
        }

        .icon-link.download .download-base {
            position: absolute;
            width: 18px;
            height: 3px;
            background: currentColor;
            bottom: 5px;
        }

        .icon-link:hover {
            opacity: 1;
            color: #e51f3f;
        }

        .brand-mark {
            width: 14px;
            height: 14px;
            border: 4px solid #e51f3f;
            display: inline-block;
        }

        .viewer-actions {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .viewer-actions a {
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            border-bottom: 1px solid currentColor;
        }

        .viewer-actions .icon-link {
            border-bottom: 0;
            position: relative;
        }

        .stage {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 76px 86px 42px;
        }

        .stage img {
            max-width: 100%;
            max-height: calc(100vh - 130px);
            object-fit: contain;
            box-shadow: 0 28px 80px rgba(0,0,0,.42);
        }

        .nav-arrow {
            position: fixed;
            z-index: 4;
            top: 50%;
            transform: translateY(-50%);
            width: 54px;
            height: 86px;
            display: grid;
            place-items: center;
            color: #fff;
            text-decoration: none;
            font-size: 58px;
            line-height: 1;
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.15);
        }

        .nav-arrow:hover {
            background: #e51f3f;
            border-color: #e51f3f;
        }

        .nav-arrow.prev {
            left: 22px;
        }

        .nav-arrow.next {
            right: 22px;
        }

        .viewer-caption {
            position: fixed;
            z-index: 5;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 18px 24px;
            display: flex;
            justify-content: center;
            gap: 18px;
            color: rgba(255,255,255,.82);
            background: linear-gradient(0deg, rgba(0,0,0,.82), rgba(0,0,0,0));
            font-size: 14px;
        }

        @media (max-width: 760px) {
            .stage {
                padding: 76px 18px 80px;
            }

            .nav-arrow {
                width: 44px;
                height: 68px;
                font-size: 42px;
            }

            .nav-arrow.prev {
                left: 8px;
            }

            .nav-arrow.next {
                right: 8px;
            }

            .viewer-caption {
                display: block;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <header class="viewer-top">
        <div class="viewer-left">
            <a class="brand" href="dashboard.php">ARTMOCK <span class="brand-mark"></span></a>
        </div>
        <nav class="viewer-actions">
            <a class="icon-link back" href="<?= h($backUrl) ?>" aria-label="Volver a la ficha de obra" title="Volver a la ficha de obra"></a>
            <a href="mockups.php">Archivo</a>
            <a class="icon-link download" href="<?= h(download_url($mockup['mockup_file'])) ?>" aria-label="Descargar mockup" title="Descargar mockup"><span class="download-base" aria-hidden="true"></span></a>
        </nav>
    </header>

    <?php if ($prevId): ?>
        <a class="nav-arrow prev" href="viewer.php?id=<?= h($prevId) ?>" aria-label="Imagen anterior">&lsaquo;</a>
    <?php endif; ?>

    <main class="stage">
        <img src="<?= h(media_url($mockup['mockup_file'])) ?>" alt="Mockup">
    </main>

    <?php if ($nextId): ?>
        <a class="nav-arrow next" href="viewer.php?id=<?= h($nextId) ?>" aria-label="Imagen siguiente">&rsaquo;</a>
    <?php endif; ?>

    <footer class="viewer-caption">
        <span><?= h(Display::contextTitle($mockup['context_id'])) ?></span>
        <span><?= h(date('d/m/Y H:i', strtotime((string)$mockup['created_at']))) ?></span>
    </footer>

    <script>
        document.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowLeft') {
                const prev = document.querySelector('.nav-arrow.prev');
                if (prev) window.location.href = prev.href;
            }

            if (event.key === 'ArrowRight') {
                const next = document.querySelector('.nav-arrow.next');
                if (next) window.location.href = next.href;
            }

            if (event.key === 'Escape') {
                window.location.href = <?= json_encode($backUrl, JSON_UNESCAPED_SLASHES) ?>;
            }
        });
    </script>
</body>
</html>
