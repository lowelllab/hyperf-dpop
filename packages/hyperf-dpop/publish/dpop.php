<?php
declare(strict_types=1);

use function Hyperf\Support\env;

return [
    // Public HTTPS endpoint and optional path rewriting.
    'origin' => env('DPOP_ORIGIN', ''),
    'internal_prefix' => '',
    'external_prefix' => '',

    // DPoP proof time bounds, replay retention, and nonce rotation. Times are seconds.
    'domain' => env('DPOP_SECURITY_DOMAIN', ''),
    'nonce_key' => env('DPOP_NONCE_KEY', ''),
    'previous_nonce_key' => env('DPOP_PREVIOUS_NONCE_KEY', ''),
    'past_seconds' => 60,
    'future_seconds' => 30,
    'clock_skew' => 5,
    'proof_max_bytes' => 8192,
    'nonce_ttl' => 300,
    'replay_ttl' => 120,

    // Shared replay ledger. MySQL uses an independent, dedicated connection.
    'replay_driver' => 'mysql',
    'mysql_dsn' => env('DPOP_MYSQL_DSN', ''),
    'mysql_user' => env('DPOP_MYSQL_USER', ''),
    'mysql_password' => env('DPOP_MYSQL_PASSWORD', ''),
    'mysql_table' => 'dpop_proofs',
    'mysql_connect_timeout' => 3,

    // Shared admission budget and optional Redis replay. Connection details stay in redis.php.
    'redis_pool' => 'default',
    'namespace' => env('DPOP_REDIS_NAMESPACE', 'dpop'),
    'trusted_proxies' => [],
    'global_limit' => 2000,
    'source_limit' => 120,
    'budget_window' => 60,
    'max_concurrency' => 16,

    // Require a verified client certificate on the generated gateway listener.
    // List only the direct peers authorized to attest successful mTLS, using exact IPs.
    'mtls_enabled' => env('DPOP_MTLS_ENABLED', false),
    'mtls_trusted_proxies' => [],

    // Used by dpop:gateway-config. Paths and addresses are from HAProxy's environment.
    'gateway_bind' => env('DPOP_GATEWAY_BIND', ''),
    'gateway_cert' => env('DPOP_GATEWAY_CERT', ''),
    'gateway_backend' => env('DPOP_BACKEND_ADDR', ''),
    'mtls_client_ca' => env('DPOP_MTLS_CLIENT_CA', ''),
    'mtls_client_crl' => env('DPOP_MTLS_CLIENT_CRL', ''),
];
