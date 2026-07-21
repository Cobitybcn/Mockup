<?php
declare(strict_types=1);

final class AppStore
{
    private const DEFAULT_RATE_MINOR = 25000;

    /** @var array<string,string> */
    private const CONTINENTS = [
        'europe' => 'Europe',
        'africa' => 'Africa',
        'asia' => 'Asia',
        'north_america' => 'North America',
        'south_america' => 'South America',
        'oceania' => 'Oceania',
    ];

    /** @var array<string,array<string,string>> */
    private const COUNTRIES = [
        'europe' => [
            'AL'=>'Albania','AD'=>'Andorra','AM'=>'Armenia','AT'=>'Austria','AZ'=>'Azerbaijan','BY'=>'Belarus','BE'=>'Belgium','BA'=>'Bosnia and Herzegovina','BG'=>'Bulgaria','HR'=>'Croatia','CY'=>'Cyprus','CZ'=>'Czechia','DK'=>'Denmark','EE'=>'Estonia','FI'=>'Finland','FR'=>'France','GE'=>'Georgia','DE'=>'Germany','GR'=>'Greece','HU'=>'Hungary','IS'=>'Iceland','IE'=>'Ireland','IT'=>'Italy','XK'=>'Kosovo','LV'=>'Latvia','LI'=>'Liechtenstein','LT'=>'Lithuania','LU'=>'Luxembourg','MT'=>'Malta','MD'=>'Moldova','MC'=>'Monaco','ME'=>'Montenegro','NL'=>'Netherlands','MK'=>'North Macedonia','NO'=>'Norway','PL'=>'Poland','PT'=>'Portugal','RO'=>'Romania','RU'=>'Russia','SM'=>'San Marino','RS'=>'Serbia','SK'=>'Slovakia','SI'=>'Slovenia','ES'=>'Spain','SE'=>'Sweden','CH'=>'Switzerland','TR'=>'Türkiye','UA'=>'Ukraine','GB'=>'United Kingdom','VA'=>'Vatican City',
        ],
        'africa' => [
            'DZ'=>'Algeria','AO'=>'Angola','BJ'=>'Benin','BW'=>'Botswana','BF'=>'Burkina Faso','BI'=>'Burundi','CV'=>'Cabo Verde','CM'=>'Cameroon','CF'=>'Central African Republic','TD'=>'Chad','KM'=>'Comoros','CG'=>'Congo','CD'=>'Congo, Democratic Republic','CI'=>'Côte d’Ivoire','DJ'=>'Djibouti','EG'=>'Egypt','GQ'=>'Equatorial Guinea','ER'=>'Eritrea','SZ'=>'Eswatini','ET'=>'Ethiopia','GA'=>'Gabon','GM'=>'Gambia','GH'=>'Ghana','GN'=>'Guinea','GW'=>'Guinea-Bissau','KE'=>'Kenya','LS'=>'Lesotho','LR'=>'Liberia','LY'=>'Libya','MG'=>'Madagascar','MW'=>'Malawi','ML'=>'Mali','MR'=>'Mauritania','MU'=>'Mauritius','MA'=>'Morocco','MZ'=>'Mozambique','NA'=>'Namibia','NE'=>'Niger','NG'=>'Nigeria','RW'=>'Rwanda','ST'=>'São Tomé and Príncipe','SN'=>'Senegal','SC'=>'Seychelles','SL'=>'Sierra Leone','SO'=>'Somalia','ZA'=>'South Africa','SS'=>'South Sudan','SD'=>'Sudan','TZ'=>'Tanzania','TG'=>'Togo','TN'=>'Tunisia','UG'=>'Uganda','ZM'=>'Zambia','ZW'=>'Zimbabwe',
        ],
        'asia' => [
            'AF'=>'Afghanistan','BH'=>'Bahrain','BD'=>'Bangladesh','BT'=>'Bhutan','BN'=>'Brunei','KH'=>'Cambodia','CN'=>'China','IN'=>'India','ID'=>'Indonesia','IR'=>'Iran','IQ'=>'Iraq','IL'=>'Israel','JP'=>'Japan','JO'=>'Jordan','KZ'=>'Kazakhstan','KW'=>'Kuwait','KG'=>'Kyrgyzstan','LA'=>'Laos','LB'=>'Lebanon','MY'=>'Malaysia','MV'=>'Maldives','MN'=>'Mongolia','MM'=>'Myanmar','NP'=>'Nepal','KP'=>'North Korea','OM'=>'Oman','PK'=>'Pakistan','PS'=>'Palestine','PH'=>'Philippines','QA'=>'Qatar','SA'=>'Saudi Arabia','SG'=>'Singapore','KR'=>'South Korea','LK'=>'Sri Lanka','SY'=>'Syria','TW'=>'Taiwan','TJ'=>'Tajikistan','TH'=>'Thailand','TL'=>'Timor-Leste','TM'=>'Turkmenistan','AE'=>'United Arab Emirates','UZ'=>'Uzbekistan','VN'=>'Vietnam','YE'=>'Yemen',
        ],
        'north_america' => [
            'AG'=>'Antigua and Barbuda','BS'=>'Bahamas','BB'=>'Barbados','BZ'=>'Belize','CA'=>'Canada','CR'=>'Costa Rica','CU'=>'Cuba','DM'=>'Dominica','DO'=>'Dominican Republic','SV'=>'El Salvador','GD'=>'Grenada','GT'=>'Guatemala','HT'=>'Haiti','HN'=>'Honduras','JM'=>'Jamaica','MX'=>'Mexico','NI'=>'Nicaragua','PA'=>'Panama','KN'=>'Saint Kitts and Nevis','LC'=>'Saint Lucia','VC'=>'Saint Vincent and the Grenadines','TT'=>'Trinidad and Tobago','US'=>'United States',
        ],
        'south_america' => [
            'AR'=>'Argentina','BO'=>'Bolivia','BR'=>'Brazil','CL'=>'Chile','CO'=>'Colombia','EC'=>'Ecuador','GY'=>'Guyana','PY'=>'Paraguay','PE'=>'Peru','SR'=>'Suriname','UY'=>'Uruguay','VE'=>'Venezuela',
        ],
        'oceania' => [
            'AU'=>'Australia','FJ'=>'Fiji','KI'=>'Kiribati','MH'=>'Marshall Islands','FM'=>'Micronesia','NR'=>'Nauru','NZ'=>'New Zealand','PW'=>'Palau','PG'=>'Papua New Guinea','WS'=>'Samoa','SB'=>'Solomon Islands','TO'=>'Tonga','TV'=>'Tuvalu','VU'=>'Vanuatu',
        ],
    ];

    public function __construct(private readonly PDO $pdo, private readonly string $artistEmail) {}

    public static function fromApp(string $appRoot, string $artistEmail): self
    {
        return new self(artist_site_database_connection($appRoot), strtolower(trim($artistEmail)));
    }

    /** @return array<string,string> */
    public static function continents(): array
    {
        return self::CONTINENTS;
    }

    /** @return array<string,array<string,string>> */
    public static function countries(): array
    {
        return self::COUNTRIES;
    }

    /** @return array{continent:string,continent_label:string,country_code:string,country_name:string}|null */
    public static function destination(string $countryCode): ?array
    {
        $countryCode = strtoupper(trim($countryCode));
        foreach (self::COUNTRIES as $continent => $countries) {
            if (!isset($countries[$countryCode])) continue;
            return [
                'continent' => $continent,
                'continent_label' => self::CONTINENTS[$continent],
                'country_code' => $countryCode,
                'country_name' => $countries[$countryCode],
            ];
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public function offerForArtwork(int $artworkId): ?array
    {
        if ($artworkId <= 0) return null;
        try {
            $stmt = $this->pdo->prepare("SELECT v.*,u.email artist_email,
                    COALESCE(s.shipping_rates_json,'') shipping_rates_json,
                    COALESCE(s.shipping_policy,'') shipping_policy,
                    COALESCE(s.currency,v.currency) store_currency,
                    COALESCE(s.payment_provider,'') payment_provider,
                    COALESCE(pc.external_account_id,'') stripe_account_id,
                    COALESCE(pc.connection_status,'not_connected') connection_status,
                    COALESCE(pc.charges_enabled,0) charges_enabled,
                    COALESCE(pc.payouts_enabled,0) payouts_enabled,
                    COALESCE(pc.livemode,0) stripe_livemode
                FROM artist_site_print_variants v
                INNER JOIN users u ON u.id=v.user_id
                LEFT JOIN artist_site_settings s ON s.user_id=v.user_id
                LEFT JOIN artist_site_payment_connections pc ON pc.user_id=v.user_id AND pc.provider='stripe'
                WHERE LOWER(u.email)=? AND v.artwork_id=?
                ORDER BY CASE v.status WHEN 'active' THEN 0 WHEN 'sold_out' THEN 1 WHEN 'paused' THEN 2 ELSE 3 END,v.id
                LIMIT 1");
            $stmt->execute([$this->artistEmail, $artworkId]);
            $offer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$offer) return null;
            $offer['stock_available'] = max(0, (int)$offer['stock_on_hand'] - (int)$offer['stock_reserved']);
            $offer['shipping_rates'] = $this->shippingRates((string)$offer['shipping_rates_json']);
            $offer['is_purchasable'] = (string)$offer['status'] === 'active'
                && (int)$offer['price_minor'] > 0
                && (int)$offer['stock_available'] > 0
                && strtoupper((string)$offer['currency']) === strtoupper((string)$offer['store_currency']);
            return $offer;
        } catch (PDOException) {
            return null;
        }
    }

    /** @return array<string,int> */
    private function shippingRates(string $json): array
    {
        $decoded = json_decode($json, true);
        $rates = [];
        foreach (self::CONTINENTS as $key => $_label) {
            $rates[$key] = max(0, (int)(is_array($decoded) ? ($decoded[$key] ?? self::DEFAULT_RATE_MINOR) : self::DEFAULT_RATE_MINOR));
        }
        return $rates;
    }

    /**
     * @param array<string,mixed> $artwork
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createOrder(array $artwork, array $input): array
    {
        $artworkId = (int)($artwork['canonical_artwork_id'] ?? 0);
        $offer = $this->offerForArtwork($artworkId);
        if (!$offer || empty($offer['is_purchasable'])) throw new RuntimeException('This artwork is not currently available for acquisition.');

        $name = $this->requiredText($input, 'name', 'Enter your name.', 255);
        $email = strtolower($this->requiredText($input, 'email', 'Enter your email address.', 255));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
        $destination = self::destination((string)($input['country_code'] ?? ''));
        if (!$destination) throw new RuntimeException('Select a destination country.');
        $addressLine1 = $this->requiredText($input, 'address_line_1', 'Enter the delivery address.', 255);
        $city = $this->requiredText($input, 'city', 'Enter the destination city.', 160);
        $addressLine2 = $this->optionalText($input, 'address_line_2', 255);
        $region = $this->optionalText($input, 'region', 160);
        $postalCode = $this->optionalText($input, 'postal_code', 40);
        $phone = $this->optionalText($input, 'phone', 80);
        $message = $this->optionalText($input, 'message', 2000);

        $driver = strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->pdo->beginTransaction();
        try {
            $published = $this->pdo->prepare("SELECT 1
                FROM publications p
                INNER JOIN artwork_sheets sh ON sh.id=p.artwork_sheet_id AND sh.user_id=p.user_id
                WHERE p.user_id=? AND sh.canonical_artwork_id=? AND p.status='published' AND p.visibility IN ('public','unlisted')
                LIMIT 1");
            $published->execute([(int)$offer['user_id'], $artworkId]);
            if (!$published->fetchColumn()) throw new RuntimeException('This artwork is no longer published for acquisition.');

            $lockSql = 'SELECT * FROM artist_site_print_variants WHERE id=? AND user_id=? AND artwork_id=?' . ($driver === 'mysql' ? ' FOR UPDATE' : '');
            $lock = $this->pdo->prepare($lockSql);
            $lock->execute([(int)$offer['id'], (int)$offer['user_id'], $artworkId]);
            $variant = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$variant || (string)$variant['status'] !== 'active' || (int)$variant['price_minor'] <= 0
                || ((int)$variant['stock_on_hand'] - (int)$variant['stock_reserved']) < 1) {
                throw new RuntimeException('This artwork has just become unavailable.');
            }
            if (strtoupper((string)$variant['currency']) !== strtoupper((string)$offer['store_currency'])) {
                throw new RuntimeException('The artwork and shipping currencies must match before an order can be created.');
            }

            $settings = $this->pdo->prepare('SELECT shipping_rates_json,shipping_policy FROM artist_site_settings WHERE user_id=? LIMIT 1');
            $settings->execute([(int)$offer['user_id']]);
            $shippingSettings = $settings->fetch(PDO::FETCH_ASSOC) ?: [];
            $rates = $this->shippingRates((string)($shippingSettings['shipping_rates_json'] ?? ''));
            $shippingMinor = (int)$rates[$destination['continent']];
            $subtotalMinor = (int)$variant['price_minor'];
            $totalMinor = $subtotalMinor + $shippingMinor;
            $publicNumber = 'AM-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6)));
            $now = date('c');
            $shipping = $destination + [
                'address_line_1' => $addressLine1,
                'address_line_2' => $addressLine2,
                'city' => $city,
                'region' => $region,
                'postal_code' => $postalCode,
                'phone' => $phone,
                'message' => $message,
                'rate_minor' => $shippingMinor,
            ];

            $insertOrder = $this->pdo->prepare('INSERT INTO artist_site_orders
                (user_id,public_number,customer_name,customer_email,payment_status,order_status,currency,subtotal_minor,shipping_minor,tax_minor,total_minor,provider_reference,shipping_json,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $insertOrder->execute([
                (int)$offer['user_id'], $publicNumber, $name, $email, 'pending', 'request_received',
                strtoupper((string)$variant['currency']), $subtotalMinor, $shippingMinor, 0, $totalMinor, '',
                json_encode($shipping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', $now, $now,
            ]);
            $orderId = (int)$this->pdo->lastInsertId();

            $snapshot = [
                'artwork_slug' => (string)($artwork['slug'] ?? ''),
                'artwork_title' => (string)($artwork['title'] ?? ''),
                'variant_title' => (string)$variant['title'],
                'size_label' => (string)$variant['size_label'],
                'support' => (string)$variant['support'],
                'finish' => (string)$variant['finish'],
                'currency' => strtoupper((string)$variant['currency']),
                'unit_price_minor' => $subtotalMinor,
                'shipping_minor' => $shippingMinor,
            ];
            $this->pdo->prepare('INSERT INTO artist_site_order_items
                (order_id,print_variant_id,artwork_id,title,sku,variant_snapshot_json,quantity,unit_price_minor,total_minor)
                VALUES (?,?,?,?,?,?,?,?,?)')->execute([
                    $orderId, (int)$variant['id'], $artworkId, (string)($artwork['title'] ?? $variant['title']),
                    (string)$variant['sku'], json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                    1, $subtotalMinor, $subtotalMinor,
                ]);

            $reserve = $this->pdo->prepare("UPDATE artist_site_print_variants
                SET stock_reserved=stock_reserved+1,updated_at=?
                WHERE id=? AND user_id=? AND status='active' AND (stock_on_hand-stock_reserved)>=1");
            $reserve->execute([$now, (int)$variant['id'], (int)$offer['user_id']]);
            if ($reserve->rowCount() !== 1) throw new RuntimeException('This artwork has just become unavailable.');

            $this->pdo->prepare('INSERT INTO artist_site_activity (user_id,event_type,entity_type,entity_id,message,created_at) VALUES (?,?,?,?,?,?)')
                ->execute([(int)$offer['user_id'], 'order.created', 'order', (string)$orderId, 'Acquisition request ' . $publicNumber . ' received.', $now]);
            $this->pdo->commit();

            return [
                'id' => $orderId,
                'user_id' => (int)$offer['user_id'],
                'public_number' => $publicNumber,
                'customer_name' => $name,
                'customer_email' => $email,
                'artwork_title' => (string)($artwork['title'] ?? ''),
                'artwork_slug' => (string)($artwork['slug'] ?? ''),
                'currency' => strtoupper((string)$variant['currency']),
                'subtotal_minor' => $subtotalMinor,
                'shipping_minor' => $shippingMinor,
                'total_minor' => $totalMinor,
                'destination' => $destination,
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $site */
    public function notifyOrder(array $order, array $site): void
    {
        $artistEmail = filter_var((string)($site['email'] ?? ''), FILTER_VALIDATE_EMAIL)
            ? (string)$site['email']
            : $this->artistEmail;
        $customerEmail = (string)$order['customer_email'];
        $number = (string)$order['public_number'];
        $currency = (string)$order['currency'];
        $total = self::money((int)$order['total_minor'], $currency);
        $headersForArtist = [
            'From: ' . $artistEmail,
            'Reply-To: ' . $customerEmail,
            'Content-Type: text/plain; charset=UTF-8',
        ];
        $artistBody = "New acquisition request\n\nOrder: {$number}\nArtwork: {$order['artwork_title']}\nCollector: {$order['customer_name']}\nEmail: {$customerEmail}\nDestination: {$order['destination']['country_name']}\nTotal: {$total}\n";
        @mail($artistEmail, '[Artist Website] Acquisition request ' . $number, $artistBody, implode("\r\n", $headersForArtist));

        $headersForCustomer = [
            'From: ' . $artistEmail,
            'Reply-To: ' . $artistEmail,
            'Content-Type: text/plain; charset=UTF-8',
        ];
        $customerBody = "Thank you, {$order['customer_name']}.\n\nWe received your acquisition request for {$order['artwork_title']}.\nReference: {$number}\nTotal: {$total}\n\nThe studio will contact you to confirm the order and payment.\n";
        @mail($customerEmail, 'Acquisition request ' . $number, $customerBody, implode("\r\n", $headersForCustomer));
    }

    public static function money(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2, '.', ',') . ' ' . strtoupper($currency);
    }

    /** @param array<string,mixed> $input */
    private function requiredText(array $input, string $key, string $message, int $max): string
    {
        $value = $this->optionalText($input, $key, $max);
        if ($value === '') throw new RuntimeException($message);
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalText(array $input, string $key, int $max): string
    {
        $value = trim((string)($input[$key] ?? ''));
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }
}
