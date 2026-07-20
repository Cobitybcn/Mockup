<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../app/Support/Auth.php';
require_once __DIR__ . '/../../app/Services/PinterestPublisher.php';
require_once __DIR__ . '/../../app/Services/PinterestIntegrationService.php';

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,is_admin INTEGER NOT NULL DEFAULT 0)');
$pdo->exec("CREATE TABLE pinterest_connections (id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,purpose TEXT NOT NULL,pinterest_account_id TEXT NOT NULL,access_token_encrypted TEXT,refresh_token_encrypted TEXT,access_token_expires_at TEXT,refresh_token_expires_at TEXT,scopes TEXT NOT NULL,status TEXT NOT NULL,connected_at TEXT,disconnected_at TEXT,created_at TEXT,updated_at TEXT,UNIQUE(user_id,purpose))");
$pdo->exec("CREATE TABLE pinterest_artist_apps (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL UNIQUE,app_id TEXT NOT NULL,app_secret_encrypted TEXT NOT NULL,api_environment TEXT NOT NULL DEFAULT 'production',created_at TEXT NOT NULL,updated_at TEXT NOT NULL)");
$pdo->exec('INSERT INTO users VALUES (1,1),(2,0)');

$APP_ENV_VALUES['PINTEREST_API_ENVIRONMENT']='sandbox';
$APP_ENV_VALUES['PINTEREST_SANDBOX_TOKEN']='sandbox-platform-token';
$APP_ENV_VALUES['PINTEREST_APP_ID']='1589233';
$APP_ENV_VALUES['PINTEREST_APP_SECRET']='official-secret';
$APP_ENV_VALUES['PINTEREST_REDIRECT_URI']='https://artworkmockups.com/integrations/pinterest/callback';
$APP_ENV_VALUES['PINTEREST_TOKEN_KEY']=base64_encode(str_repeat('k',SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

$service=new PinterestIntegrationService($pdo);
$store=new ReflectionMethod(PinterestIntegrationService::class,'store');
$store->invoke($service,1,'platform',[
    'access_token'=>'platform-oauth-token','refresh_token'=>'platform-refresh-token','scope'=>implode(' ',PinterestPublisher::requiredScopes()),
],'artworkmockups','Artwork Mockups');
$store->invoke($service,2,'artist',[
    'access_token'=>'artist-oauth-token','refresh_token'=>'artist-refresh-token','scope'=>implode(' ',PinterestPublisher::requiredScopes()),
],'mauriziovalch','Maurizio Valch');

$checks=[
    ($service->connection(1,'platform')['pinterest_account_id']??'')==='artworkmockups',
    ($service->connection(2,'artist')['pinterest_account_id']??'')==='mauriziovalch',
    $service->isPublishingReady(1,'platform'),
    $service->isPublishingReady(2,'artist'),
];

$blocked=false;
try{$service->disconnect(2,'platform');}catch(RuntimeException){$blocked=true;}
$checks[]=$blocked;

$configMethod=new ReflectionMethod(PinterestIntegrationService::class,'config');
$artistConfig=$configMethod->invoke($service,2,'artist');
$platformConfig=$configMethod->invoke($service,1,'platform');
$checks[]=$artistConfig['app_id']==='1589233'&&$artistConfig['source']==='official';
$checks[]=$platformConfig['app_id']==='1589233'&&$platformConfig['source']==='official';

$readCredentials=new ReflectionMethod(PinterestIntegrationService::class,'boardReadCredentials');
$checks[]=$readCredentials->invoke($service,1,'platform')===['sandbox-platform-token','https://api-sandbox.pinterest.com/v5'];
$checks[]=$readCredentials->invoke($service,2,'artist')===['artist-oauth-token','https://api-sandbox.pinterest.com/v5'];

if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$url=$service->authorizationUrl(2,'artist');
$checks[]=str_contains($url,'client_id=1589233');
$checks[]=str_contains($url,'pins%3Awrite')&&str_contains($url,'boards%3Awrite');

$pdo->prepare("UPDATE pinterest_connections SET scopes='manual_token' WHERE user_id=2 AND purpose='artist'")->execute();
$checks[]=$service->isPublishingReady(2,'artist')===false;

if(in_array(false,$checks,true)){
    fwrite(STDERR,"FAIL: Pinterest official-app connection flow.\n");
    exit(1);
}
echo "PASS: artists authorize their own Pinterest accounts through the official Artwork Mockups app without entering developer credentials.\n";
