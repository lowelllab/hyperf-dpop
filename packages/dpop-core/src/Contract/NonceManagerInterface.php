<?php
declare(strict_types=1);
namespace Acme\Dpop\Contract;

interface NonceManagerInterface
{
    public function issue(string $jkt, string $context, int $now): string;
    public function verify(#[\SensitiveParameter] string $nonce, string $jkt, string $context, int $now): bool;
}
