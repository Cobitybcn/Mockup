<?php
// reset_admin.php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();
    $email = 'admin@artmock.com';
    $password = 'password123';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $now = date('c');

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user) {
        $stmtUpdate = $pdo->prepare('UPDATE users SET password_hash = :hash, credits = 100, is_admin = 1, updated_at = :now WHERE id = :id');
        $stmtUpdate->execute([
            'hash' => $hash,
            'now' => $now,
            'id' => (int)$user['id']
        ]);
        echo "Administrador actualizado con éxito.\nEmail: {$email}\nNueva Contraseña: {$password}\nCréditos asignados: 100\n";
    } else {
        $stmtInsert = $pdo->prepare('
            INSERT INTO users (email, password_hash, name, credits, is_admin, created_at, updated_at)
            VALUES (:email, :hash, "Admin ArtMock", 100, 1, :now, :now)
        ');
        $stmtInsert->execute([
            'email' => $email,
            'hash' => $hash,
            'now' => $now
        ]);
        echo "Administrador creado con éxito.\nEmail: {$email}\nContraseña: {$password}\nCréditos asignados: 100\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "Error resetting admin: " . $e->getMessage();
}
