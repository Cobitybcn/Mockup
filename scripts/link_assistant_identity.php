<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$emails = array_values(array_unique(array_filter(array_map(
    static fn (string $email): string => strtolower(trim($email)),
    explode(',', (string)($argv[1] ?? ''))
))));
$displayName = trim((string)($argv[2] ?? ''));

if (count($emails) < 2 || $displayName === '') {
    fwrite(STDERR, "Usage: php scripts/link_assistant_identity.php email1,email2 \"Display name\"\n");
    exit(1);
}

$pdo = Database::connection();
$placeholders = implode(',', array_fill(0, count($emails), '?'));
$snapshot = static function () use ($pdo, $placeholders, $emails): array {
    $statement = $pdo->prepare("SELECT id,email,name,is_admin FROM users WHERE LOWER(email) IN ($placeholders) ORDER BY id");
    $statement->execute($emails);
    return $statement->fetchAll();
};

$before = $snapshot();
if (count($before) !== count($emails)) {
    fwrite(STDERR, "Every email must belong to an existing user.\n");
    exit(1);
}

$identityId = (new AssistantRepository($pdo))->mergeUsersByEmails($emails, $displayName);
$after = $snapshot();
if ($before !== $after) {
    throw new RuntimeException('Application user records changed while linking the assistant identity.');
}

$members = $pdo->prepare('SELECT m.identity_id,u.id,u.email,u.is_admin FROM assistant_identity_members m JOIN users u ON u.id=m.user_id WHERE m.identity_id=? ORDER BY u.id');
$members->execute([$identityId]);
$linked = $members->fetchAll();
if (count($linked) !== count($emails)) {
    throw new RuntimeException('The assistant identity does not contain every requested account.');
}

echo 'Assistant identity ' . $identityId . " linked without changing application users or roles.\n";
foreach ($linked as $member) {
    echo sprintf("- user_id=%d email=%s role=%s\n", (int)$member['id'], (string)$member['email'], (int)$member['is_admin'] === 1 ? 'admin' : 'user');
}
