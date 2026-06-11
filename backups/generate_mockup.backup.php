<?php
declare(strict_types=1);

ini_set('max_execution_time', '120');
ini_set('max_input_time', '120');
ini_set('memory_limit', '512M');
ini_set('display_errors', '1');
ini_set('log_errors', '1');

set_time_limit(120);

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function wants_json_response(): bool
{
    return (string)($_POST['ajax'] ?? $_GET['ajax'] ?? '') === '1' ||
        str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') ||
        strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function fail_page(string $msg): void
{
    if (wants_json_response()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => $msg,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Error</title></head><body>';
    echo '<p>' . h($msg) . '</p>';
    echo '<p><a href="javascript:history.back()">Volver</a></p>';
    echo '</body></html>';
    exit;
}

function find_image(string $name): ?string
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

function public_path_for_file(string $path): string
{
    $base = basename($path);

    if (is_file(__DIR__ . '/results/' . $base)) {
        return 'media.php?file=' . rawurlencode($base);
    }

    if (is_file(__DIR__ . '/uploads/' . $base)) {
        return 'uploads/' . rawurlencode($base);
    }

    return rawurlencode($base);
}

function read_provider_settings_for_root(string $imagePath): array
{
    $metaPath = __DIR__ . '/results/' . pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.meta.json';

    if (!is_file($metaPath)) {
        return [];
    }

    $data = json_decode((string)file_get_contents($metaPath), true);

    return is_array($data) && isset($data['provider_settings']) && is_array($data['provider_settings'])
        ? $data['provider_settings']
        : [];
}

function assert_root_owner(string $imagePath, array $user): void
{
    $metaPath = __DIR__ . '/results/' . pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.meta.json';

    if (!is_file($metaPath)) {
        fail_page('No se encontro metadata de propiedad para esta obra.');
    }

    $data = json_decode((string)file_get_contents($metaPath), true);

    if (is_array($data) && (int)($data['user_id'] ?? 0) !== (int)$user['id']) {
        fail_page('No tienes acceso a esta obra.');
    }
}

$image = trim((string)($_POST['image'] ?? $_GET['image'] ?? ''));
$json = trim((string)($_POST['json'] ?? $_GET['json'] ?? ''));
$contextId = trim((string)($_POST['context_id'] ?? $_GET['context_id'] ?? ''));
$prompt = trim((string)($_POST['prompt'] ?? $_GET['prompt'] ?? ''));

if ($image === '' || $prompt === '') {
    fail_page('Faltan datos para generar el mockup simulado.');
}

$imagePath = find_image($image);

if (!$imagePath) {
    fail_page('No se encontro la imagen raiz: ' . $image);
}

assert_root_owner($imagePath, $currentUser);

try {
    ProviderSettings::set(read_provider_settings_for_root($imagePath));
    $generator = ServiceFactory::mockupGenerator();
    $result = $generator->generate($imagePath, $contextId, $prompt, [
        'json' => $json,
    ]);

    $pdo = Database::connection();
    $stmt = $pdo->prepare("
        INSERT INTO mockups (user_id, artwork_file, mockup_file, context_id, prompt_file, created_at)
        VALUES (:user_id, :artwork_file, :mockup_file, :context_id, :prompt_file, :created_at)
    ");
    $stmt->execute([
        'user_id' => (int)$currentUser['id'],
        'artwork_file' => basename($imagePath),
        'mockup_file' => basename((string)$result['file']),
        'context_id' => $contextId,
        'prompt_file' => basename((string)$result['prompt_file']),
        'created_at' => date('c'),
    ]);
    $mockupId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    fail_page($e->getMessage());
}

$resultUrl = 'media.php?file=' . rawurlencode($result['file']);
$viewerUrl = isset($mockupId) && $mockupId > 0 ? 'viewer.php?id=' . $mockupId : $resultUrl;
$resultDownloadUrl = $resultUrl . '&download=1';
$rootUrl = public_path_for_file($imagePath);
$promptUrl = 'media.php?file=' . rawurlencode($result['prompt_file']);

if (wants_json_response()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'message' => (string)$result['message'],
        'context_id' => $contextId,
        'mockup_id' => $mockupId,
        'image_url' => $resultUrl,
        'viewer_url' => $viewerUrl,
        'download_url' => $resultDownloadUrl,
        'prompt_url' => $promptUrl,
        'root_url' => $rootUrl,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Mockup generado</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body {
            margin: 0;
            padding: 40px;
            font-family: Arial, Helvetica, sans-serif;
            background: #f6f6f4;
            color: #111;
        }

        .wrap {
            max-width: 1100px;
            margin: 0 auto;
            background: #fff;
            padding: 32px;
            border: 1px solid #dfdfdc;
            box-shadow: 0 18px 46px rgba(0,0,0,.08);
        }

        h1 {
            margin: 0;
        }

        .titlebar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 24px;
        }

        .titlebar .button {
            margin-top: 0;
            margin-right: 0;
            white-space: nowrap;
        }

        img {
            max-width: 100%;
            height: auto;
            display: block;
            border: 1px solid #ddd;
            margin: 20px 0;
        }

        .actions {
            margin-top: 24px;
        }

        .button {
            display: inline-block;
            background: #e51f3f;
            color: #fff;
            padding: 13px 18px;
            text-decoration: none;
            font-weight: bold;
            margin-right: 10px;
            margin-top: 10px;
        }

        .secondary {
            background: #111;
        }

        .info {
            background: #f1f1ef;
            padding: 14px;
            border-left: 4px solid #e51f3f;
            margin-bottom: 20px;
        }

        textarea {
            width: 100%;
            min-height: 220px;
            box-sizing: border-box;
            padding: 12px;
            border: 1px solid #ddd;
            background: #fafafa;
            font-family: Consolas, monospace;
            font-size: 13px;
            line-height: 1.45;
        }

        code {
            background: #eee;
            padding: 2px 5px;
        }

        .hero-image {
            border: 1px solid #dfdfdc;
            background: #f1f1ef;
        }
    </style>
</head>

<body>

<div class="wrap">
    <div class="titlebar">
        <h1>Mockup generado</h1>
        <a class="button" href="<?= h($resultDownloadUrl) ?>">
            Descargar imagen
        </a>
    </div>

    <div class="info">
        <?= h($result['message']) ?><br>
        Contexto: <strong><?= h($contextId ?: '-') ?></strong>
    </div>

    <img class="hero-image" src="<?= h($resultUrl) ?>" alt="Mockup generado">

    <h2>Prompt generado</h2>
    <textarea readonly><?= h($prompt) ?></textarea>

    <div class="actions">
        <a class="button" href="<?= h($viewerUrl) ?>">
            Abrir imagen
        </a>

        <a class="button" href="<?= h($resultDownloadUrl) ?>">
            Descargar imagen
        </a>

        <a class="button secondary" href="<?= h($promptUrl) ?>" target="_blank">
            Abrir prompt
        </a>

        <a class="button secondary" href="<?= h($rootUrl) ?>" target="_blank">
            Abrir imagen raiz
        </a>

        <a class="button secondary" href="form2.php?image=<?= rawurlencode(basename($imagePath)) ?>&json=<?= rawurlencode($json) ?>">
            Volver al formulario 2
        </a>

        <a class="button secondary" href="artwork_new.php">
            Crear otra obra base
        </a>

        <a class="button secondary" href="dashboard.php">
            Dashboard
        </a>
    </div>
</div>

</body>
</html>
