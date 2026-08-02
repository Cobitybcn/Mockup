<?php
declare(strict_types=1);

/**
 * The artist's X connection, shaped like the Instagram and Facebook ones so the
 * Connections screen treats all of them the same way.
 *
 * Two things differ, both because X requires them rather than by choice: the
 * authorization carries a PKCE challenge, and the token endpoint is called with
 * HTTP Basic auth. X also issues short-lived access tokens, so `offline.access`
 * and the refresh token are what keep the account connected between sessions.
 */
final class XIntegrationService
{
    private const AUTHORIZE_URL = 'https://x.com/i/oauth2/authorize';
    private const TOKEN_URL = 'https://api.x.com/2/oauth2/token';
    private const ME_URL = 'https://api.x.com/2/users/me';

    public function __construct(private PDO $pdo) {}

    public function authorizationUrl(int $userId, string $purpose = 'artist'): string
    {
        Auth::start();
        if (!$this->oauthEnabled()) {
            throw new RuntimeException('X OAuth is disabled until its exact public HTTPS callback is registered.');
        }
        $purpose = $this->purpose($purpose);
        $this->assertPurposeAllowed($userId, $purpose);
        $config = $this->config($purpose);

        $state = $this->urlSafe(random_bytes(32));
        $verifier = $this->urlSafe(random_bytes(64));
        $_SESSION['x_oauth_'.$purpose] = [
            'hash' => hash('sha256', $state),
            'verifier' => $verifier,
            'user_id' => $userId,
            'expires_at' => time() + 600,
        ];

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            'code_challenge' => $this->urlSafe(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completeAuthorization(int $userId, string $purpose, string $code, string $state): void
    {
        Auth::start();
        $purpose = $this->purpose($purpose);
        $pending = $_SESSION['x_oauth_'.$purpose] ?? null;
        unset($_SESSION['x_oauth_'.$purpose]);

        if (!is_array($pending)
            || (int)($pending['user_id'] ?? 0) !== $userId
            || (int)($pending['expires_at'] ?? 0) < time()
            || $state === ''
            || !hash_equals((string)($pending['hash'] ?? ''), hash('sha256', $state))
        ) {
            throw new RuntimeException('The X authorization could not be verified. Start the connection again.');
        }
        if ($code === '') {
            throw new RuntimeException('X did not return an authorization code.');
        }

        $config = $this->config($purpose);
        $token = $this->requestToken($config, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $config['redirect_uri'],
            'code_verifier' => (string)$pending['verifier'],
        ]);

        $accessToken = trim((string)($token['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new RuntimeException('X did not return an access token.');
        }
        $identity = $this->identity($accessToken);

        $this->storeConnection(
            $userId,
            $purpose,
            (string)($identity['id'] ?? ''),
            (string)($identity['username'] ?? ''),
            (string)($identity['name'] ?? ''),
            $accessToken,
            trim((string)($token['refresh_token'] ?? '')),
            (int)($token['expires_in'] ?? 7200),
            trim((string)($token['scope'] ?? implode(' ', $this->scopes())))
        );
    }

    /** @return array<string,mixed>|null */
    public function connection(int $userId, string $purpose = 'artist'): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id,user_id,purpose,x_user_id,x_username,x_display_name,token_expires_at,scopes,status,connected_at FROM x_connections WHERE user_id=? AND purpose=? LIMIT 1');
        $stmt->execute([$userId, $this->purpose($purpose)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * The token to publish with, refreshed when it is about to lapse so the
     * artist never meets an expired connection mid-send.
     *
     * @return array{x_user_id:string,username:string,access_token:string}
     */
    public function publishingContext(int $userId, string $purpose = 'artist'): array
    {
        $purpose = $this->purpose($purpose);
        $connection = $this->connection($userId, $purpose);
        if (!is_array($connection) || ($connection['status'] ?? '') !== 'connected') {
            throw new RuntimeException('Connect the X account before publishing.');
        }
        $granted = $this->grantedScopes((string)($connection['scopes'] ?? ''));
        $missing = array_values(array_diff(['tweet.write', 'users.read'], $granted));
        if ($missing) {
            throw new RuntimeException('X did not grant the permissions required to publish: '.implode(', ', $missing).'.');
        }

        if (strtotime((string)($connection['token_expires_at'] ?? '')) <= time() + 300) {
            $this->refresh($userId, $purpose);
        }

        $stmt = $this->pdo->prepare('SELECT access_token_encrypted FROM x_connections WHERE user_id=? AND purpose=? AND status=? LIMIT 1');
        $stmt->execute([$userId, $purpose, 'connected']);
        $encrypted = (string)($stmt->fetchColumn() ?: '');
        if ($encrypted === '') {
            throw new RuntimeException('The X account has no token available. Connect it again.');
        }
        return [
            'x_user_id' => (string)$connection['x_user_id'],
            'username' => (string)$connection['x_username'],
            'access_token' => $this->decrypt($encrypted),
        ];
    }

    public function disconnect(int $userId, string $purpose = 'artist'): void
    {
        $purpose = $this->purpose($purpose);
        $now = date('c');
        $this->pdo->prepare("UPDATE x_connections SET access_token_encrypted=NULL,refresh_token_encrypted=NULL,status='disconnected',disconnected_at=?,updated_at=? WHERE user_id=? AND purpose=?")
            ->execute([$now, $now, $userId, $purpose]);
    }

    public function oauthEnabled(): bool
    {
        return strtolower(trim(app_env('X_OAUTH_ENABLED', 'false'))) === 'true';
    }

    /** X's access tokens are short-lived; without this the artist reconnects several times a day. */
    private function refresh(int $userId, string $purpose): void
    {
        $stmt = $this->pdo->prepare('SELECT refresh_token_encrypted FROM x_connections WHERE user_id=? AND purpose=? AND status=? LIMIT 1');
        $stmt->execute([$userId, $purpose, 'connected']);
        $encrypted = (string)($stmt->fetchColumn() ?: '');
        if ($encrypted === '') {
            throw new RuntimeException('The X connection expired and cannot be renewed. Connect it again.');
        }
        $config = $this->config($purpose);
        $token = $this->requestToken($config, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->decrypt($encrypted),
        ]);
        $accessToken = trim((string)($token['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new RuntimeException('X did not renew the access token. Connect the account again.');
        }
        $now = date('c');
        $this->pdo->prepare('UPDATE x_connections SET access_token_encrypted=?,refresh_token_encrypted=?,token_expires_at=?,updated_at=? WHERE user_id=? AND purpose=?')
            ->execute([
                $this->encrypt($accessToken),
                $this->encrypt(trim((string)($token['refresh_token'] ?? '')) ?: $this->decrypt($encrypted)),
                date('c', time() + max(60, (int)($token['expires_in'] ?? 7200))),
                $now,
                $userId,
                $purpose,
            ]);
    }

    /** @param array<string,string> $parameters */
    private function requestToken(array $config, array $parameters): array
    {
        $response = $this->request('POST', self::TOKEN_URL, $parameters, [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic '.base64_encode($config['client_id'].':'.$config['client_secret']),
        ]);
        if (isset($response['error'])) {
            throw new RuntimeException('X rejected the authorization: '.(string)($response['error_description'] ?? $response['error']));
        }
        return $response;
    }

    /** @return array<string,mixed> */
    private function identity(string $accessToken): array
    {
        $response = $this->request('GET', self::ME_URL, [], ['Authorization: Bearer '.$accessToken]);
        $data = (array)($response['data'] ?? []);
        if (trim((string)($data['id'] ?? '')) === '') {
            throw new RuntimeException('X did not return the account identity.');
        }
        return $data;
    }

    /**
     * @param array<string,string> $parameters
     * @param list<string> $headers
     * @return array<string,mixed>
     */
    private function request(string $method, string $url, array $parameters, array $headers): array
    {
        $handle = curl_init();
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_URL] = $url;
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        } else {
            $options[CURLOPT_URL] = $parameters === [] ? $url : $url.'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new RuntimeException('X could not be reached: '.$error);
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('X answered with something that is not JSON.');
        }
        return $decoded;
    }

    private function storeConnection(
        int $userId,
        string $purpose,
        string $xUserId,
        string $username,
        string $displayName,
        string $accessToken,
        string $refreshToken,
        int $expiresIn,
        string $scopes
    ): void {
        $now = date('c');
        $expiresAt = date('c', time() + max(60, $expiresIn));
        $encryptedRefresh = $refreshToken !== '' ? $this->encrypt($refreshToken) : null;

        if ($this->connection($userId, $purpose)) {
            $this->pdo->prepare('UPDATE x_connections SET x_user_id=?,x_username=?,x_display_name=?,access_token_encrypted=?,refresh_token_encrypted=?,token_expires_at=?,scopes=?,status=?,connected_at=?,disconnected_at=NULL,updated_at=? WHERE user_id=? AND purpose=?')
                ->execute([$xUserId, $username, $displayName, $this->encrypt($accessToken), $encryptedRefresh, $expiresAt, $scopes, 'connected', $now, $now, $userId, $purpose]);
            return;
        }
        $this->pdo->prepare('INSERT INTO x_connections (user_id,purpose,x_user_id,x_username,x_display_name,access_token_encrypted,refresh_token_encrypted,token_expires_at,scopes,status,connected_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$userId, $purpose, $xUserId, $username, $displayName, $this->encrypt($accessToken), $encryptedRefresh, $expiresAt, $scopes, 'connected', $now, $now, $now]);
    }

    /** @return list<string> */
    private function grantedScopes(string $stored): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $stored) ?: [])));
    }

    /** @return array{client_id:string,client_secret:string,redirect_uri:string} */
    private function config(string $purpose): array
    {
        $suffix = strtoupper($this->purpose($purpose));
        $config = [
            'client_id' => app_env('X_CLIENT_ID_'.$suffix),
            'client_secret' => app_env('X_CLIENT_SECRET_'.$suffix),
            'redirect_uri' => app_env('X_REDIRECT_URI_'.$suffix),
        ];
        foreach ($config as $key => $value) {
            if ($value === '') {
                throw new RuntimeException('Falta configurar X_'.strtoupper($key).'_'.$suffix.'.');
            }
        }
        return $config;
    }

    /** @return list<string> */
    private function scopes(): array
    {
        $configured = app_env('X_SCOPES', 'tweet.write tweet.read users.read offline.access');
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $configured) ?: []))));
    }

    private function urlSafe(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function purpose(string $purpose): string
    {
        if (!in_array($purpose, ['artist', 'platform'], true)) {
            throw new InvalidArgumentException('Invalid X connection purpose.');
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
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1.'.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    private function decrypt(string $encoded): string
    {
        if (!str_starts_with($encoded, 'v1.')) {
            throw new RuntimeException('El token cifrado de X no es válido.');
        }
        $raw = base64_decode(substr($encoded, 3), true);
        if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('El token cifrado de X está dañado.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key());
        if (!is_string($plain)) {
            throw new RuntimeException('No se pudo descifrar el token de X.');
        }
        return $plain;
    }

    private function key(): string
    {
        $key = base64_decode(app_env('X_TOKEN_KEY'), true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Falta configurar X_TOKEN_KEY con 32 bytes en Base64.');
        }
        return $key;
    }
}
