<?php
declare(strict_types=1);

final class PinterestBatchService
{
    public function __construct(private PDO $pdo,private MockupPinterestDraftService $drafts){}

    public function create(array $mockupIds,array $user,string $purpose,string $destinationUrl): int
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$mockupIds),fn($id)=>$id>0)));
        if(!$ids||count($ids)>10)throw new InvalidArgumentException('Select between 1 and 10 mockups.');
        if(!filter_var($destinationUrl,FILTER_VALIDATE_URL)||parse_url($destinationUrl,PHP_URL_SCHEME)!=='https')throw new InvalidArgumentException('Enter a public HTTPS destination.');
        $now=date('c');$this->pdo->beginTransaction();
        try{$this->pdo->prepare('INSERT INTO pinterest_batches (user_id,purpose,destination_url,status,created_at,updated_at) VALUES (?,?,?,?,?,?)')->execute([(int)$user['id'],$purpose,$destinationUrl,'draft',$now,$now]);$batchId=(int)$this->pdo->lastInsertId();
            foreach($ids as $position=>$mockupId){$draft=$this->drafts->create($mockupId,$user,$purpose,$destinationUrl);$this->pdo->prepare('INSERT INTO pinterest_batch_items (batch_id,draft_id,position,status) VALUES (?,?,?,?)')->execute([$batchId,(int)$draft['id'],$position,'draft']);}
            $this->pdo->commit();return $batchId;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function batch(int $batchId,int $userId): array
    {$s=$this->pdo->prepare('SELECT * FROM pinterest_batches WHERE id=? AND user_id=?');$s->execute([$batchId,$userId]);$b=$s->fetch(PDO::FETCH_ASSOC);if(!$b)throw new RuntimeException('Pinterest batch not found.');return $b;}

    public function items(int $batchId,int $userId): array
    {$this->batch($batchId,$userId);$s=$this->pdo->prepare('SELECT d.*,m.mockup_file,bi.status item_status FROM pinterest_batch_items bi JOIN pinterest_pin_drafts d ON d.id=bi.draft_id JOIN mockups m ON m.id=d.mockup_id WHERE bi.batch_id=? ORDER BY bi.position');$s->execute([$batchId]);return $s->fetchAll(PDO::FETCH_ASSOC);}

    public function selectBoards(array $draft,int $userId,array $boardIds,array $boards): void
    {
        $ids=array_values(array_unique(array_filter(array_map('strval',$boardIds))));if(!$ids||count($ids)>3)throw new InvalidArgumentException('Choose between 1 and 3 boards.');
        $available=[];foreach($boards as $b)$available[(string)($b['id']??'')]=$b;
        $published=$this->publishedBoardIds($userId,(int)$draft['mockup_id'],(string)$draft['purpose']);
        foreach($ids as $id)if(!isset($available[$id])||isset($published[$id]))throw new RuntimeException('A selected board is unavailable or was already published.');
        $this->pdo->beginTransaction();try{$this->pdo->prepare("DELETE FROM pinterest_pin_destinations WHERE draft_id=? AND status<>'published'")->execute([(int)$draft['id']]);$now=date('c');$q=$this->pdo->prepare('INSERT INTO pinterest_pin_destinations (draft_id,user_id,mockup_id,purpose,board_id,board_name,status,external_url,error,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');foreach($ids as $id)$q->execute([(int)$draft['id'],$userId,(int)$draft['mockup_id'],(string)$draft['purpose'],$id,(string)($available[$id]['name']??''),'selected','','',$now,$now]);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function destinations(int $draftId): array
    {$s=$this->pdo->prepare('SELECT * FROM pinterest_pin_destinations WHERE draft_id=? ORDER BY board_name');$s->execute([$draftId]);return $s->fetchAll(PDO::FETCH_ASSOC);}

    public function publishedBoardIds(int $userId,int $mockupId,string $purpose): array
    {$s=$this->pdo->prepare("SELECT board_id,external_url FROM pinterest_pin_destinations WHERE user_id=? AND mockup_id=? AND purpose=? AND status='published'");$s->execute([$userId,$mockupId,$purpose]);$out=[];foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$out[(string)$r['board_id']]=(string)$r['external_url'];return $out;}

    public function markDestination(int $destinationId,string $status,string $externalId='',string $externalUrl='',string $error=''): void
    {$this->pdo->prepare('UPDATE pinterest_pin_destinations SET status=?,external_id=?,external_url=?,error=?,updated_at=? WHERE id=?')->execute([$status,$externalId,$externalUrl,mb_substr($error,0,1000),date('c'),$destinationId]);}
}
