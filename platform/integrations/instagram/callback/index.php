<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3).'/app/bootstrap.php';
Auth::start();
$user = Auth::user();
$message = '';
$ok = false;
try {
    if (!$user) {
        throw new RuntimeException('Your Artwork Mockups session expired. Sign in and connect again.');
    }
    if (isset($_GET['error'])) {
        throw new RuntimeException('Instagram authorization was cancelled.');
    }
    (new InstagramIntegrationService(Database::connection()))->completeAuthorization(
        (int)$user['id'],
        'artist',
        trim((string)($_GET['code'] ?? '')),
        trim((string)($_GET['state'] ?? ''))
    );
    $message = 'The professional Instagram account is connected. No content was published.';
    $ok = true;
} catch (Throwable $e) {
    $message = $e->getMessage();
}
PublicPage::start('Instagram connection | Artwork Mockups', 'Secure Instagram connection response.', 'integrations/instagram/callback/', true);
?>
<span class="eyebrow">Instagram connection</span><h1><?=$ok ? 'Connection complete' : 'Connection failed'?></h1><div class="info-card"><p><?=PublicPage::h($message)?></p></div><p><a href="<?=PublicPage::h(PublicPage::path('integrations/instagram/'))?>">Return to Instagram connection</a></p>
<?php PublicPage::end(); ?>
