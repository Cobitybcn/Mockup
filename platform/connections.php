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

$connectionError = (string)($_SESSION['connections_error'] ?? '');
$connectionNotice = (string)($_SESSION['connections_notice'] ?? '');
$openConnection = preg_replace('/[^a-z]/', '', (string)($_GET['open'] ?? $_SESSION['connections_open'] ?? ''));
unset($_SESSION['connections_error'], $_SESSION['connections_notice'], $_SESSION['connections_open']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $network = preg_replace('/[^a-z]/', '', (string)($_POST['network'] ?? ''));
    try {
        if (!hash_equals((string)($_SESSION['connections_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Your session expired. Reload Connections and try again.');
        }

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'connect_facebook') {
            header('Location: ' . $metaService->authorizationUrl($userId, 'artist'));
            exit;
        }
        if ($action === 'select_facebook_page') {
            $metaService->selectPage($userId, 'artist', (string)($_POST['page_id'] ?? ''));
            $_SESSION['connections_notice'] = 'Facebook was connected to the selected Page.';
        } elseif ($action === 'disconnect_facebook') {
            $metaService->disconnect($userId, 'artist');
            $_SESSION['connections_notice'] = 'Facebook fue desconectado.';
        } elseif ($action === 'connect_instagram') {
            header('Location: ' . $instagramService->authorizationUrl($userId, 'artist'));
            exit;
        } elseif ($action === 'disconnect_instagram') {
            $instagramService->disconnect($userId, 'artist');
            $_SESSION['connections_notice'] = 'Instagram fue desconectado.';
        } elseif ($action === 'connect_pinterest') {
            header('Location: ' . $pinterestService->authorizationUrl($userId, 'artist'));
            exit;
        } elseif ($action === 'disconnect_pinterest') {
            $pinterestService->disconnect($userId, 'artist');
            $_SESSION['connections_notice'] = 'Pinterest fue desconectado.';
        } else {
            throw new RuntimeException('The connection action is not valid.');
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
$facebookArtist = $metaService->connection($userId, 'artist');
$instagramArtist = $instagramService->connection($userId, 'artist');
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
$facebookPlatform = $isAdmin ? $metaService->connection($userId, 'platform') : null;

function connections_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return array{label:string,class:string} */
function connections_status(?array $connection): array
{
    $status = strtolower(trim((string)($connection['status'] ?? '')));
    return match ($status) {
        'connected' => ['label' => 'Connected', 'class' => 'is-connected'],
        'awaiting_page' => ['label' => 'Choose a Page', 'class' => 'is-pending'],
        'pending' => ['label' => 'Pending', 'class' => 'is-pending'],
        'failed', 'needs_verification' => ['label' => 'Needs attention', 'class' => 'is-error'],
        default => ['label' => 'Not connected', 'class' => 'is-offline'],
    };
}

$artistConnections = [
    [
        'id' => 'pinterest',
        'name' => 'Pinterest',
        'eyebrow' => 'Artist account',
        'description' => 'Boards and Pins published as the artist.',
        'detail' => $pinterestReady
            ? 'Ready for Pinterest publishing.'
            : 'Authorize the Pinterest account owned by this artist.',
        'connection' => $pinterestReady ? $pinterestArtist : null,
        'href' => 'integrations/pinterest/',
        'action' => $pinterestReady ? 'Manage Pinterest' : (($pinterestArtist['status'] ?? '') === 'connected' ? 'Update Pinterest' : 'Connect Pinterest'),
    ],
    [
        'id' => 'facebook',
        'name' => 'Facebook',
        'eyebrow' => 'Artist Page',
        'description' => 'Posts published on the selected Facebook Page.',
        'detail' => ($facebookArtist['status'] ?? '') === 'connected'
            ? (string)($facebookArtist['page_name'] ?? 'Facebook Page')
            : (($facebookArtist['status'] ?? '') === 'awaiting_page'
                ? 'Meta is authorized. Select the artist Page.'
                : 'Connect a Facebook Page managed by this artist.'),
        'connection' => $facebookArtist,
        'href' => 'integrations/meta/',
        'action' => ($facebookArtist['status'] ?? '') === 'connected' ? 'Manage Facebook' : 'Connect Facebook',
    ],
    [
        'id' => 'instagram',
        'name' => 'Instagram',
        'eyebrow' => 'Professional artist account',
        'description' => 'Posts and carousels published as the artist.',
        'detail' => ($instagramArtist['status'] ?? '') === 'connected'
            ? '@' . ltrim((string)($instagramArtist['username'] ?? ''), '@')
            : 'Connect the professional Instagram account owned by this artist.',
        'connection' => $instagramArtist,
        'href' => 'integrations/instagram/',
        'action' => ($instagramArtist['status'] ?? '') === 'connected' ? 'Manage Instagram' : 'Connect Instagram',
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Connections - Artwork Mockups</title>
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
        .connections-platform-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:16px}
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
        @media(max-width:980px){.connections-grid{grid-template-columns:1fr}.connection-card{min-height:230px}.connections-platform-grid{grid-template-columns:1fr}}
        @media(max-width:680px){.connections-section-head{display:block}.connections-section-head p{margin-top:6px;text-align:left}.connection-card__top,.connections-platform-item{align-items:flex-start;flex-direction:column}.connection-form__row{grid-template-columns:1fr}.connection-dialog__head,.connection-dialog__body{padding-left:20px;padding-right:20px}}
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
                    <h1>Connections</h1>
                    <p class="connections-intro">One place for every publishing account owned by this artist. Each network keeps its own credentials and identity.</p>
                </div>
                <div class="topbar-actions"><a class="button-link secondary" href="social_media_board.php">Social Media Board</a></div>
            </div>

            <?php if ($connectionNotice !== ''): ?><div class="connections-message"><?= connections_h($connectionNotice) ?></div><?php endif; ?>
            <?php if ($connectionError !== ''): ?><div class="connections-message is-error"><?= connections_h($connectionError) ?></div><?php endif; ?>

            <section class="connections-section" aria-labelledby="artist-connections-title">
                <div class="connections-section-head">
                    <h2 id="artist-connections-title">Artist connections</h2>
                    <p>These accounts publish artwork as <?= connections_h((string)($user['email'] ?? 'the current artist')) ?>.</p>
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

            <?php if ($isAdmin): ?>
                <section class="connections-section connections-platform" aria-labelledby="platform-connections-title">
                    <div class="connections-section-head">
                        <h2 id="platform-connections-title">Artwork Mockups platform</h2>
                        <p>Administrative identity for promoting the application. It never uses an artist's credentials.</p>
                    </div>
                    <div class="connections-platform-grid">
                        <?php foreach ([
                            ['Pinterest', $pinterestPlatform, 'integrations/pinterest/'],
                            ['Facebook', $facebookPlatform, 'integrations/meta/'],
                        ] as [$name, $connection, $href]): $status = connections_status($connection); ?>
                            <div class="connections-platform-item">
                                <div><strong><?= connections_h($name) ?></strong><small><?= connections_h($status['label']) ?></small></div>
                                <a class="button-link secondary" href="<?= connections_h($href) ?>">Manage</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <dialog class="connection-dialog" id="connection-pinterest" aria-labelledby="connection-pinterest-title">
                <div class="connection-dialog__head">
                    <div><span>Artist account</span><h2 id="connection-pinterest-title">Pinterest</h2></div>
                    <button class="connection-dialog__close" type="button" data-connection-close aria-label="Close">&times;</button>
                </div>
                <div class="connection-dialog__body">
                    <?php if ($pinterestReady): ?>
                        <div class="connection-summary">
                            <p><strong>Connected account</strong></p>
                            <p><?= connections_h((string)($pinterestArtist['pinterest_account_id'] ?? 'Pinterest')) ?></p>
                        </div>
                        <p>This is the identity used when you publish as the artist.</p>
                        <form class="connection-form" method="post">
                            <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>">
                            <input type="hidden" name="network" value="pinterest">
                            <div class="connection-form__actions"><button class="button-link secondary" name="action" value="disconnect_pinterest">Desconectar Pinterest</button></div>
                        </form>
                    <?php else: ?>
                        <p><?=($pinterestArtist['status']??'')==='connected'?'Pinterest needs a new authorization that allows publishing.':'Sign in to Pinterest and authorize Artwork Mockups.'?> You will not need to copy codes or tokens.</p>
                        <form class="connection-form" method="post">
                            <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>">
                            <input type="hidden" name="network" value="pinterest">
                            <div class="connection-form__actions"><button class="button-link primary" name="action" value="connect_pinterest"><?=($pinterestArtist['status']??'')==='connected'?'Actualizar Pinterest':'Conectar Pinterest'?></button></div>
                        </form>
                    <?php endif; ?>
                </div>
            </dialog>

            <dialog class="connection-dialog" id="connection-facebook" aria-labelledby="connection-facebook-title">
                <div class="connection-dialog__head">
                    <div><span>Artist Page</span><h2 id="connection-facebook-title">Facebook</h2></div>
                    <button class="connection-dialog__close" type="button" data-connection-close aria-label="Close">&times;</button>
                </div>
                <div class="connection-dialog__body">
                    <?php if (($facebookArtist['status'] ?? '') === 'connected'): ?>
                        <div class="connection-summary"><p><strong>Connected Page</strong></p><p><?= connections_h((string)($facebookArtist['page_name'] ?? 'Facebook Page')) ?></p></div>
                        <p>Facebook publications will be posted to this Page only.</p>
                        <form class="connection-form" method="post">
                            <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>"><input type="hidden" name="network" value="facebook">
                            <div class="connection-form__actions"><button class="button-link secondary" name="action" value="disconnect_facebook">Desconectar Facebook</button></div>
                        </form>
                    <?php elseif (($facebookArtist['status'] ?? '') === 'awaiting_page'): ?>
                        <p>Facebook has authorized the account. Now choose the artist Page.</p>
                        <?php if ($facebookPages): ?>
                            <form class="connection-form" method="post">
                                <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>"><input type="hidden" name="network" value="facebook">
                                <?php foreach ($facebookPages as $page): ?><label class="connection-choice"><input type="radio" name="page_id" value="<?= connections_h((string)$page['id']) ?>" required><span><?= connections_h((string)($page['name'] ?? 'Facebook Page')) ?></span></label><?php endforeach; ?>
                                <div class="connection-form__actions"><button class="button-link primary" name="action" value="select_facebook_page">Use this Page</button></div>
                            </form>
                        <?php else: ?><p>No Pages managed by this account were found.</p><?php endif; ?>
                    <?php else: ?>
                        <p>Connect Maurizio’s professional Page. Facebook will request permission, then return here so you can choose it.</p>
                        <form class="connection-form" method="post">
                            <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>"><input type="hidden" name="network" value="facebook">
                            <div class="connection-form__actions"><button class="button-link primary" name="action" value="connect_facebook">Conectar Facebook</button></div>
                        </form>
                    <?php endif; ?>
                </div>
            </dialog>

            <dialog class="connection-dialog" id="connection-instagram" aria-labelledby="connection-instagram-title">
                <div class="connection-dialog__head">
                    <div><span>Professional artist account</span><h2 id="connection-instagram-title">Instagram</h2></div>
                    <button class="connection-dialog__close" type="button" data-connection-close aria-label="Close">&times;</button>
                </div>
                <div class="connection-dialog__body">
                    <?php if (($instagramArtist['status'] ?? '') === 'connected'): ?>
                        <div class="connection-summary"><p><strong>Connected account</strong></p><p>@<?= connections_h(ltrim((string)($instagramArtist['username'] ?? ''), '@')) ?></p></div>
                        <p>Instagram publications will use this professional account.</p>
                        <form class="connection-form" method="post">
                            <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>"><input type="hidden" name="network" value="instagram">
                            <div class="connection-form__actions"><button class="button-link secondary" name="action" value="disconnect_instagram">Desconectar Instagram</button></div>
                        </form>
                    <?php else: ?>
                        <?php if ($instagramService->oauthEnabled()): ?>
                            <p>Connect the artist’s professional Instagram account directly. Nothing will be published during connection.</p>
                            <form class="connection-form" method="post">
                                <input type="hidden" name="csrf" value="<?= connections_h($_SESSION['connections_csrf']) ?>"><input type="hidden" name="network" value="instagram">
                                <div class="connection-form__actions"><button class="button-link primary" name="action" value="connect_instagram">Conectar Instagram</button></div>
                            </form>
                        <?php else: ?>
                            <p><strong>Instagram will be connected on the published site.</strong> Localhost does not contain Instagram’s private credentials and will not modify Maurizio’s live connection.</p>
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
    const initial = <?= json_encode(in_array($openConnection, ['pinterest', 'facebook', 'instagram'], true) ? $openConnection : '', JSON_UNESCAPED_SLASHES) ?>;
    if (initial) openDialog(initial);
})();
</script>
</body>
</html>
