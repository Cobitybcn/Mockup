<?php
declare(strict_types=1);

final class MetaIntegrationService
{
    public function __construct(private PDO $pdo) {}

    public function authorizationUrl(int $userId, string $purpose = 'artist'): string
    {
        Auth::start();
        if (app_env('META_OAUTH_ENABLED','false') !== 'true') {
            throw new RuntimeException('Meta OAuth is disabled in this environment. Enable it only on the approved public HTTPS callback.');
        }
        $purpose = $this->purpose($purpose);
        $this->assertPurposeAllowed($userId, $purpose);
        $config = $this->config($purpose);
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $_SESSION['meta_oauth_'.$purpose] = [
            'hash' => hash('sha256', $state), 'user_id' => $userId,
            'expires_at' => time() + 600,
        ];
        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => $config['app_id'], 'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code', 'scope' => implode(',', $this->scopes()), 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completeAuthorization(int $userId, string $purpose, string $code, string $state): void
    {
        Auth::start();
        $purpose = $this->purpose($purpose);
        $pending = $_SESSION['meta_oauth_'.$purpose] ?? null;
        unset($_SESSION['meta_oauth_'.$purpose]);
        if (!is_array($pending) || (int)($pending['user_id'] ?? 0) !== $userId
            || (int)($pending['expires_at'] ?? 0) < time()
            || !hash_equals((string)($pending['hash'] ?? ''), hash('sha256', $state))) {
            throw new RuntimeException('La autorización de Meta expiró o no es válida. Conecta nuevamente.');
        }
        if ($code === '') throw new RuntimeException('Meta no devolvió un código de autorización.');
        $config = $this->config($purpose);
        $short = $this->graph('/oauth/access_token', [
            'client_id'=>$config['app_id'],'client_secret'=>$config['app_secret'],
            'redirect_uri'=>$config['redirect_uri'],'code'=>$code,
        ]);
        $shortToken = trim((string)($short['access_token'] ?? ''));
        if ($shortToken === '') throw new RuntimeException('Meta no devolvió un access token.');
        $long = $this->graph('/oauth/access_token', [
            'grant_type'=>'fb_exchange_token','client_id'=>$config['app_id'],
            'client_secret'=>$config['app_secret'],'fb_exchange_token'=>$shortToken,
        ]);
        $token = trim((string)($long['access_token'] ?? $shortToken));
        $me = $this->graph('/me', ['fields'=>'id,name','access_token'=>$token]);
        $permissions = $this->graph('/me/permissions', ['access_token'=>$token]);
        $granted = [];
        foreach ((array)($permissions['data'] ?? []) as $permission) {
            if (is_array($permission) && ($permission['status'] ?? '') === 'granted') {
                $name = trim((string)($permission['permission'] ?? ''));
                if ($name !== '') $granted[] = $name;
            }
        }
        $this->storeUserConnection(
            $userId,
            $purpose,
            $me,
            $token,
            (int)($long['expires_in'] ?? $short['expires_in'] ?? 5184000),
            $granted
        );
        $pages = $this->pages($userId, $purpose);
        if (count($pages) === 1) $this->selectPage($userId, $purpose, (string)$pages[0]['id']);
    }

    public function connection(int $userId, string $purpose = 'artist'): ?array
    {
        $stmt=$this->pdo->prepare('SELECT id,user_id,purpose,meta_user_id,meta_user_name,token_expires_at,page_id,page_name,instagram_account_id,instagram_username,scopes,status,connected_at FROM meta_connections WHERE user_id=? AND purpose=? LIMIT 1');
        $stmt->execute([$userId,$this->purpose($purpose)]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    public function pages(int $userId, string $purpose = 'artist'): array
    {
        $this->assertPurposeAllowed($userId,$purpose);
        $token=$this->userToken($userId,$purpose);
        $fields=['id','name','access_token','tasks'];
        if(in_array('instagram_basic',$this->scopes(),true))$fields[]='instagram_business_account{id,username}';
        $response=$this->graph('/me/accounts',[
            'fields'=>implode(',',$fields),
            'limit'=>'100','access_token'=>$token,
        ]);
        return array_values(array_filter((array)($response['data']??[]),fn($p)=>is_array($p)&&trim((string)($p['id']??''))!==''));
    }

    public function selectPage(int $userId,string $purpose,string $pageId): void
    {
        $selected=null;
        foreach($this->pages($userId,$purpose) as $page) if(hash_equals((string)$page['id'],$pageId)){$selected=$page;break;}
        if(!is_array($selected))throw new RuntimeException('Selecciona una Página disponible en la cuenta conectada.');
        $tasks=array_values(array_map('strval',(array)($selected['tasks']??[])));
        if($tasks&&!in_array('CREATE_CONTENT',$tasks,true)&&!in_array('MANAGE',$tasks,true))throw new RuntimeException('Tu acceso a esta Página no permite crear contenido.');
        $ig=is_array($selected['instagram_business_account']??null)?$selected['instagram_business_account']:[];
        $now=date('c');
        $this->pdo->prepare('UPDATE meta_connections SET page_id=?,page_name=?,page_access_token_encrypted=?,instagram_account_id=?,instagram_username=?,status=?,connected_at=?,updated_at=? WHERE user_id=? AND purpose=?')
            ->execute([(string)$selected['id'],(string)($selected['name']??''),$this->encrypt((string)($selected['access_token']??'')),(string)($ig['id']??''),(string)($ig['username']??''),'connected',$now,$now,$userId,$this->purpose($purpose)]);
    }

    public function assertPublishingReady(int $userId,string $purpose,array $channels): array
    {
        $purpose=$this->purpose($purpose);$connection=$this->connection($userId,$purpose);
        if(!is_array($connection)||($connection['status']??'')!=='connected')throw new RuntimeException('Conecta una Página de Facebook antes de publicar.');
        if(strtotime((string)($connection['token_expires_at']??''))<=time()+300)throw new RuntimeException('La conexión de Meta expiró. Conecta nuevamente.');
        if(trim((string)($connection['page_id']??''))==='')throw new RuntimeException('Selecciona una Página de Facebook antes de publicar.');
        $requested=array_values(array_intersect(['facebook','instagram'],array_unique(array_map('strval',$channels))));
        if(!$requested)throw new InvalidArgumentException('Selecciona Facebook, Instagram o ambos.');
        $required=['pages_show_list','pages_read_engagement'];
        if(in_array('facebook',$requested,true))$required[]='pages_manage_posts';
        if(in_array('instagram',$requested,true)){$required[]='instagram_basic';$required[]='instagram_content_publish';if(trim((string)($connection['instagram_account_id']??''))==='')throw new RuntimeException('La Página seleccionada no tiene una cuenta profesional de Instagram vinculada.');}
        $granted=array_values(array_filter(array_map('trim',explode(',',(string)($connection['scopes']??'')))));
        $missing=array_values(array_diff(array_unique($required),$granted));
        if($missing)throw new RuntimeException('Meta no concedió los permisos requeridos: '.implode(', ',$missing).'.');
        return $connection;
    }

    /** @return array{page_id:string,page_name:string,instagram_account_id:string,instagram_username:string,access_token:string,app_secret:string,graph_version:string} */
    public function publishingContext(int $userId,string $purpose,array $channels): array
    {
        $connection=$this->assertPublishingReady($userId,$purpose,$channels);
        $stmt=$this->pdo->prepare('SELECT page_access_token_encrypted FROM meta_connections WHERE user_id=? AND purpose=? AND status=? LIMIT 1');
        $stmt->execute([$userId,$this->purpose($purpose),'connected']);$encrypted=(string)($stmt->fetchColumn()?:'');
        if($encrypted==='')throw new RuntimeException('La Página de Meta no tiene un access token disponible. Conecta nuevamente.');
        $config=$this->config($purpose);
        return [
            'page_id'=>(string)$connection['page_id'],'page_name'=>(string)$connection['page_name'],
            'instagram_account_id'=>(string)$connection['instagram_account_id'],'instagram_username'=>(string)$connection['instagram_username'],
            'access_token'=>$this->decrypt($encrypted),'app_secret'=>$config['app_secret'],'graph_version'=>$this->version(),
        ];
    }

    public function disconnect(int $userId,string $purpose='artist'): void
    {
        $this->assertPurposeAllowed($userId,$purpose);$now=date('c');
        $this->pdo->prepare("UPDATE meta_connections SET user_access_token_encrypted=NULL,page_access_token_encrypted=NULL,status='disconnected',disconnected_at=?,updated_at=? WHERE user_id=? AND purpose=?")
            ->execute([$now,$now,$userId,$this->purpose($purpose)]);
    }

    private function storeUserConnection(int $userId,string $purpose,array $me,string $token,int $expiresIn,array $grantedScopes): void
    {
        $now=date('c');$expires=date('c',time()+max(60,$expiresIn));$existing=$this->connection($userId,$purpose);
        $scopeValue=implode(',',array_values(array_unique(array_filter(array_map('trim',$grantedScopes)))));
        $values=[(string)($me['id']??''),(string)($me['name']??''),$this->encrypt($token),$expires,$scopeValue,'awaiting_page',$now,$now,$userId,$purpose];
        if($existing){
            $this->pdo->prepare('UPDATE meta_connections SET meta_user_id=?,meta_user_name=?,user_access_token_encrypted=?,token_expires_at=?,scopes=?,status=?,connected_at=?,disconnected_at=NULL,updated_at=? WHERE user_id=? AND purpose=?')->execute($values);
        }else{
            $this->pdo->prepare('INSERT INTO meta_connections (meta_user_id,meta_user_name,user_access_token_encrypted,token_expires_at,scopes,status,connected_at,created_at,updated_at,user_id,purpose) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([(string)($me['id']??''),(string)($me['name']??''),$this->encrypt($token),$expires,$scopeValue,'awaiting_page',$now,$now,$now,$userId,$purpose]);
        }
    }

    private function userToken(int $userId,string $purpose): string
    {
        $stmt=$this->pdo->prepare('SELECT user_access_token_encrypted,token_expires_at FROM meta_connections WHERE user_id=? AND purpose=? AND status IN (?,?) LIMIT 1');
        $stmt->execute([$userId,$this->purpose($purpose),'awaiting_page','connected']);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new RuntimeException('Conecta la cuenta de Meta primero.');
        if(strtotime((string)$row['token_expires_at'])<=time()+300)throw new RuntimeException('La conexión de Meta expiró. Conecta nuevamente.');
        return $this->decrypt((string)$row['user_access_token_encrypted']);
    }

    private function graph(string $path,array $query): array
    {
        $url='https://graph.facebook.com/'.$this->version().$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);
        $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$error=curl_error($curl);curl_close($curl);
        $data=is_string($body)?json_decode($body,true):null;
        if($error!==''||$status<200||$status>=300||!is_array($data)){
            $message=is_array($data)?trim((string)($data['error']['message']??'')):'';
            throw new RuntimeException('Meta API respondió con un error (HTTP '.$status.').'.($message!==''?' '.mb_substr($message,0,240):''));
        }
        return $data;
    }

    private function config(string $purpose): array
    {
        $suffix=strtoupper($this->purpose($purpose));$config=[
            'app_id'=>app_env('META_APP_ID_'.$suffix),'app_secret'=>app_env('META_APP_SECRET_'.$suffix),
            'redirect_uri'=>app_env('META_REDIRECT_URI_'.$suffix),
        ];
        foreach($config as $key=>$value)if($value==='')throw new RuntimeException('Falta configurar META_'.strtoupper($key).'_'.$suffix.'.');
        return $config;
    }
    private function scopes(): array{return array_values(array_filter(array_map('trim',explode(',',app_env('META_SCOPES','pages_show_list,pages_read_engagement,pages_manage_posts')))));}
    private function version(): string{return preg_replace('/[^v0-9.]/','',app_env('META_GRAPH_VERSION','v25.0'))?:'v25.0';}
    private function purpose(string $purpose): string{if(!in_array($purpose,['artist','platform'],true))throw new InvalidArgumentException('Invalid Meta connection purpose.');return $purpose;}
    private function assertPurposeAllowed(int $userId,string $purpose): void{if($this->purpose($purpose)!=='platform')return;$s=$this->pdo->prepare('SELECT is_admin FROM users WHERE id=?');$s->execute([$userId]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('La identidad de plataforma está disponible solo para administradores.');}
    private function encrypt(string $plain): string{$key=$this->key();$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);return 'v1.'.base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$key));}
    private function decrypt(string $encoded): string
    {
        if(!str_starts_with($encoded,'v1.'))throw new RuntimeException('El token cifrado no es válido.');
        $raw=base64_decode(substr($encoded,3),true);
        if(!is_string($raw)||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('El token cifrado está dañado.');
        $nonce=substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher=substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain=sodium_crypto_secretbox_open($cipher,$nonce,$this->key());
        if(!is_string($plain))throw new RuntimeException('No se pudo descifrar el token de Meta.');
        return $plain;
    }
    private function key(): string{$key=base64_decode(app_env('META_TOKEN_KEY'),true);if(!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('Falta configurar META_TOKEN_KEY con 32 bytes en Base64.');return $key;}
}
