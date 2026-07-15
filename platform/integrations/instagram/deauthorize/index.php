<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3).'/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        (new InstagramIntegrationService(Database::connection()))->handleDeauthorization((string)($_POST['signed_request'] ?? ''));
        echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid deauthorization request.'], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

PublicPage::start('Instagram deauthorization | Artwork Mockups', 'Secure Instagram deauthorization endpoint.', 'integrations/instagram/deauthorize/', true);
?>
<span class="eyebrow">Instagram security</span><h1>Deauthorization endpoint</h1><div class="info-card"><p>This secure endpoint receives signed Instagram deauthorization requests. No content is published from this page.</p></div>
<?php PublicPage::end(); ?>
