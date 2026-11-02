<?php
declare(strict_types=1);
namespace Acme\HyperfDpop;

final class Cancellation
{
    public static function assertActive(): void
    {
        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0 && \Swoole\Coroutine::isCanceled()) {
            throw new \Acme\Dpop\Exception\DpopException('dpop_security_unavailable');
        }
    }
}
