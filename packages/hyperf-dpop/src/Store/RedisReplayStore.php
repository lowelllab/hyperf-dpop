<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Store;

use Acme\Dpop\Contract\ReplayStoreInterface;
use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\ValueObject\{SecurityPolicy, VerifiedProof};

/** Atomic across workers; acknowledged Redis data loss can permit replays. */
final readonly class RedisReplayStore implements ReplayStoreInterface, ReplayStoreProbeInterface
{
    private const SCRIPT = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
if math.abs(now - tonumber(ARGV[1])) > tonumber(ARGV[2]) then return -2 end
if tonumber(ARGV[3]) < now-tonumber(ARGV[4]) or tonumber(ARGV[3]) > now+tonumber(ARGV[5]) then return -1 end
if ARGV[7] ~= '' and now >= tonumber(ARGV[7]) then return -1 end
if ARGV[8] ~= '' and now < tonumber(ARGV[8]) then return -1 end
local accepted = redis.call('SET', KEYS[1], '1', 'NX', 'EX', ARGV[6])
if accepted then return 1 else return 0 end
LUA;

    public function __construct(private RedisExecutorInterface $redis, private string $namespace = 'dpop')
    {
        if (preg_match('/^[A-Za-z0-9:_-]{1,64}$/D', $namespace) !== 1) { throw new \InvalidArgumentException('Invalid Redis namespace'); }
    }

    public function reserve(string $domain, VerifiedProof $proof, int $verifiedAt, SecurityPolicy $policy): void
    {
        try {
            $key = $this->namespace . ':replay:' . hash('sha256', $domain . "\0" . $proof->jkt . "\0" . $proof->jti);
            $result = $this->redis->evaluate(self::SCRIPT, [$key], [$verifiedAt, $policy->clockSkew, $proof->issuedAt, $policy->pastSeconds, $policy->futureSeconds, $policy->replayTtl, $proof->expiresAt ?? '', $proof->notBefore ?? '']);
            if ($result === 0 || $result === -1) { throw new DpopException('invalid_dpop_proof'); }
            if ($result !== 1) { throw new DpopException('dpop_security_unavailable'); }
        } catch (DpopException $e) { throw $e; }
        catch (\Throwable) { throw new DpopException('dpop_security_unavailable'); }
    }

    public function probe(): void
    {
        try { if (!$this->redis->ping()) { throw new DpopException('dpop_security_unavailable'); } }
        catch (\Throwable) { throw new DpopException('dpop_security_unavailable'); }
    }
}
