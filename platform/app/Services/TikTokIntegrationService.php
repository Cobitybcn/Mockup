<?php
declare(strict_types=1);

/**
 * TikTok Content Posting API connection (OAuth 2.0 + PKCE, per TikTok for
 * Developers). Independent from the Meta/Pinterest connections — TikTok
 * requires a code_verifier/code_challenge pair that Instagram's simpler
 * authorization-code flow does not use.
 */
final class TikTokIntegrationService
{
    private const AUTHORIZE_URL = 'https://www.tiktok.com/v2/auth/authorize/';
    private const TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';
    private const USER_INFO_URL = 'https://open.tiktokapis.com/v2/user/info/';

    public function __construct(private PDO $pdo) {}

    public function authorizationUrl(int $userId, string $purpose = 'artist'): string
    {
        Auth::start();
        if (!$this->oauthEnabled()) {
            throw new RuntimeException('TikTok OAuth is disabled until the app credentials and callback are configured.');
        }
        $purpose = $this->purpose($purpose);
        $this->assertPurposeAllowed($userId, $purpose);
        $config = $this->config($purpose);
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $_SESSION['tiktok_oauth_'.$purpose] = [
            'hash' => hash('sha256', $state),
            'verifier' => $verifier,
            'user_id' => $userId,
            'expires_at' => time() + 600,
        ];

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_key' => $config['client_key'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(',', $this->scopes()),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completeAuthorization(int $userId, string $purpose, string $code, string $state): void
    {
        Auth::start();
        $purpose = $this->purpose($purpose);
        $pending = $_SESSION['tiktok_oauth_'.$purpose] ?? null;
        unset($_SESSION['tiktok_oauth_'.$purpose]);

        if (!is_array($pending)
            || (int)($pending['user_id'] ?? 0) !== $userId
            || (int)($pending['expires_at'] ?? 0) < time()
            || $state === ''
            || !hash_equals((string)($pending['hash'] ?? ''), hash('sha256', $state))) {
            throw new RuntimeException('La autorización de TikTok expiró o no es válida. Conecta nuevamente.');
        }
        $verifier = (string)($pending['verifier'] ?? '');
        if ($verifier === '' || $code === '') {
            throw new RuntimeException('TikTok no devolvió un código de autorización.');
        }

        $config = $this->config($purpose);
        $token = $this->requestJson('POST', self::TOKEN_URL, [
            'client_key' => $config['client_key'],
            'client_secret' => $config['client_secret'],
            'grant_type' => 'authorization_code',
            'redirect_uri' => $config['redirect_uri'],
            'code' => $code,
            'code_verifier' => $verifier,
        ]);
        $this->storeTokenResponse($userId, $purpose, $token);
    }

    public function connection(int $userId, string $purpose = 'artist'): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id,user_id,purpose,tiktok_open_id,tiktok_username,tiktok_display_name,token_expires_at,scopes,status,connected_at FROM tiktok_connections WHERE user_id=? AND purpose=? LIMIT 1');
        $stmt->execute([$userId, $this->purpose($purpose)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array{open_id:string,username:string,access_token:string,api_version:string} */
    public function publishingContext(int $userId, string $purpose = 'artist'): array
    {
        $purpose = $this->purpose($purpose);
        $connection = $this->connection($userId, $purpose);
        if (!is_array($connection) || ($connection['status'] ?? '') !== 'connected') {
            throw new RuntimeException('Conecta la cuenta de TikTok antes de publicar.');
        }
        $granted = array_values(array_filter(array_map('trim', explode(',', (string)($connection['scopes'] ?? '')))));
        $missing = array_values(array_diff($this->scopes(), $granted));
        if ($missing) {
            throw new RuntimeException('TikTok no concedió los permisos requeridos: '.implode(', ', $missing).'.');
        }

        // TikTok access tokens are short-lived (about 24h); refresh proactively
        // rather than failing publishes once they expire.
        if (strtotime((string)($connection['token_expires_at'] ?? '')) <= time() + 300) {
            $this->refreshAccessToken($userId, $purpose);
            $connection = $this->connection($userId, $purpose);
            if (!is_array($connection) || ($connection['status'] ?? '') !== 'connected') {
                throw new RuntimeException('La conexión de TikTok expiró. Conecta nuevamente.');
            }
        }

        $stmt = $this->pdo->prepare('SELECT access_token_encrypted FROM tiktok_connections WHERE user_id=? AND purpose=? AND status=? LIMIT 1');
        $stmt->execute([$userId, $purpose, 'connected']);
        $encrypted = (string)($stmt->fetchColumn() ?: '');
        if ($encrypted === '') {
            throw new RuntimeException('La cuenta de TikTok no tiene un token disponible. Conecta nuevamente.');
        }
        return [
            'open_id' => (string)$connection['tiktok_open_id'],
            'username' => (string)$connection['tiktok_username'],
            'access_token' => $this->decrypt($encrypted),
            'api_version' => 'v2',
        ];
    }

    public function disconnect(int $userId, string $purpose = 'artist'): void
    {
        $purpose = $this->purpose($purpose);
        $this->assertPurposeAllowed($userId, $purpose);
        $now = date('c');
        $this->pdo->prepare("UPDATE tiktok_connections SET access_token_encrypted=NULL,refresh_token_encrypted=NULL,status='disconnected',disconnected_at=?,updated_at=? WHERE user_id=? AND purpose=?")
            ->execute([$now, $now, $userId, $purpose]);
    }

    public function oauthEnabled(): bool
    {
        return strtolower(app_env('TIKTOK_OAUTH_ENABLED', 'false')) === 'true'
            && app_env('TIKTOK_CLIENT_KEY_ARTIST') !== ''
            && app_env('TIKTOK_CLIENT_SECRET_ARTIST') !== ''
            && app_env('TIKTOK_REDIRECT_URI_ARTIST') !== '';
    }

    private function refreshAccessToken(int $userId, string $purpose): void
    {
        $stmt = $this->pdo->prepare('SELECT refresh_token_encrypted FROM tiktok_connections WHERE user_id=? AND purpose=? AND status=? LIMIT 1');
        $stmt->execute([$userId, $purpose, 'connected']);
        $encrypted = (string)($stmt->fetchColumn() ?: '');
        if ($encrypted === '') {
            throw new RuntimeException('La conexión de TikTok no tiene un refresh token disponible. Conecta nuevamente.');
        }
        $config = $this->config($purpose);
        $token = $this->requestJson('POST', self::TOKEN_URL, [
            'client_key' => $config['client_key'],
            'client_secret' => $config['client_secret'],
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->decrypt($encrypted),
        ]);
        $this->storeTokenResponse($userId, $purpose, $token, refreshIdentity: false);
    }

    private function storeTokenResponse(int $userId, string $purpose, array $token, bool $refreshIdentity = true): void
    {
        $accessToken = trim((string)($token['access_token'] ?? ''));
        $refreshToken = trim((string)($token['refresh_token'] ?? ''));
        $openId = trim((string)($token['open_id'] ?? ''));
        if ($accessToken === '' || $refreshToken === '' || $openId === '') {
            throw new RuntimeException('TikTok no devolvió un token completo.');
        }
        $grantedScopes = array_values(array_filter(array_map('trim', explode(',', (string)($token['scope'] ?? implode(',', $this->scopes()))))));

        $username = '';
        $displayName = '';
        if ($refreshIdentity) {
            $profile = $this->requestUserInfo($accessToken);
            $username = trim((string)($profile['username'] ?? ''));
            $displayName = trim((string)($profile['display_name'] ?? ''));
        }

        $now = date('c');
        $accessExpiresAt = date('c', time() + max(60, (int)($token['expires_in'] ?? 86400)));
        $refreshExpiresAt = date('c', time() + max(60, (int)($token['refresh_expires_in'] ?? 31536000)));
        $scopeValue = implode(',', $grantedScopes ?: $this->scopes());

        $existing = $this->connection($userId, $purpose);
        if ($existing) {
            if ($refreshIdentity) {
                $this->pdo->prepare('UPDATE tiktok_connections SET tiktok_open_id=?,tiktok_username=?,tiktok_display_name=?,access_token_encrypted=?,refresh_token_encrypted=?,token_expires_at=?,refresh_expires_at=?,scopes=?,status=?,connected_at=?,disconnected_at=NULL,updated_at=? WHERE user_id=? AND purpose=?')
                    ->execute([$openId, $username, $displayName, $this->encrypt($accessToken), $this->encrypt($refreshToken), $accessExpiresAt, $refreshExpiresAt, $scopeValue, 'connected', $now, $now, $userId, $purpose]);
            } else {
                $this->pdo->prepare('UPDATE tiktok_connections SET access_token_encrypted=?,refresh_token_encrypted=?,token_expires_at=?,refresh_expires_at=?,status=?,updated_at=? WHERE user_id=? AND purpose=?')
                    ->execute([$this->encrypt($accessToken), $this->encrypt($refreshToken), $accessExpiresAt, $refreshExpiresAt, 'connected', $now, $userId, $purpose]);
            }
            return;
        }
        $this->pdo->prepare('INSERT INTO tiktok_connections (user_id,purpose,tiktok_open_id,tiktok_username,tiktok_display_name,access_token_encrypted,refresh_token_encrypted,token_expires_at,refresh_expires_at,scopes,status,connected_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$userId, $purpose, $openId, $username, $displayName, $this->encrypt($accessToken), $this->encrypt($refreshToken), $accessExpiresAt, $refreshExpiresAt, $scopeValue, 'connected', $now, $now, $now]);
    }

    private function requestUserInfo(string $accessToken): array
    {
        $response = $this->requestJson('GET', self::USER_INFO_URL, ['fields' => 'open_id,username,display_name'], $accessToken);
        return (array)($response['data']['user'] ?? []);
    }

    private function requestJson(string $method, string $url, array $parameters, string $bearerToken = ''): array
    {
        $method = strtoupper($method);
        $curl = curl_init();
        $headers = ['Accept: application/json'];
        if ($bearerToken !== '') {
            $headers[] = 'Authorization: Bearer '.$bearerToken;
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_URL] = $url;
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $options[CURLOPT_URL] = $url.($parameters ? '?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986) : '');
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $data = is_string($body) ? json_decode($body, true) : null;
        $apiError = is_array($data) ? (string)($data['error']['code'] ?? '') : '';
        if ($error !== '' || $status < 200 || $status >= 300 || !is_array($data) || ($apiError !== '' && $apiError !== 'ok')) {
            $message = is_array($data) ? trim((string)($data['error']['message'] ?? '')) : '';
            throw new RuntimeException('TikTok API respondió con un error (HTTP '.$status.').'.($message !== '' ? ' '.mb_substr($message, 0, 240) : ''));
        }
        return $data;
    }

    private function config(string $purpose): array
    {
        $suffix = strtoupper($this->purpose($purpose));
        $config = [
            'client_key' => app_env('TIKTOK_CLIENT_KEY_'.$suffix),
            'client_secret' => app_env('TIKTOK_CLIENT_SECRET_'.$suffix),
            'redirect_uri' => app_env('TIKTOK_REDIRECT_URI_'.$suffix),
        ];
        foreach ($config as $key => $value) {
            if ($value === '') {
                throw new RuntimeException('Falta configurar TIKTOK_'.strtoupper($key).'_'.$suffix.'.');
            }
        }
        return $config;
    }

    private function scopes(): array
    {
        $configured = app_env('TIKTOK_SCOPES', 'user.info.basic,video.publish');
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $configured)))));
    }

    private function purpose(string $purpose): string
    {
        if (!in_array($purpose, ['artist', 'platform'], true)) {
            throw new InvalidArgumentException('Invalid TikTok connection purpose.');
        }
        return $purpose;
    }

    private function assertPurposeAllowed(int $userId, string $purpose): void
    {
        if ($this->purpose($purpose) !== 'platform') {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT is_admin FROM users WHERE id=?');
        $stmt->execute([$userId]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('La identidad de plataforma está disponible solo para administradores.');
        }
    }

    private function encrypt(string $plain): string
    {
        $key = $this->key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1.'.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $key));
    }

    private function decrypt(string $encoded): string
    {
        if (!str_starts_with($encoded, 'v1.')) {
            throw new RuntimeException('El token cifrado de TikTok no es válido.');
        }
        $raw = base64_decode(substr($encoded, 3), true);
        if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('El token cifrado de TikTok está dañado.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());
        if (!is_string($plain)) {
            throw new RuntimeException('No se pudo descifrar el token de TikTok.');
        }
        return $plain;
    }

    private function key(): string
    {
        $key = base64_decode(app_env('TIKTOK_TOKEN_KEY'), true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Falta configurar TIKTOK_TOKEN_KEY con 32 bytes en Base64.');
        }
        return $key;
    }
}
