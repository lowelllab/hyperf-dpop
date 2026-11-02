<?php
declare(strict_types=1);
namespace Acme\Dpop\Contract;

use Acme\Dpop\ValueObject\SecurityPolicy;
use Acme\Dpop\ValueObject\VerifiedProof;

interface ReplayStoreInterface
{
    /** Atomically reserve after checking store time; duplicates and uncertainty throw. */
    public function reserve(string $domain, VerifiedProof $proof, int $verifiedAt, SecurityPolicy $policy): void;
}
