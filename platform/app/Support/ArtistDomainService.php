<?php
declare(strict_types=1);

final class ArtistDomainService
{
    private const PLATFORM_SUFFIX = '.artworkmockups.com';
    private const TXT_PREFIX = 'artworkmockups-domain-verification=';

    private Closure $dnsResolver;

    public function __construct(private PDO $pdo, ?callable $dnsResolver = null)
    {
        $this->dnsResolver = $dnsResolver !== null
            ? Closure::fromCallable($dnsResolver)
            : static fn(string $record): array => dns_get_record($record, DNS_TXT) ?: [];
    }

    /** @return array<string,mixed> */
    public function configuration(int $userId): array
    {
        $profile = $this->profileDestination($userId);
        $domain = $this->domainRow($userId);
        $customDomain = (string)($profile['custom_domain'] ?? '');

        if ($customDomain !== '' && (!$domain || (string)$domain['hostname'] !== $customDomain)) {
            $domain = $this->createPendingDomain($userId, $customDomain);
        } elseif ($domain && (string)$domain['verification_token'] === '') {
            $token = self::newToken();
            $this->pdo->prepare("UPDATE artist_domains SET verification_token=?,status='pending',verified_at=NULL,updated_at=? WHERE user_id=?")
                ->execute([$token, date('c'), $userId]);
            $domain = $this->domainRow($userId);
        }

        $status = $customDomain === '' ? 'none' : (string)($domain['status'] ?? 'pending');
        $token = (string)($domain['verification_token'] ?? '');
        $subdomain = (string)($profile['subdomain'] ?? '');

        return [
            'subdomain' => $subdomain,
            'custom_domain' => $customDomain,
            'status' => $status,
            'verification_record' => $customDomain === '' ? '' : '_artworkmockups-verification.' . $customDomain,
            'verification_value' => $token === '' ? '' : self::TXT_PREFIX . $token,
            'routing_target' => strtolower(trim(app_env('ARTIST_SITE_DOMAIN_TARGET', 'artists.artworkmockups.com'), '.')),
            'verified_at' => (string)($domain['verified_at'] ?? ''),
            'last_checked_at' => (string)($domain['last_checked_at'] ?? ''),
            'last_error' => (string)($domain['last_error'] ?? ''),
            'public_host' => $status === 'verified' && $customDomain !== ''
                ? $customDomain
                : ($subdomain !== '' ? $subdomain . self::PLATFORM_SUFFIX : ''),
        ];
    }

    /** @return array<string,mixed> */
    public function saveConfiguration(int $userId, string $subdomainInput, string $customDomainInput): array
    {
        $subdomain = self::normalizeSubdomain($subdomainInput);
        $customDomain = self::normalizeHost($customDomainInput);
        $this->assertAvailable($userId, $subdomain, $customDomain);
        $this->ensureProfile($userId);

        $existing = $this->domainRow($userId);
        $started = !$this->pdo->inTransaction();
        if ($started) $this->pdo->beginTransaction();

        try {
            $now = date('c');
            $this->pdo->prepare('UPDATE artist_profiles SET subdomain=?,custom_domain=?,updated_at=? WHERE user_id=?')
                ->execute([$subdomain, $customDomain, $now, $userId]);

            if ($customDomain === '') {
                $this->pdo->prepare('DELETE FROM artist_domains WHERE user_id=?')->execute([$userId]);
            } elseif (!$existing || (string)$existing['hostname'] !== $customDomain) {
                $token = self::newToken();
                if ($existing) {
                    $this->pdo->prepare("UPDATE artist_domains SET hostname=?,verification_token=?,status='pending',verified_at=NULL,last_checked_at=NULL,last_error=NULL,updated_at=? WHERE user_id=?")
                        ->execute([$customDomain, $token, $now, $userId]);
                } else {
                    $this->pdo->prepare("INSERT INTO artist_domains (user_id,hostname,verification_token,status,verified_at,last_checked_at,last_error,created_at,updated_at) VALUES (?,?,?,'pending',NULL,NULL,NULL,?,?)")
                        ->execute([$userId, $customDomain, $token, $now, $now]);
                }
            }

            if ($started) $this->pdo->commit();
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($error instanceof PDOException && str_contains(strtolower($error->getMessage()), 'unique')) {
                throw new RuntimeException('This website address is already assigned to another artist.', 0, $error);
            }
            throw $error;
        }

        return $this->configuration($userId);
    }

    /** @return array<string,mixed> */
    public function verifyOwnership(int $userId): array
    {
        $domain = $this->domainRow($userId);
        if (!$domain || trim((string)$domain['hostname']) === '') {
            throw new RuntimeException('Save a custom domain before verifying it.');
        }

        $recordName = '_artworkmockups-verification.' . (string)$domain['hostname'];
        $expected = self::TXT_PREFIX . (string)$domain['verification_token'];
        $records = ($this->dnsResolver)($recordName);
        $verified = false;

        foreach (is_array($records) ? $records : [] as $record) {
            $values = [];
            if (is_string($record)) $values[] = $record;
            if (is_array($record)) {
                if (isset($record['txt'])) $values[] = (string)$record['txt'];
                foreach ((array)($record['entries'] ?? []) as $entry) $values[] = (string)$entry;
            }
            foreach ($values as $value) {
                if (hash_equals($expected, trim($value, " \t\n\r\0\x0B\""))) {
                    $verified = true;
                    break 2;
                }
            }
        }

        $now = date('c');
        if ($verified) {
            $this->pdo->prepare("UPDATE artist_domains SET status='verified',verified_at=?,last_checked_at=?,last_error=NULL,updated_at=? WHERE user_id=?")
                ->execute([$now, $now, $now, $userId]);
        } else {
            $message = 'The verification TXT record is not visible yet. DNS changes can take some time.';
            $this->pdo->prepare("UPDATE artist_domains SET status='pending',verified_at=NULL,last_checked_at=?,last_error=?,updated_at=? WHERE user_id=?")
                ->execute([$now, $message, $now, $userId]);
        }

        $configuration = $this->configuration($userId);
        $configuration['verified_now'] = $verified;
        return $configuration;
    }

    public function verifiedCustomDomain(int $userId): string
    {
        $stmt = $this->pdo->prepare("SELECT hostname FROM artist_domains WHERE user_id=? AND status='verified' LIMIT 1");
        $stmt->execute([$userId]);
        return strtolower(trim((string)($stmt->fetchColumn() ?: '')));
    }

    public static function normalizeHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';

        $candidate = preg_match('#^https?://#i', $value) ? $value : 'https://' . ltrim($value, '/');
        $parts = parse_url($candidate);
        if (!is_array($parts) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Enter a domain only, without a port, query, or login.');
        }
        $path = (string)($parts['path'] ?? '');
        if ($path !== '' && $path !== '/') throw new RuntimeException('Enter the domain without a page path.');

        $host = strtolower(trim((string)$parts['host'], '.'));
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') $host = strtolower($ascii);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) || strlen($host) > 253 || !str_contains($host, '.')) {
            throw new RuntimeException('Enter a valid public domain.');
        }
        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
                throw new RuntimeException('Enter a valid public domain.');
            }
        }
        $tld = end($labels);
        if (!preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/', (string)$tld)) {
            throw new RuntimeException('Enter a valid public domain.');
        }
        if ($host === 'artworkmockups.com' || str_ends_with($host, self::PLATFORM_SUFFIX)) {
            throw new RuntimeException('Artwork Mockups addresses are assigned through the subdomain field.');
        }
        return $host;
    }

    public static function normalizeSubdomain(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') return '';
        if (strlen($value) < 3 || strlen($value) > 63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $value)) {
            throw new RuntimeException('The subdomain must contain 3–63 lowercase letters, numbers, or internal hyphens.');
        }
        $reserved = ['admin', 'api', 'app', 'artists', 'assets', 'auth', 'blog', 'cdn', 'help', 'mail', 'platform', 'site-admin', 'status', 'support', 'www'];
        if (in_array($value, $reserved, true)) throw new RuntimeException('This subdomain is reserved by Artwork Mockups.');
        return $value;
    }

    private function assertAvailable(int $userId, string $subdomain, string $customDomain): void
    {
        if ($subdomain !== '') {
            $stmt = $this->pdo->prepare('SELECT 1 FROM artist_profiles WHERE user_id<>? AND LOWER(subdomain)=? LIMIT 1');
            $stmt->execute([$userId, $subdomain]);
            if ($stmt->fetchColumn()) throw new RuntimeException('This Artwork Mockups subdomain is already assigned.');
        }
        if ($customDomain !== '') {
            $stmt = $this->pdo->prepare('SELECT 1 FROM artist_domains WHERE user_id<>? AND LOWER(hostname)=? LIMIT 1');
            $stmt->execute([$userId, $customDomain]);
            if ($stmt->fetchColumn()) throw new RuntimeException('This custom domain is already assigned.');
            $stmt = $this->pdo->prepare('SELECT 1 FROM artist_profiles WHERE user_id<>? AND LOWER(custom_domain)=? LIMIT 1');
            $stmt->execute([$userId, $customDomain]);
            if ($stmt->fetchColumn()) throw new RuntimeException('This custom domain is already assigned.');
        }
    }

    private function ensureProfile(int $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM artist_profiles WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn()) return;

        $fields = ArtistProfile::fields();
        $columns = array_merge(['user_id'], $fields, ['created_at', 'updated_at']);
        $now = date('c');
        $values = array_merge([$userId], array_fill(0, count($fields), ''), [$now, $now]);
        $this->pdo->prepare('INSERT INTO artist_profiles (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')
            ->execute($values);
    }

    /** @return array<string,mixed> */
    private function profileDestination(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT COALESCE(subdomain,'') subdomain,COALESCE(custom_domain,'') custom_domain FROM artist_profiles WHERE user_id=? LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['subdomain' => '', 'custom_domain' => ''];
    }

    /** @return array<string,mixed>|null */
    private function domainRow(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artist_domains WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function createPendingDomain(int $userId, string $hostname): array
    {
        $now = date('c');
        $existing = $this->domainRow($userId);
        if ($existing) {
            $this->pdo->prepare("UPDATE artist_domains SET hostname=?,verification_token=?,status='pending',verified_at=NULL,last_checked_at=NULL,last_error=NULL,updated_at=? WHERE user_id=?")
                ->execute([$hostname, self::newToken(), $now, $userId]);
        } else {
            $this->pdo->prepare("INSERT INTO artist_domains (user_id,hostname,verification_token,status,verified_at,last_checked_at,last_error,created_at,updated_at) VALUES (?,?,?,'pending',NULL,NULL,NULL,?,?)")
                ->execute([$userId, $hostname, self::newToken(), $now, $now]);
        }
        return $this->domainRow($userId) ?? [];
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(24));
    }
}
