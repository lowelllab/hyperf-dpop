<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('UTC');
$projectRoot = dirname(__DIR__, 3);
$applicationRoot = sys_get_temp_dir() . '/hyperf-dpop-example-' . substr(hash('sha256', $projectRoot), 0, 12);
if (!is_dir($applicationRoot . '/runtime')) { mkdir($applicationRoot . '/runtime', 0700, true); }
foreach (['composer.json'=>$projectRoot . '/composer.json', 'composer.lock'=>$projectRoot . '/composer.lock', 'vendor'=>$projectRoot . '/vendor', 'config'=>dirname(__DIR__) . '/config', 'app'=>dirname(__DIR__) . '/app'] as $name=>$target) {
    if (!file_exists($applicationRoot . '/' . $name)) { symlink($target, $applicationRoot . '/' . $name); }
}
define('BASE_PATH', $applicationRoot);
require $projectRoot . '/vendor/autoload.php';
Hyperf\Di\ClassLoader::init();
$container = require BASE_PATH . '/config/container.php';
$container->get(Hyperf\Contract\ApplicationInterface::class)->run();
