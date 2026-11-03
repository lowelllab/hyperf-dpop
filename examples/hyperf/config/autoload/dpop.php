<?php
declare(strict_types=1);
$options = require dirname(__DIR__, 4) . '/packages/hyperf-dpop/publish/dpop.php';
return array_replace($options, [
    'origin' => getenv('DPOP_EXAMPLE_ORIGIN') ?: (getenv('DPOP_EXAMPLE_TLS_CERT') ? 'https://api.example:9510' : 'https://api.example'),
    'domain' => 'isolated-example',
    // Synthetic fixture only; never reuse this public value in production.
    'nonce_key' => str_repeat('synthetic-test-nonce-key-', 3),
    'namespace' => 'isolated-example',
    'mysql_dsn' => (string) getenv('DPOP_TEST_MYSQL_DSN'),
    'mysql_user' => (string) getenv('DPOP_TEST_MYSQL_USER'),
    'mysql_password' => (string) getenv('DPOP_TEST_MYSQL_PASSWORD'),
    'global_limit' => 10000, 'source_limit' => 5000,
]);
