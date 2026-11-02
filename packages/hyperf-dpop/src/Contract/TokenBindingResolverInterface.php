<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Contract;

use Acme\Dpop\ValueObject\TokenBinding;
use Psr\Http\Message\ServerRequestInterface;

interface TokenBindingResolverInterface
{
    /** Validate signature/session, expiry, audience and revocation; never cache active=true here by default. */
    public function resolve(#[\SensitiveParameter] string $accessToken, ServerRequestInterface $request): TokenBinding;
}
