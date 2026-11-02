<?php
declare(strict_types=1);
namespace Acme\Dpop\Exception;

/** Contains protocol categories only; never chain an exception containing credentials. */
final class DpopException extends \RuntimeException
{
    public function __construct(public readonly string $error, #[\SensitiveParameter] private readonly ?string $challenge = null, public readonly int $retryAfter = 60)
    {
        if ($retryAfter < 1 || $retryAfter > 3600) { throw new \InvalidArgumentException('Invalid retry delay'); }
        if (!in_array($error, ['missing_authorization', 'invalid_request', 'invalid_dpop_proof', 'invalid_token', 'invalid_grant', 'use_dpop_nonce', 'dpop_security_unavailable', 'rate_limited'], true)) {
            throw new \InvalidArgumentException('Unknown DPoP error category');
        }
        parent::__construct($error);
    }

    public function nonce(): ?string { return $this->challenge; }
    public function __debugInfo(): array { return ['error' => $this->error]; }
}
