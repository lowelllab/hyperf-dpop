<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Store;

interface ReplayStoreProbeInterface
{
    /** Read-only health/schema check. Throw if the actual store cannot be verified. */
    public function probe(): void;
}
