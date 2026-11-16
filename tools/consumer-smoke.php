<?php
declare(strict_types=1);
// Separate Composer project and PHP process: root dev autoloading cannot hide missing package dependencies.
$root=dirname(__DIR__);
if(!is_dir($root.'/build') && !mkdir($root.'/build',0700,true) && !is_dir($root.'/build')){throw new RuntimeException('Consumer log directory unavailable');}
$consumer=sys_get_temp_dir().'/dpop-consumer-'.bin2hex(random_bytes(6));mkdir($consumer,0700,true);
$workspace=json_decode(file_get_contents($root.'/composer.json'),true,flags:JSON_THROW_ON_ERROR);
$require=array_intersect_key($workspace['require'],array_flip(['php','lowelllab/hyperf-dpop']));
$repositories=$workspace['repositories'];
$repositories[0]['url']=$root.'/'.$repositories[0]['url'];
$metadata=['name'=>'fixture/dpop-consumer','require'=>$require,'repositories'=>$repositories,'config'=>['allow-plugins'=>new stdClass()]];
// This is an unpublished path development build; the consumer must explicitly opt into dev stability.
$metadata['minimum-stability']='dev';$metadata['prefer-stable']=true;
file_put_contents($consumer.'/composer.json',json_encode($metadata,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$process=proc_open([PHP_BINARY,'/usr/local/bin/composer','install','--no-dev','--no-interaction','--prefer-dist'],[0=>['pipe','r'],1=>['file',$root.'/build/consumer-install.log','a'],2=>['file',$root.'/build/consumer-install.log','a']],$pipes,$consumer);
if(!is_resource($process)){throw new RuntimeException('Consumer Composer start failed');}fclose($pipes[0]);if(proc_close($process)!==0){throw new RuntimeException('Consumer install failed; see build/consumer-install.log');}
$check=<<<'PHP'
<?php
require __DIR__.'/vendor/autoload.php';
define('BASE_PATH',__DIR__);
if(Composer\InstalledVersions::isInstalled('phpunit/phpunit')){throw new RuntimeException('Root dev dependencies leaked into consumer');}
foreach([Acme\Dpop\Authenticator::class,Acme\Dpop\Protocol\ProofVerifier::class,Acme\HyperfDpop\Middleware\DpopMiddleware::class,Acme\HyperfDpop\BootstrapValidator::class] as $class){if(!class_exists($class)){throw new RuntimeException('Package class unavailable');}}
new Acme\Dpop\Protocol\ProofVerifier(new Acme\Dpop\ValueObject\SecurityPolicy());
$config=(new Acme\HyperfDpop\ConfigProvider())();
if(!isset($config['dependencies']) || count($config['commands'])!==2){throw new RuntimeException('Required CLI commands were not registered');}
if(Composer\InstalledVersions::isInstalled('hyperf/redis') || Composer\InstalledVersions::isInstalled('hyperf/devtool')){throw new RuntimeException('Optional host dependencies leaked into base installation');}
echo "Clean Composer consumer: package autoload and required CLI valid without optional host dependencies\n";
PHP;
file_put_contents($consumer.'/check.php',$check);
$process=proc_open([PHP_BINARY,$consumer.'/check.php'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,$consumer);
if(!is_resource($process)){throw new RuntimeException('Consumer PHP start failed');}fclose($pipes[0]);if(proc_close($process)!==0){throw new RuntimeException('Consumer verification failed');}
