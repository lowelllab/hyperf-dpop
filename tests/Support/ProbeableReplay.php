<?php
declare(strict_types=1);
namespace DpopTest\Support;

/** Doctor fixture: any write is an error. */
final class ProbeableReplay implements \Acme\Dpop\Contract\ReplayStoreInterface, \Acme\HyperfDpop\Store\ReplayStoreProbeInterface
{
    public function reserve(string $domain,\Acme\Dpop\ValueObject\VerifiedProof $proof,int $verifiedAt,\Acme\Dpop\ValueObject\SecurityPolicy $policy):void { throw new \LogicException('Doctor must not write'); }
    public function probe():void {}
}
