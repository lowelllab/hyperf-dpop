<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use DpopTest\Support\Environment;

Environment::schema();$root=dirname(__DIR__);$app=sys_get_temp_dir().'/dpop-package-cli-'.bin2hex(random_bytes(6));
mkdir($app.'/config/autoload',0700,true);mkdir($app.'/runtime',0700,true);
foreach(['composer.json','composer.lock','vendor'] as $name){symlink($root.'/'.$name,$app.'/'.$name);}
symlink($root.'/examples/hyperf/app',$app.'/app');
foreach(glob($root.'/examples/hyperf/config/autoload/*.php') as $source){file_put_contents($app.'/config/autoload/'.basename($source),'<?php return require '.var_export($source,true).';');}
file_put_contents($app.'/config/config.php',"<?php return ['app_name'=>'dpop-cli-audit','app_env'=>'prod','scan_cacheable'=>true];");
file_put_contents($app.'/config/container.php',file_get_contents($root.'/examples/hyperf/config/container.php'));
file_put_contents($app.'/config/routes.php','<?php return require '.var_export($root.'/examples/hyperf/config/routes.php',true).';');
$bootstrap='<?php declare(strict_types=1); define("BASE_PATH",__DIR__); require '.var_export($root.'/vendor/autoload.php',true).'; Hyperf\\Di\\ClassLoader::init(); $container=require BASE_PATH."/config/container.php"; $container->get(Hyperf\\Contract\\ApplicationInterface::class)->run();';
file_put_contents($app.'/bin.php',str_replace('\\\\','\\',$bootstrap));
$baseOptions=require $root.'/examples/hyperf/config/autoload/dpop.php';
$baseDependencies=require $root.'/examples/hyperf/config/autoload/dependencies.php';
$writeOptions=static function(array $changes=[])use($app,$baseOptions):void{file_put_contents($app.'/config/autoload/dpop.php','<?php return '.var_export(array_replace($baseOptions,$changes),true).';');};
$writeDependencies=static function(array $changes=[],bool $removeToken=false)use($app,$baseDependencies):void{$values=array_replace($baseDependencies,$changes);if($removeToken){unset($values[Acme\HyperfDpop\Contract\TokenBindingResolverInterface::class]);}file_put_contents($app.'/config/autoload/dependencies.php','<?php return '.var_export($values,true).';');};
$writeOptions();$writeDependencies();$cases=[];$sequence=0;
$run=static function(string $name,array $arguments,int $expected=0)use($root,$app,&$cases,&$sequence):string{
    $log=$root.'/build/review2-cli-'.(++$sequence).'.log';
    $process=proc_open(array_merge([PHP_BINARY,$app.'/bin.php'],$arguments),[0=>['pipe','r'],1=>['file',$log,'w'],2=>['file',$log,'a']],$pipes,$app);
    if(!is_resource($process)){throw new RuntimeException('CLI process unavailable');}fclose($pipes[0]);$code=proc_close($process);$output=file_get_contents($log);
    if($code!==$expected || str_contains($output,'Fatal error') || str_contains($output,'Deprecated:')){throw new RuntimeException($name.' failed (exit '.$code.'); see '.$log);}
    $cases[]=['case'=>$name,'exit'=>$code,'result'=>'PASS'];return $output;
};
$before=(int)Environment::pdo()->query('SELECT COUNT(*) FROM dpop_proofs')->fetchColumn();
$run('production-cold-doctor',['dpop:doctor']);
if(count(glob($app.'/runtime/container/*.cache'))===0){throw new RuntimeException('Production cache not produced');}
$run('production-warm-doctor',['dpop:doctor']);
foreach(['nonce_key'=>'','origin'=>'http://insecure.example','replay_ttl'=>10,'internal_prefix'=>'//ambiguous','domain'=>''] as $key=>$value){$writeOptions([$key=>$value]);$run('invalid-config-'.$key,['dpop:doctor'],1);}$writeOptions();
$writeDependencies(removeToken:true);$run('missing-token-resolver',['dpop:doctor'],1);$writeDependencies();
$writeDependencies([Acme\Dpop\Contract\ReplayStoreInterface::class=>DpopTest\Support\MemoryReplay::class]);
$custom=$run('custom-store-unverified',['dpop:doctor'],1);
if(!str_contains($custom,'unverified')){throw new RuntimeException('Custom store health falsely reported');}
$writeDependencies([Acme\Dpop\Contract\ReplayStoreInterface::class=>DpopTest\Support\ProbeableReplay::class]);
$run('custom-store-readonly-probe',['dpop:doctor']);$writeDependencies();
$writeDependencies([Acme\HyperfDpop\Admission\AdmissionBudgetInterface::class=>DpopTest\Support\TrackingBudget::class]);
$custom=$run('custom-budget-unverified',['dpop:doctor'],1);
if(!str_contains($custom,'unverified')){throw new RuntimeException('Custom budget health falsely reported');}
$writeDependencies([Acme\HyperfDpop\Admission\AdmissionBudgetInterface::class=>DpopTest\Support\ProbeableBudget::class]);
$run('custom-budget-readonly-probe',['dpop:doctor']);$writeDependencies();
$run('publish-ddl',['vendor:publish','acme/hyperf-dpop','--id=schema']);
if(hash_file('sha256',$app.'/dpop-proofs.sql')!==hash_file('sha256',$root.'/packages/hyperf-dpop/publish/dpop-proofs.sql')){throw new RuntimeException('Published DDL differs');}
$original=file_get_contents($app.'/config/autoload/dpop.php');
$run('publish-config-preserves-existing',['vendor:publish','acme/hyperf-dpop','--id=config']);
if(file_get_contents($app.'/config/autoload/dpop.php')!==$original){throw new RuntimeException('Config overwritten without force');}
$run('publish-config-explicit-force',['vendor:publish','acme/hyperf-dpop','--id=config','--force']);
if(hash_file('sha256',$app.'/config/autoload/dpop.php')!==hash_file('sha256',$root.'/packages/hyperf-dpop/publish/dpop.php')){throw new RuntimeException('Published config differs');}
if($before!==(int)Environment::pdo()->query('SELECT COUNT(*) FROM dpop_proofs')->fetchColumn()){throw new RuntimeException('Doctor/publishing wrote authentication data');}
file_put_contents($root.'/build/review2-cli-result.json',json_encode(['cases'=>$cases,'production_cache'=>true,'authentication_writes'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo count($cases)." production CLI scenarios passed: cold/warm cache, fail-closed config, custom probes, config/DDL publishing; no authentication writes\n";
