<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$direction = $argv[1] ?? 'up'; $pdo = Database::connection(); $mysql = Database::isMysql();
if ($direction === 'down') { $pdo->exec('DROP TABLE IF EXISTS pinterest_connections'); $pdo->exec('DROP TABLE IF EXISTS contact_messages'); echo "Migration rolled back.\n"; exit(0); }
if ($mysql) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pinterest_connections (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,purpose VARCHAR(20) NOT NULL DEFAULT 'artist',pinterest_account_id VARCHAR(190) NOT NULL,access_token_encrypted MEDIUMTEXT NULL,refresh_token_encrypted MEDIUMTEXT NULL,access_token_expires_at VARCHAR(40) NULL,refresh_token_expires_at VARCHAR(40) NULL,scopes TEXT NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'pending',connected_at VARCHAR(40) NULL,disconnected_at VARCHAR(40) NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,UNIQUE KEY uq_pinterest_connections_user_purpose(user_id,purpose),CONSTRAINT pinterest_connections_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_messages (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,email VARCHAR(254) NOT NULL,subject VARCHAR(80) NOT NULL,message MEDIUMTEXT NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'new',created_at VARCHAR(40) NOT NULL,KEY idx_contact_status_created(status,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pinterest_connections (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,purpose TEXT NOT NULL DEFAULT 'artist',pinterest_account_id TEXT NOT NULL,access_token_encrypted TEXT,refresh_token_encrypted TEXT,access_token_expires_at TEXT,refresh_token_expires_at TEXT,scopes TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'pending',connected_at TEXT,disconnected_at TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,UNIQUE(user_id,purpose))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_messages (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,email TEXT NOT NULL,subject TEXT NOT NULL,message TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'new',created_at TEXT NOT NULL)");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_contact_status_created ON contact_messages(status,created_at)');
}
echo "Migration applied. Token columns exist but must remain empty until authenticated encryption is implemented.\n";
