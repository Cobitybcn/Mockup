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
        throw new RuntimeException('X authorization was cancelled.');
    }
    (new XIntegrationService(Database::connection()))->completeAuthorization(
        (int)$user['id'],
        'artist',
        trim((string)($_GET['code'] ?? '')),
        trim((string)($_GET['state'] ?? ''))
    );
    $message = 'The X account is now connected.';
} catch (Throwable $e) {
    $message = $e->getMessage();
    // The reason only reached the session, which is exactly what goes missing
    // when the connection does not take. error_log reaches Cloud Logging;
    // Logger writes inside the container, where nobody can read it.
    error_log('X connection failed: ' . $message);
}
if (!isset($e)) {
    error_log('X connection succeeded for user ' . (int)($user['id'] ?? 0));
}
if (isset($e)) {
    $_SESSION['connections_error'] = $message;
} else {
    $_SESSION['connections_notice'] = $message;
}
$_SESSION['connections_open'] = 'x';
header('X-Robots-Tag: noindex, nofollow', true);
header('Location: ' . PublicPage::path('connections.php?open=x'));
exit;
