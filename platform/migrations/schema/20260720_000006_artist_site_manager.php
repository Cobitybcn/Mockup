<?php
declare(strict_types=1);

return [
    'description' => 'Create the per-artist website management, stock, order, and activity tables',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $integer = $mysql ? 'INT UNSIGNED' : 'INTEGER';
        $long = $mysql ? 'LONGTEXT' : 'TEXT';

        $pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_settings (
            id {$id}, user_id {$integer} NOT NULL UNIQUE,
            site_title VARCHAR(255) NOT NULL DEFAULT '', tagline VARCHAR(255) NOT NULL DEFAULT '',
            locale VARCHAR(20) NOT NULL DEFAULT 'en', site_status VARCHAR(30) NOT NULL DEFAULT 'draft',
            contact_email VARCHAR(255) NOT NULL DEFAULT '', inquiry_intro {$long} NOT NULL,
            currency VARCHAR(3) NOT NULL DEFAULT 'EUR', payment_provider VARCHAR(40) NOT NULL DEFAULT '',
            payment_status VARCHAR(30) NOT NULL DEFAULT 'not_connected',
            shipping_regions {$long} NOT NULL, shipping_policy {$long} NOT NULL,
            created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_constellations (
            id {$id}, user_id {$integer} NOT NULL, artwork_id {$integer} NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 0, country VARCHAR(120) NOT NULL DEFAULT '',
            region VARCHAR(160) NOT NULL DEFAULT '', city VARCHAR(160) NOT NULL DEFAULT '',
            postal_code VARCHAR(40) NOT NULL DEFAULT '', latitude VARCHAR(40) NOT NULL DEFAULT '',
            longitude VARCHAR(40) NOT NULL DEFAULT '', privacy VARCHAR(30) NOT NULL DEFAULT 'country',
            public_note {$long} NOT NULL, created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL,
            UNIQUE(user_id, artwork_id)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_print_variants (
            id {$id}, user_id {$integer} NOT NULL, artwork_id {$integer} NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '', sku VARCHAR(120) NOT NULL DEFAULT '',
            size_label VARCHAR(120) NOT NULL DEFAULT '', support VARCHAR(120) NOT NULL DEFAULT '',
            finish VARCHAR(120) NOT NULL DEFAULT '', inventory_mode VARCHAR(30) NOT NULL DEFAULT 'in_stock',
            edition_size INTEGER NOT NULL DEFAULT 0, stock_on_hand INTEGER NOT NULL DEFAULT 0,
            stock_reserved INTEGER NOT NULL DEFAULT 0, price_minor INTEGER NOT NULL DEFAULT 0,
            currency VARCHAR(3) NOT NULL DEFAULT 'EUR', status VARCHAR(30) NOT NULL DEFAULT 'draft',
            created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_orders (
            id {$id}, user_id {$integer} NOT NULL, public_number VARCHAR(80) NOT NULL,
            customer_name VARCHAR(255) NOT NULL DEFAULT '', customer_email VARCHAR(255) NOT NULL DEFAULT '',
            payment_status VARCHAR(30) NOT NULL DEFAULT 'pending', order_status VARCHAR(30) NOT NULL DEFAULT 'awaiting_payment',
            currency VARCHAR(3) NOT NULL DEFAULT 'EUR', subtotal_minor INTEGER NOT NULL DEFAULT 0,
            shipping_minor INTEGER NOT NULL DEFAULT 0, tax_minor INTEGER NOT NULL DEFAULT 0,
            total_minor INTEGER NOT NULL DEFAULT 0, provider_reference VARCHAR(255) NOT NULL DEFAULT '',
            shipping_json {$long} NOT NULL, created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL,
            UNIQUE(user_id, public_number)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_order_items (
            id {$id}, order_id {$integer} NOT NULL, print_variant_id {$integer} NOT NULL,
            artwork_id {$integer} NOT NULL, title VARCHAR(255) NOT NULL DEFAULT '',
            sku VARCHAR(120) NOT NULL DEFAULT '', variant_snapshot_json {$long} NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 1, unit_price_minor INTEGER NOT NULL DEFAULT 0,
            total_minor INTEGER NOT NULL DEFAULT 0
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_activity (
            id {$id}, user_id {$integer} NOT NULL, event_type VARCHAR(60) NOT NULL,
            entity_type VARCHAR(60) NOT NULL DEFAULT '', entity_id VARCHAR(80) NOT NULL DEFAULT '',
            message {$long} NOT NULL, created_at VARCHAR(40) NOT NULL
        )");
    },
];
