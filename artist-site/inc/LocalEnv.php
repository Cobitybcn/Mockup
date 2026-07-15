<?php
declare(strict_types=1);

function load_local_env(string $file): void
{
    if(!is_file($file))return;
    foreach(file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
        $line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
        [$key,$value]=array_map('trim',explode('=',$line,2));if($key===''||getenv($key)!==false)continue;
        if(strlen($value)>=2&&(($value[0]==='"'&&str_ends_with($value,'"'))||($value[0]==="'"&&str_ends_with($value,"'"))))$value=substr($value,1,-1);
        putenv($key.'='.$value);$_ENV[$key]=$value;
    }
}
