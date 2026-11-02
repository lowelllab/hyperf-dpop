<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Store;

/** Hyperf Redis returns pooled connections after each command; no request state is kept. */
final readonly class HyperfRedisExecutor implements RedisExecutorInterface
{
    public function __construct(private \Hyperf\Redis\Redis $redis) {}
    public function evaluate(string $script, array $keys, array $arguments): mixed { return $this->redis->eval($script, array_merge($keys, $arguments), count($keys)); }
    public function ping(): bool { return (bool) $this->redis->ping(); }
}
