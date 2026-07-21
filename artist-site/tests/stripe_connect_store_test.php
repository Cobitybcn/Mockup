<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/platform/vendor/autoload.php';
require dirname(__DIR__) . '/inc/StripeCheckout.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE artist_site_orders (
    id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,public_number TEXT NOT NULL,payment_status TEXT NOT NULL,order_status TEXT NOT NULL,
    currency TEXT NOT NULL,total_minor INTEGER NOT NULL,provider_reference TEXT NOT NULL,provider_account_id TEXT NOT NULL,updated_at TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE artist_site_order_items (order_id INTEGER NOT NULL,print_variant_id INTEGER NOT NULL,quantity INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE artist_site_print_variants (id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,stock_reserved INTEGER NOT NULL,updated_at TEXT NOT NULL)");
$pdo->exec("CREATE TABLE artist_site_activity (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,event_type TEXT NOT NULL,entity_type TEXT NOT NULL,entity_id TEXT NOT NULL,message TEXT NOT NULL,created_at TEXT NOT NULL)");
$pdo->exec("INSERT INTO artist_site_print_variants(id,user_id,stock_reserved,updated_at) VALUES (9,7,2,'now')");
$pdo->exec("INSERT INTO artist_site_orders VALUES (1,7,'AM-PAID','pending','awaiting_payment','EUR',12500,'cs_test_paid','acct_ArtistA','now')");
$pdo->exec("INSERT INTO artist_site_orders VALUES (2,7,'AM-EXPIRED','pending','awaiting_payment','EUR',12500,'cs_test_expired','acct_ArtistA','now')");
$pdo->exec("INSERT INTO artist_site_order_items VALUES (1,9,1)");
$pdo->exec("INSERT INTO artist_site_order_items VALUES (2,9,1)");

$payments = new StripeCheckout($pdo, '', '', null);
$paid = $payments->processEvent('checkout.session.completed', [
    'id' => 'cs_test_paid',
    'payment_status' => 'paid',
    'amount_total' => 12500,
    'currency' => 'eur',
    'metadata' => ['order_id' => '1'],
], 'acct_ArtistA');
$expired = $payments->processEvent('checkout.session.expired', [
    'id' => 'cs_test_expired',
    'payment_status' => 'unpaid',
    'amount_total' => 12500,
    'currency' => 'eur',
    'metadata' => ['order_id' => '2'],
], 'acct_ArtistA');

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };
$expect($paid['status'] === 'paid', 'Paid Connect event was not applied.');
$expect($expired['status'] === 'expired', 'Expired Connect event was not applied.');
$expect((string)$pdo->query('SELECT payment_status FROM artist_site_orders WHERE id=1')->fetchColumn() === 'paid', 'Paid order was not persisted.');
$expect((string)$pdo->query('SELECT order_status FROM artist_site_orders WHERE id=2')->fetchColumn() === 'cancelled', 'Expired order was not cancelled.');
$expect((int)$pdo->query('SELECT stock_reserved FROM artist_site_print_variants WHERE id=9')->fetchColumn() === 1, 'Expired payment did not release only its own reservation.');

try {
    $payments->processEvent('checkout.session.completed', [
        'id' => 'cs_test_paid', 'payment_status' => 'paid', 'amount_total' => 12500, 'currency' => 'eur', 'metadata' => ['order_id' => '1'],
    ], 'acct_ArtistB');
    $failures[] = 'A different artist account was allowed to settle the order.';
} catch (RuntimeException) {
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}
echo "PASS: Stripe Connect events stay scoped to each artist and settle reservations safely.\n";
