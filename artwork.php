<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$pdo = Database::connection();
$id = max(0, (int)($_GET['id'] ?? 0));

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function media_url(?string $file, bool $download = false): string
{
    if (!$file) {
        return '';
    }

    $url = 'media.php?file=' . rawurlencode(basename($file));

    return $download ? $url . '&download=1' : $url;
}

function read_json_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $data = json_decode((string)file_get_contents($path), true);

    return is_array($data) ? $data : [];
}

if ($id <= 0) {
    http_response_code(404);
    die('Falta la obra.');
}

$stmt = $pdo->prepare('
    SELECT *
    FROM artworks
    WHERE id = :id
    AND user_id = :user_id
    LIMIT 1
');
$stmt->execute([
    'id' => $id,
    'user_id' => (int)$user['id'],
]);
$artwork = $stmt->fetch();

if (!is_array($artwork)) {
    http_response_code(404);
    die('No se encontro la obra.');
}

$rootFile = basename((string)($artwork['root_file'] ?? ''));
$rootPath = $rootFile ? RESULTS_DIR . DIRECTORY_SEPARATOR . $rootFile : '';
$rootBase = $rootFile ? pathinfo($rootFile, PATHINFO_FILENAME) : '';
$meta = $rootBase ? read_json_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $rootBase . '.meta.json') : [];
$analysis = $rootBase ? read_json_file(ANALYSIS_DIR . DIRECTORY_SEPARATOR . $rootBase . '.analysis.json') : [];
$profile = is_array($analysis['artwork_profile'] ?? null) ? $analysis['artwork_profile'] : [];
$contexts = is_array($analysis['recommended_contexts'] ?? null) ? $analysis['recommended_contexts'] : [];
$firstPrompt = (string)($contexts[0]['prompt'] ?? '');
$analysisNeedsRefresh = $firstPrompt !== '' && (
    str_contains($firstPrompt, 'shoe lengths') ||
    str_contains($firstPrompt, 'adult male shoe') ||
    !str_contains($firstPrompt, 'PROMPT_RULESET_VERSION: admin_editable_v1')
);

$mockupStmt = $pdo->prepare('
    SELECT *
    FROM mockups
    WHERE user_id = :user_id
    AND artwork_file = :artwork_file
    ORDER BY created_at DESC
');
$mockupStmt->execute([
    'user_id' => (int)$user['id'],
    'artwork_file' => $rootFile,
]);
$mockups = $mockupStmt->fetchAll();

$measurement = $meta['measurements'] ?? [];
$unit = (string)($measurement['unit'] ?? $artwork['unit'] ?? 'cm');
$width = $measurement['width'] ?? $artwork['width'] ?? '';
$height = $measurement['height'] ?? $artwork['height'] ?? '';
$depth = $measurement['depth'] ?? $artwork['depth'] ?? '';
$sizeText = trim((string)$width) !== '' && trim((string)$height) !== ''
    ? trim((string)$width . ' x ' . (string)$height . ($depth !== '' && $depth !== null ? ' x ' . (string)$depth : '') . ' ' . $unit)
    : 'Sin medidas';

$artistProfile = is_array($profile['_artist_profile'] ?? null) ? $profile['_artist_profile'] : ArtistProfile::findForUser((int)$user['id']);
$artistName = trim((string)($artistProfile['artist_name'] ?? ''));
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ficha de obra</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .artwork-sheet {
            display: grid;
            grid-template-columns: minmax(280px, 420px) 1fr;
            gap: 28px;
            align-items: start;
        }

        .root-panel img {
            width: 100%;
            height: auto;
            display: block;
            background: var(--surface-soft);
            border: 14px solid #fff;
            box-shadow: var(--shadow);
        }

        .data-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .data-box {
            background: var(--surface-soft);
            border: 1px solid var(--line);
            padding: 13px;
            min-height: 78px;
        }

        .data-box strong {
            display: block;
            margin-bottom: 5px;
            color: var(--muted);
            font-size: 12px;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .context-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 14px;
        }

        .context-card {
            border: 1px solid var(--line);
            background: #fff;
            padding: 16px;
        }

        .context-card h3 {
            font-size: 18px;
        }

        .inline-result {
            display: none;
            margin: 14px 0;
            background: #f1f1ef;
            border: 1px solid #dfdfdc;
            padding: 10px;
        }

        .inline-result.active {
            display: block;
        }

        .inline-result img {
            width: 100%;
            aspect-ratio: 4 / 3;
            object-fit: cover;
            display: block;
            background: #e8e8e4;
        }

        .inline-thumb {
            display: block;
            margin-bottom: 10px;
        }

        .download-icon {
            width: 22px;
            height: 22px;
            display: inline-block;
            position: relative;
            border-bottom: 2px solid currentColor;
        }

        .download-icon::before {
            content: "";
            position: absolute;
            left: 10px;
            top: 2px;
            width: 2px;
            height: 13px;
            background: currentColor;
        }

        .download-icon::after {
            content: "";
            position: absolute;
            left: 6px;
            top: 10px;
            width: 8px;
            height: 8px;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(45deg);
        }

        .inline-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .inline-actions a {
            font-weight: 700;
            text-decoration: none;
            border-bottom: 1px solid currentColor;
        }

        .inline-loader {
            display: grid;
            grid-template-columns: 34px 1fr;
            gap: 12px;
            align-items: center;
        }

        .inline-status {
            color: #4a453e;
            font-size: 13px;
            line-height: 1.4;
        }

        .spinner {
            width: 28px;
            height: 28px;
            border: 3px solid #d8d8d2;
            border-top-color: #e51f3f;
            border-radius: 50%;
            animation: spin .85s linear infinite;
        }

        .loader-track {
            height: 7px;
            margin-top: 9px;
            overflow: hidden;
            background: #deded8;
            position: relative;
        }

        .loader-track::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 38%;
            background: #e51f3f;
            animation: trackMove 1.35s ease-in-out infinite;
        }

        .context-card.generated .context-copy,
        .context-card.generated .prompt-preview {
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes trackMove {
            0% { left: -38%; }
            55% { left: 100%; }
            100% { left: 100%; }
        }

        .prompt-preview {
            width: 100%;
            min-height: 160px;
            font-family: Consolas, monospace;
            font-size: 12px;
            line-height: 1.45;
            background: #fafafa;
        }

        @media (max-width: 980px) {
            .artwork-sheet,
            .data-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
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
            <li><a href="dashboard.php">Dashboard</a></li>
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
            <li><a class="active" href="artwork.php?id=<?= h($id) ?>">Ficha de obra</a></li>
            <li><a href="mockups.php">Mockups</a></li>
            <li><a href="logout.php">Salir</a></li>
        </ul>
    </aside>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            Ficha permanente de obra: imagen raiz, lectura curatorial, propuestas, prompts y mockups.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Ficha de obra</h1>
                    <p><?= h(Display::artworkTitle($rootFile, (string)$artwork['job_id'])) ?></p>
                </div>
                <div class="topbar-actions">
                    <?php if ($rootFile): ?>
                        <a class="button-link" href="form2.php?image=<?= rawurlencode($rootFile) ?>">Mockups</a>
                        <a class="button-link secondary" href="analyze.php?image=<?= rawurlencode($rootFile) ?>&redirect=1">Actualizar analisis</a>
                        <a class="button-link secondary" href="<?= h(media_url($rootFile, true)) ?>">Descargar raiz</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($analysisNeedsRefresh): ?>
                <div class="notice error">
                    Este analisis fue generado con una version anterior del prompt de escala. Actualiza el analisis antes de generar nuevos mockups para evitar referencias visuales no deseadas.
                </div>
            <?php endif; ?>

            <section class="artwork-sheet">
                <div class="root-panel">
                    <?php if ($rootFile && is_file($rootPath)): ?>
                        <img src="<?= h(media_url($rootFile)) ?>" alt="Imagen raiz">
                    <?php else: ?>
                        <div class="empty-state">Esta obra todavia no tiene imagen raiz terminada.</div>
                    <?php endif; ?>
                </div>

                <div>
                    <section class="panel">
                        <h2>Datos de obra</h2>
                        <div class="data-grid">
                            <div class="data-box"><strong>Artista</strong><?= h($artistName !== '' ? $artistName : 'Sin nombre definido') ?></div>
                            <div class="data-box"><strong>Medidas de la obra</strong><?= h($sizeText) ?></div>
                            <div class="data-box"><strong>Estado</strong><?= h($artwork['status']) ?></div>
                            <div class="data-box"><strong>Fecha</strong><?= h(date('d/m/Y H:i', strtotime((string)$artwork['created_at']))) ?></div>
                            <div class="data-box"><strong>Orientacion</strong><?= h($analysis['image']['orientation'] ?? '-') ?></div>
                            <div class="data-box"><strong>Mockups creados</strong><?= h(count($mockups)) ?></div>
                        </div>
                    </section>

                    <section class="panel">
                        <h2>Lectura curatorial</h2>
                        <?php if (!$profile): ?>
                            <div class="empty-state">Todavia no hay analisis curatorial. Abre Formulario 2 para generarlo.</div>
                        <?php else: ?>
                            <div class="data-grid">
                                <div class="data-box"><strong>Lectura</strong><?= h($profile['one_line_curatorial_read'] ?? '-') ?></div>
                                <div class="data-box"><strong>Estilo</strong><?= h($profile['style_summary'] ?? '-') ?></div>
                                <div class="data-box"><strong>Paleta</strong><?= h(implode(', ', $profile['palette'] ?? [])) ?></div>
                                <div class="data-box"><strong>Atmosfera</strong><?= h(implode(', ', $profile['mood_tags'] ?? [])) ?></div>
                                <div class="data-box"><strong>Publico</strong><?= h($profile['audience_profile']['primary'] ?? '-') ?></div>
                                <div class="data-box"><strong>Temporada</strong><?= h($profile['seasonal_strategy']['primary_season'] ?? '-') ?></div>
                                <div class="data-box"><strong>Temperatura emocional</strong><?= h($profile['emotional_palette']['temperature'] ?? '-') ?></div>
                                <div class="data-box"><strong>Presencia onirica</strong><?= h($profile['dreamlike_presence']['level'] ?? '-') ?></div>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </section>

            <section class="panel">
                <div class="section-heading">
                    <h2>Mockups de esta obra</h2>
                    <p><?= h(count($mockups)) ?> imagenes</p>
                </div>

                <?php if (!$mockups): ?>
                    <div class="empty-state">Todavia no hay mockups generados desde esta obra.</div>
                <?php else: ?>
                    <div class="grid">
                        <?php foreach ($mockups as $mockup): ?>
                            <article class="item-card">
                                <a class="inline-thumb" href="viewer.php?id=<?= h($mockup['id']) ?>" aria-label="Abrir mockup">
                                    <img src="<?= h(media_url($mockup['mockup_file'])) ?>" alt="Mockup">
                                </a>
                                <h3><?= h(Display::contextTitle($mockup['context_id'])) ?></h3>
                                <div class="card-actions">
                                    <a href="<?= h(media_url($mockup['mockup_file'], true)) ?>" aria-label="Descargar mockup" title="Descargar">
                                        <span class="download-icon" aria-hidden="true"></span>
                                    </a>
                                    <?php if ($isAdmin && !empty($mockup['prompt_file'])): ?>
                                        <a href="<?= h(media_url($mockup['prompt_file'])) ?>" target="_blank">Prompt</a>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>


            <section class="panel">
                <div class="section-heading">
                    <h2><?= h(count($contexts)) ?> propuestas curatoriales</h2>
                    <p><?= h(count($contexts)) ?> propuestas</p>
                </div>

                <?php if (!$contexts): ?>
                    <div class="empty-state">Todavia no hay propuestas guardadas.</div>
                <?php else: ?>
                    <div class="context-list">
                        <?php foreach ($contexts as $index => $context): ?>
                            <article class="context-card">
                                <p class="meta-line">Direccion <?= h($index + 1) ?> · <?= h($context['camera_group'] ?? '-') ?> · <?= h($context['time_of_day'] ?? '-') ?></p>
                                <h3><?= h($context['name'] ?? $context['id'] ?? 'Contexto') ?></h3>
                                <div class="inline-result" aria-live="polite">
                                    <div class="inline-status">Esperando generacion.</div>
                                </div>
                                <p class="context-copy"><?= h($context['why'] ?? '') ?></p>
                                <div class="card-actions">
                                    <?php if ($rootFile && !$analysisNeedsRefresh): ?>
                                        <form class="inline-mockup-form" action="generate_mockup.php" method="post">
                                            <input type="hidden" name="image" value="<?= h($rootFile) ?>">
                                            <input type="hidden" name="json" value="<?= h($rootBase . '.analysis.json') ?>">
                                            <input type="hidden" name="context_id" value="<?= h($context['id'] ?? '') ?>">
                                            <input type="hidden" name="prompt" value="<?= h($context['prompt'] ?? '') ?>">
                                            <input type="hidden" name="ajax" value="1">
                                            <button type="submit">Generar</button>
                                        </form>
                                    <?php elseif ($rootFile): ?>
                                        <a href="analyze.php?image=<?= rawurlencode($rootFile) ?>&redirect=1">Actualizar antes de generar</a>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isAdmin): ?>
                                    <details>
                                        <summary>Ver prompt tecnico</summary>
                                        <textarea class="prompt-preview" readonly><?= h($context['prompt'] ?? '') ?></textarea>
                                    </details>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

        </div>
    </main>
</div>
<script>
    const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;

    document.querySelectorAll('.inline-mockup-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const card = form.closest('.context-card');
            const resultBox = card.querySelector('.inline-result');
            const button = form.querySelector('button');
            const originalText = button.textContent;

            card.classList.remove('generated');
            resultBox.classList.add('active');
            resultBox.innerHTML = `
                <div class="inline-loader">
                    <div class="spinner" aria-hidden="true"></div>
                    <div class="inline-status">
                        Generando mockup dentro de la ficha.
                        <div class="loader-track" aria-hidden="true"></div>
                    </div>
                </div>
            `;
            button.disabled = true;
            button.textContent = 'Generando...';

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();

                if (!response.ok || !data.ok) {
                    throw new Error(data.error || 'No se pudo generar el mockup.');
                }

                card.classList.add('generated');
                const promptLink = isAdmin
                    ? `<a href="${escapeAttribute(data.prompt_url)}" target="_blank" rel="noopener">Prompt</a>`
                    : '';
                resultBox.innerHTML = `
                    <a class="inline-thumb" href="${escapeAttribute(data.viewer_url)}" aria-label="Abrir mockup generado">
                        <img src="${escapeAttribute(data.image_url)}" alt="Mockup generado">
                    </a>
                    <div class="inline-actions">
                        <a href="${escapeAttribute(data.download_url)}" aria-label="Descargar mockup" title="Descargar">
                            <span class="download-icon" aria-hidden="true"></span>
                        </a>
                        ${promptLink}
                    </div>
                `;
                button.textContent = 'Generar otra vez';
            } catch (error) {
                resultBox.innerHTML = '<div class="inline-status">Error: ' + escapeHtml(error.message) + '</div>';
                button.textContent = originalText;
            } finally {
                button.disabled = false;
            }
        });
    });

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function escapeAttribute(value) {
        return escapeHtml(value);
    }
</script>
</body>
</html>
