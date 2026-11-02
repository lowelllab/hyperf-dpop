<?php
declare(strict_types=1);
namespace Acme\Dpop\ValueObject;

final readonly class SecurityPolicy
{
    public function __construct(
        public int $pastSeconds = 60,
        public int $futureSeconds = 30,
        public int $replayTtl = 120,
        public int $clockSkew = 5,
        public int $proofMaxBytes = 8192,
        public int $nonceTtl = 300,
    ) {
        if ($pastSeconds < 1 || $pastSeconds > 300 || $futureSeconds < 0 || $futureSeconds > 60
            || $clockSkew < 0 || $clockSkew > 10 || $replayTtl <= $pastSeconds + $futureSeconds + 2 * $clockSkew
            || $replayTtl > 3600 || $proofMaxBytes < 512 || $proofMaxBytes > 16384 || $nonceTtl < 30 || $nonceTtl > 600) {
            throw new \InvalidArgumentException('Unsafe DPoP policy');
        }
    }

    public function assertTime(int $issuedAt, int $now): void
    {
        if ($issuedAt < $now - $this->pastSeconds || $issuedAt > $now + $this->futureSeconds) {
            throw new \Acme\Dpop\Exception\DpopException('invalid_dpop_proof');
        }
    }

    public function assertProofTime(VerifiedProof $proof, int $now): void
    {
        $this->assertTime($proof->issuedAt, $now);
        if (($proof->expiresAt !== null && $now >= $proof->expiresAt)
            || ($proof->notBefore !== null && $now < $proof->notBefore)) {
            throw new \Acme\Dpop\Exception\DpopException('invalid_dpop_proof');
        }
    }
}
