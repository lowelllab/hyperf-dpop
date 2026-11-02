<?php
declare(strict_types=1);

// Verify actual VCS installation from two local main branches, including the branch aliases.
$root=dirname(__DIR__);$directory=json_decode(file_get_contents($root.'/build/packagist-latest.json'),true,flags:JSON_THROW_ON_ERROR)['directory'];
$directory=realpath($directory);$prefix=realpath($root.'/build/packagist').DIRECTORY_SEPARATOR;
if($directory===false || !str_starts_with($directory,$prefix)){throw new RuntimeException('Unexpected package output path');}
$manifest=json_decode(file_get_contents($directory.'/manifest.json'),true,flags:JSON_THROW_ON_ERROR);
$log=$directory.'/verification.log';
$run=static function(array $command,string $cwd)use($log):void{
    $p=proc_open($command,[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$cwd);
    if(!is_resource($p)){throw new RuntimeException('Verification command unavailable');}fclose($pipes[0]);
    if(proc_close($p)!==0){throw new RuntimeException('Package verification failed; see '.$log);}
};
$repositories=[];
foreach($manifest['packages'] as $name=>$package){
    $path=$package['directory'];
    foreach($package['files'] as $file=>$expected){if(hash_file('sha256',$path.'/'.$file)!==$expected){throw new RuntimeException('Export checksum mismatch');}}
    $run([PHP_BINARY,'/usr/local/bin/composer','validate','--strict'],$path);
    if(file_exists($path.'/.git')){throw new RuntimeException('Verify a fresh export; existing repositories are preserved');}
    $run(['git','init','--quiet','-b','main'],$path);
    $run(['git','add','--all'],$path);
    $run(['git','-c','user.name=Package export fixture','-c','user.email=package-fixture@example.invalid','commit','--quiet','-m','Local package export verification'],$path);
    $repositories[]=['type'=>'vcs','url'=>$path];
}
$consumer=$directory.'/consumer';mkdir($consumer,0700);
$metadata=['name'=>'fixture/export-consumer','require'=>['php'=>'^8.5','lowelllab/hyperf-dpop'=>'^0.1@dev'],'minimum-stability'=>'dev','prefer-stable'=>true,'repositories'=>$repositories,'config'=>['allow-plugins'=>new stdClass()]];
file_put_contents($consumer.'/composer.json',json_encode($metadata,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$run([PHP_BINARY,'/usr/local/bin/composer','install','--no-dev','--no-interaction','--prefer-dist'],$consumer);
$check=<<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';
define('BASE_PATH',__DIR__);
foreach(['lowelllab/dpop-core','lowelllab/hyperf-dpop'] as $name){
    if(!Composer\InstalledVersions::isInstalled($name)){throw new RuntimeException('Published package name unavailable');}
    $path=Composer\InstalledVersions::getInstallPath($name);
    if(!str_starts_with(realpath($path),__DIR__.'/vendor/')){throw new RuntimeException('Consumer package must be installed independently');}
}
foreach([Acme\Dpop\Authenticator::class,Acme\HyperfDpop\Middleware\DpopMiddleware::class] as $class){if(!class_exists($class)){throw new RuntimeException('Existing PHP API unavailable');}}
if(Composer\InstalledVersions::isInstalled('acme/dpop-core') || Composer\InstalledVersions::isInstalled('acme/hyperf-dpop') || class_exists('DpopTest\Support\ProofFactory')){throw new RuntimeException('Old package names or workspace test autoload leaked');}
new Acme\Dpop\Protocol\ProofVerifier(new Acme\Dpop\ValueObject\SecurityPolicy());
$config=(new Acme\HyperfDpop\ConfigProvider())();
if(!isset($config['dependencies']) || $config['commands']!==[]){throw new RuntimeException('ConfigProvider or optional commands invalid');}
echo "Two exported VCS main branches install under lowelllab; core dependency, branch aliases and PHP APIs valid\n";
PHP;
file_put_contents($consumer.'/check.php',$check);$run([PHP_BINARY,$consumer.'/check.php'],$consumer);
file_put_contents($directory.'/verification.json',json_encode(['result'=>'PASS','composer_names'=>array_keys($manifest['packages']),'vcs_branches'=>'main','branch_aliases'=>'0.1.x-dev','core_transitive_dependency'=>true,'workspace_dev_autoload'=>false,'remote_push'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
echo "Two-package exports, strict metadata and clean VCS consumer verified; no remote push\n";
