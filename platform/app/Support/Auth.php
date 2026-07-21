<?php
declare(strict_types=1);

class Auth
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            require_once __DIR__ . '/DatabaseSessionHandler.php';
            $pdo = Database::connection();
            session_set_save_handler(new DatabaseSessionHandler($pdo), true);

            $production = strtolower(app_env('APP_ENV', '')) === 'production';
            $secureCookie = $production
                || str_starts_with(strtolower(app_env('APP_PUBLIC_URL', '')), 'https://')
                || strtolower((string)($_SERVER['HTTPS'] ?? '')) === 'on'
                || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            session_set_cookie_params([
                'path' => '/',
                'httponly' => true,
                'secure' => $secureCookie,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function csrfToken(string $scope = 'default'): string
    {
        self::start();
        $scope = preg_replace('/[^a-z0-9_.-]/i', '', $scope) ?: 'default';
        if (empty($_SESSION['csrf_tokens'][$scope]) || !is_string($_SESSION['csrf_tokens'][$scope])) {
            $_SESSION['csrf_tokens'][$scope] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_tokens'][$scope];
    }

    public static function validateCsrf(string $token, string $scope = 'default'): bool
    {
        self::start();
        $scope = preg_replace('/[^a-z0-9_.-]/i', '', $scope) ?: 'default';
        $expected = (string)($_SESSION['csrf_tokens'][$scope] ?? '');
        return $expected !== '' && $token !== '' && hash_equals($expected, $token);
    }

    public static function requireValidCsrf(string $token, string $scope = 'default'): void
    {
        if (!self::validateCsrf($token, $scope)) {
            throw new RuntimeException('Your form session expired. Reload the page and try again.');
        }
    }

    public static function requestCsrfToken(): string
    {
        $header = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if ($header !== '') return $header;
        return trim((string)($_POST['csrf'] ?? ''));
    }

    public static function user(): ?array
    {
        self::start();

        $id = (int)($_SESSION['user_id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        $stmt = Database::connection()->prepare("SELECT id, email, name, credits, plan_code, is_admin, status, session_version, created_at FROM users WHERE id = :id AND status = 'active'");
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        if (!is_array($user) || (int)($user['session_version'] ?? 1) !== (int)($_SESSION['session_version'] ?? 0)) {
            unset($_SESSION['user_id'], $_SESSION['session_version']);
            return null;
        }

        return $user;
    }

    public static function requireUser(): array
    {
        $user = self::user();

        if (!$user) {
            header('Location: login.php');
            exit;
        }

        return $user;
    }

    public static function isAdmin(?array $user = null): bool
    {
        $user = $user ?: self::user();

        if (!$user) {
            return false;
        }

        $emails = array_filter(array_map(
            fn(string $email): string => strtolower(trim($email)),
            explode(',', defined('ADMIN_EMAILS') ? ADMIN_EMAILS : '')
        ));

        return (int)($user['is_admin'] ?? 0) === 1 ||
            in_array(strtolower((string)($user['email'] ?? '')), $emails, true);
    }

    public static function login(string $email, string $password): bool
    {
        self::start();

        $email = strtolower(trim($email));
        if (!AuthRateLimiter::consume('login', $email, 8, 900)) {
            password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            return false;
        }

        $stmt = Database::connection()->prepare("SELECT * FROM users WHERE email = :email AND status = 'active'");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!is_array($user) || !password_verify($password, (string)$user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['session_version'] = (int)($user['session_version'] ?? 1);
        AuthRateLimiter::clear('login', $email);

        return true;
    }

    public static function register(string $email, string $password, string $name = ''): array
    {
        self::start();

        if (strtolower(app_env('APP_ENV', '')) === 'production'
            && strtolower(app_env('PUBLIC_REGISTRATION_ENABLED', 'false')) !== 'true') {
            throw new RuntimeException('Registration is temporarily unavailable. Contact the studio for access.');
        }

        $email = strtolower(trim($email));
        $name = trim($name);

        if (!AuthRateLimiter::consume('register', $email, 3, 3600)) {
            throw new RuntimeException('Too many registration attempts. Try again later.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ingresa un email valido.');
        }

        if (strlen($password) < 11) {
            throw new RuntimeException('La contrasena debe tener al menos 11 caracteres.');
        }

        $now = date('c');
        $stmt = Database::connection()->prepare("
            INSERT INTO users (email, password_hash, name, credits, created_at, updated_at)
            VALUES (:email, :password_hash, :name, 10, :created_at, :updated_at)
        ");

        try {
            $stmt->execute([
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Ese email ya esta registrado.');
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)Database::connection()->lastInsertId();
        $_SESSION['session_version'] = 1;

        return self::requireUser();
    }

    public static function requestPasswordReset(string $email): array
    {
        $email = strtolower(trim($email));
        if (!AuthRateLimiter::consume('password_reset_request', $email, 4, 3600)) {
            return ['ok' => true, 'sent' => false, 'debug_link' => ''];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => true, 'sent' => false, 'debug_link' => ''];
        }

        $stmt = Database::connection()->prepare("SELECT id, email, name FROM users WHERE email = :email AND status = 'active' LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!is_array($user)) {
            return ['ok' => true, 'sent' => false, 'debug_link' => ''];
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $now = date('c');
        $expiresAt = date('c', time() + 3600);

        Database::withBusyRetry(function () use ($user, $email, $tokenHash, $now, $expiresAt): void {
            $pdo = Database::connection();
            $pdo->prepare('UPDATE password_resets SET used_at = :used_at WHERE user_id = :user_id AND used_at IS NULL')
                ->execute([
                    'used_at' => $now,
                    'user_id' => (int)$user['id'],
                ]);

            $pdo->prepare('
                INSERT INTO password_resets (user_id, email, token_hash, expires_at, used_at, created_at)
                VALUES (:user_id, :email, :token_hash, :expires_at, NULL, :created_at)
            ')->execute([
                'user_id' => (int)$user['id'],
                'email' => $email,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'created_at' => $now,
            ]);
        });

        $link = self::passwordResetUrl($token);
        $sent = self::sendPasswordResetEmail($email, (string)($user['name'] ?? ''), $link);
        error_log('Password reset requested; delivery=' . ($sent ? 'sent' : 'failed'));

        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $debugEnabled = strtolower(app_env('APP_ENV', '')) !== 'production'
            && (strtolower(app_env('PASSWORD_RESET_DEBUG_LINK', 'false')) === 'true'
            || str_starts_with($host, 'localhost')
            || str_starts_with($host, '127.0.0.1'));

        return [
            'ok' => true,
            'sent' => $sent,
            'debug_link' => $debugEnabled ? $link : '',
        ];
    }

    public static function resetPassword(string $token, string $password): void
    {
        $token = trim($token);
        if (!AuthRateLimiter::consume('password_reset_submit', hash('sha256', $token), 6, 3600)) {
            throw new RuntimeException('This reset link is invalid or expired.');
        }
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
            throw new RuntimeException('This reset link is invalid or expired.');
        }

        if (strlen($password) < 11) {
            throw new RuntimeException('Password must be at least 11 characters.');
        }

        $tokenHash = hash('sha256', $token);
        $stmt = Database::connection()->prepare("
            SELECT pr.*, u.id AS resolved_user_id
            FROM password_resets pr
            INNER JOIN users u ON u.id = pr.user_id
            WHERE pr.token_hash = :token_hash AND pr.used_at IS NULL AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();

        if (!is_array($row) || strtotime((string)$row['expires_at']) < time()) {
            throw new RuntimeException('This reset link is invalid or expired.');
        }

        $now = date('c');
        Database::withBusyRetry(function () use ($row, $password, $now): void {
            $pdo = Database::connection();
            $pdo->prepare('UPDATE users SET password_hash = :password_hash, session_version = session_version + 1, updated_at = :updated_at WHERE id = :id')
                ->execute([
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'updated_at' => $now,
                    'id' => (int)$row['user_id'],
                ]);

            $pdo->prepare('UPDATE password_resets SET used_at = :used_at WHERE user_id = :user_id AND used_at IS NULL')
                ->execute([
                    'used_at' => $now,
                    'user_id' => (int)$row['user_id'],
                ]);
        });
    }

    public static function changePassword(string $currentPassword, string $newPassword): void
    {
        self::start();

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('You must be logged in to change your password.');
        }

        if (strlen($newPassword) < 11) {
            throw new RuntimeException('New password must be at least 11 characters.');
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id AND status = 'active' LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $passwordHash = $stmt->fetchColumn();

        if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
            throw new RuntimeException('Current password is incorrect.');
        }

        $now = date('c');
        Database::withBusyRetry(function () use ($pdo, $userId, $newPassword, $now): void {
            $pdo->prepare('UPDATE users SET password_hash = :password_hash, session_version = session_version + 1, updated_at = :updated_at WHERE id = :id')
                ->execute([
                    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'updated_at' => $now,
                    'id' => $userId,
                ]);

            $pdo->prepare('UPDATE password_resets SET used_at = :used_at WHERE user_id = :user_id AND used_at IS NULL')
                ->execute([
                    'used_at' => $now,
                    'user_id' => $userId,
                ]);
        });

        session_regenerate_id(true);
        $_SESSION['session_version'] = (int)($_SESSION['session_version'] ?? 1) + 1;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();
    }

    private static function passwordResetUrl(string $token): string
    {
        $baseUrl = rtrim(app_env('APP_URL', app_env('APP_PUBLIC_URL', '')), '/');
        if (strtolower(app_env('APP_ENV', '')) === 'production') {
            if (!str_starts_with(strtolower($baseUrl), 'https://')) {
                throw new RuntimeException('APP_URL must be configured with the production HTTPS origin.');
            }
            return $baseUrl . '/reset_password.php?token=' . rawurlencode($token);
        }
        if ($baseUrl === '') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            $scheme = $https ? 'https' : 'http';
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
            $baseUrl = $scheme . '://' . $host;
        }

        return $baseUrl . '/reset_password.php?token=' . rawurlencode($token);
    }

    private static function sendPasswordResetEmail(string $email, string $name, string $link): bool
    {
        $from = app_env('MAIL_FROM', 'no-reply@artworkmockups.local');
        $subject = 'Reset your Artwork Mockups password';
        $displayName = trim($name) !== '' ? trim($name) : 'there';
        $body = "Hi {$displayName},\n\n"
            . "Use this link to reset your Artwork Mockups password:\n\n"
            . $link . "\n\n"
            . "This link expires in 1 hour. If you did not request this, you can ignore this email.\n";

        $headers = [
            'From: Artwork Mockups <' . $from . '>',
            'Reply-To: ' . $from,
            'Content-Type: text/plain; charset=UTF-8',
        ];

        return @mail($email, $subject, $body, implode("\r\n", $headers));
    }

}
