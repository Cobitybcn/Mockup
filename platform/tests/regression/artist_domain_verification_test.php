<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/artist-site/inc/TenantResolver.php';

function run_artist_domain_verification_tests(): void
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY,email TEXT NOT NULL)");

    $profileFields = ArtistProfile::fields();
    $profileColumns = [];
    foreach ($profileFields as $field) $profileColumns[] = $field . " TEXT NOT NULL DEFAULT ''";
    $pdo->exec('CREATE TABLE artist_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL UNIQUE,' . implode(',', $profileColumns) . ',created_at TEXT NOT NULL,updated_at TEXT NOT NULL)');
    $migration = require dirname(__DIR__, 2) . '/migrations/schema/20260721_000005_verified_artist_domains.php';
    $migration['up']($pdo);
    $pdo->exec("INSERT INTO users (id,email) VALUES (1,'artist@example.com'),(2,'other@example.com')");

    $dnsAnswer = [];
    $service = new ArtistDomainService($pdo, static function (string $record) use (&$dnsAnswer): array {
        return $dnsAnswer[$record] ?? [];
    });

    $pending = $service->saveConfiguration(1, 'sample-artist', 'https://Example-Art.com/');
    TestHarness::assertSame('example-art.com', $pending['custom_domain'], 'custom artist domains are canonicalized before persistence');
    TestHarness::assertSame('pending', $pending['status'], 'a newly registered custom domain remains inactive until DNS ownership is proven');
    TestHarness::assertTrue(str_starts_with($pending['verification_value'], 'artworkmockups-domain-verification='), 'each artist receives a scoped DNS verification value');

    $notVerified = $service->verifyOwnership(1);
    TestHarness::assertTrue(empty($notVerified['verified_now']) && $notVerified['public_host'] === 'sample-artist.artworkmockups.com', 'an unverified domain keeps the safe platform subdomain active');

    $dnsAnswer[$pending['verification_record']] = [['txt' => $pending['verification_value']]];
    $verified = $service->verifyOwnership(1);
    TestHarness::assertTrue(!empty($verified['verified_now']) && $verified['public_host'] === 'example-art.com', 'matching TXT ownership activates the custom host for the artist');

    $duplicateBlocked = false;
    try {
        $service->saveConfiguration(2, 'other-artist', 'example-art.com');
    } catch (RuntimeException) {
        $duplicateBlocked = true;
    }
    TestHarness::assertTrue($duplicateBlocked, 'a verified or pending custom domain cannot be claimed by another artist');

    $platformDomainBlocked = false;
    try {
        ArtistDomainService::normalizeHost('admin.artworkmockups.com');
    } catch (RuntimeException) {
        $platformDomainBlocked = true;
    }
    TestHarness::assertTrue($platformDomainBlocked, 'platform-owned hosts cannot be registered as artist custom domains');

    $oldEnvironment = getenv('APP_ENV');
    $oldHost = $_SERVER['HTTP_HOST'] ?? null;
    $oldTenantEmail = $_GET['tenant_email'] ?? null;
    putenv('APP_ENV=production');
    $_SERVER['HTTP_HOST'] = 'example-art.com';
    $_GET['tenant_email'] = 'other@example.com';
    $resolved = (new TenantResolver($pdo))->resolveEmail();
    TestHarness::assertSame('artist@example.com', $resolved, 'production tenant resolution ignores query overrides and uses the verified host owner');

    $_SERVER['HTTP_HOST'] = 'unknown-example.com';
    $unknownBlocked = false;
    try {
        (new TenantResolver($pdo))->resolveEmail();
    } catch (RuntimeException) {
        $unknownBlocked = true;
    }
    TestHarness::assertTrue($unknownBlocked, 'unknown production hosts fail closed instead of showing another artist website');

    if ($oldEnvironment === false) putenv('APP_ENV'); else putenv('APP_ENV=' . $oldEnvironment);
    if ($oldHost === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $oldHost;
    if ($oldTenantEmail === null) unset($_GET['tenant_email']); else $_GET['tenant_email'] = $oldTenantEmail;

    $profileSource = (string)file_get_contents(dirname(__DIR__, 2) . '/artist_profile.php');
    TestHarness::assertTrue(str_contains($profileSource, 'value="verify_domain"') && str_contains($profileSource, 'name="csrf"'), 'Artist Profile exposes DNS verification with CSRF protection');
}
