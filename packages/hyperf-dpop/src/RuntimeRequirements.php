<?php
declare(strict_types=1);
namespace Acme\HyperfDpop;

final class RuntimeRequirements
{
    public static function assertSupported(): void
    {
        $swoole = phpversion('swoole');
        if ($swoole === false || !str_starts_with($swoole, '6.2.') || version_compare($swoole, '6.2.5', '<')) {
            throw new \RuntimeException('DPoP requires the Swoole 6.2 extension at version 6.2.5 or later.');
        }
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('DPoP requires the OpenSSL extension.');
        }
    }
}
