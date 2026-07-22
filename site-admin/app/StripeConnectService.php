<?php
declare(strict_types=1);

final class StripeConnectService
{
    public static function secretKey(): string
    {
        return class_exists('ProviderSettings')
            ? ProviderSettings::stripeConnectSecretKey()
            : trim((string)(getenv('STRIPE_CONNECT_SECRET_KEY') ?: ''));
    }

    public static function clientId(): string
    {
        return class_exists('ProviderSettings')
            ? ProviderSettings::stripeConnectClientId()
            : trim((string)(getenv('STRIPE_CONNECT_CLIENT_ID') ?: ''));
    }

    public static function webhookSecret(): string
    {
        return class_exists('ProviderSettings')
            ? ProviderSettings::stripeConnectWebhookSecret()
            : trim((string)(getenv('STRIPE_CONNECT_WEBHOOK_SECRET') ?: ''));
    }

    public static function redirectUri(): string
    {
        return class_exists('ProviderSettings')
            ? ProviderSettings::stripeConnectRedirectUri()
            : trim((string)(getenv('STRIPE_CONNECT_REDIRECT_URI') ?: ''));
    }

    public static function isConfigured(): bool
    {
        return self::isConnectionConfigured()
            && str_starts_with(self::webhookSecret(), 'whsec_');
    }

    public static function isConnectionConfigured(): bool
    {
        return self::validSecretKey(self::secretKey())
            && str_starts_with(self::clientId(), 'ca_')
            && filter_var(self::redirectUri(), FILTER_VALIDATE_URL)
            && class_exists(\Stripe\StripeClient::class);
    }

    public static function mode(): string
    {
        if (str_starts_with(self::secretKey(), 'sk_live_')) return 'live';
        if (str_starts_with(self::secretKey(), 'sk_test_')) return 'test';
        return 'not configured';
    }

    /** @param array<string,mixed> $user */
    public function authorizationUrl(array $user, string $state, string $businessUrl): string
    {
        if (!self::isConnectionConfigured()) throw new RuntimeException('Stripe Connect authorization credentials are incomplete.');
        if (strlen($state) < 32) throw new RuntimeException('Stripe connection state is invalid.');
        $query = [
            'response_type' => 'code',
            'client_id' => self::clientId(),
            'scope' => 'read_write',
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
            'stripe_user[email]' => strtolower(trim((string)($user['email'] ?? ''))),
            'stripe_user[url]' => $businessUrl,
            'stripe_user[physical_product]' => 'true',
            'stripe_user[product_description]' => 'Original artworks sold through the artist website.',
        ];
        return 'https://connect.stripe.com/oauth/authorize?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{account_id:string,livemode:bool,account:array<string,mixed>} */
    public function exchangeAuthorizationCode(string $code): array
    {
        if (!self::isConnectionConfigured()) throw new RuntimeException('Stripe Connect authorization credentials are incomplete.');
        if ($code === '' || !str_starts_with($code, 'ac_')) throw new RuntimeException('Stripe returned an invalid authorization code.');
        \Stripe\Stripe::setApiKey(self::secretKey());
        $response = \Stripe\OAuth::token(['grant_type' => 'authorization_code', 'code' => $code]);
        $accountId = trim((string)($response->stripe_user_id ?? ''));
        if (!str_starts_with($accountId, 'acct_')) throw new RuntimeException('Stripe did not return a connected account.');
        return [
            'account_id' => $accountId,
            'livemode' => (bool)($response->livemode ?? false),
            'account' => $this->account($accountId),
        ];
    }

    /** @return array<string,mixed> */
    public function account(string $accountId): array
    {
        if (!self::isConnectionConfigured() || !str_starts_with($accountId, 'acct_')) throw new RuntimeException('Stripe account cannot be checked.');
        $client = new \Stripe\StripeClient(self::secretKey());
        return $client->accounts->retrieve($accountId, [])->toArray();
    }

    public function deauthorize(string $accountId): void
    {
        if (!self::isConnectionConfigured() || !str_starts_with($accountId, 'acct_')) throw new RuntimeException('Stripe account cannot be disconnected.');
        \Stripe\Stripe::setApiKey(self::secretKey());
        \Stripe\OAuth::deauthorize(['client_id' => self::clientId(), 'stripe_user_id' => $accountId]);
    }

    private static function validSecretKey(string $key): bool
    {
        return str_starts_with($key, 'sk_test_') || str_starts_with($key, 'sk_live_');
    }
}
