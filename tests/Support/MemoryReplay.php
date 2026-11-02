<?php
declare(strict_types=1);
namespace DpopTest\Support;

use Acme\Dpop\Contract\ReplayStoreInterface;
use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\ValueObject\{SecurityPolicy, VerifiedProof};

/** Deliberately test-only; never registered by the production ConfigProvider. */
final class MemoryReplay implements ReplayStoreInterface
{
    public array $accepted = [];
    public function reserve(string $domain, VerifiedProof $proof, int $verifiedAt, SecurityPolicy $policy): void
    {
        $key = $domain . ':' . $proof->jkt . ':' . $proof->jti;
        if (isset($this->accepted[$key])) { throw new DpopException('invalid_dpop_proof'); }
        $this->accepted[$key] = true;
    }
}
