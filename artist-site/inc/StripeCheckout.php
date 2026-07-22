<?php
declare(strict_types=1);

require_once __DIR__ . '/StripeArtistCredentials.php';

final class StripeCheckout
{
    private const SESSION_LIFETIME_SECONDS = 1800;

    private ?\Stripe\StripeClient $client;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
        private readonly string $accountId,
        private readonly string $secretKey,
        private readonly string $webhookSecret,
        ?\Stripe\StripeClient $client = null,
    ) {
        $this->client = $client;
        if ($this->client === null && self::validSecretKey($secretKey) && class_exists(\Stripe\StripeClient::class)) {
            $this->client = new \Stripe\StripeClient($secretKey);
        }
    }

    public static function forArtist(PDO $pdo, int $userId): self
    {
        $credentials = new StripeArtistCredentials($pdo);
        $connection = $credentials->connection($userId);
        return new self(
            $pdo,
            $userId,
            (string)$connection['external_account_id'],
            $credentials->secretKey($userId),
            $credentials->webhookSecret($userId),
        );
    }

    public function signingSecret(): string
    {
        return $this->webhookSecret;
    }

    /** @param array<string,mixed> $offer */
    public static function enabledForOffer(array $offer): bool
    {
        return strtolower((string)($offer['payment_provider'] ?? '')) === 'stripe'
            && (string)($offer['connection_status'] ?? '') === 'connected'
            && !empty($offer['charges_enabled'])
            && !empty($offer['payouts_enabled'])
            && str_starts_with((string)($offer['stripe_account_id'] ?? ''), 'acct_')
            && class_exists(\Stripe\StripeClient::class);
    }

    /**
     * @param array<string,mixed> $order
     * @return array{id:string,url:string}
     */
    public function createSession(array $order, string $successUrl, string $cancelUrl): array
    {
        if (!$this->client || !self::validSecretKey($this->secretKey) || !str_starts_with($this->webhookSecret, 'whsec_')) {
            throw new RuntimeException('Stripe Checkout is not fully configured.');
        }
        if (!preg_match('/^acct_[A-Za-z0-9]+$/', $this->accountId) || (int)$order['user_id'] !== $this->userId) throw new RuntimeException('This artist has not configured a valid Stripe account.');
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
            ], ['idempotency_key' => 'artist-site-order-' . (int)$order['id']]);
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
            $updated->execute([$sessionId, $this->accountId, date('c'), (int)$order['id'], (int)$order['user_id']]);
            if ($updated->rowCount() !== 1) throw new RuntimeException('The order could not be linked to Stripe.');
        } catch (Throwable $error) {
            try { $this->client->checkout->sessions->expire($sessionId); } catch (Throwable) {}
            $this->releaseUnpaidOrder((int)$order['id'], (int)$order['user_id'], 'Stripe session linking failed.');
            throw new RuntimeException('Secure payment could not be started. Please try again.', 0, $error);
        }

        return ['id' => $sessionId, 'url' => $sessionUrl];
    }

    /** @return array<string,mixed> */
    public function syncSession(string $sessionId): array
    {
        if (!$this->client || !str_starts_with($sessionId, 'cs_') || !str_starts_with($this->accountId, 'acct_')) throw new RuntimeException('Invalid Stripe Checkout session.');
        $session = $this->client->checkout->sessions->retrieve($sessionId);
        $data = $session->toArray();
        if ((string)($data['payment_status'] ?? '') === 'paid') {
            $this->processEvent('checkout.session.completed', $data);
        } elseif ((string)($data['status'] ?? '') === 'expired') {
            $this->processEvent('checkout.session.expired', $data);
        }
        return $data;
    }

    public function cancelSession(string $sessionId): void
    {
        if (!$this->client || !str_starts_with($sessionId, 'cs_') || !str_starts_with($this->accountId, 'acct_')) return;
        $session = $this->client->checkout->sessions->retrieve($sessionId);
        $data = $session->toArray();
        if ((string)($data['payment_status'] ?? '') === 'paid') {
            $this->processEvent('checkout.session.completed', $data);
            return;
        }
        if ((string)($data['status'] ?? '') === 'open') {
            $session = $this->client->checkout->sessions->expire($sessionId);
            $data = $session->toArray();
        }
        $this->processEvent('checkout.session.expired', $data);
    }

    /**
     * Idempotently applies a verified Stripe Checkout event.
     * @param array<string,mixed> $session
     * @return array{handled:bool,status:string,order_id:int}
     */
    public function processEvent(string $eventType, array $session): array
    {
        $paidEvent = in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true);
        $failedEvent = in_array($eventType, ['checkout.session.expired', 'checkout.session.async_payment_failed'], true);
        if (!$paidEvent && !$failedEvent) return ['handled' => false, 'status' => 'ignored', 'order_id' => 0];

        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $orderId = max(0, (int)($metadata['order_id'] ?? 0));
        $sessionId = trim((string)($session['id'] ?? ''));
        if ($orderId <= 0 || !str_starts_with($sessionId, 'cs_') || !preg_match('/^acct_[A-Za-z0-9]+$/', $this->accountId)) {
            throw new RuntimeException('Stripe event is missing its order reference.');
        }

        $driver = strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->pdo->beginTransaction();
        try {
            $query = 'SELECT * FROM artist_site_orders WHERE id=?' . ($driver === 'mysql' ? ' FOR UPDATE' : '');
            $orderStmt = $this->pdo->prepare($query);
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            if (!$order || (int)$order['user_id'] !== $this->userId
                || !hash_equals((string)$order['provider_reference'], $sessionId)
                || !hash_equals((string)$order['provider_account_id'], $this->accountId)) {
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

    /** @return array<string,mixed>|null */
    public function receipt(int $orderId, string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artist_site_orders WHERE id=? AND provider_reference=? AND provider_account_id=? LIMIT 1');
        $stmt->execute([$orderId, $sessionId, $this->accountId]);
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

    private static function validSecretKey(string $key): bool { return StripeArtistCredentials::validSecretKey($key); }
}
