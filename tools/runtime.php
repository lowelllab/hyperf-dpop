<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$framework = Composer\InstalledVersions::getPrettyVersion('hyperf/framework');
if (PHP_VERSION_ID < 80512 || !extension_loaded('swoole') || version_compare(phpversion('swoole'), '6.2.4', '<') || !str_starts_with(ltrim($framework, 'v'), '3.2.')) { throw new RuntimeException('Unexpected validation runtime'); }
echo json_encode(['php'=>PHP_VERSION, 'swoole'=>phpversion('swoole'), 'hyperf'=>$framework, 'redis'=>phpversion('redis'), 'jose'=>Composer\InstalledVersions::getPrettyVersion('web-token/jwt-library')], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
