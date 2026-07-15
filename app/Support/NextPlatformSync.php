<?php
declare(strict_types=1);

final class NextPlatformSync
{
    public static function run(): void
    {
        $nextRoot=dirname(__DIR__,4);
        $script=$nextRoot.DIRECTORY_SEPARATOR.'tools'.DIRECTORY_SEPARATOR.'import_faithful_to_next.php';
        if(!is_file($script))return;
        $php=PHP_BINARY;
        if(str_contains(strtolower($php),'apache')||str_contains(strtolower($php),'httpd')){
            $configured=defined('PHP_BINARY_PATH')?trim((string)PHP_BINARY_PATH):'';
            $php=$configured!==''?$configured:'php';
        }
        $command='"'.$php.'" "'.$script.'"';
        $descriptor=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $process=@proc_open($command,$descriptor,$pipes,$nextRoot);
        if(!is_resource($process)){error_log('NextPlatformSync could not start.');return;}
        fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        if($exit!==0)error_log('NextPlatformSync failed: '.trim($stderr?:$stdout));
    }
}
