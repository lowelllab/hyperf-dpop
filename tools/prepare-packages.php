<?php
declare(strict_types=1);

// Prepare two package roots locally; never create remotes, tags or Packagist submissions.
$root=dirname(__DIR__);
$output=$root.'/build/packagist/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
if(!mkdir($output,0700,true)){throw new RuntimeException('Cannot create package output');}
$manifest=['directory'=>$output,'packages'=>[]];
foreach(['dpop-core','hyperf-dpop'] as $package){
    $source=$root.'/packages/'.$package;$target=$output.'/'.$package;$files=[];
    foreach(['composer.json','README.md','LICENSE','src','publish'] as $entry){
        $path=$source.'/'.$entry;if(!file_exists($path)){continue;}
        $paths=is_dir($path)?new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS)):[$path];
        foreach($paths as $item){
            $from=(string)$item;if(is_link($from) || !is_file($from)){throw new RuntimeException('Package export must contain regular files');}
            $relative=substr($from,strlen($source)+1);$to=$target.'/'.$relative;
            if(!is_dir(dirname($to)) && !mkdir(dirname($to),0700,true)){throw new RuntimeException('Cannot create package directory');}
            if(!copy($from,$to)){throw new RuntimeException('Package copy failed');}
            $files[str_replace('\\','/',$relative)]=hash_file('sha256',$to);
        }
    }
    $metadata=json_decode(file_get_contents($target.'/composer.json'),true,flags:JSON_THROW_ON_ERROR);
    if(($metadata['name']??null)!=='lowelllab/'.$package || ($metadata['type']??null)!=='library'
        || isset($metadata['repositories']) || isset($metadata['require-dev'])){throw new RuntimeException('Unexpected package metadata');}
    ksort($files);$manifest['packages'][$metadata['name']]=['directory'=>$target,'files'=>$files];
    echo $metadata['name'].' prepared: '.count($files).' files at '.$target."\n";
}
file_put_contents($output.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
file_put_contents($root.'/build/packagist-latest.json',json_encode(['directory'=>$output],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
