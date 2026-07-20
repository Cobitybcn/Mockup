<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/app/bootstrap.php';
Auth::start();
$user = Auth::user();
if ($user) FeatureAccess::requirePage($user, FeatureAccess::SOCIAL_MANAGE, 'Social Media');
if ($user && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: ' . PublicPage::path('connections.php?open=instagram'));
    exit;
}
$service = new InstagramIntegrationService(Database::connection());
$error = '';
$connection = $user ? $service->connection((int)$user['id']) : null;

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['instagram_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        $error = 'La sesión expiró. Recarga la página.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'connect') {
                header('Location: '.$service->authorizationUrl((int)$user['id']));
                exit;
            }
            if ($action === 'disconnect') {
                $service->disconnect((int)$user['id']);
                header('Location: ./');
                exit;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$_SESSION['instagram_csrf'] = bin2hex(random_bytes(24));
$connected = (($connection['status'] ?? '') === 'connected');
PublicPage::start('Instagram Connection | Artwork Mockups', 'Connect one professional Instagram account for controlled artwork publishing.', 'integrations/instagram/');
?>
<?php if ($user): ?><p><a class="button-link secondary" href="<?= PublicPage::h(PublicPage::path('connections.php')) ?>">Back to Connections</a></p><?php endif; ?>
<style>
    .public-main{--public-max:920px}.instagram-hero{padding:30px 0 34px;border-bottom:1px solid var(--line)}
    .instagram-hero h1{max-width:730px}.instagram-hero .lede{max-width:710px}
    .instagram-alert{margin:28px 0;border:1px solid #ead2ce;background:#fff6f4;color:#8b3329;padding:14px 16px;border-radius:6px}.instagram-alert p{margin:0;color:inherit}
    .instagram-card{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:28px;align-items:center;border:1px solid var(--line);background:var(--surface);padding:28px;border-radius:8px;box-shadow:var(--shadow);margin-top:24px}
    .instagram-card h3{margin:0 0 10px;font-size:29px}.instagram-card p{margin:5px 0}.instagram-card__action{min-width:210px;text-align:right}
    .instagram-badge{display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border:1px solid #cddfce;border-radius:999px;background:#edf6ee;color:#27643a;font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase}.instagram-badge::before{content:"";width:7px;height:7px;border-radius:50%;background:#4f9a62}
    .instagram-rules{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.instagram-rule{border:1px solid var(--line);background:var(--surface);padding:18px;border-radius:6px}.instagram-rule strong{display:block;color:var(--accent);font-size:10px;letter-spacing:.1em;text-transform:uppercase}.instagram-rule p{margin-bottom:0}
    @media(max-width:680px){.instagram-card{grid-template-columns:1fr}.instagram-card__action{text-align:left}.instagram-rules{grid-template-columns:1fr}}
</style>
<section class="instagram-hero">
    <span class="eyebrow">Direct professional connection</span>
    <h1>Instagram</h1>
    <p class="lede">Connect the professional artist account directly. This connection is independent from the Artwork Mockups Facebook Page and never publishes by itself.</p>
</section>
<?php if ($error):?><div class="instagram-alert"><p><?=PublicPage::h($error)?></p></div><?php endif;?>
<section>
    <h2>Artist account</h2>
    <?php if (!$user):?>
        <div class="instagram-card"><div><h3>Sign in required</h3><p>Sign in to Artwork Mockups before connecting Instagram.</p></div><div class="instagram-card__action"><a class="button-link primary" href="<?=PublicPage::h(PublicPage::path('login.php'))?>">Sign in</a></div></div>
    <?php elseif ($connected):?>
        <div class="instagram-card">
            <div><span class="instagram-badge">Connected</span><h3>@<?=PublicPage::h((string)$connection['username'])?></h3><p><strong>Account type:</strong> <?=PublicPage::h((string)$connection['account_type'])?></p><p><strong>Permissions:</strong> <?=PublicPage::h((string)$connection['scopes'])?></p><p><strong>Authorization expires:</strong> <?=PublicPage::h((string)$connection['token_expires_at'])?></p></div>
            <div class="instagram-card__action"><form method="post"><input type="hidden" name="csrf" value="<?=PublicPage::h($_SESSION['instagram_csrf'])?>"><button class="button-link secondary" name="action" value="disconnect">Disconnect</button></form></div>
        </div>
    <?php else:?>
        <div class="instagram-card">
            <div><h3>@mauriziovalch</h3><p>For the professional Creator account used to present and publish the artist's work.</p><?php if (!$service->oauthEnabled()):?><p><strong>Connection is safely paused.</strong> We will enable it after registering the exact callback in Meta.</p><?php endif;?></div>
            <div class="instagram-card__action"><form method="post"><input type="hidden" name="csrf" value="<?=PublicPage::h($_SESSION['instagram_csrf'])?>"><button class="button-link primary" name="action" value="connect" <?=$service->oauthEnabled() ? '' : 'disabled'?>>Connect Instagram</button></form></div>
        </div>
    <?php endif;?>
</section>
<section><h2>Controlled publishing</h2><div class="instagram-rules"><div class="instagram-rule"><strong>Separate</strong><p>Instagram and Facebook keep independent credentials.</p></div><div class="instagram-rule"><strong>Encrypted</strong><p>The Instagram token is encrypted before storage.</p></div><div class="instagram-rule"><strong>Explicit</strong><p>Every publication will require final approval.</p></div></div></section>
<?php PublicPage::end(); ?>
