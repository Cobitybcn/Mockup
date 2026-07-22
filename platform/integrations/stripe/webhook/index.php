<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/artist-site/inc/StripeCheckout.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow', true);

try {
    $payload = file_get_contents('php://input');
    $signature = trim((string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
    if (!is_string($payload) || $payload === '' || $signature === '') throw new RuntimeException('Missing Stripe webhook payload or signature.');

    $unverified = json_decode($payload, true);
    $orderId = max(0, (int)($unverified['data']['object']['metadata']['order_id'] ?? 0));
    if ($orderId <= 0) {
        http_response_code(200);
        echo json_encode(['received' => true, 'handled' => false, 'status' => 'ignored', 'order_id' => 0]);
        exit;
    }
    $pdo = Database::connection();
    $owner = $pdo->prepare('SELECT user_id FROM artist_site_orders WHERE id=? LIMIT 1');
    $owner->execute([$orderId]);
    $userId = (int)($owner->fetchColumn() ?: 0);
    if ($userId <= 0) throw new RuntimeException('Stripe order owner was not found.');

    $payments = StripeCheckout::forArtist($pdo, $userId);
    $event = \Stripe\Webhook::constructEvent($payload, $signature, $payments->signingSecret());
    $object = $event->data->object;
    $data = method_exists($object, 'toArray') ? $object->toArray() : (array)$object;
    $result = $payments->processEvent((string)$event->type, $data);
    http_response_code(200);
    echo json_encode(['received' => true] + $result, JSON_UNESCAPED_SLASHES);
} catch (\UnexpectedValueException|\Stripe\Exception\SignatureVerificationException $error) {
    http_response_code(400);
    echo json_encode(['received' => false, 'error' => 'Invalid Stripe webhook.']);
} catch (Throwable $error) {
    error_log('Stripe webhook error: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['received' => false, 'error' => 'Stripe webhook could not be processed.']);
}
