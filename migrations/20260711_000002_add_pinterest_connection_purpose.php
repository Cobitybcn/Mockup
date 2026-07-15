<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$direction=$argv[1]??'up';$pdo=Database::connection();$mysql=Database::isMysql();
if($direction==='up'){
    if($mysql){
        $column=$pdo->query("SHOW COLUMNS FROM pinterest_connections LIKE 'purpose'")->fetchColumn();
        if(!$column){$pdo->exec("ALTER TABLE pinterest_connections ADD COLUMN purpose VARCHAR(20) NOT NULL DEFAULT 'artist' AFTER user_id");$pdo->exec("UPDATE pinterest_connections pc INNER JOIN users u ON u.id=pc.user_id SET pc.purpose='platform' WHERE u.is_admin=1");}
        $indexes=$pdo->query("SHOW INDEX FROM pinterest_connections")->fetchAll(PDO::FETCH_ASSOC);$names=[];foreach($indexes as $index)$names[(string)$index['Key_name']]=true;
        if(!isset($names['uq_pinterest_connections_user_purpose']))$pdo->exec('ALTER TABLE pinterest_connections ADD UNIQUE KEY uq_pinterest_connections_user_purpose (user_id,purpose)');
        if(isset($names['uq_pinterest_connections_user']))$pdo->exec('ALTER TABLE pinterest_connections DROP INDEX uq_pinterest_connections_user');
    }else{
        $columns=$pdo->query('PRAGMA table_info(pinterest_connections)')->fetchAll(PDO::FETCH_ASSOC);$has=false;foreach($columns as $column)if(($column['name']??'')==='purpose')$has=true;
        if(!$has){
            $pdo->beginTransaction();try{
                $pdo->exec("CREATE TABLE pinterest_connections_new (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,purpose TEXT NOT NULL DEFAULT 'artist',pinterest_account_id TEXT NOT NULL,access_token_encrypted TEXT,refresh_token_encrypted TEXT,access_token_expires_at TEXT,refresh_token_expires_at TEXT,scopes TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'pending',connected_at TEXT,disconnected_at TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,UNIQUE(user_id,purpose))");
                $pdo->exec("INSERT INTO pinterest_connections_new (id,user_id,purpose,pinterest_account_id,access_token_encrypted,refresh_token_encrypted,access_token_expires_at,refresh_token_expires_at,scopes,status,connected_at,disconnected_at,created_at,updated_at) SELECT pc.id,pc.user_id,CASE WHEN COALESCE(u.is_admin,0)=1 THEN 'platform' ELSE 'artist' END,pc.pinterest_account_id,pc.access_token_encrypted,pc.refresh_token_encrypted,pc.access_token_expires_at,pc.refresh_token_expires_at,pc.scopes,pc.status,pc.connected_at,pc.disconnected_at,pc.created_at,pc.updated_at FROM pinterest_connections pc LEFT JOIN users u ON u.id=pc.user_id");
                $pdo->exec('DROP TABLE pinterest_connections');$pdo->exec('ALTER TABLE pinterest_connections_new RENAME TO pinterest_connections');$pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }
    }
    echo "Pinterest connection purposes added. Existing administrator connections are platform connections; other existing connections are artist connections.\n";exit;
}
if($direction==='down'){
    $duplicates=(int)$pdo->query('SELECT COUNT(*) FROM (SELECT user_id FROM pinterest_connections GROUP BY user_id HAVING COUNT(*)>1) duplicate_users')->fetchColumn();
    if($duplicates>0)throw new RuntimeException('Cannot roll back while a user has both artist and platform connections. Disconnect one first.');
    if($mysql){$pdo->exec('ALTER TABLE pinterest_connections DROP INDEX uq_pinterest_connections_user_purpose');$pdo->exec('ALTER TABLE pinterest_connections DROP COLUMN purpose');$pdo->exec('ALTER TABLE pinterest_connections ADD UNIQUE KEY uq_pinterest_connections_user (user_id)');}
    else{
        $pdo->beginTransaction();try{
            $pdo->exec("CREATE TABLE pinterest_connections_old (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL UNIQUE,pinterest_account_id TEXT NOT NULL,access_token_encrypted TEXT,refresh_token_encrypted TEXT,access_token_expires_at TEXT,refresh_token_expires_at TEXT,scopes TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'pending',connected_at TEXT,disconnected_at TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
            $pdo->exec('INSERT INTO pinterest_connections_old (id,user_id,pinterest_account_id,access_token_encrypted,refresh_token_encrypted,access_token_expires_at,refresh_token_expires_at,scopes,status,connected_at,disconnected_at,created_at,updated_at) SELECT id,user_id,pinterest_account_id,access_token_encrypted,refresh_token_encrypted,access_token_expires_at,refresh_token_expires_at,scopes,status,connected_at,disconnected_at,created_at,updated_at FROM pinterest_connections');
            $pdo->exec('DROP TABLE pinterest_connections');$pdo->exec('ALTER TABLE pinterest_connections_old RENAME TO pinterest_connections');$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    echo "Pinterest connection purposes removed.\n";exit;
}
throw new InvalidArgumentException('Use up or down.');
