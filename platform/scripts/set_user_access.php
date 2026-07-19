<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run from the CLI.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/app/Support/Database.php';
require_once dirname(__DIR__) . '/app/Support/FeatureAccess.php';

$options = getopt('', ['email:', 'plan:', 'execute', 'actor-context:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php scripts/set_user_access.php --email=person@example.com --plan=artist_pro [--execute] [--actor-context=deployment]\n");
    exit(0);
}

$email = strtolower(trim((string)($options['email'] ?? '')));
$plan = trim((string)($options['plan'] ?? ''));
$execute = isset($options['execute']);
$actorContext = trim((string)($options['actor-context'] ?? 'deployment_cli'));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Provide a valid --email.\n");
    exit(1);
}
if (!array_key_exists($plan, FeatureAccess::plans())) {
    fwrite(STDERR, '--plan must be one of: ' . implode(', ', array_keys(FeatureAccess::plans())) . PHP_EOL);
    exit(1);
}

try {
    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT id, email, plan_code, is_admin FROM users WHERE LOWER(email)=:email LIMIT 2');
    $stmt->execute(['email' => $email]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($users) !== 1) {
        throw new RuntimeException(count($users) === 0 ? "No user found for {$email}." : "More than one user matched {$email}.");
    }
    $user = $users[0];
    if ((int)$user['is_admin'] === 1) {
        throw new RuntimeException('Admin accounts already have full access and cannot be assigned an artist plan here.');
    }
    fwrite(STDOUT, sprintf(
        "User #%d <%s>: %s -> %s (%s)\n",
        (int)$user['id'],
        (string)$user['email'],
        (string)$user['plan_code'],
        $plan,
        app_env('APP_ENV', 'unset')
    ));
    if (!$execute) {
        fwrite(STDOUT, "Dry run only. Add --execute to apply.\n");
        exit(0);
    }

    FeatureAccess::updateUserAccess(
        $pdo,
        (int)$user['id'],
        $plan,
        [],
        "Plan assigned by {$actorContext}",
        null,
        $actorContext
    );
    fwrite(STDOUT, "Access updated and recorded in user_access_audit.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Access update failed: {$error->getMessage()}\n");
    exit(1);
}
