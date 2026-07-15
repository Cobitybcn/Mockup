<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$pdo=Database::connection();$mysql=Database::isMysql();
$columns=$mysql?$pdo->query("SHOW COLUMNS FROM pinterest_connections")->fetchAll(PDO::FETCH_COLUMN):array_column($pdo->query('PRAGMA table_info(pinterest_connections)')->fetchAll(PDO::FETCH_ASSOC),'name');
$hasPurpose=in_array('purpose',$columns,true);
$total=(int)$pdo->query('SELECT COUNT(*) FROM pinterest_connections')->fetchColumn();
$report=['driver'=>Database::driver(),'has_purpose'=>$hasPurpose,'total_connections'=>$total,'by_purpose'=>[],'conflicts'=>0];
if($hasPurpose){
    $report['by_purpose']=$pdo->query('SELECT purpose,COUNT(*) AS count FROM pinterest_connections GROUP BY purpose ORDER BY purpose')->fetchAll(PDO::FETCH_ASSOC);
    $report['conflicts']=(int)$pdo->query('SELECT COUNT(*) FROM (SELECT user_id,purpose FROM pinterest_connections GROUP BY user_id,purpose HAVING COUNT(*)>1) duplicate_connections')->fetchColumn();
}else{
    $report['legacy_admin_connections']=(int)$pdo->query('SELECT COUNT(*) FROM pinterest_connections pc INNER JOIN users u ON u.id=pc.user_id WHERE u.is_admin=1')->fetchColumn();
    $report['legacy_artist_connections']=$total-$report['legacy_admin_connections'];
}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
