<?php
declare(strict_types=1);
namespace DpopTest\Support;

final class TrackingBudget implements \Acme\HyperfDpop\Admission\AdmissionBudgetInterface
{
    public int $active = 0;
    public function enter(string $source, string $category): \Closure
    {
        ++$this->active; $released=false;
        return function () use (&$released): void { if (!$released) { --$this->active; $released=true; } };
    }
}
