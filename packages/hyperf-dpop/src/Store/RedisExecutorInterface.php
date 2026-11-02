<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Store;

interface RedisExecutorInterface
{
    /** @param list<string> $keys @param list<string|int> $arguments */
    public function evaluate(string $script, array $keys, array $arguments): mixed;
    public function ping(): bool;
}
