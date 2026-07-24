<?php
declare(strict_types=1);

final class MockupPinterestDraftService
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(int $mockupId,array $user,string $purpose,string $destinationUrl,string $locale='en'): array
    {
        $userId=(int)$user['id'];$purpose=$this->purpose($purpose,$user);$locale=$locale==='es'?'es':'en';
        if(!filter_var($destinationUrl,FILTER_VALIDATE_URL)||strtolower((string)parse_url($destinationUrl,PHP_URL_SCHEME))!=='https')throw new InvalidArgumentException('Pinterest destination must be a public HTTPS URL.');
        $stmt=$this->pdo->prepare('SELECT * FROM mockups WHERE id=? AND user_id=? LIMIT 1');$stmt->execute([$mockupId,$userId]);$mockup=$stmt->fetch(PDO::FETCH_ASSOC);if(!$mockup)throw new RuntimeException('Mockup not found.');
        $artworkId=(int)($mockup['source_artwork_id']??0);$artwork=null;
        if($artworkId>0){$stmt=$this->pdo->prepare('SELECT * FROM artworks WHERE id=? AND user_id=? LIMIT 1');$stmt->execute([$artworkId,$userId]);$artwork=$stmt->fetch(PDO::FETCH_ASSOC);}
        if(!$artwork){$stmt=$this->pdo->prepare('SELECT * FROM artworks WHERE user_id=? AND (root_file=? OR main_file=?) LIMIT 1');$stmt->execute([$userId,$mockup['artwork_file'],$mockup['artwork_file']]);$artwork=$stmt->fetch(PDO::FETCH_ASSOC);}
        if(!$artwork)throw new RuntimeException('Related artwork not found.');
        $root=basename((string)($artwork['root_file']??$mockup['artwork_file']));$base=pathinfo($root,PATHINFO_FILENAME);$analysis=[];$file=ANALYSIS_DIR.DIRECTORY_SEPARATOR.$base.'.analysis.json';if(is_file($file)){$decoded=json_decode((string)file_get_contents($file),true);if(is_array($decoded))$analysis=$decoded;}
        if(!$analysis){$stmt=$this->pdo->prepare('SELECT analysis_json FROM artwork_analysis WHERE artwork_id=? ORDER BY id DESC LIMIT 1');$stmt->execute([(int)$artwork['id']]);$decoded=json_decode((string)($stmt->fetchColumn()?:''),true);if(is_array($decoded))$analysis=array_key_exists('suggested_titles',$decoded)||array_key_exists('contextual_proposals',$decoded)?$decoded:['artwork_profile'=>$decoded];}
        $profile=is_array($analysis['artwork_profile']??null)?$analysis['artwork_profile']:[];$artistProfile=is_array($profile['_artist_profile']??null)?$profile['_artist_profile']:ArtistProfile::findForUser($userId);
        $content=MockupEditorialContent::build($artwork,$analysis,$artistProfile,Display::contextTitle((string)$mockup['context_id']));
        $reviewed=(new MockupSocialContentService($this->pdo))->forMockup($userId,$mockupId,$locale);
        $reviewedContent=(array)($reviewed['content']??[]);
        $reviewedPinterest=(array)($reviewedContent['social']['pinterest']??[]);
        $hasReviewed=trim((string)($reviewedPinterest['title']??''))!==''&&trim((string)($reviewedPinterest['description']??''))!=='';
        if($hasReviewed){
            $content['title']=(string)$reviewedPinterest['title'];
            $content['description']=(string)$reviewedPinterest['description'];
            $content['altText']=MockupSocialContentService::text($reviewedContent['alt_text']??'',$content['altText']??'');
            $content['keywords']=MockupSocialContentService::list($reviewedPinterest['keywords']??[],$reviewedContent['search_terms']??[]);
            $boards=MockupSocialContentService::list($reviewedPinterest['board_suggestions']??[]);
            if($boards)$content['board']=$boards[0];
        }
        $sheetStmt=$this->pdo->prepare("SELECT title,description,keywords,tags,alt_text,caption,generated_json FROM mockup_sheets WHERE user_id=? AND mockup_file=? ORDER BY id DESC LIMIT 1");$sheetStmt->execute([$userId,basename((string)$mockup['mockup_file'])]);$sheet=$sheetStmt->fetch(PDO::FETCH_ASSOC);
        if(!$hasReviewed&&is_array($sheet)){
            $storedGenerated=json_decode((string)($sheet['generated_json']??''),true);$v2=is_array($storedGenerated['mockup_analysis_v2']??null)?$storedGenerated['mockup_analysis_v2']:[];
            if((string)($v2['analysis_language']??'')!=='es')throw new RuntimeException('Generá y revisá primero el análisis español del mockup.');
            $v2Pinterest=(array)($v2['channels']['pinterest']??[]);$v2Neutral=(array)($v2['neutral']??[]);
            if(trim((string)($v2Pinterest['title']??''))!=='')$content['title']=(string)$v2Pinterest['title'];
            if(trim((string)($v2Pinterest['description']??''))!=='')$content['description']=(string)$v2Pinterest['description'];
            if(trim((string)($v2Neutral['alt_text']??''))!=='')$content['altText']=(string)$v2Neutral['alt_text'];
            if(array_filter((array)($v2Pinterest['keywords']??[]),'trim'))$content['keywords']=array_values(array_filter(array_map('trim',(array)$v2Pinterest['keywords'])));
            $boards=array_values(array_filter(array_map('trim',(array)($v2Pinterest['board_suggestions']??[]))));if($boards)$content['board']=$boards[0];
        }elseif(!$hasReviewed){
            throw new RuntimeException('Generá y revisá primero el análisis español del mockup.');
        }
        $payload=['mockup_id'=>$mockupId,'purpose'=>$purpose,'locale'=>$locale,'board_suggestion'=>$content['board'],'title'=>$content['title'],'description'=>$content['description'],'alt_text'=>$content['altText'],'keywords'=>$content['keywords'],'hashtags'=>$content['hashtags'],'destination_url'=>$destinationUrl];$now=date('c');
        $token=bin2hex(random_bytes(32));$this->pdo->prepare('INSERT INTO pinterest_pin_drafts (user_id,mockup_id,purpose,board_suggestion,title,description,alt_text,keywords,hashtags,destination_url,status,payload_json,media_token,external_url,error,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$userId,$mockupId,$purpose,$content['board'],mb_substr($content['title'],0,100),mb_substr($content['description'],0,500),mb_substr($content['altText'],0,500),json_encode($content['keywords'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($content['hashtags'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$destinationUrl,'draft',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$token,'','',$now,$now]);
        return ['id'=>(int)$this->pdo->lastInsertId()]+$payload;
    }

    public function draft(int $draftId,int $userId): array
    {
        $stmt=$this->pdo->prepare('SELECT d.*,m.mockup_file FROM pinterest_pin_drafts d JOIN mockups m ON m.id=d.mockup_id WHERE d.id=? AND d.user_id=? LIMIT 1');$stmt->execute([$draftId,$userId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Pinterest draft not found.');return $row;
    }

    public function recommendBoard(array $draft,array $boards): ?array
    {
        $needle=$this->tokens((string)$draft['board_suggestion']);$best=null;$score=0;
        foreach($boards as $board){$name=(string)($board['name']??'');$candidate=count(array_intersect($needle,$this->tokens($name)));if($candidate>$score){$score=$candidate;$best=$board;}}
        return $best;
    }

    public function selectBoard(int $draftId,int $userId,string $boardId,array $availableBoards): array
    {
        $selected=null;foreach($availableBoards as $board)if((string)($board['id']??'')===$boardId){$selected=$board;break;}if(!$selected)throw new RuntimeException('Select a board from the connected Pinterest account.');
        $this->pdo->prepare("UPDATE pinterest_pin_drafts SET board_id=?,board_name=?,status='board_selected',updated_at=? WHERE id=? AND user_id=?")->execute([$boardId,(string)($selected['name']??''),date('c'),$draftId,$userId]);return $this->draft($draftId,$userId);
    }

    public function selectSection(int $draftId,int $userId,string $sectionId,array $availableSections): array
    {
        if($sectionId===''){$this->pdo->prepare("UPDATE pinterest_pin_drafts SET board_section_id='',board_section_name='',updated_at=? WHERE id=? AND user_id=?")->execute([date('c'),$draftId,$userId]);return $this->draft($draftId,$userId);}
        $selected=null;foreach($availableSections as $section)if((string)($section['id']??'')===$sectionId){$selected=$section;break;}if(!$selected)throw new RuntimeException('Select a section from the chosen Pinterest board.');$this->pdo->prepare('UPDATE pinterest_pin_drafts SET board_section_id=?,board_section_name=?,updated_at=? WHERE id=? AND user_id=?')->execute([$sectionId,(string)($selected['name']??''),date('c'),$draftId,$userId]);return $this->draft($draftId,$userId);
    }

    public function updateDestination(int $draftId,int $userId,string $destinationUrl): array
    {
        if(!filter_var($destinationUrl,FILTER_VALIDATE_URL)||strtolower((string)parse_url($destinationUrl,PHP_URL_SCHEME))!=='https')throw new InvalidArgumentException('Pinterest destination must be a public HTTPS URL.');
        $this->pdo->prepare('UPDATE pinterest_pin_drafts SET destination_url=?,updated_at=? WHERE id=? AND user_id=?')->execute([$destinationUrl,date('c'),$draftId,$userId]);
        return $this->draft($draftId,$userId);
    }

    public function updateContent(int $draftId,int $userId,string $title,string $description,string $altText): array
    {
        $title=trim($title);$description=trim($description);$altText=trim($altText);
        if($title==='')throw new InvalidArgumentException('Pinterest title is required.');
        $this->pdo->prepare('UPDATE pinterest_pin_drafts SET title=?,description=?,alt_text=?,updated_at=? WHERE id=? AND user_id=?')
            ->execute([mb_substr($title,0,100),mb_substr($description,0,500),mb_substr($altText,0,500),date('c'),$draftId,$userId]);
        return $this->draft($draftId,$userId);
    }

    public function saveCrop(int $draftId,int $userId,float $x,float $y,float $zoom): array
    {
        $draft=$this->draft($draftId,$userId);$x=max(0,min(1,$x));$y=max(0,min(1,$y));$zoom=max(1,min(3,$zoom));$sourceFile=basename((string)$draft['mockup_file']);$sourcePath=RESULTS_DIR.DIRECTORY_SEPARATOR.$sourceFile;if(!is_file($sourcePath)&&StorageService::isGcsActive())StorageService::downloadFile('results/'.$sourceFile,$sourcePath);$info=@getimagesize($sourcePath);if(!$info)throw new RuntimeException('Mockup image is unavailable.');[$sw,$sh,$type]=$info;$source=match($type){IMAGETYPE_JPEG=>@imagecreatefromjpeg($sourcePath),IMAGETYPE_PNG=>@imagecreatefrompng($sourcePath),IMAGETYPE_WEBP=>@imagecreatefromwebp($sourcePath),default=>false};if(!$source)throw new RuntimeException('Unsupported mockup image.');
        $tw=1000;$th=1500;$scale=max($tw/$sw,$th/$sh)*$zoom;$cropW=$tw/$scale;$cropH=$th/$scale;$left=($sw-$cropW)*$x;$top=($sh-$cropH)*$y;$canvas=imagecreatetruecolor($tw,$th);imagecopyresampled($canvas,$source,0,0,(int)round($left),(int)round($top),$tw,$th,(int)round($cropW),(int)round($cropH));$dir=dirname(__DIR__,2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'pinterest_drafts';if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Cannot create Pinterest crop directory.');$file='pin-crop-'.$draftId.'-'.substr(hash('sha256',(string)$draft['media_token']),0,12).'.jpg';$path=$dir.DIRECTORY_SEPARATOR.$file;if(!imagejpeg($canvas,$path,92))throw new RuntimeException('Cannot save Pinterest crop.');imagedestroy($source);imagedestroy($canvas);if(StorageService::isGcsActive()&&!StorageService::uploadFile('pinterest-drafts/'.$file,$path))throw new RuntimeException('Cannot save Pinterest crop to persistent storage.');$this->pdo->prepare('UPDATE pinterest_pin_drafts SET crop_x=?,crop_y=?,crop_zoom=?,variant_file=?,variant_width=1000,variant_height=1500,updated_at=? WHERE id=? AND user_id=?')->execute([$x,$y,$zoom,$file,date('c'),$draftId,$userId]);return $this->draft($draftId,$userId);
    }

    public function markPublished(int $draftId,int $userId,string $externalId,string $externalUrl,array $payload): void
    {$this->pdo->prepare("UPDATE pinterest_pin_drafts SET status='published',external_id=?,external_url=?,payload_json=?,error='',updated_at=? WHERE id=? AND user_id=?")->execute([$externalId,$externalUrl,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),date('c'),$draftId,$userId]);$draft=$this->draft($draftId,$userId);$boardId=(string)($draft['board_id']??'');if($boardId!==''){$s=$this->pdo->prepare('SELECT id FROM pinterest_pin_destinations WHERE draft_id=? AND board_id=?');$s->execute([$draftId,$boardId]);$destinationId=(int)$s->fetchColumn();$now=date('c');if($destinationId>0)$this->pdo->prepare("UPDATE pinterest_pin_destinations SET status='published',external_id=?,external_url=?,error='',updated_at=? WHERE id=?")->execute([$externalId,$externalUrl,$now,$destinationId]);else $this->pdo->prepare('INSERT INTO pinterest_pin_destinations (draft_id,user_id,mockup_id,purpose,board_id,board_name,status,external_id,external_url,error,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$draftId,$userId,(int)$draft['mockup_id'],(string)$draft['purpose'],$boardId,(string)($draft['board_name']??''),'published',$externalId,$externalUrl,'',$now,$now]);}}

    public function markFailed(int $draftId,int $userId,string $error): void
    {$this->pdo->prepare("UPDATE pinterest_pin_drafts SET status='failed',error=?,updated_at=? WHERE id=? AND user_id=?")->execute([mb_substr($error,0,1000),date('c'),$draftId,$userId]);}

    private function tokens(string $value): array
    {
        $value=strtolower((string)preg_replace('/[^a-z0-9]+/i',' ',$value));return array_values(array_filter(explode(' ',$value),static fn(string $word):bool=>strlen($word)>2));
    }

    private function purpose(string $purpose,array $user): string
    {
        if(!in_array($purpose,['artist','platform'],true))throw new InvalidArgumentException('Invalid Pinterest identity.');
        if($purpose==='platform'&&!Auth::isAdmin($user))throw new RuntimeException('The Artwork Mockups Pinterest identity is administrator-only.');return $purpose;
    }
}
