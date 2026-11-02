<?php
declare(strict_types=1);
namespace Acme\HyperfDpop;

/** Override the interface for application metrics; receives no user/credential values. */
final class AuthenticationObserver implements \Acme\HyperfDpop\Contract\AuthenticationObserverInterface
{
    public function record(string $category, string $outcome, float $milliseconds): void {}
}
