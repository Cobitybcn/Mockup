<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function find_file(string $name): ?string
{
    $safe = basename($name);

    $paths = [
        __DIR__ . '/results/' . $safe,
        __DIR__ . '/uploads/' . $safe,
        __DIR__ . '/' . $safe,
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function public_path(string $file): string
{
    $base = basename($file);

    if (is_file(__DIR__ . '/results/' . $base)) {
        return 'media.php?file=' . rawurlencode($base);
    }

    if (is_file(__DIR__ . '/uploads/' . $base)) {
        return 'uploads/' . rawurlencode($base);
    }

    return rawurlencode($base);
}

function assert_root_owner(string $imagePath, array $user): void
{
    $metaPath = __DIR__ . '/results/' . pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.meta.json';

    if (!is_file($metaPath)) {
        http_response_code(403);
        die('No se encontro metadata de propiedad para esta obra.');
    }

    $data = json_decode((string)file_get_contents($metaPath), true);

    if (is_array($data) && (int)($data['user_id'] ?? 0) !== (int)$user['id']) {
        http_response_code(403);
        die('No tienes acceso a esta obra.');
    }
}

$image = $_GET['image'] ?? $_POST['image'] ?? '';
$json = $_GET['json'] ?? $_POST['json'] ?? '';

if (!$image && !empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $qs);

    foreach ($qs as $key => $value) {
        if (preg_match('/\.(png|jpg|jpeg|webp)$/i', $key)) {
            $image = $key;
            break;
        }
    }
}

if (!$image && $json) {
    $jsonPathTmp = find_file($json);

    if ($jsonPathTmp) {
        $tmpData = json_decode((string)file_get_contents($jsonPathTmp), true);
        $image = $tmpData['image']['file'] ?? '';
    }
}

if (!$image) {
    die('Falta la imagen. Usa: form2.php?image=nombre_imagen.png');
}

$imagePath = find_file($image);

if (!$imagePath) {
    die('No se encontró la imagen: ' . h($image));
}

assert_root_owner($imagePath, $currentUser);

if (!$json) {
    $base = pathinfo(basename($image), PATHINFO_FILENAME);
    $possibleJson = $base . '.analysis.json';

    if (find_file($possibleJson)) {
        $json = $possibleJson;
    }
}

$analysis = null;
$jsonPath = $json ? find_file($json) : null;

if ($jsonPath) {
    $analysis = json_decode((string)file_get_contents($jsonPath), true);
}

$currentArtistProfile = ArtistProfile::findForUser((int)$currentUser['id']);
$currentArtistProfileUpdatedAt = (string)($currentArtistProfile['updated_at'] ?? '');
$analysisProfile = is_array($analysis) && is_array($analysis['artwork_profile'] ?? null)
    ? $analysis['artwork_profile']
    : [];
$analysisArtistProfileUpdatedAt = (string)($analysisProfile['_artist_profile_updated_at'] ?? '');
$hasArtistProfileForValidation = ArtistProfile::hasContent($currentArtistProfile);
$hasCurrentArtistProfileInAnalysis = !$hasArtistProfileForValidation ||
    ($currentArtistProfileUpdatedAt !== '' && $currentArtistProfileUpdatedAt === $analysisArtistProfileUpdatedAt);

$contextsForValidation = $analysis['recommended_contexts'] ?? [];
$expectedContextCount = PromptSettings::mockupContextCount();
$firstPromptForValidation = $contextsForValidation[0]['prompt'] ?? '';
$metaPathForValidation = __DIR__ . '/results/' . pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.meta.json';
$hasRootMeta = is_file($metaPathForValidation);
$analysisWidthCm = $analysis['image']['physical_size']['width_cm'] ?? null;
$hasDynamicScaleText = str_contains((string)$firstPromptForValidation, 'small') ||
    str_contains((string)$firstPromptForValidation, 'medium') ||
    str_contains((string)$firstPromptForValidation, 'large statement') ||
    str_contains((string)$firstPromptForValidation, 'monumental');
$hasScaleAnchors = str_contains((string)$firstPromptForValidation, 'PROMPT_RULESET_VERSION: admin_editable_v1');
$cameraGroupsForValidation = array_map(fn($ctx) => (string)($ctx['camera_group'] ?? ''), $contextsForValidation);
$timesForValidation = array_map(fn($ctx) => (string)($ctx['time_of_day'] ?? ''), $contextsForValidation);
$humanProfilesForValidation = array_values(array_filter(array_map(fn($ctx) => (string)($ctx['human_profile'] ?? ''), $contextsForValidation)));
$hasBasicContextShape = count($contextsForValidation) === $expectedContextCount &&
    count(array_filter($contextsForValidation, fn($ctx) => trim((string)($ctx['prompt'] ?? '')) !== '')) === $expectedContextCount &&
    count(array_filter($cameraGroupsForValidation, fn($v) => $v !== '')) === $expectedContextCount &&
    count(array_filter($timesForValidation, fn($v) => $v !== '')) === $expectedContextCount;
$hasFullMockupQuotas =
    count(array_filter($cameraGroupsForValidation, fn($v) => $v === 'three_quarter_left')) >= 3 &&
    count(array_filter($cameraGroupsForValidation, fn($v) => $v === 'three_quarter_right')) >= 3 &&
    count(array_filter($cameraGroupsForValidation, fn($v) => $v === 'front_close')) >= 2 &&
    count(array_filter($timesForValidation, fn($v) => $v === 'day')) >= 4 &&
    count(array_filter($timesForValidation, fn($v) => $v === 'afternoon')) >= 3 &&
    count(array_filter($timesForValidation, fn($v) => $v === 'night')) >= 3 &&
    count($humanProfilesForValidation) >= 4 &&
    in_array('male_180', $humanProfilesForValidation, true) &&
    in_array('female_155', $humanProfilesForValidation, true);
$hasExpectedMockupQuotas = $expectedContextCount >= 10 ? $hasFullMockupQuotas : $hasBasicContextShape;

if (
    !$analysis ||
    empty($contextsForValidation) ||
    count($contextsForValidation) !== $expectedContextCount ||
    str_contains((string)$firstPromptForValidation, 'Prototype prompt generated locally') ||
    !$hasDynamicScaleText ||
    !$hasScaleAnchors ||
    !$hasExpectedMockupQuotas ||
    !$hasCurrentArtistProfileInAnalysis ||
    ($hasRootMeta && !$analysisWidthCm)
) {
    if (isset($_GET['json']) && $_GET['json'] !== '') {
        http_response_code(500);
        die('No se pudo generar un analisis valido para Formulario 2. Revisa el JSON de analisis: ' . h((string)$_GET['json']));
    }

    $analyzeUrl = 'analyze.php?image=' . rawurlencode(basename($imagePath)) . '&redirect=1';
    header('Location: ' . $analyzeUrl);
    exit;
}

$profile = $analysis['artwork_profile'] ?? [];
$contexts = $analysis['recommended_contexts'] ?? [];
$isAdmin = Auth::isAdmin($currentUser);
$mode = $analysis['mode'] ?? ServiceFactory::appMode();
$mockNotice = $analysis['mock_notice'] ?? '';
$audience = $profile['audience_profile']['primary'] ?? '';
$season = $profile['seasonal_strategy']['primary_season'] ?? '';
$emotionalTemperature = $profile['emotional_palette']['temperature'] ?? '';
$dreamlikeLevel = $profile['dreamlike_presence']['level'] ?? '';

$imagePublic = public_path($imagePath);
$jsonPublic = $jsonPath ? basename($jsonPath) : '';

$orientation = $analysis['image']['orientation'] ?? '';
$widthCm = $analysis['image']['physical_size']['width_cm'] ?? null;
$heightCm = $analysis['image']['physical_size']['height_cm'] ?? null;
$depthCm = $analysis['image']['physical_size']['depth_cm'] ?? null;

$sizeText = '';

if ($widthCm && $heightCm) {
    $sizeText = $widthCm . ' × ' . $heightCm . ' cm';

    if ($depthCm) {
        $sizeText .= ' × ' . $depthCm . ' cm';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Formulario 2 - Dirección artística</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">

    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f6f6f4;
            color: #111;
        }

        .wrap {
            max-width: 1280px;
            margin: 0;
        }

        .top {
            display: grid;
            grid-template-columns: 360px 1fr;
            gap: 32px;
            align-items: start;
            margin-bottom: 36px;
        }

        .artwork-box {
            background: #fff;
            padding: 18px;
            border: 1px solid #dfdfdc;
            box-shadow: 0 18px 46px rgba(0,0,0,.08);
        }

        .artwork-box img {
            width: 100%;
            height: auto;
            display: block;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 32px;
            font-weight: 700;
            letter-spacing: 0;
        }

        h2 {
            margin: 0 0 12px;
            font-size: 20px;
        }

        .subtitle {
            font-size: 16px;
            line-height: 1.5;
            color: #6d6d6d;
            max-width: 760px;
        }

        .profile {
            margin-top: 22px;
            background: #fff;
            border: 1px solid #dfdfdc;
            padding: 20px;
            box-shadow: 0 14px 36px rgba(0,0,0,.06);
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 14px;
        }

        .data {
            font-size: 14px;
            background: #f1f1ef;
            padding: 10px 12px;
            border: 1px solid #dfdfdc;
        }

        .data strong {
            display: block;
            margin-bottom: 3px;
            font-size: 12px;
            text-transform: uppercase;
            color: #6d6d6d;
            letter-spacing: .04em;
        }

        .contexts {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
        }

        .card {
            background: #fff;
            border: 1px solid #dfdfdc;
            padding: 18px;
            display: flex;
            flex-direction: column;
            min-height: 520px;
            box-shadow: 0 16px 38px rgba(0,0,0,.07);
        }

        .number {
            font-size: 12px;
            text-transform: uppercase;
            color: #e51f3f;
            letter-spacing: .08em;
            margin-bottom: 8px;
        }

        .card h3 {
            margin: 0;
            font-size: 19px;
            line-height: 1.15;
            letter-spacing: 0;
        }

        .purpose {
            display: inline-block;
            margin-top: 10px;
            font-size: 12px;
            padding: 5px 8px;
            background: #111;
            color: #fff;
            border-radius: 0;
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

        .inline-result .inline-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .inline-result a {
            font-weight: 700;
            text-decoration: none;
            border-bottom: 1px solid currentColor;
        }

        .inline-status {
            color: #4a453e;
            font-size: 13px;
            line-height: 1.4;
        }

        .inline-loader {
            display: grid;
            grid-template-columns: 34px 1fr;
            gap: 12px;
            align-items: center;
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

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes trackMove {
            0% { left: -38%; }
            55% { left: 100%; }
            100% { left: 100%; }
        }

        .card.generated .card-copy {
            display: none;
        }

        .meta {
            margin: 14px 0;
            display: grid;
            gap: 7px;
            font-size: 13px;
            color: #4a453e;
        }

        .meta div {
            border-bottom: 1px solid #dfdfdc;
            padding-bottom: 6px;
        }

        .text {
            font-size: 14px;
            line-height: 1.45;
            color: #4a453e;
            margin-bottom: 12px;
        }

        .why {
            font-size: 13px;
            line-height: 1.4;
            color: #4a453e;
            background: #f1f1ef;
            padding: 10px;
            border-left: 4px solid #e51f3f;
            margin-bottom: 14px;
        }

        form {
            margin-top: auto;
        }

        button {
            width: 100%;
            border: 0;
            background: #111;
            color: #fff;
            padding: 13px 14px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            background: #e51f3f;
        }

        details {
            margin-top: 12px;
            font-size: 12px;
            color: #6d6d6d;
        }

        summary {
            cursor: pointer;
            margin-bottom: 8px;
        }

        textarea {
            width: 100%;
            min-height: 160px;
            font-size: 11px;
            line-height: 1.35;
            border: 1px solid #dfdfdc;
            background: #fff;
            padding: 8px;
            box-sizing: border-box;
        }

        .back {
            margin-top: 32px;
        }

        .back a {
            color: #111;
            text-decoration: none;
            border-bottom: 1px solid #111;
        }

        @media (max-width: 1100px) {
            .contexts {
                grid-template-columns: repeat(2, minmax(260px, 1fr));
            }

            .top {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            body {
                padding: 18px;
            }

            .contexts {
                grid-template-columns: 1fr;
            }

            .profile-grid {
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
            <li><a class="active" href="form2.php?image=<?= rawurlencode(basename($imagePath)) ?>">Direccion artistica</a></li>
            <li><a href="account.php">Cuenta y pagos</a></li>
        </ul>
    </aside>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($currentUser['email']) ?></a>
        </header>

        <div class="alert-strip">
            Formulario 2: selecciona una direccion curatorial para generar el mockup.
        </div>

        <div class="workspace">
            <div class="wrap">

    <div class="top">
        <div class="artwork-box">
            <img src="<?= h($imagePublic) ?>" alt="Imagen raíz">
        </div>

        <div>
            <h1>Dirección artística para mockups</h1>

            <div class="subtitle">
                Esta pantalla lee la imagen raiz y propone diez direcciones curatoriales para presentar la obra con intencion, escala y contexto.
            </div>

            <div class="profile">
                <h2>Lectura curatorial</h2>

                <div class="profile-grid">
                    <div class="data">
                        <strong>Lectura curatorial</strong>
                        <?= h($profile['one_line_curatorial_read'] ?? '-') ?>
                    </div>

                    <div class="data">
                        <strong>Estilo</strong>
                        <?= h($profile['style_summary'] ?? '-') ?>
                    </div>

                    <div class="data">
                        <strong>Paleta</strong>
                        <?= h(implode(', ', $profile['palette'] ?? [])) ?>
                    </div>

                    <div class="data">
                        <strong>Atmósfera</strong>
                        <?= h(implode(', ', $profile['mood_tags'] ?? [])) ?>
                    </div>

                    <div class="data">
                        <strong>Orientación</strong>
                        <?= h($orientation ?: '-') ?>
                    </div>

                    <div class="data">
                        <strong>Medidas</strong>
                        <?= h($sizeText ?: '-') ?>
                    </div>

                    <div class="data">
                        <strong>Luminosidad</strong>
                        <?= h($profile['luminosity'] ?? '-') ?>
                    </div>

                    <div class="data">
                        <strong>Uso comercial</strong>
                        <?= h(implode(', ', $profile['commercial_fit'] ?? [])) ?>
                    </div>

                    <div class="data">
                        <strong>Publico sugerido</strong>
                        <?= h($audience ?: '-') ?>
                    </div>

                    <div class="data">
                        <strong>Temporada</strong>
                        <?= h($season ?: '-') ?>
                    </div>

                    <div class="data">
                        <strong>Temperatura emocional</strong>
                        <?= h($emotionalTemperature ?: '-') ?>
                    </div>

                    <div class="data">
                        <strong>Presencia onirica</strong>
                        <?= h($dreamlikeLevel ?: '-') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <h2><?= h(count($contexts)) ?> propuestas de contexto para esta obra</h2>

    <div class="contexts">
        <?php foreach ($contexts as $i => $ctx): ?>
            <?php
                $prompt = $ctx['prompt'] ?? '';
                $ctxId = $ctx['id'] ?? ('ctx_' . ($i + 1));
                $humanProfile = $ctx['human_profile'] ?? null;
                $humanText = match ($humanProfile) {
                    'male_180' => 'hombre 1,80 m',
                    'female_155' => 'mujer 1,55 m',
                    default => (!empty($ctx['with_human']) ? 'figura discreta' : 'no'),
                };
            ?>

            <div class="card">
                <div class="number">Direccion <?= $i + 1 ?></div>

                <h3><?= h($ctx['name'] ?? 'Contexto') ?></h3>

                <span class="purpose">
                    <?= h(str_replace('_', ' ', $ctx['purpose'] ?? '')) ?>
                </span>

                <div class="inline-result" aria-live="polite">
                    <div class="inline-status">Esperando generacion.</div>
                </div>

                <div class="card-copy">
                    <div class="meta">
                        <div><strong>Camara:</strong> <?= h($ctx['camera'] ?? '-') ?></div>
                        <div><strong>Momento:</strong> <?= h($ctx['time_of_day'] ?? '-') ?></div>
                        <div><strong>Colocacion:</strong> <?= h($ctx['placement'] ?? '-') ?></div>
                        <div><strong>Figura humana:</strong> <?= h($humanText) ?></div>
                        <div><strong>Ajuste curatorial:</strong> <?= h($ctx['score'] ?? '-') ?></div>
                    </div>

                    <div class="text">
                        <strong>Escena:</strong><br>
                        <?= h($ctx['scene'] ?? '-') ?>
                    </div>

                    <div class="text">
                        <strong>Luz:</strong><br>
                        <?= h($ctx['lighting'] ?? '-') ?>
                    </div>

                    <div class="why">
                        <?= h($ctx['why'] ?? '') ?>
                    </div>
                </div>

                <form class="inline-mockup-form" action="generate_mockup.php" method="post">
                    <input type="hidden" name="image" value="<?= h(basename($imagePath)) ?>">
                    <input type="hidden" name="json" value="<?= h($jsonPublic) ?>">
                    <input type="hidden" name="context_id" value="<?= h($ctxId) ?>">
                    <input type="hidden" name="prompt" value="<?= h($prompt) ?>">
                    <input type="hidden" name="ajax" value="1">

                    <button type="submit">
                        Generar este mockup
                    </button>
                </form>

                <?php if ($isAdmin): ?>
                    <details>
                        <summary>Ver prompt tecnico</summary>
                        <textarea readonly><?= h($prompt) ?></textarea>
                    </details>
                <?php endif; ?>
            </div>

        <?php endforeach; ?>
    </div>

    <div class="back">
        <a href="artwork_new.php">Volver al formulario inicial</a>
        &nbsp;·&nbsp;
        <a href="dashboard.php">Dashboard</a>
    </div>

</div>
        </div>
    </main>
</div>

<script>
    const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;

    document.querySelectorAll('.inline-mockup-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const card = form.closest('.card');
            const resultBox = card.querySelector('.inline-result');
            const button = form.querySelector('button');
            const originalText = button.textContent;

            card.classList.remove('generated');
            resultBox.classList.add('active');
            resultBox.innerHTML = `
                <div class="inline-loader">
                    <div class="spinner" aria-hidden="true"></div>
                    <div class="inline-status">
                        Generando mockup. Puedes seguir revisando las otras propuestas.
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
