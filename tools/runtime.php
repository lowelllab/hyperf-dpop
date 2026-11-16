<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

Acme\HyperfDpop\RuntimeRequirements::assertSupported();
$expected = getenv('DPOP_EXPECTED_PHP');
$framework = Composer\InstalledVersions::getPrettyVersion('hyperf/framework');
if ($expected !== PHP_VERSION || $framework === null || !str_starts_with(ltrim($framework, 'v'), '3.2.')) {
    throw new RuntimeException('Unexpected validation runtime or Hyperf version');
}

foreach (['openssl', 'pcntl', 'pdo_mysql', 'redis', 'sockets'] as $extension) {
    if (!extension_loaded($extension)) {
        throw new RuntimeException('Missing validation extension: ' . $extension);
    }
}

echo json_encode([
    'php' => PHP_VERSION,
    'swoole' => phpversion('swoole'),
    'hyperf' => $framework,
    'hyperf_di' => Composer\InstalledVersions::getPrettyVersion('hyperf/di'),
    'redis' => phpversion('redis'),
    'openssl' => OPENSSL_VERSION_TEXT,
    'jose' => Composer\InstalledVersions::getPrettyVersion('web-token/jwt-library'),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
