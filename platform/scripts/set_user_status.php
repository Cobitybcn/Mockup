<?php
declare(strict_types=1);

/**
 * Reversibly enable or disable a non-admin account.
 *
 * Disabling requires a completed archive manifest for the same user. The
 * command never deletes database rows or files.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run from the CLI.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/app/Support/Database.php';

$options = getopt('', ['email:', 'status:', 'archive-manifest:', 'execute', 'help']);
if (isset($options['help'])) {
    printUsage();
    exit(0);
}

$email = strtolower(trim((string)($options['email'] ?? '')));
$status = strtolower(trim((string)($options['status'] ?? '')));
$manifestPath = trim((string)($options['archive-manifest'] ?? ''));
$execute = array_key_exists('execute', $options);

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Provide a valid --email address.');
}
if (!in_array($status, ['active', 'disabled'], true)) {
    fail('--status must be active or disabled.');
}
if ($status === 'disabled' && $manifestPath === '') {
    fail('--archive-manifest is required when disabling an account.');
}

try {
    // Database::connection() applies the additive users.status migration.
    $pdo = Database::connection();
    $user = loadUser($pdo, $email, false);
    if ((int)$user['is_admin'] === 1 && $status === 'disabled') {
        throw new RuntimeException('Administrator accounts cannot be disabled with this command.');
    }
    if ($status === 'disabled') {
        validateArchiveManifest($manifestPath, $user);
    }

    $currentStatus = strtolower((string)($user['status'] ?? 'active'));
    fwrite(STDOUT, sprintf(
        "User #%d <%s>: %s -> %s\n",
        (int)$user['id'],
        (string)$user['email'],
        $currentStatus,
        $status
    ));

    if (!$execute) {
        fwrite(STDOUT, "Dry run only: the account was not changed. Add --execute to apply.\n");
        exit(0);
    }
    if ($currentStatus === $status) {
        fwrite(STDOUT, "No change was necessary.\n");
        exit(0);
    }

    Database::beginWriteTransaction($pdo);
    try {
        $lockedUser = loadUser($pdo, $email, Database::isMysql());
        if ((int)$lockedUser['id'] !== (int)$user['id']) {
            throw new RuntimeException('The target account changed during the operation.');
        }
        if ((int)$lockedUser['is_admin'] === 1 && $status === 'disabled') {
            throw new RuntimeException('Administrator accounts cannot be disabled with this command.');
        }

        $now = date(DATE_ATOM);
        $stmt = $pdo->prepare('UPDATE users SET status=:status, disabled_at=:disabled_at, updated_at=:updated_at WHERE id=:id');
        $stmt->execute([
            'status' => $status,
            'disabled_at' => $status === 'disabled' ? $now : null,
            'updated_at' => $now,
            'id' => (int)$user['id'],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    fwrite(STDOUT, $status === 'disabled'
        ? "Account disabled. Existing sessions will be rejected on their next authenticated request. No data was deleted.\n"
        : "Account reactivated. No archived or active data was changed.\n");
} catch (Throwable $e) {
    fail($e->getMessage());
}

function printUsage(): void
{
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php scripts/set_user_status.php --email=person@example.com --status=disabled --archive-manifest=C:\\absolute\\archive\\manifest.json\n");
    fwrite(STDOUT, "  php scripts/set_user_status.php --email=person@example.com --status=disabled --archive-manifest=C:\\absolute\\archive\\manifest.json --execute\n");
    fwrite(STDOUT, "  php scripts/set_user_status.php --email=person@example.com --status=active --execute\n");
}

/** @return array<string,mixed> */
function loadUser(PDO $pdo, string $email, bool $forUpdate): array
{
    $sql = 'SELECT * FROM users WHERE LOWER(email)=? LIMIT 2' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$email]);
    $users = $stmt->fetchAll();
    if (count($users) !== 1) {
        throw new RuntimeException(count($users) === 0 ? "No user found for {$email}." : "More than one user matched {$email}.");
    }
    return $users[0];
}

/** @param array<string,mixed> $user */
function validateArchiveManifest(string $path, array $user): void
{
    if (!is_file($path)) {
        throw new RuntimeException("Archive manifest not found: {$path}");
    }
    $manifest = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['status'] ?? '') !== 'complete') {
        throw new RuntimeException('The archive manifest is not marked complete.');
    }
    if ((int)($manifest['user']['id'] ?? 0) !== (int)$user['id']
        || strtolower((string)($manifest['user']['email'] ?? '')) !== strtolower((string)$user['email'])) {
        throw new RuntimeException('The archive manifest belongs to a different user.');
    }
    if (($manifest['safety']['source_files_deleted'] ?? null) !== false
        || ($manifest['safety']['database_rows_changed'] ?? null) !== false) {
        throw new RuntimeException('The archive manifest does not confirm a non-destructive source state.');
    }
    $archiveRoot = dirname($path);
    foreach (['database-export.sql', 'checksums.sha256'] as $requiredFile) {
        if (!is_file($archiveRoot . DIRECTORY_SEPARATOR . $requiredFile)) {
            throw new RuntimeException("The archive is incomplete: {$requiredFile} is missing.");
        }
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "Account status change failed: {$message}\n");
    exit(1);
}
