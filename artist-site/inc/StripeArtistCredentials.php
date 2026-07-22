<?php
declare(strict_types=1);

final class StripeArtistCredentials
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function connection(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artist_site_payment_connections WHERE user_id=? AND provider=? LIMIT 1');
        $stmt->execute([$userId, 'stripe']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['user_id'=>$userId,'provider'=>'stripe','external_account_id'=>'','livemode'=>0,'charges_enabled'=>0,'payouts_enabled'=>0,'details_submitted'=>0,'connection_status'=>'not_connected','has_secret_key'=>false,'has_webhook_secret'=>false];
        $row['has_secret_key'] = trim((string)($row['secret_key_encrypted'] ?? '')) !== '';
        $row['has_webhook_secret'] = trim((string)($row['webhook_secret_encrypted'] ?? '')) !== '';
        unset($row['secret_key_encrypted'], $row['webhook_secret_encrypted']);
        return $row;
    }

    /** @return array<string,mixed> */
    public function save(int $userId, string $secretKey, string $webhookSecret): array
    {
        $current = $this->storedConnection($userId);
        $secretKey = trim($secretKey);
        $webhookSecret = trim($webhookSecret);
        if ($secretKey === '' && $current) $secretKey = $this->decrypt((string)$current['secret_key_encrypted']);
        if ($webhookSecret === '' && $current) $webhookSecret = $this->decrypt((string)$current['webhook_secret_encrypted']);
        if (!self::validSecretKey($secretKey)) throw new RuntimeException('Enter a valid Stripe secret key beginning with sk_test_ or sk_live_.');
        if (!str_starts_with($webhookSecret, 'whsec_')) throw new RuntimeException('Enter the signing secret for this Stripe webhook beginning with whsec_.');
        if (!class_exists(\Stripe\StripeClient::class)) throw new RuntimeException('Stripe is temporarily unavailable on this server.');
        try {
            $accountObject = (new \Stripe\StripeClient($secretKey))->accounts->retrieve();
            $account = method_exists($accountObject, 'toArray') ? $accountObject->toArray() : (array)$accountObject;
        } catch (Throwable $error) {
            throw new RuntimeException('Stripe could not verify this secret key. Check it and try again.', 0, $error);
        }
        $accountId = trim((string)($account['id'] ?? ''));
        if (!preg_match('/^acct_[A-Za-z0-9]+$/', $accountId)) throw new RuntimeException('Stripe did not return a valid account.');
        $duplicate = $this->pdo->prepare("SELECT user_id FROM artist_site_payment_connections WHERE provider='stripe' AND external_account_id=? AND user_id<>? AND connection_status<>'disconnected' LIMIT 1");
        $duplicate->execute([$accountId, $userId]);
        if ($duplicate->fetchColumn()) throw new RuntimeException('This Stripe account is already configured for another artist.');
        $previousAccountId = trim((string)($current['external_account_id'] ?? ''));
        if ($previousAccountId !== '' && $previousAccountId !== $accountId && $this->hasPendingOrders($userId, $previousAccountId)) throw new RuntimeException('Resolve or cancel pending Stripe orders before changing accounts.');

        $chargesEnabled = !empty($account['charges_enabled']);
        $payoutsEnabled = !empty($account['payouts_enabled']);
        $detailsSubmitted = !empty($account['details_submitted']);
        $status = $chargesEnabled && $payoutsEnabled && $detailsSubmitted ? 'connected' : 'requirements_due';
        $now = date('c');
        $values = ['stripe',$accountId,str_starts_with($secretKey,'sk_live_')?1:0,$chargesEnabled?1:0,$payoutsEnabled?1:0,$detailsSubmitted?1:0,$status,$this->encrypt($secretKey),$this->encrypt($webhookSecret),$now];
        if ($current) {
            $this->pdo->prepare('UPDATE artist_site_payment_connections SET provider=?,external_account_id=?,livemode=?,charges_enabled=?,payouts_enabled=?,details_submitted=?,connection_status=?,secret_key_encrypted=?,webhook_secret_encrypted=?,updated_at=? WHERE user_id=?')->execute([...$values,$userId]);
        } else {
            $this->pdo->prepare('INSERT INTO artist_site_payment_connections (provider,external_account_id,livemode,charges_enabled,payouts_enabled,details_submitted,connection_status,secret_key_encrypted,webhook_secret_encrypted,created_at,updated_at,user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([...array_slice($values,0,9),$now,$now,$userId]);
        }
        return $this->connection($userId);
    }

    public function disconnect(int $userId): void
    {
        $current = $this->storedConnection($userId);
        $accountId = trim((string)($current['external_account_id'] ?? ''));
        if ($accountId !== '' && $this->hasPendingOrders($userId, $accountId)) throw new RuntimeException('Resolve or cancel pending Stripe orders before disconnecting this account.');
        $this->pdo->prepare("UPDATE artist_site_payment_connections SET external_account_id='',livemode=0,charges_enabled=0,payouts_enabled=0,details_submitted=0,connection_status='disconnected',secret_key_encrypted='',webhook_secret_encrypted='',updated_at=? WHERE user_id=? AND provider='stripe'")->execute([date('c'),$userId]);
    }

    public function secretKey(int $userId): string { $row=$this->storedConnection($userId); return $row?$this->decrypt((string)$row['secret_key_encrypted']):''; }
    public function webhookSecret(int $userId): string { $row=$this->storedConnection($userId); return $row?$this->decrypt((string)$row['webhook_secret_encrypted']):''; }

    public static function encryptionConfigured(): bool
    {
        $encoded = function_exists('app_env') ? trim((string)app_env('STRIPE_CREDENTIALS_KEY','')) : '';
        if ($encoded === '') $encoded = trim((string)(getenv('STRIPE_CREDENTIALS_KEY') ?: ''));
        $key = base64_decode($encoded,true);
        return is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
    }

    public static function validSecretKey(string $key): bool { return str_starts_with($key,'sk_test_') || str_starts_with($key,'sk_live_'); }

    /** @return array<string,mixed>|null */
    private function storedConnection(int $userId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM artist_site_payment_connections WHERE user_id=? AND provider=? LIMIT 1');
        $stmt->execute([$userId,'stripe']);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return $row?:null;
    }

    private function hasPendingOrders(int $userId,string $accountId): bool
    {
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM artist_site_orders WHERE user_id=? AND provider_account_id=? AND payment_status='pending' AND order_status NOT IN ('cancelled','completed')");
        $stmt->execute([$userId,$accountId]);
        return (int)$stmt->fetchColumn()>0;
    }

    private function encrypt(string $plain): string
    {
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1.'.base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$this->key()));
    }

    private function decrypt(string $encoded): string
    {
        if($encoded==='')return '';
        if(!str_starts_with($encoded,'v1.'))throw new RuntimeException('The stored Stripe credential has an invalid version.');
        $raw=base64_decode(substr($encoded,3),true);
        if(!is_string($raw)||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('The stored Stripe credential is damaged.');
        $nonce=substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$this->key());
        if(!is_string($plain))throw new RuntimeException('The stored Stripe credential could not be decrypted.');
        return $plain;
    }

    private function key(): string
    {
        $encoded=function_exists('app_env')?trim((string)app_env('STRIPE_CREDENTIALS_KEY','')):'';
        if($encoded==='')$encoded=trim((string)(getenv('STRIPE_CREDENTIALS_KEY')?:''));
        $key=base64_decode($encoded,true);
        if(!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('Stripe credential encryption is not configured on this server.');
        return $key;
    }
}
