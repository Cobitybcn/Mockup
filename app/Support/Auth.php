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

            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function user(): ?array
    {
        self::start();

        $id = (int)($_SESSION['user_id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        $stmt = Database::connection()->prepare('SELECT id, email, name, credits, is_admin, created_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
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

        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => strtolower(trim($email))]);
        $user = $stmt->fetch();

        if (!is_array($user) || !password_verify($password, (string)$user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];

        return true;
    }

    public static function register(string $email, string $password, string $name = ''): array
    {
        self::start();

        $email = strtolower(trim($email));
        $name = trim($name);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ingresa un email valido.');
        }

        if (strlen($password) < 8) {
            throw new RuntimeException('La contrasena debe tener al menos 8 caracteres.');
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

        $_SESSION['user_id'] = (int)Database::connection()->lastInsertId();

        return self::requireUser();
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
}
