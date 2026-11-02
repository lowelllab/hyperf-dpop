<?php
declare(strict_types=1);
$root=dirname(__DIR__);$count=0;
$filter=new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),static fn($item)=>!$item->isDir() || !in_array($item->getFilename(),['vendor','build','.git','.phpunit.cache','runtime'],true));
foreach(new RecursiveIteratorIterator($filter) as $file){
    if($file->getExtension()!=='md'){continue;}$content=file_get_contents($file->getPathname());
    if(!mb_check_encoding($content,'UTF-8')){throw new RuntimeException('Non UTF-8 document: '.$file->getFilename());}
    if(preg_match_all('/^```/m',$content)%2!==0){throw new RuntimeException('Unbalanced fences: '.$file->getFilename());}
    $prose=preg_replace('/^```[^\n]*\n.*?^```\s*$/ms','',$content);
    preg_match_all('/\]\(([^)]+)\)/',$prose,$matches);
    foreach($matches[1] as $target){if(preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/',$target) || str_starts_with($target,'#')){continue;}$path=explode('#',$target,2)[0];if(!file_exists($file->getPath().'/'.$path)){throw new RuntimeException('Missing document reference: '.$target);}}
    ++$count;
}
echo "Documentation: {$count} UTF-8 files, local references and fences valid\n";
