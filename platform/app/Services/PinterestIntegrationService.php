<?php
declare(strict_types=1);

final class PinterestTransportException extends RuntimeException {}

final class PinterestIntegrationService
{
    private const OAUTH_URL = 'https://www.pinterest.com/oauth/';

    public function __construct(private PDO $pdo,private ?Closure $apiTransport=null) {}

    public function authorizationUrl(int $userId, string $purpose = 'artist'): string
    {
        Auth::start();
        $this->assertPurposeAllowed($userId,$purpose);
        $config=$this->config($userId,$purpose);
        $this->assertConfigured($config);
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $purpose=$this->purpose($purpose);
        $_SESSION['pinterest_oauth'] = [
            'hash' => hash('sha256', $state),
            'user_id' => $userId,
            'purpose'=>$purpose,
            'app_id'=>$config['app_id'],
            'api_environment'=>$config['api_environment'],
            'expires_at' => time() + 600,
        ];
        return self::OAUTH_URL . '?' . http_build_query([
            'client_id' => $config['app_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(',', PinterestPublisher::requiredScopes()),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completeAuthorization(int $userId, string $code, string $state): void
    {
        Auth::start();
        $pending = $_SESSION['pinterest_oauth'] ?? null;
        unset($_SESSION['pinterest_oauth']);
        if (!is_array($pending) || (int)$pending['user_id'] !== $userId || (int)$pending['expires_at'] < time()
            || !hash_equals((string)$pending['hash'], hash('sha256', $state))) {
            throw new RuntimeException('La autorización de Pinterest expiró o no es válida. Intenta conectar nuevamente.');
        }
        if ($code === '') throw new RuntimeException('Pinterest no devolvió un código de autorización.');
        $purpose=(string)($pending['purpose']??'artist');
        $config=$this->config($userId,$purpose);
        if(!hash_equals((string)($pending['app_id']??''),$config['app_id'])
            || !hash_equals((string)($pending['api_environment']??''),$config['api_environment'])){
            throw new RuntimeException('La configuración de Pinterest cambió durante la autorización. Inicia la conexión nuevamente.');
        }
        $tokens = $this->tokenRequest([
            'grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$config['redirect_uri'],'continuous_refresh'=>'true'
        ],$config);
        $account = $this->api('GET', '/user_account', (string)$tokens['access_token'],null,$this->apiBase($config));
        $this->store($userId, $purpose, $tokens, (string)($account['id'] ?? $account['username'] ?? ''), (string)($account['username'] ?? 'Pinterest'));
    }

    public function artistAppConfiguration(int $userId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT app_id,app_secret_encrypted,api_environment,updated_at FROM pinterest_artist_apps WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        return [
            'app_id'=>(string)$row['app_id'],
            'api_environment'=>$this->environment((string)$row['api_environment']),
            'redirect_uri'=>$this->redirectUri(),
            'has_secret'=>$this->decrypt((string)$row['app_secret_encrypted'])!=='',
            'updated_at'=>(string)$row['updated_at'],
        ];
    }

    public function connectArtistWithToken(int $userId,string $appId,string $accessToken,string $environment='production'): array
    {
        $appId=trim($appId);$accessToken=trim($accessToken);$environment=$this->environment($environment);
        if($this->isAdminUser($userId))throw new RuntimeException('La conexión con token personal pertenece a una cuenta de artista.');
        if(!preg_match('/^[0-9]{5,30}$/',$appId))throw new InvalidArgumentException('Introduce un App ID de Pinterest válido.');
        if(strlen($accessToken)<20||strlen($accessToken)>4096)throw new InvalidArgumentException('Introduce un access token de Pinterest válido.');

        $account=$this->api('GET','/user_account',$accessToken,null,$this->apiBase(['api_environment'=>$environment]));
        $accountId=trim((string)($account['id']??$account['username']??''));
        if($accountId==='')throw new RuntimeException('Pinterest no devolvió una cuenta válida para este token.');

        $existingAppStmt=$this->pdo->prepare('SELECT created_at FROM pinterest_artist_apps WHERE user_id=? LIMIT 1');
        $existingAppStmt->execute([$userId]);$existingApp=$existingAppStmt->fetch(PDO::FETCH_ASSOC);
        $encryptedSecret=$this->encrypt('');
        $now=date('c');$created=is_array($existingApp)?(string)$existingApp['created_at']:$now;
        $encryptedToken=$this->encrypt($accessToken);

        $this->pdo->beginTransaction();
        try{
            if((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
                $appSql='INSERT INTO pinterest_artist_apps (user_id,app_id,app_secret_encrypted,api_environment,created_at,updated_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE app_id=VALUES(app_id),app_secret_encrypted=VALUES(app_secret_encrypted),api_environment=VALUES(api_environment),updated_at=VALUES(updated_at)';
            }else{
                $appSql='INSERT INTO pinterest_artist_apps (user_id,app_id,app_secret_encrypted,api_environment,created_at,updated_at) VALUES (?,?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET app_id=excluded.app_id,app_secret_encrypted=excluded.app_secret_encrypted,api_environment=excluded.api_environment,updated_at=excluded.updated_at';
            }
            $this->pdo->prepare($appSql)->execute([$userId,$appId,$encryptedSecret,$environment,$created,$now]);

            $existingConnection=$this->connection($userId,'artist');
            if($existingConnection){
                $sql="UPDATE pinterest_connections SET pinterest_account_id=?,access_token_encrypted=?,refresh_token_encrypted=NULL,access_token_expires_at=NULL,refresh_token_expires_at=NULL,scopes=?,status='connected',connected_at=?,disconnected_at=NULL,updated_at=? WHERE user_id=? AND purpose='artist'";
                $this->pdo->prepare($sql)->execute([$accountId,$encryptedToken,'manual_token',$now,$now,$userId]);
            }else{
                $sql="INSERT INTO pinterest_connections (user_id,purpose,pinterest_account_id,access_token_encrypted,refresh_token_encrypted,access_token_expires_at,refresh_token_expires_at,scopes,status,connected_at,created_at,updated_at) VALUES (?,'artist',?,?,NULL,NULL,NULL,?,'connected',?,?,?)";
                $this->pdo->prepare($sql)->execute([$userId,$accountId,$encryptedToken,'manual_token',$now,$now,$now]);
            }
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $this->connection($userId,'artist')??[];
    }

    public function connection(int $userId, string $purpose = 'artist'): ?array
    {
        $stmt=$this->pdo->prepare('SELECT id,user_id,purpose,pinterest_account_id,scopes,status,connected_at,access_token_expires_at FROM pinterest_connections WHERE user_id=? AND purpose=? LIMIT 1');
        $stmt->execute([$userId,$this->purpose($purpose)]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    public function isPublishingReady(int $userId, string $purpose = 'artist'): bool
    {
        $connection=$this->connection($userId,$purpose);
        if(!is_array($connection)||($connection['status']??'')!=='connected')return false;
        $granted=array_values(array_filter(preg_split('/[\s,]+/',trim((string)($connection['scopes']??'')))?:[]));
        return array_diff(PinterestPublisher::requiredScopes(),$granted)===[];
    }

    public function boards(int $userId, string $purpose = 'artist'): array
    {
        $this->assertPurposeAllowed($userId,$purpose);
        [$token,$apiBase]=$this->boardReadCredentials($userId,$purpose); $items=[]; $bookmark=null;
        do {
            $path='/boards?page_size=100' . ($bookmark ? '&bookmark='.rawurlencode($bookmark) : '');
            $page=$this->api('GET',$path,$token,null,$apiBase); $items=array_merge($items,(array)($page['items']??[]));
            $bookmark=is_string($page['bookmark']??null)?$page['bookmark']:null;
        } while ($bookmark !== null && count($items) < 500);
        usort($items,fn($a,$b)=>strcasecmp((string)($a['name']??''),(string)($b['name']??'')));
        return $items;
    }

    public function sections(int $userId,string $purpose,string $boardId): array
    {
        $this->assertPurposeAllowed($userId,$purpose);if($boardId==='')return [];[$token,$apiBase]=$this->boardReadCredentials($userId,$purpose);$items=[];$bookmark=null;
        do{$path='/boards/'.rawurlencode($boardId).'/sections?page_size=100'.($bookmark?'&bookmark='.rawurlencode($bookmark):'');$page=$this->api('GET',$path,$token,null,$apiBase);$items=array_merge($items,(array)($page['items']??[]));$bookmark=is_string($page['bookmark']??null)?$page['bookmark']:null;}while($bookmark!==null&&count($items)<500);
        usort($items,fn($a,$b)=>strcasecmp((string)($a['name']??''),(string)($b['name']??'')));return $items;
    }

    public function createPin(int $userId, array $payload, string $purpose = 'artist'): array
    {
        $this->assertPurposeAllowed($userId,$purpose);
        $config=$this->config($userId,$purpose);
        return $this->api('POST','/pins',$this->accessToken($userId,$purpose),$payload,$this->apiBase($config));
    }

    public function disconnect(int $userId, string $purpose = 'artist'): void
    {
        $this->assertPurposeAllowed($userId,$purpose);
        $stmt=$this->pdo->prepare("UPDATE pinterest_connections SET access_token_encrypted=NULL,refresh_token_encrypted=NULL,status='disconnected',disconnected_at=?,updated_at=? WHERE user_id=? AND purpose=?");
        $now=date('c'); $stmt->execute([$now,$now,$userId,$this->purpose($purpose)]);
    }

    private function accessToken(int $userId, string $purpose): string
    {
        $config=$this->config($userId,$purpose);
        if ($this->isSandbox($config) && $purpose==='platform') {
            $this->assertPurposeAllowed($userId, $purpose);
            $token = trim(app_env('PINTEREST_SANDBOX_TOKEN'));
            if ($token === '') {
                throw new RuntimeException('Falta configurar PINTEREST_SANDBOX_TOKEN para publicar durante Trial access.');
            }
            return $token;
        }
        $purpose=$this->purpose($purpose);$stmt=$this->pdo->prepare('SELECT * FROM pinterest_connections WHERE user_id=? AND purpose=? AND status=? LIMIT 1');
        $stmt->execute([$userId,$purpose,'connected']); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)) throw new RuntimeException('Conecta tu cuenta de Pinterest primero.');
        $expiresAt=trim((string)($row['access_token_expires_at']??''));
        if($expiresAt!==''&&strtotime($expiresAt) <= time()+300) {
            $encryptedRefresh=trim((string)($row['refresh_token_encrypted']??''));
            if($encryptedRefresh==='')throw new RuntimeException('El token de Pinterest expiró. Genera uno nuevo y actualízalo desde tu cuenta de artista.');
            $refresh=$this->decrypt($encryptedRefresh);
            $tokens=$this->tokenRequest(['grant_type'=>'refresh_token','refresh_token'=>$refresh],$config);
            $this->store($userId,$purpose,$tokens,(string)$row['pinterest_account_id'],'Pinterest');
            return (string)$tokens['access_token'];
        }
        return $this->decrypt((string)$row['access_token_encrypted']);
    }

    private function store(int $userId,string $purpose,array $tokens,string $accountId,string $displayName): void
    {
        $access=(string)($tokens['access_token']??''); $refresh=(string)($tokens['refresh_token']??'');
        if($access===''||$refresh==='') throw new RuntimeException('Pinterest no devolvió tokens renovables.');
        $now=date('c'); $accessExpiry=date('c',time()+max(60,(int)($tokens['expires_in']??2592000)));
        $refreshExpiry=isset($tokens['refresh_token_expires_at'])?date('c',(int)$tokens['refresh_token_expires_at']):date('c',time()+max(60,(int)($tokens['refresh_token_expires_in']??5184000)));
        $scopes=(string)($tokens['scope']??implode(' ',PinterestPublisher::requiredScopes()));
        $purpose=$this->purpose($purpose);$existing=$this->connection($userId,$purpose);
        if($existing){
            $sql='UPDATE pinterest_connections SET pinterest_account_id=?,access_token_encrypted=?,refresh_token_encrypted=?,access_token_expires_at=?,refresh_token_expires_at=?,scopes=?,status=?,connected_at=?,disconnected_at=NULL,updated_at=? WHERE user_id=? AND purpose=?';
            $this->pdo->prepare($sql)->execute([$accountId,$this->encrypt($access),$this->encrypt($refresh),$accessExpiry,$refreshExpiry,$scopes,'connected',$now,$now,$userId,$purpose]);
        }else{
            $sql='INSERT INTO pinterest_connections (user_id,purpose,pinterest_account_id,access_token_encrypted,refresh_token_encrypted,access_token_expires_at,refresh_token_expires_at,scopes,status,connected_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)';
            $this->pdo->prepare($sql)->execute([$userId,$purpose,$accountId,$this->encrypt($access),$this->encrypt($refresh),$accessExpiry,$refreshExpiry,$scopes,'connected',$now,$now,$now]);
        }
    }

    private function tokenRequest(array $fields,array $config): array
    {
        $this->assertConfigured($config);
        $curl=curl_init($this->apiBase($config).'/oauth/token');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Basic '.base64_encode($config['app_id'].':'.$config['app_secret']),'Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>http_build_query($fields),CURLOPT_TIMEOUT=>30]);
        return $this->decodeResponse($curl,'Pinterest OAuth');
    }

    private function api(string $method,string $path,string $token,?array $payload=null,?string $apiBase=null): array
    {
        if($this->apiTransport instanceof Closure){
            $result=($this->apiTransport)($method,$path,$token,$payload,$apiBase);
            if(!is_array($result))throw new PinterestTransportException('Pinterest API no devolvió una respuesta válida.');
            return $result;
        }
        $curl=curl_init(($apiBase??'https://api.pinterest.com/v5').$path); $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json'],CURLOPT_TIMEOUT=>30];
        if($payload!==null)$opts[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        curl_setopt_array($curl,$opts); return $this->decodeResponse($curl,'Pinterest API');
    }

    /**
     * Trial-pending apps may use Pinterest's production-limited token to read
     * the app owner's real boards. Keep this bridge scoped to one local admin
     * user and never reuse the token for creating Pins.
     *
     * @return array{0:string,1:string}
     */
    private function boardReadCredentials(int $userId,string $purpose): array
    {
        $purpose=$this->purpose($purpose);
        $config=$this->config($userId,$purpose);
        $readToken=trim(app_env('PINTEREST_PRODUCTION_READ_TOKEN'));
        $readUserId=(int)app_env('PINTEREST_PRODUCTION_READ_USER_ID','0');
        if($config['source']==='platform'&&$purpose==='artist'&&$readToken!==''&&$readUserId>0&&$userId===$readUserId){
            return [$readToken,'https://api.pinterest.com/v5'];
        }
        return [$this->accessToken($userId,$purpose),$this->apiBase($config)];
    }

    private function decodeResponse(CurlHandle $curl,string $label): array
    {
        $body=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); $error=curl_error($curl); curl_close($curl);
        $decoded=is_string($body)?json_decode($body,true):null;
        if($error!==''||$status===0||$status>=500||($status>=200&&$status<300&&!is_array($decoded))) {
            throw new PinterestTransportException($label.' no devolvió una respuesta concluyente. Verifica Pinterest antes de reintentar.');
        }
        if($status<200||$status>=300||!is_array($decoded)) {
            $details='';
            if(is_array($decoded)){
                $code=preg_replace('/[^A-Za-z0-9._-]/','',(string)($decoded['code']??''));
                $message=trim((string)($decoded['message']??$decoded['error_description']??$decoded['error']??''));
                $message=mb_substr(preg_replace('/[\x00-\x1F\x7F]/u',' ',$message)??'',0,240);
                if($code!==''||$message!=='')$details=' Pinterest code '.$code.($message!==''?': '.$message:'').'.';
            }
            throw new RuntimeException($label.' respondió con un error (HTTP '.$status.').'.$details);
        }
        return $decoded;
    }

    private function encrypt(string $plain): string
    {
        $key=$this->key(); $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1.'.base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$key));
    }

    private function decrypt(string $encoded): string
    {
        if(!str_starts_with($encoded,'v1.')) throw new RuntimeException('El token cifrado no tiene una versión válida.');
        $raw=base64_decode(substr($encoded,3),true);
        if(!is_string($raw)||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('El token cifrado está dañado.');
        $nonce=substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$this->key());
        if(!is_string($plain)) throw new RuntimeException('No se pudo descifrar el token de Pinterest.');
        return $plain;
    }

    private function key(): string
    {
        $encoded=app_env('PINTEREST_TOKEN_KEY'); $key=base64_decode($encoded,true);
        if(!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('Falta configurar PINTEREST_TOKEN_KEY con 32 bytes en Base64.');
        return $key;
    }

    private function assertConfigured(array $config): void
    {
        foreach(['app_id'=>'App ID','app_secret'=>'App Secret','redirect_uri'=>'Redirect URI'] as $key=>$label)
            if(trim((string)($config[$key]??''))==='')throw new RuntimeException('Falta configurar Pinterest '.$label.'.');
    }

    private function apiBase(array $config): string
    {
        return $this->isSandbox($config)
            ? 'https://api-sandbox.pinterest.com/v5'
            : 'https://api.pinterest.com/v5';
    }

    private function isSandbox(array $config): bool
    {
        return $this->environment((string)($config['api_environment']??'production')) === 'sandbox';
    }

    private function config(int $userId,string $purpose): array
    {
        $purpose=$this->purpose($purpose);
        return [
            'app_id'=>trim(app_env('PINTEREST_APP_ID')),
            'app_secret'=>trim(app_env('PINTEREST_APP_SECRET')),
            'redirect_uri'=>$this->redirectUri(),
            'api_environment'=>$this->environment(app_env('PINTEREST_API_ENVIRONMENT','production')),
            'source'=>'official',
        ];
    }

    private function redirectUri(): string
    {
        return trim(app_env('PINTEREST_REDIRECT_URI'));
    }

    private function environment(string $environment): string
    {
        $environment=strtolower(trim($environment));
        if(!in_array($environment,['production','sandbox'],true))throw new InvalidArgumentException('El entorno de Pinterest no es válido.');
        return $environment;
    }

    private function isAdminUser(int $userId): bool
    {
        $stmt=$this->pdo->prepare('SELECT is_admin FROM users WHERE id=? LIMIT 1');$stmt->execute([$userId]);
        return (int)$stmt->fetchColumn()===1;
    }

    private function purpose(string $purpose): string
    {
        if(!in_array($purpose,['artist','platform'],true))throw new InvalidArgumentException('Invalid Pinterest connection purpose.');
        return $purpose;
    }

    private function assertPurposeAllowed(int $userId,string $purpose): void
    {
        $purpose=$this->purpose($purpose);if($purpose!=='platform')return;
        if(!$this->isAdminUser($userId))throw new RuntimeException('The Artwork Mockups Pinterest account is available to administrators only.');
    }
}
