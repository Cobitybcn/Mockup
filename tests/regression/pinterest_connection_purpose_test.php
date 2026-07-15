<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
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

$APP_ENV_VALUES['PINTEREST_API_ENVIRONMENT']='sandbox';
$APP_ENV_VALUES['PINTEREST_SANDBOX_TOKEN']='sandbox-token';
$APP_ENV_VALUES['PINTEREST_PRODUCTION_READ_TOKEN']='production-read-token';
$APP_ENV_VALUES['PINTEREST_PRODUCTION_READ_USER_ID']='1';
$readCredentials=new ReflectionMethod(PinterestIntegrationService::class,'boardReadCredentials');
$adminArtist=$readCredentials->invoke($service,1,'artist');
$otherArtist=$readCredentials->invoke($service,2,'artist');
$adminPlatform=$readCredentials->invoke($service,1,'platform');
$checks[]=$adminArtist===['production-read-token','https://api.pinterest.com/v5'];
$checks[]=$otherArtist===['sandbox-token','https://api-sandbox.pinterest.com/v5'];
$checks[]=$adminPlatform===['sandbox-token','https://api-sandbox.pinterest.com/v5'];

if(in_array(false,$checks,true)){fwrite(STDERR,"FAIL: Pinterest connection purposes.\n");exit(1);}echo "PASS: platform and artist Pinterest connections remain separate; production-limited board reads are scoped to one artist admin and cannot replace publishing credentials.\n";
