<?php
declare(strict_types=1);
namespace Acme\Dpop;

final class SystemClock implements \Psr\Clock\ClockInterface
{
    public function now(): \DateTimeImmutable { return new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
