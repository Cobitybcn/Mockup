<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$pdo = Database::connection();

$stmt = $pdo->prepare("
    SELECT *
    FROM artworks
    WHERE user_id = :user_id
    AND status = 'done'
    AND root_file IS NOT NULL
    AND root_file != ''
    ORDER BY created_at DESC
");
$stmt->execute(['user_id' => $user['id']]);
$artworks = $stmt->fetchAll();

$mockupStmt = $pdo->prepare("
    SELECT *
    FROM mockups
    WHERE user_id = :user_id
    ORDER BY created_at DESC
    LIMIT 12
");
$mockupStmt->execute(['user_id' => $user['id']]);
$mockups = $mockupStmt->fetchAll();

$mockupCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM mockups
    WHERE user_id = :user_id
");
$mockupCountStmt->execute(['user_id' => $user['id']]);
$mockupTotal = (int)$mockupCountStmt->fetchColumn();

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function result_url(?string $file): string
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
    <title>Dashboard</title>
    <link rel="stylesheet" href="style.css">
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
            <li><a class="active" href="dashboard.php">Dashboard</a></li>
            <li><a href="artwork_new.php">Crear obra raiz</a></li>
            <li><a href="artist_profile.php">Perfil de artista</a></li>
            <?php if ($isAdmin): ?>
                <li><a href="admin_prompts.php">Admin prompts</a></li>
                <li><a href="admin_api_keys.php">API keys</a></li>
            <?php endif; ?>
            <li><a href="account.php">Cuenta y pagos</a></li>
        </ul>

        <div class="nav-section">Archivo</div>
        <ul class="nav">
            <li><a href="#obras">Obras raiz</a></li>
            <li><a href="mockups.php">Mockups</a></li>
            <li><a href="logout.php">Salir</a></li>
        </ul>
    </aside>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            Beta privado: genera imagenes raiz fieles y mockups curatoriales para venta online.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Dashboard</h1>
                    <p>Archivo privado de obras, imagenes raiz y mockups.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link" href="artwork_new.php">Upload artwork</a>
                    <a class="button-link secondary" href="account.php">Cuenta</a>
                </div>
            </div>

            <section class="stats">
                <div class="stat-card">
                    <span>Obras raiz</span>
                    <strong><?= count($artworks) ?></strong>
                </div>
                <div class="stat-card">
                    <span>Mockups</span>
                    <strong><?= h($mockupTotal) ?></strong>
                </div>
                <div class="stat-card">
                    <span>Creditos</span>
                    <strong><?= h($user['credits']) ?></strong>
                </div>
                <div class="stat-card">
                    <span>Estado</span>
                    <strong>Beta</strong>
                </div>
            </section>

            <section class="panel" id="obras">
                <div class="section-heading">
                    <h2>Obras raiz</h2>
                    <p><?= count($artworks) ?> piezas</p>
                </div>

                <?php if (!$artworks): ?>
                    <div class="empty-state">Todavia no tienes obras creadas.</div>
                <?php else: ?>
                    <div class="grid">
                        <?php foreach ($artworks as $artwork): ?>
                            <article class="item-card">
                                <?php if (!empty($artwork['root_file']) && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . basename((string)$artwork['root_file']))): ?>
                                    <a href="artwork.php?id=<?= h($artwork['id']) ?>" aria-label="Abrir ficha de obra">
                                        <img src="<?= h(result_url($artwork['root_file'])) ?>" alt="Obra raiz">
                                    </a>
                                <?php endif; ?>

                                <h3><?= h(Display::artworkTitle($artwork['root_file'], (string)$artwork['job_id'])) ?></h3>
                                <span class="status-pill <?= h($artwork['status']) ?>"><?= h($artwork['status']) ?></span>
                                <p class="meta-line">Medidas: <?= h(trim(($artwork['width'] ?: '-') . ' x ' . ($artwork['height'] ?: '-') . ' ' . $artwork['unit'])) ?></p>

                                <div class="card-actions">
                                    <a href="artwork.php?id=<?= h($artwork['id']) ?>">Ficha</a>
                                    <?php if (!empty($artwork['root_file'])): ?>
                                        <a href="form2.php?image=<?= rawurlencode(basename((string)$artwork['root_file'])) ?>">Mockups</a>
                                        <a href="<?= h(download_url($artwork['root_file'])) ?>" aria-label="Descargar obra raiz" title="Descargar">
                                            <span class="download-icon" aria-hidden="true"></span>
                                        </a>
                                    <?php else: ?>
                                        <a href="waiting.php?job=<?= rawurlencode((string)$artwork['job_id']) ?>">Ver estado</a>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="panel" id="mockups">
                <div class="section-heading">
                    <h2>Mockups recientes</h2>
                    <p><a href="mockups.php"><?= h($mockupTotal) ?> en total</a></p>
                </div>

                <?php if (!$mockups): ?>
                    <div class="empty-state">Todavia no tienes mockups generados.</div>
                <?php else: ?>
                    <div class="grid">
                        <?php foreach ($mockups as $mockup): ?>
                            <article class="item-card">
                                <a href="viewer.php?id=<?= h($mockup['id']) ?>" aria-label="Abrir mockup">
                                    <img src="<?= h(result_url($mockup['mockup_file'])) ?>" alt="Mockup">
                                </a>
                                <h3><?= h(Display::contextTitle($mockup['context_id'])) ?></h3>
                                <div class="card-actions">
                                    <a href="<?= h(download_url($mockup['mockup_file'])) ?>" aria-label="Descargar mockup" title="Descargar">
                                        <span class="download-icon" aria-hidden="true"></span>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
