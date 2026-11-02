<?php
declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'origin' => env('DPOP_ORIGIN', ''),
    'internal_prefix' => '',
    'external_prefix' => '',
    'domain' => env('DPOP_SECURITY_DOMAIN', ''),
    'nonce_key' => env('DPOP_NONCE_KEY', ''),
    'previous_nonce_key' => env('DPOP_PREVIOUS_NONCE_KEY', ''),
    'past_seconds' => 60, 'future_seconds' => 30, 'clock_skew' => 5,
    'replay_ttl' => 120, 'proof_max_bytes' => 8192, 'nonce_ttl' => 300,
    'replay_driver' => 'mysql',
    'mysql_dsn' => env('DPOP_MYSQL_DSN', ''),
    'mysql_user' => env('DPOP_MYSQL_USER', ''),
    'mysql_password' => env('DPOP_MYSQL_PASSWORD', ''),
    'mysql_table' => 'dpop_proofs',
    'namespace' => env('DPOP_REDIS_NAMESPACE', 'dpop'),
    'trusted_proxies' => [],
    'global_limit' => 2000, 'source_limit' => 120,
    'budget_window' => 60, 'max_concurrency' => 16,
];
