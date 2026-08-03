<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::SOCIAL_MANAGE, 'Social Media');
$pdo = Database::connection();
$userId = (int)$user['id'];
$isAdmin = Auth::isAdmin($user);

$pinterestService = new PinterestIntegrationService($pdo);
$metaService = new MetaIntegrationService($pdo);
$instagramService = new InstagramIntegrationService($pdo);
$tiktokService = new TikTokIntegrationService($pdo);
$xService = new XIntegrationService($pdo);

$connectionError = (string)($_SESSION['connections_error'] ?? '');
$connectionNotice = (string)($_SESSION['connections_notice'] ?? '');
$openConnection = preg_replace('/[^a-z]/', '', (string)($_GET['open'] ?? $_SESSION['connections_open'] ?? ''));
unset($_SESSION['connections_error'], $_SESSION['connections_notice'], $_SESSION['connections_open']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $network = preg_replace('/[^a-z]/', '', (string)($_POST['network'] ?? ''));
    try {
        if (!hash_equals((string)($_SESSION['connections_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException(t('Your session expired. Reload Connections and try again.', 'Tu sesión expiró. Recargá Conexiones e intentá de nuevo.'));
        }

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'connect_facebook') {
            header('Location: ' . $metaService->authorizationUrl($userId, 'artist'));
            exit;
        }
        if ($action === 'select_facebook_page') {
            $metaService->selectPage($userId, 'artist', (string)($_POST['page_id'] ?? ''));
            $_SESSION['connections_notice'] = t('Facebook was connected to the selected Page.', 'Facebook se conectó a la Página seleccionada.');
        } elseif ($action === 'disconnect_facebook') {
            $metaService->disconnect($userId, 'artist');
            $_SESSION['connections_notice'] = t('Facebook was disconnected.', 'Facebook fue desconectado.');
        } elseif ($action === 'connect_instagram') {
            header('Location: ' . $instagramService->authorizationUrl($userId, 'artist'));
            exit;
        } elseif ($action === 'disconnect_instagram') {
            $instagramService->disconnect($userId, 'artist');
            $_SESSION['connections_notice'] = t('Instagram was disconnected.', 'Instagram fue desconectado.');
        } elseif ($action === 'connect_tiktok') {
            header('Location: ' . $tiktokService->authorizationUrl($userId, 'artist'));
            exit;
        } elseif ($action === 'disconnect_tiktok') {
            $tiktokService->disconnect($userId, 'artist');
            $_SESSION['connections_notice'] = t('TikTok was disconnected.', 'TikTok fue desconectado.');
        } elseif ($action === 'connect_x') {
            header('Location: ' . $xService->authorizationUrl($userId, 'artist'));
            exit;
        } elseif ($action === 'disconnect_x') {
            $xService->disconnect($userId, 'artist');
            $_SESSION['connections_notice'] = t('X was disconnected.', 'X fue desconectado.');
        } elseif ($action === 'connect_pinterest') {
            header('Location: ' . $pinterestService->authorizationUrl($userId, 'artist'));
            exit;
        } elseif ($action === 'connect_pinterest_platform') {
            header('Location: ' . $pinterestService->authorizationUrl($userId, 'platform'));
            exit;
        } elseif ($action === 'save_pinterest_app') {
            $requestedPinterestAppId = trim((string)($_POST['app_id'] ?? ''));
            $platformPinterestConfig = $isAdmin ? $pinterestService->platformAppConfiguration($userId) : [];
            $platformPinterestAppId = trim((string)($platformPinterestConfig['app_id'] ?? ''));
            if ($isAdmin && $platformPinterestAppId !== '' && hash_equals($platformPinterestAppId, $requestedPinterestAppId)) {
                throw new RuntimeException(t(
                    'App ' . $platformPinterestAppId . ' is the administrative Artwork Mockups app. Manage it from the “Artwork Mockups administrative accounts” section; do not save it as an artist app.',
                    'La app ' . $platformPinterestAppId . ' es la app administrativa de Artwork Mockups. Gestionála desde “Cuentas administrativas de Artwork Mockups”; no la guardes como app del artista.'
                ));
            }
            $savedApp = $pinterestService->saveArtistAppConfiguration(
                $userId,
                $requestedPinterestAppId,
                (string)($_POST['pinterest_app_secret'] ?? ''),
                (string)($_POST['api_environment'] ?? 'production')
            );
            $_SESSION['connections_notice'] = t('Pinterest app ', 'La app de Pinterest ') . (string)($savedApp['app_id'] ?? '') . t(' was saved securely for this artist.', ' se guardó de forma segura para este artista.');
        } elseif ($action === 'create_pinterest_sandbox_board') {
            $board = $pinterestService->createSandboxDemoBoard($userId, 'artist');
            $_SESSION['connections_notice'] = t('Pinterest Sandbox board “', 'El tablero Sandbox de Pinterest “') . (string)($board['name'] ?? 'Artwork Mockups Sandbox Demo') . t('” is ready.', '” está listo.');
        } elseif ($action === 'create_pinterest_platform_sandbox_board') {
            $board = $pinterestService->createSandboxDemoBoard($userId, 'platform');
            $_SESSION['connections_notice'] = t('Pinterest Sandbox board “', 'El tablero Sandbox de Pinterest “') . (string)($board['name'] ?? 'Artwork Mockups Sandbox Demo') . t('” is ready for the platform account.', '” está listo para la cuenta de la plataforma.');
        } elseif ($action === 'disconnect_pinterest') {
            $pinterestService->disconnect($userId, 'artist');
            $_SESSION['connections_notice'] = t('Pinterest was disconnected.', 'Pinterest fue desconectado.');
        } elseif ($action === 'disconnect_pinterest_platform') {
            $pinterestService->disconnect($userId, 'platform');
            $_SESSION['connections_notice'] = t('The Artwork Mockups Pinterest account was disconnected.', 'La cuenta de Pinterest de Artwork Mockups fue desconectada.');
        } else {
            throw new RuntimeException(t('The connection action is not valid.', 'La acción de conexión no es válida.'));
        }

        $_SESSION['connections_open'] = $network;
        header('Location: connections.php?open=' . rawurlencode($network));
        exit;
    } catch (Throwable $e) {
        $connectionError = $e->getMessage();
        $openConnection = $network;
    }
}

$pinterestArtist = $pinterestService->connection($userId, 'artist');
$pinterestReady = $pinterestService->isPublishingReady($userId, 'artist');
$pinterestApp = $pinterestService->artistAppConfiguration($userId);
$facebookArtist = $metaService->connection($userId, 'artist');
$instagramArtist = $instagramService->connection($userId, 'artist');
$tiktokArtist = $tiktokService->connection($userId, 'artist');
$xArtist = $xService->connection($userId, 'artist');
$facebookPages = [];
if (($facebookArtist['status'] ?? '') === 'awaiting_page') {
    try {
        $facebookPages = $metaService->pages($userId, 'artist');
    } catch (Throwable $e) {
        $connectionError = $e->getMessage();
        $openConnection = 'facebook';
    }
}

$_SESSION['connections_csrf'] = bin2hex(random_bytes(24));

$pinterestPlatform = $isAdmin ? $pinterestService->connection($userId, 'platform') : null;
$pinterestPlatformReady = $isAdmin ? $pinterestService->isPublishingReady($userId, 'platform') : false;
$pinterestPlatformApp = $isAdmin ? $pinterestService->platformAppConfiguration($userId) : null;
$facebookPlatform = $isAdmin ? $metaService->connection($userId, 'platform') : null;
$pinterestPlatformAppId = trim((string)($pinterestPlatformApp['app_id'] ?? ''));
$pinterestArtistAppId = trim((string)($pinterestApp['app_id'] ?? ''));
$pinterestArtistUsesPlatformApp = $isAdmin
    && $pinterestPlatformAppId !== ''
    && $pinterestArtistAppId !== ''
    && hash_equals($pinterestPlatformAppId, $pinterestArtistAppId);
$pinterestPlatformExpectedAccount = '@artworkmockups';

function connections_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return array{label:string,class:string} */
function connections_status(?array $connection): array
{
    $status = strtolower(trim((string)($connection['status'] ?? '')));
    return match ($status) {
        'connected' => ['label' => t('Connected', 'Conectado'), 'class' => 'is-connected'],
        'awaiting_page' => ['label' => t('Choose a Page', 'Elegir una Página'), 'class' => 'is-pending'],
        'pending' => ['label' => t('Pending', 'Pendiente'), 'class' => 'is-pending'],
        'failed', 'needs_verification' => ['label' => t('Needs attention', 'Necesita atención'), 'class' => 'is-error'],
        default => ['label' => t('Not connected', 'No conectado'), 'class' => 'is-offline'],
    };
}

$xUsername = ltrim(trim((string)($xArtist['x_username'] ?? '')), '@');
$xDisplayName = trim((string)($xArtist['x_display_name'] ?? ''));
$xAccountLabel = $xUsername !== ''
    ? '@' . $xUsername
    : ($xDisplayName !== '' ? $xDisplayName : t('Connected X account', 'Cuenta X conectada'));
$tiktokUsername = ltrim(trim((string)($tiktokArtist['tiktok_username'] ?? '')), '@');
$tiktokDisplayName = trim((string)($tiktokArtist['tiktok_display_name'] ?? ''));
$tiktokAccountLabel = $tiktokUsername !== ''
    ? '@' . $tiktokUsername
    : ($tiktokDisplayName !== '' ? $tiktokDisplayName : t('Connected TikTok account', 'Cuenta TikTok conectada'));

$artistConnections = [
    [
        'id' => 'pinterest',
        'name' => 'Pinterest',
        'eyebrow' => t('Personal artist identity', 'Identidad personal del artista'),
        'description' => t('Boards and Pins published as the artist.', 'Tableros y Pins publicados como el artista.'),
        'detail' => $pinterestArtistUsesPlatformApp
            ? t(
                'Configuration conflict: app ' . $pinterestArtistAppId . ' is the administrative app. Use the administrative block above for the Pinterest review.',
                'Configuración mezclada: la app ' . $pinterestArtistAppId . ' es administrativa. Para la revisión de Pinterest usá el bloque administrativo de arriba.'
            )
            : ($pinterestReady
                ? t('Ready for Pinterest publishing.', 'Listo para publicar en Pinterest.')
                : t('Authorize the Pinterest account owned by this artist.', 'Autorizá la cuenta de Pinterest de este artista.')),
        'connection' => $pinterestReady ? $pinterestArtist : null,
        'href' => 'integrations/pinterest/',
        'action' => $pinterestArtistUsesPlatformApp
            ? t('Review mixed configuration', 'Revisar configuración mezclada')
            : ($pinterestReady ? t('Manage artist Pinterest', 'Gestionar Pinterest del artista') : (($pinterestArtist['status'] ?? '') === 'connected' ? t('Update artist Pinterest', 'Actualizar Pinterest del artista') : t('Connect artist Pinterest', 'Conectar Pinterest del artista'))),
    ],
    [
        'id' => 'facebook',
        'name' => 'Facebook',
        'eyebrow' => t('Artist Page', 'Página del artista'),
        'description' => t('Posts published on the selected Facebook Page.', 'Publicaciones en la Página de Facebook seleccionada.'),
        'detail' => ($facebookArtist['status'] ?? '') === 'connected'
            ? (string)($facebookArtist['page_name'] ?? t('Facebook Page', 'Página de Facebook'))
            : (($facebookArtist['status'] ?? '') === 'awaiting_page'
                ? t('Meta is authorized. Select the artist Page.', 'Meta está autorizado. Elegí la Página del artista.')
                : t('Connect a Facebook Page managed by this artist.', 'Conectá una Página de Facebook administrada por este artista.')),
        'connection' => $facebookArtist,
        'href' => 'integrations/meta/',
        'action' => ($facebookArtist['status'] ?? '') === 'connected' ? t('Manage Facebook', 'Gestionar Facebook') : t('Connect Facebook', 'Conectar Facebook'),
    ],
    [
        'id' => 'instagram',
        'name' => 'Instagram',
        'eyebrow' => t('Professional artist account', 'Cuenta profesional del artista'),
        'description' => t('Posts and carousels published as the artist.', 'Publicaciones y carruseles publicados como el artista.'),
        'detail' => ($instagramArtist['status'] ?? '') === 'connected'
            ? '@' . ltrim((string)($instagramArtist['username'] ?? ''), '@')
            : t('Connect the professional Instagram account owned by this artist.', 'Conectá la cuenta profesional de Instagram de este artista.'),
        'connection' => $instagramArtist,
        'href' => 'integrations/instagram/',
        'action' => ($instagramArtist['status'] ?? '') === 'connected' ? t('Manage Instagram', 'Gestionar Instagram') : t('Connect Instagram', 'Conectar Instagram'),
    ],
    [
        'id' => 'x',
        'name' => 'X',
        'eyebrow' => t('Artist account', 'Cuenta del artista'),
        'description' => t('Posts published from the Publication section.', 'Publicaciones enviadas desde la sección Publicación.'),
        'detail' => ($xArtist['status'] ?? '') === 'connected'
            ? $xAccountLabel
            : t('Connect the X account owned by this artist.', 'Conectá la cuenta de X de este artista.'),
        'connection' => $xArtist,
        'href' => 'integrations/x/',
        'action' => ($xArtist['status'] ?? '') === 'connected' ? t('Manage X', 'Gestionar X') : t('Connect X', 'Conectar X'),
    ],
    [
        'id' => 'tiktok',
        'name' => 'TikTok',
        'eyebrow' => t('Artist account', 'Cuenta del artista'),
        'description' => t('Finished videos published from the Video Lab.', 'Videos terminados publicados desde el Video Lab.'),
        'detail' => ($tiktokArtist['status'] ?? '') === 'connected'
            ? $tiktokAccountLabel
            : t('Connect the TikTok account owned by this artist.', 'Conectá la cuenta de TikTok de este artista.'),
        'connection' => $tiktokArtist,
        'href' => 'integrations/tiktok/',
        'action' => ($tiktokArtist['status'] ?? '') === 'connected' ? t('Manage TikTok', 'Gestionar TikTok') : t('Connect TikTok', 'Conectar TikTok'),
    ],
];
?>
<!doctype html>
<html lang="<?= connections_h(Translator::locale($user)) ?>">
<head>
    <meta charset="utf-8">
    <title><?= connections_h(t('Connections - Artwork Mockups', 'Conexiones - Artwork Mockups')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .connections-intro{max-width:760px;margin:0;color:var(--muted);font-size:15px}
        .connections-section{margin-top:30px}
        .connections-section-head{display:flex;justify-content:space-between;gap:24px;align-items:end;margin-bottom:16px}
        .connections-section-head h2{margin:0;font-family:var(--font-serif);font-size:31px;font-weight:500}
        .connections-section-head p{max-width:520px;margin:0;color:var(--muted);text-align:right}
        .connections-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
        .connection-card{display:flex;flex-direction:column;min-height:280px;padding:26px;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface);box-shadow:var(--shadow)}
        .connection-card__top{display:flex;align-items:flex-start;justify-content:space-between;gap:18px}
        .connection-network{display:grid;gap:5px}.connection-network span{color:var(--accent);font-size:9px;font-weight:700;letter-spacing:.12em;text-transform:uppercase}
        .connection-network h3{margin:0;font-family:var(--font-serif);font-size:34px;font-weight:500}
        .connection-status{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;padding:7px 10px;border:1px solid var(--line);border-radius:999px;background:var(--surface-soft);color:var(--muted);font-size:9px;font-weight:700;letter-spacing:.07em;text-transform:uppercase}
        .connection-status::before{content:"";width:7px;height:7px;border-radius:50%;background:#a7a39d}
        .connection-status.is-connected{border-color:#c8d8c5;background:#eef5ec;color:#496246}.connection-status.is-connected::before{background:#63835e}
        .connection-status.is-pending{border-color:#dec99d;background:#fff8e9;color:#765f32}.connection-status.is-pending::before{background:#ba8d3e}
        .connection-status.is-error{border-color:#e0b7b2;background:#fff2f0;color:#8a443e}.connection-status.is-error::before{background:#aa544d}
        .connection-card__description{margin:24px 0 8px;font-size:15px}.connection-card__detail{margin:0 0 24px;color:var(--muted);font-size:12px;overflow-wrap:anywhere}
        .connection-card__action{margin-top:auto}.connection-card__action .button-link{width:100%;justify-content:center;min-height:48px;background:#eef3eb;border-color:#c9d6c5;color:#42513f}
        .connection-card__action .button-link:hover{background:#dfeadd;border-color:#b8cbb3}
        .connections-platform{padding:24px;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface-soft)}
        .connections-platform-grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(260px,.65fr);gap:14px;margin-top:16px}
        .connection-card--platform{min-height:0;background:#f3f7f1;border-color:#c9d6c5;box-shadow:none}
        .connection-card--platform .connection-network span{color:#496246}
        .connection-card--platform .connection-card__description{margin-top:18px}
        .connection-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 18px;margin:14px 0 22px;padding:16px;border:1px solid var(--line);border-radius:6px;background:var(--surface)}
        .connection-facts div{min-width:0}.connection-facts dt{margin:0 0 4px;color:var(--muted);font-size:9px;font-weight:700;letter-spacing:.09em;text-transform:uppercase}
        .connection-facts dd{margin:0;color:var(--ink);font-size:14px;font-weight:600;overflow-wrap:anywhere}
        .connection-review-note{padding:15px 16px;border-left:3px solid #8aa184;background:#f3f7f1;color:#42513f!important}
        .connection-review-note strong{display:block;margin-bottom:4px}
        .connections-platform-item{display:flex;justify-content:space-between;gap:20px;align-items:center;padding:18px;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface)}
        .connections-platform-item strong{display:block}.connections-platform-item small{color:var(--muted)}
        .connections-message{margin:18px 0 0;padding:14px 16px;border:1px solid #c8d8c5;border-radius:var(--radius);background:#eef5ec;color:#496246}
        .connections-message.is-error{border-color:#e0b7b2;background:#fff2f0;color:#8a443e}
        .connection-dialog{width:min(620px,calc(100vw - 32px));max-height:calc(100vh - 48px);padding:0;border:1px solid var(--line);border-radius:10px;background:var(--surface);color:var(--ink);box-shadow:0 20px 55px rgba(54,45,38,.18)}
        .connection-dialog::backdrop{background:rgba(43,40,36,.38)}
        .connection-dialog__head{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;padding:26px 28px 20px;border-bottom:1px solid var(--line)}
        .connection-dialog__head span{display:block;margin-bottom:5px;color:var(--accent);font-size:9px;font-weight:700;letter-spacing:.12em;text-transform:uppercase}
        .connection-dialog__head h2{margin:0;font-family:var(--font-serif);font-size:36px;font-weight:500}
        .connection-dialog__close{display:grid;place-items:center;width:34px;height:34px;padding:0;border:1px solid var(--line);border-radius:50%;background:var(--surface-soft);color:var(--muted);font-size:22px;line-height:1;cursor:pointer}
        .connection-dialog__body{display:grid;gap:20px;padding:24px 28px 28px;overflow:auto}
        .connection-dialog__body p{margin:0;color:var(--muted);line-height:1.6}.connection-dialog__body strong{color:var(--ink)}
        .connection-form{display:grid;gap:16px}.connection-form label{display:grid;gap:7px;color:var(--ink);font-size:12px;font-weight:700}
        .connection-form input,.connection-form select{width:100%;min-height:48px;padding:11px 13px;border:1px solid var(--line);border-radius:5px;background:#fff;color:var(--ink);font:inherit}
        .connection-form input:focus,.connection-form select:focus{outline:2px solid rgba(183,133,139,.24);outline-offset:1px;border-color:var(--accent)}
        .connection-form small{color:var(--muted);font-weight:400;line-height:1.5}.connection-form__row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .connection-form__actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding-top:4px}.connection-form__actions .button-link{min-height:48px;justify-content:center}
        .connection-choice{display:flex!important;grid-template-columns:auto 1fr!important;align-items:center;gap:10px!important;padding:13px;border:1px solid var(--line);border-radius:6px;background:var(--surface-soft)}
        .connection-choice input{width:auto;min-height:0}.connection-summary{padding:16px;border:1px solid var(--line);border-radius:6px;background:var(--surface-soft)}
        .connection-advanced{border:1px solid var(--line);border-radius:6px;background:var(--surface-soft)}
        .connection-advanced summary{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:15px 16px;color:var(--ink);font-size:12px;font-weight:700;cursor:pointer;list-style:none}
        .connection-advanced summary::-webkit-details-marker{display:none}.connection-advanced summary::after{content:"+";color:var(--accent);font-size:18px;font-weight:400}.connection-advanced[open] summary::after{content:"−"}
        .connection-advanced__body{display:grid;gap:16px;padding:0 16px 16px;border-top:1px solid var(--line)}
        .connection-advanced__body>p{padding-top:14px}.connection-callback{overflow-wrap:anywhere;color:var(--ink)!important;font-size:12px}
        @media(max-width:980px){.connections-grid{grid-template-columns:1fr}.connection-card{min-height:230px}.connections-platform-grid{grid-template-columns:1fr}}
        @media(max-width:680px){.connections-section-head{display:block}.connections-section-head p{margin-top:6px;text-align:left}.connection-card__top,.connections-platform-item{align-items:flex-start;flex-direction:column}.connection-form__row,.connection-facts{grid-template-columns:1fr}.connection-dialog__head,.connection-dialog__body{padding-left:20px;padding-right:20px}}
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= connections_h($user['email']) ?></a></header>
        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1><?= connections_h(t('Connections', 'Conexiones')) ?></h1>
                    <p class="connections-intro"><?= connections_h(t('One place for every publishing account owned by this artist. Each network keeps its own credentials and identity.', 'Un solo lugar para todas las cuentas de publicación de este artista. Cada red mantiene sus propias credenciales e identidad.')) ?></p>
                </div>
                <div class="topbar-actions"><a class="button-link secondary" href="social_media_board.php"><?= connections_h(t('Social Media Board', 'Tablero de Redes Sociales')) ?></a></div>
            </div>

            <?php if ($connectionNotice !== ''): ?><div class="connections-message"><?= connections_h($connectionNotice) ?></div><?php endif; ?>
            <?php if ($connectionError !== ''): ?><div class="connections-message is-error"><?= connections_h($connectionError) ?></div><?php endif; ?>

            <?php if ($isAdmin): ?>
                <section class="connections-section connections-platform" aria-labelledby="platform-connections-title">
                    <div class="connections-section-head">
                        <h2 id="platform-connections-title"><?= connections_h(t('Artwork Mockups administrative accounts', 'Cuentas administrativas de Artwork Mockups')) ?></h2>
                        <p><?= connections_h(t(
                            'Use this Pinterest identity for the Standard-access review of the official Artwork Mockups app.',
                            'Para la revisión de acceso Standard de la app oficial, usá esta identidad de Pinterest.'
                        )) ?></p>
                    </div>
                    <div class="connections-platform-grid">
                        <?php $platformPinterestStatus = connections_status($pinterestPlatform); ?>
                        <article class="connection-card connection-card--platform">
                            <div class="connection-card__top">
                                <div class="connection-network">
                                    <span><?= connections_h(t('Administrative account · @artworkmockups', 'Cuenta administrativa · @artworkmockups')) ?></span>
                                    <h3>Pinterest</h3>
                                </div>
                                <span class="connection-status <?= connections_h($platformPinterestStatus['class']) ?>"><?= connections_h($platformPinterestStatus['label']) ?></span>
                            </div>
                            <p class="connection-card__description"><?= connections_h(t(
                                'Official connection used to publish as Artwork Mockups and demonstrate the Pinterest integration.',
                                'Conexión oficial para publicar como Artwork Mockups y demostrar la integración ante Pinterest.'
                            )) ?></p>
                            <dl class="connection-facts">
                                <div><dt><?= connections_h(t('Pinterest account', 'Cuenta de Pinterest')) ?></dt><dd><?= connections_h($pinterestPlatformExpectedAccount) ?></dd></div>
                                <div><dt><?= connections_h(t('OAuth app', 'App OAuth')) ?></dt><dd><?= connections_h(t('App ID ', 'App ID ') . ($pinterestPlatformAppId !== '' ? $pinterestPlatformAppId : t('not configured', 'sin configurar'))) ?></dd></div>
                                <div><dt><?= connections_h(t('API environment', 'Entorno API')) ?></dt><dd><?= connections_h(strtoupper((string)($pinterestPlatformApp['api_environment'] ?? 'production'))) ?></dd></div>
                                <div><dt><?= connections_h(t('Publishing identity', 'Identidad de publicación')) ?></dt><dd>Artwork Mockups</dd></div>
                            </dl>
                            <div class="connection-card__action"><button class="button-link secondary" type="button" data-connection-open="pinterestplatform"><?= connections_h(t('Open administrative Pinterest connection', 'Abrir conexión administrativa de Pinterest')) ?></button></div>
                        </article>
                        <div class="connections-platform-item">
                            <?php $facebookPlatformStatus = connections_status($facebookPlatform); ?>
                            <div><strong>Facebook</strong><small><?= connections_h(t('Administrative account · ', 'Cuenta administrativa · ') . $facebookPlatformStatus['label']) ?></small></div>
                            <a class="button-link secondary" href="integrations/meta/"><?= connections_h(t('Manage administrative Facebook', 'Gestionar Facebook administrativo')) ?></a>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="connections-section" aria-labelledby="artist-connections-title">
                <div class="connections-section-head">
                    <h2 id="artist-connections-title"><?= connections_h($isAdmin ? t('Personal artist accounts', 'Cuentas personales del artista') : t('Artist connections', 'Conexiones del artista')) ?></h2>
                    <p><?php if ($isAdmin): ?><?= connections_h(t('Optional personal identities. Do not use this section for the official Pinterest app review.', 'Identidades personales opcionales. No uses esta sección para revisar la app oficial de Pinterest.')) ?><?php else: ?><?= connections_h(t('These accounts publish artwork as ', 'Estas cuentas publican obras como ')) ?><?= connections_h((string)($user['email'] ?? t('the current artist', 'el artista actual'))) ?>.<?php endif; ?></p>
                </div>
                <div class="connections-grid">
                    <?php foreach ($artistConnections as $item): $status = connections_status($item['connection']); ?>
                        <article class="connection-card" id="<?= connections_h($item['id']) ?>">
                            <div class="connection-card__top">
                                <div class="connection-network"><span><?= connections_h($item['eyebrow']) ?></span><h3><?= connections_h($item['name']) ?></h3></div>
                                <span class="connection-status <?= connections_h($status['class']) ?>"><?= connections_h($status['label']) ?></span>
                            </div>
                            <p class="connection-card__description"><?= connections_h($item['description']) ?></p>
                            <p class="connection-card__detail"><?= connections_h($item['detail']) ?></p>
                            <div class="connection-card__action"><button class="button-link secondary" type="button" data-connection-open="<?= connections_h($item['id']) ?>"><?= connections_h($item['action']) ?></button></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <dialog class="connection-dialog" id="connection-pinterest" aria-labelledby="connection-pinterest-title">
                <div class="connection-dialog__head">
                    <div><span><?= connections_h(t('Personal artist identity', 'Identidad personal del artista')) ?></span><h2 id="connection-pinterest-title">Pinterest<?= $pinterestArtistAppId !== '' ? ' · App ' . connections_h($pinterestArtistAppId) : '' ?></h2></div>
                    <button class="connection-dialog__close" type="button" data-connection-close aria-label="<?= connections_h(t('Close', 'Cerrar')) ?>">&times;</button>
                </div>
                <div class="connection-dialog__body">
                    <?php if ($pinterestArtistUsesPlatformApp): ?>
                        <p class="connection-review-note"><strong><?= connections_h(t('This is not the administrative review connection.', 'Esta no es la conexión administrativa para la revisión.')) ?></strong><?= connections_h(t(
                            'App ' . $pinterestArtistAppId . ' is configured here in the personal artist slot, but that ID belongs to the official Artwork Mockups app. Use “Administrative account · @artworkmockups” above.',
                            'La app ' . $pinterestArtistAppId . ' quedó configurada en el espacio personal del artista, pero ese ID pertenece a la app oficial de Artwork Mockups. Usá “Cuenta administrativa · @artworkmockups” arriba.'
                        )) ?></p>
                    <?php endif; ?>
                    <?php if ($pinterestReady): ?>
                        <dl class="connection-facts">
                            <div><dt><?= connections_h(t('Connection type', 'Tipo de conexión')) ?></dt><dd><?= connections_h(t('Personal artist', 'Personal del artista')) ?></dd></div>
                            <div><dt><?= connections_h(t('Pinterest account ID', 'ID de cuenta Pinterest')) ?></dt><dd><?= connections_h((string)($pinterestArtist['pinterest_account_id'] ?? 'Pinterest')) ?></dd></div>
                            <div><dt><?= connections_h(t('OAuth app', 'App OAuth')) ?></dt><dd><?= connections_h($pinterestArtistAppId !== '' ? 'App ID ' . $pinterestArtistAppId : t('Not configured', 'Sin configurar')) ?></dd></div>
                            <div><dt><?= connections_h(t('API environment', 'Entorno API')) ?></dt><dd><?= connections_h(strtoupper((string)($pinterestApp['api_environment'] ?? 'production'))) ?></dd></div>
                        </dl>
                        <form class="connection-form" method="post">
                            <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>">
                            <input type="hidden" name="network" value="pinterest">
                            <div class="connection-form__actions">
                                <?php if (($pinterestApp['has_secret'] ?? false) === true && !$pinterestArtistUsesPlatformApp): ?><button class="button-link primary" name="action" value="connect_pinterest"><?= connections_h(t('Reconnect artist account with app ', 'Reconectar cuenta del artista con app ')) ?><?= connections_h($pinterestArtistAppId) ?></button><?php endif; ?>
                                <?php if (($pinterestApp['api_environment'] ?? 'production') === 'sandbox' && !$pinterestArtistUsesPlatformApp): ?><button class="button-link secondary" name="action" value="create_pinterest_sandbox_board"><?= connections_h(t('Prepare artist Sandbox board', 'Preparar tablero Sandbox del artista')) ?></button><?php endif; ?>
                                <button class="button-link secondary" name="action" value="disconnect_pinterest"><?= connections_h(t('Disconnect personal artist identity', 'Desconectar identidad personal del artista')) ?></button>
                            </div>
                        </form>
                    <?php else: ?>
                        <p><?= connections_h(($pinterestArtist['status']??'')==='connected' ? t('Pinterest needs a new authorization that allows publishing.', 'Pinterest necesita una nueva autorización que permita publicar.') : t('Sign in to Pinterest and authorize Artwork Mockups.', 'Iniciá sesión en Pinterest y autorizá Artwork Mockups.')) ?> <?= connections_h(t('You will not need to copy codes or tokens.', 'No vas a necesitar copiar códigos ni tokens.')) ?></p>
                        <form class="connection-form" method="post">
                            <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>">
                            <input type="hidden" name="network" value="pinterest">
                            <?php if (($pinterestApp['has_secret'] ?? false) === true && !$pinterestArtistUsesPlatformApp): ?><div class="connection-form__actions"><button class="button-link primary" name="action" value="connect_pinterest"><?= connections_h(($pinterestArtist['status']??'')==='connected' ? t('Update artist Pinterest', 'Actualizar Pinterest del artista') : t('Connect artist Pinterest', 'Conectar Pinterest del artista')) ?> · App <?= connections_h($pinterestArtistAppId) ?></button></div><?php endif; ?>
                        </form>
                    <?php endif; ?>
                    <details class="connection-advanced">
                        <summary><?= connections_h(t('Developer app', 'App de desarrollador')) ?><?= $pinterestApp ? ' · ' . connections_h((string)$pinterestApp['app_id']) : '' ?></summary>
                        <div class="connection-advanced__body">
                            <p><?= connections_h(t('Use a dedicated Pinterest developer app for this artist. Its secret is encrypted and never displayed again.', 'Usá una app de desarrollador de Pinterest dedicada para este artista. Su secreto se encripta y nunca vuelve a mostrarse.')) ?></p>
                            <form class="connection-form" method="post" autocomplete="off">
                                <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>">
                                <input type="hidden" name="network" value="pinterest">
                                <label><?= connections_h(t('App ID', 'ID de la app')) ?>
                                    <input name="app_id" inputmode="numeric" pattern="[0-9]{5,30}" autocomplete="off" data-1p-ignore data-lpignore="true" required value="<?= connections_h((string)($pinterestApp['app_id'] ?? '')) ?>">
                                </label>
                                <label><?= connections_h(t('App Secret', 'Secreto de la app')) ?>
                                    <input type="password" name="pinterest_app_secret" autocomplete="new-password" data-1p-ignore data-lpignore="true" <?=($pinterestApp['has_secret']??false)?'':'required'?> placeholder="<?= connections_h(($pinterestApp['has_secret']??false) ? t('Leave blank to keep the saved secret', 'Dejalo en blanco para mantener el secreto guardado') : t('Paste the app secret', 'Pegá el secreto de la app')) ?>">
                                    <small><?= connections_h(($pinterestApp['has_secret']??false) ? t('A secret is already stored securely.', 'Ya hay un secreto guardado de forma segura.') : t('The secret is sent only to Artwork Mockups over HTTPS.', 'El secreto se envía solo a Artwork Mockups por HTTPS.')) ?></small>
                                </label>
                                <label><?= connections_h(t('API environment', 'Entorno de la API')) ?>
                                    <select name="api_environment">
                                        <option value="production" <?=($pinterestApp['api_environment']??'production')==='production'?'selected':''?>><?= connections_h(t('Production', 'Producción')) ?></option>
                                        <option value="sandbox" <?=($pinterestApp['api_environment']??'production')==='sandbox'?'selected':''?>><?= connections_h(t('Sandbox (Trial demo)', 'Sandbox (demo de prueba)')) ?></option>
                                    </select>
                                    <small><?= connections_h(t('Use Sandbox while Pinterest is reviewing a Trial app. Switch back to Production after Standard access is approved.', 'Usá Sandbox mientras Pinterest revisa una app de prueba. Volvé a Producción cuando se apruebe el acceso Standard.')) ?></small>
                                </label>
                                <p class="connection-callback"><strong><?= connections_h(t('OAuth callback', 'Callback de OAuth')) ?></strong><br><?= connections_h((string)($pinterestApp['redirect_uri'] ?? PublicPage::url('integrations/pinterest/callback'))) ?></p>
                                <div class="connection-form__actions"><button class="button-link secondary" name="action" value="save_pinterest_app"><?= connections_h(t('Save developer app', 'Guardar app de desarrollador')) ?></button></div>
                            </form>
                        </div>
                    </details>
                </div>
            </dialog>

            <?php if ($isAdmin): ?>
                <dialog class="connection-dialog" id="connection-pinterestplatform" aria-labelledby="connection-pinterestplatform-title">
                    <div class="connection-dialog__head">
                        <div><span><?= connections_h(t('Administrative account · @artworkmockups', 'Cuenta administrativa · @artworkmockups')) ?></span><h2 id="connection-pinterestplatform-title">Pinterest · App <?= connections_h($pinterestPlatformAppId) ?></h2></div>
                        <button class="connection-dialog__close" type="button" data-connection-close aria-label="<?= connections_h(t('Close', 'Cerrar')) ?>">&times;</button>
                    </div>
                    <div class="connection-dialog__body">
                        <p class="connection-review-note"><strong><?= connections_h(t('Use this connection in the Pinterest review video.', 'Usá esta conexión en el video de revisión de Pinterest.')) ?></strong><?= connections_h(t(
                            'It demonstrates the official app, the administrative @artworkmockups account and the configured ' . strtoupper((string)($pinterestPlatformApp['api_environment'] ?? 'production')) . ' environment without mixing in the artist identity.',
                            'Demuestra la app oficial, la cuenta administrativa @artworkmockups y el entorno configurado ' . strtoupper((string)($pinterestPlatformApp['api_environment'] ?? 'production')) . ' sin mezclar la identidad del artista.'
                        )) ?></p>
                        <dl class="connection-facts">
                            <div><dt><?= connections_h(t('Connection type', 'Tipo de conexión')) ?></dt><dd><?= connections_h(t('Artwork Mockups administrative', 'Administrativa de Artwork Mockups')) ?></dd></div>
                            <div><dt><?= connections_h(t('Account to authorize', 'Cuenta que debe autorizarse')) ?></dt><dd><?= connections_h($pinterestPlatformExpectedAccount) ?></dd></div>
                            <div><dt><?= connections_h(t('OAuth app', 'App OAuth')) ?></dt><dd>App ID <?= connections_h($pinterestPlatformAppId) ?></dd></div>
                            <div><dt><?= connections_h(t('API environment', 'Entorno API')) ?></dt><dd><?= connections_h(strtoupper((string)($pinterestPlatformApp['api_environment'] ?? 'production'))) ?></dd></div>
                            <div><dt><?= connections_h(t('Connection status', 'Estado de conexión')) ?></dt><dd><?= connections_h($pinterestPlatformReady ? t('Connected and ready', 'Conectada y lista') : t('Authorization required', 'Requiere autorización')) ?></dd></div>
                            <div><dt><?= connections_h(t('Pinterest account ID', 'ID de cuenta Pinterest')) ?></dt><dd><?= connections_h($pinterestPlatformReady ? (string)($pinterestPlatform['pinterest_account_id'] ?? 'Pinterest') : '—') ?></dd></div>
                        </dl>
                        <form class="connection-form" method="post">
                            <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>">
                            <input type="hidden" name="network" value="pinterestplatform">
                            <div class="connection-form__actions">
                                <button class="button-link primary" name="action" value="connect_pinterest_platform"><?= connections_h($pinterestPlatformReady ? t('Repeat OAuth for @artworkmockups · App ', 'Repetir OAuth de @artworkmockups · App ') : t('Connect @artworkmockups · App ', 'Conectar @artworkmockups · App ')) ?><?= connections_h($pinterestPlatformAppId) ?></button>
                                <?php if (($pinterestPlatformApp['api_environment'] ?? 'production') === 'sandbox'): ?><button class="button-link secondary" name="action" value="create_pinterest_platform_sandbox_board"><?= connections_h(t('Prepare Sandbox board · App ', 'Preparar tablero Sandbox · App ')) ?><?= connections_h($pinterestPlatformAppId) ?></button><?php endif; ?>
                                <?php if ($pinterestPlatformReady): ?><button class="button-link secondary" name="action" value="disconnect_pinterest_platform"><?= connections_h(t('Disconnect administrative @artworkmockups identity', 'Desconectar identidad administrativa @artworkmockups')) ?></button><?php endif; ?>
                            </div>
                        </form>
                        <details class="connection-advanced">
                            <summary><?= connections_h(t('Technical details', 'Detalles técnicos')) ?> · App <?= connections_h($pinterestPlatformAppId) ?></summary>
                            <div class="connection-advanced__body">
                                <p><?= connections_h(t('The platform app secret stays on the server and is never displayed.', 'El secreto de la app de la plataforma permanece en el servidor y nunca se muestra.')) ?></p>
                                <p><strong><?= connections_h(t('API environment', 'Entorno de la API')) ?></strong><br><?= connections_h(ucfirst((string)($pinterestPlatformApp['api_environment'] ?? 'production'))) ?></p>
                                <p class="connection-callback"><strong><?= connections_h(t('OAuth callback', 'Callback de OAuth')) ?></strong><br><?= connections_h((string)($pinterestPlatformApp['redirect_uri'] ?? PublicPage::url('integrations/pinterest/callback'))) ?></p>
                            </div>
                        </details>
                    </div>
                </dialog>
            <?php endif; ?>

            <dialog class="connection-dialog" id="connection-facebook" aria-labelledby="connection-facebook-title">
                <div class="connection-dialog__head">
                    <div><span><?= connections_h(t('Artist Page', 'Página del artista')) ?></span><h2 id="connection-facebook-title">Facebook</h2></div>
                    <button class="connection-dialog__close" type="button" data-connection-close aria-label="<?= connections_h(t('Close', 'Cerrar')) ?>">&times;</button>
                </div>
                <div class="connection-dialog__body">
                    <?php if (($facebookArtist['status'] ?? '') === 'connected'): ?>
                        <div class="connection-summary"><p><strong><?= connections_h(t('Connected Page', 'Página conectada')) ?></strong></p><p><?= connections_h((string)($facebookArtist['page_name'] ?? t('Facebook Page', 'Página de Facebook'))) ?></p></div>
                        <p><?= connections_h(t('Facebook publications will be posted to this Page only.', 'Las publicaciones de Facebook se publicarán solo en esta Página.')) ?></p>
                        <form class="connection-form" method="post">
                            <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>"><input type="hidden" name="network" value="facebook">
                            <div class="connection-form__actions"><button class="button-link secondary" name="action" value="disconnect_facebook"><?= connections_h(t('Disconnect Facebook', 'Desconectar Facebook')) ?></button></div>
                        </form>
                    <?php elseif (($facebookArtist['status'] ?? '') === 'awaiting_page'): ?>
                        <p><?= connections_h(t('Facebook has authorized the account. Now choose the artist Page.', 'Facebook autorizó la cuenta. Ahora elegí la Página del artista.')) ?></p>
                        <?php if ($facebookPages): ?>
                            <form class="connection-form" method="post">
                                <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>"><input type="hidden" name="network" value="facebook">
                                <?php foreach ($facebookPages as $page): ?><label class="connection-choice"><input type="radio" name="page_id" value="<?= connections_h((string)$page['id']) ?>" required><span><?= connections_h((string)($page['name'] ?? t('Facebook Page', 'Página de Facebook'))) ?></span></label><?php endforeach; ?>
                                <div class="connection-form__actions"><button class="button-link primary" name="action" value="select_facebook_page"><?= connections_h(t('Use this Page', 'Usar esta Página')) ?></button></div>
                            </form>
                        <?php else: ?><p><?= connections_h(t('No Pages managed by this account were found.', 'No se encontraron Páginas administradas por esta cuenta.')) ?></p><?php endif; ?>
                    <?php else: ?>
                        <p><?= connections_h(t("Connect the artist's professional Page. Facebook will request permission, then return here so you can choose it.", 'Conectá la Página profesional del artista. Facebook pedirá permiso y después volverá acá para que la elijas.')) ?></p>
                        <form class="connection-form" method="post">
                            <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>"><input type="hidden" name="network" value="facebook">
                            <div class="connection-form__actions"><button class="button-link primary" name="action" value="connect_facebook"><?= connections_h(t('Connect Facebook', 'Conectar Facebook')) ?></button></div>
                        </form>
                    <?php endif; ?>
                </div>
            </dialog>

            <dialog class="connection-dialog" id="connection-instagram" aria-labelledby="connection-instagram-title">
                <div class="connection-dialog__head">
                    <div><span><?= connections_h(t('Professional artist account', 'Cuenta profesional del artista')) ?></span><h2 id="connection-instagram-title">Instagram</h2></div>
                    <button class="connection-dialog__close" type="button" data-connection-close aria-label="<?= connections_h(t('Close', 'Cerrar')) ?>">&times;</button>
                </div>
                <div class="connection-dialog__body">
                    <?php if (($instagramArtist['status'] ?? '') === 'connected'): ?>
                        <div class="connection-summary"><p><strong><?= connections_h(t('Connected account', 'Cuenta conectada')) ?></strong></p><p>@<?= connections_h(ltrim((string)($instagramArtist['username'] ?? ''), '@')) ?></p></div>
                        <p><?= connections_h(t('Instagram publications will use this professional account.', 'Las publicaciones de Instagram usarán esta cuenta profesional.')) ?></p>
                        <form class="connection-form" method="post">
                            <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>"><input type="hidden" name="network" value="instagram">
                            <div class="connection-form__actions"><button class="button-link secondary" name="action" value="disconnect_instagram"><?= connections_h(t('Disconnect Instagram', 'Desconectar Instagram')) ?></button></div>
                        </form>
                    <?php else: ?>
                        <?php if ($instagramService->oauthEnabled()): ?>
                            <p><?= connections_h(t("Connect the artist's professional Instagram account directly. Nothing will be published during connection.", 'Conectá directamente la cuenta profesional de Instagram del artista. No se publicará nada durante la conexión.')) ?></p>
                            <form class="connection-form" method="post">
                                <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>"><input type="hidden" name="network" value="instagram">
                                <div class="connection-form__actions"><button class="button-link primary" name="action" value="connect_instagram"><?= connections_h(t('Connect Instagram', 'Conectar Instagram')) ?></button></div>
                            </form>
                        <?php else: ?>
                            <p><strong><?= connections_h(t('Instagram will be connected on the published site.', 'Instagram se conectará en el sitio publicado.')) ?></strong> <?= connections_h(t("Localhost does not contain Instagram's private credentials and will not modify the artist's live connection.", 'Localhost no contiene las credenciales privadas de Instagram y no modificará la conexión en producción del artista.')) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </dialog>

            <dialog class="connection-dialog" id="connection-x" aria-labelledby="connection-x-title">
                <div class="connection-dialog__head">
                    <div><span><?= connections_h(t('Artist account', 'Cuenta del artista')) ?></span><h2 id="connection-x-title">X</h2></div>
                    <button class="connection-dialog__close" type="button" data-connection-close aria-label="<?= connections_h(t('Close', 'Cerrar')) ?>">&times;</button>
                </div>
                <div class="connection-dialog__body">
                    <?php if (($xArtist['status'] ?? '') === 'connected'): ?>
                        <div class="connection-summary"><p><strong><?= connections_h(t('Connected account', 'Cuenta conectada')) ?></strong></p><p><?= connections_h($xAccountLabel) ?></p></div>
                        <p><?= connections_h(t('Publications from the Publication section will use this account.', 'Las publicaciones de la sección Publicación usarán esta cuenta.')) ?></p>
                        <form class="connection-form" method="post">
                            <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>"><input type="hidden" name="network" value="x">
                            <div class="connection-form__actions"><button class="button-link secondary" name="action" value="disconnect_x"><?= connections_h(t('Disconnect X', 'Desconectar X')) ?></button></div>
                        </form>
                    <?php else: ?>
                        <?php if ($xService->oauthEnabled()): ?>
                            <p><?= connections_h(t("Connect the artist's X account directly. Nothing will be published during connection.", 'Conectá directamente la cuenta de X del artista. No se publicará nada durante la conexión.')) ?></p>
                            <form class="connection-form" method="post">
                                <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>"><input type="hidden" name="network" value="x">
                                <div class="connection-form__actions"><button class="button-link primary" name="action" value="connect_x"><?= connections_h(t('Connect X', 'Conectar X')) ?></button></div>
                            </form>
                        <?php else: ?>
                            <p><strong><?= connections_h(t('X is not connected yet.', 'X todavía no está conectado.')) ?></strong> <?= connections_h(t('Set X_OAUTH_ENABLED to true once the callback registered in the X developer portal matches this installation.', 'Poné X_OAUTH_ENABLED en true cuando la URI de retorno registrada en el portal de X coincida con esta instalación.')) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </dialog>

            <dialog class="connection-dialog" id="connection-tiktok" aria-labelledby="connection-tiktok-title">
                <div class="connection-dialog__head">
                    <div><span><?= connections_h(t('Artist account', 'Cuenta del artista')) ?></span><h2 id="connection-tiktok-title">TikTok</h2></div>
                    <button class="connection-dialog__close" type="button" data-connection-close aria-label="<?= connections_h(t('Close', 'Cerrar')) ?>">&times;</button>
                </div>
                <div class="connection-dialog__body">
                    <?php if (($tiktokArtist['status'] ?? '') === 'connected'): ?>
                        <div class="connection-summary"><p><strong><?= connections_h(t('Connected account', 'Cuenta conectada')) ?></strong></p><p><?= connections_h($tiktokAccountLabel) ?></p></div>
                        <p><?= connections_h(t('Video publications will use this account. Posts start private until TikTok approves the app for public posting.', 'Las publicaciones de video usarán esta cuenta. Las publicaciones empiezan privadas hasta que TikTok apruebe la app para publicación pública.')) ?></p>
                        <form class="connection-form" method="post">
                            <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>"><input type="hidden" name="network" value="tiktok">
                            <div class="connection-form__actions"><button class="button-link secondary" name="action" value="disconnect_tiktok"><?= connections_h(t('Disconnect TikTok', 'Desconectar TikTok')) ?></button></div>
                        </form>
                    <?php else: ?>
                        <?php if ($tiktokService->oauthEnabled()): ?>
                            <p><?= connections_h(t("Connect the artist's TikTok account directly. Nothing will be published during connection.", 'Conectá directamente la cuenta de TikTok del artista. No se publicará nada durante la conexión.')) ?></p>
                            <form class="connection-form" method="post">
                                <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>"><input type="hidden" name="network" value="tiktok">
                                <div class="connection-form__actions"><button class="button-link primary" name="action" value="connect_tiktok"><?= connections_h(t('Connect TikTok', 'Conectar TikTok')) ?></button></div>
                            </form>
                        <?php else: ?>
                            <p><strong><?= connections_h(t('TikTok is not connected yet.', 'TikTok todavía no está conectado.')) ?></strong> <?= connections_h(t('This needs a TikTok for Developers app with the Content Posting API scope approved before it can be enabled here.', 'Esto necesita una app de TikTok for Developers con el scope de Content Posting API aprobado antes de poder habilitarse acá.')) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </dialog>
        </div>
    </main>
</div>
<script>
(() => {
    const openDialog = (network) => {
        const dialog = document.getElementById(`connection-${network}`);
        if (dialog && !dialog.open) dialog.showModal();
    };
    document.querySelectorAll('[data-connection-open]').forEach((button) => button.addEventListener('click', () => openDialog(button.dataset.connectionOpen)));
    document.querySelectorAll('[data-connection-close]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
    document.querySelectorAll('.connection-dialog').forEach((dialog) => dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    }));
    const initial = <?= json_encode(in_array($openConnection, ['pinterest', 'pinterestplatform', 'facebook', 'instagram', 'tiktok'], true) ? $openConnection : '', JSON_UNESCAPED_SLASHES) ?>;
    if (initial) openDialog(initial);
})();
</script>
</body>
</html>
