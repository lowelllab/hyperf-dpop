<?php
declare(strict_types=1);
namespace DpopTest\Support;

final class FrozenClock implements \Psr\Clock\ClockInterface
{
    public function __construct(public int $timestamp) {}
    public function now(): \DateTimeImmutable { return new \DateTimeImmutable('@' . $this->timestamp); }
}
