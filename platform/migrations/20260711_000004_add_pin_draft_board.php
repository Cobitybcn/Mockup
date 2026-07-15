<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
$direction=$argv[1]??'up';$pdo=Database::connection();$mysql=Database::isMysql();
if($direction!=='up')throw new RuntimeException('This additive migration is not rolled back separately; remove the drafts table with migration 000003 down.');
if($mysql){foreach(['board_id'=>'VARCHAR(190)','board_name'=>'VARCHAR(255)'] as $column=>$type){$stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$stmt->execute(['pinterest_pin_drafts',$column]);if(!(int)$stmt->fetchColumn())$pdo->exec("ALTER TABLE pinterest_pin_drafts ADD COLUMN {$column} {$type} NOT NULL DEFAULT '' AFTER board_suggestion");}}
else{$columns=array_column($pdo->query('PRAGMA table_info(pinterest_pin_drafts)')->fetchAll(PDO::FETCH_ASSOC),'name');foreach(['board_id','board_name'] as $column)if(!in_array($column,$columns,true))$pdo->exec("ALTER TABLE pinterest_pin_drafts ADD COLUMN {$column} TEXT NOT NULL DEFAULT ''");}
echo "Pinterest draft board fields ready.\n";
