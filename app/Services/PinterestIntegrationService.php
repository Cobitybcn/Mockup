<?php
declare(strict_types=1);

final class PinterestTransportException extends RuntimeException {}

final class PinterestIntegrationService
{
    private const OAUTH_URL = 'https://www.pinterest.com/oauth/';

    public function __construct(private PDO $pdo) {}

    public function authorizationUrl(int $userId, string $purpose = 'artist'): string
    {
        Auth::start();
        $this->assertConfigured();
        $this->assertPurposeAllowed($userId,$purpose);
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $purpose=$this->purpose($purpose);
        $_SESSION['pinterest_oauth'] = ['hash' => hash('sha256', $state), 'user_id' => $userId, 'purpose'=>$purpose, 'expires_at' => time() + 600];
        return self::OAUTH_URL . '?' . http_build_query([
            'client_id' => app_env('PINTEREST_APP_ID'),
            'redirect_uri' => app_env('PINTEREST_REDIRECT_URI'),
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
        $tokens = $this->tokenRequest(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>app_env('PINTEREST_REDIRECT_URI')]);
        $account = $this->api('GET', '/user_account', (string)$tokens['access_token']);
        $this->store($userId, (string)($pending['purpose']??'artist'), $tokens, (string)($account['id'] ?? $account['username'] ?? ''), (string)($account['username'] ?? 'Pinterest'));
    }

    public function connection(int $userId, string $purpose = 'artist'): ?array
    {
        $stmt=$this->pdo->prepare('SELECT id,user_id,purpose,pinterest_account_id,scopes,status,connected_at,access_token_expires_at FROM pinterest_connections WHERE user_id=? AND purpose=? LIMIT 1');
        $stmt->execute([$userId,$this->purpose($purpose)]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    public function boards(int $userId, string $purpose = 'artist'): array
    {
        $this->assertPurposeAllowed($userId,$purpose);
        $token=$this->accessToken($userId,$purpose); $items=[]; $bookmark=null;
        do {
            $path='/boards?page_size=100' . ($bookmark ? '&bookmark='.rawurlencode($bookmark) : '');
            $page=$this->api('GET',$path,$token); $items=array_merge($items,(array)($page['items']??[]));
            $bookmark=is_string($page['bookmark']??null)?$page['bookmark']:null;
        } while ($bookmark !== null && count($items) < 500);
        usort($items,fn($a,$b)=>strcasecmp((string)($a['name']??''),(string)($b['name']??'')));
        return $items;
    }

    public function sections(int $userId,string $purpose,string $boardId): array
    {
        $this->assertPurposeAllowed($userId,$purpose);if($boardId==='')return [];$token=$this->accessToken($userId,$purpose);$items=[];$bookmark=null;
        do{$path='/boards/'.rawurlencode($boardId).'/sections?page_size=100'.($bookmark?'&bookmark='.rawurlencode($bookmark):'');$page=$this->api('GET',$path,$token);$items=array_merge($items,(array)($page['items']??[]));$bookmark=is_string($page['bookmark']??null)?$page['bookmark']:null;}while($bookmark!==null&&count($items)<500);
        usort($items,fn($a,$b)=>strcasecmp((string)($a['name']??''),(string)($b['name']??'')));return $items;
    }

    public function createPin(int $userId, array $payload, string $purpose = 'artist'): array
    {
        $this->assertPurposeAllowed($userId,$purpose);
        return $this->api('POST','/pins',$this->accessToken($userId,$purpose),$payload);
    }

    public function disconnect(int $userId, string $purpose = 'artist'): void
    {
        $this->assertPurposeAllowed($userId,$purpose);
        $stmt=$this->pdo->prepare("UPDATE pinterest_connections SET access_token_encrypted=NULL,refresh_token_encrypted=NULL,status='disconnected',disconnected_at=?,updated_at=? WHERE user_id=? AND purpose=?");
        $now=date('c'); $stmt->execute([$now,$now,$userId,$this->purpose($purpose)]);
    }

    private function accessToken(int $userId, string $purpose): string
    {
        if ($this->isSandbox()) {
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
        if(strtotime((string)$row['access_token_expires_at']) <= time()+300) {
            $refresh=$this->decrypt((string)$row['refresh_token_encrypted']);
            $tokens=$this->tokenRequest(['grant_type'=>'refresh_token','refresh_token'=>$refresh]);
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

    private function tokenRequest(array $fields): array
    {
        $this->assertConfigured();
        $curl=curl_init($this->apiBase().'/oauth/token');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Basic '.base64_encode(app_env('PINTEREST_APP_ID').':'.app_env('PINTEREST_APP_SECRET')),'Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>http_build_query($fields),CURLOPT_TIMEOUT=>30]);
        return $this->decodeResponse($curl,'Pinterest OAuth');
    }

    private function api(string $method,string $path,string $token,?array $payload=null): array
    {
        $curl=curl_init($this->apiBase().$path); $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json'],CURLOPT_TIMEOUT=>30];
        if($payload!==null)$opts[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        curl_setopt_array($curl,$opts); return $this->decodeResponse($curl,'Pinterest API');
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

    private function assertConfigured(): void
    {
        foreach(['PINTEREST_APP_ID','PINTEREST_APP_SECRET','PINTEREST_REDIRECT_URI'] as $key) if(app_env($key)==='') throw new RuntimeException('Falta configurar '.$key.'.');
    }

    private function apiBase(): string
    {
        return $this->isSandbox()
            ? 'https://api-sandbox.pinterest.com/v5'
            : 'https://api.pinterest.com/v5';
    }

    private function isSandbox(): bool
    {
        return strtolower(trim(app_env('PINTEREST_API_ENVIRONMENT','production'))) === 'sandbox';
    }

    private function purpose(string $purpose): string
    {
        if(!in_array($purpose,['artist','platform'],true))throw new InvalidArgumentException('Invalid Pinterest connection purpose.');
        return $purpose;
    }

    private function assertPurposeAllowed(int $userId,string $purpose): void
    {
        $purpose=$this->purpose($purpose);if($purpose!=='platform')return;
        $stmt=$this->pdo->prepare('SELECT is_admin FROM users WHERE id=? LIMIT 1');$stmt->execute([$userId]);
        if((int)$stmt->fetchColumn()!==1)throw new RuntimeException('The Artwork Mockups Pinterest account is available to administrators only.');
    }
}
