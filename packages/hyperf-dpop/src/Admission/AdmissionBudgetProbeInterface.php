<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Admission;

/** Read-only diagnostic; never consumes authentication capacity. */
interface AdmissionBudgetProbeInterface
{
    public function probe(): void;
}
