<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/AppDatabase.php';

function assert_contact_protection(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
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
    [str_contains($index, 'name="csrf"'), 'the contact form carries a per-session CSRF token'],
    [str_contains($index, 'name="website"'), 'the honeypot remains active'],
    [str_contains($index, "contact_form_started'] ?? time()) < 3"), 'submissions made too quickly are rejected'],
    [str_contains($index, "'artist_contact_global'"), 'contact attempts have a global safety ceiling'],
    [str_contains($index, "'artist_contact_ip'"), 'contact attempts are limited independently by IP'],
    [str_contains($index, "'artist_contact_email'"), 'contact attempts are limited independently by email'],
    [str_contains($index, "'artist_contact_session'"), 'contact attempts are limited independently by browser session'],
    [!str_contains($index, 'turnstile') && !str_contains($index, 'cf-turnstile-response'), 'the form has no external Turnstile dependency'],
    [str_contains($cloudBuild, '--remove-secrets="TURNSTILE_SITE_KEY,TURNSTILE_SECRET_KEY"'), 'the deployment clears inherited Turnstile secret references'],
    [!str_contains($cloudBuild, '_TURNSTILE_') && !str_contains($cloudBuild, 'TURNSTILE_SITE_KEY=${'), 'the production deployment requires no Turnstile secret values'],
] as [$passed, $description]) {
    assert_contact_protection($passed, $description);
}

echo "PASS: artist contact anti-spam protection without external captcha\n";
