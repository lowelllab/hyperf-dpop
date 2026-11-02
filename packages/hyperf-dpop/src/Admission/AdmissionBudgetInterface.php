<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Admission;

interface AdmissionBudgetInterface
{
    /** Returns an idempotent release callback. */
    public function enter(string $source, string $category): \Closure;
}
