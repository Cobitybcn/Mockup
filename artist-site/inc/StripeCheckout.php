<?php
declare(strict_types=1);

final class StripeCheckout
{
    private const SESSION_LIFETIME_SECONDS = 1800;

    private ?\Stripe\StripeClient $client;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $secretKey,
        private readonly string $webhookSecret,
        ?\Stripe\StripeClient $client = null,
    ) {
        $this->client = $client;
        if ($this->client === null && self::validSecretKey($secretKey) && class_exists(\Stripe\StripeClient::class)) {
            $this->client = new \Stripe\StripeClient($secretKey);
        }
    }

    public static function fromEnvironment(PDO $pdo): self
    {
        return new self($pdo, self::secretKey(), self::webhookSecret());
    }

    public static function secretKey(): string
    {
        return class_exists('ProviderSettings')
            ? ProviderSettings::stripeConnectSecretKey()
            : trim((string)(getenv('STRIPE_CONNECT_SECRET_KEY') ?: ''));
    }

    public static function webhookSecret(): string
    {
        return class_exists('ProviderSettings')
            ? ProviderSettings::stripeConnectWebhookSecret()
            : trim((string)(getenv('STRIPE_CONNECT_WEBHOOK_SECRET') ?: ''));
    }

    public static function isConfigured(): bool
    {
        return self::validSecretKey(self::secretKey())
            && str_starts_with(self::webhookSecret(), 'whsec_')
            && class_exists(\Stripe\StripeClient::class);
    }

    public static function mode(): string
    {
        $key = self::secretKey();
        if (str_starts_with($key, 'sk_live_')) return 'live';
        if (str_starts_with($key, 'sk_test_')) return 'test';
        return 'not configured';
    }

    /** @param array<string,mixed> $offer */
    public static function enabledForOffer(array $offer): bool
    {
        return strtolower((string)($offer['payment_provider'] ?? '')) === 'stripe'
            && (string)($offer['connection_status'] ?? '') === 'connected'
            && !empty($offer['charges_enabled'])
            && !empty($offer['payouts_enabled'])
            && str_starts_with((string)($offer['stripe_account_id'] ?? ''), 'acct_')
            && self::isConfigured();
    }

    /**
     * @param array<string,mixed> $order
     * @return array{id:string,url:string}
     */
    public function createSession(array $order, string $connectedAccountId, string $successUrl, string $cancelUrl): array
    {
        if (!$this->client || !self::validSecretKey($this->secretKey) || !str_starts_with($this->webhookSecret, 'whsec_')) {
            throw new RuntimeException('Stripe Checkout is not fully configured.');
        }
        if (!preg_match('/^acct_[A-Za-z0-9]+$/', $connectedAccountId)) throw new RuntimeException('This artist has not connected a valid Stripe account.');
        foreach ([$successUrl, $cancelUrl] as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw new RuntimeException('Stripe return URLs are not configured correctly.');
            }
        }

        $currency = strtolower((string)$order['currency']);
        $lineItems = [[
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => (int)$order['subtotal_minor'],
                'product_data' => ['name' => (string)$order['artwork_title']],
            ],
            'quantity' => 1,
        ]];
        if ((int)$order['shipping_minor'] > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int)$order['shipping_minor'],
                    'product_data' => ['name' => 'Insured artwork shipping'],
                ],
                'quantity' => 1,
            ];
        }

        try {
            $session = $this->client->checkout->sessions->create([
                'mode' => 'payment',
                'customer_email' => (string)$order['customer_email'],
                'client_reference_id' => (string)$order['public_number'],
                'line_items' => $lineItems,
                'metadata' => [
                    'order_id' => (string)$order['id'],
                    'user_id' => (string)$order['user_id'],
                    'public_number' => (string)$order['public_number'],
                ],
                'payment_intent_data' => [
                    'metadata' => [
                        'order_id' => (string)$order['id'],
                        'public_number' => (string)$order['public_number'],
                    ],
                ],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'expires_at' => time() + self::SESSION_LIFETIME_SECONDS,
            ], [
                'idempotency_key' => 'artist-site-order-' . (int)$order['id'],
                'stripe_account' => $connectedAccountId,
            ]);
        } catch (Throwable $error) {
            $this->releaseUnpaidOrder((int)$order['id'], (int)$order['user_id'], 'Stripe Checkout could not be started.');
            throw new RuntimeException('Secure payment could not be started. Please try again.', 0, $error);
        }

        $sessionId = trim((string)($session->id ?? ''));
        $sessionUrl = trim((string)($session->url ?? ''));
        if ($sessionId === '' || !filter_var($sessionUrl, FILTER_VALIDATE_URL)) {
            $this->releaseUnpaidOrder((int)$order['id'], (int)$order['user_id'], 'Stripe returned an incomplete Checkout session.');
            throw new RuntimeException('Secure payment could not be started. Please try again.');
        }

        try {
            $updated = $this->pdo->prepare("UPDATE artist_site_orders
                SET provider_reference=?,provider_account_id=?,order_status='awaiting_payment',updated_at=?
                WHERE id=? AND user_id=? AND payment_status='pending' AND provider_reference=''");
            $updated->execute([$sessionId, $connectedAccountId, date('c'), (int)$order['id'], (int)$order['user_id']]);
            if ($updated->rowCount() !== 1) throw new RuntimeException('The order could not be linked to Stripe.');
        } catch (Throwable $error) {
            try { $this->client->checkout->sessions->expire($sessionId, [], ['stripe_account' => $connectedAccountId]); } catch (Throwable) {}
            $this->releaseUnpaidOrder((int)$order['id'], (int)$order['user_id'], 'Stripe session linking failed.');
            throw new RuntimeException('Secure payment could not be started. Please try again.', 0, $error);
        }

        return ['id' => $sessionId, 'url' => $sessionUrl];
    }

    /** @return array<string,mixed> */
    public function syncSession(string $sessionId, string $connectedAccountId): array
    {
        if (!$this->client || !str_starts_with($sessionId, 'cs_') || !str_starts_with($connectedAccountId, 'acct_')) throw new RuntimeException('Invalid Stripe Checkout session.');
        $session = $this->client->checkout->sessions->retrieve($sessionId, [], ['stripe_account' => $connectedAccountId]);
        $data = $session->toArray();
        if ((string)($data['payment_status'] ?? '') === 'paid') {
            $this->processEvent('checkout.session.completed', $data, $connectedAccountId);
        } elseif ((string)($data['status'] ?? '') === 'expired') {
            $this->processEvent('checkout.session.expired', $data, $connectedAccountId);
        }
        return $data;
    }

    public function cancelSession(string $sessionId, string $connectedAccountId): void
    {
        if (!$this->client || !str_starts_with($sessionId, 'cs_') || !str_starts_with($connectedAccountId, 'acct_')) return;
        $session = $this->client->checkout->sessions->retrieve($sessionId, [], ['stripe_account' => $connectedAccountId]);
        $data = $session->toArray();
        if ((string)($data['payment_status'] ?? '') === 'paid') {
            $this->processEvent('checkout.session.completed', $data, $connectedAccountId);
            return;
        }
        if ((string)($data['status'] ?? '') === 'open') {
            $session = $this->client->checkout->sessions->expire($sessionId, [], ['stripe_account' => $connectedAccountId]);
            $data = $session->toArray();
        }
        $this->processEvent('checkout.session.expired', $data, $connectedAccountId);
    }

    /**
     * Idempotently applies a verified Stripe Checkout event.
     * @param array<string,mixed> $session
     * @return array{handled:bool,status:string,order_id:int}
     */
    public function processEvent(string $eventType, array $session, string $connectedAccountId): array
    {
        $paidEvent = in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true);
        $failedEvent = in_array($eventType, ['checkout.session.expired', 'checkout.session.async_payment_failed'], true);
        if (!$paidEvent && !$failedEvent) return ['handled' => false, 'status' => 'ignored', 'order_id' => 0];

        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $orderId = max(0, (int)($metadata['order_id'] ?? 0));
        $sessionId = trim((string)($session['id'] ?? ''));
        if ($orderId <= 0 || !str_starts_with($sessionId, 'cs_') || !preg_match('/^acct_[A-Za-z0-9]+$/', $connectedAccountId)) {
            throw new RuntimeException('Stripe event is missing its order reference.');
        }

        $driver = strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->pdo->beginTransaction();
        try {
            $query = 'SELECT * FROM artist_site_orders WHERE id=?' . ($driver === 'mysql' ? ' FOR UPDATE' : '');
            $orderStmt = $this->pdo->prepare($query);
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            if (!$order || !hash_equals((string)$order['provider_reference'], $sessionId)
                || !hash_equals((string)$order['provider_account_id'], $connectedAccountId)) {
                throw new RuntimeException('Stripe order reference does not match.');
            }

            if ($paidEvent) {
                if ((string)($session['payment_status'] ?? '') !== 'paid') {
                    $this->pdo->rollBack();
                    return ['handled' => false, 'status' => 'awaiting_payment', 'order_id' => $orderId];
                }
                $amount = (int)($session['amount_total'] ?? -1);
                $currency = strtoupper((string)($session['currency'] ?? ''));
                if ($amount !== (int)$order['total_minor'] || $currency !== strtoupper((string)$order['currency'])) {
                    throw new RuntimeException('Stripe payment amount or currency does not match the order.');
                }
                if ((string)$order['payment_status'] === 'paid') {
                    $this->pdo->commit();
                    return ['handled' => true, 'status' => 'paid', 'order_id' => $orderId];
                }
                $nextOrderStatus = in_array((string)$order['order_status'], ['request_received', 'awaiting_payment'], true)
                    ? 'confirmed'
                    : (string)$order['order_status'];
                $now = date('c');
                $this->pdo->prepare('UPDATE artist_site_orders SET payment_status=?,order_status=?,updated_at=? WHERE id=?')
                    ->execute(['paid', $nextOrderStatus, $now, $orderId]);
                $this->log((int)$order['user_id'], 'order.paid', $orderId, 'Stripe confirmed payment for ' . (string)$order['public_number'] . '.', $now);
                $this->pdo->commit();
                return ['handled' => true, 'status' => 'paid', 'order_id' => $orderId];
            }

            if ((string)$order['payment_status'] === 'paid' || in_array((string)$order['order_status'], ['cancelled', 'completed'], true)) {
                $this->pdo->commit();
                return ['handled' => true, 'status' => (string)$order['payment_status'], 'order_id' => $orderId];
            }
            $now = date('c');
            $this->releaseReservedStock($orderId, (int)$order['user_id'], $driver, $now);
            $paymentStatus = $eventType === 'checkout.session.async_payment_failed' ? 'failed' : 'expired';
            $this->pdo->prepare("UPDATE artist_site_orders SET payment_status=?,order_status='cancelled',updated_at=? WHERE id=?")
                ->execute([$paymentStatus, $now, $orderId]);
            $this->log((int)$order['user_id'], 'order.cancelled', $orderId, 'Stripe payment was not completed; reserved stock was released.', $now);
            $this->pdo->commit();
            return ['handled' => true, 'status' => $paymentStatus, 'order_id' => $orderId];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Routes verified Connect events, including account lifecycle events.
     * @param array<string,mixed> $object
     * @return array{handled:bool,status:string,order_id:int}
     */
    public function processConnectEvent(string $eventType, array $object, string $connectedAccountId): array
    {
        if ($eventType === 'account.updated') {
            if ((string)($object['id'] ?? '') !== $connectedAccountId) throw new RuntimeException('Stripe account update does not match its Connect envelope.');
            $charges = !empty($object['charges_enabled']);
            $payouts = !empty($object['payouts_enabled']);
            $details = !empty($object['details_submitted']);
            $status = $charges && $payouts && $details ? 'connected' : 'requirements_due';
            $stmt = $this->pdo->prepare('UPDATE artist_site_payment_connections SET charges_enabled=?,payouts_enabled=?,details_submitted=?,connection_status=?,updated_at=? WHERE provider=? AND external_account_id=?');
            $stmt->execute([$charges ? 1 : 0, $payouts ? 1 : 0, $details ? 1 : 0, $status, date('c'), 'stripe', $connectedAccountId]);
            $this->pdo->prepare('UPDATE artist_site_settings SET payment_status=?,updated_at=? WHERE user_id IN (SELECT user_id FROM artist_site_payment_connections WHERE provider=? AND external_account_id=?)')
                ->execute([$status, date('c'), 'stripe', $connectedAccountId]);
            return ['handled' => true, 'status' => $status, 'order_id' => 0];
        }

        if ($eventType === 'account.application.deauthorized') {
            $users = $this->pdo->prepare('SELECT user_id FROM artist_site_payment_connections WHERE provider=? AND external_account_id=?');
            $users->execute(['stripe', $connectedAccountId]);
            foreach ($users->fetchAll(PDO::FETCH_COLUMN) as $userIdValue) {
                $userId = (int)$userIdValue;
                $orders = $this->pdo->prepare("SELECT id FROM artist_site_orders WHERE user_id=? AND provider_account_id=? AND payment_status='pending' AND order_status NOT IN ('cancelled','completed')");
                $orders->execute([$userId, $connectedAccountId]);
                foreach ($orders->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
                    $this->releaseUnpaidOrder((int)$orderId, $userId, 'Stripe access was revoked; reserved stock was released.');
                }
                $this->pdo->prepare("UPDATE artist_site_payment_connections SET charges_enabled=0,payouts_enabled=0,connection_status='disconnected',updated_at=? WHERE user_id=? AND external_account_id=?")
                    ->execute([date('c'), $userId, $connectedAccountId]);
                $this->pdo->prepare("UPDATE artist_site_settings SET payment_provider='',payment_status='not_connected',updated_at=? WHERE user_id=?")
                    ->execute([date('c'), $userId]);
            }
            return ['handled' => true, 'status' => 'disconnected', 'order_id' => 0];
        }

        return $this->processEvent($eventType, $object, $connectedAccountId);
    }

    /** @return array<string,mixed>|null */
    public function receipt(int $orderId, string $sessionId, string $connectedAccountId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artist_site_orders WHERE id=? AND provider_reference=? AND provider_account_id=? LIMIT 1');
        $stmt->execute([$orderId, $sessionId, $connectedAccountId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        return $order ?: null;
    }

    public function releaseUnpaidOrder(int $orderId, int $userId, string $message): void
    {
        $driver = strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->pdo->beginTransaction();
        try {
            $query = 'SELECT * FROM artist_site_orders WHERE id=? AND user_id=?' . ($driver === 'mysql' ? ' FOR UPDATE' : '');
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$orderId, $userId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order || (string)$order['payment_status'] === 'paid' || in_array((string)$order['order_status'], ['cancelled', 'completed'], true)) {
                $this->pdo->commit();
                return;
            }
            $now = date('c');
            $this->releaseReservedStock($orderId, $userId, $driver, $now);
            $this->pdo->prepare("UPDATE artist_site_orders SET payment_status='failed',order_status='cancelled',updated_at=? WHERE id=? AND user_id=?")
                ->execute([$now, $orderId, $userId]);
            $this->log($userId, 'order.cancelled', $orderId, $message, $now);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function releaseReservedStock(int $orderId, int $userId, string $driver, string $now): void
    {
        $items = $this->pdo->prepare('SELECT print_variant_id,quantity FROM artist_site_order_items WHERE order_id=?');
        $items->execute([$orderId]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $variantId = (int)$item['print_variant_id'];
            $quantity = max(1, (int)$item['quantity']);
            $lock = $this->pdo->prepare('SELECT stock_reserved FROM artist_site_print_variants WHERE id=? AND user_id=?' . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute([$variantId, $userId]);
            $reserved = $lock->fetchColumn();
            if ($reserved === false) throw new RuntimeException('Reserved stock record not found.');
            $nextReserved = max(0, (int)$reserved - $quantity);
            $this->pdo->prepare('UPDATE artist_site_print_variants SET stock_reserved=?,updated_at=? WHERE id=? AND user_id=?')
                ->execute([$nextReserved, $now, $variantId, $userId]);
        }
    }

    private function log(int $userId, string $event, int $orderId, string $message, string $now): void
    {
        $this->pdo->prepare('INSERT INTO artist_site_activity (user_id,event_type,entity_type,entity_id,message,created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, $event, 'order', (string)$orderId, $message, $now]);
    }

    private static function validSecretKey(string $key): bool
    {
        return str_starts_with($key, 'sk_test_') || str_starts_with($key, 'sk_live_');
    }
}
