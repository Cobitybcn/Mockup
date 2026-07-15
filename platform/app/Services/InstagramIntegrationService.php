<?php
declare(strict_types=1);

/**
 * Direct Instagram Login connection for professional Creator/Business accounts.
 * It deliberately does not share credentials with the Facebook Page connection.
 */
final class InstagramIntegrationService
{
    private const AUTHORIZE_URL = 'https://www.instagram.com/oauth/authorize';
    private const TOKEN_URL = 'https://api.instagram.com/oauth/access_token';

    public function __construct(private PDO $pdo) {}

    public function authorizationUrl(int $userId, string $purpose = 'artist'): string
    {
        Auth::start();
        if (!$this->oauthEnabled()) {
            throw new RuntimeException('Instagram OAuth is disabled until its exact public HTTPS callback is registered.');
        }
        $purpose = $this->purpose($purpose);
        $this->assertPurposeAllowed($userId, $purpose);
        $config = $this->config($purpose);
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $_SESSION['instagram_oauth_'.$purpose] = [
            'hash' => hash('sha256', $state),
            'user_id' => $userId,
            'expires_at' => time() + 600,
        ];

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $config['app_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(',', $this->scopes()),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completeAuthorization(int $userId, string $purpose, string $code, string $state): void
    {
        Auth::start();
        $purpose = $this->purpose($purpose);
        $pending = $_SESSION['instagram_oauth_'.$purpose] ?? null;
        unset($_SESSION['instagram_oauth_'.$purpose]);

        if (!is_array($pending)
            || (int)($pending['user_id'] ?? 0) !== $userId
            || (int)($pending['expires_at'] ?? 0) < time()
            || $state === ''
            || !hash_equals((string)($pending['hash'] ?? ''), hash('sha256', $state))) {
            throw new RuntimeException('La autorización de Instagram expiró o no es válida. Conecta nuevamente.');
        }
        if ($code === '') {
            throw new RuntimeException('Instagram no devolvió un código de autorización.');
        }

        $config = $this->config($purpose);
        $short = $this->requestJson('POST', self::TOKEN_URL, [
            'client_id' => $config['app_id'],
            'client_secret' => $config['app_secret'],
            'grant_type' => 'authorization_code',
            'redirect_uri' => $config['redirect_uri'],
            'code' => $code,
        ]);
        $shortToken = trim((string)($short['access_token'] ?? ''));
        if ($shortToken === '') {
            throw new RuntimeException('Instagram no devolvió un access token.');
        }

        $long = $this->requestJson('GET', $this->graphBase().'/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $config['app_secret'],
            'access_token' => $shortToken,
        ]);
        $token = trim((string)($long['access_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Instagram no devolvió el token de larga duración.');
        }

        $profile = $this->requestJson('GET', $this->graphBase().'/'.$this->version().'/me', [
            'fields' => 'user_id,username',
            'access_token' => $token,
        ]);
        $instagramUserId = trim((string)($profile['user_id'] ?? $profile['id'] ?? $short['user_id'] ?? ''));
        $username = trim((string)($profile['username'] ?? ''));
        if ($instagramUserId === '' || $username === '') {
            throw new RuntimeException('Instagram autorizó la cuenta, pero no devolvió su identidad profesional.');
        }

        $this->storeConnection(
            $userId,
            $purpose,
            $instagramUserId,
            $username,
            'PROFESSIONAL',
            $token,
            (int)($long['expires_in'] ?? 5184000)
        );
    }

    public function connection(int $userId, string $purpose = 'artist'): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id,user_id,purpose,instagram_user_id,username,account_type,token_expires_at,scopes,status,connected_at FROM instagram_connections WHERE user_id=? AND purpose=? LIMIT 1');
        $stmt->execute([$userId, $this->purpose($purpose)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array{instagram_user_id:string,username:string,access_token:string,graph_version:string} */
    public function publishingContext(int $userId, string $purpose = 'artist'): array
    {
        $purpose = $this->purpose($purpose);
        $connection = $this->connection($userId, $purpose);
        if (!is_array($connection) || ($connection['status'] ?? '') !== 'connected') {
            throw new RuntimeException('Conecta la cuenta profesional de Instagram antes de publicar.');
        }
        if (strtotime((string)($connection['token_expires_at'] ?? '')) <= time() + 300) {
            throw new RuntimeException('La conexión de Instagram expiró. Conecta nuevamente.');
        }
        $granted = array_values(array_filter(array_map('trim', explode(',', (string)($connection['scopes'] ?? '')))));
        $missing = array_values(array_diff($this->scopes(), $granted));
        if ($missing) {
            throw new RuntimeException('Instagram no concedió los permisos requeridos: '.implode(', ', $missing).'.');
        }
        $stmt = $this->pdo->prepare('SELECT access_token_encrypted FROM instagram_connections WHERE user_id=? AND purpose=? AND status=? LIMIT 1');
        $stmt->execute([$userId, $purpose, 'connected']);
        $encrypted = (string)($stmt->fetchColumn() ?: '');
        if ($encrypted === '') {
            throw new RuntimeException('La cuenta de Instagram no tiene un token disponible. Conecta nuevamente.');
        }
        return [
            'instagram_user_id' => (string)$connection['instagram_user_id'],
            'username' => (string)$connection['username'],
            'access_token' => $this->decrypt($encrypted),
            'graph_version' => $this->version(),
        ];
    }

    public function disconnect(int $userId, string $purpose = 'artist'): void
    {
        $purpose = $this->purpose($purpose);
        $this->assertPurposeAllowed($userId, $purpose);
        $now = date('c');
        $this->pdo->prepare("UPDATE instagram_connections SET access_token_encrypted=NULL,status='disconnected',disconnected_at=?,updated_at=? WHERE user_id=? AND purpose=?")
            ->execute([$now, $now, $userId, $purpose]);
    }

    public function handleDeauthorization(string $signedRequest, string $purpose = 'artist'): void
    {
        $payload = $this->verifiedSignedRequest($signedRequest, $purpose);
        $this->forgetInstagramIdentity((string)$payload['user_id']);
    }

    /** @return array{url:string,confirmation_code:string} */
    public function handleDataDeletion(string $signedRequest, string $purpose = 'artist'): array
    {
        $payload = $this->verifiedSignedRequest($signedRequest, $purpose);
        $this->forgetInstagramIdentity((string)$payload['user_id']);
        $confirmationCode = 'IG'.strtoupper(bin2hex(random_bytes(8)));
        return [
            'url' => PublicPage::url('integrations/instagram/data-deletion/?code='.rawurlencode($confirmationCode)),
            'confirmation_code' => $confirmationCode,
        ];
    }

    public function oauthEnabled(): bool
    {
        return strtolower(app_env('INSTAGRAM_OAUTH_ENABLED', 'false')) === 'true';
    }

    private function storeConnection(int $userId, string $purpose, string $instagramUserId, string $username, string $accountType, string $token, int $expiresIn): void
    {
        $now = date('c');
        $expiresAt = date('c', time() + max(60, $expiresIn));
        $scopeValue = implode(',', $this->scopes());
        if ($this->connection($userId, $purpose)) {
            $this->pdo->prepare('UPDATE instagram_connections SET instagram_user_id=?,username=?,account_type=?,access_token_encrypted=?,token_expires_at=?,scopes=?,status=?,connected_at=?,disconnected_at=NULL,updated_at=? WHERE user_id=? AND purpose=?')
                ->execute([$instagramUserId, $username, $accountType, $this->encrypt($token), $expiresAt, $scopeValue, 'connected', $now, $now, $userId, $purpose]);
            return;
        }
        $this->pdo->prepare('INSERT INTO instagram_connections (user_id,purpose,instagram_user_id,username,account_type,access_token_encrypted,token_expires_at,scopes,status,connected_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$userId, $purpose, $instagramUserId, $username, $accountType, $this->encrypt($token), $expiresAt, $scopeValue, 'connected', $now, $now, $now]);
    }

    private function verifiedSignedRequest(string $signedRequest, string $purpose): array
    {
        $parts = explode('.', trim($signedRequest), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new RuntimeException('Instagram did not provide a valid signed request.');
        }
        [$encodedSignature, $encodedPayload] = $parts;
        $signature = $this->base64UrlDecode($encodedSignature);
        $payloadJson = $this->base64UrlDecode($encodedPayload);
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || strtoupper((string)($payload['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            throw new RuntimeException('Instagram signed request algorithm is not valid.');
        }
        $expected = hash_hmac('sha256', $encodedPayload, $this->config($purpose)['app_secret'], true);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Instagram signed request signature is not valid.');
        }
        if (trim((string)($payload['user_id'] ?? '')) === '') {
            throw new RuntimeException('Instagram signed request does not identify an account.');
        }
        return $payload;
    }

    private function forgetInstagramIdentity(string $instagramUserId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM instagram_connections WHERE instagram_user_id=?')->execute([$instagramUserId]);
            $this->pdo->prepare("UPDATE meta_connections SET instagram_account_id='',instagram_username='',updated_at=? WHERE instagram_account_id=?")
                ->execute([date('c'), $instagramUserId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function base64UrlDecode(string $value): string
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding !== 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($normalized, true);
        if (!is_string($decoded)) {
            throw new RuntimeException('Instagram signed request encoding is not valid.');
        }
        return $decoded;
    }

    private function requestJson(string $method, string $url, array $parameters): array
    {
        $method = strtoupper($method);
        $curl = curl_init();
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        if ($method === 'POST') {
            $options[CURLOPT_URL] = $url;
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $options[CURLOPT_URL] = $url.'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $data = is_string($body) ? json_decode($body, true) : null;
        if ($error !== '' || $status < 200 || $status >= 300 || !is_array($data)) {
            $message = is_array($data) ? trim((string)($data['error']['message'] ?? $data['error_message'] ?? '')) : '';
            throw new RuntimeException('Instagram API respondió con un error (HTTP '.$status.').'.($message !== '' ? ' '.mb_substr($message, 0, 240) : ''));
        }
        return $data;
    }

    private function config(string $purpose): array
    {
        $suffix = strtoupper($this->purpose($purpose));
        $config = [
            'app_id' => app_env('INSTAGRAM_APP_ID_'.$suffix),
            'app_secret' => app_env('INSTAGRAM_APP_SECRET_'.$suffix),
            'redirect_uri' => app_env('INSTAGRAM_REDIRECT_URI_'.$suffix),
        ];
        foreach ($config as $key => $value) {
            if ($value === '') {
                throw new RuntimeException('Falta configurar INSTAGRAM_'.strtoupper($key).'_'.$suffix.'.');
            }
        }
        return $config;
    }

    private function scopes(): array
    {
        $configured = app_env('INSTAGRAM_SCOPES', 'instagram_business_basic,instagram_business_content_publish');
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $configured)))));
    }

    private function graphBase(): string
    {
        return 'https://graph.instagram.com';
    }

    private function version(): string
    {
        return preg_replace('/[^v0-9.]/', '', app_env('INSTAGRAM_GRAPH_VERSION', 'v25.0')) ?: 'v25.0';
    }

    private function purpose(string $purpose): string
    {
        if (!in_array($purpose, ['artist', 'platform'], true)) {
            throw new InvalidArgumentException('Invalid Instagram connection purpose.');
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
            throw new RuntimeException('El token cifrado de Instagram no es válido.');
        }
        $raw = base64_decode(substr($encoded, 3), true);
        if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('El token cifrado de Instagram está dañado.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());
        if (!is_string($plain)) {
            throw new RuntimeException('No se pudo descifrar el token de Instagram.');
        }
        return $plain;
    }

    private function key(): string
    {
        $key = base64_decode(app_env('INSTAGRAM_TOKEN_KEY'), true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Falta configurar INSTAGRAM_TOKEN_KEY con 32 bytes en Base64.');
        }
        return $key;
    }
}
