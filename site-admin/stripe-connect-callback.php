<?php
declare(strict_types=1);

$localPlatformDirectory = __DIR__ . '/../platform';
$platformDirectory = is_file($localPlatformDirectory . '/app/bootstrap.php') ? $localPlatformDirectory : dirname(__DIR__);
require_once $platformDirectory . '/app/bootstrap.php';
require_once __DIR__ . '/app/SiteManagerService.php';
require_once __DIR__ . '/app/StripeConnectService.php';

$user = Auth::user();
if (!$user || !FeatureAccess::allows($user, FeatureAccess::WEBSITE_MANAGE)) {
    header('Location: ../platform/login.php');
    exit;
}

Auth::start();
$expectedState = (string)($_SESSION['stripe_connect_state'] ?? '');
$expectedUserId = (int)($_SESSION['stripe_connect_user_id'] ?? 0);
unset($_SESSION['stripe_connect_state'], $_SESSION['stripe_connect_user_id']);

try {
    if (isset($_GET['error'])) throw new RuntimeException('Stripe connection was cancelled or refused.');
    $state = (string)($_GET['state'] ?? '');
    if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state) || $expectedUserId !== (int)$user['id']) {
        throw new RuntimeException('Stripe connection verification failed. Please start again.');
    }
    $connection = (new StripeConnectService())->exchangeAuthorizationCode(trim((string)($_GET['code'] ?? '')));
    $manager = new SiteManagerService(Database::connection());
    $manager->saveStripeConnection((int)$user['id'], $connection['account_id'], $connection['livemode'], $connection['account']);
    $_SESSION['site_manager_notice'] = 'Stripe account connected successfully.';
} catch (Throwable $error) {
    $_SESSION['site_manager_error'] = $error->getMessage();
}

header('Location: index.php?area=store&section=payments');
exit;
