<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/ArtistContactProtection.php';
require_once dirname(__DIR__) . '/inc/AppDatabase.php';

function assert_contact_protection(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

putenv('K_SERVICE');
putenv('APP_ENV');
putenv('TURNSTILE_SITE_KEY');
putenv('TURNSTILE_SECRET_KEY');
$local = new ArtistContactProtection();
assert_contact_protection(
    $local->verify('', '192.0.2.10', 'localhost', '') === true,
    'local development remains usable without Turnstile credentials'
);

putenv('APP_ENV=production');
$production = new ArtistContactProtection();
assert_contact_protection(
    $production->verify('', '192.0.2.10', 'mauriziovalch.com', '') === false,
    'production fails closed when Turnstile credentials are absent'
);

putenv('TURNSTILE_SITE_KEY=site-key-for-test');
putenv('TURNSTILE_SECRET_KEY=secret-key-for-test');
$capturedPayload = [];
$verified = new ArtistContactProtection(static function (array $payload) use (&$capturedPayload): array {
    $capturedPayload = $payload;
    return [
        'success' => true,
        'action' => 'artist_contact',
        'hostname' => 'mauriziovalch.com',
        'cdata' => 'session-binding',
    ];
});
assert_contact_protection(
    $verified->verify('valid-token', '192.0.2.10', 'mauriziovalch.com:443', 'session-binding'),
    'a server-verified token bound to the contact action, host and session is accepted'
);
assert_contact_protection(
    $capturedPayload === [
        'secret' => 'secret-key-for-test',
        'response' => 'valid-token',
        'remoteip' => '192.0.2.10',
    ],
    'the secret, token and validated client address are sent to Siteverify'
);

foreach ([
    ['action' => 'other_action', 'hostname' => 'mauriziovalch.com', 'cdata' => 'session-binding'],
    ['action' => 'artist_contact', 'hostname' => 'attacker.example', 'cdata' => 'session-binding'],
    ['action' => 'artist_contact', 'hostname' => 'mauriziovalch.com', 'cdata' => 'other-session'],
] as $invalidResponse) {
    $rejected = new ArtistContactProtection(static fn(array $payload): array => ['success' => true] + $invalidResponse);
    assert_contact_protection(
        !$rejected->verify('valid-token', '192.0.2.10', 'mauriziovalch.com', 'session-binding'),
        'tokens issued for another action, host or browser session are rejected'
    );
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE auth_rate_limits (
    action TEXT NOT NULL,
    identity_hash TEXT NOT NULL,
    window_started_at INTEGER NOT NULL,
    attempts INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    PRIMARY KEY (action, identity_hash)
)');
assert_contact_protection(artist_site_rate_limit_key($pdo, 'artist_contact_session', 'session-a', 3, 900), 'session attempt one is allowed');
assert_contact_protection(artist_site_rate_limit_key($pdo, 'artist_contact_session', 'session-a', 3, 900), 'session attempt two is allowed');
assert_contact_protection(artist_site_rate_limit_key($pdo, 'artist_contact_session', 'session-a', 3, 900), 'session attempt three is allowed');
assert_contact_protection(!artist_site_rate_limit_key($pdo, 'artist_contact_session', 'session-a', 3, 900), 'session attempt four is blocked');
assert_contact_protection(artist_site_rate_limit_key($pdo, 'artist_contact_session', 'session-b', 3, 900), 'another session has an independent allowance');

putenv('K_SERVICE=mockups-artist-site');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99, 198.51.100.24, 35.191.0.1';
$_SERVER['REMOTE_ADDR'] = '169.254.1.1';
assert_contact_protection(
    artist_site_client_address() === '198.51.100.24',
    'Cloud Run rate limits use the client address from the trusted end of the forwarded chain'
);
putenv('K_SERVICE');
unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);

$root = dirname(__DIR__);
$index = (string)file_get_contents($root . '/index.php');
$cloudBuild = (string)file_get_contents($root . '/cloudbuild.hardening.yaml');
foreach ([
    [str_contains($index, "name=\"csrf\""), 'the contact form carries a per-session CSRF token'],
    [str_contains($index, "name=\"website\""), 'the existing honeypot remains active'],
    [str_contains($index, "data-action=\"artist_contact\""), 'the widget requests a contact-scoped Turnstile token'],
    [str_contains($index, "\$_POST['cf-turnstile-response']"), 'the server validates the Turnstile response'],
    [str_contains($index, "'artist_contact_ip'"), 'contact attempts are limited independently by IP'],
    [str_contains($index, "'artist_contact_email'"), 'verified attempts are limited independently by email'],
    [str_contains($index, "'artist_contact_session'"), 'verified attempts are limited independently by browser session'],
    [str_contains($cloudBuild, 'TURNSTILE_SITE_KEY=${_TURNSTILE_SITE_KEY_SECRET}:latest'), 'the production site key comes from Secret Manager'],
    [str_contains($cloudBuild, 'TURNSTILE_SECRET_KEY=${_TURNSTILE_SECRET_KEY_SECRET}:latest'), 'the production secret key comes from Secret Manager'],
] as [$passed, $description]) {
    assert_contact_protection($passed, $description);
}

putenv('APP_ENV');
putenv('TURNSTILE_SITE_KEY');
putenv('TURNSTILE_SECRET_KEY');
echo "PASS: artist contact anti-spam protection\n";
