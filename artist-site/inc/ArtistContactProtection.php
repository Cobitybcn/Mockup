<?php
declare(strict_types=1);

final class ArtistContactProtection
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** @var null|callable(array<string,string>):array<string,mixed> */
    private $verificationRequest;

    public function __construct(?callable $verificationRequest = null)
    {
        $this->verificationRequest = $verificationRequest;
    }

    public function siteKey(): string
    {
        return trim((string)(getenv('TURNSTILE_SITE_KEY') ?: ''));
    }

    public function verify(string $token, string $remoteAddress, string $expectedHostname, string $expectedCdata): bool
    {
        $siteKey = $this->siteKey();
        $secretKey = trim((string)(getenv('TURNSTILE_SECRET_KEY') ?: ''));
        if ($siteKey === '' || $secretKey === '') {
            return !$this->isProduction();
        }

        $token = trim($token);
        if ($token === '' || strlen($token) > 2048) return false;

        $payload = [
            'secret' => $secretKey,
            'response' => $token,
            'remoteip' => filter_var($remoteAddress, FILTER_VALIDATE_IP) ? $remoteAddress : '',
        ];
        $response = $this->verificationRequest !== null
            ? ($this->verificationRequest)($payload)
            : $this->requestVerification($payload);

        if (($response['success'] ?? false) !== true) return false;
        if (!hash_equals('artist_contact', (string)($response['action'] ?? ''))) return false;
        if (!hash_equals($expectedCdata, (string)($response['cdata'] ?? ''))) return false;

        $expectedHostname = $this->normalizeHostname($expectedHostname);
        $verifiedHostname = $this->normalizeHostname((string)($response['hostname'] ?? ''));
        return $expectedHostname !== ''
            && $verifiedHostname !== ''
            && hash_equals($expectedHostname, $verifiedHostname);
    }

    /** @param array<string,string> $payload @return array<string,mixed> */
    private function requestVerification(array $payload): array
    {
        $handle = curl_init(self::VERIFY_URL);
        if ($handle === false) throw new RuntimeException('Turnstile verification could not be initialized.');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($body) || $status !== 200) {
            throw new RuntimeException('Turnstile verification failed: ' . ($error !== '' ? $error : 'HTTP ' . $status));
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new RuntimeException('Turnstile returned an invalid response.');
        return $decoded;
    }

    private function isProduction(): bool
    {
        return getenv('K_SERVICE') !== false
            || strtolower(trim((string)(getenv('APP_ENV') ?: ''))) === 'production';
    }

    private function normalizeHostname(string $hostname): string
    {
        $hostname = strtolower(trim(explode(':', $hostname, 2)[0]));
        return filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ? $hostname : '';
    }
}
