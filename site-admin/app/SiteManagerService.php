<?php
declare(strict_types=1);

final class SiteManagerService
{
    private PublicationService $publications;
    private WebsiteBoardService $websiteBoard;

    public function __construct(private PDO $pdo)
    {
        $this->publications = new PublicationService($pdo);
        $this->websiteBoard = new WebsiteBoardService($pdo);
        ArtworkSeries::ensureSchema($pdo);
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $integer = $mysql ? 'INT UNSIGNED' : 'INTEGER';
        $long = $mysql ? 'LONGTEXT' : 'TEXT';

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_settings (
            id {$id}, user_id {$integer} NOT NULL UNIQUE,
            site_title VARCHAR(255) NOT NULL DEFAULT '', tagline VARCHAR(255) NOT NULL DEFAULT '',
            locale VARCHAR(20) NOT NULL DEFAULT 'en', site_status VARCHAR(30) NOT NULL DEFAULT 'draft',
            contact_email VARCHAR(255) NOT NULL DEFAULT '', inquiry_intro {$long} NOT NULL,
            currency VARCHAR(3) NOT NULL DEFAULT 'EUR', payment_provider VARCHAR(40) NOT NULL DEFAULT '',
            payment_status VARCHAR(30) NOT NULL DEFAULT 'not_connected',
            shipping_regions {$long} NOT NULL, shipping_policy {$long} NOT NULL, shipping_rates_json {$long} NOT NULL,
            created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL
        )");
        if (!$this->columnExists('artist_site_settings', 'shipping_rates_json')) {
            $this->pdo->exec('ALTER TABLE artist_site_settings ADD COLUMN shipping_rates_json ' . ($mysql ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT '{}'"));
            $this->pdo->prepare('UPDATE artist_site_settings SET shipping_rates_json=? WHERE shipping_rates_json IS NULL OR shipping_rates_json=?')
                ->execute([$this->defaultShippingRatesJson(), '']);
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_constellations (
            id {$id}, user_id {$integer} NOT NULL, artwork_id {$integer} NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 0, country VARCHAR(120) NOT NULL DEFAULT '',
            region VARCHAR(160) NOT NULL DEFAULT '', city VARCHAR(160) NOT NULL DEFAULT '',
            postal_code VARCHAR(40) NOT NULL DEFAULT '', latitude VARCHAR(40) NOT NULL DEFAULT '',
            longitude VARCHAR(40) NOT NULL DEFAULT '', privacy VARCHAR(30) NOT NULL DEFAULT 'country',
            public_note {$long} NOT NULL, created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL,
            UNIQUE(user_id, artwork_id)
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_print_variants (
            id {$id}, user_id {$integer} NOT NULL, artwork_id {$integer} NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '', sku VARCHAR(120) NOT NULL DEFAULT '',
            size_label VARCHAR(120) NOT NULL DEFAULT '', support VARCHAR(120) NOT NULL DEFAULT '',
            finish VARCHAR(120) NOT NULL DEFAULT '', inventory_mode VARCHAR(30) NOT NULL DEFAULT 'in_stock',
            edition_size INTEGER NOT NULL DEFAULT 0, stock_on_hand INTEGER NOT NULL DEFAULT 0,
            stock_reserved INTEGER NOT NULL DEFAULT 0, price_minor INTEGER NOT NULL DEFAULT 0,
            currency VARCHAR(3) NOT NULL DEFAULT 'EUR', status VARCHAR(30) NOT NULL DEFAULT 'draft',
            created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_orders (
            id {$id}, user_id {$integer} NOT NULL, public_number VARCHAR(80) NOT NULL,
            customer_name VARCHAR(255) NOT NULL DEFAULT '', customer_email VARCHAR(255) NOT NULL DEFAULT '',
            payment_status VARCHAR(30) NOT NULL DEFAULT 'pending', order_status VARCHAR(30) NOT NULL DEFAULT 'awaiting_payment',
            currency VARCHAR(3) NOT NULL DEFAULT 'EUR', subtotal_minor INTEGER NOT NULL DEFAULT 0,
            shipping_minor INTEGER NOT NULL DEFAULT 0, tax_minor INTEGER NOT NULL DEFAULT 0,
            total_minor INTEGER NOT NULL DEFAULT 0, provider_reference VARCHAR(255) NOT NULL DEFAULT '',
            provider_account_id VARCHAR(120) NOT NULL DEFAULT '',
            shipping_json {$long} NOT NULL, created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL,
            UNIQUE(user_id, public_number)
        )");
        if (!$this->columnExists('artist_site_orders', 'provider_account_id')) {
            $this->pdo->exec("ALTER TABLE artist_site_orders ADD COLUMN provider_account_id VARCHAR(120) NOT NULL DEFAULT ''");
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_payment_connections (
            id {$id}, user_id {$integer} NOT NULL UNIQUE,
            provider VARCHAR(40) NOT NULL DEFAULT 'stripe',
            external_account_id VARCHAR(120) NOT NULL DEFAULT '',
            livemode INTEGER NOT NULL DEFAULT 0,
            charges_enabled INTEGER NOT NULL DEFAULT 0,
            payouts_enabled INTEGER NOT NULL DEFAULT 0,
            details_submitted INTEGER NOT NULL DEFAULT 0,
            connection_status VARCHAR(30) NOT NULL DEFAULT 'not_connected',
            created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_order_items (
            id {$id}, order_id {$integer} NOT NULL, print_variant_id {$integer} NOT NULL,
            artwork_id {$integer} NOT NULL, title VARCHAR(255) NOT NULL DEFAULT '',
            sku VARCHAR(120) NOT NULL DEFAULT '', variant_snapshot_json {$long} NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 1, unit_price_minor INTEGER NOT NULL DEFAULT 0,
            total_minor INTEGER NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_activity (
            id {$id}, user_id {$integer} NOT NULL, event_type VARCHAR(60) NOT NULL,
            entity_type VARCHAR(60) NOT NULL DEFAULT '', entity_id VARCHAR(80) NOT NULL DEFAULT '',
            message {$long} NOT NULL, created_at VARCHAR(40) NOT NULL
        )");
    }

    /** @return array<string,mixed> */
    public function settings(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artist_site_settings WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
        $profile = ArtistProfile::findForUser($userId);
        return [
            'user_id' => $userId,
            'site_title' => (string)($profile['artist_name'] ?? ''),
            'tagline' => '',
            'locale' => 'en',
            'site_status' => 'draft',
            'contact_email' => '',
            'inquiry_intro' => '',
            'currency' => 'EUR',
            'payment_provider' => '',
            'payment_status' => 'not_connected',
            'shipping_regions' => '',
            'shipping_policy' => '',
            'shipping_rates_json' => $this->defaultShippingRatesJson(),
        ];
    }

    /** @param array<string,mixed> $input */
    public function saveSettings(int $userId, string $section, array $input): void
    {
        $current = $this->settings($userId);
        if ($section === 'site') {
            $current['site_title'] = trim((string)($input['site_title'] ?? ''));
            $current['tagline'] = trim((string)($input['tagline'] ?? ''));
            $locale = strtolower(trim((string)($input['locale'] ?? 'en')));
            $current['locale'] = preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale) ? $locale : 'en';
            $status = (string)($input['site_status'] ?? 'draft');
            $current['site_status'] = in_array($status, ['draft', 'active', 'suspended'], true) ? $status : 'draft';
        } elseif ($section === 'inquire') {
            $email = strtolower(trim((string)($input['contact_email'] ?? '')));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid contact email.');
            $current['contact_email'] = $email;
            $current['inquiry_intro'] = trim((string)($input['inquiry_intro'] ?? ''));
        } elseif ($section === 'payments') {
            $currency = strtoupper(trim((string)($input['currency'] ?? 'EUR')));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) throw new RuntimeException('Currency must use a three-letter ISO code.');
            $current['currency'] = $currency;
        } elseif ($section === 'shipping') {
            $rates = [];
            foreach (array_keys($this->defaultShippingRates()) as $continent) {
                $price = str_replace(',', '.', trim((string)($input['shipping_rate_' . $continent] ?? '250')));
                if (!is_numeric($price) || (float)$price < 0) throw new RuntimeException('Enter a valid shipping price for every continent.');
                $rates[$continent] = (int)round((float)$price * 100);
            }
            $current['shipping_rates_json'] = json_encode($rates, JSON_UNESCAPED_SLASHES) ?: $this->defaultShippingRatesJson();
            $current['shipping_policy'] = trim((string)($input['shipping_policy'] ?? ''));
        } else {
            throw new RuntimeException('Unknown settings section.');
        }
        $this->upsertSettings($userId, $current);
        $this->log($userId, 'settings.updated', 'settings', $section, ucfirst($section) . ' settings updated.');
    }

    /** @param array<string,mixed> $settings */
    private function upsertSettings(int $userId, array $settings): void
    {
        $now = date('c');
        $exists = $this->pdo->prepare('SELECT id FROM artist_site_settings WHERE user_id=?');
        $exists->execute([$userId]);
        $values = [
            (string)$settings['site_title'], (string)$settings['tagline'], (string)$settings['locale'],
            (string)$settings['site_status'], (string)$settings['contact_email'], (string)$settings['inquiry_intro'],
            (string)$settings['currency'], (string)$settings['payment_provider'], (string)$settings['payment_status'],
            (string)$settings['shipping_regions'], (string)$settings['shipping_policy'],
            (string)($settings['shipping_rates_json'] ?? $this->defaultShippingRatesJson()), $now,
        ];
        if ($exists->fetchColumn()) {
            $this->pdo->prepare('UPDATE artist_site_settings SET site_title=?,tagline=?,locale=?,site_status=?,contact_email=?,inquiry_intro=?,currency=?,payment_provider=?,payment_status=?,shipping_regions=?,shipping_policy=?,shipping_rates_json=?,updated_at=? WHERE user_id=?')
                ->execute([...$values, $userId]);
            return;
        }
        $this->pdo->prepare('INSERT INTO artist_site_settings (site_title,tagline,locale,site_status,contact_email,inquiry_intro,currency,payment_provider,payment_status,shipping_regions,shipping_policy,shipping_rates_json,created_at,updated_at,user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([...array_slice($values, 0, 12), $now, $now, $userId]);
    }

    /** @return array<string,mixed> */
    public function paymentConnection(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artist_site_payment_connections WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: [
            'user_id' => $userId,
            'provider' => 'stripe',
            'external_account_id' => '',
            'livemode' => 0,
            'charges_enabled' => 0,
            'payouts_enabled' => 0,
            'details_submitted' => 0,
            'connection_status' => 'not_connected',
        ];
    }

    /** @param array<string,mixed> $account */
    public function saveStripeConnection(int $userId, string $accountId, bool $livemode, array $account): void
    {
        if (!preg_match('/^acct_[A-Za-z0-9]+$/', $accountId)) throw new RuntimeException('Invalid Stripe connected account.');
        $duplicate = $this->pdo->prepare("SELECT user_id FROM artist_site_payment_connections WHERE external_account_id=? AND user_id<>? AND connection_status<>'disconnected' LIMIT 1");
        $duplicate->execute([$accountId, $userId]);
        if ($duplicate->fetchColumn()) throw new RuntimeException('This Stripe account is already connected to another artist.');
        $chargesEnabled = !empty($account['charges_enabled']);
        $payoutsEnabled = !empty($account['payouts_enabled']);
        $detailsSubmitted = !empty($account['details_submitted']);
        $status = $chargesEnabled && $payoutsEnabled && $detailsSubmitted ? 'connected' : 'requirements_due';
        $now = date('c');
        $exists = $this->pdo->prepare('SELECT id FROM artist_site_payment_connections WHERE user_id=?');
        $exists->execute([$userId]);
        if ($exists->fetchColumn()) {
            $this->pdo->prepare('UPDATE artist_site_payment_connections SET provider=?,external_account_id=?,livemode=?,charges_enabled=?,payouts_enabled=?,details_submitted=?,connection_status=?,updated_at=? WHERE user_id=?')
                ->execute(['stripe', $accountId, $livemode ? 1 : 0, $chargesEnabled ? 1 : 0, $payoutsEnabled ? 1 : 0, $detailsSubmitted ? 1 : 0, $status, $now, $userId]);
        } else {
            $this->pdo->prepare('INSERT INTO artist_site_payment_connections (user_id,provider,external_account_id,livemode,charges_enabled,payouts_enabled,details_submitted,connection_status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$userId, 'stripe', $accountId, $livemode ? 1 : 0, $chargesEnabled ? 1 : 0, $payoutsEnabled ? 1 : 0, $detailsSubmitted ? 1 : 0, $status, $now, $now]);
        }
        $settings = $this->settings($userId);
        $settings['payment_provider'] = 'stripe';
        $settings['payment_status'] = $status;
        $this->upsertSettings($userId, $settings);
        $this->log($userId, 'payment.connected', 'payment_connection', $accountId, 'Stripe account connected for this artist.');
    }

    public function disconnectStripeConnection(int $userId): void
    {
        $connection = $this->paymentConnection($userId);
        $now = date('c');
        $this->pdo->prepare("UPDATE artist_site_payment_connections SET external_account_id='',livemode=0,charges_enabled=0,payouts_enabled=0,details_submitted=0,connection_status='disconnected',updated_at=? WHERE user_id=?")
            ->execute([$now, $userId]);
        $settings = $this->settings($userId);
        $settings['payment_provider'] = '';
        $settings['payment_status'] = 'not_connected';
        $this->upsertSettings($userId, $settings);
        $this->log($userId, 'payment.disconnected', 'payment_connection', (string)$connection['external_account_id'], 'Stripe account disconnected for this artist.');
    }

    /** @return array<string,int> */
    private function defaultShippingRates(): array
    {
        return [
            'europe' => 25000,
            'africa' => 25000,
            'asia' => 25000,
            'north_america' => 25000,
            'south_america' => 25000,
            'oceania' => 25000,
        ];
    }

    private function defaultShippingRatesJson(): string
    {
        return json_encode($this->defaultShippingRates(), JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function columnExists(string $table, string $column): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetchColumn();
        }
        foreach ($this->pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string)($row['name'] ?? '') === $column) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $input */
    public function saveArtist(int $userId, array $input): void
    {
        $profile = ArtistProfile::findForUser($userId);
        foreach (['artist_name', 'short_bio', 'statement'] as $field) {
            if (array_key_exists($field, $input)) $profile[$field] = trim((string)$input[$field]);
        }
        ArtistProfile::saveForUser($userId, $profile);
        $this->log($userId, 'artist.updated', 'artist', (string)$userId, 'Public artist profile updated.');
    }

    /** @param array<string,mixed> $input */
    public function saveDomain(int $userId, array $input): void
    {
        (new ArtistDomainService($this->pdo))->saveConfiguration(
            $userId,
            (string)($input['subdomain'] ?? ''),
            (string)($input['custom_domain'] ?? '')
        );
        $this->log($userId, 'domain.updated', 'domain', (string)$userId, 'Artist website destination updated.');
    }

    /** @return array<int,array<string,mixed>> */
    public function artworks(int $userId): array
    {
        $fallback = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? "CONCAT('Artwork #',a.id)" : "('Artwork #' || a.id)";
        $stmt = $this->pdo->prepare("SELECT a.id artwork_id,
                COALESCE(NULLIF(sh.title,''),NULLIF(ag.title,''),NULLIF(a.final_title,''),{$fallback}) artwork_title,
                COALESCE(NULLIF(sh.source_image_file,''),NULLIF(a.root_file,''),NULLIF(a.main_file,''),'') image_file,
                COALESCE(NULLIF(s.title,''),NULLIF(a.series,''),'') series_title,
                COALESCE(a.artwork_group_id,0) artwork_group_id,
                COALESCE(sh.id,0) artwork_sheet_id,
                COALESCE(p.id,0) publication_id,COALESCE(p.slug,'') slug,
                COALESCE(sh.title,'') public_title,COALESCE(sh.short_description,'') short_description,
                COALESCE(sh.description,'') description,'inherit' content_source,
                COALESCE(p.cta_label,'') cta_label,COALESCE(p.cta_url,'') cta_url,
                COALESCE(p.header_file,'') header_file,COALESCE(p.status,'not_prepared') publication_status,
                COALESCE(p.visibility,'private') visibility,COALESCE(p.updated_at,a.updated_at) website_updated_at,
                COALESCE(p.display_order,0) display_order,
                CASE WHEN COALESCE(c.enabled,0)=1 THEN COALESCE(c.country,'') ELSE '' END constellation_country
            FROM artworks a
            LEFT JOIN artwork_groups ag ON ag.id=a.artwork_group_id AND ag.user_id=a.user_id
            LEFT JOIN artwork_series s ON s.id=a.series_id AND s.user_id=a.user_id
            LEFT JOIN artist_site_constellations c ON c.artwork_id=a.id AND c.user_id=a.user_id
            LEFT JOIN artwork_sheets sh ON sh.id=(SELECT MAX(sh2.id) FROM artwork_sheets sh2 WHERE sh2.user_id=a.user_id AND sh2.canonical_artwork_id=a.id AND COALESCE(sh2.status,'')<>'merged')
            LEFT JOIN publications p ON p.id=(SELECT MAX(p2.id) FROM publications p2 WHERE p2.user_id=a.user_id AND p2.artwork_sheet_id=sh.id)
            WHERE a.user_id=? AND a.status='done'
            AND (COALESCE(a.artwork_group_id,0)=0 OR (ag.status='active' AND ag.canonical_artwork_id=a.id))
            ORDER BY CASE WHEN p.status='published' AND p.display_order>0 THEN 0 ELSE 1 END,
                CASE WHEN p.status='published' THEN p.display_order ELSE 0 END ASC,
                ag.updated_at DESC,ag.created_at DESC,ag.id DESC,a.id DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function artwork(int $userId, int $artworkId): array
    {
        foreach ($this->artworks($userId) as $artwork) if ((int)$artwork['artwork_id'] === $artworkId) return $artwork;
        throw new RuntimeException('Artwork not found.');
    }

    /** @param array<int,int> $publicationIds */
    public function reorderArtworks(int $userId, array $publicationIds): void
    {
        $publicationIds = array_values(array_unique(array_filter(array_map('intval', $publicationIds), static fn(int $id): bool => $id > 0)));
        if (!$publicationIds) throw new RuntimeException('No published artworks were supplied for ordering.');

        $placeholders = implode(',', array_fill(0, count($publicationIds), '?'));
        $owned = $this->pdo->prepare("SELECT id FROM publications WHERE user_id=? AND status='published' AND id IN ({$placeholders})");
        $owned->execute([$userId, ...$publicationIds]);
        $ownedIds = array_map('intval', $owned->fetchAll(PDO::FETCH_COLUMN));
        sort($ownedIds);
        $requestedIds = $publicationIds;
        sort($requestedIds);
        if ($ownedIds !== $requestedIds) throw new RuntimeException('The public order contains an unavailable artwork. Reload the page and try again.');

        $started = !$this->pdo->inTransaction();
        if ($started) $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE publications SET display_order=?,updated_at=? WHERE id=? AND user_id=?');
            $now = date('c');
            foreach ($publicationIds as $position => $publicationId) {
                $update->execute([($position + 1) * 10, $now, $publicationId, $userId]);
            }
            if ($started) $this->pdo->commit();
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        $this->log($userId, 'artworks.reordered', 'artwork', '', 'Public artwork order updated.');
    }

    /** @return array<int,array{file:string,label:string}> */
    public function artworkCoverOptions(int $userId, int $artworkId): array
    {
        $artwork = $this->artwork($userId, $artworkId);
        $options = [];
        $add = static function (array &$items, string $file, string $label): void {
            $file = basename(trim($file));
            if ($file !== '' && !isset($items[$file])) $items[$file] = ['file' => $file, 'label' => $label];
        };
        $add($options, (string)$artwork['image_file'], 'Main artwork image');

        // Ownership was already established by artwork(); this historical table has no user_id column.
        $views = $this->pdo->prepare('SELECT file_name,view_type FROM root_artwork_candidates WHERE artwork_id=? ORDER BY id');
        $views->execute([$artworkId]);
        foreach ($views->fetchAll(PDO::FETCH_ASSOC) as $view) {
            $add($options, (string)$view['file_name'], trim(str_replace('_', ' ', (string)$view['view_type'])) ?: 'Artwork view');
        }

        $mockups = $this->pdo->prepare('SELECT m.mockup_file,COALESCE(NULLIF(m.title,\'\'),\'Context image\') label
            FROM mockup_sheets m
            WHERE m.user_id=? AND (
                m.artwork_id=? OR m.artwork_sheet_id=? OR (? > 0 AND m.artwork_group_id=?)
            )
            ORDER BY CASE WHEN m.artwork_id=? THEN 0 WHEN m.artwork_sheet_id=? THEN 1 ELSE 2 END,m.id DESC');
        $mockups->execute([
            $userId, $artworkId, (int)$artwork['artwork_sheet_id'],
            (int)$artwork['artwork_group_id'], (int)$artwork['artwork_group_id'],
            $artworkId, (int)$artwork['artwork_sheet_id'],
        ]);
        foreach ($mockups->fetchAll(PDO::FETCH_ASSOC) as $mockup) $add($options, (string)$mockup['mockup_file'], (string)$mockup['label']);
        return array_values($options);
    }

    /** @param array<string,mixed> $input */
    public function saveArtwork(int $userId, int $artworkId, array $input, string $action): void
    {
        $artwork = $this->artwork($userId, $artworkId);
        $sheetId = (int)$artwork['artwork_sheet_id'];
        if ($sheetId <= 0) throw new RuntimeException('This artwork does not have an editorial sheet yet.');
        $publicationId = (int)$artwork['publication_id'];
        if ($publicationId <= 0) $publicationId = $this->publications->createForSheet($sheetId, $userId);

        $this->publications->save($publicationId, $userId, [
            'title' => trim((string)($input['title'] ?? '')),
            'short_description' => trim((string)($input['short_description'] ?? '')),
            'description' => trim((string)($input['description'] ?? '')),
            'cta_label' => trim((string)($input['cta_label'] ?? '')),
            'cta_url' => trim((string)($input['cta_url'] ?? '')),
        ], null);
        if (array_key_exists('header_file', $input)) {
            $headerFile = basename(trim((string)$input['header_file']));
            $allowed = array_column($this->artworkCoverOptions($userId, $artworkId), 'file');
            if ($headerFile !== '' && !in_array($headerFile, $allowed, true)) throw new RuntimeException('The selected catalog cover does not belong to this artwork.');
            $this->pdo->prepare('UPDATE publications SET header_file=?,updated_at=? WHERE id=? AND user_id=?')
                ->execute([$headerFile, date('c'), $publicationId, $userId]);
        }
        if (in_array($action, ['publish', 'unpublish', 'hide', 'show'], true)) {
            $this->websiteBoard->catalogAction($userId, $publicationId, $action);
        }
        if (array_key_exists('constellation_country', $input)) {
            $this->saveArtworkConstellationCountry($userId, $artworkId, (string)$input['constellation_country']);
        }
        $verb = $action === 'publish' ? 'published' : ($action === 'unpublish' ? 'unpublished' : 'updated');
        $this->log($userId, 'artwork.' . $verb, 'artwork', (string)$artworkId, 'Artwork website entry ' . $verb . '.');
    }

    private function saveArtworkConstellationCountry(int $userId, int $artworkId, string $country): void
    {
        $country = trim($country);
        $stmt = $this->pdo->prepare('SELECT id FROM artist_site_constellations WHERE user_id=? AND artwork_id=? LIMIT 1');
        $stmt->execute([$userId, $artworkId]);
        $exists = (bool)$stmt->fetchColumn();
        if (!$exists && $country === '') return;

        $enabled = $country === '' ? 0 : 1;
        $privacy = $country === '' ? 'private' : 'country';
        $now = date('c');
        if ($exists) {
            $this->pdo->prepare("UPDATE artist_site_constellations SET enabled=?,country=?,region='',city='',postal_code='',latitude='',longitude='',privacy=?,public_note='',updated_at=? WHERE user_id=? AND artwork_id=?")
                ->execute([$enabled, $country, $privacy, $now, $userId, $artworkId]);
            return;
        }
        $this->pdo->prepare("INSERT INTO artist_site_constellations (user_id,artwork_id,enabled,country,region,city,postal_code,latitude,longitude,privacy,public_note,created_at,updated_at) VALUES (?,?,?,?,'','','','','',?,'',?,?)")
            ->execute([$userId, $artworkId, $enabled, $country, $privacy, $now, $now]);
    }

    /** @return array<int,array<string,mixed>> */
    public function series(int $userId): array
    {
        $series = ArtworkSeries::seriesList($this->pdo, $userId);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM publications p INNER JOIN artwork_sheets sh ON sh.id=p.artwork_sheet_id AND sh.user_id=p.user_id INNER JOIN artworks a ON a.id=sh.canonical_artwork_id AND a.user_id=p.user_id WHERE p.user_id=? AND p.status='published' AND a.series_id=?");
        foreach ($series as &$item) {
            $count->execute([$userId, (int)$item['id']]);
            $item['published_artwork_count'] = (int)$count->fetchColumn();
        }
        unset($item);
        return $series;
    }

    public function seriesItem(int $userId, int $seriesId): array
    {
        foreach ($this->series($userId) as $series) if ((int)$series['id'] === $seriesId) return $series;
        throw new RuntimeException('Series not found.');
    }

    /** @param array<string,mixed> $input */
    public function saveSeries(int $userId, int $seriesId, array $input, string $action): void
    {
        $this->seriesItem($userId, $seriesId);
        ArtworkSeries::updateContent($this->pdo, $userId, $seriesId, $input);
        if ($action === 'publish') ArtworkSeries::setPublished($this->pdo, $userId, $seriesId, true);
        if ($action === 'unpublish') ArtworkSeries::setPublished($this->pdo, $userId, $seriesId, false);
        $verb = $action === 'publish' ? 'published' : ($action === 'unpublish' ? 'unpublished' : 'updated');
        $this->log($userId, 'series.' . $verb, 'series', (string)$seriesId, 'Series website entry ' . $verb . '.');
    }

    /** @return array<int,array<string,mixed>> */
    public function notes(int $userId): array
    {
        return $this->websiteBoard->notes($userId, true);
    }

    public function note(int $userId, int $noteId): array
    {
        foreach ($this->notes($userId) as $note) if ((int)$note['id'] === $noteId) return $note;
        throw new RuntimeException('Studio Note not found.');
    }

    public function saveNote(int $userId, int $noteId, string $title, string $body, string $action): void
    {
        $this->websiteBoard->saveNote($userId, $noteId, $title, $body);
        if (in_array($action, ['publish', 'unpublish'], true)) $this->websiteBoard->noteAction($userId, $noteId, $action);
        $verb = $action === 'publish' ? 'published' : ($action === 'unpublish' ? 'unpublished' : 'updated');
        $this->log($userId, 'note.' . $verb, 'studio_note', (string)$noteId, 'Studio Note ' . $verb . '.');
    }

    /** @return array<int,array<string,mixed>> */
    public function constellations(int $userId): array
    {
        $items = [];
        $stmt = $this->pdo->prepare('SELECT * FROM artist_site_constellations WHERE user_id=?');
        $stmt->execute([$userId]);
        $stored = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $stored[(int)$row['artwork_id']] = $row;
        foreach ($this->artworks($userId) as $artwork) {
            if ((string)$artwork['publication_status'] !== 'published') continue;
            $location = $stored[(int)$artwork['artwork_id']] ?? [];
            $items[] = array_merge($artwork, [
                'constellation_id' => (int)($location['id'] ?? 0),
                'constellation_enabled' => (bool)($location['enabled'] ?? false),
                'country' => (string)($location['country'] ?? ''),
                'region' => (string)($location['region'] ?? ''),
                'city' => (string)($location['city'] ?? ''),
                'postal_code' => (string)($location['postal_code'] ?? ''),
                'latitude' => (string)($location['latitude'] ?? ''),
                'longitude' => (string)($location['longitude'] ?? ''),
                'privacy' => (string)($location['privacy'] ?? 'country'),
                'public_note' => (string)($location['public_note'] ?? ''),
            ]);
        }
        return $items;
    }

    /** @param array<string,mixed> $input */
    public function saveConstellation(int $userId, int $artworkId, array $input): void
    {
        $artwork = $this->artwork($userId, $artworkId);
        if ((string)$artwork['publication_status'] !== 'published') throw new RuntimeException('Publish the artwork before adding it to Constellations.');
        $privacy = (string)($input['privacy'] ?? 'country');
        if (!in_array($privacy, ['country', 'region', 'city', 'approximate', 'private'], true)) $privacy = 'country';
        $country = trim((string)($input['country'] ?? ''));
        $region = trim((string)($input['region'] ?? ''));
        $city = trim((string)($input['city'] ?? ''));
        $postalCode = trim((string)($input['postal_code'] ?? ''));
        $latitude = trim((string)($input['latitude'] ?? ''));
        $longitude = trim((string)($input['longitude'] ?? ''));
        if ($privacy !== 'private' && $country === '') throw new RuntimeException('Country is required for a public Constellation placement.');
        if ($privacy === 'approximate' && (!is_numeric($latitude) || !is_numeric($longitude))) {
            throw new RuntimeException('Latitude and longitude are required for an approximate map point.');
        }
        // Public precision is the publication decision. "Private" is the single explicit hidden state.
        $enabled = $privacy === 'private' ? 0 : 1;
        $values = [
            $enabled, $country, $region, $city, $postalCode, $latitude, $longitude,
            $privacy, trim((string)($input['public_note'] ?? '')), date('c'),
        ];
        $stmt = $this->pdo->prepare('SELECT id FROM artist_site_constellations WHERE user_id=? AND artwork_id=?');
        $stmt->execute([$userId, $artworkId]);
        if ($stmt->fetchColumn()) {
            $this->pdo->prepare('UPDATE artist_site_constellations SET enabled=?,country=?,region=?,city=?,postal_code=?,latitude=?,longitude=?,privacy=?,public_note=?,updated_at=? WHERE user_id=? AND artwork_id=?')
                ->execute([...$values, $userId, $artworkId]);
        } else {
            $now = date('c');
            $this->pdo->prepare('INSERT INTO artist_site_constellations (enabled,country,region,city,postal_code,latitude,longitude,privacy,public_note,updated_at,user_id,artwork_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([...$values, $userId, $artworkId, $now]);
        }
        $this->log($userId, 'constellation.updated', 'artwork', (string)$artworkId, 'Constellation placement updated.');
    }

    /** @return array<int,array<string,mixed>> */
    public function prints(int $userId, ?int $artworkId = null): array
    {
        $sql = 'SELECT v.* FROM artist_site_print_variants v WHERE v.user_id=?';
        $params = [$userId];
        if ($artworkId !== null) { $sql .= ' AND v.artwork_id=?'; $params[] = $artworkId; }
        $sql .= ' ORDER BY v.artwork_id,v.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['stock_available'] = max(0, (int)$row['stock_on_hand'] - (int)$row['stock_reserved']);
        unset($row);
        return $rows;
    }

    /** @param array<string,mixed> $input */
    public function savePrint(int $userId, int $artworkId, int $variantId, array $input): int
    {
        $artwork = $this->artwork($userId, $artworkId);
        if ((string)$artwork['publication_status'] !== 'published') throw new RuntimeException('Publish the artwork before configuring its stock.');
        $sku = strtoupper(trim((string)($input['sku'] ?? '')));
        if ($sku === '') $sku = 'ART-' . $artworkId;
        $duplicate = $this->pdo->prepare('SELECT id FROM artist_site_print_variants WHERE user_id=? AND sku=? AND id<>? LIMIT 1');
        $duplicate->execute([$userId, $sku, $variantId]);
        if ($duplicate->fetchColumn()) throw new RuntimeException('This SKU is already in use.');
        $mode = (string)($input['inventory_mode'] ?? 'in_stock');
        if (!in_array($mode, ['in_stock', 'made_to_order', 'limited_edition'], true)) $mode = 'in_stock';
        $status = (string)($input['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'active', 'paused', 'sold_out'], true)) $status = 'draft';
        $currency = strtoupper(trim((string)($input['currency'] ?? 'EUR')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) throw new RuntimeException('Currency must use a three-letter ISO code.');
        $price = str_replace(',', '.', trim((string)($input['price'] ?? '0')));
        if (!is_numeric($price) || (float)$price < 0) throw new RuntimeException('Enter a valid price.');
        $values = [
            trim((string)($input['title'] ?? '')), $sku, trim((string)($input['size_label'] ?? '')),
            trim((string)($input['support'] ?? '')), trim((string)($input['finish'] ?? '')), $mode,
            max(0, (int)($input['edition_size'] ?? 0)), max(0, (int)($input['stock_on_hand'] ?? 0)),
            (int)round((float)$price * 100), $currency, $status, date('c'),
        ];
        if ($variantId > 0) {
            $owner = $this->pdo->prepare('SELECT id FROM artist_site_print_variants WHERE id=? AND user_id=? AND artwork_id=?');
            $owner->execute([$variantId, $userId, $artworkId]);
            if (!$owner->fetchColumn()) throw new RuntimeException('Print format not found.');
            $this->pdo->prepare('UPDATE artist_site_print_variants SET title=?,sku=?,size_label=?,support=?,finish=?,inventory_mode=?,edition_size=?,stock_on_hand=?,price_minor=?,currency=?,status=?,updated_at=? WHERE id=? AND user_id=?')
                ->execute([...$values, $variantId, $userId]);
        } else {
            $now = date('c');
            $this->pdo->prepare('INSERT INTO artist_site_print_variants (title,sku,size_label,support,finish,inventory_mode,edition_size,stock_on_hand,price_minor,currency,status,updated_at,user_id,artwork_id,stock_reserved,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?)')
                ->execute([...$values, $userId, $artworkId, $now]);
            $variantId = (int)$this->pdo->lastInsertId();
        }
        $this->log($userId, 'stock.updated', 'artwork_stock', (string)$variantId, 'Artwork stock updated.');
        return $variantId;
    }

    /** @return array<int,array<string,mixed>> */
    public function orders(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artist_site_orders WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 200');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function order(int $userId, int $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artist_site_orders WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) return null;
        $items = $this->pdo->prepare('SELECT * FROM artist_site_order_items WHERE order_id=? ORDER BY id');
        $items->execute([$orderId]);
        $order['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
        $shipping = json_decode((string)$order['shipping_json'], true);
        $order['shipping'] = is_array($shipping) ? $shipping : [];
        return $order;
    }

    public function updateOrder(int $userId, int $orderId, string $action): void
    {
        $driver = strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->pdo->beginTransaction();
        try {
            $sql = 'SELECT * FROM artist_site_orders WHERE id=? AND user_id=?' . ($driver === 'mysql' ? ' FOR UPDATE' : '');
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$orderId, $userId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) throw new RuntimeException('Order not found.');
            $currentStatus = (string)$order['order_status'];
            if (in_array($currentStatus, ['cancelled', 'completed'], true)) throw new RuntimeException('This order is already closed.');

            $nextStatus = $currentStatus;
            $paymentStatus = (string)$order['payment_status'];
            $event = '';
            $message = '';
            if ($action === 'confirm') {
                $nextStatus = 'confirmed';
                $event = 'order.confirmed';
                $message = 'Order ' . $order['public_number'] . ' confirmed.';
            } elseif ($action === 'paid') {
                $paymentStatus = 'paid';
                if (in_array($nextStatus, ['request_received', 'awaiting_payment'], true)) $nextStatus = 'confirmed';
                $event = 'order.paid';
                $message = 'Order ' . $order['public_number'] . ' marked as paid.';
            } elseif ($action === 'shipped') {
                if ($paymentStatus !== 'paid') throw new RuntimeException('Mark the order as paid before shipping it.');
                $nextStatus = 'shipped';
                $event = 'order.shipped';
                $message = 'Order ' . $order['public_number'] . ' marked as shipped.';
            } elseif ($action === 'complete') {
                if ($paymentStatus !== 'paid') throw new RuntimeException('Mark the order as paid before completing it.');
                $this->settleReservedStock($orderId, $userId, true, $driver);
                $nextStatus = 'completed';
                $event = 'order.completed';
                $message = 'Order ' . $order['public_number'] . ' completed and stock sold.';
            } elseif ($action === 'cancel') {
                $this->settleReservedStock($orderId, $userId, false, $driver);
                $nextStatus = 'cancelled';
                $event = 'order.cancelled';
                $message = 'Order ' . $order['public_number'] . ' cancelled and stock released.';
            } else {
                throw new RuntimeException('Unknown order action.');
            }

            $now = date('c');
            $this->pdo->prepare('UPDATE artist_site_orders SET payment_status=?,order_status=?,updated_at=? WHERE id=? AND user_id=?')
                ->execute([$paymentStatus, $nextStatus, $now, $orderId, $userId]);
            $this->pdo->prepare('INSERT INTO artist_site_activity (user_id,event_type,entity_type,entity_id,message,created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$userId, $event, 'order', (string)$orderId, $message, $now]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function settleReservedStock(int $orderId, int $userId, bool $consume, string $driver): void
    {
        $items = $this->pdo->prepare('SELECT print_variant_id,quantity FROM artist_site_order_items WHERE order_id=?');
        $items->execute([$orderId]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $variantId = (int)$item['print_variant_id'];
            $quantity = max(1, (int)$item['quantity']);
            $lockSql = 'SELECT stock_on_hand,stock_reserved FROM artist_site_print_variants WHERE id=? AND user_id=?' . ($driver === 'mysql' ? ' FOR UPDATE' : '');
            $lock = $this->pdo->prepare($lockSql);
            $lock->execute([$variantId, $userId]);
            $stock = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$stock) throw new RuntimeException('Reserved stock record not found.');
            if ((int)$stock['stock_reserved'] < $quantity) throw new RuntimeException('Reserved stock is inconsistent for this order.');
            $stockOnHand = max(0, (int)$stock['stock_on_hand'] - ($consume ? $quantity : 0));
            $stockReserved = max(0, (int)$stock['stock_reserved'] - $quantity);
            $status = $consume && $stockOnHand === 0 ? 'sold_out' : null;
            if ($status !== null) {
                $this->pdo->prepare('UPDATE artist_site_print_variants SET stock_on_hand=?,stock_reserved=?,status=?,updated_at=? WHERE id=? AND user_id=?')
                    ->execute([$stockOnHand, $stockReserved, $status, date('c'), $variantId, $userId]);
            } else {
                $this->pdo->prepare('UPDATE artist_site_print_variants SET stock_on_hand=?,stock_reserved=?,updated_at=? WHERE id=? AND user_id=?')
                    ->execute([$stockOnHand, $stockReserved, date('c'), $variantId, $userId]);
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function activity(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artist_site_activity WHERE user_id=? ORDER BY id DESC LIMIT 100');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function log(int $userId, string $eventType, string $entityType, string $entityId, string $message): void
    {
        $this->pdo->prepare('INSERT INTO artist_site_activity (user_id,event_type,entity_type,entity_id,message,created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, $eventType, $entityType, $entityId, $message, date('c')]);
    }
}
