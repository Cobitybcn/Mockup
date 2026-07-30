<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3).'/app/bootstrap.php';
Auth::start();
$user = Auth::user();
$message = '';
try {
    if (!$user) {
        throw new RuntimeException('Your Artwork Mockups session expired. Sign in and connect again.');
    }
    if (!FeatureAccess::allows($user, FeatureAccess::VIDEO_MANAGE)) {
        throw new RuntimeException('Video publishing requires Artist Pro.');
    }
    if (isset($_GET['error'])) {
        throw new RuntimeException('TikTok authorization was cancelled.');
    }
    (new TikTokIntegrationService(Database::connection()))->completeAuthorization(
        (int)$user['id'],
        'artist',
        trim((string)($_GET['code'] ?? '')),
        trim((string)($_GET['state'] ?? ''))
    );
    $message = 'The TikTok account is now connected.';
} catch (Throwable $e) {
    $message = $e->getMessage();
}
if (isset($e)) {
    $_SESSION['connections_error'] = $message;
} else {
    $_SESSION['connections_notice'] = $message;
}
$_SESSION['connections_open'] = 'tiktok';
header('X-Robots-Tag: noindex, nofollow', true);
header('Location: ' . PublicPage::path('connections.php?open=tiktok'));
exit;
