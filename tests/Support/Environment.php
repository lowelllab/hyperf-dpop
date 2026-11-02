<?php
declare(strict_types=1);
namespace DpopTest\Support;

use Acme\HyperfDpop\Store\{RedisExecutorInterface, MySqlReplayStore, RedisReplayStore};

final class Environment
{
    public static function pdo(): \PDO
    {
        return new \PDO((string) getenv('DPOP_TEST_MYSQL_DSN'), (string) getenv('DPOP_TEST_MYSQL_USER'), (string) getenv('DPOP_TEST_MYSQL_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_PERSISTENT => false, \PDO::ATTR_EMULATE_PREPARES => false, \PDO::ATTR_TIMEOUT => 3]);
    }

    public static function schema(): void
    {
        self::pdo()->exec(file_get_contents(__DIR__ . '/../../packages/hyperf-dpop/publish/dpop-proofs.sql'));
    }

    public static function redis(): RedisExecutorInterface
    {
        return new class implements RedisExecutorInterface {
            private \Redis $redis;
            public function __construct() { $this->redis = new \Redis(); $this->redis->connect((string) getenv('DPOP_TEST_REDIS_HOST'), 6379, 2); }
            public function evaluate(string $script, array $keys, array $arguments): mixed { return $this->redis->eval($script, array_merge($keys, $arguments), count($keys)); }
            public function ping(): bool { return (bool) $this->redis->ping(); }
        };
    }

    public static function store(string $driver): MySqlReplayStore|RedisReplayStore
    {
        return $driver === 'mysql' ? new MySqlReplayStore(self::pdo(...)) : new RedisReplayStore(self::redis(), 'dpop-test');
    }
}
