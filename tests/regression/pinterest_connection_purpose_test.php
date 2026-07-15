<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Services/PinterestPublisher.php';
require_once __DIR__ . '/../../app/Services/PinterestIntegrationService.php';

$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,is_admin INTEGER NOT NULL DEFAULT 0)');
$pdo->exec("CREATE TABLE pinterest_connections (id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,purpose TEXT NOT NULL,pinterest_account_id TEXT NOT NULL,scopes TEXT NOT NULL,status TEXT NOT NULL,connected_at TEXT,access_token_expires_at TEXT,UNIQUE(user_id,purpose))");
$pdo->exec("INSERT INTO users VALUES (1,1),(2,0)");
$pdo->exec("INSERT INTO pinterest_connections VALUES (1,1,'platform','artworkmockups','pins:write','connected','2026-01-01','2027-01-01'),(2,1,'artist','maurizio','pins:write','connected','2026-01-01','2027-01-01'),(3,2,'artist','another_artist','pins:write','connected','2026-01-01','2027-01-01')");
$service=new PinterestIntegrationService($pdo);
$checks=[
    in_array('user_accounts:read',PinterestPublisher::requiredScopes(),true),
    ($service->connection(1,'platform')['pinterest_account_id']??'')==='artworkmockups',
    ($service->connection(1,'artist')['pinterest_account_id']??'')==='maurizio',
    ($service->connection(2,'artist')['pinterest_account_id']??'')==='another_artist',
];
$blocked=false;try{$service->disconnect(2,'platform');}catch(RuntimeException){$blocked=true;}$checks[]=$blocked;
if(in_array(false,$checks,true)){fwrite(STDERR,"FAIL: Pinterest connection purposes.\n");exit(1);}echo "PASS: platform and artist Pinterest connections remain separate; platform is admin-only.\n";
