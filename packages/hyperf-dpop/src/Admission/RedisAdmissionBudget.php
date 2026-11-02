<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Admission;

use Acme\Dpop\Exception\DpopException;
use Acme\HyperfDpop\Store\RedisExecutorInterface;

final class RedisAdmissionBudget implements AdmissionBudgetInterface, AdmissionBudgetProbeInterface
{
    private int $active = 0;
    private const SCRIPT = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local window = tonumber(ARGV[1])
local bucket = math.floor(now/window)
local retry = -(window - (now % window))
local global = KEYS[1] .. ':' .. bucket
local n = redis.call('INCR', global)
if n == 1 then redis.call('EXPIRE', global, window+1) end
if n > tonumber(ARGV[2]) then return retry end
local source = KEYS[2] .. ':' .. bucket
local s = redis.call('INCR', source)
if s == 1 then redis.call('EXPIRE', source, window+1) end
if s > tonumber(ARGV[3]) then return retry end
return 1
LUA;

    public function __construct(private readonly RedisExecutorInterface $redis, private readonly string $namespace = 'dpop', private readonly int $globalLimit = 2000, private readonly int $sourceLimit = 120, private readonly int $window = 60, private readonly int $concurrency = 16)
    {
        if (preg_match('/^[A-Za-z0-9:_-]{1,64}$/D', $namespace) !== 1 || $globalLimit < 1 || $globalLimit > 1000000 || $sourceLimit < 1 || $window < 1 || $window > 300 || $concurrency < 1 || $concurrency > 10000) { throw new \InvalidArgumentException('Unsafe admission policy'); }
    }

    public function probe(): void
    {
        try { if (!$this->redis->ping()) { throw new DpopException('dpop_security_unavailable'); } }
        catch (\Throwable) { throw new DpopException('dpop_security_unavailable'); }
    }

    public function enter(string $source, string $category): \Closure
    {
        if (!in_array($category, ['token', 'refresh', 'resource'], true)) { throw new \InvalidArgumentException('Unknown admission category'); }
        if ($this->active >= $this->concurrency) { throw new DpopException('rate_limited', retryAfter: 1); }
        ++$this->active;
        try {
            // One hash tag keeps both keys in the same Redis Cluster slot. Global budget limits source-key creation.
            $prefix = $this->namespace . ':{admission}';
            $accepted = $this->redis->evaluate(self::SCRIPT, [$prefix . ':global', $prefix . ':' . $category . ':source:' . hash('sha256', $source)], [$this->window, $this->globalLimit, $this->sourceLimit]);
            if (is_int($accepted) && $accepted < 0 && $accepted >= -$this->window) { throw new DpopException('rate_limited', retryAfter: -$accepted); }
            if ($accepted !== 1) { throw new DpopException('dpop_security_unavailable'); }
        } catch (\Throwable $e) {
            --$this->active;
            if ($e instanceof DpopException) { throw $e; }
            throw new DpopException('dpop_security_unavailable');
        }
        $released = false;
        return function () use (&$released): void { if (!$released) { $released = true; --$this->active; } };
    }
}
