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
    if (!FeatureAccess::allows($user, FeatureAccess::SOCIAL_MANAGE)) {
        throw new RuntimeException('Social Media requires Artist Pro.');
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
    $message = 'La cuenta profesional de Instagram quedó conectada.';
} catch (Throwable $e) {
    $message = $e->getMessage();
}
if (isset($e)) {
    $_SESSION['connections_error'] = $message;
} else {
    $_SESSION['connections_notice'] = $message;
}
$_SESSION['connections_open'] = 'instagram';
header('X-Robots-Tag: noindex, nofollow', true);
header('Location: ' . PublicPage::path('connections.php?open=instagram'));
exit;
