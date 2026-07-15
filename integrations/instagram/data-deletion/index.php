<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3).'/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $result = (new InstagramIntegrationService(Database::connection()))->handleDataDeletion((string)($_POST['signed_request'] ?? ''));
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data deletion request.'], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$confirmationCode = trim((string)($_GET['code'] ?? ''));
PublicPage::start('Instagram data deletion | Artwork Mockups', 'Instagram data deletion request and status endpoint.', 'integrations/instagram/data-deletion/', true);
?>
<span class="eyebrow">Instagram privacy</span><h1>Data deletion</h1>
<?php if ($confirmationCode !== ''):?><div class="notice success"><p><strong>Deletion completed.</strong> Confirmation code: <?=PublicPage::h($confirmationCode)?></p></div><?php endif;?>
<div class="info-card"><p>When Instagram sends a valid signed deletion request, Artwork Mockups removes the stored Instagram access token and the Instagram identity associated with it.</p><p>For a complete Artwork Mockups account deletion request, follow the instructions on the <a href="<?=PublicPage::h(PublicPage::path('data-deletion/'))?>">Data Deletion page</a>.</p></div>
<?php PublicPage::end(); ?>
