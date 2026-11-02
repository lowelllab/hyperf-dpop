<?php
declare(strict_types=1);
namespace DpopTest\Support;

use Acme\HyperfDpop\Admission\{AdmissionBudgetInterface, AdmissionBudgetProbeInterface};

final class ProbeableBudget implements AdmissionBudgetInterface, AdmissionBudgetProbeInterface
{
    public function enter(string $source, string $category): \Closure { throw new \LogicException('Doctor must not consume capacity'); }
    public function probe(): void {}
}
