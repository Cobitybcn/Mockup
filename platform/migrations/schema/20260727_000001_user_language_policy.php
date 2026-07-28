<?php
declare(strict_types=1);

return [
    'description' => 'Store the working and publication languages chosen by each artist',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        $now = date(DATE_ATOM);

        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_language_policy (
                user_id INT UNSIGNED NOT NULL,
                working_locale VARCHAR(12) NOT NULL,
                publication_locales_json VARCHAR(255) NOT NULL,
                interface_locale VARCHAR(12) NOT NULL DEFAULT '',
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (user_id),
                CONSTRAINT fk_user_language_policy_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_language_policy (
                user_id INTEGER NOT NULL PRIMARY KEY,
                working_locale TEXT NOT NULL,
                publication_locales_json TEXT NOT NULL,
                interface_locale TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )");
        }

        // Las cuentas que ya existen trabajaron siempre con espanol como master
        // editorial e ingles internacional como adaptacion. Se registra ese estado
        // de forma explicita para que ningun motor tenga que volver a suponerlo.
        $seed = $mysql
            ? "INSERT IGNORE INTO user_language_policy
                   (user_id, working_locale, publication_locales_json, interface_locale, created_at, updated_at)
               SELECT id, 'es', '[\"es\",\"en\"]', '', :created_at, :updated_at FROM users"
            : "INSERT OR IGNORE INTO user_language_policy
                   (user_id, working_locale, publication_locales_json, interface_locale, created_at, updated_at)
               SELECT id, 'es', '[\"es\",\"en\"]', '', :created_at, :updated_at FROM users";

        $stmt = $pdo->prepare($seed);
        $stmt->execute(['created_at' => $now, 'updated_at' => $now]);
    },
];
