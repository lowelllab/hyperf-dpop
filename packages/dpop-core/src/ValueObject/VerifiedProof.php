<?php
declare(strict_types=1);
namespace Acme\Dpop\ValueObject;

final readonly class VerifiedProof
{
    public function __construct(public string $jkt, public string $jti, public int $issuedAt, public ?int $expiresAt = null, public ?int $notBefore = null) {}
}
