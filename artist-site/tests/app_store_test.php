<?php
declare(strict_types=1);

require dirname(__DIR__) . '/inc/AppStore.php';
require dirname(__DIR__, 2) . '/site-admin/app/SiteManagerService.php';

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY,email TEXT NOT NULL)");
$pdo->exec("CREATE TABLE publications (id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,artwork_sheet_id INTEGER NOT NULL,status TEXT NOT NULL,visibility TEXT NOT NULL)");
$pdo->exec("CREATE TABLE artwork_sheets (id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,canonical_artwork_id INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE artist_site_settings (user_id INTEGER PRIMARY KEY,currency TEXT NOT NULL,payment_provider TEXT NOT NULL DEFAULT '',shipping_rates_json TEXT NOT NULL,shipping_policy TEXT NOT NULL)");
$pdo->exec("CREATE TABLE artist_site_payment_connections (user_id INTEGER PRIMARY KEY,provider TEXT NOT NULL,external_account_id TEXT NOT NULL,connection_status TEXT NOT NULL,charges_enabled INTEGER NOT NULL,payouts_enabled INTEGER NOT NULL,livemode INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE artist_site_print_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,artwork_id INTEGER NOT NULL,title TEXT NOT NULL,sku TEXT NOT NULL,
    size_label TEXT NOT NULL,support TEXT NOT NULL,finish TEXT NOT NULL,inventory_mode TEXT NOT NULL,edition_size INTEGER NOT NULL,
    stock_on_hand INTEGER NOT NULL,stock_reserved INTEGER NOT NULL,price_minor INTEGER NOT NULL,currency TEXT NOT NULL,status TEXT NOT NULL,
    created_at TEXT NOT NULL,updated_at TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE artist_site_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,public_number TEXT NOT NULL,customer_name TEXT NOT NULL,customer_email TEXT NOT NULL,
    payment_status TEXT NOT NULL,order_status TEXT NOT NULL,currency TEXT NOT NULL,subtotal_minor INTEGER NOT NULL,shipping_minor INTEGER NOT NULL,
    tax_minor INTEGER NOT NULL,total_minor INTEGER NOT NULL,provider_reference TEXT NOT NULL,provider_account_id TEXT NOT NULL DEFAULT '',shipping_json TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE artist_site_order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER NOT NULL,print_variant_id INTEGER NOT NULL,artwork_id INTEGER NOT NULL,title TEXT NOT NULL,
    sku TEXT NOT NULL,variant_snapshot_json TEXT NOT NULL,quantity INTEGER NOT NULL,unit_price_minor INTEGER NOT NULL,total_minor INTEGER NOT NULL
)");
$pdo->exec("CREATE TABLE artist_site_activity (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,event_type TEXT NOT NULL,entity_type TEXT NOT NULL,entity_id TEXT NOT NULL,message TEXT NOT NULL,created_at TEXT NOT NULL)");
$rates = json_encode(['europe'=>25000,'africa'=>27000,'asia'=>28000,'north_america'=>29000,'south_america'=>30000,'oceania'=>31000]);
$pdo->prepare('INSERT INTO users(id,email) VALUES(?,?)')->execute([7,'artist@example.com']);
$pdo->prepare('INSERT INTO artwork_sheets(id,user_id,canonical_artwork_id) VALUES(?,?,?)')->execute([41,7,31]);
$pdo->prepare('INSERT INTO publications(id,user_id,artwork_sheet_id,status,visibility) VALUES(?,?,?,?,?)')->execute([51,7,41,'published','public']);
$pdo->prepare('INSERT INTO artist_site_settings(user_id,currency,shipping_rates_json,shipping_policy) VALUES(?,?,?,?)')->execute([7,'EUR',$rates,'Policy']);
$pdo->prepare("INSERT INTO artist_site_print_variants(user_id,artwork_id,title,sku,size_label,support,finish,inventory_mode,edition_size,stock_on_hand,stock_reserved,price_minor,currency,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([7,31,'Original artwork','ART-31','120 × 100 cm','Canvas','','in_stock',2,2,0,150000,'EUR','active',date('c'),date('c')]);

$store = new AppStore($pdo, 'artist@example.com');
$artwork = ['canonical_artwork_id'=>31,'slug'=>'test-work','title'=>'Test Work'];
$baseInput = ['name'=>'Collector','email'=>'collector@example.com','address_line_1'=>'Main Street 1','city'=>'Madrid'];
$first = $store->createOrder($artwork, array_merge($baseInput, ['country_code'=>'ES']));
$second = $store->createOrder($artwork, array_merge($baseInput, ['email'=>'second@example.com','country_code'=>'AR']));

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$expect((int)$first['shipping_minor'] === 25000 && (int)$first['total_minor'] === 175000, 'Europe rate was not applied to the first order.');
$expect((int)$second['shipping_minor'] === 30000 && (int)$second['total_minor'] === 180000, 'South America rate was not applied to the second order.');
$expect((int)$pdo->query('SELECT stock_reserved FROM artist_site_print_variants WHERE id=1')->fetchColumn() === 2, 'Creating orders did not reserve stock atomically.');

$reflection = new ReflectionClass(SiteManagerService::class);
$manager = $reflection->newInstanceWithoutConstructor();
$property = $reflection->getProperty('pdo');
$property->setAccessible(true);
$property->setValue($manager, $pdo);
$manager->updateOrder(7, (int)$first['id'], 'paid');
$manager->updateOrder(7, (int)$first['id'], 'complete');
$manager->updateOrder(7, (int)$second['id'], 'cancel');
$stock = $pdo->query('SELECT stock_on_hand,stock_reserved,status FROM artist_site_print_variants WHERE id=1')->fetch();
$expect((int)$stock['stock_on_hand'] === 1 && (int)$stock['stock_reserved'] === 0 && (string)$stock['status'] === 'active', 'Completing and cancelling orders did not settle stock correctly.');
$expect((string)$pdo->query('SELECT order_status FROM artist_site_orders WHERE id=' . (int)$first['id'])->fetchColumn() === 'completed', 'Completed order status was not persisted.');
$expect((string)$pdo->query('SELECT order_status FROM artist_site_orders WHERE id=' . (int)$second['id'])->fetchColumn() === 'cancelled', 'Cancelled order status was not persisted.');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}
echo "PASS: Store calculates continental shipping, reserves stock, and settles completed or cancelled orders.\n";
