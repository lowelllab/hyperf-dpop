<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Contract;

interface AuthenticationObserverInterface
{
    /** Category is token/refresh/resource; outcome is an allowlisted category, never credentials. */
    public function record(string $category, string $outcome, float $milliseconds): void;
}
