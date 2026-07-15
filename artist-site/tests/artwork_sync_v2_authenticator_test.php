<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/ArtworkSyncV2Authenticator.php';
$secret=str_repeat('s',32);$raw='{"schema_version":"2.0"}';$timestamp='1783828800';$signature=hash_hmac('sha256',$timestamp."\n".$raw,$secret);$auth=new ArtworkSyncV2Authenticator($secret);$auth->verify($raw,$timestamp,$signature,1783828800);$blocked=0;
foreach([[$raw,$timestamp,str_repeat('0',64),1783828800],[$raw,$timestamp,$signature,1783829200]] as $args){try{$auth->verify(...$args);}catch(RuntimeException){$blocked++;}}
if($blocked!==2){fwrite(STDERR,"FAIL: signed sync authentication checks failed.\n");exit(1);}echo "PASS: valid HMAC accepted; altered and expired requests rejected.\n";
