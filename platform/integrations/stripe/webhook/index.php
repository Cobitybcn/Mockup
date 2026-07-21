<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/artist-site/inc/StripeCheckout.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow', true);

try {
    if (!StripeCheckout::isConfigured()) throw new RuntimeException('Stripe Connect webhook is not configured.');
    $payload = file_get_contents('php://input');
    $signature = trim((string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
    if (!is_string($payload) || $payload === '' || $signature === '') throw new RuntimeException('Missing Stripe webhook payload or signature.');

    $event = \Stripe\Webhook::constructEvent($payload, $signature, StripeCheckout::webhookSecret());
    $connectedAccountId = trim((string)($event->account ?? ''));
    if (!str_starts_with($connectedAccountId, 'acct_')) throw new RuntimeException('Stripe Connect event is missing its connected account.');
    $object = $event->data->object;
    $data = method_exists($object, 'toArray') ? $object->toArray() : (array)$object;
    $result = StripeCheckout::fromEnvironment(Database::connection())
        ->processConnectEvent((string)$event->type, $data, $connectedAccountId);
    http_response_code(200);
    echo json_encode(['received' => true] + $result, JSON_UNESCAPED_SLASHES);
} catch (\UnexpectedValueException|\Stripe\Exception\SignatureVerificationException $error) {
    http_response_code(400);
    echo json_encode(['received' => false, 'error' => 'Invalid Stripe webhook.']);
} catch (Throwable $error) {
    error_log('Stripe Connect webhook error: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['received' => false, 'error' => 'Stripe webhook could not be processed.']);
}
